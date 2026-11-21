<?php
// Serves profile photos and ID images that are stored in the database.
require_once __DIR__ . '/lib.php';
$me = require_login();
$t = $_GET['t'] ?? 'photo'; $uid = (int)($_GET['u'] ?? 0);
$col = $t === 'id' ? 'id_image' : 'profile_picture';
$row = one("SELECT $col AS img, barangay_id FROM users WHERE id = ?", [$uid]);
if (!$row || !$row['img']) { http_response_code(404); exit; }
if ($t === 'id' && !($uid === (int)$me['id'] || (is_official($me) && $row['barangay_id'] === $me['barangay_id']))) { http_response_code(403); exit; }
if (!preg_match('#^data:(image/[a-z]+);base64,(.+)$#s', $row['img'], $m)) { http_response_code(404); exit; }
header('Content-Type: ' . $m[1]);
header('Cache-Control: private, max-age=3600');
echo base64_decode($m[2]);
