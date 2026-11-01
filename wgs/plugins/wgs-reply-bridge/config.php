<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class WgsReplyBridgeConfig extends PluginConfig {

    function getOptions() {
        return array(
            'shared_secret' => new PasswordField(array(
                'label' => 'Shared secret',
                'required' => true,
                'hint' => 'HMAC key for the osticket-mcp caller. Never logged.',
            )),
            'allowed_ips' => new TextboxField(array(
                'label' => 'Allowed IPs',
                'required' => true,
                'default' => '192.168.1.193',
                'hint' => 'Comma-separated IPs or CIDR blocks. Checked with osTicket\'s client IP helper.',
                'configuration' => array('size' => 60, 'length' => 255),
            )),
            'enabled' => new BooleanField(array(
                'label' => 'Enabled',
                'required' => false,
                'default' => true,
                'hint' => 'Turn the routes off without disabling the plugin instance.',
            )),
            'max_body_bytes' => new TextboxField(array(
                'label' => 'Max body bytes',
                'required' => true,
                'default' => '8388608',
                'validator' => 'number',
                'hint' => 'Reject a request body larger than this many bytes.',
                'configuration' => array('size' => 12, 'length' => 12),
            )),
            // Staging tests only. Production leaves this empty. The bridge
            // ignores every value except the two fault names below.
            'test_fault' => new TextboxField(array(
                'label' => 'Test fault',
                'required' => false,
                'default' => '',
                'hint' => 'Leave empty in production. Staging may set after_reply or note_fail.',
                'configuration' => array('size' => 20, 'length' => 32),
            )),
        );
    }
}
