<?php
require_once __DIR__ . '/lib.php';
if ($u = user()) redirect(home_for($u));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = post('username');
    $row = one('SELECT id, password, role FROM users WHERE username = ? OR phone_number = ?', [$id, $id]);
    if ($row && password_verify($_POST['password'] ?? '', $row['password'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $row['id'];
        $_SESSION['db_ready'] = true;
        redirect($row['role'] === 'admin' ? 'admin.php' : 'dashboard.php');
    }
    $error = 'Invalid username or password.';
}

/* "Top eco-heroes" panel (PublicLeaderboard) */
$bars = all('SELECT name FROM barangays WHERE is_participating = 1 ORDER BY name');
$pick = $_GET['lb'] ?? ($bars[0]['name'] ?? '');
$leaders = all("SELECT " . USER_COLS . " FROM users u JOIN barangays b ON b.id = u.barangay_id WHERE u.role = 'resident' AND u.is_verified = 1 AND b.name = ? ORDER BY u.points DESC, u.username LIMIT 5", [$pick]);
$medals = ['🥇', '🥈', '🥉'];
$aside = '<section class="rounded-2xl border border-slate-200 bg-white p-5 dark:bg-slate-900"><div class="flex items-center gap-2">' . ic('trophy', 'h-5 w-5 text-brand-700')
    . '<h2 class="text-base font-bold tracking-tight text-slate-900">Top eco-heroes</h2></div><p class="mt-1 text-sm text-slate-500">Residents with the most Eco-Points in each barangay.</p>'
    . '<div class="mt-4 flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1" role="tablist" aria-label="Choose a barangay">';
foreach ($bars as $b) {
    $on = $b['name'] === $pick;
    $aside .= '<a role="tab" aria-selected="' . ($on ? 'true' : 'false') . '" href="login.php?lb=' . urlencode($b['name']) . '" class="grid h-8 flex-1 place-items-center rounded-md px-2 text-xs font-semibold transition-colors ' . ($on ? 'border border-slate-200 bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800') . '">' . e($b['name']) . '</a>';
}
$aside .= '</div><div class="mt-3 min-h-[200px]">';
if (!$leaders) $aside .= '<p class="py-16 text-center text-sm text-slate-500">No rankings yet in ' . e($pick) . '. Join a cleanup drive and be the first!</p>';
else {
    $aside .= '<ol class="divide-y divide-slate-100">';
    foreach ($leaders as $i => $l)
        $aside .= '<li class="flex items-center gap-3 py-2.5"><span class="w-7 shrink-0 text-center text-sm font-semibold text-slate-500">' . ($medals[$i] ?? '#' . ($i + 1)) . '</span>' . user_avatar($l)
            . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($l)) . '</p><p class="truncate eco-meta">' . e($l['title'] ?: 'No title yet') . '</p></div>'
            . '<span class="text-sm font-bold text-brand-800">' . (int)$l['points'] . ' pts</span></li>';
    $aside .= '</ol>';
}
$aside .= '</div></section>';

auth_start('Welcome to EcoConnect', 'Sign in to join cleanup drives, earn Eco-Points, and manage your barangay.', $aside);
echo form_error($error);
?>
<form method="post" class="space-y-5"><?= csrf() ?>
  <?= field('Username', 'username', $_POST['username'] ?? '', 'autocomplete="username" placeholder="Enter your username" required autofocus') ?>
  <?= password_field('Password', 'password', 'autocomplete="current-password" placeholder="Enter your password" required',
        '<a href="forgot-password.php" class="text-sm font-medium text-slate-600 hover:text-brand-800">Forgot password?</a>') ?>
  <div class="pt-1"><button class="eco-btn-primary">Log in</button></div>
</form>
<?php auth_end('New resident? <a href="register.php" class="eco-link">Create an account</a><span class="mt-2 block text-xs text-slate-500">Barangay official accounts are created by the system administrator.</span>');
