<?php
require_once __DIR__ . '/lib.php';
$barangays = all('SELECT id, name FROM barangays ORDER BY name');
$bid = (int)($_GET['barangay'] ?? 0);
$rows = all("SELECT u.first_name, u.last_name, u.username, u.points, u.title, b.name AS barangay
    FROM users u JOIN barangays b ON b.id = u.barangay_id
    WHERE u.role = 'resident' AND u.is_verified = 1" . ($bid ? ' AND u.barangay_id = ?' : '') . '
    ORDER BY u.points DESC, u.username LIMIT 20', $bid ? [$bid] : []);
header_html('Leaderboard', 'Top 20 residents by Eco-Points', '', 'wide');
?>
<form method="get" style="max-width:260px;margin-bottom:20px"><select name="barangay" onchange="this.form.submit()">
  <option value="">All barangays</option>
  <?php foreach ($barangays as $b): ?><option value="<?= $b['id'] ?>" <?= $b['id'] === $bid ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
</select></form>
<div class="card"><table><tr><th>#</th><th>Resident</th><th>Barangay</th><th>Title</th><th>Points</th></tr>
<?php foreach ($rows as $i => $r):
    // Public page: show first name + last initial only.
    $n = $r['first_name'] ? $r['first_name'] . ($r['last_name'] ? ' ' . mb_substr($r['last_name'], 0, 1) . '.' : '') : $r['username']; ?>
  <tr><td><?= $i + 1 ?></td><td><?= e($n) ?></td><td><?= e($r['barangay']) ?></td><td><?= e($r['title'] ?: '—') ?></td><td><b><?= (int)$r['points'] ?></b></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="muted">No residents on the board yet.</td></tr><?php endif; ?>
</table></div>
<?php footer_html();
