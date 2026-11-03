<?php
/**
 * Staging helper for the reply bridge. CLI only. Talks to osTicket
 * through its own classes (install, fixtures, signature, postMessage,
 * status restore). Never prints the shared secret except for the
 * `secret` command, which the test process captures and does not log.
 */
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 404 Not Found');
    exit;
}

chdir('/var/www/html');
ob_start();
require '/var/www/html/main.inc.php';
$noise = ob_get_clean();

// osTicket warns under PHP 8 (undefined indexes, dynamic properties).
// Swallow those so they do not break JSON output or session shutdown.
set_error_handler(function ($no, $str, $file, $line) {
    return true;
});

function bridge_out($data, $code = 0) {
    if (!is_string($data))
        $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $data, "\n";
    exit($code);
}

function bridge_fail($message, $extra = array()) {
    bridge_out(array('ok' => false, 'error' => $message) + $extra, 1);
}

$cmd = isset($argv[1]) ? $argv[1] : '';

try {
    switch ($cmd) {
    case 'install':
        bridge_install(isset($argv[2]) ? $argv[2] : '');
        break;
    case 'secret':
        bridge_secret();
        break;
    case 'fixtures':
        bridge_out(array('ok' => true) + bridge_fixtures());
        break;
    case 'signature':
        bridge_out(bridge_signature_get($argv[2]));
        break;
    case 'set-signature':
        bridge_out(bridge_signature_set($argv[2], $argv[3]));
        break;
    case 'state':
        bridge_out(bridge_state($argv[2]));
        break;
    case 'entries':
        $since = isset($argv[3]) ? (int) $argv[3] : 0;
        bridge_out(bridge_entries($argv[2], $since));
        break;
    case 'collabs':
        bridge_out(bridge_collabs($argv[2]));
        break;
    case 'post-message':
        $text = implode(' ', array_slice($argv, 3));
        bridge_out(bridge_post_message($argv[2], $text));
        break;
    case 'restore':
        bridge_out(bridge_restore($argv[2], (int) $argv[3], (int) $argv[4]));
        break;
    case 'set-fault':
        $flag = isset($argv[2]) ? (string) $argv[2] : 'off';
        bridge_out(bridge_fault_constant($flag));
        break;
    case 'clear-fault-config':
        bridge_out(bridge_fault_config(null));
        break;
    case 'plant-fault-config':
        $value = isset($argv[2]) ? (string) $argv[2] : '';
        if ($value === '')
            bridge_fail('A config value is required.');
        bridge_out(bridge_fault_config($value));
        break;
    case 'ui-reply':
        $text = implode(' ', array_slice($argv, 4));
        bridge_out(bridge_ui_reply($argv[2], $argv[3], $text));
        break;
    case 'reply-template':
        bridge_out(bridge_reply_template($argv[2]));
        break;
    case 'set-reply-template':
        bridge_out(bridge_write_reply_template($argv[2]));
        break;
    case 'set-max':
        $max = isset($argv[2]) ? (int) $argv[2] : 0;
        if ($max < 1)
            bridge_fail('max_body_bytes must be a positive number.');
        bridge_out(bridge_config(array('max_body_bytes' => (string) $max)));
        break;
    case 'set-collab':
        bridge_out(bridge_set_collab($argv[2], (int) $argv[3], (int) $argv[4]));
        break;
    case 'other-user':
        bridge_out(bridge_other_user($argv[2]));
        break;
    case 'set-owner':
        bridge_out(bridge_set_owner($argv[2], $argv[3], (int) $argv[4]));
        break;
    case 'ledger':
        bridge_out(bridge_ledger($argv[2]));
        break;
    case 'legacy-mail-unknown':
        bridge_out(bridge_legacy_mail_unknown($argv[2]));
        break;
    case 'render-config':
        bridge_out(bridge_render_config());
        break;
    case 'verify-compare':
        bridge_out(bridge_verify_compare());
        break;
    case 'grant-close-no-reply':
        bridge_out(bridge_grant_close_no_reply());
        break;
    case 'restore-close-no-reply':
        bridge_out(bridge_restore_close_no_reply(
            isset($argv[2]) ? (int) $argv[2] : 0,
            isset($argv[3]) ? (int) $argv[3] : 0,
            isset($argv[4]) ? (int) $argv[4] : 0,
            isset($argv[5]) ? (int) $argv[5] : 0,
            isset($argv[6]) ? (int) $argv[6] : 0
        ));
        break;
    default:
        bridge_fail('Unknown command.', array('noise' => $noise));
    }
} catch (Throwable $ex) {
    bridge_fail($ex->getMessage(), array(
        'at' => basename($ex->getFile()) . ':' . $ex->getLine(),
    ));
}

function bridge_plugin() {
    foreach (Plugin::objects() as $p) {
        if ($p->getInstallPath() == 'plugins/wgs-reply-bridge')
            return $p;
    }
    return null;
}

function bridge_make_secret() {
    // Upper, lower, digit, symbol. No HTML-special characters: the
    // password field's validator runs htmlchars on the value.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!*-_';
    $secret = 'Aa1!';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < 32; $i++)
        $secret .= $alphabet[random_int(0, $max)];
    return $secret;
}

function bridge_install($cidr) {
    if (!preg_match('/^[0-9a-fA-F:.\/,]+$/', (string) $cidr))
        bridge_fail('allowed_ips value is not an IP or CIDR list.');

    // SimpleForm keeps submitted values only when the request is a POST.
    // A CLI merge would let stored defaults overwrite the values we pass.
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $plugin = bridge_plugin();
    $created = false;
    if (!$plugin) {
        $plugin = $GLOBALS['ost']->plugins->install('wgs-reply-bridge');
        if (!$plugin)
            bridge_fail('Plugin install returned false. Is the directory in include/plugins?');
        $plugin = Plugin::lookup($plugin->getId());
        $created = true;
    }
    if (!$plugin->isActive()) {
        $plugin->isactive = 1;
        if (!$plugin->save())
            bridge_fail('Could not enable the plugin.');
    }

    $impl = $plugin->getImpl();
    if (!$impl)
        bridge_fail('Plugin class did not load.');

    $inst = null;
    foreach ($plugin->getInstances() as $i) {
        $inst = $i;
        break;
    }

    $errors = array();
    if (!$inst) {
        $secret = bridge_make_secret();
        $vars = array(
            'name' => 'WGS Reply Bridge',
            'notes' => '',
            'isactive' => 1,
            'shared_secret' => $secret,
            'allowed_ips' => $cidr,
            'enabled' => 1,
            'max_body_bytes' => '8388608',
        );
        $inst = $impl->addInstance($vars, $errors);
        if (!$inst)
            bridge_fail('Could not add the plugin instance.', array('errors' => $errors));
        $created = true;
    } else {
        if (!$inst->isEnabled()) {
            $inst->setStatus(true);
            $inst->save();
        }
        $current = (string) $inst->getConfig()->get('allowed_ips');
        $enabled = $inst->getConfig()->get('enabled') ? true : false;
        if ($current !== $cidr || !$enabled) {
            $vars = array(
                'name' => $inst->getName() ?: 'WGS Reply Bridge',
                'notes' => '',
                'isactive' => 1,
                'shared_secret' => (string) $inst->getConfig()->get('shared_secret'),
                'allowed_ips' => $cidr,
                'enabled' => 1,
                'max_body_bytes' => (string) ($inst->getConfig()->get('max_body_bytes') ?: '8388608'),
                'default_close_status' => bridge_close_status_id($inst->getConfig()),
            );
            if (!$inst->update($vars, $errors))
                bridge_fail('Could not update the plugin instance.', array('errors' => $errors));
        }
    }

    bridge_out(array(
        'ok' => true,
        'created' => $created,
        'plugin_id' => (int) $plugin->getId(),
        'instance_id' => (int) $inst->getId(),
        'active' => $plugin->isActive() ? true : false,
        'instance_enabled' => $inst->isEnabled() ? true : false,
        'allowed_ips' => (string) $inst->getConfig()->get('allowed_ips'),
    ));
}

function bridge_secret() {
    $plugin = bridge_plugin();
    if (!$plugin)
        bridge_fail('Plugin is not installed.');
    $inst = null;
    foreach ($plugin->getInstances() as $i) {
        $inst = $i;
        break;
    }
    if (!$inst)
        bridge_fail('Plugin has no instance.');
    $secret = (string) $inst->getConfig()->get('shared_secret');
    if ($secret === '')
        bridge_fail('Shared secret is empty.');
    echo $secret;
    exit(0);
}

function bridge_staff($username) {
    $staff = Staff::lookup($username);
    if (!$staff)
        bridge_fail('Staff not found: ' . $username);
    return $staff;
}

function bridge_ticket($number) {
    $ticket = Ticket::lookupByNumber($number);
    if (!$ticket)
        bridge_fail('Ticket not found: ' . $number);
    return $ticket;
}

function bridge_active_collabs($ticket) {
    $rows = array();
    $active = 0;
    if ($list = $ticket->getCollaborators()) {
        foreach ($list as $c) {
            $on = $c->isActive() ? true : false;
            if ($on)
                $active++;
            $rows[] = array(
                'user_id' => (int) $c->getUserId(),
                'active' => $on,
                'email' => strtolower((string) $c->getEmail()),
            );
        }
    }
    return array($rows, $active);
}

function bridge_replyable($ticket, $staff, $needClose) {
    if ($ticket->isChild() && $ticket->getMergeType() != 'visual')
        return false;
    // Same gate as WgsReplyBridgePlugin::replyBlock: format only.
    // is_valid_email DNS-checks, and staging has no DNS.
    if (!Validator::is_email($ticket->getEmail(), false, false))
        return false;
    if (Banlist::isBanned($ticket->getEmail()))
        return false;
    if ($ticket->getLock())
        return false;
    if (!$ticket->checkStaffPerm($staff, Ticket::PERM_REPLY))
        return false;
    if ($needClose && !$ticket->checkStaffPerm($staff, Ticket::PERM_CLOSE))
        return false;
    if ($needClose && $ticket->isCloseable() !== true)
        return false;
    return true;
}

function bridge_brief($ticket) {
    list($collabs, $active) = bridge_active_collabs($ticket);
    $dept = $ticket->getDept();
    $email = $dept ? $dept->getEmail() : null;
    return array(
        'number' => (string) $ticket->getNumber(),
        'id' => (int) $ticket->getId(),
        'subject' => (string) $ticket->getSubject(),
        'status_id' => (int) $ticket->getStatusId(),
        'state' => (string) $ticket->getState(),
        'staff_id' => $ticket->getStaffId() ? (int) $ticket->getStaffId() : 0,
        'team_id' => $ticket->getTeamId() ? (int) $ticket->getTeamId() : 0,
        'dept_id' => $dept ? (int) $dept->getId() : 0,
        'dept_name' => $dept ? (string) $dept->getName() : '',
        'dept_email' => $email ? (string) $email->getAddress() : '',
        'owner_user_id' => (int) $ticket->getOwnerId(),
        'owner_email' => (string) $ticket->getEmail(),
        'owner_name' => ($ticket->getOwner() ? (string) $ticket->getOwner()->getName() : ''),
        'collaborators' => count($collabs),
        'active_collaborators' => $active,
        'closeable' => $ticket->isCloseable() === true,
    );
}

function bridge_staff_brief($staff) {
    global $cfg;
    return array(
        'id' => (int) $staff->getId(),
        'username' => (string) $staff->getUserName(),
        'name' => (string) $staff->getName(),
        'firstname' => (string) $staff->getFirstName(),
        'signature_type' => (string) $staff->getDefaultSignatureType(),
        'from_name_type' => (string) $staff->getReplyFromNameType(),
        'has_signature' => $staff->getSignature() ? true : false,
        'hide_staff_name' => ($cfg && $cfg->hideStaffName()) ? true : false,
    );
}

function bridge_fixtures() {
    global $cfg;

    $open = array();
    foreach (Ticket::objects()->filter(array('status__state' => 'open')) as $ticket)
        $open[] = $ticket;

    $prefer = array('trevor', 'cyoung', 'fortin-mike', 'john', 'kayla', 'lisa');
    $reply = null;
    $buckets = null;
    foreach ($prefer as $username) {
        $staff = Staff::lookup($username);
        if (!$staff || !$staff->isActive() || !$staff->getSignature())
            continue;
        $buckets = bridge_bucket($open, $staff);
        if (count($buckets['plain']) >= 3 && count($buckets['close']) >= 6 && count($buckets['collab']) >= 1) {
            $reply = $staff;
            break;
        }
    }
    if (!$reply) {
        foreach (Staff::objects()->filter(array('isactive' => 1)) as $staff) {
            if (!$staff->getSignature())
                continue;
            $buckets = bridge_bucket($open, $staff);
            if (count($buckets['plain']) >= 3 && count($buckets['close']) >= 6 && count($buckets['collab']) >= 1) {
                $reply = $staff;
                break;
            }
        }
    }
    if (!$reply) {
        $diag = array();
        foreach (array('trevor', 'cyoung', 'fortin-mike', 'john', 'kayla') as $username) {
            $staff = Staff::lookup($username);
            if (!$staff)
                continue;
            $b = bridge_bucket($open, $staff);
            $diag[$username] = array(
                'active' => $staff->isActive() ? true : false,
                'signature' => $staff->getSignature() ? true : false,
                'plain' => count($b['plain']),
                'close' => count($b['close']),
                'collab' => count($b['collab']),
                'any' => count($b['any']),
            );
        }
        bridge_fail('No active staff member with a signature can reply to enough open tickets.', array(
            'open' => count($open),
            'diag' => $diag,
        ));
    }

    $used = array();
    $take = function (&$list) use (&$used) {
        foreach ($list as $ticket) {
            $number = (string) $ticket->getNumber();
            if (isset($used[$number]))
                continue;
            $used[$number] = true;
            return $ticket;
        }
        return null;
    };

    $plain1 = $take($buckets['plain']);
    $plain2 = $take($buckets['plain']);
    $plain3 = $take($buckets['plain']);
    $close = $take($buckets['close']);
    $collab = $take($buckets['collab']);
    $stale = $take($buckets['any']);
    $race = $take($buckets['any']);
    $faultReply = $take($buckets['any']);
    $faultNote = $take($buckets['any']);
    $owner = $take($buckets['any']);
    $beforeReply = $take($buckets['any']);
    $twoNotes = $take($buckets['any']);
    $bodyHash = $take($buckets['any']);
    $due = $take($buckets['close']);
    $planted = $take($buckets['any']);
    // The real department template, not a swapped one. lastmessage has to
    // be in it so the suite can prove that variable still previews.
    $threadVars = null;
    foreach ($buckets['any'] as $candidate) {
        $number = (string) $candidate->getNumber();
        if (isset($used[$number]))
            continue;
        $dept = $candidate->getDept();
        $tpl = $dept ? $dept->getTemplate() : null;
        $msg = $tpl ? $tpl->getReplyMsgTemplate() : null;
        if (!$msg)
            continue;
        $blob = strtolower((string) $msg->getSubject() . "\n" . (string) $msg->getBody());
        if (strpos($blob, 'ticket.thread.lastmessage') === false)
            continue;
        $used[$number] = true;
        $threadVars = $candidate;
        break;
    }
    $verify = $take($buckets['any']);
    $verifyFault = $take($buckets['any']);
    $statusChange = $take($buckets['close']);
    $statusInterrupt = $take($buckets['close']);
    $statusNote = $take($buckets['close']);
    $replyExpect = $take($buckets['close']);
    $requestReuse = $take($buckets['any']);
    $templateProbe = $take($buckets['any']);
    if (!$plain1 || !$plain2 || !$plain3 || !$close || !$collab || !$stale
            || !$race || !$faultReply || !$faultNote || !$owner
            || !$beforeReply || !$twoNotes || !$bodyHash || !$due || !$planted
            || !$threadVars || !$verify || !$verifyFault || !$statusChange
            || !$statusInterrupt || !$statusNote || !$replyExpect
            || !$requestReuse || !$templateProbe)
        bridge_fail('Not enough distinct tickets for the reply staff.', array(
            'any' => count($buckets['any']),
            'close' => count($buckets['close']),
            'thread_lastmessage' => $threadVars ? true : false,
        ));

    $noAccessStaff = null;
    $noAccessTicket = null;
    foreach (array('shane', 'vaughn') as $username) {
        $candidate = Staff::lookup($username);
        if (!$candidate || !$candidate->isActive())
            continue;
        foreach ($open as $ticket) {
            if ($ticket->checkStaffPerm($candidate))
                continue;
            if ($ticket->isChild() && $ticket->getMergeType() != 'visual')
                continue;
            $noAccessStaff = $candidate;
            $noAccessTicket = $ticket;
            break 2;
        }
    }
    if (!$noAccessStaff)
        bridge_fail('No active staff member lacks access to an open ticket.');

    $noReplyStaff = null;
    $noReplyTicket = null;
    $adam = Staff::lookup('adam');
    $scan = array();
    if ($adam && $adam->isActive())
        $scan[] = $adam;
    foreach (Staff::objects()->filter(array('isactive' => 1)) as $staff) {
        if ($adam && (int) $staff->getId() == (int) $adam->getId())
            continue;
        $scan[] = $staff;
    }
    foreach ($scan as $staff) {
        foreach ($open as $ticket) {
            if (!$ticket->checkStaffPerm($staff))
                continue;
            if ($ticket->checkStaffPerm($staff, Ticket::PERM_REPLY))
                continue;
            if ($ticket->checkStaffPerm($staff, Ticket::PERM_CLOSE))
                continue;
            if ($ticket->isChild() && $ticket->getMergeType() != 'visual')
                continue;
            $noReplyStaff = $staff;
            $noReplyTicket = $ticket;
            break 2;
        }
    }
    if (!$noReplyStaff)
        bridge_fail('No active staff member can view a ticket but not reply.');

    return array(
        'open_tickets' => count($open),
        'hide_staff_name' => ($cfg && $cfg->hideStaffName()) ? true : false,
        'lock_enabled' => ($cfg && $cfg->isTicketLockEnabled()) ? true : false,
        'reply_staff' => bridge_staff_brief($reply),
        'no_access_staff' => bridge_staff_brief($noAccessStaff),
        'no_reply_staff' => bridge_staff_brief($noReplyStaff),
        'tickets' => array(
            'plain' => bridge_brief($plain1),
            'signature' => bridge_brief($plain2),
            'silent' => bridge_brief($plain3),
            'close' => bridge_brief($close),
            'collab' => bridge_brief($collab),
            'stale' => bridge_brief($stale),
            'race' => bridge_brief($race),
            'fault_reply' => bridge_brief($faultReply),
            'fault_note' => bridge_brief($faultNote),
            'owner' => bridge_brief($owner),
            'before_reply' => bridge_brief($beforeReply),
            'two_notes' => bridge_brief($twoNotes),
            'body_hash' => bridge_brief($bodyHash),
            'due_date' => bridge_brief($due),
            'planted' => bridge_brief($planted),
            'thread_vars' => bridge_brief($threadVars),
            'verify' => bridge_brief($verify),
            'verify_fault' => bridge_brief($verifyFault),
            'status_change' => bridge_brief($statusChange),
            'status_interrupt' => bridge_brief($statusInterrupt),
            'status_note' => bridge_brief($statusNote),
            'reply_expect' => bridge_brief($replyExpect),
            'request_reuse' => bridge_brief($requestReuse),
            'template_probe' => bridge_brief($templateProbe),
            'no_access' => bridge_brief($noAccessTicket),
            'no_reply' => bridge_brief($noReplyTicket),
        ),
    );
}

function bridge_bucket($open, $staff) {
    $out = array('plain' => array(), 'close' => array(), 'collab' => array(), 'any' => array());
    foreach ($open as $ticket) {
        if (!bridge_replyable($ticket, $staff, false))
            continue;
        $out['any'][] = $ticket;
        list(, $active) = bridge_active_collabs($ticket);
        if ($active > 0)
            $out['collab'][] = $ticket;
        else
            $out['plain'][] = $ticket;
        if (bridge_replyable($ticket, $staff, true))
            $out['close'][] = $ticket;
    }
    return $out;
}

function bridge_signature_get($username) {
    $staff = bridge_staff($username);
    return array(
        'ok' => true,
        'type' => (string) $staff->getDefaultSignatureType(),
        'text' => (string) $staff->getSignature(),
        'name' => (string) $staff->getName(),
    );
}

function bridge_signature_set($username, $type) {
    if (!in_array($type, array('none', 'mine', 'dept'), true))
        bridge_fail('Signature type must be none, mine, or dept.');
    $staff = bridge_staff($username);
    $staff->default_signature_type = $type;
    if (!$staff->save())
        bridge_fail('Could not save the signature default.');
    $fresh = Staff::lookup($staff->getId());
    return array(
        'ok' => true,
        'type' => (string) $fresh->getDefaultSignatureType(),
    );
}

function bridge_state($number) {
    $ticket = bridge_ticket($number);
    $counts = array('M' => 0, 'R' => 0, 'N' => 0);
    $last = 0;
    foreach ($ticket->getThread()->getEntries() as $entry) {
        $type = (string) $entry->getType();
        if (isset($counts[$type]))
            $counts[$type]++;
        $id = (int) $entry->getId();
        if ($id > $last)
            $last = $id;
    }
    $status = $ticket->getStatus();
    return array(
        'ok' => true,
        'number' => (string) $ticket->getNumber(),
        'status_id' => (int) $ticket->getStatusId(),
        'status_name' => $status ? (string) $status->getName() : '',
        'state' => (string) $ticket->getState(),
        'staff_id' => $ticket->getStaffId() ? (int) $ticket->getStaffId() : 0,
        'team_id' => $ticket->getTeamId() ? (int) $ticket->getTeamId() : 0,
        'last_entry_id' => $last,
        'counts' => $counts,
    );
}

function bridge_entries($number, $since) {
    $ticket = bridge_ticket($number);
    $rows = array();
    foreach ($ticket->getThread()->getEntries() as $entry) {
        $id = (int) $entry->getId();
        if ($id <= $since)
            continue;
        $rows[] = array(
            'id' => $id,
            'type' => (string) $entry->getType(),
            'staff_id' => (int) $entry->getStaffId(),
            'user_id' => (int) $entry->getUserId(),
            'poster' => (string) $entry->getPoster(),
            'format' => (string) $entry->format,
            'body' => (string) $entry->body,
        );
    }
    usort($rows, function ($a, $b) { return $a['id'] - $b['id']; });
    return array('ok' => true, 'entries' => $rows);
}

function bridge_collabs($number) {
    $ticket = bridge_ticket($number);
    list($rows,) = bridge_active_collabs($ticket);
    return array('ok' => true, 'collaborators' => $rows);
}

function bridge_post_message($number, $text) {
    global $thisstaff;
    if (trim($text) === '')
        bridge_fail('Message text is required.');
    $ticket = bridge_ticket($number);
    // A customer message, not a staff action. Alerts stay off so this
    // does not email anyone; it only adds a thread entry.
    $thisstaff = null;
    $message = $ticket->postMessage(array(
        'userId' => $ticket->getUserId(),
        'message' => $text,
    ), 'Web', false);
    if (!$message)
        bridge_fail('postMessage returned nothing.');
    return array(
        'ok' => true,
        'entry_id' => (int) $message->getId(),
        'type' => (string) $message->getType(),
    );
}

function bridge_restore($number, $statusId, $staffId) {
    global $thisstaff;
    $ticket = bridge_ticket($number);
    $actor = $ticket->getStaff();
    if ($actor)
        $thisstaff = $actor;
    $status = TicketStatus::lookup($statusId);
    if (!$status)
        bridge_fail('Status was not found.');
    $errors = array();
    // Empty comments: setStatus posts an alerted note when comments are set.
    $ok = $ticket->setStatus($status, '', $errors);
    if (!$ok)
        bridge_fail('setStatus failed.', array('errors' => $errors));
    $ticket = Ticket::lookup($ticket->getId());
    if ((int) $ticket->getStaffId() !== (int) $staffId) {
        $ticket->setStaffId((int) $staffId);
        $ticket = Ticket::lookup($ticket->getId());
    }
    return array(
        'ok' => true,
        'status_id' => (int) $ticket->getStatusId(),
        'state' => (string) $ticket->getState(),
        'staff_id' => $ticket->getStaffId() ? (int) $ticket->getStaffId() : 0,
    );
}

function bridge_instance() {
    $plugin = bridge_plugin();
    if (!$plugin)
        bridge_fail('Plugin is not installed.');
    foreach ($plugin->getInstances() as $inst)
        return $inst;
    bridge_fail('Plugin has no instance.');
}

/**
 * ChoiceField stores the status id. Post that id back so a later save
 * does not blank the field. The name is not required here.
 */
function bridge_close_status_id($cfg) {
    $stored = $cfg ? $cfg->get('default_close_status') : null;
    if (is_array($stored)) {
        reset($stored);
        $stored = key($stored);
    }
    if ($stored === null || $stored === '')
        return '';
    return (string) (int) $stored;
}

/**
 * Render the instance Config form the way the admin page does.
 * Returns flags only. If the shared secret appears in the HTML, this
 * refuses and does not return the HTML.
 */
function bridge_render_config() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $inst = bridge_instance();
    $cfg = $inst->getConfig();
    $secret = (string) $cfg->get('shared_secret');
    $form = $inst->getForm();
    if (!$form)
        bridge_fail('Config form did not load.');
    $options = array();
    ob_start();
    include INCLUDE_DIR . 'staff/templates/simple-form.tmpl.php';
    $html = ob_get_clean();
    if ($secret !== '' && strpos($html, $secret) !== false)
        bridge_fail('The rendered config page contains the shared secret. Refusing to return it.');
    $needles = array(
        'WGS Reply Bridge help',
        'autocomplete="new-password"',
        'Browsers may autofill',
        'ip_not_allowed',
        'auth_failed',
        'stale_timestamp',
        'verification_failed',
        'MCP_MUX_ALLOW_ANONYMOUS',
        'MCP_MUX_IDENTITIES_FILE',
        'MCP_MUX_TOKEN',
        'OSTICKET_BRIDGE_URL',
        'OSTICKET_BRIDGE_SECRET',
        'OSTICKET_BRIDGE_CONNECT_IP',
        'OSTICKET_BRIDGE_TIMEOUT',
        'OSTICKET_STAFF_IDENTITIES_FILE',
        'staff_identities.json',
        'set-secret.php',
    );
    $found = array();
    foreach ($needles as $needle)
        $found[$needle] = (strpos($html, $needle) !== false);
    $found['disabled'] = (strpos($html, 'disabled.') !== false);
    $found['Help heading'] = (strpos($html, 'Help</h3>') !== false);
    return array(
        'ok' => true,
        'html_length' => strlen($html),
        'secret_in_html' => false,
        'found' => $found,
    );
}

/**
 * Rewrite one non-secret setting. The decrypted secret is posted back so
 * the password field does not wipe it. The secret is not part of the output.
 */
function bridge_config($changes) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inst = bridge_instance();
    $cfg = $inst->getConfig();
    $vars = array(
        'name' => $inst->getName() ?: 'WGS Reply Bridge',
        'notes' => '',
        'isactive' => 1,
        'shared_secret' => (string) $cfg->get('shared_secret'),
        'allowed_ips' => (string) $cfg->get('allowed_ips'),
        'enabled' => $cfg->get('enabled') ? 1 : 0,
        'max_body_bytes' => (string) ($cfg->get('max_body_bytes') ?: '8388608'),
        'default_close_status' => bridge_close_status_id($cfg),
    );
    foreach ($changes as $key => $value) {
        if (!array_key_exists($key, $vars) || $key === 'shared_secret')
            bridge_fail('Refusing to change that setting.');
        $vars[$key] = $value;
    }
    $errors = array();
    if (!$inst->update($vars, $errors))
        bridge_fail('Could not update the plugin instance.', array('errors' => $errors));
    $out = array('ok' => true);
    foreach ($changes as $key => $value)
        $out[$key] = $value;
    return $out;
}

function bridge_set_collab($number, $userId, $active) {
    $ticket = bridge_ticket($number);
    if ($list = $ticket->getCollaborators()) {
        foreach ($list as $c) {
            if ((int) $c->getUserId() !== (int) $userId)
                continue;
            $c->setFlag(Collaborator::FLAG_ACTIVE, $active ? true : false);
            if (!$c->save())
                bridge_fail('Could not save the collaborator flag.');
            return array(
                'ok' => true,
                'user_id' => (int) $userId,
                'active' => $c->isActive() ? true : false,
            );
        }
    }
    bridge_fail('That user is not a collaborator on this ticket.');
}

function bridge_other_user($number) {
    $ticket = bridge_ticket($number);
    $skip = array((int) $ticket->getOwnerId() => true);
    if ($list = $ticket->getCollaborators()) {
        foreach ($list as $c)
            $skip[(int) $c->getUserId()] = true;
    }
    foreach (User::objects()->order_by('id')->limit(500) as $user) {
        $id = (int) $user->getId();
        if (isset($skip[$id]))
            continue;
        $email = strtolower((string) $user->getEmail());
        if ($email === '' || strpos($email, '@') === false)
            continue;
        return array(
            'ok' => true,
            'user_id' => $id,
            'email' => $email,
        );
    }
    bridge_fail('No user is available who is neither the owner nor a collaborator.');
}

function bridge_set_owner($number, $username, $userId) {
    global $thisstaff;
    $staff = bridge_staff($username);
    $thisstaff = $staff;
    $ticket = bridge_ticket($number);
    $old = (int) $ticket->getOwnerId();
    $user = User::lookup((int) $userId);
    if (!$user)
        bridge_fail('User was not found.');
    if (!$ticket->changeOwner($user)) {
        bridge_fail('changeOwner returned false.', array(
            'can_edit' => $ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) ? true : false,
            'old_user_id' => $old,
        ));
    }
    $fresh = Ticket::lookup((int) $ticket->getId());
    return array(
        'ok' => true,
        'old_user_id' => $old,
        'owner_user_id' => (int) $fresh->getOwnerId(),
        'owner_email' => (string) $fresh->getEmail(),
    );
}

function bridge_ledger($requestId) {
    if (!preg_match('/^[A-Za-z0-9-]{36}$/', (string) $requestId))
        bridge_fail('request id is not valid.');
    $sql = 'SELECT status, ticket_id, staff_id, response_entry_id, note_entry_id,'
        . ' native_returned, baseline_entry_id FROM `' . TABLE_PREFIX . 'wgs_reply_ledger`'
        . ' WHERE request_id=' . db_input($requestId);
    if (!($res = db_query($sql)) || !db_num_rows($res))
        return array('ok' => true, 'found' => false);
    $row = db_fetch_array($res);
    return array(
        'ok' => true,
        'found' => true,
        'status' => (string) $row['status'],
        'ticket_id' => (int) $row['ticket_id'],
        'staff_id' => (int) $row['staff_id'],
        'response_entry_id' => $row['response_entry_id'] ? (int) $row['response_entry_id'] : null,
        'note_entry_id' => $row['note_entry_id'] ? (int) $row['note_entry_id'] : null,
        'native_returned' => !empty($row['native_returned']) ? 1 : 0,
        'baseline_entry_id' => $row['baseline_entry_id'] !== null ? (int) $row['baseline_entry_id'] : null,
    );
}

/**
 * Rewrite a reply_recorded_mail_unknown result to the plain JSON older
 * code stored, with no expect wrapper, so a retry can prove it still
 * replays the stored bytes.
 */
function bridge_legacy_mail_unknown($requestId) {
    if (!preg_match('/^[A-Za-z0-9-]{36}$/', (string) $requestId))
        bridge_fail('request id is not valid.');
    $sql = 'SELECT response_entry_id, note_entry_id FROM `' . TABLE_PREFIX . 'wgs_reply_ledger`'
        . ' WHERE request_id=' . db_input($requestId)
        . ' AND status=' . db_input('reply_recorded_mail_unknown');
    if (!($res = db_query($sql)) || !db_num_rows($res))
        bridge_fail('No mail-unknown row with that request id.');
    $row = db_fetch_array($res);
    $body = json_encode(array(
        'ok' => false,
        'error' => 'mail_handoff_unknown',
        'message' => 'legacy plain result',
        'response_entry_id' => (int) $row['response_entry_id'],
        'note_entry_id' => (int) $row['note_entry_id'],
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $update = 'UPDATE `' . TABLE_PREFIX . 'wgs_reply_ledger`'
        . ' SET result_json=' . db_input($body)
        . ' WHERE request_id=' . db_input($requestId);
    if (!db_query($update))
        bridge_fail('Could not rewrite the ledger result.');
    return array('ok' => true, 'body' => $body);
}

/**
 * The fault is a PHP constant in the staging ost-config.php. It is not a
 * plugin setting. The file holds the database password, so this rewrites
 * only a marked block and never prints the file. Apache revalidates the
 * script after a few seconds (opcache.validate_timestamps is on).
 */
function bridge_fault_constant($flag) {
    if ($flag === 'off')
        $flag = '';
    if ($flag !== '' && $flag !== 'after_reply' && $flag !== 'note_fail'
            && $flag !== 'before_reply' && $flag !== 'verify_status'
            && $flag !== 'status_interrupt')
        bridge_fail('Fault must be after_reply, note_fail, before_reply, verify_status, status_interrupt, or off.');

    $path = '/var/www/html/include/ost-config.php';
    $raw = file_get_contents($path);
    if ($raw === false)
        bridge_fail('Could not read the staging config.');

    $begin = '/* WGS_REPLY_BRIDGE_TEST_FAULTS */';
    $end = '/* /WGS_REPLY_BRIDGE_TEST_FAULTS */';
    $inner = $begin . "\n";
    if ($flag !== '')
        $inner .= "define('WGS_REPLY_BRIDGE_TEST_FAULTS', " . var_export($flag, true) . ");\n";
    $inner .= $end;
    $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';
    if (preg_match($pattern, $raw))
        $next = preg_replace($pattern, $inner, $raw, 1);
    else
        $next = rtrim($raw) . "\n<?php\n" . $inner . "\n";

    $tmp = tempnam(sys_get_temp_dir(), 'wgsfault');
    if ($tmp === false || file_put_contents($tmp, $next) === false)
        bridge_fail('Could not stage the fault constant.');
    $lint = array();
    $code = 0;
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
    if ($code !== 0) {
        unlink($tmp);
        bridge_fail('Refusing to write the staging config because the syntax check failed.');
    }
    if (!copy($tmp, $path)) {
        unlink($tmp);
        bridge_fail('Could not update the fault constant.');
    }
    unlink($tmp);
    // revalidate_freq is 2s and file_update_protection is 2s.
    sleep(4);
    return array('ok' => true, 'fault' => ($flag === '' ? 'off' : $flag));
}

/**
 * Plant or delete a test_fault row in plugin config. The bridge must
 * ignore it. null deletes. The value is not a secret.
 */
function bridge_fault_config($value) {
    $inst = bridge_instance();
    $ns = $inst->getNamespace();
    db_query('DELETE FROM ' . CONFIG_TABLE . ' WHERE namespace=' . db_input($ns)
        . ' AND `key`=' . db_input('test_fault'));
    if ($value !== null) {
        $sql = 'INSERT INTO ' . CONFIG_TABLE . ' (namespace, `key`, value) VALUES ('
            . db_input($ns) . ',' . db_input('test_fault') . ',' . db_input($value) . ')';
        if (!db_query($sql))
            bridge_fail('Could not plant the config row.');
    }
    $stored = null;
    $res = db_query('SELECT value FROM ' . CONFIG_TABLE . ' WHERE namespace=' . db_input($ns)
        . ' AND `key`=' . db_input('test_fault'));
    if ($res && ($row = db_fetch_array($res)) && isset($row['value']))
        $stored = (string) $row['value'];
    return array(
        'ok' => true,
        'value' => $stored,
    );
}

function bridge_ui_reply($number, $username, $text) {
    global $thisstaff;
    if (trim($text) === '')
        bridge_fail('Reply text is required.');
    $staff = bridge_staff($username);
    $thisstaff = $staff;
    $ticket = bridge_ticket($number);
    $clean = ThreadEntryBody::clean($text);
    if (!$clean)
        bridge_fail('Reply text is empty after clean.');
    $errors = array();
    // alert false: the entry is a real staff postReply, and this probe
    // does not need a second customer email.
    $response = $ticket->postReply(array(
        'response' => $clean,
        'reply-to' => 'user',
        'reply_status_id' => (int) $ticket->getStatusId(),
        'signature' => 'none',
    ), $errors, false);
    if (!$response || !$response->getId())
        bridge_fail('postReply returned nothing.', array('errors' => $errors));
    return array(
        'ok' => true,
        'response_entry_id' => (int) $response->getId(),
        'type' => (string) $response->getType(),
        'staff_id' => (int) $response->getStaffId(),
    );
}

function bridge_reply_template_row($number) {
    $ticket = bridge_ticket($number);
    $dept = $ticket->getDept();
    $tpl = $dept ? $dept->getTemplate() : null;
    $msg = $tpl ? $tpl->getReplyMsgTemplate() : null;
    if (!$msg)
        bridge_fail('This department has no ticket.reply template.');
    return $msg;
}

function bridge_reply_template($number) {
    $msg = bridge_reply_template_row($number);
    return array(
        'ok' => true,
        'id' => (int) $msg->getId(),
        'subject' => (string) $msg->getSubject(),
        'body' => (string) $msg->getBody(),
    );
}

/**
 * Writes the subject and body from stdin JSON. Direct SQL so a later
 * restore puts the original bytes back. Format::sanitize is not run.
 */
function bridge_write_reply_template($number) {
    $raw = stream_get_contents(STDIN);
    $in = json_decode((string) $raw, true);
    if (!is_array($in) || !isset($in['subject']) || !isset($in['body'])
            || !is_string($in['subject']) || !is_string($in['body']))
        bridge_fail('stdin must be a JSON object with subject and body.');
    if ($in['subject'] === '' || $in['body'] === '')
        bridge_fail('subject and body are required.');
    $msg = bridge_reply_template_row($number);
    $sql = 'UPDATE ' . EMAIL_TEMPLATE_TABLE
        . ' SET subject=' . db_input($in['subject'])
        . ', body=' . db_input($in['body'])
        . ' WHERE id=' . db_input((int) $msg->getId());
    if (!db_query($sql))
        bridge_fail('Could not update the reply template.');
    $id = (int) $msg->getId();
    $res = db_query('SELECT subject, body FROM ' . EMAIL_TEMPLATE_TABLE . ' WHERE id=' . db_input($id));
    $row = ($res) ? db_fetch_array($res) : null;
    if (!$row)
        bridge_fail('The reply template row was not readable after the update.');
    return array(
        'ok' => true,
        'id' => $id,
        'subject' => (string) $row['subject'],
        'body' => (string) $row['body'],
    );
}

function bridge_impl() {
    $plugin = bridge_plugin();
    if (!$plugin)
        bridge_fail('Reply bridge plugin is not installed.');
    $impl = $plugin->getImpl();
    if (!$impl)
        bridge_fail('Reply bridge plugin did not load.');
    return $impl;
}

/**
 * Calls the pure verifier with an injected snapshot. Reads one ticket
 * before and after so a write would show up. Does not update the ticket.
 */
function bridge_verify_compare() {
    $impl = bridge_impl();
    $ticket = null;
    foreach (Ticket::objects()->filter(array('status__state' => 'open')) as $candidate) {
        $ticket = $candidate;
        break;
    }
    if (!$ticket)
        bridge_fail('No open ticket to prove the verifier does not write.');
    $before = (int) $ticket->getStatusId();
    $noteBody = $impl->expectedNoteColumn('Bridge pure verifier probe');
    $snapshot = array(
        'status_id' => 1,
        'staff_id' => 0,
        'reply' => null,
        'note' => array(
            'type' => 'N',
            'staff_id' => 0,
            'body' => $noteBody,
        ),
    );
    $expect = array(
        'reply_sha256' => '',
        'note_sha256' => $impl->columnHash($noteBody),
        'status_id' => 3,
        'require_reply' => false,
    );
    $verification = $impl->compareVerification($snapshot, $expect);
    $fresh = Ticket::lookup((int) $ticket->getId());
    $after = $fresh ? (int) $fresh->getStatusId() : -1;
    return array(
        'ok' => true,
        'ticket_number' => (string) $ticket->getNumber(),
        'status_before' => $before,
        'status_after' => $after,
        'verification' => $verification,
    );
}

/**
 * Point adam at a role that can close and create, and cannot reply, for
 * one closeable ticket's department. The suite restores it in the same test.
 */
function bridge_grant_close_no_reply() {
    $staff = Staff::lookup('adam');
    if (!$staff || !$staff->isActive())
        bridge_fail('adam is not an active staff member.');
    $ticket = null;
    foreach (Ticket::objects()->filter(array('status__state' => 'open')) as $candidate) {
        if (!$candidate->checkStaffPerm($staff))
            continue;
        if ($candidate->isChild() && $candidate->getMergeType() != 'visual')
            continue;
        if ($candidate->isCloseable() !== true)
            continue;
        $ticket = $candidate;
        break;
    }
    if (!$ticket)
        bridge_fail('No closeable open ticket that adam can open.');

    $perms = JsonDataEncoder::encode(array(
        'ticket.close' => 1,
        'ticket.create' => 1,
    ));
    $role = Role::lookup(array('name' => 'Bridge close without reply'));
    if ($role) {
        $role->flags = Role::FLAG_ENABLED;
        $role->permissions = $perms;
        $role->notes = '';
    } else {
        $role = Role::create(array(
            'flags' => Role::FLAG_ENABLED,
            'name' => 'Bridge close without reply',
            'permissions' => $perms,
            'notes' => '',
        ));
    }
    if (!$role->save(true))
        bridge_fail('Could not save the close-without-reply role.');

    $staffId = (int) $staff->getId();
    $deptId = (int) $ticket->getDeptId();
    $roleId = (int) $role->getId();
    $access = StaffDeptAccess::lookup(array(
        'staff_id' => $staffId,
        'dept_id' => $deptId,
    ));
    $createdRow = 0;
    $previousRoleId = 0;
    if ($access) {
        $previousRoleId = (int) $access->role_id;
        $access->role_id = $roleId;
        if (!$access->save())
            bridge_fail('Could not point the department access at the temporary role.');
    } else {
        $createdRow = 1;
        $access = StaffDeptAccess::create(array(
            'staff_id' => $staffId,
            'dept_id' => $deptId,
            'role_id' => $roleId,
            'flags' => 0,
        ));
        if (!$access->save())
            bridge_fail('Could not add department access for the temporary role.');
    }

    $freshStaff = Staff::lookup($staffId);
    $freshTicket = Ticket::lookup((int) $ticket->getId());
    $effective = $freshTicket ? $freshTicket->getRole($freshStaff) : null;
    return array(
        'ok' => true,
        'staff_id' => $staffId,
        'staff_username' => (string) $freshStaff->getUserName(),
        'ticket_number' => (string) $freshTicket->getNumber(),
        'dept_id' => $deptId,
        'previous_role_id' => $previousRoleId,
        'created_row' => $createdRow,
        'role_id' => $roleId,
        'can_reply' => ($effective && $effective->hasPerm(Ticket::PERM_REPLY)) ? true : false,
        'can_close' => ($effective && $effective->hasPerm(Ticket::PERM_CLOSE)) ? true : false,
        'can_create' => ($effective && $effective->hasPerm(Ticket::PERM_CREATE)) ? true : false,
        'closeable' => $freshTicket->isCloseable() === true,
    );
}

function bridge_restore_close_no_reply($staffId, $deptId, $previousRoleId, $createdRow, $roleId) {
    if ($staffId < 1 || $deptId < 1 || $roleId < 1)
        bridge_fail('restore-close-no-reply needs staff, department, and role ids.');
    if ($createdRow) {
        db_query('DELETE FROM ' . STAFF_DEPT_TABLE
            . ' WHERE staff_id=' . db_input($staffId)
            . ' AND dept_id=' . db_input($deptId)
            . ' AND role_id=' . db_input($roleId));
    } else {
        db_query('UPDATE ' . STAFF_DEPT_TABLE
            . ' SET role_id=' . db_input($previousRoleId)
            . ' WHERE staff_id=' . db_input($staffId)
            . ' AND dept_id=' . db_input($deptId));
    }
    $deptLeft = (int) db_result(db_query(
        'SELECT COUNT(*) FROM ' . STAFF_DEPT_TABLE . ' WHERE role_id=' . db_input($roleId)));
    $staffLeft = (int) db_result(db_query(
        'SELECT COUNT(*) FROM ' . STAFF_TABLE . ' WHERE role_id=' . db_input($roleId)));
    if ($deptLeft || $staffLeft)
        bridge_fail('The temporary role is still referenced.', array(
            'role_id' => $roleId,
            'dept_rows' => $deptLeft,
            'staff_rows' => $staffLeft,
        ));
    db_query('DELETE FROM ' . ROLE_TABLE
        . ' WHERE id=' . db_input($roleId)
        . ' AND name=' . db_input('Bridge close without reply'));
    $still = Role::lookup($roleId);
    if ($still)
        bridge_fail('The temporary role was not deleted.', array('role_id' => $roleId));
    return array('ok' => true, 'role_deleted' => true);
}
