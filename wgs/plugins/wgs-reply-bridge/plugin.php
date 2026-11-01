<?php
return array(
    'id' => 'wgs:reply-bridge',
    'version' => '0.1',
    'name' => 'WGS Reply Bridge',
    'author' => 'Warehouse Guitar Speakers',
    'description' => 'Post a staff reply through osTicket\'s own Ticket::postReply(). The bridge does not send mail.',
    'url' => 'https://wgsusa.com/',
    'plugin' => 'class.replybridge.php:WgsReplyBridgePlugin',
    // Compared with MAJOR_VERSION (1.17). See PluginManager::isCompatible().
    'ost_version' => '1.17',
);
