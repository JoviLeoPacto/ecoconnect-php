<?php
require_once __DIR__ . '/lib.php'; $u = user(); header('Location: ' . (!$u ? 'index.php' : (is_official($u) ? 'admin.php?m=residents' : 'dashboard.php')));
