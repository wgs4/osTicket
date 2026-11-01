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


def ctl(args, secret=False, stdin=None):
    proc = subprocess.run(
        ["docker", "exec", "-i", "osticket_staging_web", "php", CTL, *args],
        input=stdin,
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
    ctl(["clear-fault-config"])
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
    check_unknown_does_not_adopt(staff, tickets["before_reply"])
    check_distinct_notes(staff, tickets["two_notes"])
    check_body_hash(staff, tickets["body_hash"])
    check_due_preview(staff, tickets["due_date"])
    check_planted_config(staff, tickets["planted"])
    check_thread_vars(staff, tickets["thread_vars"])

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
    summary = "The note is posted with the interrupted reply, and the retry must not send again."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("12 interrupted native call is mail_handoff_unknown and the retry matches", False,
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
    if not sent or sent["code"] != 500 or not sent["json"] or sent["json"].get("ok") or sent["json"].get("error") != "mail_handoff_unknown":
        problems.append("first send %s %s" % (
            sent["code"] if sent else 0, (sent or {}).get("raw", "")[:500]))
    else:
        if "email" not in (sent["json"].get("message") or "").lower():
            problems.append("message does not tell staff to check the email")
        response_id = sent["json"].get("response_entry_id")
        note_id = sent["json"].get("note_entry_id")
        if not response_id or not note_id:
            problems.append("ids response %s note %s" % (response_id, note_id))
        ledger = ctl(["ledger", sent["rid"]])
        if ledger.get("status") != "reply_recorded_mail_unknown":
            problems.append("ledger %s" % ledger)
        if ledger.get("native_returned"):
            problems.append("native_returned was set on an interrupted call")
        if ledger.get("response_entry_id") != response_id or ledger.get("note_entry_id") != note_id:
            problems.append("ledger ids %s body %s %s" % (
                ledger, response_id, note_id))
        midway = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        note_err = expect_note(midway, staff, summary)
        if note_err:
            problems.append(note_err)
        replies = [entry for entry in midway if entry["type"] == "R"]
        if len(replies) != 1 or replies[0]["id"] != response_id:
            problems.append("reply rows %s" % replies)
        retry = post("send", fields, rid=sent["rid"])
        if retry["code"] != 500 or retry["raw"] != sent["raw"]:
            problems.append("retry is not the stored result %s %s" % (
                retry["code"], retry["raw"][:400]))
        final = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        if len([entry for entry in final if entry["type"] == "R"]) != 1:
            problems.append("retry added a reply")
        if len([entry for entry in final if entry["type"] == "N"]) != 1:
            problems.append("retry added a note")
        done = ctl(["ledger", sent["rid"]])
        if done.get("status") != "reply_recorded_mail_unknown" or done.get("note_entry_id") != note_id:
            problems.append("ledger after retry %s" % done)
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("12 interrupted native call is mail_handoff_unknown and the retry matches", not problems, detail)


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
        if not ledger.get("native_returned"):
            problems.append("native_returned was not set after postReply returned")
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


def write_template(number, subject, body):
    return ctl(
        ["set-reply-template", number],
        stdin=json.dumps({"subject": subject, "body": body}),
    )


def restore_template(number, original, problems):
    try:
        restored = write_template(number, original["subject"], original["body"])
    except (Exception, SystemExit) as exc:
        problems.append("template restore failed: %s" % exc)
        return
    if restored.get("subject") != original["subject"] or restored.get("body") != original["body"]:
        problems.append("template was not restored")


def check_unknown_does_not_adopt(staff, ticket):
    before = ctl(["state", ticket["number"]])
    marker = "Bridge unknown %s" % uuid.uuid4().hex[:12]
    summary = "This request must stay unknown after a later staff reply."
    fields = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker,
    }
    preview = post("preview", fields)
    if preview["code"] != 200 or not preview["json"].get("ok"):
        record("18 failure before the reply does not adopt a later one", False,
               "preview failed %s" % preview["raw"][:400])
        return
    fields["preview_hash"] = preview["json"]["preview_hash"]
    fields["note_summary"] = summary
    problems = []
    sent = None
    try:
        ctl(["set-fault", "before_reply"])
        sent = post("send", fields)
    finally:
        ctl(["set-fault", "off"])
    if not sent or sent["code"] != 500 or not sent["json"] or sent["json"].get("error") != "reply_state_unknown":
        problems.append("first send %s %s" % (
            sent["code"] if sent else 0, (sent or {}).get("raw", "")[:500]))
    else:
        if sent["json"].get("ok") or sent["json"].get("response_entry_id") is not None:
            problems.append("unknown result claimed an entry %s" % sent["json"])
        message = sent["json"].get("message") or ""
        lower = message.lower()
        if "did not save" in lower:
            problems.append("message claims the reply was not saved: %s" % message)
        if ("unknown" not in lower or "ticket thread" not in lower
                or "reply sent using claude" not in lower or "by hand" not in lower
                or "will not be sent again" not in lower):
            problems.append("message does not say the handoff is unknown: %s" % message)
        ledger = ctl(["ledger", sent["rid"]])
        if ledger.get("status") != "reply_state_unknown" or ledger.get("response_entry_id"):
            problems.append("ledger %s" % ledger)
        ui_text = "UI reply that must not be adopted %s" % uuid.uuid4().hex[:12]
        ui = ctl(["ui-reply", ticket["number"], staff["username"], ui_text])
        if not ui.get("response_entry_id"):
            problems.append("ui reply did not post %s" % ui)
        midway = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        replies = [entry for entry in midway if entry["type"] == "R"]
        notes = [entry for entry in midway if entry["type"] == "N"]
        if len(replies) != 1 or replies[0]["id"] != ui.get("response_entry_id"):
            problems.append("expected only the UI reply, got %s" % replies)
        if notes:
            problems.append("a note was posted for the unknown request")
        retry = post("send", fields, rid=sent["rid"])
        if retry["code"] != 500 or retry["raw"] != sent["raw"]:
            problems.append("retry is not the stored result %s %s" % (
                retry["code"], retry["raw"][:400]))
        done = ctl(["ledger", sent["rid"]])
        if done.get("response_entry_id") or done.get("note_entry_id") or done.get("status") != "reply_state_unknown":
            problems.append("retry adopted an entry %s" % done)
        final = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
        if len([entry for entry in final if entry["type"] == "R"]) != 1:
            problems.append("retry posted a reply")
        if any(entry["type"] == "N" for entry in final):
            problems.append("retry posted a note")
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("18 failure before the reply does not adopt a later one", not problems, detail)


def check_distinct_notes(staff, ticket):
    before = ctl(["state", ticket["number"]])
    summary_a = "Note A is owed by the first request only."
    summary_b = "Note B belongs to the second request."
    marker_a = "Bridge note A %s" % uuid.uuid4().hex[:12]
    marker_b = "Bridge note B %s" % uuid.uuid4().hex[:12]
    fields_a = {
        "ticket_number": ticket["number"],
        "staff_username": staff["username"],
        "response_html": "<p>%s</p>" % marker_a,
    }
    preview_a = post("preview", fields_a)
    if preview_a["code"] != 200 or not preview_a["json"].get("ok"):
        record("19 a failed note is posted on retry and is not the other send's note", False,
               "preview A failed %s" % preview_a["raw"][:400])
        return
    fields_a["preview_hash"] = preview_a["json"]["preview_hash"]
    fields_a["note_summary"] = summary_a
    problems = []
    sent_a = None
    try:
        ctl(["set-fault", "note_fail"])
        sent_a = post("send", fields_a)
    finally:
        ctl(["set-fault", "off"])
    if not sent_a or sent_a["code"] != 500 or not sent_a["json"] or sent_a["json"].get("error") != "partial_note_missing":
        problems.append("send A %s %s" % (
            sent_a["code"] if sent_a else 0, (sent_a or {}).get("raw", "")[:400]))
    else:
        fields_b = {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
            "response_html": "<p>%s</p>" % marker_b,
        }
        preview_b = post("preview", fields_b)
        if preview_b["code"] != 200 or not preview_b["json"].get("ok"):
            problems.append("preview B %s" % preview_b["raw"][:300])
        else:
            fields_b["preview_hash"] = preview_b["json"]["preview_hash"]
            fields_b["note_summary"] = summary_b
            sent_b = post("send", fields_b)
            if sent_b["code"] != 200 or not sent_b["json"] or not sent_b["json"].get("ok"):
                problems.append("send B %s %s" % (sent_b["code"], sent_b["raw"][:400]))
            else:
                note_b = sent_b["json"].get("note_entry_id")
                retry = post("send", fields_a, rid=sent_a["rid"])
                if retry["code"] != 200 or not retry["json"] or not retry["json"].get("ok"):
                    problems.append("retry A %s %s" % (retry["code"], retry["raw"][:400]))
                elif (retry["json"].get("error") == "stale_preview"
                        or retry["json"].get("note_entry_id") == note_b):
                    problems.append("retry A did not post its own note %s" % retry["json"])
                else:
                    note_a = retry["json"].get("note_entry_id")
                    final = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
                    replies = [entry for entry in final if entry["type"] == "R"]
                    notes = [entry for entry in final if entry["type"] == "N"]
                    if len(replies) != 2:
                        problems.append("R count %s" % len(replies))
                    if len(notes) != 2:
                        problems.append("N count %s" % len(notes))
                    bodies = {entry["id"]: entry["body"].replace("\r\n", "\n") for entry in notes}
                    want_a = "Reply sent using Claude by %s\n\n%s" % (staff["name"], summary_a)
                    want_b = "Reply sent using Claude by %s\n\n%s" % (staff["name"], summary_b)
                    if bodies.get(note_a) != want_a:
                        problems.append("note A body %r" % bodies.get(note_a))
                    if bodies.get(note_b) != want_b:
                        problems.append("note B body %r" % bodies.get(note_b))
                    if note_a == note_b:
                        problems.append("note ids are not distinct")
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("19 a failed note is posted on retry and is not the other send's note", not problems, detail)


def check_body_hash(staff, ticket):
    original = ctl(["reply-template", ticket["number"]])
    before = ctl(["state", ticket["number"]])
    problems = []
    try:
        written = write_template(
            ticket["number"],
            original["subject"],
            "<p>Static reply template with no response variable.</p><p>%{poster}</p>",
        )
        if "%{response}" in (written.get("body") or "").lower():
            problems.append("template still contains the response variable: %s" % written.get("body"))
        else:
            marker_a = "Alpha body %s" % uuid.uuid4().hex[:12]
            marker_b = "Beta body %s" % uuid.uuid4().hex[:12]
            fields_a = {
                "ticket_number": ticket["number"],
                "staff_username": staff["username"],
                "response_html": "<p>%s</p>" % marker_a,
            }
            fields_b = {
                "ticket_number": ticket["number"],
                "staff_username": staff["username"],
                "response_html": "<p>%s</p>" % marker_b,
            }
            first = post("preview", fields_a)
            second = post("preview", fields_b)
            if first["code"] != 200 or not first["json"].get("ok") or second["code"] != 200 or not second["json"].get("ok"):
                problems.append("preview %s %s" % (first["raw"][:200], second["raw"][:200]))
            else:
                if first["json"].get("body") != second["json"].get("body"):
                    problems.append("rendered bodies differ without a response variable")
                if first["json"].get("preview_hash") == second["json"].get("preview_hash"):
                    problems.append("hashes match for different response_html")
                fields_b["preview_hash"] = first["json"]["preview_hash"]
                fields_b["note_summary"] = "This send must be refused as stale."
                sent = post("send", fields_b)
                after = ctl(["state", ticket["number"]])
                entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
                if not (sent["code"] == 409 and sent["json"] and sent["json"].get("error") == "stale_preview"):
                    problems.append("send %s %s" % (sent["code"], sent["raw"][:300]))
                if any(entry["type"] == "R" for entry in entries) or after["last_entry_id"] != before["last_entry_id"]:
                    problems.append("thread moved")
    finally:
        restore_template(ticket["number"], original, problems)
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("20 changed response_html is stale when the template omits response", not problems, detail)


def check_due_preview(staff, ticket):
    original = ctl(["reply-template", ticket["number"]])
    before = ctl(["state", ticket["number"]])
    problems = []
    outcome = ""
    try:
        written = write_template(
            ticket["number"],
            "Due %{ticket.due_date} ref %{ticket.number}",
            "<p>%{response}</p><p>Due %{ticket.due_date}</p>",
        )
        blob = (written.get("subject") or "") + "\n" + (written.get("body") or "")
        if "ticket.due_date" not in blob:
            problems.append("due_date token was not stored: %s" % blob[:300])
        else:
            opts = post("options", {
                "ticket_number": ticket["number"],
                "staff_username": staff["username"],
            })
            closed = []
            if opts["json"] and opts["json"].get("statuses"):
                closed = [item for item in opts["json"]["statuses"]["options"] if item.get("state") == "closed"]
            if not closed:
                problems.append("no closed status in the reply choices")
            else:
                marker = "Bridge due date %s" % uuid.uuid4().hex[:12]
                fields = {
                    "ticket_number": ticket["number"],
                    "staff_username": staff["username"],
                    "response_html": "<p>%s</p>" % marker,
                    "reply_status_id": closed[0]["id"],
                }
                preview = post("preview", fields)
                err = (preview["json"] or {}).get("error")
                if preview["code"] == 409 and err == "preview_unsupported":
                    outcome = "preview_unsupported"
                    variables = (preview["json"] or {}).get("variables") or []
                    if "ticket.due_date" not in variables:
                        problems.append("variables %s" % variables)
                elif preview["code"] == 200 and preview["json"] and preview["json"].get("ok"):
                    outcome = "exact"
                    fields["preview_hash"] = preview["json"]["preview_hash"]
                    fields["note_summary"] = "Closing reply used to compare the due date."
                    before_mail = mail_index()
                    sent = post("send", fields)
                    if sent["code"] != 200 or not sent["json"] or not sent["json"].get("ok"):
                        problems.append("send %s %s" % (sent["code"], sent["raw"][:400]))
                    else:
                        hits = mail_for_marker(new_messages(before_mail), marker, ticket["owner_email"])
                        if len(hits) != 1:
                            problems.append("mail hits %s" % len(hits))
                        else:
                            mail_text = visible_text((hits[0].get("HTML") or "") + "\n" + (hits[0].get("Text") or ""))
                            prev_text = visible_text(preview["json"].get("body") or "")
                            if not prev_text or prev_text not in mail_text:
                                problems.append("preview body is not the mail body")
                            if hits[0].get("Subject") != preview["json"].get("subject"):
                                problems.append("subject %r preview %r" % (
                                    hits[0].get("Subject"), preview["json"].get("subject")))
                else:
                    problems.append("preview %s %s" % (preview["code"], preview["raw"][:400]))
    finally:
        restore_template(ticket["number"], original, problems)
        changed = True
        try:
            later = ctl(["state", ticket["number"]])
            changed = (
                later["status_id"] != before["status_id"]
                or later["staff_id"] != before["staff_id"]
            )
        except (Exception, SystemExit) as exc:
            problems.append("could not read the ticket after the due-date check: %s" % exc)
        if changed:
            try:
                restored_state = ctl(["restore", ticket["number"], str(before["status_id"]), str(before["staff_id"])])
                if restored_state.get("status_id") != before["status_id"] or restored_state.get("state") != "open":
                    problems.append("restore left the ticket %s" % restored_state)
            except (Exception, SystemExit) as exc:
                problems.append("ticket restore failed: %s" % exc)
    detail = "ticket %s %s" % (ticket["number"], outcome or "no-result")
    if problems:
        detail += "\n" + "\n".join(problems)
    record("21 closing preview with ticket.due_date is exact or unsupported", not problems, detail)


def check_planted_config(staff, ticket):
    ctl(["set-fault", "off"])
    planted = ctl(["plant-fault-config", "after_reply"])
    problems = []
    sent = None
    try:
        if planted.get("value") != "after_reply":
            problems.append("config row was not planted %s" % planted)
        else:
            before = ctl(["state", ticket["number"]])
            marker = "Bridge planted config %s" % uuid.uuid4().hex[:12]
            summary = "A stored test_fault value must not change this reply."
            fields = {
                "ticket_number": ticket["number"],
                "staff_username": staff["username"],
                "response_html": "<p>%s</p>" % marker,
            }
            preview = post("preview", fields)
            if preview["code"] != 200 or not preview["json"].get("ok"):
                problems.append("preview %s" % preview["raw"][:300])
            else:
                fields["preview_hash"] = preview["json"]["preview_hash"]
                fields["note_summary"] = summary
                sent = post("send", fields)
                if sent["code"] != 200 or not sent["json"] or not sent["json"].get("ok"):
                    problems.append("send %s %s" % (sent["code"], sent["raw"][:400]))
                elif sent["json"].get("mail") != "handed_to_osticket":
                    problems.append("mail %s" % sent["json"].get("mail"))
                else:
                    ledger = ctl(["ledger", sent["rid"]])
                    if ledger.get("status") != "completed" or not ledger.get("native_returned"):
                        problems.append("ledger %s" % ledger)
                    entries = ctl(["entries", ticket["number"], str(before["last_entry_id"])])["entries"]
                    note_err = expect_note(entries, staff, summary)
                    if note_err:
                        problems.append(note_err)
                    if len([entry for entry in entries if entry["type"] == "R"]) != 1:
                        problems.append("R count is not 1")
    finally:
        cleared = ctl(["clear-fault-config"])
        if cleared.get("value") is not None:
            problems.append("config row remains %s" % cleared)
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("22 a persisted test_fault value is ignored", not problems, detail)


def check_thread_vars(staff, ticket):
    original = ctl(["reply-template", ticket["number"]])
    blob = ((original.get("subject") or "") + "\n" + (original.get("body") or "")).lower()
    problems = []
    if "ticket.thread.lastmessage" not in blob:
        record("23 thread.complete is unsupported and thread.lastmessage matches the mail", False,
               "real template has no ticket.thread.lastmessage\n%s" % blob[:400])
        return
    try:
        marker = "Bridge lastmessage %s" % uuid.uuid4().hex[:12]
        fields = {
            "ticket_number": ticket["number"],
            "staff_username": staff["username"],
            "response_html": "<p>%s</p>" % marker,
        }
        before_mail = mail_index()
        preview = post("preview", fields)
        if preview["code"] != 200 or not preview["json"] or not preview["json"].get("ok"):
            problems.append("lastmessage preview %s %s" % (preview["code"], preview["raw"][:400]))
        elif (preview["json"].get("error") == "preview_unsupported"):
            problems.append("lastmessage was refused %s" % preview["json"].get("variables"))
        else:
            fields["preview_hash"] = preview["json"]["preview_hash"]
            fields["note_summary"] = "Real template, last customer message stays in the mail."
            sent = post("send", fields)
            if sent["code"] != 200 or not sent["json"] or not sent["json"].get("ok"):
                problems.append("lastmessage send %s %s" % (sent["code"], sent["raw"][:400]))
            else:
                hits = mail_for_marker(new_messages(before_mail), marker, ticket["owner_email"])
                if len(hits) != 1:
                    problems.append("lastmessage mail hits %s" % len(hits))
                else:
                    mail_err = check_reply_mail(
                        preview["json"], new_messages(before_mail), ticket["owner_email"], marker)
                    if mail_err:
                        problems.append(mail_err)
                    mail_text = visible_text((hits[0].get("HTML") or "") + "\n" + (hits[0].get("Text") or ""))
                    prev_text = visible_text(preview["json"].get("body") or "")
                    if not prev_text or prev_text not in mail_text:
                        problems.append("preview body is not the mail body (%s vs %s chars)" % (
                            len(prev_text), len(mail_text)))

        mid = ctl(["state", ticket["number"]])
        written = write_template(
            ticket["number"],
            "Thread %{ticket.number}",
            "<p>%{response}</p><p>%{ticket.thread.complete}</p>",
        )
        written_blob = ((written.get("subject") or "") + "\n" + (written.get("body") or "")).lower()
        if "ticket.thread.complete" not in written_blob:
            problems.append("complete token was not stored")
        else:
            refused = post("preview", {
                "ticket_number": ticket["number"],
                "staff_username": staff["username"],
                "response_html": "<p>This preview must be refused.</p>",
            })
            err = (refused["json"] or {}).get("error")
            variables = (refused["json"] or {}).get("variables") or []
            if refused["code"] != 409 or err != "preview_unsupported":
                problems.append("complete preview %s %s" % (refused["code"], refused["raw"][:400]))
            elif "ticket.thread.complete" not in variables:
                problems.append("variables %s" % variables)
            after = ctl(["state", ticket["number"]])
            if after["last_entry_id"] != mid["last_entry_id"]:
                problems.append("refused preview posted an entry %s -> %s" % (
                    mid["last_entry_id"], after["last_entry_id"]))
    finally:
        restore_template(ticket["number"], original, problems)
    detail = "ticket %s" % ticket["number"]
    if problems:
        detail += "\n" + "\n".join(problems)
    record("23 thread.complete is unsupported and thread.lastmessage matches the mail", not problems, detail)


def cleanup_faults():
    for args in (["set-fault", "off"], ["clear-fault-config"], ["set-max", "8388608"]):
        try:
            ctl(args)
        except (Exception, SystemExit) as exc:
            say("cleanup %s failed: %s" % (" ".join(args), exc))


if __name__ == "__main__":
    code = 1
    try:
        code = main()
    except Exception as exc:
        say("ERROR %s" % exc)
        raise
    finally:
        cleanup_faults()
    sys.exit(code)
