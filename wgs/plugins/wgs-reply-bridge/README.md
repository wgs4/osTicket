# WGS Reply Bridge

osTicket plugin that posts a staff reply by calling `Ticket::postReply()`, the
same way the staff panel does for Post Reply. osTicket's own mailer sends the
email. The bridge does not open an SMTP connection and does not insert thread
rows.

Source lives at `wgs/plugins/wgs-reply-bridge/`. Install it as a directory
plugin at `include/plugins/wgs-reply-bridge/`.

## Production install

1. Copy this directory to `/var/www/osticket/include/plugins/wgs-reply-bridge/`
   on the osTicket host (Apache user `www-data`, PHP 8.2).
2. Admin Panel > Manage > Plugins. Install **WGS Reply Bridge**, then enable it.
3. Add the instance and set:
   - **Shared secret.** HMAC key for the caller. A password field, so osTicket
     encrypts it. The box uses `autocomplete="new-password"`. Browsers may
     autofill a saved password there. Clear it, paste the secret, and save.
     Use at least 8 characters with upper, lower, and a digit or symbol (the
     password-policy plugin applies). From the osTicket host, the same write
     can be done without a browser:

     ```
     printf '%s' "$SECRET" | php include/plugins/wgs-reply-bridge/cli/set-secret.php INSTANCE_ID
     ```

     The script reads stdin, stores the value through the field's own
     `to_database`, reads it back through a fresh config, and prints only
     lengths and `readback_match`. It never prints the secret. It refuses
     every SAPI other than `cli`.
   - **Allowed IPs.** Comma-separated IPs or CIDR blocks. Default
     `192.168.1.193`, which is the osticket-mcp host. Checked with
     `osTicket::get_client_ip()`.
   - **Enabled.** Turns the routes off without disabling the plugin.
   - **Max body bytes.** Default `8388608`.
   - **Default close status.** An enabled status whose state is closed. The
     default is the status named Closed, or the lowest-id closed status when
     that name is missing. The reply route does not apply it.
     `reply_status_id` stays explicit and defaults to the current status.

   A second MCP host is a second instance, with its own allowed IP and its
   own secret. The Help section at the top of the Config tab describes
   install, a second instance, the CLI, and the MCP files. osTicket 1.17
   hardcodes the Instance and Config tabs, so the plugin cannot add a Help
   tab without a core edit. The section is a FreeText field instead.
4. The first request creates `<prefix>wgs_reply_ledger` if it is missing.
   An existing table gains `baseline_entry_id`, `executing_at`, and
   `native_returned` when those columns are absent, and `status` is widened
   to `VARCHAR(32)` when it is narrower. Rows are not dropped.

There is no test-fault setting. A value stored under that name in plugin
config is ignored. Faults run only when the PHP constant
`WGS_REPLY_BRIDGE_TEST_FAULTS` is defined, which production never does.

Do not point staging at production, and do not change SMTP or email-account
rows as part of installing this.

## Route contract

All routes are `POST` with a JSON object body and a JSON response. The ticket
is the displayed ticket number. The acting agent is `staff_username`. The
bridge sets `$thisstaff` to that agent and refuses the call when that agent
could not do the same thing in the staff UI.

Every request needs these headers:

- `X-WGS-Timestamp`: unix seconds. Rejected when it is more than 300 seconds
  from the server clock.
- `X-WGS-Request-Id`: a 36-character caller id (`A-Z a-z 0-9 -`).
- `X-WGS-Signature`: hex HMAC-SHA256 of

  ```
  timestamp + "\n" + request_id + "\n" + METHOD + "\n" + path + "\n" + sha256_hex(body)
  ```

  `METHOD` is uppercase. `path` is the public path, for example
  `/api/wgs-reply/send`, not the rewritten `PATH_INFO`. The key is the shared
  secret. Comparison uses `hash_equals`.

A browser session or cookie is never accepted. CSRF is not disabled.

| Route | Body | Result |
| --- | --- | --- |
| `/api/wgs-reply/options` | `ticket_number`, `staff_username` | What the Post Reply form would show that agent right now, plus `default_close_status`, `can_close`, and `template` |
| `/api/wgs-reply/canned` | those, plus `canned_id` (`original`, `lastmessage`, or a numeric id) | The expanded body and attachment names |
| `/api/wgs-reply/preview` | the reply fields | Recipients, From, signature, status after, rendered `ticket.reply` subject and body, `template`, `warnings`, and `preview_hash`. Writes nothing |
| `/api/wgs-reply/send` | the reply fields, `preview_hash`, and `note_summary` | Posts the reply, then the internal note, then returns `verification` |
| `/api/wgs-reply/status` | `ticket_number`, `staff_username`, `status_id`, `summary` | `Ticket::setStatus`, then one internal note. Does not require `ticket.reply` |

Reply fields: `response_html` (required), `signature` (`none`, `mine`, `dept`;
default is the agent's default when that choice exists), `reply_to` (`all`,
`user`, `none`; default `all`), `cc_user_ids` (optional user ids),
`from_email_id` (optional; default is the department address; must be one of
the options), `reply_status_id` (optional; default is the current status; must
be one of the options), `attachments` (optional `{name, mime, base64}`).

`preview_hash` is `{unix_time}.{hmac}` over a version `v3` material. Each
field is length-prefixed (`byte_length:value`) so a newline in the body
cannot collide with the next field. It binds the acting staff id, ticket id,
last thread entry id, status id, owner user id, the resolved To and Cc
addresses, the From email id, address, and display name, the resolved
signature text, the rendered subject and body, `reply_to`, `reply_status_id`,
the collaborator user ids that will be applied, and each attachment as
`name:mime:sha256`. It also binds the sha256 of the cleaned response, the
sha256 of the raw `response_html`, the signature option (`none`, `mine`, or
`dept`), whether `cc_user_ids` was omitted, and the selected `from_email_id`.
To, Cc, collaborator ids, and attachments are sorted before the HMAC. It
expires after 15 minutes. `note_summary` is not part of the hash. A hash from
a previous version does not match. The raw response is bound on its own, so
a department template that does not print `%{response}` still goes stale when
`response_html` changes.

Preview computes recipients from the collaborator selection in the request
without writing it. An explicit `cc_user_ids` of `[]` means no collaborator
is active. A selected collaborator is included even when the stored row is
inactive. The rendered subject and body use an in-memory copy of the ticket.
That copy receives the status, assignee, overdue flag, and due dates
`postReply` would set, including `isanswered`. It is never saved. `closed`,
`lastupdate`, and `reopened` are clock values and are not copied. A reopen
also recomputes the SLA from the current time, and that date is not invented.
If the department `ticket.reply` template names one of those values
(`ticket.close_date`, `ticket.last_update`, `ticket.reopened`, or a
`ticket.due_date` that can change inside the 15 minute preview window, or any
`ticket.due_date` on a reopen), preview and send refuse with
`preview_unsupported` and a `variables` list. They do not return a rendering
that would not match the mail. `%{ticket.thread}` and
`%{ticket.thread.complete}` (including `complete.reversed`) list messages and
responses, so the sent mail contains the new reply and the preview of the
old thread would not. Those are refused the same way.
`%{ticket.thread.lastmessage}` is the last customer message (type M) and
`%{ticket.thread.original}` is the original customer message. A new response
does not change either, and preview still renders them.

Send order:

1. A request id that already has a stored result does not post again.
   `completed` and `verification_failed` re-read the stored entry ids and
   compare them to the stored hashes. When that block is unchanged, the
   original HTTP bytes are returned and the new body is not interpreted.
   When it differs, a new body is returned and nothing is sent again.
   `reply_state_unknown` and `reply_recorded_mail_unknown` are HTTP 500 and
   return the stored bytes without that re-read. `completed` is HTTP 200.
2. Reject `no_access` and a missing ticket before any ticket payload.
3. Take a MySQL advisory lock named `wgs_reply_<ticket_id>` (wait 10 seconds,
   refuse `busy` otherwise). Reload the ticket. Check the stored result again.
4. Resume a row that already started, before the hash check. `reply_recorded`
   with no note posts only the note. A captured response whose `postReply`
   did not return normally posts the note if it is missing and returns
   `mail_handoff_unknown`. `executing` with no response id becomes
   `reply_state_unknown`. Resume does not call `postReply`, and it does not
   search the thread by staff, entry order, or note text.
5. Recompute the preview hash from live state and refuse `stale_preview` on
   any difference.
6. Claim the request id as `reserved`, then `executing`, then acquire
   osTicket's ticket lock and apply collaborators.
7. Listen for osTicket's `threadentry.created` signal, scoped to this thread
   and this request. The response entry id is written to the ledger in its
   own update as soon as osTicket saves the row, and the listener is removed
   when the call returns. `native_returned` is set only after `postReply`
   returns that same entry.
8. Post the internal note. Re-read the reply, the note, and the status from
   the database (not the in-memory ticket). When every check passes, mark
   the row `completed` and return `verification`. When any check fails, the
   row is `verification_failed` (HTTP 500) with the entry ids and the block.
   Nothing is sent again. The acting agent's response draft is deleted when
   the response id is saved.

A `postReply` that saved a row and then threw, or that returned a different
entry than the one captured, is `reply_recorded_mail_unknown`. The audit note
is still posted. The result is `ok: false`, error `mail_handoff_unknown`,
with both entry ids. A retry returns that stored result and does not send
again. A call that captured no response id is `reply_state_unknown`. The
entry may already have been saved, because osTicket writes the row before
it signals. The result says persistence and mail handoff are unknown, and
that a saved reply is missing its `Reply sent using Claude` note, which
staff add by hand. A later reply on the ticket, including one by the same
agent, is not adopted.

The internal note is:

```
Reply sent using Claude by <staff full name>

<summary>
```

`note_summary` is required. The note entry id is written from the same
signal as soon as the note row is saved. A retry that already has a response
id and no note id posts only the note. It does not match an existing note
by its text.

`stale_preview`, `locked_by_other`, `busy`, `preview_unsupported`, and a
request id that cannot be posted again use HTTP 409. A body over
`max_body_bytes` uses HTTP 413. The source IP is checked before the body is
read. `CONTENT_LENGTH` above the limit is rejected before `php://input` is
opened, and the read itself stops at `max_body_bytes + 1`. osTicket's
`Http::response` labels every status it does not list as 500
(`include/class.http.php`), and 409 and 413 are not in that list, so the
bridge sends those two status lines itself.

Errors are `{ok:false, error, message}`. `partial_note_missing` includes
`response_entry_id`. `reply_state_unknown` includes `response_entry_id` set
to null. `mail_handoff_unknown` includes `response_entry_id` and
`note_entry_id`. `preview_unsupported` includes `variables`, the template
names that cannot be projected. Codes include `auth_failed`, `ip_not_allowed`,
`stale_timestamp`, `staff_not_found`, `staff_inactive`, `no_access`,
`no_reply_permission`, `ticket_not_found`, `locked_by_other`, `busy`,
`stale_preview`, `preview_unsupported`, `invalid_option`, `banned_owner`,
`empty_response`, `empty_summary`, `already_done`, `partial_note_missing`,
`reply_state_unknown`, `mail_handoff_unknown`, `verification_failed`,
`no_change`, and `no_close_permission`. `no_access` and
`ticket_not_found` are returned before any ticket payload is built. That
body is only `ok`, `error`, and `message`.

`verification` is `{reply_recorded, reply_by_staff, reply_body_matches,
note_recorded, note_text_exact, status_expected, status_actual,
status_matches, all_passed}`. The reply and note checks compare a fresh SQL
read to the sha256 of the column osTicket stored. The ledger keeps those
hashes, not the message text. On a status change the reply checks are true
because that call did not post a reply. `all_passed` then means the status
and the note matched.

`options.default_close_status` is `{id, name}`. `options.can_close` is true
when the acting agent's role has `ticket.close` and `Ticket::isCloseable()`
is true. The tools decide whether to close. The bridge does not close a
reply unless `reply_status_id` says so.

`template` is computed from the department's live `ticket.reply` body,
split at the first `%{response}`, with each side rendered by
`Ticket::replaceVars`. `preview.warnings` is a list. It flags a response
that starts with Hi, Hello, Dear, Hey, or the recipient's first name when
the template already adds a greeting, a response that ends with the staff
name or a sign-off when the template adds `%{poster}` or `%{staff}`, and a
response that already contains the selected signature text. Warnings do not
change the preview hash.

`POST /api/wgs-reply/status` uses the same permission checks as the staff
status dialog. A closed target needs `ticket.close` and a closeable ticket.
An open target needs `ticket.close` or `ticket.create`. A ticket that is
already in that status is `no_change` (HTTP 409) and does not consume the
request id. `Ticket::setStatus` is called with an empty comment so osTicket
does not post its own note. The bridge note is:

```
Status set to <status name> using Claude by <staff full name>

<summary>
```

`summary` is required. The note is posted with alerts off and does not
change status again. The same request id is idempotent. A second instance
is its own IP list and its own secret. A request is accepted by the first
enabled instance whose IP matches and whose secret matches the signature.

The ledger is a state machine. A row is never deleted once execution starts.

- `reserved`: the request id is claimed, and `postReply` has not been called.
- `executing`: persisted before `postReply`, with the last thread entry id
  at that moment. `native_returned` is still 0.
- `reply_recorded`: `postReply` returned the captured response entry.
  `native_returned` is 1. The note may still be missing.
- `reply_recorded_mail_unknown`: the response row was captured and the call
  did not finish normally. The stored result tells staff to check whether
  the customer got the email. This request will not be sent again.
- `reply_state_unknown`: no response id was captured for this request.
  Persistence and mail handoff are unknown. Staff check the thread and, if
  the reply is there, add the missing note by hand. This request will not
  be sent again, and a later thread entry is not attached to it.
- `completed`: the note id and the success JSON. `mail` is
  `handed_to_osticket` only on a completed reply. A completed status change
  has no `mail` field.
- `verification_failed`: the reply or the status was saved, and a fresh read
  did not match. HTTP 500. This request is not sent again.
- `status_reserved` and `status_executing`: the status route's own states.
  A send does not resume them into `postReply`.

`partial_note_missing` means the reply is saved and the note is not. It is
never reported as success. The same request id posts the note and then
completes.

The constant `WGS_REPLY_BRIDGE_TEST_FAULTS` is a staging switch. Production
does not define it, and the installer never defines it. The only honored
values are `after_reply` (thrown from the response listener after the row is
saved), `before_reply` (thrown after the row is `executing` and before
`postReply`), `note_fail` (the note is not posted), and `verify_status`
(the fresh status read is forced to a different enabled status, then the
original status id is written back). Anything else is ignored. A
`test_fault` row in plugin config does nothing. `verify_status` does not
run on a replay.

## Known limits

- `cc_user_ids` may only name people who are already collaborators. The reply
  form's select only lists those. Omitting the field keeps the currently
  active collaborators; an empty list deactivates them, which is what an
  unchecked form does.
- An owner address that `Validator::is_email` rejects before any DNS lookup
  (no mailbox, or host `localhost`) is refused. A DNS failure is only the
  banner at `ticket-view.inc.php` lines 57-58. The Post Reply form still
  renders (lines 795-809) and `scp/tickets.php` does not refuse it. Staging
  has no DNS and every address is `@staging.invalid`, so a DNS miss is not
  `can_reply: false`.
- On success, `mail` is `handed_to_osticket`. osTicket discards the mailer's
  return value, so the bridge does not claim the message was delivered. A
  `reply_to` of `none` records the reply and does not email the customer.
  osTicket may still send its own internal activity alerts to staff. When
  the response row was saved and the call did not return normally, the
  result is `mail_handoff_unknown` instead, and staff should check the
  customer mailbox.
- Closing follows `Ticket::setStatus`. The acting agent can become the
  assignee, and auto-refer-on-close can add a referral. Auto-claim on an open
  unassigned ticket is the native `postReply` default. The bridge does not
  undo either.
- The signature is not stored on the thread entry. It is only in the email
  osTicket renders.
- Preview does not store attachment bytes. The hash covers the file name,
  the mime type, and the sha256 of the bytes. Inline `data:` images are
  turned into attachments by osTicket when the reply is posted, not during
  preview.
- The ledger stores the request id, the state, the entry ids,
  `native_returned`, and the response JSON. A completed or
  `verification_failed` row also stores the sha256 of the expected reply
  column and the expected note column, plus the expected status id. It does
  not store the secret, the signature, or the message body. A crash after the response row is
  saved leaves `reply_recorded_mail_unknown` (or `reply_recorded` when
  `postReply` had already returned). The same request id finishes the note
  when it is missing and does not post a second reply. A crash before the
  response id is captured leaves `reply_state_unknown`. The row may already
  exist. The bridge does not attach a later entry and does not resend.
- Staging only: the edge proxy's address is what osTicket sees for requests
  from the host, because `TRUSTED_PROXIES` is empty. Allow the `ost_internal`
  subnet there. Do not copy that override to production.
