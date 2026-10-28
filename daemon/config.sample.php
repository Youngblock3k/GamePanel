<?php
// Copy to config.php. Never commit config.php.
return [
    // Paste the secret shown ONCE by the panel when you created the node.
    'secret' => 'PASTE_NODE_SECRET_HERE',

    // Optional but recommended: only accept requests from the panel's IP(s).
    'allowed_ips' => [],

    'data_dir' => '/var/lib/gamepanel/servers',
    'state_dir' => '/var/lib/gamepanel/state',
    'docker_socket' => '/var/run/docker.sock',

    // Containers run as this uid:gid; the daemon user must own the same ids so it can edit files.
    'container_uid' => 988,
    'container_gid' => 988,

    'network' => 'gamepanel0',
    // Fixed subnet so the host firewall (install/vorlane-firewall) can block containers from the LAN/host.
    'network_subnet' => '172.30.0.0/16',

    // PHP CLI used for the background installer (php-fpm cannot run CLI scripts).
    'php_cli' => '/usr/bin/php',
];
