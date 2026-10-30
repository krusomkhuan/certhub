<?php
// Copy to config.php and set credentials supplied by your hosting provider.
// Never commit config.php or include it in public downloads/backups.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'certhub',
    'username' => 'certhub_app',
    'password' => 'CHANGE_ME',
    // Optional absolute PEM CA path if the remote database requires verified TLS.
    'ssl_ca' => '',
];
