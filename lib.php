<?php
// Shared helpers: database, session, security, points/titles, page layout.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui.php';

session_start();

class UserError extends RuntimeException {}

const TITLE_THRESHOLDS = [
    500 => 'Halaman Master', 300 => 'Lodi ng Lupa', 200 => 'Plantitosaurus', 150 => 'Super Tropikal',
    100 => 'Beterano sa Damo', 60 => 'Dahon Ranger', 30 => 'Pormang Tanim', 10 => 'Budol ng Kalikasan',
];
const TITLE_META = [
    'Halaman Master' => ['👑', 'Simple lang pero may diin na ikaw ang hari o reyna ng mga tanim.'],
    'Lodi ng Lupa' => ['🪴', 'Baliktad na "idol", para sa eksperto sa pagtatanim at pag-aalaga ng lupa.'],
    'Plantitosaurus' => ['🦖', 'Para sa mga may edad na o lumang kaluluwa na adik na adik sa pag-aalaga ng halaman.'],
    'Super Tropikal' => ['🌴', 'Ang tropa ninyong laging handang sumagip sa kalikasan.'],
    'Beterano sa Damo' => ['🌾', 'Pwede sa hardinero, pwede rin sa laging tumatambay sa damuhan.'],
    'Dahon Ranger' => ['🍃', 'Parang superhero sa mall, pero luntian at eco-friendly ang trip.'],
    'Pormang Tanim' => ['😎', 'Astig at pampaporma kahit nagbubungkal lang ng lupa.'],
    'Budol ng Kalikasan' => ['🌱', 'Dahil napilitan (na-budol) ka na lang ding maging eco-friendly sa huli.'],
];
const USER_COLS = 'u.id,u.username,u.first_name,u.last_name,u.email,u.phone_number,u.role,u.barangay_id,u.points,u.peak_points,u.title,u.is_verified,u.date_joined,(u.id_image IS NOT NULL) AS has_id,(u.profile_picture IS NOT NULL) AS has_photo,(COALESCE(LENGTH(u.profile_picture),0)+COALESCE(LENGTH(u.id_image),0)) AS photo_v';
const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/* ---------- database ---------- */
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (empty($_SESSION['db_ready'])) {            // first run: build tables + starter accounts automatically
            require_once __DIR__ . '/install_db.php';
            ensure_installed();
            $_SESSION['db_ready'] = true;
        }
    }
    return $pdo;
}
function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function one(string $sql, array $args = []): ?array { return q($sql, $args)->fetch() ?: null; }
function all(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function val(string $sql, array $args = []) { $r = q($sql, $args)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

function tx(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* ---------- request helpers ---------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return; }
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function csrf_field(): string {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, refresh the page and try again.');
    }
}
function post(string $k, string $default = ''): string { return trim((string)($_POST[$k] ?? $default)); }
function post_int(string $k, int $min = 0, ?int $max = null): int {
    $v = filter_var($_POST[$k] ?? null, FILTER_VALIDATE_INT);
    if ($v === false || $v === null || $v < $min || ($max !== null && $v > $max)) throw new UserError("Please enter a valid number for “$k”.");
    return $v;
}

/** Run a POST action; shows a flash message and reloads the page. */
function run(callable $fn, string $okMessage, ?string $to = null): never {
    try { $r = $fn(); flash(is_string($r) && $r !== '' ? $r : $okMessage); }
    catch (UserError $ex) { flash($ex->getMessage(), 'err'); }
    catch (PDOException $ex) { error_log($ex->getMessage()); flash('Database error. Please try again.', 'err'); }
    redirect($to ?? basename($_SERVER['SCRIPT_NAME']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

/* ---------- auth ---------- */
function user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = one('SELECT ' . USER_COLS . ', u.password, b.name AS barangay FROM users u LEFT JOIN barangays b ON b.id = u.barangay_id WHERE u.id = ?', [$_SESSION['uid']]);
        }
    }
    return $u;
}
function require_login(): array { $u = user(); if (!$u) redirect('login.php'); return $u; }
function require_resident(): array { $u = require_login(); if (is_official($u)) redirect('admin.php'); return $u; }
function require_official(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') redirect('dashboard.php');
    return $u;
}
function is_official(array $u): bool { return $u['role'] === 'admin'; }
function full_name(array $u): string { return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? ''); }
function first_name(array $u): string { return ($u['first_name'] ?? '') ?: ($u['username'] ?? ''); }

/* ---------- points, titles, codes ---------- */
function compute_title(int $points): string {
    foreach (TITLE_THRESHOLDS as $min => $title) if ($points >= $min) return $title;
    return '';
}
function refresh_title(int $userId): void {
    $u = one('SELECT points, peak_points FROM users WHERE id = ?', [$userId]);
    $peak = max($u['points'], $u['peak_points']);
    q('UPDATE users SET peak_points = ?, title = ? WHERE id = ?', [$peak, compute_title($peak), $userId]);
}
function add_points(int $userId, int $pts): void {
    q('UPDATE users SET points = points + ? WHERE id = ?', [$pts, $userId]);
    refresh_title($userId);
}
function reserved_points(int $userId): int {
    return (int)val("SELECT COALESCE(SUM(points_spent),0) FROM reward_claims WHERE user_id = ? AND status = 'Pending'", [$userId]);
}
function new_code(string $table, string $col): string {
    do {
        $c = '';
        for ($i = 0; $i < 8; $i++) $c .= CODE_ALPHABET[random_int(0, strlen(CODE_ALPHABET) - 1)];
    } while (val("SELECT 1 FROM $table WHERE $col = ?", [$c]));
    return $c;
}
function normalize_code(string $s): string {
    return implode('', array_filter(str_split(strtoupper($s)), fn($ch) => str_contains(CODE_ALPHABET, $ch)));
}
function fmt_code(?string $c): string { return $c ? substr($c, 0, 4) . '-' . substr($c, 4) : ''; }

/** Mark a participant as attended and award the event's points. Returns points awarded. */
function mark_attended(int $participationId, int $officialId, string $method): int {
    return tx(function () use ($participationId, $officialId, $method) {
        $p = one('SELECT p.*, e.points_reward, e.status AS event_status FROM event_participants p JOIN events e ON e.id = p.event_id WHERE p.id = ? FOR UPDATE', [$participationId]);
        if (!$p) throw new UserError('Participant not found.');
        if ($p['event_status'] !== 'upcoming') throw new UserError('This event is closed.');
        if ($p['status'] === 'attended') return 0;
        q("UPDATE event_participants SET status='attended', attended_at=NOW(), checkin_method=?, checked_in_by=? WHERE id=?", [$method, $officialId, $participationId]);
        add_points((int)$p['user_id'], (int)$p['points_reward']);
        return (int)$p['points_reward'];
    });
}

/** Give a reserved reward to the resident and deduct the points. */
function release_claim(int $claimId, int $officialId): array {
    return tx(function () use ($claimId, $officialId) {
        $c = one('SELECT c.*, u.barangay_id FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE c.id = ? FOR UPDATE', [$claimId]);
        $me = one('SELECT barangay_id FROM users WHERE id = ?', [$officialId]);
        if (!$c) throw new UserError('Claim not found.');
        if ($c['barangay_id'] !== $me['barangay_id']) throw new UserError('This claim belongs to a different barangay.');
        if ($c['status'] !== 'Pending') throw new UserError('This claim is already ' . strtolower($c['status']) . '.');
        $m = one('SELECT points FROM users WHERE id = ? FOR UPDATE', [$c['user_id']]);
        if ($m['points'] < $c['points_spent']) throw new UserError("The resident only has {$m['points']} points but this reward needs {$c['points_spent']}. Reject the claim or award points first.");
        q('UPDATE users SET points = points - ? WHERE id = ?', [$c['points_spent'], $c['user_id']]);
        q("UPDATE reward_claims SET status='Claimed', claimed_at=NOW(), claimed_by=? WHERE id=?", [$officialId, $claimId]);
        return $c;
    });
}

/** Cancel a reserved claim and put the items back in stock. */
function cancel_claim(array $claim): void {
    q("UPDATE reward_claims SET status='Rejected' WHERE id = ?", [$claim['id']]);
    if ($claim['reward_id']) q('UPDATE rewards SET stock = stock + ? WHERE id = ?', [$claim['quantity'], $claim['reward_id']]);
}

/* ---------- images (stored in the database so they survive redeploys) ---------- */
function ui_date(?string $d): string { return $d ? date('n/j/Y', strtotime($d)) : ''; }
function ui_time(?string $t): string { return $t ? substr($t, 0, 5) : ''; }
function image_error(?array $f): string {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Please upload a photo of your valid ID.';
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > 5 * 1048576) return 'Image must be 5 MB or smaller.';
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] === 0) return 'The selected file is empty or could not be uploaded.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? '' : 'Only JPG, PNG, GIF or WEBP images are allowed.';
}
function image_data_uri(array $f): string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($f['tmp_name']));
}
function has_upload(string $key): bool { return !empty($_FILES[$key]) && ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE; }
function pw_error(string $p): string {
    if (strlen($p) < 8) return 'Use at least 8 characters.';
    if (preg_match('/^\d+$/', $p)) return 'Password cannot be numbers only.';
    $s = (strlen($p) >= 8) + (strlen($p) >= 12) + (preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p)) + (bool)preg_match('/\d/', $p) + (bool)preg_match('/[^A-Za-z0-9]/', $p);
    return min($s, 4) < 2 ? 'Mix letters, numbers or symbols for a stronger password.' : '';
}
function parse_coords(?string $loc): ?array {
    return $loc && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $loc, $m) ? [(float)$m[1], (float)$m[2]] : null;
}
function title_info(int $points): array {
    $next = null; $earned = [];
    foreach (array_reverse(TITLE_THRESHOLDS, true) as $min => $t) { if ($points >= $min) $earned[] = [$min, $t]; elseif ($next === null) $next = [$min, $t]; }
    return ['earned' => $earned, 'next' => $next];
}
function peak(array $u): int { return max((int)($u['peak_points'] ?? 0), (int)($u['points'] ?? 0)); }
