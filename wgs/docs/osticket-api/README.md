# osTicket API documentation (official copy)

A copy of every piece of official osTicket API documentation we could find,
kept here so WGS development does not depend on outside sites.

Collected 2026-11-01. Production runs osTicket **v1.17.6**
(commit `3ff35ad5347ffdefa1e98b8a2240f8ed7e45025e`), so the in-source copy
is taken from that exact tag.

## What is here

| Folder | Source | Version |
|---|---|---|
| `docs.osticket.com/` | [github.com/osTicket/docs](https://github.com/osTicket/docs), the source of https://docs.osticket.com | commit `f2367c5f7a87e855cd200be0862dc47a3cf14708` (2026-01-16), site labels it 1.17.7 |
| `in-source-v1.17.6/` | `setup/doc/` and `api/http.php` from [osTicket/osTicket](https://github.com/osTicket/osTicket) | tag `v1.17.6` |

`docs.osticket.com/` holds:

- `Developer Documentation/API Docs.rst`: authentication, `X-API-Key`, IP binding.
- `Developer Documentation/API/Tickets.rst`: create a ticket (XML, JSON, email).
- `Developer Documentation/API/Tasks.rst`: remote cron.
- `Developer Documentation/Database ERDs.rst`: database diagrams page.
- `Admin/Manage/API Keys.rst`: how admins make and limit API keys.
- `Plugins/*.rst`: the official plugins. We run four of them (Two Factor
  Authentication, Help Desk Audit, Attachments in S3, Password Management Policies).
- `_static/images/`: the screenshots those pages use.
- `LICENSE`: GPL-3.0, from the docs repo.

`in-source-v1.17.6/` holds the developer docs that ship inside the code:
`api.md`, `api/tickets.md`, `api/tasks.md`, plus `signals.md`, `forms.md`,
`orm.md`, `streams.md`, `package.md`, `i18n.md`. `api-http.php.txt` is the
real route table.

The rendered pages on docs.osticket.com were checked with Firecrawl on
2026-11-01. They match the `.rst` source. The site's PDF and HTML-zip download
links returned an HTML error page, not a file, so they are not included.

## The facts that matter for WGS

1. **The HTTP API can only create tickets and run cron.** The full route table
   in v1.17.6 (`api/http.php`):
   - `POST /api/tickets.xml | .json | .email` → `TicketApiController::create`
   - `POST /api/tasks/cron` → `CronApiController::execute`

   There is no endpoint to read a ticket, post a reply, post a note, or change
   status. The docs say so: "the API support creation of tickets only".
2. **Plugins can add routes.** `api/http.php` calls
   `Signal::send('api', $dispatcher)` before it resolves the URL. A plugin can
   listen for the `api` signal and register its own route. That is the
   supported way to add a "post reply" endpoint that runs osTicket's own
   `Ticket::postReply()`, so osTicket still sends the email itself.
   See `in-source-v1.17.6/signals.md`.
3. **API keys are tied to one IP address** and checked against the caller.
   Behind a proxy, the IP comes from `X-Forwarded-For`.

## Not included

Third-party API add-ons (for example github.com/BMSVieira/osticket-api) are
not official osTicket sources, so they are not copied here.
