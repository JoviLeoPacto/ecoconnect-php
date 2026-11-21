<?php
// Shared helpers: database, session, security, points/titles, page layout.
require_once __DIR__ . '/config.php';

session_start();

class UserError extends RuntimeException {}

const TITLE_THRESHOLDS = [
    500 => 'Halaman Master', 300 => 'Lodi ng Lupa', 200 => 'Plantitosaurus', 150 => 'Super Tropikal',
    100 => 'Beterano sa Damo', 60 => 'Dahon Ranger', 30 => 'Pormang Tanim', 10 => 'Budol ng Kalikasan',
];
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
            $u = one('SELECT u.*, b.name AS barangay FROM users u LEFT JOIN barangays b ON b.id = u.barangay_id WHERE u.id = ?', [$_SESSION['uid']]);
        }
    }
    return $u;
}
function require_login(): array { $u = user(); if (!$u) redirect('index.php'); return $u; }
function require_official(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('Only Barangay Officials can open this page.'); }
    return $u;
}
function is_official(array $u): bool { return $u['role'] === 'admin'; }
function full_name(array $u): string { return trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']; }

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

/* ---------- layout (matches the EcoConnect React site: green brand, slate neutrals, sidebar shell) ---------- */
function icon(string $name): string {
    $p = [
        'leaf' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'bag' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'trophy' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'menu' => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
    ][$name] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
function theme_toggle(): string {
    return '<button type="button" class="icon-btn" id="themeBtn" aria-label="Switch theme" title="Switch theme"><span class="i-moon">' . icon('moon') . '</span><span class="i-sun">' . icon('sun') . '</span></button>';
}
function logo(string $href): string {
    return '<a href="' . $href . '" class="logo"><span class="logo-mark">' . icon('leaf') . '</span><span>' . APP_NAME . '</span></a>';
}

/**
 * Start a page. Logged in  -> sidebar app shell with a top bar (title / subtitle / actions).
 * Logged out -> centred public layout. $width: narrow | form | wide (public pages only).
 */
function header_html(string $title, string $subtitle = '', string $actions = '', string $width = 'narrow'): void {
    global $LAYOUT;
    $u = user();
    $LAYOUT = $u ? 'app' : 'public';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#15803d"><title>' . e($title) . ' · ' . APP_NAME . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<script>(function(){var t=null;try{t=localStorage.getItem("ecoconnect_theme")}catch(e){}if(t!=="light"&&t!=="dark"){t=window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}if(t==="dark")document.documentElement.classList.add("dark")})();</script>';
    echo '<style>' . css() . '</style></head><body class="' . ($u ? 'shell' : 'pub') . '">';
    $flashes = '';
    foreach (flash() as [$type, $msg]) $flashes .= '<div class="msg ' . e($type) . '" role="alert">' . e($msg) . '</div>';

    if (!$u) {
        echo '<div class="pub-page"><header class="pub-head">' . logo('index.php') . '<div class="pub-actions">';
        if (basename($_SERVER['SCRIPT_NAME']) === 'leaderboard.php') echo '<a class="btn alt sm" href="index.php">Log in</a>';
        echo theme_toggle() . '</div></header><main class="pub-main"><div class="pub-box ' . e($width) . '">';
        echo '<div class="pub-title"><h1>' . e($title) . '</h1>' . ($subtitle !== '' ? '<p>' . e($subtitle) . '</p>' : '') . '</div>' . $flashes;
        return;
    }

    $page = basename($_SERVER['SCRIPT_NAME']);
    $nav = [['dashboard.php', 'Events', 'calendar'], ['rewards.php', 'Rewards', 'bag']];
    if (is_official($u)) $nav[] = ['residents.php', 'Residents', 'users'];
    $nav[] = ['leaderboard.php', 'Leaderboard', 'trophy'];
    $nav[] = ['profile.php', 'Profile', 'user'];
    $initial = strtoupper(mb_substr(full_name($u), 0, 1));

    echo '<div class="overlay" id="overlay"></div><aside class="side" id="side"><div class="side-top">' . logo('dashboard.php') . '</div><nav>';
    foreach ($nav as [$href, $label, $ic]) echo '<a href="' . $href . '"' . ($page === $href || ($page === 'event.php' && $href === 'dashboard.php') ? ' class="on" aria-current="page"' : '') . '>' . icon($ic) . '<span>' . e($label) . '</span></a>';
    echo '<a href="logout.php" class="out">' . icon('logout') . '<span>Log out</span></a></nav>';
    echo '<div class="side-user"><span class="avatar">' . e($initial) . '</span><div><b>' . e(full_name($u)) . '</b><small>' . (is_official($u) ? 'Barangay Official' : 'Resident') . ' · ' . e($u['barangay']) . '</small></div></div></aside>';
    echo '<div class="content"><header class="top"><div class="top-l"><button type="button" class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu">' . icon('menu') . '</button><div><h1>' . e($title) . '</h1>' . ($subtitle !== '' ? '<p>' . e($subtitle) . '</p>' : '') . '</div></div><div class="top-r">' . $actions . theme_toggle() . '</div></header>';
    echo '<main class="main"><div class="inner">' . $flashes;
}
function footer_html(): void {
    global $LAYOUT;
    if ($LAYOUT === 'app') echo '</div></main></div>';
    else echo '</div></main><footer class="pub-foot">By continuing you agree to our Terms &amp; Data Privacy Policy<br>in line with the Data Privacy Act of 2012 (RA 10173).</footer></div>';
    echo '<script>(function(){var d=document.documentElement,b=document.getElementById("themeBtn");if(b)b.onclick=function(){var t=d.classList.toggle("dark")?"dark":"light";try{localStorage.setItem("ecoconnect_theme",t)}catch(e){}};'
       . 'var m=document.getElementById("menuBtn"),s=document.getElementById("side"),o=document.getElementById("overlay");if(m&&s){m.onclick=function(){s.classList.add("open");o.classList.add("open")};o.onclick=function(){s.classList.remove("open");o.classList.remove("open")}}})();</script></body></html>';
}

function css(): string {
    return <<<'CSS'
:root{color-scheme:light;--surface:#fff;--s50:#f8fafc;--s100:#f1f5f9;--s200:#e2e8f0;--s300:#cbd5e1;--s400:#94a3b8;--s500:#64748b;--s600:#475569;--s700:#334155;--s800:#1e293b;--s900:#0f172a;
--b50:#f0fdf4;--b100:#dcfce7;--b200:#bbf7d0;--b600:#16a34a;--b700:#15803d;--b800:#166534;--b900:#14532d;--btn-text:#fff;
--r50:#fef2f2;--r200:#fecaca;--r600:#dc2626;--r700:#b91c1c;--a50:#fffbeb;--a200:#fde68a;--a800:#92400e}
.dark{color-scheme:dark;--surface:#161f2e;--s50:#0d131f;--s100:#222d3f;--s200:#2e3a4e;--s300:#475569;--s400:#78879c;--s500:#94a3b8;--s600:#b0bdcd;--s700:#cbd5e1;--s800:#e2e8f0;--s900:#f1f5f9;
--b50:#142e21;--b100:#163c28;--b200:#1e5437;--b600:#4ade80;--b700:#4ade80;--b800:#86efac;--b900:#bbf7d0;--btn-text:#052e16;
--r50:#3a161a;--r200:#632328;--r600:#f87171;--r700:#fca5a5;--a50:#36280c;--a200:#5c4414;--a800:#fde68a}
*{box-sizing:border-box}
body{margin:0;font:14px/1.55 "Plus Jakarta Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--s900);background:var(--surface);-webkit-font-smoothing:antialiased}
a{color:inherit}.ic{width:18px;height:18px;flex:none}
.logo{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:700;font-size:16px;letter-spacing:-.01em;color:var(--s900)}
.logo-mark{display:grid;place-items:center;width:36px;height:36px;border-radius:8px;background:var(--b700);color:var(--btn-text)}
.icon-btn{display:inline-grid;place-items:center;width:36px;height:36px;border-radius:8px;border:0;background:transparent;color:var(--s500);padding:0;cursor:pointer}
.icon-btn:hover{background:var(--s100);color:var(--s900)}.i-sun{display:none}.dark .i-sun{display:inline-grid}.dark .i-moon{display:none}

/* public / auth layout */
.pub-page{min-height:100vh;display:flex;flex-direction:column;background:var(--surface)}
.pub-head{display:flex;justify-content:space-between;align-items:center;padding:24px 32px 0}.pub-actions{display:flex;align-items:center;gap:10px}
.pub-main{flex:1;display:flex;justify-content:center;align-items:flex-start;padding:40px 20px}
.pub-box{width:100%;max-width:400px}.pub-box.form{max-width:520px}.pub-box.wide{max-width:760px}
.pub-title{text-align:center;margin-bottom:28px}.pub-title h1{margin:0;font-size:30px;line-height:1.2;font-weight:700;letter-spacing:-.02em}.pub-title p{margin:8px 0 0;color:var(--s500);font-size:14px}
.pub-foot{padding:0 20px 28px;text-align:center;font-size:12px;color:var(--s500);line-height:1.6}
.pub-note{margin-top:24px;text-align:center;font-size:14px;color:var(--s600)}.pub-note small{display:block;margin-top:8px;font-size:12px;color:var(--s500)}
.link{font-weight:600;color:var(--s900);text-decoration:underline;text-underline-offset:4px;text-decoration-color:var(--s300)}.link:hover{color:var(--b800);text-decoration-color:var(--b700)}

/* app shell */
body.shell{background:var(--s50);height:100vh;overflow:hidden;display:flex}
.side{width:256px;flex:none;display:flex;flex-direction:column;background:var(--surface);border-right:1px solid var(--s200)}
.side-top{height:64px;display:flex;align-items:center;padding:0 20px}
.side nav{flex:1;padding:12px;display:flex;flex-direction:column;gap:2px;overflow-y:auto}
.side nav a{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;text-decoration:none;font-weight:500;color:var(--s600)}
.side nav a .ic{color:var(--s400)}.side nav a:hover{background:var(--s50);color:var(--s900)}
.side nav a.on{background:var(--b50);color:var(--b800);font-weight:600}.side nav a.on .ic{color:var(--b700)}
.side nav a.out{color:var(--r600);margin-top:auto}.side nav a.out .ic{color:var(--r600)}.side nav a.out:hover{background:var(--r50)}
.side-user{display:flex;align-items:center;gap:12px;padding:14px 16px;border-top:1px solid var(--s200);min-width:0}
.side-user div{min-width:0}.side-user b{display:block;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.side-user small{display:block;font-size:12px;color:var(--s500);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.avatar{display:grid;place-items:center;flex:none;width:36px;height:36px;border-radius:50%;background:var(--b100);color:var(--b800);font-weight:700}
.content{flex:1;min-width:0;display:flex;flex-direction:column}
.top{height:64px;flex:none;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 24px;background:var(--surface);border-bottom:1px solid var(--s200)}
.top-l{display:flex;align-items:center;gap:8px;min-width:0}.top h1{margin:0;font-size:18px;font-weight:700;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.top p{margin:0;font-size:13px;color:var(--s500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.top-r{display:flex;align-items:center;gap:8px;flex:none}
.menu-btn{display:none;margin-left:-8px}.overlay{display:none}
.main{flex:1;overflow-y:auto}.inner{max-width:1152px;margin:0 auto;padding:32px 24px 48px}
@media(max-width:1023px){
 .side{position:fixed;inset:0 auto 0 0;z-index:50;display:none}.side.open{display:flex}
 .overlay.open{display:block;position:fixed;inset:0;z-index:40;background:rgba(15,23,42,.4)}
 .menu-btn{display:inline-grid}.top{padding:0 16px}.inner{padding:20px 16px 40px}.top p{display:none}}

/* components */
h2{font-size:16px;font-weight:600;margin:0 0 12px;color:var(--s900)}h2.sect{font-size:15px;margin:28px 0 14px}
.card{background:var(--surface);border:1px solid var(--s200);border-radius:12px;padding:20px;margin-bottom:20px}
.card>h2:first-child{margin-top:0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}.grid>.card{margin-bottom:0}
.stat{font-size:12px;font-weight:500;color:var(--s500)}.stat b{display:block;font-size:26px;line-height:1.2;font-weight:700;color:var(--s900);margin-bottom:2px}
.muted{color:var(--s500);font-size:13px}.card p{margin:.4em 0}
.msg{display:block;padding:12px 14px;border-radius:8px;margin-bottom:20px;font-size:14px;border:1px solid}
.msg.ok{background:var(--b50);border-color:var(--b200);color:var(--b800)}.msg.err{background:var(--r50);border-color:var(--r200);color:var(--r700)}
.msg.warn{background:var(--a50);border-color:var(--a200);color:var(--a800)}
label{display:block;font-size:14px;font-weight:600;color:var(--s800);margin:16px 0 6px}label:first-child{margin-top:0}.row{margin-bottom:0}.row+label,.row+p,.row+.msg{margin-top:16px}form>.row+div,form>.row+p{margin-top:16px}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;align-items:end}.row>div>label{margin-top:0}
input,select,textarea{width:100%;height:44px;padding:0 14px;border:1px solid var(--s200);border-radius:8px;background:var(--s50);color:var(--s900);font:inherit;font-size:14px;transition:border-color .15s,box-shadow .15s}
textarea{height:auto;min-height:96px;padding:10px 14px;resize:vertical}input::placeholder,textarea::placeholder{color:var(--s400)}
input:focus,select:focus,textarea:focus{outline:0;background:var(--surface);border-color:var(--b600);box-shadow:0 0 0 4px rgba(22,163,74,.12)}
table input,table select{height:36px;font-size:13px}
button,.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:40px;padding:0 16px;border:1px solid transparent;border-radius:8px;background:var(--b700);color:var(--btn-text);font:inherit;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:background .15s}
button:hover,.btn:hover{background:var(--b800)}button:focus-visible,.btn:focus-visible{outline:0;box-shadow:0 0 0 4px rgba(22,163,74,.25)}
button.alt,.btn.alt{background:var(--surface);color:var(--s800);border-color:var(--s200)}button.alt:hover,.btn.alt:hover{background:var(--s50)}
button.danger{background:var(--surface);color:var(--r600);border-color:var(--r200)}button.danger:hover{background:var(--r50)}
.btn.sm,button.sm,table button,.card form.inline button,.card>.btn{height:32px;padding:0 12px;font-size:12px}
.w100{width:100%;height:44px}form.inline{display:inline-block;margin:0 4px 4px 0}.card>.btn,.card>form.inline:first-of-type{margin-top:4px}
.tag{display:inline-flex;align-items:center;border-radius:6px;padding:2px 8px;font-size:12px;font-weight:500;white-space:nowrap;background:var(--s100);color:var(--s700);vertical-align:middle}
.tag.upcoming,.tag.Pending,.tag.joined{background:var(--a50);color:var(--a800)}
.tag.completed,.tag.Claimed,.tag.attended,.tag.Approved{background:var(--b50);color:var(--b800)}
.tag.cancelled,.tag.Rejected{background:var(--r50);color:var(--r700)}
.badge{display:inline-flex;align-items:center;gap:6px;border-radius:6px;padding:4px 10px;font-size:14px;font-weight:600;background:var(--b50);color:var(--b800)}.badge .ic{width:16px;height:16px}
.code{display:inline-block;font:700 15px ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.14em;background:var(--s100);color:var(--s900);padding:3px 10px;border-radius:6px}
table{width:100%;border-collapse:collapse;font-size:14px;text-align:left}
th{padding:12px 16px;font-size:12px;font-weight:500;color:var(--s500);background:var(--s50);border-bottom:1px solid var(--s200);white-space:nowrap}
td{padding:12px 16px;border-bottom:1px solid var(--s100);color:var(--s700);vertical-align:middle}
tr:last-child td{border-bottom:0}tbody tr:hover td,table tr:hover td{background:var(--s50)}
.card:has(>table){padding:0;overflow-x:auto}.card:has(>table)>h2{padding:20px 20px 0}.card>table:not(:first-child){margin-top:10px}
.card:has(>table)>form{padding:0 20px 20px}
h2 + form,.card>form{max-width:none}
@media(max-width:640px){.pub-head{padding:20px 20px 0}.pub-title h1{font-size:26px}}
CSS;
}
