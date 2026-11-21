<?php
// One-time installer: creates the database from database/ecoconnect.sql and adds the starter accounts.
// Run it once in the browser (http://localhost/ecoconnect-php/setup.php), then DELETE this file.
require_once __DIR__ . '/config.php';

$done = false; $error = null; $lines = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $base = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
        try {
            // Hosting (InfinityFree etc.): the database already exists, so connect to it directly.
            $pdo = new PDO($base . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $opts);
        } catch (PDOException $ex) {
            if ($ex->getCode() != 1049) throw $ex;   // 1049 = unknown database (local XAMPP): create it
            $pdo = new PDO($base, DB_USER, DB_PASS, $opts);
            $pdo->exec('CREATE DATABASE `' . DB_NAME . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . DB_NAME . '`');
        }
        $sql = file_get_contents(__DIR__ . '/database/ecoconnect.sql');
        $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);   // db is already selected
        $pdo->exec($sql);

        $bid = (int)$pdo->query("SELECT id FROM barangays WHERE name = 'Tiniguiban'")->fetchColumn();
        $cat = (int)$pdo->query("SELECT id FROM event_categories WHERE name = 'General Cleanup'")->fetchColumn();
        $add = $pdo->prepare("INSERT INTO users (username,password,first_name,last_name,phone_number,role,barangay_id,is_verified) VALUES (?,?,?,?,?,?,?,1)");
        $add->execute(['official', password_hash('Official123!', PASSWORD_DEFAULT), 'Official', 'Account', '09000000001', 'admin', $bid]);
        $officialId = (int)$pdo->lastInsertId();
        $add->execute(['resident', password_hash('Resident123!', PASSWORD_DEFAULT), 'Resident', 'Account', '09000000002', 'resident', $bid]);

        $pdo->prepare("INSERT INTO events (barangay_id, organizer_id, category_id, title, description, location, date, time, points_reward) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$bid, $officialId, $cat, 'Barangay Clean-up Drive', 'Bring gloves and a sack. Meet at the barangay hall.', 'Barangay Hall', date('Y-m-d', strtotime('+7 days')), '07:00:00', 20]);
        $pdo->prepare("INSERT INTO rewards (barangay_id, name, points_cost, stock, max_per_claim, icon) VALUES (?,?,?,?,?,?)")
            ->execute([$bid, 'Reusable tote bag', 30, 20, 2, '👜']);
        $done = true;
    } catch (Throwable $ex) { $error = $ex->getMessage(); }
}
?><!doctype html><meta charset="utf-8"><title>EcoConnect setup</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>body{font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:#0f172a}a{color:#15803d;font-weight:600}button{background:#15803d;color:#fff;border:0;border-radius:8px;font:600 14px inherit;cursor:pointer}</style>
<body style="max-width:560px;margin:40px auto;padding:0 16px">
<h1 style="font-size:28px;letter-spacing:-.02em">EcoConnect setup</h1>
<?php if ($done): ?>
  <p style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px;border-radius:8px"><b>Done.</b> Database <code><?= htmlspecialchars(DB_NAME) ?></code> was created.</p>
  <p>Logins:<br><b>official</b> / Official123!<br><b>resident</b> / Resident123!</p>
  <p><b>Delete <code>setup.php</code> now</b>, and change the passwords before real use.</p>
  <p><a href="index.php">Go to login →</a></p>
<?php else: ?>
  <?php if ($error): ?><p style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px;border-radius:8px">Setup failed: <?= htmlspecialchars($error) ?><br>Check <code>config.php</code> and that MySQL is running.</p><?php endif; ?>
  <p>This creates the <code><?= htmlspecialchars(DB_NAME) ?></code> database and <b>erases any existing EcoConnect tables</b> in it.</p>
  <form method="post"><button style="padding:10px 18px">Create database and accounts</button></form>
<?php endif; ?>
