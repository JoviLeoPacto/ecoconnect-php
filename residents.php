<?php
require_once __DIR__ . '/lib.php';
$me = require_official();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $resident = fn() => one("SELECT * FROM users WHERE id = ? AND role = 'resident' AND barangay_id = ?", [post_int('user_id', 1), $me['barangay_id']]) ?: throw new UserError('Resident not found in your barangay.');

    if ($action === 'verify') run(function () use ($resident) {
        q('UPDATE users SET is_verified = 1 WHERE id = ?', [$resident()['id']]);
    }, 'Resident verified.');

    if ($action === 'award') run(function () use ($me, $resident) {
        $pts = post_int('points', 1, 1000);
        tx(function () use ($me, $resident, $pts) {
            $r = $resident();
            add_points((int)$r['id'], $pts);
            q('INSERT INTO point_awards (user_id, awarded_by, points, reason) VALUES (?,?,?,?)', [$r['id'], $me['id'], $pts, post('reason') ?: 'Direct award by official']);
        });
    }, 'Points awarded.');

    if ($action === 'transfer') run(function () use ($me) {
        tx(function () use ($me) {
            $t = one("SELECT * FROM transfer_requests WHERE id = ? AND target_barangay_id = ? AND status = 'Pending' FOR UPDATE", [post_int('request_id', 1), $me['barangay_id']]);
            if (!$t) throw new UserError('Request not found or already decided.');
            if (post('decision') === 'Approved') {
                // Each barangay prices rewards differently: points reset, title and history are kept.
                foreach (all("SELECT * FROM reward_claims WHERE user_id = ? AND status = 'Pending' FOR UPDATE", [$t['user_id']]) as $c) cancel_claim($c);
                q('UPDATE users SET barangay_id = ?, points = 0 WHERE id = ?', [$t['target_barangay_id'], $t['user_id']]);
                q("UPDATE transfer_requests SET status='Approved', decided_by=?, decided_at=NOW() WHERE id=?", [$me['id'], $t['id']]);
            } else {
                q("UPDATE transfer_requests SET status='Rejected', decided_by=?, decided_at=NOW() WHERE id=?", [$me['id'], $t['id']]);
            }
        });
    }, 'Transfer decision saved.');

    redirect('residents.php');
}

header_html('Residents', $me['barangay'] . ' · verify residents and award points');
$residents = all("SELECT * FROM users WHERE role = 'resident' AND barangay_id = ? ORDER BY is_verified, last_name, first_name", [$me['barangay_id']]);
$incoming = all("SELECT t.*, u.first_name, u.last_name, u.username, u.points, b.name AS source FROM transfer_requests t
    JOIN users u ON u.id = t.user_id JOIN barangays b ON b.id = t.source_barangay_id
    WHERE t.target_barangay_id = ? AND t.status = 'Pending' ORDER BY t.created_at", [$me['barangay_id']]);
?>
<?php if ($incoming): ?><div class="card"><h2>Incoming transfer requests</h2>
<table><tr><th>Resident</th><th>From</th><th>Reason</th><th></th></tr>
<?php foreach ($incoming as $t): ?>
  <tr><td><?= e(full_name($t)) ?> <span class="muted">(<?= (int)$t['points'] ?> pts → resets to 0)</span></td><td><?= e($t['source']) ?></td><td><?= e($t['reason']) ?></td>
  <td><?php foreach (['Approved' => '', 'Rejected' => 'danger'] as $d => $cls): ?>
    <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="transfer"><input type="hidden" name="request_id" value="<?= $t['id'] ?>"><input type="hidden" name="decision" value="<?= $d ?>"><button class="<?= $cls ?>"><?= $d === 'Approved' ? 'Approve' : 'Reject' ?></button></form>
  <?php endforeach; ?></td></tr>
<?php endforeach; ?></table></div><?php endif; ?>
<div class="card"><table><tr><th>Name</th><th>Phone</th><th>Points</th><th>Status</th><th>Award points</th></tr>
<?php foreach ($residents as $r): ?>
  <tr><td><?= e(full_name($r)) ?><br><span class="muted">@<?= e($r['username']) ?></span></td><td><?= e($r['phone_number']) ?></td><td><?= (int)$r['points'] ?><br><span class="muted"><?= e($r['title']) ?></span></td>
  <td><?php if ($r['is_verified']): ?><span class="tag Claimed">Verified</span><?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="user_id" value="<?= $r['id'] ?>"><button>Verify</button></form><?php endif; ?></td>
  <td><form method="post" class="row" style="grid-template-columns:80px 1fr auto;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="award"><input type="hidden" name="user_id" value="<?= $r['id'] ?>">
    <input type="number" name="points" min="1" max="1000" placeholder="pts" required><input name="reason" placeholder="Reason (optional)"><button class="alt">Award</button></form></td></tr>
<?php endforeach; ?>
<?php if (!$residents): ?><tr><td colspan="5" class="muted">No residents have registered in your barangay yet.</td></tr><?php endif; ?>
</table></div>
<?php footer_html();
