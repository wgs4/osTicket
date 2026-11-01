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
        if ($flag === 'off')
            $flag = '';
        if ($flag !== '' && $flag !== 'after_reply' && $flag !== 'note_fail')
            bridge_fail('Fault must be after_reply, note_fail, or off.');
        bridge_out(bridge_config(array('test_fault' => $flag)));
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
            'test_fault' => '',
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
                'test_fault' => (string) $inst->getConfig()->get('test_fault'),
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
        if (count($buckets['plain']) >= 3 && count($buckets['close']) >= 1 && count($buckets['collab']) >= 1) {
            $reply = $staff;
            break;
        }
    }
    if (!$reply) {
        foreach (Staff::objects()->filter(array('isactive' => 1)) as $staff) {
            if (!$staff->getSignature())
                continue;
            $buckets = bridge_bucket($open, $staff);
            if (count($buckets['plain']) >= 3 && count($buckets['close']) >= 1 && count($buckets['collab']) >= 1) {
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
    if (!$plain1 || !$plain2 || !$plain3 || !$close || !$collab || !$stale
            || !$race || !$faultReply || !$faultNote || !$owner)
        bridge_fail('Not enough distinct tickets for the reply staff.', array(
            'any' => count($buckets['any']),
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
        'test_fault' => (string) $cfg->get('test_fault'),
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
        . ' baseline_entry_id FROM `' . TABLE_PREFIX . 'wgs_reply_ledger`'
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
        'baseline_entry_id' => $row['baseline_entry_id'] !== null ? (int) $row['baseline_entry_id'] : null,
    );
}
