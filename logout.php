<?php
require_once __DIR__ . '/lib.php';
$_SESSION = [];
session_destroy();
redirect('index.php');
