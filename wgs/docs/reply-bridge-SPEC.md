# WGS Reply Bridge: spec

Owner: David Young. Lead: Claude. Status: build, staging only. Date: 2026-11-01.

## Goal

Let WGS staff, working through Claude, post a real **reply to the customer** on
an osTicket ticket. The reply must take exactly the path a human reply takes
when the staff member clicks **Post Reply** in the staff panel. osTicket then
sends the email itself, following its own live settings.

## Hard rules (from David)

1. **Nothing may bypass osTicket to send email.** No SMTP, no PHP `mail()`, no
   email tool, and no SQL that fakes a thread entry. The bridge calls
   osTicket's own `Ticket::postReply()`. osTicket's own mailer sends.
2. **The reply is posted as the staff member who asked.** The customer sees
   exactly what they would see if that staff member had logged in and replied:
   the name, signature, From address, department template and permissions.
   There is no bot name.
3. **Follow the live rules. Never hard-code them.** Every decision that the
   staff UI makes from settings (From choices, signature choices, status
   choices, auto-claim, alerts, collaborators, templates, ban list, lock mode)
   must be read from osTicket at request time, through osTicket's own classes.
   When an admin changes a setting, the bridge must follow the new setting with
   no code change.
4. **After each reply, post one internal note as the same staff member**:

   ```
   Reply sent using Claude by <staff full name>

   <summary>
   ```

   - Line 1 exactly as shown, with the staff member's display name.
   - Line 2 blank.
   - Then the summary that the caller supplies (required, not empty). It says
     what Claude did and why. Examples: "Kayla asked me to reply to the
     customer." / "Changed the speaker impedance on Shopify order #30965 to
     8 ohm; it synced to xTuple SO 591580." / "Trevor had me research X. Found
     Y. Sources: ... Reasoning: ...".
   - Post it with osTicket's own `postNote()`. It must **not** change the
     ticket status (a ticket the reply closed stays closed; an open one stays
     open), must **not** send staff alert emails (`alert=false`), and must not
     claim or reassign the ticket.
5. Production is not touched by this build. All tests run on the staging stack.

## Where it runs

- A plugin directory installed on the osTicket server:
  `include/plugins/wgs-reply-bridge/` (`plugin.php`, `config.php`, PHP classes).
  Source lives in this repo at `wgs/plugins/wgs-reply-bridge/`.
- It registers routes on osTicket's own API dispatcher by listening to the
  `api` signal (`api/http.php` calls `Signal::send('api', $dispatcher)`).
  Routes live under `/api/wgs-reply/`.
- Production later: Rod or David copies the directory into
  `/var/www/osticket/include/plugins/` and enables it in Admin Panel > Plugins.
  Apache runs as `www-data`. PHP 8.2.35.

## Authentication (machine to machine)

The only caller is the osticket-mcp service on 192.168.1.193.

- Plugin config fields: `shared_secret` (password field), `allowed_ips`
  (comma list, default `192.168.1.193`), `enabled` (bool), `max_body_bytes`.
- Every request carries headers:
  - `X-WGS-Timestamp`: unix seconds. Reject if more than 300 s from server time.
  - `X-WGS-Request-Id`: caller UUID. Used for idempotency (below).
  - `X-WGS-Signature`: hex HMAC-SHA256 of
    `timestamp + "\n" + request_id + "\n" + METHOD + "\n" + path + "\n" + sha256_hex(body)`
    with `shared_secret`. Compare with `hash_equals`.
- Reject when the source IP is not in `allowed_ips` (use osTicket's own client
  IP helper, so it matches how osTicket reads IPs).
- Never accept browser cookies or a staff session as auth on these routes.
  Never disable CSRF globally.
- Never log the secret, signatures, or message bodies.

## Acting staff

- Every call names the acting staff member by `staff_username`.
- Look up with osTicket's `Staff` class. Refuse if not found or not active.
- Set the global `$thisstaff` to that staff object for the whole request, so
  that `postReply`, `postNote`, signatures, From name, permissions and the
  Help Desk Audit plugin all see that staff member.
- Then apply the same checks the staff UI applies: ticket access
  (`checkStaffPerm`), role permission `ticket.reply` in the ticket's department,
  closed-status choices only with `ticket.close`, and so on. If the staff member
  cannot do it in the UI, the bridge refuses.
- Caller-supplied `staffId`, `poster`, `pid`, `flags`, or raw `$vars` are never
  passed through.

## Routes

All are `POST` with a JSON body; responses are JSON. Ticket is addressed by its
displayed `ticket_number` (the bridge resolves the internal id).

### `POST /api/wgs-reply/options`
Input: `ticket_number`, `staff_username`.
Returns what the Post Reply form would show to that staff member right now:
- `can_reply` (+ reason when false: no access, no `ticket.reply`, merged child,
  banned owner, invalid owner email, ...)
- ticket: number, subject, status (id, name, state), department, assignee,
  lock state (held by whom, until when)
- `from_emails`: the From choices the UI dropdown would list, with the default
- `reply_to`: options `all` / `user` / `none`, the owner, and the collaborators
  with their user ids and whether each is active (= checked by default)
- `signatures`: available options (`none`, `mine` only if the staff has one,
  `dept` only if the dept is public with a signature) and the default
- `statuses`: the reply status choices the UI would list, and the default
  (current status)
- `canned_responses`: id + title of the enabled ones this staff may use
- `attachments`: allowed (bool), max size, max count, allowed types
- `auto_claim`: whether replying would auto-assign the ticket to this staff
  member under current settings (informational; native behavior is kept)

### `POST /api/wgs-reply/canned`
Input: `ticket_number`, `staff_username`, `canned_id` (number, `original`, or
`lastmessage`). Returns the expanded body (and attachment names) exactly as the
UI's canned-response insert produces it, using osTicket's own expansion.

### `POST /api/wgs-reply/preview`
Input: the reply fields (below). Runs every check the real send runs, writes
nothing, and returns:
- recipients (To and Cc addresses) that osTicket would use
- From address and From display name that osTicket would use
- signature that would be appended, status after, auto-claim yes/no
- the subject and body as rendered from the department `ticket.reply` template,
  built with osTicket's own template and variable replacement
- `preview_hash`: HMAC over the normalized inputs, the acting staff id, and the
  ticket's current state (thread last entry id, status id, collaborator set).
  Expires after 15 minutes.

### `POST /api/wgs-reply/send`
Input: the reply fields + `preview_hash` + `note_summary`.
1. Idempotency: if `X-WGS-Request-Id` was already completed, return the stored
   result and do nothing else. Keep a small ledger table
   (`<prefix>wgs_reply_ledger`: request_id unique, ticket_id, staff_id,
   status, response_entry_id, note_entry_id, created, result_json), created by
   the plugin if missing.
2. Re-run all checks. Refuse if `preview_hash` does not match the current state
   (someone else replied, status changed, collaborators changed).
3. Acquire osTicket's own ticket lock as the acting staff (respect the lock
   mode setting; refuse if another agent holds it).
4. Collaborators: apply `cc_user_ids` exactly as the controller does. If the
   caller omits `cc_user_ids`, pass the **currently active** collaborators so
   none are deactivated (omitting `ccs[]` in the real form deactivates all of
   them; the bridge must not do that by accident).
5. Call `Ticket::postReply($vars, $errors, $alert, $claim)` the way
   `scp/tickets.php` does for `a=reply` (same `$vars` keys, `$alert` true unless
   `reply_to=none`, native claim behavior).
6. Post the internal note (rule 4) with `postNote(..., alert=false)` and no
   status change.
7. Release the lock, delete the acting staff member's response draft for the
   ticket (same as the UI).
8. Re-read actual state and return: response entry id, note entry id, ticket
   status after, assignee after, recipients, and `mail: "handed_to_osticket"`.
   (Stock osTicket discards the mailer result, so the bridge must not claim
   delivery.)
9. Record the ledger row.

### Reply fields
`ticket_number`, `staff_username`, `response_html` (required), `signature`
(`none|mine|dept`, default = staff default), `reply_to` (`all|user|none`,
default `all`), `cc_user_ids` (optional list), `from_email_id` (optional,
default = dept default; must be one of the `options` choices),
`reply_status_id` (optional, default = current status; must be one of the
`options` choices), `attachments` (optional list of `{name, mime, base64}`,
checked against the live attachment settings and stored through osTicket's own
file classes).

## Errors
`{ "ok": false, "error": "<code>", "message": "<plain text>" }` with codes such as
`auth_failed`, `ip_not_allowed`, `stale_timestamp`, `staff_not_found`,
`staff_inactive`, `no_access`, `no_reply_permission`, `ticket_not_found`,
`locked_by_other`, `stale_preview`, `invalid_option`, `banned_owner`,
`empty_response`, `empty_summary`, `already_done` (with stored result).

## Staging (where this is tested)

- Stack: `/Volumes/PRO-G40/Docker/osticket-staging/compose.yaml`.
  Containers `osticket_staging_web` (php 8.2 + apache, code at `./app`),
  `osticket_staging` (MariaDB, db `osticket`, prefix `ostka_`),
  `osticket_staging_mailpit`, `osticket_staging_edge` (nginx proxy).
- Staff UI: http://127.0.0.1:8095/scp/ . Mail catcher UI and API:
  http://127.0.0.1:8025/ (Mailpit API `/api/v1/messages`).
- The web and mailpit containers are on a Docker network with no internet.
  All SMTP accounts point at Mailpit. All customer and staff addresses end in
  `@staging.invalid`. PHP `sendmail_path=/bin/false`.
- Requests from the host reach Apache through the edge proxy, so the bridge
  sees the proxy's IP. Put that IP (or the `ost_internal` subnet) in
  `allowed_ips` on staging only.

## Acceptance (all on staging)

1. Reply as a real staff username on a real open ticket: one `R` entry by that
   staff member, one Mailpit message to the owner from the department address
   with that staff member's name/signature per their preferences, then one `N`
   entry with the exact note format. No status change from the note.
2. Same with `reply_status_id` = a closed status, by a staff member with
   `ticket.close`: ticket ends closed, note does not reopen it.
3. Staff without access to the department: refused, nothing written.
4. Staff whose role lacks `ticket.reply`: refused.
5. Change a setting in the admin panel (for example the staff member's
   default signature, or the department's From email), repeat: the new
   setting is followed with no code change.
6. Replay the same `X-WGS-Request-Id`: no second reply, same result.
7. Bad signature / old timestamp / wrong IP: refused, nothing written.
8. `reply_to=none`: reply recorded, no customer email, note posted.
9. Collaborators untouched when `cc_user_ids` is omitted.
10. Preview then a new customer message arrives: send refused as `stale_preview`.

---

# v2 additions (David, 2026-11-01, after the first live reply)

## V2-1. Verify every send
After the reply and the internal note, the bridge re-reads osTicket (fresh
objects / DB, not in-memory state) and returns
`verification: {reply_recorded, reply_by_staff, reply_body_matches,
note_recorded, note_text_exact, status_expected, status_actual,
status_matches, all_passed}`. Any false item: `ok:false`, code
`verification_failed`, with the block and the entry ids. Never resend.
A replayed request id re-runs the verification against the stored ids.

## V2-2. Default close (decided in the tools, exposed by the bridge)
- New plugin config field `default_close_status` (choice of enabled
  statuses whose state is `closed`; default = the status named `Closed`,
  else the lowest-id closed status). Production: "Closed" (id 3), not
  "Cold Sales Lead".
- `options` adds `default_close_status {id,name}` and `can_close` (the acting
  staff may close this ticket now: `ticket.close` role permission plus the
  ticket's own closeability, e.g. open tasks / required fields).
- The bridge keeps `reply_status_id` explicit (default = current status).
  The MCP tools choose: close by default with `default_close_status` when
  `can_close`; leave open only when the staff member said so (`leaveOpen`);
  when the staff cannot close, keep the current status and warn.

## V2-3. Standalone status change: `POST /api/wgs-reply/status`
Input: `ticket_number`, `staff_username`, `status_id`, `summary` (required).
As the acting staff, with the same checks the staff UI status change uses
(access, `ticket.close` for closed states, reopen rules), call native
`Ticket::setStatus()`. Then post an internal note via `postNote`
(alert=false, no further status change):
```
Status set to <status name> using Claude by <staff full name>

<summary>
```
Idempotent by `X-WGS-Request-Id` (same ledger). Verify like V2-1
(status_matches, note_recorded, note_text_exact). Refuse a no-op
(already in that status) with code `no_change`.

## V2-4. Template awareness
`options` and `preview` add `template`:
`{adds_greeting, greeting_rendered, adds_staff_name, adds_signature,
before_response_rendered, after_response_rendered}`, computed from the
department's live `ticket.reply` template by splitting at `%{response}` and
rendering each side with osTicket's own variable replacement (so it follows
template edits). `preview.warnings` flags a response that starts with a
greeting (Hi/Hello/Dear/Hey/<recipient first name>,) when the template adds
one, or ends with the staff member's name / a sign-off when the template
adds `%{poster}`, or includes a signature block when one is selected.

## V2-5. Secret entry that browsers cannot spoil
- The Shared secret input must carry `autocomplete="new-password"` (or the
  osTicket-supported equivalent) and its hint must say browsers may autofill
  a saved password there; clear it, paste, save.
- Ship `wgs/plugins/wgs-reply-bridge/cli/set-secret.php` (refuses non-CLI),
  the reviewed one-time script: reads the secret from STDIN, stores it through
  the field's own `to_database`, re-reads through a fresh config, prints only
  lengths and `readback_match`. Usage in the README and Help.

## V2-6. Help inside osTicket
Setup documentation available from the plugin's admin page: if osTicket
1.17.6 lets a plugin add its own tab without changing core files, add a
"Help" tab; otherwise a clearly titled Help section at the top of the Config
tab (FreeText / section field). Content: what the bridge does, every config
field, install on a new server, adding another instance (e.g. a second MCP
host: its own instance, IP, secret), setting the secret (UI and CLI),
the MCP side (.env keys, staff_identities.json, mux identities file +
`MCP_MUX_ALLOW_ANONYMOUS`), personal keys per staff member, how to test,
and troubleshooting (disabled, ip_not_allowed, auth_failed, stale_timestamp,
verification_failed). No secrets in the help text. Never modify core files.
