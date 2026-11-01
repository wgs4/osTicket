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
     encrypts it. Use at least 8 characters with upper, lower, and a digit or
     symbol (the password-policy plugin applies).
   - **Allowed IPs.** Comma-separated IPs or CIDR blocks. Default
     `192.168.1.193`, which is the osticket-mcp host. Checked with
     `osTicket::get_client_ip()`.
   - **Enabled.** Turns the routes off without disabling the plugin.
   - **Max body bytes.** Default `8388608`.
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
| `/api/wgs-reply/options` | `ticket_number`, `staff_username` | What the Post Reply form would show that agent right now |
| `/api/wgs-reply/canned` | those, plus `canned_id` (`original`, `lastmessage`, or a numeric id) | The expanded body and attachment names |
| `/api/wgs-reply/preview` | the reply fields | Recipients, From, signature, status after, rendered `ticket.reply` subject and body, and `preview_hash`. Writes nothing |
| `/api/wgs-reply/send` | the reply fields, `preview_hash`, and `note_summary` | Posts the reply, then the internal note |

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
that would not match the mail.

Send order:

1. A request id that already has a stored result returns those bytes and
   does not read the ticket. `completed` is HTTP 200. `reply_state_unknown`
   and `reply_recorded_mail_unknown` are HTTP 500. The same request id never
   posts again.
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
8. Post the internal note and mark the row `completed`. The acting agent's
   response draft is deleted when the response id is saved.

A `postReply` that saved a row and then threw, or that returned a different
entry than the one captured, is `reply_recorded_mail_unknown`. The audit note
is still posted. The result is `ok: false`, error `mail_handoff_unknown`,
with both entry ids. A retry returns that stored result and does not send
again. A call that saved no row is `reply_state_unknown`. A later reply on
the ticket, including one by the same agent, is not adopted.

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
`reply_state_unknown`, and `mail_handoff_unknown`. `no_access` and
`ticket_not_found` are returned before any ticket payload is built. That
body is only `ok`, `error`, and `message`.

The ledger is a state machine. A row is never deleted once execution starts.

- `reserved`: the request id is claimed, and `postReply` has not been called.
- `executing`: persisted before `postReply`, with the last thread entry id
  at that moment. `native_returned` is still 0.
- `reply_recorded`: `postReply` returned the captured response entry.
  `native_returned` is 1. The note may still be missing.
- `reply_recorded_mail_unknown`: the response row was captured and the call
  did not finish normally. The stored result tells staff to check whether
  the customer got the email. This request will not be sent again.
- `reply_state_unknown`: no response row was captured for this request. The
  stored result says the reply did not save an entry. This request will not
  be sent again, and a later thread entry is not attached to it.
- `completed`: the note id and the success JSON. `mail` is
  `handed_to_osticket` only on this path.

`partial_note_missing` means the reply is saved and the note is not. It is
never reported as success. The same request id posts the note and then
completes.

The constant `WGS_REPLY_BRIDGE_TEST_FAULTS` is a staging switch. Production
does not define it, and the installer never defines it. The only honored
values are `after_reply` (thrown from the response listener after the row is
saved), `before_reply` (thrown after the row is `executing` and before
`postReply`), and `note_fail` (the note is not posted). Anything else is
ignored. A `test_fault` row in plugin config does nothing.

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
  `native_returned`, and the response JSON. It does not store the secret,
  the signature, or the message body. A crash after the response row is
  saved leaves `reply_recorded_mail_unknown` (or `reply_recorded` when
  `postReply` had already returned). The same request id finishes the note
  when it is missing and does not post a second reply. A crash before any
  response row is saved leaves `reply_state_unknown` and does not attach a
  later entry.
- Staging only: the edge proxy's address is what osTicket sees for requests
  from the host, because `TRUSTED_PROXIES` is empty. Allow the `ost_internal`
  subnet there. Do not copy that override to production.
