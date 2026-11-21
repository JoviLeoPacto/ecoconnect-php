<?php
require_once __DIR__ . '/lib.php';
$me = require_official();
$ev = one('SELECT e.*, c.name AS category FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.id = ? AND e.barangay_id = ?', [(int)($_GET['id'] ?? 0), $me['barangay_id']]);
if (!$ev) { flash('Event not found in your barangay.', 'err'); redirect('admin.php'); }
$self = 'manage-participants.php?id=' . $ev['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = post('action');
    try {
        if ($a === 'code') {
            $code = normalize_code(post('code'));
            $p = strlen($code) === 8 ? one('SELECT p.id, p.status, u.first_name, u.last_name, u.username FROM event_participants p JOIN users u ON u.id = p.user_id WHERE p.event_id = ? AND p.ticket_code = ?', [$ev['id'], $code]) : null;
            if (!$p) throw new UserError('That ticket code is not valid for this event.');
            $name = full_name($p);
            flash($p['status'] === 'attended' ? "$name was already checked in." : "$name checked in · +" . mark_attended((int)$p['id'], (int)$me['id'], 'code') . ' pts');
        } elseif ($a === 'verify') {
            $pid = post_int('participation_id', 1);
            if (!val('SELECT 1 FROM event_participants WHERE id = ? AND event_id = ?', [$pid, $ev['id']])) throw new UserError('Participant not found.');
            $pts = mark_attended($pid, (int)$me['id'], 'manual');
            flash($pts ? "Attendance verified · +$pts pts" : 'Already verified.');
        } elseif ($a === 'complete') { q("UPDATE events SET status='completed', completed_at=NOW() WHERE id=?", [$ev['id']]); flash('Event marked done.'); }
        elseif ($a === 'reopen') { q("UPDATE events SET status='upcoming', completed_at=NULL WHERE id=?", [$ev['id']]); flash('Event reopened.'); }
    } catch (UserError $ex) { flash($ex->getMessage(), 'err'); }
    redirect($self);
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="participants-' . $ev['id'] . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, ['Name', 'Username', 'Ticket', 'Status', 'Checked in']);
    foreach (all('SELECT p.*, u.first_name, u.last_name, u.username FROM event_participants p JOIN users u ON u.id = p.user_id WHERE p.event_id = ? ORDER BY p.joined_at', [$ev['id']]) as $r) fputcsv($o, [full_name($r), $r['username'], fmt_code($r['ticket_code']), $r['status'], $r['attended_at']]);
    exit;
}

$rows = all('SELECT p.*, u.first_name, u.last_name, u.username FROM event_participants p JOIN users u ON u.id = p.user_id WHERE p.event_id = ? ORDER BY p.status, p.joined_at', [$ev['id']]);
$att = count(array_filter($rows, fn($r) => $r['status'] === 'attended')); $open = $ev['status'] === 'upcoming';
$actions = '<a href="' . $self . '&export=csv" class="eco-btn eco-btn-outline">' . ic('download', 'h-4 w-4') . ' Export</a><a href="' . $self . '&m=scan" class="eco-btn eco-btn-outline">' . ic('qr', 'h-4 w-4') . ' Enter ticket code</a>'
    . '<form method="post">' . csrf($open ? 'complete' : 'reopen') . '<button class="eco-btn eco-btn-green">' . ic($open ? 'flag' : 'undo', 'h-4 w-4') . ($open ? ' Mark done' : ' Reopen') . '</button></form>';
simple_start($ev['title'], ui_date($ev['date']) . ' · ' . ui_time($ev['time']) . ' · ' . (int)$ev['points_reward'] . ' pts per attendee', 'admin.php', 'Dashboard', $actions, 'max-w-4xl');
?>
<div class="mb-6 grid grid-cols-3 gap-3">
  <?= stat_card('users', 'Joined', count($rows)) ?><?= stat_card('check', 'Attended', $att) ?><?= stat_card('award', 'Points given', $att * (int)$ev['points_reward']) ?>
</div>
<?php if (!$open): ?><div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">This event is <?= e($ev['status']) ?>. Reopen it to check people in.</div><?php endif; ?>
<?php if (!$rows): ?><?= empty_state('users', 'Nobody has joined yet', 'Residents will appear here as they join.') ?>
<?php else: ?><ul class="eco-card divide-y divide-slate-100">
<?php foreach ($rows as $r): ?><li class="flex items-center justify-between gap-3 px-4 py-3"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900"><?= e(full_name($r)) ?></p><p class="eco-meta"><span class="font-mono"><?= e(fmt_code($r['ticket_code'])) ?></span><?= $r['attended_at'] ? ' · checked in ' . e(date('n/j g:i A', strtotime($r['attended_at']))) : '' ?></p></div>
  <?php if ($r['status'] === 'attended'): ?><?= badge('green', ic('check', 'h-3.5 w-3.5') . ' Attended') ?>
  <?php elseif ($open): ?><form method="post"><?= csrf('verify') . hid('participation_id', $r['id']) ?><button class="eco-btn eco-btn-green eco-btn-sm">Mark attended</button></form>
  <?php else: ?><?= badge('slate', 'Missed') ?><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php
$modal = '';
if (($_GET['m'] ?? '') === 'scan') $modal = modal('Enter ticket code', 'Type the 8-character code from the resident’s ticket.', $self,
    '<form method="post" class="space-y-4">' . csrf('code') . field('Ticket code', 'code', '', 'placeholder="ABCD-2345" required autofocus autocomplete="off"') . '<div class="flex justify-end gap-2"><a href="' . $self . '" class="eco-btn eco-btn-outline">Cancel</a><button class="eco-btn eco-btn-green">Check in</button></div></form>', '', 'sm');
simple_end($modal);
