# WGS osTicket

This branch, `wgs/production`, is the WGS starting point for osTicket work.

- Base: upstream tag `v1.17.6` (commit `3ff35ad5`), the release that runs on
  support.wgsusa.com (Apache VM 192.168.1.195). Confirmed from the `?v=3ff35ad`
  asset hash on the live login page.
- `wgs/` holds WGS-only files. Upstream files stay untouched so diffs against
  `v1.17.6` show only WGS changes.
- The code on .195 was copied on 2026-11-01 and compared with `v1.17.6`.
  It is the stock release. The only differences are:
  - 12 files where the official installer stamps the version and `?3ff35ad`
    asset hashes (`bootstrap.php`, `scp/*.php`, staff/client headers). No logic changes.
  - The 5 plugin packages in `include/plugins/` (audit, auth-2fa, auth-oauth2,
    auth-password-policy, storage-s3), plus `include/.MANIFEST` and the cPanel `.htaccess`.
  - Not committed: `include/ost-config.php` (secrets, never copied), `api/error_log`,
    `php.ini` (cPanel generated) and `phpinfox.php` (a stray file; the server shows
    its source, not phpinfo, but it should be removed).
  - The installer removed `setup/` and the docs files on the server. They stay in git.
- Server: PHP 8.2.35, Apache runs as `www-data`, web root `/var/www/osticket`.

Nothing older was deleted. The 2020 state is kept:

- Branches `develop`, `master`, `initial-wgs-directory` and the old version branches.
- Tags `archive/wgs-develop-2020-03` and `archive/wgs-initial-directory-2020-03`.
  The second one is the 2020 WGS install snapshot with the department-access
  patches (help topics, FAQs, canned responses). Production does NOT carry those
  patches any more; they were lost in an upgrade.

API docs: `wgs/docs/osticket-api/README.md`.
