<?php
/*********************************************************************
    WGS Reply Bridge

    Machine route that posts a staff reply by calling Ticket::postReply()
    the way scp/tickets.php does for a=reply. osTicket's mailer sends.
    This plugin never opens a socket to SMTP and never inserts thread rows.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once INCLUDE_DIR . 'class.plugin.php';
require_once dirname(__FILE__) . '/config.php';

class WgsReplyBridgePlugin extends Plugin {
    var $config_class = 'WgsReplyBridgeConfig';
    var $instance = null;
    // GET_LOCK name held for this request. respond() releases it.
    var $advisoryName = null;
    var $nativeLockTicket = null;
    var $nativeLockStaffId = 0;
    // threadentry.created listener for this request only. Signal has no
    // disconnect, and the subscriber list lives for the whole request, so
    // this is cleared before the listener is removed.
    var $capture = null;
    var $captureConnected = false;

    const PREVIEW_TTL = 900;
    const TIMESTAMP_SKEW = 300;
    const ADVISORY_WAIT = 10;

    function isMultiInstance() {
        return false;
    }

    function bootstrap() {
        $config = $this->getConfig();
        if ($config)
            $this->instance = $config->getInstance();
        $this->ensureLedger();
        Signal::connect('api', array($this, 'registerRoutes'));
    }

    function registerRoutes($dispatcher) {
        $dispatcher->append(url_post('^/wgs-reply/options$', array($this, 'options')));
        $dispatcher->append(url_post('^/wgs-reply/canned$', array($this, 'canned')));
        $dispatcher->append(url_post('^/wgs-reply/preview$', array($this, 'preview')));
        $dispatcher->append(url_post('^/wgs-reply/send$', array($this, 'send')));
    }

    function config() {
        if ($this->instance)
            return $this->instance->getConfig();
        return $this->getConfig();
    }

    function ensureLedger() {
        $table = '`' . TABLE_PREFIX . 'wgs_reply_ledger`';
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . '`request_id` VARCHAR(64) NOT NULL,'
            . '`ticket_id` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`staff_id` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`status` VARCHAR(32) NOT NULL,'
            . '`response_entry_id` INT UNSIGNED NULL,'
            . '`note_entry_id` INT UNSIGNED NULL,'
            . '`native_returned` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`baseline_entry_id` INT UNSIGNED NULL,'
            . '`executing_at` INT UNSIGNED NULL,'
            . '`created` DATETIME NOT NULL,'
            . '`result_json` MEDIUMTEXT NULL,'
            . 'PRIMARY KEY (`request_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8';
        db_query($sql);
        // Tables created by 0.1 lack the execution cursor. Never drop rows.
        if (!$this->ledgerColumn('baseline_entry_id'))
            db_query('ALTER TABLE ' . $table . ' ADD COLUMN `baseline_entry_id` INT UNSIGNED NULL');
        if (!$this->ledgerColumn('executing_at'))
            db_query('ALTER TABLE ' . $table . ' ADD COLUMN `executing_at` INT UNSIGNED NULL');
        if (!$this->ledgerColumn('native_returned'))
            db_query('ALTER TABLE ' . $table . ' ADD COLUMN `native_returned` TINYINT(1) NOT NULL DEFAULT 0');
        // Once per install. ALTER on every request takes a metadata lock
        // and would serialize two sends that should only share GET_LOCK.
        if (!$this->ledgerStatusWide())
            db_query('ALTER TABLE ' . $table . ' MODIFY `status` VARCHAR(32) NOT NULL');
    }

    function ledgerColumn($name) {
        $sql = 'SHOW COLUMNS FROM `' . TABLE_PREFIX . 'wgs_reply_ledger` LIKE ' . db_input($name);
        return ($res = db_query($sql)) && db_num_rows($res);
    }

    function ledgerStatusWide() {
        $sql = 'SHOW COLUMNS FROM `' . TABLE_PREFIX . 'wgs_reply_ledger` LIKE ' . db_input('status');
        if (!($res = db_query($sql)) || !($row = db_fetch_array($res)))
            return false;
        $type = isset($row['Type']) ? $row['Type'] : (isset($row['type']) ? $row['type'] : '');
        return (bool) (preg_match('/varchar\((\d+)\)/i', (string) $type, $m) && (int) $m[1] >= 32);
    }

    // ---- HTTP gate -------------------------------------------------------

    function options() {
        $in = $this->gate('options');
        $ctx = $this->context($in, false);
        // No ticket facts until access is granted. ticket-not-found already
        // failed inside context() with an empty body.
        if ($ctx['block'] && $ctx['block']['error'] === 'no_access')
            $this->fail($ctx['block']['error'], $ctx['block']['message'], $ctx['block']['http']);
        $this->ok($this->buildOptions($ctx['ticket'], $ctx['staff'], $ctx['block']));
    }

    function canned() {
        $in = $this->gate('canned');
        $ctx = $this->context($in, true);
        $this->ok($this->expandCanned($ctx['ticket'], $ctx['staff'], $in));
    }

    function preview() {
        $in = $this->gate('preview');
        $ctx = $this->context($in, true);
        $resolved = $this->resolveReply($ctx['ticket'], $ctx['staff'], $in);
        $this->ok($this->buildPreview($ctx['ticket'], $ctx['staff'], $resolved));
    }

    function send() {
        $in = $this->gate('send');
        $requestId = $this->requestId();

        // A finished id returns the stored bytes and does not touch the
        // ticket. That is a completed reply, or a terminal failure that
        // must not be sent again.
        if ($stored = $this->ledgerFetch($requestId)) {
            $done = $this->storedResult($stored);
            if ($done)
                $this->respond($done[0], $done[1]);
        }

        $summary = isset($in['note_summary']) ? (string) $in['note_summary'] : '';
        $ctx = $this->context($in, true);
        $ticket = $ctx['ticket'];
        $staff = $ctx['staff'];

        $this->advisoryAcquire($ticket->getId());
        try {
            $this->reloadTicket($ticket);
            $row = $this->ledgerFetch($requestId);
            if ($row && (int) $row['ticket_id'] !== (int) $ticket->getId())
                $this->fail('already_done', 'This request id was used on another ticket.', 409);

            $done = $this->storedResult($row);
            if ($done)
                $this->respond($done[0], $done[1]);

            // Resume before the hash check. A saved reply's own new entry,
            // or another send that landed after it, must not look stale.
            if ($row)
                $this->resumeLedger($ticket, $staff, $summary, $row);

            if (trim($summary) === '')
                $this->fail('empty_summary', 'note_summary is required.', 400);

            $resolved = $this->resolveReply($ticket, $staff, $in);
            $this->assertPreviewHash($ticket, $staff, $resolved, @$in['preview_hash']);

            if (!$row)
                $this->ledgerInsert($requestId, $ticket->getId(), $staff->getId(), 'reserved');
            elseif ($row['status'] !== 'reserved')
                $this->fail('already_done', 'This request id cannot be posted again.', 409);

            // Drop a non-column `owner` dirty key before either save.
            // postReply's setStatus ignores a failed save and leaves the
            // ticket closed only in memory.
            $this->dropOwnerDirty($ticket);
            $this->nativeLock($ticket, $staff);
            $this->applyCollaborators($ticket, $resolved['ccs']);
            $vars = $this->replyVars($resolved);
            $baseline = $this->lastEntryId($ticket);
            $this->ledgerMarkExecuting($requestId, $baseline);
            $this->correlateReply($ticket, $staff, $summary, $resolved, $vars, $requestId);
        } catch (WgsReplyBridgeFault $ex) {
            $this->settleNativeFailure($ticket, $staff, $summary, $requestId);
        } catch (Throwable $ex) {
            $this->settleNativeFailure($ticket, $staff, $summary, $requestId);
        }
    }

    // ---- authentication ---------------------------------------------------

    function gate($action) {
        $cfg = $this->config();
        if (!$cfg || !$cfg->get('enabled'))
            $this->fail('auth_failed', 'Reply bridge is disabled.', 403);

        // IP before the body. A rejected host must not be able to make us
        // buffer a large request.
        $ip = osTicket::get_client_ip();
        $allowed = preg_split('/\s*,\s*/', (string) $cfg->get('allowed_ips'), -1, PREG_SPLIT_NO_EMPTY);
        if (!$ip || !Validator::check_ip($ip, $allowed))
            $this->fail('ip_not_allowed', 'Source address is not allowed.', 403);

        $max = (int) $cfg->get('max_body_bytes');
        if ($max < 1)
            $max = 8388608;
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($declared > $max)
            $this->fail('invalid_option', 'Request body is too large.', 413);
        $raw = $this->readBody($max);
        if ($raw === false)
            $this->fail('invalid_option', 'Request body is too large.', 413);

        $stamp = isset($_SERVER['HTTP_X_WGS_TIMESTAMP']) ? $_SERVER['HTTP_X_WGS_TIMESTAMP'] : '';
        $rid = isset($_SERVER['HTTP_X_WGS_REQUEST_ID']) ? $_SERVER['HTTP_X_WGS_REQUEST_ID'] : '';
        $sig = isset($_SERVER['HTTP_X_WGS_SIGNATURE']) ? $_SERVER['HTTP_X_WGS_SIGNATURE'] : '';
        if (!preg_match('/^\d+$/', (string) $stamp) || !preg_match('/^[A-Za-z0-9-]{36}$/', (string) $rid))
            $this->fail('auth_failed', 'Authentication failed.', 401);
        if (abs(time() - (int) $stamp) > self::TIMESTAMP_SKEW)
            $this->fail('stale_timestamp', 'Timestamp is outside the allowed window.', 401);

        $secret = (string) $cfg->get('shared_secret');
        if ($secret === '' || !is_string($sig) || !preg_match('/^[a-f0-9]{64}$/i', $sig))
            $this->fail('auth_failed', 'Authentication failed.', 401);

        // Public route path, not PATH_INFO. The rewrite to api/http.php
        // must not change what the caller signs.
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        $path = '/api/wgs-reply/' . $action;
        $material = $stamp . "\n" . $rid . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $raw);
        $expect = hash_hmac('sha256', $material, $secret);
        if (!hash_equals($expect, strtolower($sig)))
            $this->fail('auth_failed', 'Authentication failed.', 401);

        $data = json_decode($raw, true);
        if (!is_array($data))
            $this->fail('invalid_option', 'Body must be a JSON object.', 400);
        return $data;
    }

    function requestId() {
        return (string) $_SERVER['HTTP_X_WGS_REQUEST_ID'];
    }

    /**
     * Read at most $max + 1 bytes. false means the body exceeded $max.
     * CONTENT_LENGTH is rejected before this is called.
     */
    function readBody($max) {
        $fh = fopen('php://input', 'rb');
        if (!$fh)
            return '';
        $raw = '';
        $limit = $max + 1;
        while (!feof($fh) && strlen($raw) < $limit) {
            $chunk = fread($fh, $limit - strlen($raw));
            if ($chunk === false || $chunk === '')
                break;
            $raw .= $chunk;
        }
        fclose($fh);
        if (strlen($raw) > $max)
            return false;
        return $raw;
    }

    /**
     * Staging only. Production never defines this constant, and a value
     * stored in plugin config is not read.
     */
    function testFault() {
        if (!defined('WGS_REPLY_BRIDGE_TEST_FAULTS'))
            return '';
        $flag = (string) WGS_REPLY_BRIDGE_TEST_FAULTS;
        if ($flag === 'after_reply' || $flag === 'note_fail' || $flag === 'before_reply')
            return $flag;
        return '';
    }

    // ---- staff and ticket -------------------------------------------------

    function context(array $in, $requireReply) {
        global $thisstaff;

        $username = isset($in['staff_username']) ? (string) $in['staff_username'] : '';
        $number = isset($in['ticket_number']) ? (string) $in['ticket_number'] : '';
        if ($username === '' || $number === '')
            $this->fail('invalid_option', 'ticket_number and staff_username are required.', 400);

        $staff = Staff::lookup($username);
        if (!$staff)
            $this->fail('staff_not_found', 'Staff member was not found.', 404);
        if (!$staff->isActive())
            $this->fail('staff_inactive', 'Staff member is not active.', 403);

        // Every later osTicket call (postReply, postNote, signatures,
        // audit) reads this global. Caller-supplied staffId is ignored.
        $thisstaff = $staff;

        $ticket = Ticket::lookupByNumber($number);
        if (!$ticket || !$ticket->getId())
            $this->fail('ticket_not_found', 'Ticket was not found.', 404);

        $block = $this->replyBlock($ticket, $staff);
        if ($requireReply && $block)
            $this->fail($block['error'], $block['message'], $block['http']);

        return array('ticket' => $ticket, 'staff' => $staff, 'block' => $block);
    }

    /**
     * Reasons the Post Reply form would not be usable.
     * Access: include/class.ticket.php Ticket::checkStaffPerm.
     * Form hidden: include/staff/ticket-view.inc.php lines 30 and 795-809.
     * Banners: ticket-view.inc.php lines 49-58. Ban is also enforced in
     * scp/tickets.php lines 195-197.
     * An address is_email rejects before DNS (empty mailbox, host localhost)
     * is refused. is_valid_email also DNS-checks when verify_email_addrs is
     * on; that failure is only the banner at ticket-view.inc.php:57-58.
     * The form still renders and the reply controller does not consult it.
     */
    function replyBlock($ticket, $staff) {
        if (!$ticket->checkStaffPerm($staff))
            return array('error' => 'no_access', 'message' => 'Staff member cannot access this ticket.', 'http' => 403);

        $role = $ticket->getRole($staff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY))
            return array('error' => 'no_reply_permission', 'message' => 'Staff member cannot reply to this ticket.', 'http' => 403);

        // ticket-view.inc.php:30
        if ($ticket->isChild() && $ticket->getMergeType() != 'visual')
            return array('error' => 'no_access', 'message' => 'A merged child ticket cannot be replied to.', 'http' => 403);

        if (Banlist::isBanned($ticket->getEmail()))
            return array('error' => 'banned_owner', 'message' => 'The ticket owner is on the ban list.', 'http' => 403);

        // ticket-view.inc.php:57-58 and 795-809; scp/tickets.php:195-197
        if (!Validator::is_email($ticket->getEmail(), false, false))
            return array('error' => 'invalid_option', 'message' => 'The ticket owner email address is not valid.', 'http' => 400);

        return null;
    }

    // ---- options ----------------------------------------------------------

    function buildOptions($ticket, $staff, $block) {
        global $cfg;

        $dept = $ticket->getDept();
        $status = $ticket->getStatus();
        $assignee = $this->assigneePayload($ticket);
        $out = array(
            'can_reply' => $block ? false : true,
            'ticket' => array(
                'number' => (string) $ticket->getNumber(),
                'subject' => (string) $ticket->getSubject(),
                'status' => array(
                    'id' => (int) $ticket->getStatusId(),
                    'name' => ($status ? (string) $status->getName() : ''),
                    'state' => (string) $ticket->getState(),
                ),
                'department' => array(
                    'id' => $dept ? (int) $dept->getId() : 0,
                    'name' => $dept ? (string) $dept->getName() : '',
                ),
                'assignee' => $assignee,
                'lock' => $this->lockPayload($ticket),
            ),
            'from_emails' => array(),
            'reply_to' => $this->replyToPayload($ticket),
            'signatures' => array('available' => array(), 'default' => 'none'),
            'statuses' => array('options' => array(), 'default' => (int) $ticket->getStatusId()),
            'canned_responses' => array(),
            'attachments' => $this->attachmentLimits(),
            'auto_claim' => $this->wouldAutoClaim($ticket, $ticket->getStatus()),
        );
        if ($block)
            $out['reason'] = $block['error'];

        // Choice lists are what the reply form would render. A staff member
        // who cannot open that form gets the ticket facts and empty lists.
        if ($block && in_array($block['error'], array('no_access', 'no_reply_permission'), true))
            return $out;

        $out['from_emails'] = $this->fromChoices($ticket, $staff);
        $out['signatures'] = $this->signatureChoices($ticket, $staff);
        $out['statuses'] = $this->statusChoices($ticket, $staff);
        if ($cfg->isCannedResponseEnabled()) {
            // ticket-view.inc.php:1022 — Canned::responsesByDeptId, which
            // itself filters on $thisstaff->getDepts() (class.canned.php).
            foreach (Canned::responsesByDeptId($ticket->getDeptId()) as $id => $title) {
                $out['canned_responses'][] = array('id' => (int) $id, 'title' => (string) $title);
            }
        }
        return $out;
    }

    /**
     * From dropdown. include/staff/ticket-view.inc.php lines 837-859.
     */
    function fromChoices($ticket, $staff) {
        global $cfg;

        $dept = $ticket->getDept();
        $choices = array();
        $defaultId = 0;
        if ($dept && ($e = $dept->getEmail())) {
            $defaultId = (int) $e->getId();
            $choices[] = array(
                'id' => $defaultId,
                'address' => (string) $e->getAddress(),
                'default' => true,
            );
        }
        $staffDepts = $staff->getDepts();
        if (!is_array($staffDepts))
            $staffDepts = array();
        if (in_array($cfg->getDefaultDeptId(), $staffDepts))
            $staffDepts[] = 0;
        if (($emails = Email::getAddresses(array(
                    'smtp' => true,
                    'depts' => $staffDepts,
                ), false)) && count($emails)) {
            foreach ($emails as $e) {
                if ($defaultId && (int) $e->getId() == $defaultId)
                    continue;
                $choices[] = array(
                    'id' => (int) $e->getId(),
                    'address' => (string) $e->getAddress(),
                    'default' => false,
                );
            }
        }
        return $choices;
    }

    /**
     * Signature radios. include/staff/ticket-view.inc.php lines 1035-1087.
     * "none" is always present. "mine" only when the agent has a signature.
     * "dept" only when Dept::canAppendSignature() (public, nonempty).
     * The default is the agent's default when that radio exists, else none.
     * The template marks "none" checked and also marks the default; browsers
     * keep the later checked radio.
     */
    function signatureChoices($ticket, $staff) {
        $dept = $ticket->getDept();
        $available = array('none');
        if ($staff->getSignature())
            $available[] = 'mine';
        if ($dept && $dept->canAppendSignature())
            $available[] = 'dept';
        $prefer = (string) $staff->getDefaultSignatureType();
        $default = in_array($prefer, $available, true) ? $prefer : 'none';
        return array('available' => $available, 'default' => $default);
    }

    /**
     * Reply status dropdown. include/staff/ticket-view.inc.php lines 1096-1121.
     */
    function statusChoices($ticket, $staff) {
        $role = $ticket->getRole($staff);
        $states = array('open');
        $outstanding = false;
        if ($role && $role->hasPerm(Ticket::PERM_CLOSE)
                && is_string($ticket->isCloseable()))
            $outstanding = true;
        if ($role && $role->hasPerm(Ticket::PERM_CLOSE) && !$outstanding)
            $states[] = 'closed';

        $options = array();
        foreach (TicketStatusList::getStatuses(array('states' => $states)) as $s) {
            if (!$s->isEnabled())
                continue;
            $options[] = array(
                'id' => (int) $s->getId(),
                'name' => (string) $s->getName(),
                'state' => (string) $s->getState(),
            );
        }
        return array(
            'options' => $options,
            'default' => (int) $ticket->getStatusId(),
        );
    }

    /**
     * Recipients block. include/staff/ticket-view.inc.php lines 976-997
     * (reply-to) and 947-962 (collaborator checkboxes).
     */
    function replyToPayload($ticket) {
        $owner = $ticket->getOwner();
        $collabs = array();
        if ($list = $ticket->getCollaborators()) {
            foreach ($list as $c) {
                $collabs[] = array(
                    'user_id' => (int) $c->getUserId(),
                    'name' => (string) $c->getName(),
                    'email' => (string) $c->getEmail(),
                    'active' => $c->isActive() ? true : false,
                );
            }
        }
        return array(
            'options' => array('all', 'user', 'none'),
            'default' => 'all',
            'owner' => array(
                'user_id' => (int) $ticket->getOwnerId(),
                'name' => $owner ? (string) $owner->getName() : '',
                'email' => (string) $ticket->getEmail(),
            ),
            'collaborators' => $collabs,
        );
    }

    /**
     * Attachment limits from the same field the reply form builds.
     * scp/tickets.php lines 147-151.
     */
    function attachmentLimits() {
        global $cfg;

        $field = new FileUploadField(array(
            'id' => 'attach',
            'name' => 'attach:response',
            'configuration' => array('extensions' => ''),
        ));
        $config = $field->getConfiguration();
        $max = isset($config['max']) ? $config['max'] : false;
        $maxCount = (is_numeric($max) && (int) $max > 0) ? (int) $max : null;
        $types = array();
        if (!empty($config['__extensions']))
            $types = array_values($config['__extensions']);
        if (!$types)
            $types = array('.*');
        return array(
            'allowed' => true,
            'max_size' => (int) $config['size'],
            'max_count' => $maxCount,
            'allowed_types' => $types,
            'system_allow_attachments' => $cfg->allowAttachments() ? true : false,
        );
    }

    function lockPayload($ticket) {
        $lock = $ticket->getLock();
        if (!$lock)
            return array('held' => false);
        return array(
            'held' => true,
            'staff_id' => (int) $lock->getStaffId(),
            'staff_name' => (string) $lock->getStaffName(),
            'expires' => gmdate('c', (int) $lock->getExpireTime()),
        );
    }

    function assigneePayload($ticket) {
        $staff = $ticket->getStaff();
        $team = $ticket->getTeam();
        return array(
            'staff_id' => $ticket->getStaffId() ? (int) $ticket->getStaffId() : null,
            'staff_name' => $staff ? (string) $staff->getName() : null,
            'team_id' => $ticket->getTeamId() ? (int) $ticket->getTeamId() : null,
            'team_name' => $team ? (string) $team->getName() : null,
        );
    }

    /**
     * Same predicate Ticket::postReply uses after a status change.
     * include/class.ticket.php lines 3376-3381. Closing makes the ticket
     * not open, so claim does not run. Reopen assignment is setStatus's
     * own behavior (lines 1538-1554) and is reflected here.
     */
    function wouldAutoClaim($ticket, $statusAfter) {
        global $cfg, $thisstaff;

        if (!$statusAfter)
            return false;
        $dept = $ticket->getDept();
        $enabled = $cfg->autoClaimTickets() && $dept && !$dept->disableAutoClaim();
        $open = ($statusAfter->getState() == 'open');
        $staffId = (int) $ticket->getStaffId();

        if ((int) $statusAfter->getId() != (int) $ticket->getStatusId()
                && $statusAfter->getState() == 'open'
                && $ticket->isClosed()
                && $ticket->isReopenable()) {
            $prior = $ticket->getStaff() ?: $ticket->getLastRespondent();
            $autoassign = $dept && !$dept->disableReopenAutoAssign();
            if ($autoassign && $prior && $prior->isAvailable() && $prior->canAccessDept($dept))
                $staffId = (int) $prior->getId();
            else
                $staffId = 0;
        }

        return (bool) ($enabled && $thisstaff && $open && !$staffId);
    }

    // ---- canned -----------------------------------------------------------

    /**
     * include/ajax.tickets.php cannedResponse(), lines 520-556.
     * Also requires the reply form, which is where the dropdown lives.
     */
    function expandCanned($ticket, $staff, array $in) {
        global $cfg;

        if (!$cfg->isCannedResponseEnabled())
            $this->fail('invalid_option', 'Canned responses are disabled.', 400);

        $cid = isset($in['canned_id']) ? $in['canned_id'] : null;
        if ($cid === null || $cid === '')
            $this->fail('invalid_option', 'canned_id is required.', 400);

        if (!is_numeric($cid)) {
            $cid = (string) $cid;
            if ($cid !== 'original' && $cid !== 'lastmessage')
                $this->fail('invalid_option', 'Unknown canned response.', 400);
            if (!($response = $ticket->getThread()->getVar($cid)))
                $this->fail('invalid_option', 'That thread variable is empty.', 400);
            $response = '<br/><blockquote>' . $response->asVar() . '</blockquote><br/>';
            if (!$cfg->isRichTextEnabled())
                $response = Format::html2text($response, 90);
            else
                $response = Format::viewableImages($response);
            return array('response' => $response, 'attachments' => array());
        }

        $allowed = Canned::responsesByDeptId($ticket->getDeptId());
        if (!isset($allowed[(int) $cid]))
            $this->fail('invalid_option', 'That canned response is not available to this staff member.', 400);

        $canned = Canned::lookup((int) $cid);
        if (!$canned || !$canned->isEnabled())
            $this->fail('invalid_option', 'That canned response is not available.', 400);

        $format = $cfg->isRichTextEnabled() ? 'json' : 'json.plain';
        $varReplacer = function (&$var) use ($ticket) {
            return $ticket->replaceVars($var, array('recipient' => $ticket->getOwner()));
        };
        $json = $canned->getFormattedResponse($format, $varReplacer);
        $decoded = json_decode($json, true);
        if (!is_array($decoded))
            $this->fail('invalid_option', 'Unable to expand the canned response.', 500);

        $names = array();
        if (!empty($decoded['files']) && is_array($decoded['files'])) {
            foreach ($decoded['files'] as $file) {
                if (isset($file['name']))
                    $names[] = (string) $file['name'];
            }
        }
        return array(
            'response' => isset($decoded['response']) ? $decoded['response'] : '',
            'attachments' => $names,
        );
    }

    // ---- resolve a reply --------------------------------------------------

    function resolveReply($ticket, $staff, array $in) {
        if (!isset($in['response_html']) || !is_string($in['response_html']))
            $this->fail('empty_response', 'response_html is required.', 400);
        $response = $in['response_html'];
        if (trim($response) === '')
            $this->fail('empty_response', 'response_html is required.', 400);

        // scp/tickets.php:175 cleans before the empty check.
        $clean = ThreadEntryBody::clean($response);
        if (!$clean)
            $this->fail('empty_response', 'Response required.', 400);

        $sigs = $this->signatureChoices($ticket, $staff);
        if (array_key_exists('signature', $in) && $in['signature'] !== null && $in['signature'] !== '')
            $signature = (string) $in['signature'];
        else
            $signature = $sigs['default'];
        if (!in_array($signature, $sigs['available'], true))
            $this->fail('invalid_option', 'That signature is not one of the choices.', 400);

        $replyTo = array_key_exists('reply_to', $in) && $in['reply_to'] !== null && $in['reply_to'] !== ''
            ? (string) $in['reply_to'] : 'all';
        if (!in_array($replyTo, array('all', 'user', 'none'), true))
            $this->fail('invalid_option', 'reply_to must be all, user, or none.', 400);

        $ccOmitted = !array_key_exists('cc_user_ids', $in) || $in['cc_user_ids'] === null;
        $ccs = $this->collaboratorIds($ticket, $in, $ccOmitted);

        $fromChoices = $this->fromChoices($ticket, $staff);
        $fromId = 0;
        foreach ($fromChoices as $choice) {
            if (!empty($choice['default']))
                $fromId = (int) $choice['id'];
        }
        if (array_key_exists('from_email_id', $in) && $in['from_email_id'] !== null && $in['from_email_id'] !== '') {
            $fromId = (int) $in['from_email_id'];
            $ok = false;
            foreach ($fromChoices as $choice) {
                if ((int) $choice['id'] === $fromId)
                    $ok = true;
            }
            if (!$ok)
                $this->fail('invalid_option', 'That From address is not one of the choices.', 400);
        }

        $statusChoices = $this->statusChoices($ticket, $staff);
        $statusId = (int) $ticket->getStatusId();
        if (array_key_exists('reply_status_id', $in) && $in['reply_status_id'] !== null && $in['reply_status_id'] !== '') {
            $statusId = (int) $in['reply_status_id'];
            if ($statusId !== (int) $ticket->getStatusId()) {
                $ok = false;
                foreach ($statusChoices['options'] as $choice) {
                    if ((int) $choice['id'] === $statusId)
                        $ok = true;
                }
                if (!$ok)
                    $this->fail('invalid_option', 'That status is not one of the reply choices.', 400);
            }
        }

        $attachments = $this->normalizeAttachments(isset($in['attachments']) ? $in['attachments'] : array());

        return array(
            'response_html' => $response,
            'clean' => $clean,
            'signature' => $signature,
            'reply_to' => $replyTo,
            'cc_omitted' => $ccOmitted,
            'ccs' => $ccs,
            'from_email_id' => $fromId,
            'reply_status_id' => $statusId,
            'attachments' => $attachments,
        );
    }

    /**
     * Omitted cc_user_ids becomes the currently active collaborators so a
     * caller cannot accidentally deactivate them. scp/tickets.php lines
     * 207-216 deactivate every active collaborator missing from ccs[].
     * Ids must already be collaborators: the reply form's select only
     * lists those (ticket-view.inc.php lines 952-961).
     */
    function collaboratorIds($ticket, array $in, $omitted) {
        $known = array();
        $active = array();
        if ($list = $ticket->getCollaborators()) {
            foreach ($list as $c) {
                $id = (int) $c->getUserId();
                $known[$id] = true;
                if ($c->isActive())
                    $active[] = (string) $id;
            }
        }
        if ($omitted)
            return $active;

        if (!is_array($in['cc_user_ids']))
            $this->fail('invalid_option', 'cc_user_ids must be a list.', 400);
        $ccs = array();
        foreach ($in['cc_user_ids'] as $id) {
            if (!is_numeric($id) || !isset($known[(int) $id]))
                $this->fail('invalid_option', 'cc_user_ids contains a user who is not a collaborator.', 400);
            $ccs[] = (string) (int) $id;
        }
        return $ccs;
    }

    function normalizeAttachments($attachments) {
        if ($attachments === null || $attachments === array())
            return array();
        if (!is_array($attachments))
            $this->fail('invalid_option', 'attachments must be a list.', 400);

        $limits = $this->attachmentLimits();
        if (!$limits['allowed'])
            $this->fail('invalid_option', 'Attachments are not allowed.', 400);
        if ($limits['max_count'] !== null && count($attachments) > $limits['max_count'])
            $this->fail('invalid_option', 'Too many attachments.', 400);

        $field = new FileUploadField(array(
            'id' => 'attach',
            'name' => 'attach:response',
            'configuration' => array('extensions' => ''),
        ));
        $out = array();
        foreach ($attachments as $item) {
            if (!is_array($item) || empty($item['name']) || !isset($item['base64']))
                $this->fail('invalid_option', 'Each attachment needs name and base64.', 400);
            $name = (string) $item['name'];
            if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false)
                $this->fail('invalid_option', 'Attachment name is not valid.', 400);
            $mime = isset($item['mime']) && $item['mime'] !== ''
                ? (string) $item['mime'] : 'application/octet-stream';
            $raw = base64_decode((string) $item['base64'], true);
            if ($raw === false)
                $this->fail('invalid_option', 'Attachment is not valid base64.', 400);
            if (strlen($raw) > $limits['max_size'])
                $this->fail('invalid_option', 'Attachment is too large.', 400);
            if (!$field->isValidFileType($name, $mime))
                $this->fail('invalid_option', 'Attachment type is not allowed.', 400);
            $out[] = array(
                'name' => $name,
                'type' => $mime,
                'data' => $raw,
                'size' => strlen($raw),
                'sha256' => hash('sha256', $raw),
            );
        }
        return $out;
    }

    // ---- preview ----------------------------------------------------------

    function buildPreview($ticket, $staff, array $resolved) {
        $facts = $this->replyFacts($ticket, $staff, $resolved);
        return array(
            'to' => $facts['to'],
            'cc' => $facts['cc'],
            'from' => array(
                'email_id' => $facts['email_id'],
                // Email::getAddress() is the dropdown label "Name <email>"
                // (class.email.php:116). The mailer sends getEmail() and the
                // display name separately (class.mailer.php setFromAddress).
                'address' => $facts['from_address'],
                'name' => $facts['from_name'],
            ),
            'signature' => $facts['signature'],
            'status_after' => $facts['status_after'],
            'auto_claim' => $facts['auto_claim'],
            'subject' => $facts['subject'],
            'body' => $facts['body'],
            'preview_hash' => $this->makePreviewHash($ticket, $staff, $resolved, $facts),
        );
    }

    /**
     * Everything preview shows and everything the preview hash binds,
     * from one pass. Recipients are the proposed collaborator set, not
     * the flags currently stored. The rendered subject and body use an
     * in-memory copy of the post-reply status and assignee.
     */
    function replyFacts($ticket, $staff, array $resolved) {
        global $cfg;

        // Refuse before rendering. A clock value (closed at NOW(), a due
        // date that may tip during the preview window, a reopen SLA) must
        // not be shown as if it were exact.
        $unsupported = $this->unsupportedPreviewVars($ticket, $resolved);
        if ($unsupported) {
            $this->fail('preview_unsupported',
                'The department reply template uses a ticket value this preview cannot project exactly.',
                409, array('variables' => $unsupported));
        }

        $dept = $ticket->getDept();
        $email = null;
        if ($resolved['from_email_id'])
            $email = Email::lookup($resolved['from_email_id']);
        if (!$email && $dept)
            $email = $dept->getEmail();

        $signature = '';
        if ($staff && $resolved['signature'] == 'mine')
            $signature = (string) $staff->getSignature();
        elseif ($resolved['signature'] == 'dept' && $dept && $dept->isPublic())
            $signature = (string) $dept->getSignature();

        // include/class.ticket.php postReply lines 3407-3422.
        $fromName = '';
        if ($staff && ($type = $staff->getReplyFromNameType())) {
            switch ($type) {
            case 'mine':
                if (!$cfg->hideStaffName())
                    $fromName = (string) $staff->getName();
                break;
            case 'dept':
                if ($dept && $dept->isPublic())
                    $fromName = (string) $dept->getName();
                break;
            case 'email':
            default:
                if ($email)
                    $fromName = (string) $email->getName();
                break;
            }
        }
        if ($fromName === '' && $email)
            $fromName = (string) $email->getName();

        $status = TicketStatus::lookup($resolved['reply_status_id']);
        if (!$status)
            $status = $ticket->getStatus();

        // Captured before the projection. These are the live ticket, not
        // the status the reply is about to apply.
        $recipients = $this->proposedRecipients($ticket, $resolved);
        $live = array(
            'last_entry_id' => $this->lastEntryId($ticket),
            'status_id' => (int) $ticket->getStatusId(),
            'owner_id' => (int) $ticket->getOwnerId(),
            'auto_claim' => $this->wouldAutoClaim($ticket, $status),
        );
        $rendered = $this->renderReply($ticket, $staff, $dept, $resolved, $signature, $recipients['list']);

        return array(
            'to' => $recipients['to'],
            'cc' => $recipients['cc'],
            'email_id' => $email ? (int) $email->getId() : 0,
            'from_address' => $email ? (string) $email->getEmail() : '',
            'from_name' => $fromName,
            'signature' => $signature,
            'status_after' => array(
                'id' => $status ? (int) $status->getId() : 0,
                'name' => $status ? (string) $status->getName() : '',
                'state' => $status ? (string) $status->getState() : '',
            ),
            'auto_claim' => $live['auto_claim'],
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'last_entry_id' => $live['last_entry_id'],
            'status_id' => $live['status_id'],
            'owner_id' => $live['owner_id'],
        );
    }

    /**
     * Recipients after applyCollaborators, without writing that change.
     * An explicit empty cc_user_ids deactivates every collaborator, so
     * the Cc list is empty. A selected collaborator is included even
     * when the stored row is inactive: send activates it first.
     * getRecipients() cannot express either case before the write.
     */
    function proposedRecipients($ticket, array $resolved) {
        $to = array();
        $cc = array();
        $list = new MailingList();
        if ($resolved['reply_to'] !== 'none') {
            $owner = $ticket->getOwner();
            if ($owner && ($resolved['reply_to'] === 'user' || $resolved['reply_to'] === 'all'))
                $list->addTo($owner);
            if ($resolved['reply_to'] === 'all') {
                $wanted = array();
                foreach ($resolved['ccs'] as $id)
                    $wanted[(int) $id] = true;
                if ($wanted && ($collabs = $ticket->getCollaborators())) {
                    foreach ($collabs as $c) {
                        if (isset($wanted[(int) $c->getUserId()]))
                            $list->addCc($c);
                    }
                }
            }
            foreach ($list->getEmailAddresses() as $type => $rows) {
                if (!is_array($rows))
                    continue;
                foreach ($rows as $addr) {
                    if ($type == 'cc')
                        $cc[] = (string) $addr;
                    else
                        $to[] = (string) $addr;
                }
            }
        }
        return array('to' => $to, 'cc' => $cc, 'list' => $list);
    }

    /**
     * Department ticket.reply template, osTicket variable replacement.
     * include/class.ticket.php postReply lines 3425-3448. The response
     * object is a stand-in whose asVar() is ThreadEntry::asVar(): the
     * cleaned body displayed for email. Nothing is written.
     */
    function renderReply($ticket, $staff, $dept, array $resolved, $signature, $recipients) {
        global $cfg;

        $subject = '';
        $body = '';
        if (!$dept || !($tpl = $dept->getTemplate()) || !($msg = $tpl->getReplyMsgTemplate()))
            return array('subject' => $subject, 'body' => $body);

        if ($cfg->isRichTextEnabled())
            $entryBody = new HtmlThreadEntryBody($resolved['clean']);
        else
            $entryBody = new TextThreadEntryBody($resolved['clean']);
        $stored = Format::strip_emoticons($entryBody->getClean());
        if (!$stored)
            $stored = '-';
        $stored = Format::stripExternalImages($stored);

        // postReply renders the template after setStatus and auto-claim.
        // Project those onto a clone. Writing them onto the live ticket
        // makes the real setStatus see "already closed" and skip the save.
        $projected = clone $ticket;
        $this->projectPostReply($projected, $staff, $resolved);
        $variables = array(
            'response' => new WgsReplyPreviewEntry($stored, $entryBody->getType()),
            'signature' => $signature,
            'staff' => $staff,
            'poster' => $staff,
            'recipient' => $projected->getOwner(),
        );
        if ($recipients instanceof MailingList
                && count($recipients) == 1
                && $ticket->getNumCollaborators()
                && ($contact = $recipients->offsetGet(0)->getContact())
                && ($contact instanceof TicketOwner))
            $variables['recipient.ticket_link'] = $contact->getTicketLink();

        $rendered = $projected->replaceVars($msg->asArray(), $variables);
        return array(
            'subject' => isset($rendered['subj']) ? (string) $rendered['subj'] : '',
            'body' => isset($rendered['body']) ? (string) $rendered['body'] : '',
        );
    }

    /**
     * In-memory stand-in for setStatus plus the auto-claim inside postReply.
     * Nothing here saves. The clone is never written.
     *
     * Close assigns the acting agent and clears the same overdue and due
     * dates clearOverdue(false) clears (class.ticket.php setStatus). The
     * closed and last-update timestamps are NOW() and are not copied; a
     * template that prints them is refused instead. Reopen's new SLA is
     * also NOW() and is refused rather than invented. Claim runs only when
     * the result is open and unassigned. onResponse sets isanswered before
     * the template is rendered.
     */
    function projectPostReply($ticket, $staff, array $resolved) {
        $status = TicketStatus::lookup((int) $resolved['reply_status_id']);
        if ($status) {
            // wouldAutoClaim reads the ticket before this projection changes it.
            $claim = $this->wouldAutoClaim($ticket, $status);
            $changing = (int) $status->getId() !== (int) $ticket->getStatusId();
            if ($changing && $status->getState() == 'closed' && $ticket->isCloseable() === true) {
                $ticket->status = $status;
                $ticket->staff = $staff;
                if ($ticket->isOverdue())
                    $ticket->isoverdue = 0;
                if ($ticket->getDueDate() && Misc::db2gmtime($ticket->getDueDate()) <= Misc::gmtime())
                    $ticket->duedate = null;
                if ($ticket->getSLADueDate() && Misc::db2gmtime($ticket->getSLADueDate()) <= Misc::gmtime())
                    $ticket->est_duedate = null;
            }
            elseif ($changing && $status->getState() == 'open' && $ticket->isClosed() && $ticket->isReopenable()) {
                $dept = $ticket->getDept();
                $prior = $ticket->getStaff() ?: $ticket->getLastRespondent();
                $autoassign = ($dept && !$dept->disableReopenAutoAssign());
                if ($autoassign && $prior && $prior->isAvailable() && $prior->canAccessDept($dept))
                    $ticket->staff = $prior;
                else
                    $ticket->staff = null;
                $ticket->status = $status;
            }
            elseif ($changing) {
                $ticket->status = $status;
            }
            if ($claim)
                $ticket->staff = $staff;
        }
        $ticket->isanswered = 1;
    }

    /**
     * ticket.* names in the department ticket.reply subject and body.
     * Lowercased, unique, sorted. %{ticket.thread.lastmessage} is kept
     * whole so a thread variable is not treated as the ticket root alone.
     */
    function templateTicketVars($ticket) {
        $dept = $ticket->getDept();
        if (!$dept || !($tpl = $dept->getTemplate()) || !($msg = $tpl->getReplyMsgTemplate()))
            return array();
        $blob = (string) $msg->getSubject() . "\n" . (string) $msg->getBody();
        if (!preg_match_all('/%\{(ticket\.[A-Za-z0-9_.]+)\}/', $blob, $found))
            return array();
        $names = array();
        foreach ($found[1] as $name)
            $names[strtolower($name)] = true;
        $out = array_keys($names);
        sort($out);
        return $out;
    }

    function ticketVarRoot($name) {
        $name = strtolower((string) $name);
        if (strpos($name, 'ticket.') === 0)
            $name = substr($name, 7);
        $dot = strpos($name, '.');
        if ($dot === false)
            return $name;
        return substr($name, 0, $dot);
    }

    /**
     * Variables whose post-reply value this preview cannot project.
     * Listed as they appear in the template, for example ticket.due_date
     * or ticket.thread.complete.
     *
     * ticket.thread.lastmessage is Thread::getLastMessage(), type M only
     * (class.thread.php). addResponse does not change it. ticket.thread.original
     * is the flagged original message, also type M. %{ticket.thread} and
     * %{ticket.thread.complete} render ThreadEntries, which lists messages
     * and responses (thread-export.tmpl.php), so the mail includes the new
     * response and a preview of the old thread would not match.
     */
    function unsupportedPreviewVars($ticket, array $resolved) {
        $status = TicketStatus::lookup((int) $resolved['reply_status_id']);
        $changing = $status && (int) $status->getId() !== (int) $ticket->getStatusId();
        $closing = $changing && $status->getState() == 'closed' && $ticket->isCloseable() === true;
        $reopening = $changing && $status->getState() == 'open'
            && $ticket->isClosed() && $ticket->isReopenable();
        $unstableDue = $closing && $this->dueDateUnstable($ticket);
        $bad = array();
        foreach ($this->templateTicketVars($ticket) as $name) {
            $root = $this->ticketVarRoot($name);
            if ($this->threadExpansionUnstable($name))
                $bad[$name] = true;
            if ($closing && ($root === 'close_date' || $root === 'last_update' || $root === 'lastupdate'))
                $bad[$name] = true;
            if ($closing && $root === 'due_date' && $unstableDue)
                $bad[$name] = true;
            if ($reopening && ($root === 'last_update' || $root === 'lastupdate'
                    || $root === 'due_date' || $root === 'reopened'))
                $bad[$name] = true;
        }
        $out = array_keys($bad);
        sort($out);
        return $out;
    }

    /**
     * True when this ticket.thread path includes the response postReply is
     * about to add. lastmessage, last_message, and original stay false:
     * each one is a customer message that already exists.
     */
    function threadExpansionUnstable($name) {
        $name = strtolower((string) $name);
        if (strpos($name, 'ticket.thread') !== 0)
            return false;
        $path = substr($name, strlen('ticket.thread'));
        if ($path === '')
            return true;
        if ($path[0] !== '.')
            return false;
        $path = substr($path, 1);
        foreach (array('lastmessage', 'last_message', 'original') as $stable) {
            if ($path === $stable || strpos($path, $stable . '.') === 0)
                return false;
        }
        return true;
    }

    /**
     * clearOverdue decides "in the past" at save time. A due instant inside
     * the preview window can fall on the other side of that line by the
     * time send runs.
     */
    function dueDateUnstable($ticket) {
        $now = Misc::gmtime();
        foreach (array($ticket->getDueDate(), $ticket->getSLADueDate()) as $stamp) {
            if (!$stamp)
                continue;
            $when = Misc::db2gmtime($stamp);
            if (abs($when - $now) <= self::PREVIEW_TTL)
                return true;
        }
        return false;
    }

    function previewMaterial($ticket, $staff, array $resolved, $iat, array $facts) {
        $to = $facts['to'];
        $cc = $facts['cc'];
        sort($to);
        sort($cc);
        $files = array();
        foreach ($resolved['attachments'] as $file)
            $files[] = $file['name'] . ':' . $file['type'] . ':' . $file['sha256'];
        sort($files);
        $ccs = array();
        foreach ($resolved['ccs'] as $id)
            $ccs[] = (int) $id;
        sort($ccs);
        $ccText = array();
        foreach ($ccs as $id)
            $ccText[] = (string) $id;
        // Length-prefixed fields. A newline inside the rendered body must
        // not be readable as the next field. The raw reply is bound as
        // well as the rendering, so a template that omits %{response} still
        // changes the hash when the body changes.
        $lines = array(
            'v3',
            $this->materialField((int) $staff->getId()),
            $this->materialField((int) $ticket->getId()),
            $this->materialField((int) $facts['last_entry_id']),
            $this->materialField((int) $facts['status_id']),
            $this->materialField((int) $facts['owner_id']),
            $this->materialField(implode(',', $to)),
            $this->materialField(implode(',', $cc)),
            $this->materialField((int) $facts['email_id']),
            $this->materialField($facts['from_address']),
            $this->materialField($facts['from_name']),
            $this->materialField($facts['signature']),
            $this->materialField($facts['subject']),
            $this->materialField($facts['body']),
            $this->materialField($resolved['reply_to']),
            $this->materialField((int) $resolved['reply_status_id']),
            $this->materialField(implode(',', $ccText)),
            $this->materialField(implode(',', $files)),
            $this->materialField(hash('sha256', (string) $resolved['clean'])),
            $this->materialField(hash('sha256', (string) $resolved['response_html'])),
            $this->materialField($resolved['signature']),
            $this->materialField($resolved['cc_omitted'] ? '1' : '0'),
            $this->materialField((int) $resolved['from_email_id']),
            $this->materialField((int) $iat),
        );
        return implode("\n", $lines);
    }

    function materialField($value) {
        $value = (string) $value;
        return strlen($value) . ':' . $value;
    }

    function makePreviewHash($ticket, $staff, array $resolved, ?array $facts = null) {
        if ($facts === null)
            $facts = $this->replyFacts($ticket, $staff, $resolved);
        $secret = (string) $this->config()->get('shared_secret');
        $iat = time();
        $mac = hash_hmac('sha256', $this->previewMaterial($ticket, $staff, $resolved, $iat, $facts), $secret);
        return $iat . '.' . $mac;
    }

    function assertPreviewHash($ticket, $staff, array $resolved, $hash) {
        $hash = (string) $hash;
        if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $hash, $m))
            $this->fail('stale_preview', 'preview_hash is missing or not valid.', 409);
        $iat = (int) $m[1];
        if ($iat > time() + 60 || (time() - $iat) > self::PREVIEW_TTL)
            $this->fail('stale_preview', 'preview_hash has expired.', 409);
        $secret = (string) $this->config()->get('shared_secret');
        $facts = $this->replyFacts($ticket, $staff, $resolved);
        $expect = hash_hmac('sha256', $this->previewMaterial($ticket, $staff, $resolved, $iat, $facts), $secret);
        if (!hash_equals($expect, $m[2]))
            $this->fail('stale_preview', 'The ticket changed since preview, or the reply fields do not match.', 409);
    }

    // ---- send mechanics ---------------------------------------------------

    /**
     * ajax.tickets.php acquireLock() lines 207-219, then Ticket::acquireLock.
     * Lock mode comes from config. A disabled lock is not acquired.
     */
    function acquireLock($ticket, $staff) {
        global $cfg;

        if (!$cfg->isTicketLockEnabled())
            return null;

        $lock = $ticket->getLock();
        if ($lock && (int) $lock->getStaffId() != (int) $staff->getId()) {
            $this->fail('locked_by_other',
                sprintf('Ticket is locked by %s.', (string) $lock->getStaffName()), 409);
        }
        $lock = $ticket->acquireLock($staff->getId());
        if (!$lock)
            $this->fail('locked_by_other', 'Unable to acquire the ticket lock.', 409);
        return $lock;
    }

    /**
     * scp/tickets.php lines 200-218. ccs are user ids, as strings, the way
     * the form posts them. in_array is intentionally loose, matching core.
     */
    function applyCollaborators($ticket, array $ccs) {
        $_errors = array();
        if ($ccs)
            $ticket->addCollaborators($ccs, array(), $_errors);
        if ($collabs = $ticket->getCollaborators()) {
            foreach ($collabs as $collaborator) {
                $cid = $collaborator->getUserId();
                if (!$collaborator->isActive() && ($ccs && in_array($cid, $ccs)))
                    $collaborator->setFlag(Collaborator::FLAG_ACTIVE, true);
                elseif ($collaborator->isActive() && (!$ccs || !in_array($cid, $ccs)))
                    $collaborator->setFlag(Collaborator::FLAG_ACTIVE, false);
                $collaborator->save();
            }
        }
    }

    function replyVars(array $resolved) {
        $vars = array(
            'response' => $resolved['clean'],
            'signature' => $resolved['signature'],
            'reply-to' => $resolved['reply_to'],
            'ccs' => $resolved['ccs'],
            'from_email_id' => $resolved['from_email_id'],
            'reply_status_id' => $resolved['reply_status_id'],
        );
        if ($resolved['attachments']) {
            $files = array();
            foreach ($resolved['attachments'] as $file) {
                $stored = AttachmentFile::create($file);
                if (!$stored)
                    $this->fail('invalid_option', 'Unable to store an attachment.', 400);
                $files[] = array('id' => $stored->getId(), 'name' => $file['name']);
            }
            // ThreadEntry::create reads $vars['files'] (web uploads).
            $vars['files'] = $files;
        }
        return $vars;
    }

    /**
     * Spec rule 4. TextThreadEntryBody keeps the two lines and the blank
     * line as text. alert is false. No note_status_id, so postNote does
     * not call setStatus. No claim.
     */
    function postBridgeNote($ticket, $staff, $summary) {
        // note_fail returns before postNote. The constant is the only switch.
        if ($this->testFault() === 'note_fail')
            return false;
        $text = $this->noteText($staff, $summary);
        $errors = array();
        $this->connectCapture($ticket->getThreadId(), 'N');
        try {
            $note = $ticket->postNote(array(
                'note' => new TextThreadEntryBody($text),
            ), $errors, $staff, false);
        } finally {
            $this->disconnectCapture();
        }
        if ($note && $note->getId())
            $this->ledgerCaptureNote($this->requestId(), (int) $note->getId());
        return $note;
    }

    function noteText($staff, $summary) {
        return 'Reply sent using Claude by ' . (string) $staff->getName()
            . "\n\n" . $summary;
    }

    function sendPayload($ticket, $response, $note) {
        $recipients = array('to' => array(), 'cc' => array());
        if ($response && $response->recipients) {
            $decoded = json_decode($response->recipients, true);
            if (is_array($decoded)) {
                foreach (array('to', 'cc') as $type) {
                    if (empty($decoded[$type]) || !is_array($decoded[$type]))
                        continue;
                    foreach ($decoded[$type] as $addr)
                        $recipients[$type][] = (string) $addr;
                }
            }
        }
        $status = $ticket->getStatus();
        return array(
            'ok' => true,
            'response_entry_id' => $response ? (int) $response->getId() : 0,
            'note_entry_id' => $note ? (int) $note->getId() : 0,
            'status' => array(
                'id' => (int) $ticket->getStatusId(),
                'name' => $status ? (string) $status->getName() : '',
                'state' => (string) $ticket->getState(),
            ),
            'assignee' => $this->assigneePayload($ticket),
            'recipients' => $recipients,
            'mail' => 'handed_to_osticket',
        );
    }

    // ---- ledger -----------------------------------------------------------

    function ledgerTable() {
        return '`' . TABLE_PREFIX . 'wgs_reply_ledger`';
    }

    function ledgerFetch($requestId) {
        $sql = 'SELECT request_id, ticket_id, staff_id, status, result_json,'
            . ' response_entry_id, note_entry_id, native_returned, baseline_entry_id, executing_at FROM '
            . $this->ledgerTable() . ' WHERE request_id=' . db_input($requestId);
        if (!($res = db_query($sql)) || !db_num_rows($res))
            return null;
        return db_fetch_array($res);
    }

    function ledgerInsert($requestId, $ticketId, $staffId, $status) {
        $sql = 'INSERT INTO ' . $this->ledgerTable()
            . ' (request_id, ticket_id, staff_id, status, created)'
            . ' VALUES (' . db_input($requestId) . ',' . db_input((int) $ticketId)
            . ',' . db_input((int) $staffId) . ',' . db_input($status) . ', NOW())';
        if (!db_query($sql))
            $this->fail('already_done', 'This request id is already in use.', 409);
    }

    function ledgerMarkExecuting($requestId, $baseline) {
        $sql = 'UPDATE ' . $this->ledgerTable()
            . ' SET status=' . db_input('executing')
            . ', executing_at=' . db_input(time())
            . ', baseline_entry_id=' . db_input((int) $baseline)
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND status=' . db_input('reserved');
        db_query($sql);
        $row = $this->ledgerFetch($requestId);
        if (!$row || $row['status'] !== 'executing')
            $this->fail('already_done', 'This request id cannot be posted again.', 409);
    }

    function ledgerComplete($requestId, $responseId, $noteId, $json) {
        $sql = 'UPDATE ' . $this->ledgerTable() . ' SET status=' . db_input('completed')
            . ', response_entry_id=' . db_input((int) $responseId)
            . ', note_entry_id=' . db_input((int) $noteId)
            . ', result_json=' . db_input($json)
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND status=' . db_input('reply_recorded');
        db_query($sql);
    }

    /**
     * The response row's own id, written from threadentry.created before
     * postReply returns. A later entry is never adopted into this column.
     */
    function ledgerCaptureResponse($requestId, $responseId) {
        $sql = 'UPDATE ' . $this->ledgerTable()
            . ' SET response_entry_id=' . db_input((int) $responseId)
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND response_entry_id IS NULL';
        db_query($sql);
        $this->deleteResponseDraft($requestId);
    }

    function ledgerCaptureNote($requestId, $noteId) {
        $sql = 'UPDATE ' . $this->ledgerTable()
            . ' SET note_entry_id=' . db_input((int) $noteId)
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND note_entry_id IS NULL';
        db_query($sql);
    }

    /**
     * postReply returned this entry. native_returned stays 0 when the call
     * threw or returned nothing, even if the response row was captured.
     */
    function ledgerMarkNativeReturned($requestId, $responseId) {
        $sql = 'UPDATE ' . $this->ledgerTable()
            . ' SET status=' . db_input('reply_recorded')
            . ', response_entry_id=' . db_input((int) $responseId)
            . ', native_returned=1'
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND status IN (' . db_input('executing') . ',' . db_input('reply_recorded') . ')';
        db_query($sql);
        $this->deleteResponseDraft($requestId);
    }

    function ledgerMarkMailUnknown($requestId, $responseId) {
        $sql = 'UPDATE ' . $this->ledgerTable()
            . ' SET status=' . db_input('reply_recorded_mail_unknown')
            . ', response_entry_id=' . db_input((int) $responseId)
            . ', native_returned=0'
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND status IN (' . db_input('executing') . ',' . db_input('reply_recorded_mail_unknown') . ')';
        db_query($sql);
        $this->deleteResponseDraft($requestId);
    }

    function ledgerStoreResult($requestId, $status, $responseId, $noteId, $json, $fromStatus) {
        $noteSql = ($noteId === null)
            ? ''
            : ', note_entry_id=' . db_input((int) $noteId);
        $responseSql = ($responseId === null)
            ? ''
            : ', response_entry_id=' . db_input((int) $responseId);
        $sql = 'UPDATE ' . $this->ledgerTable()
            . ' SET status=' . db_input($status)
            . $responseSql
            . $noteSql
            . ', result_json=' . db_input($json)
            . ' WHERE request_id=' . db_input($requestId)
            . ' AND status=' . db_input($fromStatus);
        db_query($sql);
    }

    function deleteResponseDraft($requestId) {
        $row = $this->ledgerFetch($requestId);
        if ($row && $row['response_entry_id'] && $row['ticket_id'] && $row['staff_id'])
            Draft::deleteForNamespace(
                'ticket.response.' . (int) $row['ticket_id'],
                (int) $row['staff_id']);
    }

    /**
     * Completed is HTTP 200. A terminal failure is HTTP 500 and the same
     * bytes on every retry. Anything still missing a result is not finished.
     */
    function storedResult($row) {
        if (!$row || !isset($row['result_json']) || $row['result_json'] === '' || $row['result_json'] === null)
            return null;
        if ($row['status'] === 'completed')
            return array(200, $row['result_json']);
        if ($row['status'] === 'reply_state_unknown' || $row['status'] === 'reply_recorded_mail_unknown')
            return array(500, $row['result_json']);
        return null;
    }

    // ---- responses --------------------------------------------------------

    function encode($data) {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    function ok(array $data) {
        $data = array('ok' => true) + $data;
        $this->respond(200, $this->encode($data));
    }

    function fail($error, $message, $http, $extra=array()) {
        $body = array(
            'ok' => false,
            'error' => $error,
            'message' => $message,
        );
        if ($extra)
            $body = $body + $extra;
        $this->respond($http, $this->encode($body));
    }

    /**
     * Http::header_code_verbose() (class.http.php:18) has no 409 or 413.
     * Any code missing from that switch is sent as 500.
     * Locks are released here because every exit goes through this method.
     */
    function respond($http, $content) {
        $this->disconnectCapture();
        $this->releaseNative();
        $this->advisoryRelease();
        if ($http == 409 || $http == 413) {
            $verbose = ($http == 409) ? '409 Conflict' : '413 Payload Too Large';
            header('HTTP/1.1 ' . $verbose);
            header('Status: ' . $verbose);
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Length: ' . strlen($content));
            print $content;
            exit;
        }
        Http::response($http, $content, 'application/json');
    }

    // ---- locks and ledger resume -----------------------------------------

    function advisoryAcquire($ticketId) {
        $name = 'wgs_reply_' . (int) $ticketId;
        $sql = 'SELECT GET_LOCK(' . db_input($name) . ',' . (int) self::ADVISORY_WAIT . ')';
        $got = db_result(db_query($sql));
        if ($got != 1)
            $this->fail('busy', 'Another reply is already being posted on this ticket.', 409);
        $this->advisoryName = $name;
    }

    function advisoryRelease() {
        if (!$this->advisoryName)
            return;
        $name = $this->advisoryName;
        $this->advisoryName = null;
        db_query('SELECT RELEASE_LOCK(' . db_input($name) . ')');
    }

    function nativeLock($ticket, $staff) {
        $lock = $this->acquireLock($ticket, $staff);
        if ($lock) {
            $this->nativeLockTicket = $ticket;
            $this->nativeLockStaffId = (int) $staff->getId();
        }
    }

    function releaseNative() {
        $ticket = $this->nativeLockTicket;
        $staffId = $this->nativeLockStaffId;
        $this->nativeLockTicket = null;
        $this->nativeLockStaffId = 0;
        if ($ticket)
            $ticket->releaseLock($staffId);
    }

    /**
     * The ticket object was loaded before GET_LOCK. Another request may
     * have committed while this one waited. Overwrite the scalar columns
     * and drop the joined objects so the next read comes from the database.
     */
    function reloadTicket($ticket) {
        $rows = Ticket::objects()
            ->filter(array('ticket_id' => $ticket->getId()))
            ->values('status_id', 'staff_id', 'user_id', 'team_id', 'lock_id')
            ->limit(1)
            ->all();
        if ($rows) {
            foreach (array('status_id', 'staff_id', 'user_id', 'team_id', 'lock_id') as $field) {
                if (array_key_exists($field, $rows[0]))
                    $ticket->ht[$field] = $rows[0][$field];
            }
        }
        $ticket->dirty = array();
        unset($ticket->ht['status'], $ticket->ht['staff'], $ticket->ht['user'],
            $ticket->ht['team'], $ticket->ht['lock']);
        // owner is a declared property. unset() on it makes every later
        // write go through __set, which records a non-column `owner` in
        // $dirty. The next ticket UPDATE then fails. Null it instead so
        // getOwner() rebuilds a TicketOwner without dirtying the row.
        if (array_key_exists('owner', get_object_vars($ticket)))
            $ticket->owner = null;
        unset($ticket->ht['owner']);
        $this->dropOwnerDirty($ticket);
        $thread = $ticket->getThread();
        if ($thread) {
            $thread->_collaborators = null;
            unset($thread->ht['active_collaborators']);
        }
    }

    /**
     * getOwner() can land a TicketOwner in $dirty under the key `owner`.
     * That is not a column. Leave the cached owner in place so the next
     * getOwner() does not assign, and dirty, again.
     */
    function dropOwnerDirty($ticket) {
        unset($ticket->dirty['owner']);
    }

    /**
     * Thread::getEntry() calls ThreadEntry::lookup($id, $threadId). The
     * primary key is only id, so the extra argument becomes an empty
     * column and the query throws. Look the entry up by its id instead.
     */
    function responseEntry($ticket, $responseId) {
        $entry = ThreadEntry::lookup((int) $responseId);
        if (!$entry || (int) $entry->getThreadId() !== (int) $ticket->getThreadId())
            return null;
        return $entry;
    }

    function lastEntryId($ticket) {
        $row = ThreadEntry::objects()
            ->filter(array('thread_id' => $ticket->getThreadId()))
            ->order_by('-id')
            ->values_flat('id')
            ->first();
        return $row ? (int) $row[0] : 0;
    }

    /**
     * Listen only for this thread and this entry type. osTicket's Signal
     * class has no disconnect, and the list is static for the request, so
     * the subscriber is removed by reflection when the call returns.
     * The search plugin's own subscriber is left in place.
     */
    function connectCapture($threadId, $type) {
        $this->disconnectCapture();
        $this->capture = array(
            'thread_id' => (int) $threadId,
            'type' => (string) $type,
            'request_id' => $this->requestId(),
        );
        Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));
        $this->captureConnected = true;
    }

    function disconnectCapture() {
        $this->capture = null;
        if (!$this->captureConnected)
            return;
        $this->captureConnected = false;
        try {
            $prop = new ReflectionProperty('Signal', 'subscribers');
            $subs = $prop->getValue();
            if (!isset($subs['threadentry.created']) || !is_array($subs['threadentry.created']))
                return;
            $keep = array();
            foreach ($subs['threadentry.created'] as $sub) {
                $callable = (is_array($sub) && isset($sub[1])) ? $sub[1] : null;
                if (is_array($callable)
                        && isset($callable[0], $callable[1])
                        && $callable[0] === $this
                        && $callable[1] === 'onThreadEntryCreated')
                    continue;
                $keep[] = $sub;
            }
            $subs['threadentry.created'] = array_values($keep);
            // PHP 8.3 requires the object argument. 8.2 (the staging
            // runtime) still uses the one-argument static form.
            if (PHP_VERSION_ID >= 80300)
                $prop->setValue(null, $subs);
            else
                $prop->setValue($subs);
        } catch (Throwable $ex) {
            // capture is already null, so a leftover call does nothing.
        }
    }

    /**
     * Fired from ThreadEntry::create after the row is saved and before
     * postReply continues into setStatus or the mailer. The id is written
     * in its own UPDATE. after_reply throws from here so the entry stays
     * saved and the mail handoff does not finish.
     */
    function onThreadEntryCreated($entry, $data = null) {
        $capture = $this->capture;
        if (!$capture || !is_object($entry))
            return;
        if ((int) $entry->getThreadId() !== (int) $capture['thread_id'])
            return;
        if ((string) $entry->getType() !== (string) $capture['type'])
            return;
        $id = (int) $entry->getId();
        if ($id < 1)
            return;
        if ($capture['type'] === 'R') {
            $this->ledgerCaptureResponse($capture['request_id'], $id);
            if ($this->testFault() === 'after_reply')
                throw new WgsReplyBridgeFault('after_reply');
        } elseif ($capture['type'] === 'N') {
            $this->ledgerCaptureNote($capture['request_id'], $id);
        }
    }

    /**
     * postReply, correlated by the entry it creates. A returned entry id
     * from this call is that entry. Nothing is chosen by staff id, by
     * being the next row, or by note text.
     */
    function correlateReply($ticket, $staff, $summary, array $resolved, array $vars, $requestId) {
        if ($this->testFault() === 'before_reply')
            throw new WgsReplyBridgeFault('before_reply');

        $errors = array();
        // scp/tickets.php:200. $alert is true unless reply-to is none.
        // Claim stays at the method default (true): native auto-claim.
        $alert = strcasecmp('none', $resolved['reply_to']);
        $this->dropOwnerDirty($ticket);
        $this->connectCapture($ticket->getThreadId(), 'R');
        try {
            $response = $ticket->postReply($vars, $errors, $alert);
        } finally {
            $this->disconnectCapture();
        }

        $returnedId = ($response && $response->getId()) ? (int) $response->getId() : 0;
        $row = $this->ledgerFetch($requestId);
        $captured = ($row && $row['response_entry_id']) ? (int) $row['response_entry_id'] : 0;

        if ($captured && $returnedId === $captured) {
            $this->ledgerMarkNativeReturned($requestId, $captured);
            $this->finishNote($ticket, $staff, $summary, $captured, $response);
        }
        if ($captured)
            $this->recordMailUnknown($ticket, $staff, $summary, $captured, $requestId);
        if ($returnedId) {
            $this->ledgerMarkNativeReturned($requestId, $returnedId);
            $this->finishNote($ticket, $staff, $summary, $returnedId, $response);
        }
        $this->storeReplyUnknown($requestId);
    }

    /**
     * A throw after the response row was captured posts the audit note and
     * stores mail_handoff_unknown. A throw before any capture stores
     * reply_state_unknown and does not look at the thread. A reserved row
     * that has not started stays reserved so a validation error can retry.
     */
    function settleNativeFailure($ticket, $staff, $summary, $requestId) {
        $this->disconnectCapture();
        $row = $this->ledgerFetch($requestId);
        if (!$row)
            $this->fail('invalid_option', 'Unable to post the reply.', 500);

        $responseId = ($row['response_entry_id']) ? (int) $row['response_entry_id'] : 0;
        $returned = ((int) $row['native_returned'] === 1) || $row['status'] === 'reply_recorded';
        if ($returned) {
            if ($row['note_entry_id']) {
                $note = $this->responseEntry($ticket, (int) $row['note_entry_id']);
                $response = $responseId ? $this->responseEntry($ticket, $responseId) : null;
                if ($note)
                    $this->completeSend($ticket, $response, $note, $requestId, $responseId);
            }
            $this->fail('partial_note_missing',
                'The reply was saved but the internal note was not.',
                500, array('response_entry_id' => $responseId ? (int) $responseId : null));
        }
        if ($responseId || $row['status'] === 'reply_recorded_mail_unknown')
            $this->recordMailUnknown($ticket, $staff, $summary, $responseId, $requestId);
        if ($row['status'] === 'executing')
            $this->storeReplyUnknown($requestId);
        $this->fail('invalid_option', 'Unable to post the reply.', 500);
    }

    /**
     * Does not call postReply. The acting agent is the ledger row's staff.
     * Returns only for a reserved row, which still has to pass the hash.
     */
    function resumeLedger($ticket, $staff, $summary, array $row) {
        global $thisstaff;

        if ($row['status'] === 'reserved')
            return;
        if ($row['status'] !== 'executing'
                && $row['status'] !== 'reply_recorded'
                && $row['status'] !== 'reply_recorded_mail_unknown')
            return;

        $actor = Staff::lookup((int) $row['staff_id']);
        if (!$actor)
            $this->fail('staff_not_found', 'Staff member was not found.', 404);
        $staff = $actor;
        $thisstaff = $actor;
        $requestId = $this->requestId();
        $responseId = $row['response_entry_id'] ? (int) $row['response_entry_id'] : 0;
        $native = (int) $row['native_returned'] === 1;

        if ($row['status'] === 'reply_recorded_mail_unknown'
                || ($row['status'] === 'executing' && $responseId && !$native))
            $this->recordMailUnknown($ticket, $staff, $summary, $responseId, $requestId);

        if ($row['status'] === 'reply_recorded' || ($row['status'] === 'executing' && $responseId && $native)) {
            if ($row['status'] !== 'reply_recorded')
                $this->ledgerMarkNativeReturned($requestId, $responseId);
            $response = $responseId ? $this->responseEntry($ticket, $responseId) : null;
            $this->finishNote($ticket, $staff, $summary, $responseId, $response);
        }

        if ($row['status'] === 'executing')
            $this->storeReplyUnknown($requestId);
        $this->fail('invalid_option', 'Unable to finish the saved reply.', 500);
    }

    /**
     * The response is already correlated. Post the note, or finish from the
     * note id the listener stored. An existing note is never chosen by text.
     */
    function finishNote($ticket, $staff, $summary, $responseId, $response) {
        $requestId = $this->requestId();
        $row = $this->ledgerFetch($requestId);
        if ($row && $row['note_entry_id']) {
            $note = $this->responseEntry($ticket, (int) $row['note_entry_id']);
            if (!$note) {
                $this->fail('partial_note_missing',
                    'The reply was saved but the internal note was not.',
                    500, array('response_entry_id' => (int) $responseId));
            }
            $this->completeSend($ticket, $response, $note, $requestId, $responseId);
        }
        if (trim($summary) === '')
            $this->fail('empty_summary', 'note_summary is required.', 400);
        if ($this->testFault() === 'note_fail') {
            $this->fail('partial_note_missing',
                'The reply was saved but the internal note was not.',
                500, array('response_entry_id' => (int) $responseId));
        }
        $note = $this->postBridgeNote($ticket, $staff, $summary);
        $row = $this->ledgerFetch($requestId);
        if ((!$note || !$note->getId()) && $row && $row['note_entry_id'])
            $note = $this->responseEntry($ticket, (int) $row['note_entry_id']);
        if (!$note || !$note->getId()) {
            $this->fail('partial_note_missing',
                'The reply was saved but the internal note was not.',
                500, array('response_entry_id' => (int) $responseId));
        }
        $this->completeSend($ticket, $response, $note, $requestId, $responseId);
    }

    /**
     * The response row exists and postReply did not return it normally.
     * The audit note is mandatory. The result does not claim the customer
     * was mailed. A retry of a stored result never posts again.
     */
    function recordMailUnknown($ticket, $staff, $summary, $responseId, $requestId) {
        if (!$responseId) {
            $this->storeReplyUnknown($requestId);
        }
        $this->ledgerMarkMailUnknown($requestId, $responseId);
        $row = $this->ledgerFetch($requestId);
        $done = $this->storedResult($row);
        if ($done)
            $this->respond($done[0], $done[1]);

        $noteId = ($row && $row['note_entry_id']) ? (int) $row['note_entry_id'] : 0;
        if ($noteId)
            $this->storeMailUnknownResult($requestId, $responseId, $noteId);

        if (trim($summary) === '')
            $this->fail('empty_summary', 'note_summary is required.', 400);
        if ($this->testFault() === 'note_fail') {
            $this->fail('partial_note_missing',
                'The reply was saved but the internal note was not.',
                500, array('response_entry_id' => (int) $responseId));
        }

        $note = null;
        try {
            $note = $this->postBridgeNote($ticket, $staff, $summary);
        } catch (Throwable $ex) {
            $note = null;
        }
        $row = $this->ledgerFetch($requestId);
        if ($row && $row['note_entry_id'])
            $noteId = (int) $row['note_entry_id'];
        elseif ($note && $note->getId())
            $noteId = (int) $note->getId();
        else
            $noteId = 0;
        if (!$noteId) {
            $this->fail('partial_note_missing',
                'The reply was saved but the internal note was not.',
                500, array('response_entry_id' => (int) $responseId));
        }
        $this->storeMailUnknownResult($requestId, $responseId, $noteId);
    }

    function storeMailUnknownResult($requestId, $responseId, $noteId) {
        $body = array(
            'ok' => false,
            'error' => 'mail_handoff_unknown',
            'message' => 'The reply was saved, but the bridge cannot tell whether the customer got the email. Check the ticket and the customer mailbox. This request will not be sent again.',
            'response_entry_id' => (int) $responseId,
            'note_entry_id' => (int) $noteId,
        );
        $json = $this->encode($body);
        $this->ledgerStoreResult($requestId, 'reply_recorded_mail_unknown', (int) $responseId, (int) $noteId, $json, 'reply_recorded_mail_unknown');
        $row = $this->ledgerFetch($requestId);
        if ($row && $row['result_json'])
            $this->respond(500, $row['result_json']);
        $this->respond(500, $json);
    }

    /**
     * No response id was captured. ThreadEntry::create saves the row before
     * the signal, so the entry may exist. Do not claim it does not, and do
     * not go looking for it. This request is not sent again.
     */
    function storeReplyUnknown($requestId) {
        $body = array(
            'ok' => false,
            'error' => 'reply_state_unknown',
            'message' => 'Persistence and mail handoff for this request are unknown. Check the ticket thread before sending again. If a reply is there, its "Reply sent using Claude" note is missing and must be added by hand. This request will not be sent again.',
            'response_entry_id' => null,
        );
        $json = $this->encode($body);
        $this->ledgerStoreResult($requestId, 'reply_state_unknown', null, null, $json, 'executing');
        $row = $this->ledgerFetch($requestId);
        if ($row && $row['status'] === 'reply_state_unknown' && $row['result_json'])
            $this->respond(500, $row['result_json']);
        $this->fail('reply_state_unknown', $body['message'], 500, array('response_entry_id' => null));
    }

    function completeSend($ticket, $response, $note, $requestId, $responseId) {
        $this->reloadTicket($ticket);
        if (!$response || !$response->getId())
            $response = $this->responseEntry($ticket, $responseId);
        $json = $this->encode($this->sendPayload($ticket, $response, $note));
        $this->ledgerComplete($requestId, $responseId, (int) $note->getId(), $json);
        $row = $this->ledgerFetch($requestId);
        if (!$row || $row['status'] !== 'completed')
            $this->fail('partial_note_missing',
                'The reply was saved but the internal note was not.',
                500, array('response_entry_id' => (int) $responseId));
        $this->respond(200, $json);
    }
}

class WgsReplyBridgeFault extends RuntimeException {
}

/**
 * Stand-in for the unsaved response entry. ThreadEntry::asVar() returns
 * the body displayed for email (include/class.thread.php lines 1357-1358).
 */
class WgsReplyPreviewEntry {
    var $body;
    var $format;

    function __construct($body, $format) {
        $this->body = $body;
        $this->format = $format;
    }

    function getBody() {
        return ThreadEntryBody::fromFormattedText($this->body, $this->format, array(
            'balanced' => ($this->format == 'html'),
        ));
    }

    function asVar() {
        return (string) $this->getBody()->display('email');
    }
}
