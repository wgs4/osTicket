# WGS osTicket

This branch, `wgs/production`, is the WGS starting point for osTicket work.

- Base: upstream tag `v1.17.6` (commit `3ff35ad5`), the release that runs on
  support.wgsusa.com (Apache VM 192.168.1.195). Confirmed from the `?v=3ff35ad`
  asset hash on the live login page.
- `wgs/` holds WGS-only files. Upstream files stay untouched so diffs against
  `v1.17.6` show only WGS changes.
- Next step: overlay a copy of the code that is really on .195 (taken without
  `include/ost-config.php` and without attachments) to record any local patches.
  Scan it for secrets first: this repo is public.

Nothing older was deleted. The 2020 state is kept:

- Branches `develop`, `master`, `initial-wgs-directory` and the old version branches.
- Tags `archive/wgs-develop-2020-03` and `archive/wgs-initial-directory-2020-03`.
  The second one is the 2020 WGS install snapshot with the department-access
  patches (help topics, FAQs, canned responses). Check whether production still
  carries those patches when the .195 copy arrives.

API docs: `wgs/docs/osticket-api/README.md`.
