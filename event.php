<?php
require_once __DIR__ . '/lib.php';
$me = require_official();
$ev = one('SELECT e.*, c.name AS category FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.id = ? AND e.barangay_id = ?', [(int)($_GET['id'] ?? 0), $me['barangay_id']]);
if (!$ev) { http_response_code(404); exit('Event not found in your barangay.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'code') run(function () use ($me, $ev) {
        $code = normalize_code(post('code'));
        $p = strlen($code) === 8 ? one('SELECT p.id, p.status, u.first_name, u.last_name, u.username FROM event_participants p JOIN users u ON u.id = p.user_id WHERE p.event_id = ? AND p.ticket_code = ?', [$ev['id'], $code]) : null;
        if (!$p) throw new UserError('That ticket code is not valid for this event.');
        $name = full_name($p);
        if ($p['status'] === 'attended') return "$name was already checked in.";
        $pts = mark_attended((int)$p['id'], (int)$me['id'], 'code');
        return "$name checked in · +$pts pts";
    }, '');
    if (post('action') === 'verify') run(function () use ($me) {
        $pts = mark_attended(post_int('participation_id', 1), (int)$me['id'], 'manual');
        return $pts ? "Attendance verified · +$pts pts" : 'Already verified.';
    }, '');
    redirect('event.php?id=' . $ev['id']);
}

$rows = all('SELECT p.*, u.first_name, u.last_name, u.username FROM event_participants p JOIN users u ON u.id = p.user_id WHERE p.event_id = ? ORDER BY p.joined_at', [$ev['id']]);
header_html($ev['title'], $ev['date'] . ' ' . substr($ev['time'], 0, 5) . ' · ' . $ev['location'] . ' · ' . (int)$ev['points_reward'] . ' pts per attendee',
    '<span class="tag ' . e($ev['status']) . '">' . e($ev['status']) . '</span>');
?>
<?php if ($ev['status'] === 'upcoming'): ?>
<div class="card"><h2>Check in by ticket code</h2>
  <form method="post" class="row" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="code">
    <div><label>Resident's 8-character code</label><input name="code" placeholder="ABCD-2345" required autofocus></div>
    <div><button>Check in</button></div>
  </form>
</div>
<?php else: ?><div class="msg warn">This event is <?= e($ev['status']) ?>. Reopen it from the Events page to check people in.</div><?php endif; ?>
<div class="card"><h2>Participants (<?= count($rows) ?>)</h2>
<table><tr><th>Name</th><th>Ticket</th><th>Status</th><th>Checked in</th><th></th></tr>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e(full_name($r)) ?></td><td><span class="code" style="font-size:14px"><?= e(fmt_code($r['ticket_code'])) ?></span></td>
    <td><span class="tag <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
    <td class="muted"><?= e($r['attended_at'] ? $r['attended_at'] . ' (' . $r['checkin_method'] . ')' : '') ?></td>
    <td><?php if ($r['status'] === 'joined' && $ev['status'] === 'upcoming'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="participation_id" value="<?= $r['id'] ?>"><button class="alt">Verify attendance</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="muted">Nobody has joined yet.</td></tr>'; ?>
</table></div>
<p><a href="dashboard.php">← Back to events</a></p>
<?php footer_html();
