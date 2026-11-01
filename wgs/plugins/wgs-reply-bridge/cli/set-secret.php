<?php
/**
 * Set the WGS Reply Bridge shared secret from the command line.
 *
 * The admin password box is easy for a browser to autofill. This script
 * reads the secret from stdin, stores it through the field's own
 * to_database, and reads it back through a fresh config. It prints lengths
 * and readback_match. It does not print the secret.
 *
 *   printf '%s' "$SECRET" | php cli/set-secret.php INSTANCE_ID
 *
 * Refuses every SAPI other than cli, including the built-in server.
 */

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "This script only runs from the command line.\n";
    exit(1);
}

$root = dirname(__FILE__);
for ($i = 0; $i < 8; $i++) {
    if (is_file($root . '/main.inc.php'))
        break;
    $parent = dirname($root);
    if ($parent === $root)
        break;
    $root = $parent;
}
if (!is_file($root . '/main.inc.php')) {
    fwrite(STDERR, "osTicket main.inc.php was not found.\n");
    exit(1);
}

$instanceId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($instanceId < 1) {
    fwrite(STDERR, "Usage: php set-secret.php INSTANCE_ID\n");
    exit(1);
}

$secret = stream_get_contents(STDIN);
if ($secret === false)
    $secret = '';
if (substr($secret, -1) === "\n")
    $secret = substr($secret, 0, -1);
if (substr($secret, -1) === "\r")
    $secret = substr($secret, 0, -1);
if ($secret === '') {
    fwrite(STDERR, "The secret on stdin is empty.\n");
    exit(1);
}

chdir($root);
ob_start();
require $root . '/main.inc.php';
ob_end_clean();

set_error_handler(function () {
    return true;
});

try {
    $inst = PluginInstance::lookup($instanceId);
    if (!$inst)
        throw new RuntimeException('Instance was not found.');
    // Do not name this $cfg. That is osTicket's global config object, and
    // this file runs at global scope.
    $bridgeConfig = $inst->getConfig();
    if (!$bridgeConfig instanceof WgsReplyBridgeConfig)
        throw new RuntimeException('That instance is not the reply bridge.');
    $options = $bridgeConfig->getOptions();
    if (empty($options['shared_secret']) || !($options['shared_secret'] instanceof PasswordField))
        throw new RuntimeException('The shared secret field is missing.');
    $field = $options['shared_secret'];
    $encrypted = $field->to_database($secret);
    if (!is_string($encrypted) || $encrypted === '')
        throw new RuntimeException('The secret could not be stored.');
    if (!$bridgeConfig->update('shared_secret', $encrypted))
        throw new RuntimeException('The secret could not be saved.');

    $fresh = new WgsReplyBridgeConfig($inst->getNamespace());
    $fresh->setInstance($inst);
    $read = $fresh->get('shared_secret');
    $read = is_string($read) ? $read : '';
    $match = ($read !== '' && hash_equals($secret, $read)) ? 'yes' : 'no';
    echo json_encode(array(
        'ok' => true,
        'instance_id' => (int) $inst->getId(),
        'stdin_length' => strlen($secret),
        'readback_length' => strlen($read),
        'readback_match' => $match,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($match === 'yes' ? 0 : 1);
} catch (Throwable $ex) {
    // The message from to_database is not the secret. Still keep the
    // output to a fixed sentence so a future change cannot echo stdin.
    fwrite(STDERR, "The secret was not stored.\n");
    echo json_encode(array(
        'ok' => false,
        'instance_id' => $instanceId,
        'stdin_length' => strlen($secret),
        'readback_length' => 0,
        'readback_match' => 'no',
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
