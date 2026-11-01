#!/usr/bin/env python3
"""Acceptance checks for the WGS Reply Bridge, against the staging stack.

Signs requests the way the spec describes and checks osTicket's database
(through staging-ctl.php) and Mailpit. Does not print the shared secret.
"""

import base64
import hashlib
import hmac
import json
import re
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid

SRC = "/Users/david/code/osTicket-reply-bridge/wgs/plugins/wgs-reply-bridge/"
DST = "/Volumes/PRO-G40/Docker/osticket-staging/app/include/plugins/wgs-reply-bridge/"
BASE = "http://127.0.0.1:8095"
MAIL = "http://127.0.0.1:8025"
CTL = "/var/www/html/include/plugins/wgs-reply-bridge/tests/staging-ctl.php"
ALLOWED = "172.21.0.0/16"

SECRET = ""
results = []


def say(line):
    print(line, flush=True)


def ctl(args, secret=False):
    proc = subprocess.run(
        ["docker", "exec", "-i", "osticket_staging_web", "php", CTL, *args],
        capture_output=True, text=True,
    )
    if secret:
        text = proc.stdout.strip().splitlines()
        if proc.returncode != 0 or not text:
            raise SystemExit("secret command failed:\n" + proc.stderr + proc.stdout)
        return text[-1].strip()
    if proc.returncode != 0:
        raise SystemExit(
            "staging-ctl %s failed (%s)\n%s\n%s" % (
                " ".join(args), proc.returncode, proc.stdout, proc.stderr)
        )
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError:
        raise SystemExit("staging-ctl %s did not return JSON:\n%s" % (
            " ".join(args), proc.stdout))


def deploy():
    subprocess.check_call(["rsync", "-a", "--delete", SRC, DST])
    lint = subprocess.run(
        ["docker", "exec", "osticket_staging_web", "php", "-l",
         "/var/www/html/include/plugins/wgs-reply-bridge/class.replybridge.php"],
        capture_output=True, text=True,
    )
    if lint.returncode != 0:
        raise SystemExit(lint.stdout + lint.stderr)
    say(lint.stdout.strip())
    # Apache keeps its own opcache. A graceful reload picks up the rsync.
    subprocess.run(
        ["docker", "exec", "osticket_staging_web", "apachectl", "graceful"],
        capture_output=True, text=True,
    )
    time.sleep(2)


def sign(secret, method, path, body, ts, rid):
    material = "%s\n%s\n%s\n%s\n%s" % (
        ts, rid, method, path, hashlib.sha256(body).hexdigest(),
    )
    return hmac.new(secret.encode(), material.encode(), hashlib.sha256).hexdigest()


def post(action, payload, rid=None, ts=None, sig=None, secret=None):
    body = json.dumps(payload, ensure_ascii=False).encode()
    path = "/api/wgs-reply/" + action
    rid = rid or str(uuid.uuid4())
    ts = str(int(time.time()) if ts is None else ts)
    secret = SECRET if secret is None else secret
    signature = sig if sig is not None else sign(secret, "POST", path, body, ts, rid)
    req = urllib.request.Request(
        BASE + path,
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "X-WGS-Timestamp": ts,
            "X-WGS-Request-Id": rid,
            "X-WGS-Signature": signature,
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            raw = resp.read()
            code = resp.status
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        code = exc.code
    text = raw.decode()
    try:
        data = json.loads(text) if text else None
    except json.JSONDecodeError:
        data = None
    return {"code": code, "raw": text, "json": data, "rid": rid, "body": body}


def post_from_container(action, payload):
    """POST from inside the web container, so Apache sees 127.0.0.1."""
    body = json.dumps(payload, ensure_ascii=False).encode()
    path = "/api/wgs-reply/" + action
    rid = str(uuid.uuid4())
    ts = str(int(time.time()))
    signature = sign(SECRET, "POST", path, body, ts, rid)
    header = "\r\n".join([
        "Content-Type: application/json",
        "X-WGS-Timestamp: " + ts,
        "X-WGS-Request-Id: " + rid,
        "X-WGS-Signature: " + signature,
    ])
    # The body is passed as argv so stdin stays free for `php`.
    php = r'''<?php
$ctx = stream_context_create(array("http" => array(
    "method" => "POST",
    "header" => base64_decode(getenv("WGS_HEADER")),
    "content" => base64_decode(getenv("WGS_BODY")),
    "ignore_errors" => true,
    "timeout" => 60,
)));
$out = file_get_contents(getenv("WGS_URL"), false, $ctx);
$status = isset($http_response_header[0]) ? $http_response_header[0] : "";
echo $status, "\n", $out;
'''
    proc = subprocess.run(
        ["docker", "exec", "-i",
         "-e", "WGS_URL=http://127.0.0.1" + path,
         "-e", "WGS_HEADER=" + base64.b64encode(header.encode()).decode(),
         "-e", "WGS_BODY=" + base64.b64encode(body).decode(),
         "osticket_staging_web", "php"],
        input=php.encode(),
        capture_output=True,
    )
    text = proc.stdout.decode(errors="replace")
    if proc.returncode != 0 and not text:
        return {"code": 0, "raw": proc.stderr.decode(errors="replace"), "json": None}
    first, _, rest = text.partition("\n")
    code = 0
    parts = first.split()
    if len(parts) >= 2 and parts[1].isdigit():
        code = int(parts[1])
    try:
        data = json.loads(rest) if rest.strip() else None
    except json.JSONDecodeError:
        data = None
    return {"code": code, "raw": text, "json": data}


def mail_index():
    req = urllib.request.Request(MAIL + "/api/v1/messages?limit=500")
    with urllib.request.urlopen(req, timeout=30) as resp:
        data = json.load(resp)
    return {m["ID"]: m for m in data.get("messages", [])}


def mail_full(mid):
    req = urllib.request.Request(MAIL + "/api/v1/message/" + mid)
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)


def addrs(field):
    out = []
    for item in field or []:
        if isinstance(item, dict) and item.get("Address"):
            out.append(item["Address"].lower())
    return out


def new_messages(before):
    after = mail_index()
    return [after[i] for i in after if i not in before]


def record(name, ok, detail):
    results.append((name, ok, detail))
    say("%s  %s" % ("PASS" if ok else "FAIL", name))
    for line in detail.splitlines():
        say("    " + line)


def reply_body(staff, marker, summary, ticket, extra=None):
    payload = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
        "note_summary": summary,
    }
    if extra:
        payload.update(extra)
    return payload


def expect_note(entries, staff, summary):
    expected = "Reply sent using Claude by %s\n\n%s" % (staff["name"], summary)
    notes = [e for e in entries if e["type"] == "N"]
    if len(notes) != 1:
        return "expected one note, got %s" % len(notes)
    note = notes[0]
    if note["staff_id"] != staff["id"]:
        return "note staff_id %s, want %s" % (note["staff_id"], staff["id"])
    body = note["body"].replace("\r\n", "\n")
    if body != expected:
        return "note body mismatch:\nGOT %r\nWANT %r" % (body, expected)
    return ""


def visible_text(value):
    text = re.sub(r"<[^>]+>", " ", value or "")
    text = "".join(ch if (ch.isalnum() or ch.isspace()) else " " for ch in text)
    return " ".join(text.split())


def check_reply_mail(preview, messages, owner, marker):
    owner = owner.lower()
    hits = []
    for message in messages:
        full = mail_full(message["ID"])
        tos = addrs(full.get("To")) + addrs(full.get("Cc"))
        if owner in tos:
            hits.append(full)
    if len(hits) != 1:
        shown = []
        for message in messages:
            full = mail_full(message["ID"])
            shown.append("%s -> %s | %s" % (
                full.get("From", {}).get("Address"),
                addrs(full.get("To")),
                full.get("Subject"),
            ))
        return "expected one message to %s, got %s\n%s" % (
            owner, len(hits), "\n".join(shown))
    mail = hits[0]
    want_from = preview["from"]["address"].lower()
    got_from = (mail.get("From") or {}).get("Address", "").lower()
    if got_from != want_from:
        return "from address %s, preview says %s" % (got_from, want_from)
    want_name = preview["from"]["name"]
    got_name = (mail.get("From") or {}).get("Name", "")
    if got_name != want_name:
        return "from name %r, preview says %r" % (got_name, want_name)
    blob = (mail.get("HTML") or "") + "\n" + (mail.get("Text") or "")
    if marker not in blob:
        return "customer mail does not contain the reply text"
    sig = preview.get("signature") or ""
    # Tag names are not part of what the customer reads. Drop them on both
    # sides, then treat punctuation the same way. A hyphen in "All-Pedal"
    # must not be deleted on one side and turned into a space on the other.
    sig_text = visible_text(sig)
    if sig_text:
        sample = sig_text[:80]
        if sample not in visible_text(blob):
            return "signature text %r not in the customer mail" % sample
    if preview.get("subject") and mail.get("Subject") != preview["subject"]:
        return "subject %r, preview says %r" % (mail.get("Subject"), preview["subject"])
    return ""


def main():
    global SECRET
    say("deploying plugin")
    deploy()
    installed = ctl(["install", ALLOWED])
    say("install %s" % json.dumps({k: installed[k] for k in installed if k != "ok"}))
    ctl(["set-fault", "off"])
    ctl(["set-max", "8388608"])
    SECRET = ctl(["secret"], secret=True)
    if len(SECRET) < 16:
        raise SystemExit("secret looks too short")
    say("secret loaded (%d chars, not printed)" % len(SECRET))

    say("finding fixtures")
    fx = ctl(["fixtures"])
    staff = fx["reply_staff"]
    tickets = fx["tickets"]
    say("reply staff %s (%s), %s open tickets" % (
        staff["username"], staff["name"], fx["open_tickets"]))
    say("no-access staff %s, no-reply staff %s" % (
        fx["no_access_staff"]["username"], fx["no_reply_staff"]["username"]))

    # Warm the route so a missing rewrite fails here, not inside check 1.
    warm = post("options", {
        "ticket_number": tickets["plain"]["number"],
        "staff_username": staff["username"],
    })
    if warm["code"] != 200 or not warm["json"] or not warm["json"].get("ok"):
        raise SystemExit("options warmup failed %s\n%s" % (warm["code"], warm["raw"][:800]))
    if not warm["json"].get("can_reply"):
        raise SystemExit("reply staff cannot reply to the plain ticket:\n%s" % warm["raw"][:800])
    say("options warmup ok, can_reply true")

    check_1(staff, tickets["plain"])
    check_2(staff, tickets["close"])
    check_3(fx["no_access_staff"], tickets["no_access"])
    check_4(fx["no_reply_staff"], tickets["no_reply"])
    check_5(staff, tickets["signature"])
    check_6()
    check_7(staff, tickets["plain"])
    check_8(staff, tickets["silent"])
    check_9(staff, tickets["collab"])
    check_10(staff, tickets["stale"])
    check_race(staff, tickets["race"])
    check_fault_after_reply(staff, tickets["fault_reply"])
    check_fault_note(staff, tickets["fault_note"])
    check_recipients(staff, tickets["collab"])
    check_owner_stale(staff, tickets["owner"])
    check_no_access_options(fx["no_access_staff"], staff, tickets["no_access"])
    check_oversize(staff, tickets["plain"])

    say("")
    failed = [name for name, ok, _detail in results if not ok]
    say("%d passed, %d failed" % (len(results) - len(failed), len(failed)))
    return 1 if failed else 0


# Filled by check 1 and replayed by check 6.
FIRST = {}


def check_1(staff, ticket):
    summary = "Asked me to reply to the customer."
    marker = "Bridge check 1 %s" % uuid.uuid4().hex[:12]
    before_state = ctl(["state", ticket["number"]])
    before_mail = mail_index()
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"] or not preview["json"].get("ok"):
        record("1 reply as staff, mail, and note", False,
               "preview failed %s %s" % (preview["code"], preview["raw"][:500]))
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    sent = post("send", fields)
    FIRST.update(rid=sent["rid"], raw=sent["raw"], ticket=ticket["number"],
                 last=before_state["last_entry_id"])
    if sent["code"] != 200 or not sent["json"] or not sent["json"].get("ok"):
        record("1 reply as staff, mail, and note", False,
               "send failed %s %s" % (sent["code"], sent["raw"][:800]))
        return
    state = ctl(["state", ticket["number"]])
    entries = ctl(["entries", ticket["number"], str(before_state["last_entry_id"])])["entries"]
    replies = [e for e in entries if e["type"] == "R"]
    problems = []
    if state["status_id"] != before_state["status_id"] or state["state"] != before_state["state"]:
        problems.append("status changed %s -> %s" % (
            before_state["state"], state["state"]))
    if len(replies) != 1:
        problems.append("expected one R entry, got %s" % len(replies))
    elif replies[0]["staff_id"] != staff["id"]:
        problems.append("R staff_id %s" % replies[0]["staff_id"])
    elif replies[0]["id"] != sent["json"]["response_entry_id"]:
        problems.append("response_entry_id does not match the R entry")
    note_err = expect_note(entries, staff, summary)
    if note_err:
        problems.append(note_err)
    elif sent["json"]["note_entry_id"] != [e for e in entries if e["type"] == "N"][0]["id"]:
        problems.append("note_entry_id does not match the N entry")
    if sent["json"].get("mail") != "handed_to_osticket":
        problems.append("mail field is %r" % sent["json"].get("mail"))
    mail_err = check_reply_mail(
        preview["json"], new_messages(before_mail), ticket["owner_email"], marker)
    if mail_err:
        problems.append(mail_err)
    detail = "ticket %s staff %s R %s N %s from %s <%s>" % (
        ticket["number"], staff["username"],
        sent["json"].get("response_entry_id"), sent["json"].get("note_entry_id"),
        preview["json"]["from"]["name"], preview["json"]["from"]["address"],
    )
    if problems:
        detail += "\n" + "\n".join(problems)
    record("1 reply as staff, mail, and note", not problems, detail)


def check_2(staff, ticket):
    options = post("options", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
    })
    if options["code"] != 200 or not options["json"].get("can_reply"):
        record("2 close via reply_status_id", False, "options failed %s" % options["raw"][:400])
        return
    closed = [s for s in options["json"]["statuses"]["options"] if s["state"] == "closed"]
    if not closed:
        record("2 close via reply_status_id", False, "no closed status in the reply choices")
        return
    status_id = closed[0]["id"]
    before = ctl(["state", ticket["number"]])
    marker = "Bridge check 2 %s" % uuid.uuid4().hex[:12]
    summary = "Asked me to close this ticket after replying."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
        "reply_status_id": status_id,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("2 close via reply_status_id", False, "preview failed %s" % preview["raw"][:400])
        return
    if preview["json"]["status_after"]["state"] != "closed":
        record("2 close via reply_status_id", False, "preview status_after is not closed")
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    sent = post("send", fields)
    problems = []
    if sent["code"] != 200 or not sent["json"].get("ok"):
        problems.append("send failed %s %s" % (sent["code"], sent["raw"][:500]))
    else:
        if sent["json"]["status"]["state"] != "closed":
            problems.append("response status is %s" % sent["json"]["status"])
        state = ctl(["state", ticket["number"]])
        if state["state"] != "closed" or state["status_id"] != status_id:
            problems.append("ticket state after send is %s (%s)" % (
                state["state"], state["status_id"]))
        entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        note_err = expect_note(entries, staff, summary)
        if note_err:
            problems.append(note_err)
        if not any(e["type"] == "R" and e["staff_id"] == staff["id"] for e in entries):
            problems.append("no R entry by the acting staff")
    restored = ctl(["restore", ticket["number"], str(before["status_id"]), str(before["staff_id"])])
    if restored.get("state") != "open" or restored.get("status_id") != before["status_id"]:
        problems.append("restore left the ticket %s" % restored)
    detail = "ticket %s closed as status %s (%s), restored to %s" % (
        ticket["number"], status_id, closed[0]["name"], before["status_id"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("2 close via reply_status_id", not problems, detail)


def check_3(staff, ticket):
    before = ctl(["state", ticket["number"]])
    sent = post("send", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>should not post</p>",
        "note_summary": "should not post",
        "preview_hash": "0." + ("ab" * 32),
    })
    after = ctl(["state", ticket["number"]])
    ok = (sent["code"] == 403 and sent["json"] and sent["json"].get("error") == "no_access"
          and after["last_entry_id"] == before["last_entry_id"]
          and after["counts"] == before["counts"])
    detail = "staff %s ticket %s -> %s %s" % (
        staff["username"], ticket["number"], sent["code"],
        (sent["json"] or {}).get("error"))
    if not ok:
        detail += "\n" + sent["raw"][:400]
        detail += "\nentries %s -> %s" % (before["counts"], after["counts"])
    record("3 staff without department access", ok, detail)


def check_4(staff, ticket):
    before = ctl(["state", ticket["number"]])
    sent = post("send", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>should not post</p>",
        "note_summary": "should not post",
        "preview_hash": "0." + ("cd" * 32),
    })
    after = ctl(["state", ticket["number"]])
    ok = (sent["code"] == 403 and sent["json"]
          and sent["json"].get("error") == "no_reply_permission"
          and after["last_entry_id"] == before["last_entry_id"]
          and after["counts"] == before["counts"])
    detail = "staff %s ticket %s -> %s %s" % (
        staff["username"], ticket["number"], sent["code"],
        (sent["json"] or {}).get("error"))
    if not ok:
        detail += "\n" + sent["raw"][:400]
    record("4 role lacks ticket.reply", ok, detail)


def check_5(staff, ticket):
    original = ctl(["signature", staff["username"]])
    options = post("options", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
    })
    if options["code"] != 200 or not options["json"].get("ok"):
        record("5 live signature setting is followed", False, options["raw"][:400])
        return
    available = options["json"]["signatures"]["available"]
    current = options["json"]["signatures"]["default"]
    target = None
    for choice in ("mine", "none", "dept"):
        if choice in available and choice != current:
            target = choice
            break
    if not target:
        record("5 live signature setting is followed", False,
               "no alternate signature in %s" % available)
        return
    problems = []
    try:
        ctl(["set-signature", staff["username"], target])
        again = post("options", {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
        })
        if again["json"]["signatures"]["default"] != target:
            problems.append("options default stayed %s" % again["json"]["signatures"]["default"])
        marker = "Bridge check 5 %s" % uuid.uuid4().hex[:12]
        summary = "Asked me to reply after the signature default changed."
        fields = {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
            "response_html": "<p>%s</p>" % marker,
        }
        before_mail = mail_index()
        preview = post("preview", fields)
        if preview["code"] != 200 or not preview["json"].get("ok"):
            problems.append("preview failed %s" % preview["raw"][:300])
        else:
            sig = preview["json"].get("signature") or ""
            if target == "none" and sig:
                problems.append("preview still has a signature")
            if target == "mine" and original["text"] and original["text"] not in sig and sig not in original["text"]:
                problems.append("preview signature is not the staff signature")
            if target == "dept" and not sig:
                problems.append("preview dept signature is empty")
            fields["preview_hash"] = preview["json"]["preview_hash"]
            fields["note_summary"] = summary
            sent = post("send", fields)
            if sent["code"] != 200 or not sent["json"].get("ok"):
                problems.append("send failed %s %s" % (sent["code"], sent["raw"][:400]))
            else:
                mail_err = check_reply_mail(
                    preview["json"], new_messages(before_mail), ticket["owner_email"], marker)
                if mail_err:
                    problems.append(mail_err)
        back = ctl(["signature", staff["username"]])
        detail = "default %s -> %s, options followed it, mail matched the preview" % (current, target)
    finally:
        ctl(["set-signature", staff["username"], original["type"]])
        back = ctl(["signature", staff["username"]])
        if back["type"] != original["type"]:
            problems.append("could not restore signature type %s" % original["type"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("5 live signature setting is followed", not problems, detail)


def check_6():
    if not FIRST.get("raw"):
        record("6 replay request id", False, "check 1 did not record a result")
        return
    before = ctl(["state", FIRST["ticket"]])
    before_mail = mail_index()
    # The body must be signed, but a completed id returns before the body
    # is interpreted. Send the same id with an empty object so a handler
    # that ignored idempotency would fail validation instead of posting.
    sent = post("send", {}, rid=FIRST["rid"])
    after = ctl(["state", FIRST["ticket"]])
    mail = new_messages(before_mail)
    ok = (sent["code"] == 200 and sent["raw"] == FIRST["raw"]
          and after["last_entry_id"] == before["last_entry_id"]
          and after["counts"] == before["counts"]
          and not mail)
    detail = "replay HTTP %s, body %s, entries unchanged, new mail %s" % (
        sent["code"], "identical" if sent["raw"] == FIRST["raw"] else "DIFFERS", len(mail))
    if not ok and sent["raw"] != FIRST["raw"]:
        detail += "\nGOT %s" % sent["raw"][:400]
    record("6 replay request id", ok, detail)


def check_7(staff, ticket):
    before = ctl(["state", ticket["number"]])
    payload = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>should not post</p>",
        "note_summary": "should not post",
        "preview_hash": "1." + ("ef" * 32),
    }
    bad = post("send", payload, sig="ab" * 32)
    old = post("send", payload, ts=int(time.time()) - 600)
    wrong = post_from_container("send", payload)
    after = ctl(["state", ticket["number"]])
    problems = []
    if not (bad["code"] == 401 and bad["json"] and bad["json"].get("error") == "auth_failed"):
        problems.append("bad signature -> %s %s" % (bad["code"], bad["raw"][:200]))
    if not (old["code"] == 401 and old["json"] and old["json"].get("error") == "stale_timestamp"):
        problems.append("old timestamp -> %s %s" % (old["code"], old["raw"][:200]))
    if not (wrong["code"] == 403 and wrong["json"] and wrong["json"].get("error") == "ip_not_allowed"):
        problems.append("wrong ip -> %s %s" % (wrong["code"], wrong["raw"][:300]))
    if after["last_entry_id"] != before["last_entry_id"] or after["counts"] != before["counts"]:
        problems.append("thread changed %s -> %s" % (before["counts"], after["counts"]))
    detail = "bad sig %s, old ts %s, wrong ip %s" % (bad["code"], old["code"], wrong["code"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("7 bad signature, old timestamp, wrong IP", not problems, detail)


def check_8(staff, ticket):
    before = ctl(["state", ticket["number"]])
    before_mail = mail_index()
    marker = "Bridge check 8 %s" % uuid.uuid4().hex[:12]
    summary = "Asked me to record the reply without emailing the customer."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
        "reply_to": "none",
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("8 reply_to none", False, "preview failed %s" % preview["raw"][:400])
        return
    if preview["json"]["to"] or preview["json"]["cc"]:
        record("8 reply_to none", False, "preview still has recipients %s" % preview["json"])
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    sent = post("send", fields)
    problems = []
    if sent["code"] != 200 or not sent["json"].get("ok"):
        problems.append("send failed %s %s" % (sent["code"], sent["raw"][:400]))
    else:
        entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        if not any(e["type"] == "R" and e["staff_id"] == staff["id"] for e in entries):
            problems.append("no R entry")
        note_err = expect_note(entries, staff, summary)
        if note_err:
            problems.append(note_err)
        owner = ticket["owner_email"].lower()
        leaked = []
        for message in new_messages(before_mail):
            full = mail_full(message["ID"])
            if owner in addrs(full.get("To")) + addrs(full.get("Cc")):
                leaked.append(full.get("Subject"))
        if leaked:
            problems.append("customer still received mail: %s" % leaked)
    detail = "ticket %s reply recorded, no message to %s" % (
        ticket["number"], ticket["owner_email"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("8 reply_to none", not problems, detail)


def check_9(staff, ticket):
    before_collab = ctl(["collabs", ticket["number"]])["collaborators"]
    before = ctl(["state", ticket["number"]])
    if not any(c["active"] for c in before_collab):
        record("9 omitted cc_user_ids leaves collaborators", False, "fixture has no active collaborator")
        return
    marker = "Bridge check 9 %s" % uuid.uuid4().hex[:12]
    summary = "Asked me to reply without changing collaborators."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("9 omitted cc_user_ids leaves collaborators", False,
               "preview failed %s" % preview["raw"][:400])
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    sent = post("send", fields)
    after_collab = ctl(["collabs", ticket["number"]])["collaborators"]
    problems = []
    if sent["code"] != 200 or not sent["json"].get("ok"):
        problems.append("send failed %s %s" % (sent["code"], sent["raw"][:400]))
    if before_collab != after_collab:
        problems.append("collaborators changed\nBEFORE %s\nAFTER %s" % (before_collab, after_collab))
    entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
    if not any(e["type"] == "R" for e in entries):
        problems.append("no reply was recorded")
    detail = "ticket %s active collaborators %s" % (
        ticket["number"], sum(1 for c in before_collab if c["active"]))
    if problems:
        detail += "\n" + "\n".join(problems)
    record("9 omitted cc_user_ids leaves collaborators", not problems, detail)


def check_10(staff, ticket):
    before = ctl(["state", ticket["number"]])
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>Bridge check 10 should not send</p>",
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("10 stale preview after a new customer message", False,
               "preview failed %s" % preview["raw"][:400])
        return
    posted = ctl(["post-message", ticket["number"],
                  "Customer wrote back before the bridge sent. Acceptance check 10."])
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = "This send must be refused."
    sent = post("send", fields)
    after = ctl(["state", ticket["number"]])
    entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
    replies = [e for e in entries if e["type"] == "R"]
    ok = (posted.get("ok") and posted.get("type") == "M"
          and sent["code"] == 409 and sent["json"]
          and sent["json"].get("error") == "stale_preview"
          and not replies)
    detail = "postMessage %s, send %s %s, new R entries %s" % (
        posted.get("entry_id"), sent["code"],
        (sent["json"] or {}).get("error"), len(replies))
    if not ok:
        detail += "\n" + sent["raw"][:400]
        detail += "\ncounts %s -> %s" % (before["counts"], after["counts"])
    record("10 stale preview after a new customer message", ok, detail)


def bracket_addr(value):
    match = re.search(r"<([^>]+)>", value or "")
    text = match.group(1) if match else (value or "")
    return text.strip().lower()


# Fixed denial sentences. A short ticket subject that happens to sit inside
# one of these is not a leaked ticket fact.
DENIAL_TEXT = (
    "staff member cannot access this ticket.",
    "a merged child ticket cannot be replied to.",
    "ticket was not found.",
)


def denial_is_empty(response, banned):
    body = response["json"]
    if response["code"] not in (403, 404) or not body:
        return "HTTP %s %s" % (response["code"], response["raw"][:300])
    if set(body.keys()) - {"ok", "error", "message"}:
        return "extra keys %s" % sorted(body.keys())
    raw = response["raw"].lower()
    for secret in banned:
        if not secret:
            continue
        low = secret.lower()
        if any(low in sentence for sentence in DENIAL_TEXT):
            continue
        if low in raw:
            return "denial contains %s" % secret
    return ""


def check_race(staff, ticket):
    before = ctl(["state", ticket["number"]])
    marker = "Bridge race %s" % uuid.uuid4().hex[:12]
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"] or not preview["json"].get("ok"):
        record("11 one reply wins a concurrent send", False,
               "preview failed %s" % preview["raw"][:400])
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = "Two sends raced. Only one reply may land."
    barrier = threading.Barrier(2)
    out = [None, None]

    def shoot(index):
        try:
            barrier.wait(timeout=30)
            out[index] = post("send", fields)
        except Exception as exc:
            out[index] = {"code": 0, "raw": str(exc), "json": None}

    threads = [threading.Thread(target=shoot, args=(i,)) for i in (0, 1)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join(timeout=90)
    problems = []
    if any(item is None for item in out):
        problems.append("a sender did not finish")
    else:
        wins = [item for item in out if item["json"] and item["json"].get("ok")]
        losses = [item for item in out if item not in wins]
        if len(wins) != 1:
            problems.append("successes %s" % len(wins))
        for item in losses:
            code = (item["json"] or {}).get("error")
            if item["code"] != 409 or code not in ("busy", "stale_preview"):
                problems.append("loser %s %s" % (item["code"], item["raw"][:300]))
    entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
    replies = [entry for entry in entries if entry["type"] == "R"]
    if len(replies) != 1:
        problems.append("R entries %s" % len(replies))
    detail = "ticket %s replies %s" % (ticket["number"], len(replies))
    if not any(item is None for item in out):
        detail += " results %s" % ", ".join(
            "%s %s" % (item["code"], (item["json"] or {}).get("error") or "ok")
            for item in out)
    if problems:
        detail += "\n" + "\n".join(problems)
    record("11 one reply wins a concurrent send", not problems, detail)


def check_fault_after_reply(staff, ticket):
    before = ctl(["state", ticket["number"]])
    marker = "Bridge fault reply %s" % uuid.uuid4().hex[:12]
    summary = "The note must be posted by the retry, not a second reply."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("12 fault after postReply then note-only retry", False,
               "preview failed %s" % preview["raw"][:400])
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    problems = []
    sent = None
    try:
        ctl(["set-fault", "after_reply"])
        sent = post("send", fields)
    finally:
        ctl(["set-fault", "off"])
    if not sent or sent["code"] != 500 or not sent["json"] or sent["json"].get("error") != "partial_note_missing":
        problems.append("first send %s %s" % (
            sent["code"] if sent else 0, (sent or {}).get("raw", "")[:400]))
    else:
        response_id = sent["json"].get("response_entry_id")
        ledger = ctl(["ledger", sent["rid"]])
        if not ledger.get("found") or ledger.get("status") != "reply_recorded":
            problems.append("ledger %s" % ledger)
        if ledger.get("response_entry_id") != response_id:
            problems.append("ledger response id %s, body %s" % (
                ledger.get("response_entry_id"), response_id))
        midway = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        replies = [entry for entry in midway if entry["type"] == "R"]
        notes = [entry for entry in midway if entry["type"] == "N"]
        if len(replies) != 1 or replies[0]["id"] != response_id:
            problems.append("reply rows after the fault %s" % replies)
        if notes:
            problems.append("a note was posted during the fault")
        retry = post("send", fields, rid=sent["rid"])
        if retry["code"] != 200 or not retry["json"] or not retry["json"].get("ok"):
            problems.append("retry %s %s" % (retry["code"], retry["raw"][:400]))
        elif retry["json"].get("response_entry_id") != response_id:
            problems.append("retry posted a different reply %s" % retry["json"])
        final = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        if len([entry for entry in final if entry["type"] == "R"]) != 1:
            problems.append("R count after retry is not 1")
        note_err = expect_note(final, staff, summary)
        if note_err:
            problems.append(note_err)
        done = ctl(["ledger", sent["rid"]])
        if done.get("status") != "completed" or not done.get("note_entry_id"):
            problems.append("ledger after retry %s" % done)
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("12 fault after postReply then note-only retry", not problems, detail)


def check_fault_note(staff, ticket):
    before = ctl(["state", ticket["number"]])
    marker = "Bridge fault note %s" % uuid.uuid4().hex[:12]
    summary = "The note failed once and the retry must add exactly one."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("13 note failure then retry completes one note", False,
               "preview failed %s" % preview["raw"][:400])
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    problems = []
    sent = None
    try:
        ctl(["set-fault", "note_fail"])
        sent = post("send", fields)
    finally:
        ctl(["set-fault", "off"])
    if not sent or sent["code"] != 500 or not sent["json"] or sent["json"].get("error") != "partial_note_missing":
        problems.append("first send %s %s" % (
            sent["code"] if sent else 0, (sent or {}).get("raw", "")[:400]))
    else:
        if sent["json"].get("ok"):
            problems.append("note failure was reported as success")
        response_id = sent["json"].get("response_entry_id")
        ledger = ctl(["ledger", sent["rid"]])
        if ledger.get("status") != "reply_recorded" or ledger.get("note_entry_id"):
            problems.append("ledger %s" % ledger)
        midway = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        if len([entry for entry in midway if entry["type"] == "R"]) != 1:
            problems.append("expected one reply before the retry")
        if any(entry["type"] == "N" for entry in midway):
            problems.append("a note was posted despite note_fail")
        retry = post("send", fields, rid=sent["rid"])
        if retry["code"] != 200 or not retry["json"] or not retry["json"].get("ok"):
            problems.append("retry %s %s" % (retry["code"], retry["raw"][:400]))
        elif retry["json"].get("response_entry_id") != response_id:
            problems.append("retry response id changed")
        final = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        if len([entry for entry in final if entry["type"] == "R"]) != 1:
            problems.append("R count after retry is not 1")
        note_err = expect_note(final, staff, summary)
        if note_err:
            problems.append(note_err)
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("13 note failure then retry completes one note", not problems, detail)


def mail_for_marker(messages, marker, owner):
    """Customer copies only. Staff activity alerts can quote the same reply."""
    owner = (owner or "").lower()
    hits = []
    for message in messages:
        full = mail_full(message["ID"])
        blob = (full.get("HTML") or "") + "\n" + (full.get("Text") or "")
        present = addrs(full.get("To")) + addrs(full.get("Cc"))
        if marker in blob and owner in present:
            hits.append(full)
    return hits


def check_recipients(staff, ticket):
    original = ctl(["collabs", ticket["number"]])["collaborators"]
    if not original:
        record("14 preview recipients match the mail", False, "fixture has no collaborators")
        return
    chosen = original[0]
    others = [row["email"] for row in original[1:] if row.get("email")]
    problems = []
    try:
        ctl(["set-collab", ticket["number"], str(chosen["user_id"]), "0"])
        marker = "Bridge inactive collab %s" % uuid.uuid4().hex[:12]
        fields = {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
            "response_html": "<p>%s</p>" % marker,
            "reply_to": "all",
            "cc_user_ids": [chosen["user_id"]],
        }
        before_mail = mail_index()
        preview = post("preview", fields)
        if preview["code"] != 200 or not preview["json"].get("ok"):
            problems.append("inactive preview %s" % preview["raw"][:400])
        else:
            cc = [bracket_addr(item) for item in preview["json"]["cc"]]
            to = [bracket_addr(item) for item in preview["json"]["to"]]
            if chosen["email"] not in cc:
                problems.append("inactive collaborator %s missing from preview cc %s" % (
                    chosen["email"], cc))
            for email in others:
                if email in cc or email in to:
                    problems.append("unselected collaborator %s is in the preview" % email)
            if ticket["owner_email"].lower() not in to:
                problems.append("owner missing from preview to %s" % to)
            fields["preview_hash"] = preview["json"]["preview_hash"]
            fields["note_summary"] = "Reply including one collaborator who was inactive."
            sent = post("send", fields)
            if sent["code"] != 200 or not sent["json"].get("ok"):
                problems.append("inactive send %s %s" % (sent["code"], sent["raw"][:400]))
            else:
                hits = mail_for_marker(new_messages(before_mail), marker, ticket["owner_email"])
                if len(hits) != 1:
                    problems.append("inactive mail hits %s" % len(hits))
                else:
                    got_to = sorted(addrs(hits[0].get("To")))
                    got_cc = sorted(addrs(hits[0].get("Cc")))
                    if got_to != sorted(to) or got_cc != sorted(cc):
                        problems.append("inactive mail to %s cc %s, preview to %s cc %s" % (
                            got_to, got_cc, sorted(to), sorted(cc)))

        marker = "Bridge empty collab %s" % uuid.uuid4().hex[:12]
        fields = {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
            "response_html": "<p>%s</p>" % marker,
            "reply_to": "all",
            "cc_user_ids": [],
        }
        before_mail = mail_index()
        preview = post("preview", fields)
        if preview["code"] != 200 or not preview["json"].get("ok"):
            problems.append("empty preview %s" % preview["raw"][:400])
        else:
            cc = [bracket_addr(item) for item in preview["json"]["cc"]]
            to = [bracket_addr(item) for item in preview["json"]["to"]]
            if cc:
                problems.append("explicit [] still previews cc %s" % cc)
            if to != [ticket["owner_email"].lower()]:
                problems.append("explicit [] to %s, owner %s" % (to, ticket["owner_email"]))
            fields["preview_hash"] = preview["json"]["preview_hash"]
            fields["note_summary"] = "Reply with every collaborator unchecked."
            sent = post("send", fields)
            if sent["code"] != 200 or not sent["json"].get("ok"):
                problems.append("empty send %s %s" % (sent["code"], sent["raw"][:400]))
            else:
                hits = mail_for_marker(new_messages(before_mail), marker, ticket["owner_email"])
                if len(hits) != 1:
                    problems.append("empty mail hits %s" % len(hits))
                else:
                    got_to = sorted(addrs(hits[0].get("To")))
                    got_cc = sorted(addrs(hits[0].get("Cc")))
                    if got_to != sorted(to) or got_cc != sorted(cc):
                        problems.append("empty mail to %s cc %s, preview to %s cc %s" % (
                            got_to, got_cc, sorted(to), sorted(cc)))
    finally:
        for row in original:
            ctl(["set-collab", ticket["number"], str(row["user_id"]),
                 "1" if row["active"] else "0"])
    detail = "ticket %s collaborator %s" % (ticket["number"], chosen["email"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("14 preview recipients match the mail", not problems, detail)


def check_owner_stale(staff, ticket):
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>Bridge owner change should not send</p>",
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("15 owner change after preview is stale", False,
               "preview failed %s" % preview["raw"][:400])
        return
    other = ctl(["other-user", ticket["number"]])
    changed = None
    problems = []
    try:
        changed = ctl(["set-owner", ticket["number"], staff["username"], str(other["user_id"])])
        if changed.get("owner_user_id") != other["user_id"]:
            problems.append("owner did not change %s" % changed)
        after = ctl(["state", ticket["number"]])
        fields["preview_hash"] = preview["json"]["preview_hash"]
        fields["note_summary"] = "This send must be refused."
        sent = post("send", fields)
        later = ctl(["state", ticket["number"]])
        entries = ctl(["entries", ticket["number"], str(after["last_entry_id"])])["entries"]
        replies = [entry for entry in entries if entry["type"] == "R"]
        if not (sent["code"] == 409 and sent["json"] and sent["json"].get("error") == "stale_preview"):
            problems.append("send %s %s" % (sent["code"], sent["raw"][:300]))
        if replies or later["last_entry_id"] != after["last_entry_id"]:
            problems.append("thread moved %s -> %s replies %s" % (
                after["last_entry_id"], later["last_entry_id"], len(replies)))
    finally:
        if changed and changed.get("old_user_id"):
            ctl(["set-owner", ticket["number"], staff["username"], str(changed["old_user_id"])])
    detail = "ticket %s owner %s -> %s" % (
        ticket["number"], ticket.get("owner_user_id"), other.get("user_id"))
    if problems:
        detail += "\n" + "\n".join(problems)
    record("15 owner change after preview is stale", not problems, detail)


def check_no_access_options(staff, reply_staff, ticket):
    banned = [ticket["number"], ticket.get("owner_email") or "", ticket.get("subject") or ""]
    opts = post("options", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
    })
    problems = []
    if not opts["json"] or opts["json"].get("error") != "no_access":
        problems.append("options %s %s" % (opts["code"], opts["raw"][:300]))
    else:
        leaked = denial_is_empty(opts, banned)
        if leaked:
            problems.append("options " + leaked)
    canned = post("canned", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "canned_id": "original",
    })
    if not canned["json"] or canned["json"].get("error") != "no_access":
        problems.append("canned %s %s" % (canned["code"], canned["raw"][:200]))
    else:
        leaked = denial_is_empty(canned, banned)
        if leaked:
            problems.append("canned " + leaked)
    preview = post("preview", {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>should not preview</p>",
    })
    if not preview["json"] or preview["json"].get("error") != "no_access":
        problems.append("preview %s %s" % (preview["code"], preview["raw"][:200]))
    else:
        leaked = denial_is_empty(preview, banned)
        if leaked:
            problems.append("preview " + leaked)
    missing = post("options", {
        "ticket_number": "991991991",
        "staff_username": reply_staff["username"],
    })
    if not missing["json"] or missing["json"].get("error") != "ticket_not_found":
        problems.append("missing %s %s" % (missing["code"], missing["raw"][:200]))
    else:
        leaked = denial_is_empty(missing, ["991991991"])
        if leaked:
            problems.append("missing " + leaked)
    detail = "options %s canned %s preview %s missing %s" % (
        opts["code"], canned["code"], preview["code"], missing["code"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("16 no_access options leak nothing", not problems, detail)


def check_oversize(staff, ticket):
    before = ctl(["state", ticket["number"]])
    rid = str(uuid.uuid4())
    sent = {"code": 0, "raw": ""}
    problems = []
    try:
        ctl(["set-max", "128"])
        payload = {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
            "response_html": "<p>%s</p>" % ("X" * 400),
            "note_summary": "This body is larger than the configured limit.",
        }
        sent = post("send", payload, rid=rid)
        after = ctl(["state", ticket["number"]])
        ledger = ctl(["ledger", rid])
        if sent["code"] != 413:
            problems.append("HTTP %s %s" % (sent["code"], sent["raw"][:300]))
        if after["last_entry_id"] != before["last_entry_id"] or after["counts"] != before["counts"]:
            problems.append("thread changed")
        if ledger.get("found"):
            problems.append("ledger row was written for a rejected body")
        if ticket["number"] in (sent["raw"] or ""):
            problems.append("413 body contains the ticket number")
    finally:
        ctl(["set-max", "8388608"])
    detail = "ticket %s HTTP %s" % (ticket["number"], sent["code"])
    if problems:
        detail += "\n" + "\n".join(problems)
    record("17 oversized body is rejected", not problems, detail)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        say("ERROR %s" % exc)
        raise
