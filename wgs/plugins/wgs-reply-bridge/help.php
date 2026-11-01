<?php
/**
 * Help shown at the top of the plugin instance Config tab.
 * osTicket 1.17 hardcodes the Instance and Config tabs in
 * include/staff/templates/plugin-instance.tmpl.php. A plugin cannot add
 * a tab without editing that core file, so this is a FreeText field.
 * No secrets belong in this text.
 */

function wgs_reply_bridge_help_html() {
    return <<<'HTML'
<p><strong>WGS Reply Bridge help</strong></p>
<p>The bridge posts a staff reply by calling Ticket::postReply(), the same way the staff panel does. osTicket sends the customer email. The bridge then posts an internal note, re-reads the ticket from the database, and returns a verification block. It can also change status on its own. It does not open an SMTP connection and it does not insert thread rows itself.</p>
<p>The admin page has two tabs, Instance and Config. osTicket draws those tabs in core, and a plugin cannot add a third tab without editing core. This Help section is the setup guide. It stays at the top of Config.</p>
<h4>Config fields</h4>
<ul>
<li><strong>Shared secret.</strong> HMAC key for one caller. osTicket encrypts it. Browsers may autofill a saved password into this box. Clear it, paste the secret, and save. The CLI below does the same write without a browser.</li>
<li><strong>Allowed IPs.</strong> Comma-separated IPs or CIDR blocks. Checked with osTicket::get_client_ip().</li>
<li><strong>Enabled.</strong> Turns this instance's routes off without disabling the plugin.</li>
<li><strong>Max body bytes.</strong> Reject a request body larger than this. Default 8388608.</li>
<li><strong>Default close status.</strong> An enabled status whose state is closed. The default is the status named Closed, or the lowest-id closed status when that name is missing. The reply route does not apply it. reply_status_id stays explicit and defaults to the ticket's current status. The MCP tools choose this status when they close.</li>
</ul>
<h4>Install on a new server</h4>
<ol>
<li>Copy this directory to include/plugins/wgs-reply-bridge/ on the osTicket host.</li>
<li>Admin Panel, Manage, Plugins. Install WGS Reply Bridge and enable it.</li>
<li>Add an instance. Set Allowed IPs to the host that will call the bridge, turn Enabled on, and set the shared secret (UI or CLI).</li>
<li>The first request creates the ledger table. Rows are not dropped on later upgrades.</li>
</ol>
<h4>Another instance</h4>
<p>A second MCP host gets its own instance, its own allowed IP, and its own secret. Do not reuse the first secret. Each request is accepted by the enabled instance whose IP list contains the caller and whose secret matches the signature. Add the instance from the plugin's Instances tab.</p>
<h4>Set the secret</h4>
<p>In the admin UI, clear the Shared secret box if a browser filled in a saved password, paste the secret, and save. From the osTicket host, the CLI reads the secret from stdin and never prints it:</p>
<pre>php include/plugins/wgs-reply-bridge/cli/set-secret.php INSTANCE_ID
printf '%s' "$SECRET" | php include/plugins/wgs-reply-bridge/cli/set-secret.php INSTANCE_ID</pre>
<p>The script stores the value through the field's own to_database, reads it back through a fresh config, and prints only lengths and readback_match (yes or no). It refuses to run unless PHP is the cli SAPI. A web request cannot use it.</p>
<h4>MCP side</h4>
<p>On the host that calls the bridge, put the matching secret in that process's .env. Do not commit the .env. staff_identities.json names the staff username each caller acts as. The mux identities file lists who may use the shared mux. Leave MCP_MUX_ALLOW_ANONYMOUS off so an unnamed caller cannot post as someone else. Each staff member has a personal key. Do not share one key across people.</p>
<h4>How to test</h4>
<p>Call POST /api/wgs-reply/options for a ticket the staff member can open. preview returns the rendered department template and a preview_hash. send posts the reply and the note, then returns verification.all_passed. POST /api/wgs-reply/status changes status and posts its own note. Replay the same X-WGS-Request-Id: the bridge re-checks the stored entry ids and does not post again.</p>
<h4>Troubleshooting</h4>
<ul>
<li><strong>disabled.</strong> The instance Enabled flag is off, or no enabled instance matches. Routes refuse the call.</li>
<li><strong>ip_not_allowed.</strong> The caller address is not in any enabled instance's Allowed IPs. osTicket sees the connecting address, not a forwarded client, unless TRUSTED_PROXIES is set.</li>
<li><strong>auth_failed.</strong> The signature, the request id, or the secret does not match. The secret is never logged.</li>
<li><strong>stale_timestamp.</strong> X-WGS-Timestamp is more than 300 seconds from the server clock.</li>
<li><strong>verification_failed.</strong> The reply or the note was saved, but a fresh database read did not match the staff member, the body, the note text, or the status. The bridge does not send the request again and does not try to correct the ticket.</li>
</ul>
HTML;
}
