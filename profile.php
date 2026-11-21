<?php
require_once __DIR__ . '/lib.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'transfer' && !is_official($me)) run(function () use ($me) {
        $target = post_int('target_barangay_id', 1);
        if (!val('SELECT 1 FROM barangays WHERE id = ? AND is_participating = 1', [$target])) throw new UserError('Choose a barangay.');
        if ($target === (int)$me['barangay_id']) throw new UserError('You are already in that barangay.');
        if (val("SELECT 1 FROM transfer_requests WHERE user_id = ? AND status = 'Pending'", [$me['id']])) throw new UserError('You already have a pending transfer request.');
        q('INSERT INTO transfer_requests (user_id, source_barangay_id, target_barangay_id, reason) VALUES (?,?,?,?)', [$me['id'], $me['barangay_id'], $target, post('reason') ?: null]);
    }, 'Transfer request sent to the new barangay.');

    if (post('action') === 'password') run(function () use ($me) {
        if (!password_verify($_POST['current'] ?? '', $me['password'])) throw new UserError('Current password is wrong.');
        if (strlen($_POST['new'] ?? '') < 8) throw new UserError('New password must be at least 8 characters.');
        q('UPDATE users SET password = ? WHERE id = ?', [password_hash($_POST['new'], PASSWORD_DEFAULT), $me['id']]);
    }, 'Password changed.');
    redirect('profile.php');
}

header_html(full_name($me), (is_official($me) ? 'Barangay Official' : 'Resident') . ' · ' . $me['barangay']);
$awards = all('SELECT a.*, u.first_name, u.last_name, u.username FROM point_awards a LEFT JOIN users u ON u.id = a.awarded_by WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 20', [$me['id']]);
$transfers = all('SELECT t.*, s.name AS source, d.name AS target FROM transfer_requests t JOIN barangays s ON s.id = t.source_barangay_id JOIN barangays d ON d.id = t.target_barangay_id WHERE t.user_id = ? ORDER BY t.created_at DESC', [$me['id']]);
$others = all('SELECT id, name FROM barangays WHERE is_participating = 1 AND id <> ? ORDER BY name', [$me['barangay_id']]);
?>
<div class="card"><p><b>@<?= e($me['username']) ?></b> · <?= e($me['phone_number']) ?> · <?= is_official($me) ? 'Barangay Official' : 'Resident' ?> · <?= e($me['barangay']) ?>
  <?= $me['is_verified'] ? '<span class="tag Claimed">Verified</span>' : '<span class="tag Pending">Not verified</span>' ?></p>
  <?php if (!is_official($me)): ?><p><b><?= (int)$me['points'] ?></b> points · title: <b><?= e($me['title'] ?: '—') ?></b> · peak points: <?= (int)$me['peak_points'] ?></p><?php endif; ?></div>
<?php if (!is_official($me)): ?>
<div class="card"><h2>Points from officials</h2>
  <table><?php foreach ($awards as $a): ?><tr><td>+<?= (int)$a['points'] ?></td><td><?= e($a['reason']) ?></td><td class="muted"><?= e($a['created_at']) ?></td></tr><?php endforeach; ?>
  <?php if (!$awards): ?><tr><td class="muted">None yet.</td></tr><?php endif; ?></table></div>
<div class="card"><h2>Transfer to another barangay</h2>
  <div class="msg warn">Heads up: each barangay prices its rewards differently, so your <b>points reset to 0</b> when a transfer is approved. Your title and history are kept, and any reserved rewards are cancelled.</div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="transfer">
    <label>New barangay</label><select name="target_barangay_id" required><option value="">Choose…</option><?php foreach ($others as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
    <label>Reason (optional)</label><textarea name="reason"></textarea><p><button>Request transfer</button></p></form>
  <?php foreach ($transfers as $t): ?><p class="muted"><?= e($t['source']) ?> → <?= e($t['target']) ?> · <span class="tag <?= e($t['status']) ?>"><?= e($t['status']) ?></span> · <?= e($t['created_at']) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<div class="card"><h2>Change password</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="row"><div><label>Current password</label><input type="password" name="current" required></div><div><label>New password</label><input type="password" name="new" minlength="8" required></div></div>
    <p><button>Change password</button></p></form></div>
<?php footer_html();
