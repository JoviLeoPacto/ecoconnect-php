<?php
require_once __DIR__ . '/lib.php';
$me = require_official();
$bid = (int)$me['barangay_id'];
$own = fn(int $id) => one('SELECT * FROM events WHERE id = ? AND barangay_id = ?', [$id, $bid]) ?: throw new UserError('Event not found in your barangay.');
$resident = fn(int $id) => one("SELECT * FROM users WHERE id = ? AND role = 'resident' AND barangay_id = ?", [$id, $bid]) ?: throw new UserError('Resident not found in your barangay.');

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action'); $back = 'admin.php'; $msg = null;
    try {
        if ($a === 'create') {
            $title = post('title'); $loc = post('location'); $desc = post('description');
            if ($title === '' || $loc === '' || $desc === '') throw new UserError('Title, description and location are required.');
            $date = DateTime::createFromFormat('Y-m-d', post('date')); $time = post('time');
            if (!$date || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new UserError('Please choose a valid date and time.');
            $cat = post_int('category_id', 1);
            if (!val('SELECT 1 FROM event_categories WHERE id = ?', [$cat])) throw new UserError('Choose a category.');
            $max = post('max_participants') === '' ? null : post_int('max_participants', 1);
            q('INSERT INTO events (barangay_id, organizer_id, category_id, title, description, location, date, time, points_reward, max_participants) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$bid, $me['id'], $cat, $title, $desc, $loc, $date->format('Y-m-d'), $time . ':00', post_int('points_reward', 0, 1000), $max]);
            $msg = 'Event created.';
        } elseif (in_array($a, ['complete', 'reopen', 'cancel'], true)) {
            $ev = $own(post_int('event_id', 1));
            if ($a === 'complete') q("UPDATE events SET status='completed', completed_at=NOW() WHERE id=?", [$ev['id']]);
            elseif ($a === 'reopen') q("UPDATE events SET status='upcoming', completed_at=NULL WHERE id=?", [$ev['id']]);
            else q("UPDATE events SET status='cancelled' WHERE id=?", [$ev['id']]);
            $msg = 'Event updated.';
        } elseif ($a === 'delete') {
            tx(function () use ($own) {
                $ev = $own(post_int('event_id', 1));
                foreach (all("SELECT user_id FROM event_participants WHERE event_id = ? AND status = 'attended'", [$ev['id']]) as $x) {
                    q('UPDATE users SET points = GREATEST(points - ?, 0), peak_points = GREATEST(peak_points - ?, 0) WHERE id = ?', [$ev['points_reward'], $ev['points_reward'], $x['user_id']]);
                    refresh_title((int)$x['user_id']);
                }
                q('DELETE FROM events WHERE id = ?', [$ev['id']]);
            });
            $msg = 'Event deleted and points reversed for attendees.';
        } elseif ($a === 'verify') { q('UPDATE users SET is_verified = 1 WHERE id = ?', [$resident(post_int('user_id', 1))['id']]); $msg = 'Resident verified.'; $back = post('back') ?: 'admin.php?m=residents'; }
        elseif ($a === 'unverify') { q('UPDATE users SET is_verified = 0 WHERE id = ?', [$resident(post_int('user_id', 1))['id']]); $msg = 'Verification removed.'; $back = post('back') ?: 'admin.php?m=residents'; }
        elseif ($a === 'award') {
            $pts = post_int('points', 1, 1000);
            tx(function () use ($me, $resident, $pts) { $r = $resident(post_int('user_id', 1)); add_points((int)$r['id'], $pts);
                q('INSERT INTO point_awards (user_id, awarded_by, points, reason) VALUES (?,?,?,?)', [$r['id'], $me['id'], $pts, post('reason') ?: 'Direct award by official']); });
            $msg = 'Points awarded.'; $back = 'admin.php?m=resident&id=' . post_int('user_id', 1);
        } elseif ($a === 'move_resident') {
            $target = post_int('target_barangay_id', 1);
            if (!val('SELECT 1 FROM barangays WHERE id = ? AND is_participating = 1 AND id <> ?', [$target, $bid])) throw new UserError('Select a target barangay.');
            tx(function () use ($resident, $target) { $r = $resident(post_int('user_id', 1));
                foreach (all("SELECT * FROM reward_claims WHERE user_id = ? AND status = 'Pending' FOR UPDATE", [$r['id']]) as $c) cancel_claim($c);
                q('UPDATE users SET barangay_id = ?, points = 0 WHERE id = ?', [$target, $r['id']]); });
            $msg = 'Resident moved. Their points were reset for the new barangay.'; $back = 'admin.php?m=residents';
        } elseif ($a === 'transfer') {
            tx(function () use ($me, $bid) {
                $t = one("SELECT * FROM transfer_requests WHERE id = ? AND target_barangay_id = ? AND status = 'Pending' FOR UPDATE", [post_int('request_id', 1), $bid]);
                if (!$t) throw new UserError('Request not found or already decided.');
                if (post('decision') === 'Approved') {
                    foreach (all("SELECT * FROM reward_claims WHERE user_id = ? AND status = 'Pending' FOR UPDATE", [$t['user_id']]) as $c) cancel_claim($c);
                    q('UPDATE users SET barangay_id = ?, points = 0 WHERE id = ?', [$t['target_barangay_id'], $t['user_id']]);
                    q("UPDATE transfer_requests SET status='Approved', decided_by=?, decided_at=NOW() WHERE id=?", [$me['id'], $t['id']]);
                } else q("UPDATE transfer_requests SET status='Rejected', decided_by=?, decided_at=NOW() WHERE id=?", [$me['id'], $t['id']]);
            });
            $msg = 'Transfer decision saved.'; $back = 'admin.php?m=transfers';
        } elseif ($a === 'add_reward') {
            if (post('name') === '') throw new UserError('Reward name is required.');
            q('INSERT INTO rewards (barangay_id, name, points_cost, stock, max_per_claim, icon) VALUES (?,?,?,?,?,?)', [$bid, post('name'), post_int('points_cost', 1), post_int('stock', 0), post_int('max_per_claim', 1), post('icon') ?: '🎁']);
            $msg = 'Reward added.'; $back = 'admin.php?m=inventory';
        } elseif (in_array($a, ['restock', 'toggle'], true)) {
            $r = one('SELECT * FROM rewards WHERE id = ? AND barangay_id = ?', [post_int('reward_id', 1), $bid]);
            if (!$r) throw new UserError('Reward not found.');
            if ($a === 'restock') q('UPDATE rewards SET stock = ?, points_cost = ?, max_per_claim = ? WHERE id = ?', [post_int('stock', 0), post_int('points_cost', 1), post_int('max_per_claim', 1), $r['id']]);
            else q('UPDATE rewards SET is_active = 1 - is_active WHERE id = ?', [$r['id']]);
            $msg = 'Reward updated.'; $back = 'admin.php?m=inventory';
        } elseif ($a === 'release') { $c = release_claim(post_int('claim_id', 1), (int)$me['id']); $msg = "Reward released: {$c['quantity']} × {$c['item_name']}. Points deducted."; $back = 'admin.php?m=claims'; }
        elseif ($a === 'release_code') {
            $code = normalize_code(post('code'));
            $c = strlen($code) === 8 ? one('SELECT c.id FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE c.claim_code = ? AND u.barangay_id = ?', [$code, $bid]) : null;
            if (!$c) throw new UserError('That claim code is not valid in your barangay.');
            $c = release_claim((int)$c['id'], (int)$me['id']); $msg = "Give {$c['quantity']} × {$c['item_name']} to the resident. Points deducted."; $back = 'admin.php?m=claims';
        } elseif ($a === 'reject') {
            tx(function () use ($bid) {
                $c = one('SELECT c.*, u.barangay_id AS ub FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE c.id = ? FOR UPDATE', [post_int('claim_id', 1)]);
                if (!$c || $c['ub'] !== $bid) throw new UserError('Claim not found in your barangay.');
                if ($c['status'] !== 'Pending') throw new UserError('This claim is already ' . strtolower($c['status']) . '.');
                cancel_claim($c);
            });
            $msg = 'Claim rejected and the item was put back in stock.'; $back = 'admin.php?m=claims';
        } elseif ($a === 'profile') {
            $first = post('first_name'); $last = post('last_name'); $un = post('username'); $em = post('email'); $ph = preg_replace('/[\s-]/', '', post('phone_number'));
            $back = 'admin.php?m=profile';
            if ($first === '' || $last === '') throw new UserError('First and last name are required.');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $un)) throw new UserError('Username must be 3–30 characters: letters, numbers, dot, dash or underscore.');
            if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $em)) throw new UserError('Enter a valid email address.');
            if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $ph)) throw new UserError('Use a PH mobile number, e.g. 09171234567.');
            if (val('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$un, $me['id']])) throw new UserError('That username is already taken.');
            if (val('SELECT 1 FROM users WHERE phone_number = ? AND id <> ?', [$ph, $me['id']])) throw new UserError('That mobile number is already registered.');
            if (($_POST['password'] ?? '') !== '') { if ($e = pw_error($_POST['password'])) throw new UserError($e); q('UPDATE users SET password = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $me['id']]); }
            if (has_upload('profile_picture')) { if ($e = image_error($_FILES['profile_picture'])) throw new UserError($e); q('UPDATE users SET profile_picture = ? WHERE id = ?', [image_data_uri($_FILES['profile_picture']), $me['id']]); }
            q('UPDATE users SET first_name=?, last_name=?, username=?, email=?, phone_number=? WHERE id=?', [$first, $last, $un, $em, $ph, $me['id']]);
            $msg = 'Profile updated';
        } else throw new UserError('Unknown action.');
        if ($msg) flash($msg);
    } catch (UserError $ex) {
        flash($ex->getMessage(), 'err');
        if ($a === 'create') $back = 'admin.php?m=newevent'; elseif ($a === 'profile') $back = 'admin.php?m=profile&edit=1';
    }
    redirect($back);
}

/* ---------------- data ---------------- */
$m = $_GET['m'] ?? '';
$pendingRes = (int)val("SELECT COUNT(*) FROM users WHERE role='resident' AND barangay_id = ? AND is_verified = 0", [$bid]);
$pendingClaims = (int)val("SELECT COUNT(*) FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE u.barangay_id = ? AND c.status = 'Pending'", [$bid]);
$pendingTr = (int)val("SELECT COUNT(*) FROM transfer_requests WHERE target_barangay_id = ? AND status = 'Pending'", [$bid]);
$events = all("SELECT e.*, c.name AS category, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS joined, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id AND p.status = 'attended') AS attended
    FROM events e JOIN event_categories c ON c.id = e.category_id WHERE e.barangay_id = ? ORDER BY (e.status='upcoming') DESC, e.date DESC, e.time DESC", [$bid]);
$totalResidents = (int)val("SELECT COUNT(*) FROM users WHERE role='resident' AND barangay_id = ?", [$bid]);
$upcoming = count(array_filter($events, fn($e) => $e['status'] === 'upcoming'));
$ptsGiven = (int)val("SELECT COALESCE(SUM(e.points_reward),0) FROM event_participants p JOIN events e ON e.id = p.event_id WHERE e.barangay_id = ? AND p.status='attended'", [$bid]) + (int)val('SELECT COALESCE(SUM(a.points),0) FROM point_awards a JOIN users u ON u.id = a.user_id WHERE u.barangay_id = ?', [$bid]);

$items = [['Dashboard', 'admin.php', 'calendar', $m === ''], ['Activity log', 'admin.php?m=activity', 'history', $m === 'activity'], ['Leaderboard', 'admin.php?m=leaderboard', 'trophy', $m === 'leaderboard'],
    ['Statistics', 'admin.php?m=stats', 'chart', $m === 'stats'], ['Event reports', 'admin.php?m=reports', 'filecheck', $m === 'reports'],
    ['Residents', 'admin.php?m=residents', 'users', in_array($m, ['residents', 'resident'], true), $pendingRes], ['Rewards inventory', 'admin.php?m=inventory', 'package', $m === 'inventory'],
    ['Reward claims', 'admin.php?m=claims', 'bag', $m === 'claims', $pendingClaims], ['Transfer requests', 'admin.php?m=transfers', 'transfer', $m === 'transfers', $pendingTr],
    ['My profile', 'admin.php?m=profile', 'user', $m === 'profile'], ['Log out', 'logout.php', 'logout', false, 0, true]];
shell_start(['nav' => $items, 'home' => 'admin.php', 'footer_logout' => false, 'title' => 'Welcome, ' . first_name($me), 'subtitle' => 'Barangay Official of ' . $me['barangay'],
    'actions' => '<a href="admin.php?m=stats" class="eco-btn eco-btn-outline hidden sm:inline-flex">' . ic('chart', 'h-4 w-4') . ' Generate statistics</a><a href="admin.php?m=newevent" class="eco-btn eco-btn-green">' . ic('plus', 'h-4 w-4') . ' New event</a>']);

$statusBadge = ['upcoming' => 'green', 'completed' => 'slate', 'cancelled' => 'red'];
$filter = $_GET['s'] ?? 'all';
?>
<div class="space-y-6">
  <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <?= stat_card('users', 'Residents', $totalResidents, $pendingRes . ' awaiting verification', $pendingRes > 0) ?>
    <?= stat_card('calendar', 'Open events', $upcoming, count($events) . ' total') ?>
    <?= stat_card('award', 'Points given', $ptsGiven, 'From events and awards') ?>
    <?= stat_card('bag', 'Pending claims', $pendingClaims, 'Rewards to hand over', $pendingClaims > 0) ?>
  </div>
  <section>
    <?= section_header('Events', 'Create, manage and close cleanup drives in ' . $me['barangay'] . '.') ?>
    <div class="mb-3 flex flex-col gap-2 sm:flex-row">
      <div class="relative flex-1"><?= ic('search', 'pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400') ?><input type="search" data-filter="#evtable" placeholder="Search events" class="eco-input pl-10"></div>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1"><?php foreach (['all' => 'All', 'upcoming' => 'Open', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $l): ?>
        <a href="admin.php?s=<?= $k ?>" class="grid h-9 place-items-center rounded-md px-3 text-sm font-semibold <?= $filter === $k ? 'border border-slate-200 bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>"><?= $l ?></a><?php endforeach; ?></div>
    </div>
    <?php $shown = array_filter($events, fn($e) => $filter === 'all' || $e['status'] === $filter); ?>
    <?php if (!$shown): ?><?= empty_state('calendar', 'No events here', 'Create a cleanup drive to get residents involved.', '<a href="admin.php?m=newevent" class="eco-btn eco-btn-green">' . ic('plus', 'h-4 w-4') . ' New event</a>') ?>
    <?php else: ?><div class="eco-card overflow-x-auto"><table class="eco-table" id="evtable"><thead><tr><th>Event</th><th>Date</th><th>Points</th><th>Participants</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
      <?php foreach ($shown as $ev): $lim = (int)$ev['max_participants']; ?>
      <tr data-item><td><p class="font-semibold text-slate-900"><?= e($ev['title']) ?></p><p class="eco-meta"><?= e($ev['category']) ?></p></td>
        <td class="whitespace-nowrap"><?= ui_date($ev['date']) ?> · <?= ui_time($ev['time']) ?></td><td><?= (int)$ev['points_reward'] ?></td>
        <td><?= (int)$ev['joined'] . ($lim ? ' / ' . $lim : '') ?> <span class="eco-meta">(<?= (int)$ev['attended'] ?> attended)</span></td>
        <td><?= badge($statusBadge[$ev['status']] ?? 'slate', e(['upcoming' => 'Open', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$ev['status']])) ?></td>
        <td><div class="flex justify-end gap-1.5">
          <a href="manage-participants.php?id=<?= (int)$ev['id'] ?>" class="eco-btn eco-btn-outline eco-btn-sm"><?= ic('users', 'h-4 w-4') ?> Participants</a>
          <a href="edit-event.php?id=<?= (int)$ev['id'] ?>" class="eco-icon-btn" title="Edit" aria-label="Edit"><?= ic('edit', 'h-4 w-4') ?></a>
          <form method="post"><?= csrf($ev['status'] === 'upcoming' ? 'complete' : 'reopen') . hid('event_id', $ev['id']) ?><button class="eco-icon-btn" title="<?= $ev['status'] === 'upcoming' ? 'Mark done' : 'Reopen' ?>" aria-label="Toggle status"><?= ic($ev['status'] === 'upcoming' ? 'flag' : 'undo', 'h-4 w-4') ?></button></form>
          <form method="post" data-confirm-title="Delete this event?" data-confirm-text="Participants will be removed and any points they earned from it are taken back. This can't be undone." data-confirm-label="Delete event" data-confirm-danger="1"><?= csrf('delete') . hid('event_id', $ev['id']) ?><button class="eco-icon-btn text-red-600" title="Delete" aria-label="Delete"><?= ic('trash', 'h-4 w-4') ?></button></form>
        </div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
</div>

<?php
/* ---------------- modals ---------------- */
$close = 'admin.php'; $modal = '';
$li = fn(string $l, string $meta, string $r = '') => '<li class="flex items-center justify-between gap-3 px-4 py-3"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900">' . $l . '</p><p class="eco-meta">' . $meta . '</p></div>' . $r . '</li>';
$cb = ['Claimed' => 'green', 'Rejected' => 'red', 'Pending' => 'amber'];

if ($m === 'newevent') {
    $cats = array_column(all('SELECT id, name FROM event_categories ORDER BY name'), 'name', 'id');
    $body = '<form method="post" class="space-y-5">' . csrf('create') . field('Title', 'title', '', 'required maxlength="100"') . area('Description', 'description', '', 'required rows="3"')
        . '<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">' . select('Category', 'category_id', ['' => 'Choose a category'] + $cats, '', 'required') . field('Points reward', 'points_reward', 10, 'min="0" max="1000" required', '', 'number')
        . field('Date', 'date', '', 'required', '', 'date') . field('Time', 'time', '', 'required', '', 'time') . field('Max participants', 'max_participants', '', 'min="1" placeholder="No limit"', 'Leave blank for no limit.', 'number') . '</div>'
        . '<div><div class="mb-1.5 flex items-baseline justify-between"><span class="eco-label mb-0">Location</span><span class="eco-meta" data-map-label>Click the map to drop a pin</span></div>'
        . '<input type="hidden" name="location" id="evloc" value=""><div data-map-pick="evloc" class="relative z-0 h-64 overflow-hidden rounded-lg border border-slate-200"></div></div>'
        . '<div class="flex justify-end gap-2"><a href="admin.php" class="eco-btn eco-btn-outline">Cancel</a><button class="eco-btn eco-btn-green" data-needs-pin disabled>Create event</button></div></form>';
    $modal = modal('New event', 'Pick the spot where residents should meet.', $close, $body, '', 'lg');
}

if ($m === 'activity') {
    $rows = all("(SELECT p.attended_at AS t, CONCAT(u.first_name,' ',u.last_name) AS who, CONCAT('Checked in to ', e.title) AS what, e.points_reward AS pts FROM event_participants p JOIN users u ON u.id=p.user_id JOIN events e ON e.id=p.event_id WHERE e.barangay_id = ? AND p.status='attended')
        UNION ALL (SELECT c.claimed_at, CONCAT(u.first_name,' ',u.last_name), CONCAT('Received ', c.quantity, ' × ', c.item_name), -c.points_spent FROM reward_claims c JOIN users u ON u.id=c.user_id WHERE u.barangay_id = ? AND c.status='Claimed')
        UNION ALL (SELECT a.created_at, CONCAT(u.first_name,' ',u.last_name), a.reason, a.points FROM point_awards a JOIN users u ON u.id=a.user_id WHERE u.barangay_id = ?)
        UNION ALL (SELECT u.date_joined, CONCAT(u.first_name,' ',u.last_name), 'Signed up', NULL FROM users u WHERE u.role='resident' AND u.barangay_id = ?) ORDER BY t DESC LIMIT 60", [$bid, $bid, $bid, $bid]);
    if (!$rows) $body = empty_state('history', 'Nothing yet', 'Activity appears as residents join, check in and claim rewards.');
    else { $body = '<ul class="eco-card divide-y divide-slate-100">'; foreach ($rows as $r) $body .= $li(e(trim($r['who'])) . ' · ' . e($r['what']), e(date('n/j/Y g:i A', strtotime($r['t']))), $r['pts'] !== null ? badge($r['pts'] >= 0 ? 'green' : 'slate', ($r['pts'] >= 0 ? '+' : '') . (int)$r['pts'] . ' pts') : ''); $body .= '</ul>'; }
    $modal = modal('Activity log', 'Latest actions in your barangay.', $close, $body);
}

if ($m === 'leaderboard') {
    $lead = all("SELECT " . USER_COLS . " FROM users u WHERE u.role='resident' AND u.is_verified=1 AND u.barangay_id = ? ORDER BY u.points DESC, u.username LIMIT 50", [$bid]);
    if (!$lead) $body = empty_state('award', 'No rankings yet');
    else { $medals = ['🥇', '🥈', '🥉']; $body = '<ol class="eco-card divide-y divide-slate-100">'; foreach ($lead as $i => $l)
        $body .= '<li class="flex items-center gap-3 px-4 py-3"><span class="w-8 shrink-0 text-center text-sm font-semibold text-slate-500">' . ($medals[$i] ?? '#' . ($i + 1)) . '</span>' . user_avatar($l) . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($l)) . '</p><p class="truncate eco-meta">' . e($l['title'] ?: 'No title yet') . '</p></div><span class="text-sm font-bold text-brand-800">' . (int)$l['points'] . ' pts</span></li>';
        $body .= '</ol>'; }
    $modal = modal('Leaderboard', 'Top contributors in ' . $me['barangay'] . '.', $close, $body);
}

if ($m === 'stats' || $m === 'reports') {
    if ($m === 'stats') {
        $att = (int)val("SELECT COUNT(*) FROM event_participants p JOIN events e ON e.id=p.event_id WHERE e.barangay_id = ? AND p.status='attended'", [$bid]);
        $jn = (int)val('SELECT COUNT(*) FROM event_participants p JOIN events e ON e.id=p.event_id WHERE e.barangay_id = ?', [$bid]);
        $rec = (int)val("SELECT COUNT(*) FROM reward_claims c JOIN users u ON u.id=c.user_id WHERE u.barangay_id = ? AND c.status='Claimed'", [$bid]);
        $ver = (int)val("SELECT COUNT(*) FROM users WHERE role='resident' AND is_verified=1 AND barangay_id = ?", [$bid]);
        $body = '<div class="grid grid-cols-2 gap-3 sm:grid-cols-3">' . stat_card('users', 'Verified residents', $ver, $totalResidents . ' registered') . stat_card('calendar', 'Events held', count($events) - $upcoming, $upcoming . ' still open')
            . stat_card('check', 'Attendance rate', $jn ? round($att / $jn * 100) . '%' : '—', "$att of $jn tickets") . stat_card('award', 'Points given', $ptsGiven) . stat_card('bag', 'Rewards received', $rec) . stat_card('shield', 'Awaiting verification', $pendingRes) . '</div>';
        $modal = modal('Statistics', 'A summary of ' . $me['barangay'] . '.', $close, $body, '', 'lg');
    } else {
        $body = '<div class="eco-card overflow-x-auto"><table class="eco-table"><thead><tr><th>Event</th><th>Date</th><th>Status</th><th>Joined</th><th>Attended</th><th>Points</th></tr></thead><tbody>';
        foreach ($events as $ev) $body .= '<tr><td class="font-medium text-slate-900">' . e($ev['title']) . '</td><td>' . ui_date($ev['date']) . '</td><td>' . e($ev['status']) . '</td><td>' . (int)$ev['joined'] . '</td><td>' . (int)$ev['attended'] . '</td><td>' . (int)$ev['attended'] * (int)$ev['points_reward'] . '</td></tr>';
        $body .= $events ? '' : '<tr><td colspan="6" class="text-slate-500">No events yet.</td></tr>';
        $body .= '</tbody></table></div>';
        $modal = modal('Event reports', 'Attendance and points for every event.', $close, $body, '', 'lg');
    }
}

if ($m === 'residents' || $m === 'resident') {
    if ($m === 'resident' && ($r = one("SELECT " . USER_COLS . " FROM users u WHERE u.id = ? AND u.role='resident' AND u.barangay_id = ?", [(int)($_GET['id'] ?? 0), $bid]))) {
        $hist = all('SELECT e.title, e.date, p.status FROM event_participants p JOIN events e ON e.id = p.event_id WHERE p.user_id = ? ORDER BY e.date DESC LIMIT 10', [$r['id']]);
        $others = array_column(all('SELECT id, name FROM barangays WHERE is_participating = 1 AND id <> ? ORDER BY name', [$bid]), 'name', 'id');
        $body = '<div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">' . user_avatar($r, 'xl') . '<div class="min-w-0"><p class="truncate text-xl font-bold text-slate-900">' . e(full_name($r)) . '</p><p class="text-sm text-slate-500">@' . e($r['username']) . ' · ' . e($r['email'] ?: '') . ' · ' . e($r['phone_number']) . '</p><div class="mt-2 flex flex-wrap gap-1.5">'
            . badge($r['is_verified'] ? 'green' : 'amber', $r['is_verified'] ? 'Verified' : 'Awaiting verification') . badge('slate', (int)$r['points'] . ' pts') . ($r['title'] ? badge('slate', e($r['title'])) : '') . '</div></div></div>';
        if ($r['has_id']) $body .= '<section class="mt-6"><h3 class="eco-section-title">Valid ID</h3><img src="' . e(media_url($r, 'id')) . '" alt="Valid ID" class="mt-3 w-full max-w-sm rounded-lg border border-slate-200"></section>';
        $body .= '<div class="mt-5 flex gap-2"><form method="post">' . csrf($r['is_verified'] ? 'unverify' : 'verify') . hid('user_id', $r['id']) . hid('back', 'admin.php?m=resident&id=' . $r['id']) . '<button class="eco-btn ' . ($r['is_verified'] ? 'eco-btn-outline' : 'eco-btn-green') . '">' . ic('shield', 'h-4 w-4') . ($r['is_verified'] ? ' Remove verification' : ' Verify resident') . '</button></form></div>'
            . '<section class="mt-8"><h3 class="eco-section-title">Award points</h3><form method="post" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4">' . csrf('award') . hid('user_id', $r['id']) . field('Points', 'points', '', 'min="1" max="1000" required', '', 'number') . field('Reason', 'reason', '', 'placeholder="Direct award by official"', '', 'text', 'sm:col-span-2') . '<div class="flex items-end"><button class="eco-btn eco-btn-green w-full">Award</button></div></form></section>'
            . '<section class="mt-8"><h3 class="eco-section-title">Recent events</h3>';
        $rows = []; foreach ($hist as $h) $rows[] = $li(e($h['title']), ui_date($h['date']), badge($h['status'] === 'attended' ? 'green' : 'slate', $h['status'] === 'attended' ? 'Attended' : 'Joined'));
        $body .= $rows ? '<ul class="eco-card mt-3 divide-y divide-slate-100">' . implode('', $rows) . '</ul>' : '<p class="mt-2 text-sm text-slate-500">No events yet.</p>';
        $body .= '</section>';
        if ($others) $body .= '<section class="mt-8"><h3 class="eco-section-title">Transfer resident</h3><form method="post" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end" data-confirm-title="Move this resident?" data-confirm-text="Their points reset to 0 and reserved rewards are cancelled." data-confirm-label="Move resident">' . csrf('move_resident') . hid('user_id', $r['id'])
            . '<div class="flex-1">' . select('Move to', 'target_barangay_id', ['' => 'Select a barangay'] + $others, '', 'required') . '</div><button class="eco-btn eco-btn-outline">' . ic('transfer', 'h-4 w-4') . ' Transfer</button></form></section>';
        $modal = modal('Resident', '', 'admin.php?m=residents', $body, '', 'lg');
    } else {
        $f = $_GET['f'] ?? 'all';
        $list = all("SELECT " . USER_COLS . " FROM users u WHERE u.role='resident' AND u.barangay_id = ? ORDER BY u.is_verified, u.last_name, u.first_name", [$bid]);
        $list = array_values(array_filter($list, fn($x) => $f === 'all' || ($f === 'pending' ? !$x['is_verified'] : $x['is_verified'])));
        $body = '<div class="mb-4 flex flex-col gap-2 sm:flex-row"><div class="relative flex-1">' . ic('search', 'pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400') . '<input type="search" data-filter="#reslist" placeholder="Search residents" class="eco-input pl-10"></div><div class="flex gap-1 rounded-lg bg-slate-100 p-1">';
        foreach (['all' => 'All', 'pending' => 'Pending', 'verified' => 'Verified'] as $k => $l) $body .= '<a href="admin.php?m=residents&f=' . $k . '" class="grid h-9 place-items-center rounded-md px-3 text-sm font-semibold ' . ($f === $k ? 'border border-slate-200 bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800') . '">' . $l . '</a>';
        $body .= '</div></div>';
        if (!$list) $body .= empty_state('users', 'No residents found');
        else { $body .= '<div id="reslist" class="grid grid-cols-1 gap-3 sm:grid-cols-2">'; foreach ($list as $x)
            $body .= '<div data-item class="eco-card flex items-center gap-3 p-4">' . user_avatar($x) . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($x)) . '</p><p class="truncate eco-meta">@' . e($x['username']) . ' · ' . (int)$x['points'] . ' pts</p></div>'
                . ($x['is_verified'] ? badge('green', 'Verified') : '<form method="post">' . csrf('verify') . hid('user_id', $x['id']) . '<button class="eco-btn eco-btn-green eco-btn-sm">Verify</button></form>')
                . '<a href="admin.php?m=resident&id=' . (int)$x['id'] . '" class="eco-btn eco-btn-outline eco-btn-sm">View</a></div>'; $body .= '</div>'; }
        $modal = modal('Residents', $totalResidents . ' registered · ' . $pendingRes . ' awaiting verification', $close, $body, '', 'xl');
    }
}

if ($m === 'inventory') {
    $rw = all('SELECT * FROM rewards WHERE barangay_id = ? ORDER BY is_active DESC, name', [$bid]);
    $body = '<form method="post" class="eco-card mb-6 grid grid-cols-2 gap-3 p-4 sm:grid-cols-6">' . csrf('add_reward') . field('Icon', 'icon', '🎁', 'maxlength="4"') . field('Name', 'name', '', 'required maxlength="100"', '', 'text', 'col-span-2 sm:col-span-2') . field('Points', 'points_cost', 50, 'min="1" required', '', 'number') . field('Stock', 'stock', 10, 'min="0" required', '', 'number') . field('Max/claim', 'max_per_claim', 1, 'min="1" required', '', 'number')
        . '<div class="col-span-2 sm:col-span-6 flex justify-end"><button class="eco-btn eco-btn-green">' . ic('plus', 'h-4 w-4') . ' Add reward</button></div></form>';
    if (!$rw) $body .= empty_state('package', 'No rewards yet', 'Add the first reward residents can redeem.');
    else { $body .= '<div class="space-y-3">'; foreach ($rw as $r) $body .= '<div class="eco-card p-4 ' . ($r['is_active'] ? '' : 'opacity-60') . '"><div class="flex items-center gap-3"><span class="text-3xl">' . e($r['icon']) . '</span><div class="min-w-0 flex-1"><p class="font-semibold text-slate-900">' . e($r['name']) . '</p><p class="eco-meta">' . ($r['is_active'] ? 'Visible to residents' : 'Hidden') . '</p></div>'
        . '<form method="post">' . csrf('toggle') . hid('reward_id', $r['id']) . '<button class="eco-btn eco-btn-outline eco-btn-sm">' . ($r['is_active'] ? 'Hide' : 'Show') . '</button></form></div>'
        . '<form method="post" class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-4">' . csrf('restock') . hid('reward_id', $r['id']) . field('Points', 'points_cost', $r['points_cost'], 'min="1"', '', 'number') . field('Stock', 'stock', $r['stock'], 'min="0"', '', 'number') . field('Max/claim', 'max_per_claim', $r['max_per_claim'], 'min="1"', '', 'number') . '<div class="col-span-3 flex items-end sm:col-span-1"><button class="eco-btn eco-btn-green w-full">Save</button></div></form></div>'; $body .= '</div>'; }
    $modal = modal('Rewards inventory', 'Manage what residents can redeem.', $close, $body, '', 'lg');
}

if ($m === 'claims') {
    $cl = all("SELECT c.*, u.first_name, u.last_name, u.username FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE u.barangay_id = ? ORDER BY (c.status='Pending') DESC, c.timestamp DESC LIMIT 100", [$bid]);
    $body = '<form method="post" class="eco-card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">' . csrf('release_code') . '<div class="flex-1">' . field('Release by claim code', 'code', '', 'placeholder="ABCD-2345" required autocomplete="off"', 'Type the 8-character code from the resident’s QR screen.') . '</div><button class="eco-btn eco-btn-green">' . ic('qr', 'h-4 w-4') . ' Release</button></form>';
    if (!$cl) $body .= empty_state('bag', 'No claims yet');
    else { $body .= '<ul class="eco-card divide-y divide-slate-100">'; foreach ($cl as $c) { $pend = $c['status'] === 'Pending';
        $body .= '<li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900">' . ($c['quantity'] > 1 ? (int)$c['quantity'] . ' × ' : '') . e($c['item_name']) . ' · ' . e(full_name($c)) . '</p><p class="eco-meta">' . ui_date($c['timestamp']) . ' · ' . (int)$c['points_spent'] . ' pts · <span class="font-mono">' . e(fmt_code($c['claim_code'])) . '</span></p></div>'
            . ($pend ? '<div class="flex gap-2"><form method="post">' . csrf('release') . hid('claim_id', $c['id']) . '<button class="eco-btn eco-btn-green eco-btn-sm">Release</button></form><form method="post" data-confirm-title="Reject this claim?" data-confirm-text="The item goes back in stock and the points are freed." data-confirm-label="Reject" data-confirm-danger="1">' . csrf('reject') . hid('claim_id', $c['id']) . '<button class="eco-btn eco-btn-danger eco-btn-sm">Reject</button></form></div>'
                : badge($cb[$c['status']] ?? 'slate', e($c['status'] === 'Claimed' ? 'Received' : $c['status']))) . '</li>'; }
        $body .= '</ul>'; }
    $modal = modal('Reward claims', $pendingClaims . ' waiting for pickup', $close, $body, '', 'lg');
}

if ($m === 'transfers') {
    $in = all("SELECT t.*, u.first_name, u.last_name, u.username, u.points, b.name AS source FROM transfer_requests t JOIN users u ON u.id = t.user_id JOIN barangays b ON b.id = t.source_barangay_id WHERE t.target_barangay_id = ? ORDER BY (t.status='Pending') DESC, t.created_at DESC LIMIT 50", [$bid]);
    if (!$in) $body = empty_state('transfer', 'No transfer requests');
    else { $body = '<ul class="eco-card divide-y divide-slate-100">'; foreach ($in as $t)
        $body .= '<li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="text-sm font-medium text-slate-900">' . e(full_name($t)) . ' <span class="font-normal text-slate-500">from ' . e($t['source']) . '</span></p><p class="eco-meta">' . ui_date($t['created_at']) . ' · ' . (int)$t['points'] . ' pts' . ($t['reason'] ? ' · ' . e($t['reason']) : '') . '</p></div>'
            . ($t['status'] === 'Pending' ? '<div class="flex gap-2"><form method="post">' . csrf('transfer') . hid('request_id', $t['id']) . hid('decision', 'Approved') . '<button class="eco-btn eco-btn-green eco-btn-sm">Approve</button></form><form method="post">' . csrf('transfer') . hid('request_id', $t['id']) . hid('decision', 'Rejected') . '<button class="eco-btn eco-btn-danger eco-btn-sm">Reject</button></form></div>' : badge($t['status'] === 'Approved' ? 'green' : 'red', e($t['status']))) . '</li>';
        $body .= '</ul>'; }
    $modal = modal('Transfer requests', 'Residents asking to join ' . $me['barangay'] . '.', $close, $body, '', 'lg');
}

if ($m === 'profile') {
    if (!empty($_GET['edit'])) {
        $body = '<form method="post" enctype="multipart/form-data" class="space-y-5">' . csrf('profile') . '<div class="mb-6 flex items-center gap-4">' . user_avatar($me, 'lg') . '<label class="eco-btn eco-btn-outline eco-btn-sm cursor-pointer">Change profile photo<input type="file" name="profile_picture" class="sr-only" accept="image/jpeg,image/png,image/gif,image/webp"></label></div>'
            . '<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">' . field('First name', 'first_name', $me['first_name'], 'required') . field('Last name', 'last_name', $me['last_name'], 'required') . '</div>'
            . field('Username', 'username', $me['username'], 'required') . field('Email', 'email', $me['email'], 'required', '', 'email') . field('Mobile number', 'phone_number', $me['phone_number'], 'required')
            . field('New password', 'password', '', 'autocomplete="new-password" placeholder="Leave blank to keep your current password"', '', 'password')
            . '<div class="flex justify-end gap-2"><a href="admin.php?m=profile" class="eco-btn eco-btn-outline">Back</a><button class="eco-btn eco-btn-green">Save changes</button></div></form>';
        $modal = modal('Edit details', '', $close, $body);
    } else {
        $body = '<div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">' . user_avatar($me, 'xl') . '<div class="min-w-0"><p class="truncate text-xl font-bold text-slate-900">' . e(full_name($me)) . '</p><p class="text-sm text-slate-500">Barangay Official of ' . e($me['barangay']) . '</p></div></div><dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">';
        foreach ([['Username', $me['username']], ['Email', $me['email'] ?: '—'], ['Mobile number', $me['phone_number']], ['Barangay', $me['barangay']]] as [$t, $d]) $body .= '<div class="min-w-0"><dt class="eco-meta">' . $t . '</dt><dd class="truncate text-sm font-medium text-slate-900">' . e($d) . '</dd></div>';
        $modal = modal('My profile', '', $close, $body . '</dl>', '<a href="admin.php?m=profile&edit=1" class="eco-btn eco-btn-green">' . ic('user', 'h-4 w-4') . ' Edit details</a>');
    }
}
shell_end($modal);
