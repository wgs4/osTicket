<?php

require_once INCLUDE_DIR . 'class.plugin.php';
require_once dirname(__FILE__) . '/help.php';

class WgsReplyBridgeConfig extends PluginConfig {

    function getOptions() {
        $closed = self::closedStatusChoices();
        $defaultClose = '';
        foreach ($closed as $id => $name) {
            if (strcasecmp($name, 'Closed') === 0) {
                $defaultClose = (string) $id;
                break;
            }
        }
        if ($defaultClose === '' && $closed) {
            $ids = array_keys($closed);
            sort($ids, SORT_NUMERIC);
            $defaultClose = (string) $ids[0];
        }

        return array(
            'help' => new FreeTextField(array(
                'label' => 'Help',
                'required' => false,
                'configuration' => array(
                    'content' => wgs_reply_bridge_help_html(),
                ),
            )),
            'shared_secret' => new PasswordField(array(
                'label' => 'Shared secret',
                'required' => true,
                // TextboxWidget prints a non-numeric autocomplete value as
                // the attribute. new-password asks the browser not to fill
                // a saved login password into this box.
                'configuration' => array('autocomplete' => 'new-password'),
                'hint' => 'HMAC key for the osticket-mcp caller. Never logged. Browsers may autofill a saved password here. Clear it, paste the secret, and save.',
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
            'default_close_status' => new ChoiceField(array(
                'label' => 'Default close status',
                'required' => false,
                'choices' => $closed,
                'default' => $defaultClose,
                'hint' => 'Enabled closed status the tools use when staff want the ticket closed. The reply route keeps the current status unless reply_status_id is set. Default is the status named Closed.',
            )),
        );
    }

    /**
     * Enabled statuses whose state is closed, id => name.
     * Empty when osTicket has none. Callers then report id 0.
     */
    static function closedStatusChoices() {
        $choices = array();
        if (!class_exists('TicketStatusList'))
            return $choices;
        foreach (TicketStatusList::getStatuses(array('states' => array('closed'))) as $status) {
            if (!$status->isEnabled())
                continue;
            $choices[(int) $status->getId()] = (string) $status->getName();
        }
        return $choices;
    }
}
