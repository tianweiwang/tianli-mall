<?php
declare(strict_types=1);

// Keep the actual base connection and the shared default transaction owner.
// Only this application's SQL diagnostics and connection behavior change.
$database = app()->config->get('database');
$database['connections']['mysql']['debug'] = false;
$database['connections']['mysql']['charset'] = 'utf8mb4';
$database['connections']['mysql']['break_reconnect'] = false;

return $database;
