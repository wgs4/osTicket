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
   - **Test fault.** Leave empty. Staging tests set `after_reply` or
     `note_fail`. Any other value is ignored, and production must not set it.
4. The first request creates `<prefix>wgs_reply_ledger` if it is missing.
   An existing table gains `baseline_entry_id` and `executing_at` when those
   columns are absent. Rows are not dropped.

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

`preview_hash` is `{unix_time}.{hmac}` over a version `v2` material. Each
field is length-prefixed (`byte_length:value`) so a newline in the body
cannot collide with the next field. It binds the acting staff id, ticket id,
last thread entry id, status id, owner user id, the resolved To and Cc
addresses, the From email id, address, and display name, the resolved
signature text, the rendered subject and body, `reply_to`, `reply_status_id`,
the collaborator user ids that will be applied, and each attachment as
`name:mime:sha256`. To, Cc, collaborator ids, and attachments are sorted
before the HMAC. It expires after 15 minutes. `note_summary` is not part of
the hash. A hash from the previous version does not match.

Preview computes recipients from the collaborator selection in the request
without writing it. An explicit `cc_user_ids` of `[]` means no collaborator
is active. A selected collaborator is included even when the stored row is
inactive. The rendered subject and body use an in-memory projection of the
post-reply status and assignee. That projection is restored before anything
is saved.

Send order: return the stored result when this request id already completed;
reject `no_access` and a missing ticket before any ticket payload; take a
MySQL advisory lock named `wgs_reply_<ticket_id>` (wait 10 seconds, refuse
`busy` otherwise); reload the ticket; recompute the preview hash from live
state and refuse `stale_preview` on any difference; acquire osTicket's ticket
lock; apply collaborators; `Ticket::postReply()`; save the response entry id
and delete that agent's response draft; `Ticket::postNote()` with
`alert=false` and no status change; release the native lock and then the
advisory lock; return the saved ids and `mail: "handed_to_osticket"`.

The internal note is:

```
Reply sent using Claude by <staff full name>

<summary>
```

`note_summary` is required.

`stale_preview`, `locked_by_other`, `busy`, and a request id that cannot be
posted again use HTTP 409. A body over `max_body_bytes` uses HTTP 413. The
source IP is checked before the body is read. `CONTENT_LENGTH` above the
limit is rejected before `php://input` is opened, and the read itself stops
at `max_body_bytes + 1`. osTicket's `Http::response` labels every status it
does not list as 500 (`include/class.http.php`), and 409 and 413 are not in
that list, so the bridge sends those two status lines itself.

Errors are `{ok:false, error, message}`. `partial_note_missing` and
`reply_state_unknown` also include `response_entry_id` (null when the entry
was not found). Codes include `auth_failed`, `ip_not_allowed`,
`stale_timestamp`, `staff_not_found`, `staff_inactive`, `no_access`,
`no_reply_permission`, `ticket_not_found`, `locked_by_other`, `busy`,
`stale_preview`, `invalid_option`, `banned_owner`, `empty_response`,
`empty_summary`, `already_done`, `partial_note_missing`, and
`reply_state_unknown`. `no_access` and `ticket_not_found` are returned before
any ticket payload is built. That body is only `ok`, `error`, and `message`.

The ledger is a state machine. A row is never deleted once execution starts.

- `reserved`: the request id is claimed, and `postReply` has not been called.
- `executing`: persisted before `postReply`, with the last thread entry id
  at that moment.
- `reply_recorded`: the response entry id, saved as soon as `postReply`
  returns. A throw or an empty return is checked for a response entry by
  this staff created after that baseline. Anything short of a saved reply
  and a saved note is `ok: false`.
- `completed`: the note id and the response JSON.

A completed request id returns the stored JSON and does nothing else. A retry
of `reply_recorded` posts only the note. The note is skipped when one with
the same first line (`Reply sent using Claude by <staff full name>`) is
already on that response. A retry never posts a second reply. `executing`
with no response entry found returns `reply_state_unknown` and does not call
`postReply` again. A note that does not save returns `partial_note_missing`
and is never reported as success.

**Test fault** is a staging switch. Leave it empty in production. The only
honored values are `after_reply` and `note_fail`. Anything else is ignored.
The installer never sets it.

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
- `mail` is always `handed_to_osticket`. osTicket discards the mailer's return
  value, so the bridge does not claim the message was delivered. A
  `reply_to` of `none` records the reply and does not email the customer.
  osTicket may still send its own internal activity alerts to staff.
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
- The ledger stores the request id, the state, the entry ids, and the
  response JSON. It does not store the secret, the signature, or the message
  body. A crash after the reply is saved leaves the row at `reply_recorded`
  or `executing`. The same request id finishes the note and does not post a
  second reply.
- Staging only: the edge proxy's address is what osTicket sees for requests
  from the host, because `TRUSTED_PROXIES` is empty. Allow the `ost_internal`
  subnet there. Do not copy that override to production.
