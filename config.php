<?php
// ---- Database settings ----
// Railway: reads the MYSQL* variables automatically (see RAILWAY.md).
// Local XAMPP (default): 127.0.0.1 / ecoconnect_db / root / no password.
// InfinityFree: replace the fallback values below with the ones from your control panel.
define('DB_HOST', getenv('MYSQLHOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('MYSQLPORT') ?: 3306));
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'ecoconnect_db');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '');

const APP_NAME = 'EcoConnect';
