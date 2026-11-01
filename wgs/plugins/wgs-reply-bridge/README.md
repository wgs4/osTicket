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

`preview_hash` is `{unix_time}.{hmac}`. It covers the acting agent, the reply
fields, and the ticket state (last thread entry, status, collaborator set).
It expires after 15 minutes. `note_summary` is not part of the hash.

Send order: return the stored result when this request id already completed;
re-check everything; refuse a stale hash; acquire osTicket's ticket lock;
apply collaborators; `Ticket::postReply()`; `Ticket::postNote()` with
`alert=false` and no status change; release the lock and delete that agent's
response draft; return the saved ids and `mail: "handed_to_osticket"`.

The internal note is:

```
Reply sent using Claude by <staff full name>

<summary>
```

`note_summary` is required.

`stale_preview`, `locked_by_other`, and an in-progress request id use HTTP
409. A body over `max_body_bytes` uses HTTP 413. osTicket's `Http::response`
labels every status it does not list as 500 (`include/class.http.php`), and
409 and 413 are not in that list, so the bridge sends those two status lines
itself.

Errors are `{ok:false, error, message}`. Codes include `auth_failed`,
`ip_not_allowed`, `stale_timestamp`, `staff_not_found`, `staff_inactive`,
`no_access`, `no_reply_permission`, `ticket_not_found`, `locked_by_other`,
`stale_preview`, `invalid_option`, `banned_owner`, `empty_response`,
`empty_summary`, and `already_done`.

A completed request id returns the stored JSON and does nothing else. A
request id still marked in progress returns `already_done`.

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
- Preview does not store attachment bytes. The hash covers the file name and
  the sha256 of the bytes. Inline `data:` images are turned into attachments
  by osTicket when the reply is posted, not during preview.
- The ledger stores the request id and the response JSON. It does not store
  the secret, the signature, or the message body. A crash after the reply is
  saved and before the ledger row is marked complete leaves the id in
  progress so a retry cannot post a second reply; that retry does not finish
  the note. Run the note by hand in that case.
- Staging only: the edge proxy's address is what osTicket sees for requests
  from the host, because `TRUSTED_PROXIES` is empty. Allow the `ost_internal`
  subnet there. Do not copy that override to production.
