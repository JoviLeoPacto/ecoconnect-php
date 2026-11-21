<?php
require_once __DIR__ . '/lib.php';
$me = require_resident();
$bid = (int)$me['barangay_id'];

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $back = 'dashboard.php';

    if ($action === 'join') {
        $evId = (int)post('event_id');
        try {
            if (!$me['is_verified']) throw new UserError('Your account must be verified by a Barangay Official before you can join events.');
            tx(function () use ($me, $evId) {
                $ev = one('SELECT * FROM events WHERE id = ? AND barangay_id = ? FOR UPDATE', [$evId, $me['barangay_id']]);
                if (!$ev) throw new UserError('Event not found.');
                if ($ev['status'] !== 'upcoming') throw new UserError('This event is no longer open.');
                if (val('SELECT 1 FROM event_participants WHERE user_id = ? AND event_id = ?', [$me['id'], $ev['id']])) throw new UserError('You already joined this event.');
                if ($ev['max_participants'] && val('SELECT COUNT(*) FROM event_participants WHERE event_id = ?', [$ev['id']]) >= $ev['max_participants']) throw new UserError('This event is full.');
                q('INSERT INTO event_participants (user_id, event_id, ticket_code) VALUES (?,?,?)', [$me['id'], $ev['id'], new_code('event_participants', 'ticket_code')]);
            });
            flash('Joined! Show this ticket at the event.');
            $back = 'dashboard.php?m=ticket&id=' . $evId;
        } catch (UserError $ex) { flash($ex->getMessage(), 'err'); }
    }

    if ($action === 'leave') run(function () use ($me) {
        $p = one("SELECT p.id, p.status, e.status AS es FROM event_participants p JOIN events e ON e.id = p.event_id WHERE p.user_id = ? AND p.event_id = ?", [$me['id'], post_int('event_id', 1)]);
        if (!$p) throw new UserError('You have not joined this event.');
        if ($p['status'] === 'attended') throw new UserError('You already attended this event.');
        if ($p['es'] !== 'upcoming') throw new UserError('This event is closed.');
        q('DELETE FROM event_participants WHERE id = ?', [$p['id']]);
    }, 'You left the event.', 'dashboard.php');

    if ($action === 'redeem') {
        try {
            if (!$me['is_verified']) throw new UserError('Your account must be verified before you can claim rewards.');
            $qty = post_int('quantity', 1);
            $claimId = 0; $label = '';
            tx(function () use ($me, $qty, &$claimId, &$label) {
                $r = one('SELECT * FROM rewards WHERE id = ? FOR UPDATE', [post_int('reward_id', 1)]);
                $m = one('SELECT points FROM users WHERE id = ? FOR UPDATE', [$me['id']]);
                if (!$r || !$r['is_active'] || $r['barangay_id'] !== $me['barangay_id']) throw new UserError('This reward is not available in your barangay.');
                if ($qty > $r['max_per_claim']) throw new UserError("You can claim at most {$r['max_per_claim']} of this reward at a time.");
                if ($qty > $r['stock']) throw new UserError($r['stock'] ? "Only {$r['stock']} left in stock." : 'This item is out of stock.');
                $cost = $r['points_cost'] * $qty;
                $held = reserved_points((int)$me['id']);
                if ($m['points'] - $held < $cost) throw new UserError($held ? "Not enough available points. $held of your {$m['points']} points are reserved for rewards waiting for pickup." : 'Insufficient points.');
                q('UPDATE rewards SET stock = stock - ? WHERE id = ?', [$qty, $r['id']]);
                q('INSERT INTO reward_claims (user_id, reward_id, item_name, quantity, points_spent, claim_code) VALUES (?,?,?,?,?,?)',
                  [$me['id'], $r['id'], $r['name'], $qty, $cost, new_code('reward_claims', 'claim_code')]);
                $claimId = (int)db()->lastInsertId(); $label = $r['name'];
            });
            flash("$qty × $label reserved. Your points are held and only deducted when you pick it up with the QR code.");
            $back = 'dashboard.php?m=claim&id=' . $claimId;
        } catch (UserError $ex) { flash($ex->getMessage(), 'err'); $back = 'dashboard.php?m=rewards'; }
    }

    if ($action === 'transfer') {
        try {
            $target = post_int('target_barangay_id', 1);
            if (!val('SELECT 1 FROM barangays WHERE id = ? AND is_participating = 1', [$target])) throw new UserError('Select a target barangay');
            if ($target === $bid) throw new UserError('You are already in that barangay.');
            if (val("SELECT 1 FROM transfer_requests WHERE user_id = ? AND status = 'Pending'", [$me['id']])) throw new UserError('You already have a pending transfer request.');
            q('INSERT INTO transfer_requests (user_id, source_barangay_id, target_barangay_id, reason) VALUES (?,?,?,?)', [$me['id'], $bid, $target, post('reason') ?: null]);
            flash('Transfer request submitted. The new barangay will review it.');
        } catch (UserError $ex) { flash($ex->getMessage(), 'err'); }
        $back = 'dashboard.php?m=transfer';
    }

    if ($action === 'profile') {
        try {
            $first = post('first_name'); $last = post('last_name'); $un = post('username'); $em = post('email'); $ph = preg_replace('/[\s-]/', '', post('phone_number'));
            if ($first === '' || $last === '') throw new UserError('First and last name are required.');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $un)) throw new UserError('Username must be 3–30 characters: letters, numbers, dot, dash or underscore.');
            if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $em)) throw new UserError('Enter a valid email address.');
            if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $ph)) throw new UserError('Use a PH mobile number, e.g. 09171234567.');
            if (val('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$un, $me['id']])) throw new UserError('That username is already taken.');
            if (val('SELECT 1 FROM users WHERE phone_number = ? AND id <> ?', [$ph, $me['id']])) throw new UserError('That mobile number is already registered.');
            if ($_POST['password'] ?? '') { if ($e = pw_error($_POST['password'])) throw new UserError($e); q('UPDATE users SET password = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $me['id']]); }
            if (has_upload('profile_picture')) { if ($e = image_error($_FILES['profile_picture'])) throw new UserError($e); q('UPDATE users SET profile_picture = ? WHERE id = ?', [image_data_uri($_FILES['profile_picture']), $me['id']]); }
            q('UPDATE users SET first_name=?, last_name=?, username=?, email=?, phone_number=? WHERE id=?', [$first, $last, $un, $em, $ph, $me['id']]);
            flash('Profile updated');
            $back = 'dashboard.php?m=profile';
        } catch (UserError $ex) { flash($ex->getMessage(), 'err'); $back = 'dashboard.php?m=profile&edit=1'; }
    }

    if ($action === 'replace_id') {
        try {
            if ($e = image_error($_FILES['id_image'] ?? null)) throw new UserError($e);
            q('UPDATE users SET id_image = ?, is_verified = 0 WHERE id = ?', [image_data_uri($_FILES['id_image']), $me['id']]);
            flash('Your new ID was uploaded. Your barangay will verify your account again.');
        } catch (UserError $ex) { flash($ex->getMessage(), 'err'); }
        $back = 'dashboard.php?m=profile';
    }
    redirect($back);
}

/* ---------------- data ---------------- */
$m = $_GET['m'] ?? '';
$events = all("SELECT e.*, c.name AS category, b.name AS barangay_name, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS joined
    FROM events e JOIN event_categories c ON c.id = e.category_id JOIN barangays b ON b.id = e.barangay_id
    WHERE e.barangay_id = ? ORDER BY e.date DESC, e.time DESC", [$bid]);
$byId = array_column($events, null, 'id');
$mine = [];
foreach (all('SELECT * FROM event_participants WHERE user_id = ?', [$me['id']]) as $p) $mine[(int)$p['event_id']] = $p;
$isDone = fn(array $e) => $e['status'] !== 'upcoming';
$open = array_values(array_filter($events, fn($e) => !$isDone($e)));
usort($open, fn($a, $b) => [$a['date'], $a['time']] <=> [$b['date'], $b['time']]);
$finished = array_values(array_filter($events, $isDone));
$best = peak($me);
$ti = title_info($best);
$currentTitle = $ti['earned'] ? $ti['earned'][count($ti['earned']) - 1][1] : '';
$next = $ti['next'];
$attendedCount = count(array_filter($mine, fn($p) => $p['status'] === 'attended'));
$reserved = reserved_points((int)$me['id']);
$available = max($me['points'] - $reserved, 0);
$tips = ['Bring a reusable bag and bottle to every cleanup drive.', 'Separate biodegradable and recyclable waste before you throw it away.', 'Wear gloves and closed shoes when picking up litter.', 'Water your plants early in the morning to save water.', 'Invite a neighbor to your next cleanup drive: more hands, faster work.'];
$tip = $tips[(int)date('z') % count($tips)];

/* ---------------- page ---------------- */
$items = [['Home', 'dashboard.php', 'calendar', $m === '', 0, false, true], ['Rewards', 'dashboard.php?m=rewards', 'bag', in_array($m, ['rewards', 'redeem', 'claim'], true), 0, false, true],
          ['Tickets', 'dashboard.php?m=tickets', 'ticket', in_array($m, ['tickets', 'ticket'], true), 0, false, true], ['Profile', 'dashboard.php?m=profile', 'user', $m === 'profile', 0, false, true],
          ['Leaderboard', 'dashboard.php?m=leaderboard', 'trophy', $m === 'leaderboard'], ['Barangay officials', 'dashboard.php?m=officials', 'users', $m === 'officials'],
          ['Transfer barangay', 'dashboard.php?m=transfer', 'transfer', $m === 'transfer'], ['Log out', 'logout.php', 'logout', false, 0, true]];
shell_start(['nav' => $items, 'home' => 'dashboard.php', 'footer_logout' => false, 'title' => 'Welcome, ' . first_name($me), 'subtitle' => 'Resident of ' . $me['barangay'],
    'actions' => '<span class="eco-badge eco-badge-green text-sm py-1">' . ic('award', 'h-4 w-4') . ' ' . (int)$me['points'] . ' pts</span>']);

/* event row (EventItem) */
$eventItem = function (array $ev) use ($mine, $me, $isDone) {
    $p = $mine[(int)$ev['id']] ?? null; $joined = (bool)$p; $fin = $isDone($ev);
    $limit = (int)$ev['max_participants']; $count = (int)$ev['joined']; $full = $limit && $count >= $limit; $left = $limit ? max($limit - $count, 0) : null;
    $h = '<div class="eco-card flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><p class="font-semibold text-slate-900">' . e($ev['title']) . '</p>'
        . ($ev['category'] ? '<span class="eco-badge eco-badge-slate">' . e($ev['category']) . '</span>' : '')
        . ($fin ? '<span class="eco-badge eco-badge-slate">' . ic('flag', 'h-3 w-3') . ' Finished</span>' : ($full && !$joined ? '<span class="eco-badge eco-badge-red">Full</span>' : '')) . '</div>'
        . '<div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500"><span class="inline-flex items-center gap-1.5">' . ic('calendar', 'h-4 w-4') . ' ' . ui_date($ev['date']) . ' · ' . ui_time($ev['time']) . '</span>'
        . '<span class="inline-flex items-center gap-1.5 font-medium text-brand-800">' . ic('award', 'h-4 w-4') . ' ' . (int)$ev['points_reward'] . ' pts</span>'
        . '<span class="inline-flex items-center gap-1.5">' . ic('users', 'h-4 w-4') . ' ' . ($limit ? "$count / $limit joined" . ($left && $left <= 5 ? " · $left left" : '') : "$count joined") . '</span></div></div>'
        . '<div class="flex shrink-0 gap-2"><a href="dashboard.php?m=event&id=' . (int)$ev['id'] . '" class="eco-btn eco-btn-outline flex-1 sm:flex-none">Details</a>';
    $attended = '<span class="eco-btn flex-1 bg-brand-50 text-brand-800 sm:flex-none">' . ic('check', 'h-4 w-4') . ' Attended</span>';
    if ($fin) { if ($joined) $h .= $p['status'] === 'attended' ? $attended : '<span class="eco-btn flex-1 bg-slate-100 text-slate-600 sm:flex-none">Missed</span>'; }
    elseif ($joined) {
        $h .= $p['status'] === 'attended' ? $attended : '<a href="dashboard.php?m=ticket&id=' . (int)$ev['id'] . '" class="eco-btn eco-btn-green flex-1 sm:flex-none" title="Show this at the event">' . ic('ticket', 'h-4 w-4') . ' Ticket</a>';
        if ($p['status'] === 'joined') $h .= '<form method="post" data-confirm-title="Cancel participation?" data-confirm-text="You will be removed from this event. You can join again later." data-confirm-label="Cancel participation" data-confirm-cancel="Keep me in" data-confirm-danger="1">' . csrf('leave') . hid('event_id', $ev['id'])
            . '<button class="eco-btn eco-btn-danger" title="Cancel participation">' . ic('xcircle', 'h-4 w-4') . ' <span class="hidden sm:inline">Cancel</span></button></form>';
    } else $h .= '<form method="post" class="flex flex-1 sm:flex-none">' . csrf('join') . hid('event_id', $ev['id']) . '<button class="eco-btn eco-btn-green flex-1 sm:flex-none"' . (!$me['is_verified'] || $full ? ' disabled' : '') . '>' . ($full ? 'Full' : 'Join') . '</button></form>';
    return $h . '</div></div>';
};
?>
<div class="pb-20 lg:pb-0"><div class="space-y-6">
  <?php if (!$me['is_verified']): ?>
  <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4"><?= ic('shield', 'mt-0.5 h-5 w-5 shrink-0 text-amber-600') ?><div><p class="text-sm font-semibold text-amber-900">Verification pending</p><p class="text-sm text-amber-800">Your barangay is reviewing your ID. You can join events once you're verified.</p></div></div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <section><?= section_header('Cleanup drives', 'Open events in ' . $me['barangay'] . '.') ?>
        <?php if (!$open): ?><?= empty_state('calendar', $me['is_verified'] ? 'No open events' : 'Events appear after verification', $me['is_verified'] ? 'Your barangay hasn’t posted any new cleanup drives. Check back soon.' : 'Once your ID is approved you’ll see your barangay’s cleanup drives here.') ?>
        <?php else: ?><div class="space-y-3"><?php foreach ($open as $ev) echo $eventItem($ev); ?></div><?php endif; ?>
      </section>
      <?php if ($finished): ?><section><?= section_header('Finished events', 'These events are done and no longer accept participants.') ?><div class="space-y-3"><?php foreach ($finished as $ev) echo $eventItem($ev); ?></div></section><?php endif; ?>
    </div>
    <aside class="space-y-6">
      <div class="eco-card p-5"><div class="flex items-baseline justify-between"><h3 class="eco-section-title">Progress</h3><span class="text-sm font-semibold text-brand-800"><?= $best ?> / 500</span></div>
        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-600" style="width: <?= min($best / 500 * 100, 100) ?>%"></div></div>
        <a href="dashboard.php?m=rewards" class="eco-btn eco-btn-outline mt-4 w-full"><?= ic('bag', 'h-4 w-4') ?> Redeem rewards</a></div>
      <div class="eco-card p-5"><h3 class="eco-section-title mb-3">My drives</h3>
        <?php if (!$mine): ?><p class="text-sm text-slate-500">You haven’t joined any drives yet.</p><?php else: ?><ul class="divide-y divide-slate-100">
          <?php foreach ($mine as $p): $ev = $byId[(int)$p['event_id']] ?? null; ?>
          <li class="flex items-center justify-between gap-2 py-2.5"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900"><?= e($ev['title'] ?? 'Cleanup activity') ?></p><p class="eco-meta"><?= e($ev['date'] ?? 'Upcoming') ?></p></div>
            <?= badge($p['status'] === 'attended' ? 'green' : 'slate', $p['status'] === 'attended' ? 'Attended' : 'Joined') ?></li>
          <?php endforeach; ?></ul><?php endif; ?></div>
    </aside>
  </div>

  <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
    <?= stat_card('award', 'Eco-Points', (int)$me['points'], $next ? ($next[0] - $best) . ' pts to ' . $next[1] : 'Highest title reached') ?>
    <?= stat_card('calendar', 'Drives joined', count($mine), $attendedCount . ' attended') ?>
    <div class="col-span-2 sm:col-span-1"><?= stat_card('star', 'Current title', '<span class="text-lg sm:text-xl">' . e($currentTitle ?: 'None yet') . '</span>', 'Earn points to unlock titles') ?></div>
  </div>

  <div class="flex items-start gap-3 rounded-xl border border-brand-200 bg-brand-50 p-4"><?= ic('sparkles', 'mt-0.5 h-5 w-5 shrink-0 text-brand-700') ?><div><p class="text-sm font-semibold text-brand-900">Eco tip</p><p class="text-sm text-brand-900/80"><?= e($tip) ?></p></div></div>
</div></div>

<?php
/* ---------------- modals ---------------- */
$close = 'dashboard.php';
$listRow = fn(string $left, string $meta, string $right) => '<li class="flex items-center justify-between gap-3 px-4 py-3"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900">' . $left . '</p><p class="eco-meta">' . $meta . '</p></div>' . $right . '</li>';
$claimBadge = ['Claimed' => 'green', 'Rejected' => 'red', 'Pending' => 'amber'];
$claimLabel = ['Claimed' => 'Received', 'Pending' => 'Reserved'];
$claims = all('SELECT * FROM reward_claims WHERE user_id = ? ORDER BY timestamp DESC', [$me['id']]);
$modal = '';

if ($m === 'event' && ($ev = $byId[(int)($_GET['id'] ?? 0)] ?? null)) {
    $p = $mine[(int)$ev['id']] ?? null; $limit = (int)$ev['max_participants']; $count = (int)$ev['joined']; $full = $limit && $count >= $limit; $coords = parse_coords($ev['location']);
    $body = '<dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">';
    foreach ([['Date', ui_date($ev['date'])], ['Time', ui_time($ev['time']) ?: '—'], ['Points', (int)$ev['points_reward'] . ' pts'], ['Spots', $limit ? "$count / $limit joined" : "$count joined · no limit"]] as [$t, $d]) $body .= '<div><dt class="eco-meta">' . $t . '</dt><dd class="text-sm font-semibold text-slate-900">' . e($d) . '</dd></div>';
    $body .= '</dl><p class="mt-5 whitespace-pre-line text-sm leading-relaxed text-slate-700">' . e($ev['description']) . '</p><div class="mt-5"><div class="mb-1.5 flex items-baseline justify-between"><span class="eco-label mb-0">Location</span>'
        . '<a href="https://www.google.com/maps/search/?api=1&query=' . urlencode($coords ? $coords[0] . ',' . $coords[1] : $ev['location'] . ', Puerto Princesa') . '" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-brand-800 hover:underline">Get directions</a></div>';
    $body .= $coords ? '<div class="relative z-0 h-56 overflow-hidden rounded-lg border border-slate-200 sm:h-64" data-event-map="' . $coords[0] . ',' . $coords[1] . '" data-title="' . e($ev['title']) . '"></div>' : '<p class="rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-700">' . e($ev['location']) . '</p>';
    $body .= '</div>';
    $foot = '<a href="dashboard.php" class="eco-btn eco-btn-outline">Close</a>';
    if ($isDone($ev)) $foot .= '<span class="eco-btn bg-slate-100 text-slate-600">' . ic('flag', 'h-4 w-4') . ' This event is finished</span>';
    elseif ($p) $foot .= '<a href="dashboard.php?m=ticket&id=' . (int)$ev['id'] . '" class="eco-btn eco-btn-green">' . ic('ticket', 'h-4 w-4') . ' Show my ticket</a>';
    else $foot .= '<form method="post">' . csrf('join') . hid('event_id', $ev['id']) . '<button class="eco-btn eco-btn-green"' . (!$me['is_verified'] || $full ? ' disabled' : '') . '>' . ($full ? 'Event is full' : 'Join event') . '</button></form>';
    $modal = modal($ev['title'], $ev['category'], $close, $body, $foot, 'lg');
}

if ($m === 'ticket' && ($ev = $byId[(int)($_GET['id'] ?? 0)] ?? null) && ($p = $mine[(int)$ev['id']] ?? null)) {
    $att = $p['status'] === 'attended';
    $body = '<div class="flex flex-col items-center text-center"><p class="text-base font-bold tracking-tight text-slate-900">' . e($ev['title']) . '</p>'
        . '<p class="mt-1 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm text-slate-500"><span class="inline-flex items-center gap-1">' . ic('calendar', 'h-4 w-4') . ' ' . ui_date($ev['date']) . ' · ' . ui_time($ev['time']) . '</span><span>' . e($ev['barangay_name']) . '</span></p>'
        . '<div class="relative mt-4"><canvas data-qr="' . e($p['ticket_code']) . '" width="256" height="256" class="w-60 max-w-full rounded-lg border border-slate-200 bg-white sm:w-64 ' . ($att ? 'opacity-25' : '') . '"></canvas>'
        . ($att ? '<div class="absolute inset-0 grid place-items-center"><span class="eco-badge eco-badge-green px-3 py-1.5 text-sm">' . ic('check', 'h-4 w-4') . ' Checked in</span></div>' : '') . '</div>'
        . '<p class="mt-4 eco-meta">Ticket code</p><p class="font-mono text-2xl font-bold tracking-[0.15em] text-slate-900">' . e(fmt_code($p['ticket_code'])) . '</p><p class="mt-1 text-sm text-slate-500">' . e(full_name($me)) . '</p>'
        . (!$me['is_verified'] ? '<p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Your ID isn’t verified yet, so officials can’t check you in until it is.</p>' : '')
        . '<p class="mt-3 eco-meta">' . ($att ? 'You earned ' . (int)$ev['points_reward'] . ' points for this event.' : 'If the scan doesn’t work, the official can type the code instead. Turn up your screen brightness for faster scans.') . '</p></div>';
    $modal = modal('Event ticket', 'Show this to a barangay official when you arrive.', $close, $body, '', 'sm');
}

if ($m === 'tickets') {
    $rows = [];
    foreach ($mine as $p) $rows[] = [$p, $byId[(int)$p['event_id']] ?? []];
    usort($rows, fn($a, $b) => [$a[0]['status'] === 'attended', (string)($a[1]['date'] ?? '')] <=> [$b[0]['status'] === 'attended', (string)($b[1]['date'] ?? '')]);
    if (!$rows) $body = empty_state('ticket', 'No tickets yet', 'Join a cleanup drive and its ticket will appear here.');
    else { $body = '<ul class="eco-card divide-y divide-slate-100">'; foreach ($rows as [$p, $ev])
        $body .= $listRow(e($ev['title'] ?? 'Cleanup activity'), e(($ev['date'] ?? '') . ' · ' . ui_time($ev['time'] ?? '')),
            $p['status'] === 'attended' ? badge('green', ic('check', 'h-3.5 w-3.5') . ' Attended') : (($ev && $isDone($ev)) ? badge('slate', 'Event finished') : '<a href="dashboard.php?m=ticket&id=' . (int)$p['event_id'] . '" class="eco-btn eco-btn-green eco-btn-sm">' . ic('ticket', 'h-4 w-4') . ' Show ticket</a>'));
        $body .= '</ul>'; }
    $modal = modal('My tickets', 'Each event you join has its own ticket.', $close, $body);
}

if ($m === 'rewards') {
    $rw = all('SELECT * FROM rewards WHERE barangay_id = ? AND is_active = 1 ORDER BY name', [$bid]);
    $sub = $reserved > 0 ? "You have $available Eco-Points available. $reserved more are held for rewards waiting for pickup." : "You have $available Eco-Points to spend.";
    $body = '<div class="grid grid-cols-1 gap-6 md:grid-cols-3"><div class="md:col-span-2">';
    if (!$rw) $body .= empty_state('bag', 'No rewards yet', 'Your barangay hasn’t listed any rewards. Check back soon.');
    else { $body .= '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">'; foreach ($rw as $r) {
        $out = $r['stock'] <= 0; $short = $available < $r['points_cost'];
        $body .= '<div class="eco-card flex flex-col p-4 ' . ($out ? 'opacity-60' : '') . '"><div class="flex items-start gap-3"><span class="text-3xl leading-none" aria-hidden="true">' . e($r['icon']) . '</span><div class="min-w-0"><p class="font-semibold text-slate-900">' . e($r['name']) . '</p>'
            . '<p class="text-sm text-slate-500">' . (int)$r['points_cost'] . ' pts · ' . ($out ? 'Out of stock' : (int)$r['stock'] . ' left') . '</p>' . (!$out ? '<p class="eco-meta">Up to ' . (int)$r['max_per_claim'] . ' per claim</p>' : '') . '</div></div>'
            . ($out || $short || !$me['is_verified'] ? '<button disabled class="eco-btn eco-btn-green mt-4 w-full">' . ($out ? 'Out of stock' : ($short ? 'Need ' . ($r['points_cost'] - $available) . ' more pts' : 'Verify first')) . '</button>'
              : '<a href="dashboard.php?m=redeem&id=' . (int)$r['id'] . '" class="eco-btn eco-btn-green mt-4 w-full">Redeem</a>') . '</div>'; }
        $body .= '</div>'; }
    $body .= '</div><div><h3 class="eco-section-title mb-3">Recent claims</h3>';
    if (!$claims) $body .= '<p class="text-sm text-slate-500">No claims yet.</p>';
    else { $body .= '<ul class="eco-card divide-y divide-slate-100">'; foreach ($claims as $c)
        $body .= '<li class="px-4 py-3"><div class="flex items-start justify-between gap-2"><div class="min-w-0"><p class="truncate text-sm font-medium text-slate-900">' . ($c['quantity'] > 1 ? (int)$c['quantity'] . ' × ' : '') . e($c['item_name']) . '</p><p class="eco-meta">' . ui_date($c['timestamp']) . ' · ' . (int)$c['points_spent'] . ' pts</p></div>'
            . badge($claimBadge[$c['status']] ?? 'slate', e($claimLabel[$c['status']] ?? $c['status']), 'shrink-0') . '</div>'
            . ($c['status'] === 'Pending' ? '<a href="dashboard.php?m=claim&id=' . (int)$c['id'] . '" class="eco-btn eco-btn-green eco-btn-sm mt-2 w-full">' . ic('qr', 'h-4 w-4') . ' Show QR to claim</a>' : '') . '</li>';
        $body .= '</ul>'; }
    $body .= '</div></div>';
    $modal = modal('Rewards', $sub, $close, $body, '', 'xl');
}

if ($m === 'redeem' && ($r = one('SELECT * FROM rewards WHERE id = ? AND barangay_id = ? AND is_active = 1', [(int)($_GET['id'] ?? 0), $bid]))) {
    $cost = (int)$r['points_cost']; $limit = max(1, min((int)$r['max_per_claim'], (int)$r['stock'], intdiv($available, max($cost, 1))));
    $body = '<form method="post" id="redeemForm" data-cost="' . $cost . '" data-avail="' . $available . '">' . csrf('redeem') . hid('reward_id', $r['id']) . '<div class="flex items-center justify-between"><span class="eco-label mb-0">Quantity</span><div class="flex items-center gap-2">'
        . '<button type="button" data-qty="-1" class="eco-btn eco-btn-outline h-10 w-10 px-0" aria-label="Decrease quantity">' . ic('minus', 'h-4 w-4') . '</button><input type="number" name="quantity" value="1" min="1" max="' . $limit . '" readonly class="w-10 border-0 bg-transparent p-0 text-center text-lg font-bold text-slate-900 h-auto focus:ring-0" aria-live="polite">'
        . '<button type="button" data-qty="1" class="eco-btn eco-btn-outline h-10 w-10 px-0" aria-label="Increase quantity">' . ic('plus', 'h-4 w-4') . '</button></div></div>'
        . '<dl class="mt-5 space-y-2 rounded-lg bg-slate-50 p-4 text-sm"><div class="flex justify-between"><dt class="text-slate-500">Total cost</dt><dd class="font-semibold text-slate-900"><span data-total>' . $cost . '</span> pts</dd></div>'
        . '<div class="flex justify-between"><dt class="text-slate-500">Available after reserving</dt><dd class="font-semibold text-slate-900"><span data-after>' . ($available - $cost) . '</span> pts</dd></div></dl>'
        . '<p class="mt-3 eco-meta">Your points are held for this reward, not deducted yet. They’re only deducted when you pick up the item at your barangay hall with your QR code. If the barangay rejects the claim, the hold is released.</p>'
        . '<div class="mt-5 flex justify-end gap-2"><a href="dashboard.php?m=rewards" class="eco-btn eco-btn-outline">Cancel</a><button class="eco-btn eco-btn-green">Reserve for <span data-total2>' . $cost . '</span> pts</button></div></form>';
    $modal = modal('Redeem ' . $r['name'], $cost . ' pts each · up to ' . (int)$r['max_per_claim'] . ' per claim', 'dashboard.php?m=rewards', $body, '', 'sm');
}

if ($m === 'claim' && ($c = one('SELECT * FROM reward_claims WHERE id = ? AND user_id = ?', [(int)($_GET['id'] ?? 0), $me['id']]))) {
    $pending = $c['status'] === 'Pending';
    $body = '<div class="flex flex-col items-center text-center"><p class="text-base font-bold tracking-tight text-slate-900">' . ($c['quantity'] > 1 ? (int)$c['quantity'] . ' × ' : '') . e($c['item_name']) . '</p><p class="mt-1 text-sm text-slate-500">' . (int)$c['points_spent'] . ' pts</p>'
        . '<div class="relative mt-4"><canvas data-qr="' . e($c['claim_code']) . '" width="256" height="256" class="w-60 max-w-full rounded-lg border border-slate-200 bg-white sm:w-64 ' . ($pending ? '' : 'opacity-25') . '"></canvas></div>'
        . '<p class="mt-4 eco-meta">Claim code</p><p class="font-mono text-2xl font-bold tracking-[0.15em] text-slate-900">' . e(fmt_code($c['claim_code'])) . '</p>'
        . '<p class="mt-3">' . badge($claimBadge[$c['status']] ?? 'slate', e($claimLabel[$c['status']] ?? $c['status'])) . '</p>'
        . '<p class="mt-3 eco-meta">' . ($pending ? 'Show this to a barangay official to receive your reward. Your points are only deducted when it is released.' : 'This claim is no longer waiting for pickup.') . '</p></div>';
    $modal = modal('Reward claim', 'Show this QR code at your barangay hall.', 'dashboard.php?m=rewards', $body, '', 'sm');
}

if ($m === 'leaderboard') {
    $lead = all("SELECT " . USER_COLS . " FROM users u WHERE u.role = 'resident' AND u.is_verified = 1 AND u.barangay_id = ? ORDER BY u.points DESC, u.username LIMIT 50", [$bid]);
    $body = '<p class="mb-4 text-sm text-slate-500">Residents of Barangay ' . e($me['barangay']) . '</p>';
    if (!$lead) $body .= empty_state('award', 'No rankings yet', 'Join a cleanup drive and be the first on the board.');
    else { $medals = ['🥇', '🥈', '🥉']; $body .= '<ol class="eco-card divide-y divide-slate-100">'; foreach ($lead as $i => $l) { $isMe = (int)$l['id'] === (int)$me['id'];
        $body .= '<li class="flex items-center gap-3 px-4 py-3 ' . ($isMe ? 'bg-brand-50/60' : '') . '"><span class="w-8 shrink-0 text-center text-sm font-semibold text-slate-500">' . ($medals[$i] ?? '#' . ($i + 1)) . '</span>' . user_avatar($l)
            . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($l)) . ($isMe ? '<span class="ml-1.5 font-normal text-slate-500">(you)</span>' : '') . '</p><p class="truncate eco-meta">' . e($l['title'] ?: 'No title yet') . '</p></div>'
            . '<span class="text-sm font-bold text-brand-800">' . (int)$l['points'] . ' pts</span></li>'; }
        $body .= '</ol>'; }
    $modal = modal('Leaderboard', 'Top environmental contributors.', $close, $body);
}

if ($m === 'officials') {
    $off = all("SELECT " . USER_COLS . " FROM users u WHERE u.role = 'admin' AND u.barangay_id = ? ORDER BY u.first_name", [$bid]);
    if (!$off) $body = empty_state('users', 'No officials listed');
    else { $body = '<ul class="divide-y divide-slate-100">'; foreach ($off as $o) $body .= '<li class="flex items-center gap-3 py-3">' . user_avatar($o) . '<div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($o)) . '</p><p class="truncate eco-meta">' . e(implode(' · ', array_filter([$o['email'], $o['phone_number']])) ?: 'Barangay official') . '</p></div></li>'; $body .= '</ul>'; }
    $modal = modal('Barangay officials', 'Contact your barangay for verification or reward questions.', $close, $body);
}

if ($m === 'transfer') {
    $reqs = all('SELECT t.*, s.name AS source, d.name AS target FROM transfer_requests t JOIN barangays s ON s.id = t.source_barangay_id JOIN barangays d ON d.id = t.target_barangay_id WHERE t.user_id = ? ORDER BY t.created_at DESC', [$me['id']]);
    $pending = (bool)array_filter($reqs, fn($r) => $r['status'] === 'Pending');
    $others = all('SELECT id, name FROM barangays WHERE is_participating = 1 AND id <> ? ORDER BY name', [$bid]);
    $tb = ['Approved' => 'green', 'Rejected' => 'red', 'Pending' => 'amber'];
    if ($pending) $body = '<div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">' . ic('transfer', 'mt-0.5 h-5 w-5 shrink-0 text-amber-600') . '<p class="text-sm text-amber-900">You already have a pending request. You can send a new one after it’s approved or rejected.</p></div>';
    else $body = '<div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">' . ic('transfer', 'mt-0.5 h-5 w-5 shrink-0 text-amber-600') . '<p class="text-sm text-amber-900">Each barangay prices its rewards differently, so your points reset to 0 when a transfer is approved. Your title and history are kept, and any reserved rewards are cancelled.</p></div>'
        . '<form method="post" class="space-y-5">' . csrf('transfer') . '<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">' . field('Current barangay', 'x', $me['barangay'], 'disabled readonly')
        . select('Move to', 'target_barangay_id', ['' => 'Select a barangay'] + array_column($others, 'name', 'id'), '', 'required') . '</div>'
        . area('Reason', 'reason', '', 'placeholder="Why are you moving? (optional)" maxlength="500" rows="3"') . '<div class="flex justify-end"><button class="eco-btn eco-btn-green">Submit request</button></div></form>';
    $body .= '<h3 class="eco-section-title mb-3 mt-8">Request history</h3>';
    if (!$reqs) $body .= '<p class="text-sm text-slate-500">No requests yet.</p>';
    else { $body .= '<div class="eco-card divide-y divide-slate-100">'; foreach ($reqs as $r) $body .= '<div class="flex items-center justify-between gap-3 px-4 py-3"><div><p class="text-sm font-medium text-slate-900">To ' . e($r['target']) . '</p><p class="eco-meta">' . ui_date($r['created_at']) . '</p></div>' . badge($tb[$r['status']] ?? 'amber', e($r['status'])) . '</div>'; $body .= '</div>'; }
    $modal = modal('Transfer barangay', 'Ask to move your account to the barangay you now live in.', $close, $body, '', 'lg');
}

if ($m === 'profile') {
    $preview = media_url($me);
    if (!empty($_GET['edit'])) {
        $body = '<form method="post" enctype="multipart/form-data" class="space-y-5">' . csrf('profile') . '<div class="mb-6 flex items-center gap-4">' . avatar($preview, full_name($me), 'lg')
            . '<label class="eco-btn eco-btn-outline eco-btn-sm cursor-pointer">Change profile photo<input type="file" name="profile_picture" class="sr-only" accept="image/jpeg,image/png,image/gif,image/webp"></label></div>'
            . '<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">' . field('First name', 'first_name', $me['first_name'], 'required maxlength="50"') . field('Last name', 'last_name', $me['last_name'], 'required maxlength="50"') . '</div>'
            . field('Username', 'username', $me['username'], 'required', 'Used to sign in.') . field('Email', 'email', $me['email'], 'required', '', 'email') . field('Mobile number', 'phone_number', $me['phone_number'], 'required', 'e.g. 09171234567')
            . field('New password', 'password', '', 'autocomplete="new-password" placeholder="Leave blank to keep your current password"', '', 'password')
            . '<div class="flex justify-end gap-2 pt-1"><a href="dashboard.php?m=profile" class="eco-btn eco-btn-outline">Back to profile</a><button class="eco-btn eco-btn-green">Save changes</button></div></form>';
        $modal = modal('Edit details', 'Change your account information.', $close, $body);
    } else {
        $tab = $_GET['tab'] ?? 'events';
        $awards = all('SELECT a.*, u.first_name, u.last_name, u.username FROM point_awards a LEFT JOIN users u ON u.id = a.awarded_by WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 50', [$me['id']]);
        $transfers = all('SELECT t.*, s.name AS source, d.name AS target FROM transfer_requests t JOIN barangays s ON s.id = t.source_barangay_id JOIN barangays d ON d.id = t.target_barangay_id WHERE t.user_id = ? ORDER BY t.created_at DESC', [$me['id']]);
        $received = count(array_filter($claims, fn($c) => $c['status'] === 'Claimed'));
        $sb = ['Claimed' => 'green', 'Rejected' => 'red', 'Pending' => 'amber', 'Approved' => 'green'];
        $body = '<div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">' . avatar($preview, full_name($me), 'xl') . '<div class="min-w-0"><p class="truncate text-xl font-bold tracking-tight text-slate-900">' . e(full_name($me)) . '</p>'
            . '<p class="text-sm text-slate-500">Resident of ' . e($me['barangay']) . '</p><div class="mt-2 flex flex-wrap gap-1.5">'
            . badge($me['is_verified'] ? 'green' : 'amber', ic('shield', 'h-3.5 w-3.5') . ' ' . ($me['is_verified'] ? 'Verified resident' : 'Awaiting verification')) . ($currentTitle ? badge('slate', ic('star', 'h-3.5 w-3.5') . ' ' . e($currentTitle)) : '') . '</div></div></div>'
            . '<dl class="mt-6 grid grid-cols-3 gap-3">';
        foreach ([['Eco-Points', (int)$me['points']], ['Drives attended', $attendedCount], ['Rewards received', $received]] as [$l, $v]) $body .= '<div class="rounded-lg bg-slate-50 p-3 text-center"><dd class="text-xl font-bold text-slate-900">' . $v . '</dd><dt class="eco-meta">' . $l . '</dt></div>';
        $body .= '</dl><section class="mt-8"><h3 class="eco-section-title">Achievements</h3>';
        if ($next) $body .= '<div class="mt-3"><div class="flex justify-between text-sm"><span class="text-slate-600">Next: ' . TITLE_META[$next[1]][0] . ' ' . e($next[1]) . '</span><span class="font-medium text-slate-900">' . $best . ' / ' . $next[0] . '</span></div><div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-600" style="width: ' . min(100, $best / $next[0] * 100) . '%"></div></div></div>';
        if (!$ti['earned']) $body .= '<p class="mt-3 text-sm text-slate-500">No titles yet. Earn ' . min(array_keys(TITLE_THRESHOLDS)) . ' points to unlock your first one.</p>';
        else { $body .= '<ul class="mt-3 grid gap-2 sm:grid-cols-2">'; foreach ($ti['earned'] as [$pts, $t]) $body .= '<li class="flex items-start gap-2.5 rounded-lg border border-slate-200 px-3 py-2.5 text-sm"><span aria-hidden="true">' . TITLE_META[$t][0] . '</span><span class="min-w-0"><span class="flex flex-wrap items-baseline gap-x-2"><span class="font-medium text-slate-900">' . e($t) . '</span><span class="eco-meta">' . $pts . ' pts</span></span><span class="block text-xs leading-snug text-slate-500">' . e(TITLE_META[$t][1]) . '</span></span></li>'; $body .= '</ul>'; }
        $body .= '</section><section class="mt-8"><h3 class="eco-section-title">History</h3><div class="mt-3 flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1" role="tablist" aria-label="History">';
        foreach ([['events', 'Events', count($mine)], ['rewards', 'Rewards', count($claims)], ['bonus', 'Bonus points', count($awards)], ['transfers', 'Transfers', count($transfers)]] as [$k, $l, $n])
            $body .= '<a role="tab" aria-selected="' . ($tab === $k ? 'true' : 'false') . '" href="dashboard.php?m=profile&tab=' . $k . '" class="grid h-9 shrink-0 flex-1 place-items-center whitespace-nowrap rounded-md px-3 text-sm font-semibold transition-colors ' . ($tab === $k ? 'border border-slate-200 bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800') . '"><span>' . $l . ' <span class="font-normal text-slate-400">' . $n . '</span></span></a>';
        $body .= '</div><div class="mt-3">';
        $ul = fn(array $rows, string $empty) => $rows ? '<ul class="eco-card divide-y divide-slate-100">' . implode('', $rows) . '</ul>' : '<p class="text-sm text-slate-500">' . $empty . '</p>';
        if ($tab === 'events') { $rows = []; foreach ($mine as $p) { $ev = $byId[(int)$p['event_id']] ?? []; $rows[] = $listRow(e($ev['title'] ?? 'Cleanup activity'), e(($ev['date'] ?? '') . ($p['status'] === 'attended' && !empty($ev['points_reward']) ? ' · +' . $ev['points_reward'] . ' pts' : '')), badge($p['status'] === 'attended' ? 'green' : 'slate', $p['status'] === 'attended' ? 'Attended' : 'Joined')); } $body .= $ul($rows, 'You haven’t joined any events yet.'); }
        if ($tab === 'rewards') { $rows = []; foreach ($claims as $c) $rows[] = $listRow(($c['quantity'] > 1 ? (int)$c['quantity'] . ' × ' : '') . e($c['item_name']), ui_date($c['timestamp']) . ' · −' . (int)$c['points_spent'] . ' pts', badge($sb[$c['status']] ?? 'slate', e($c['status'] === 'Claimed' ? 'Received' : ($c['status'] === 'Pending' ? 'Reserved' : $c['status'])))); $body .= $ul($rows, 'No rewards claimed yet.'); }
        if ($tab === 'bonus') { $rows = []; foreach ($awards as $a) { $by = trim($a['first_name'] . ' ' . $a['last_name']) ?: $a['username']; $rows[] = $listRow(e($a['reason']), ui_date($a['created_at']) . ($by ? ' · from ' . e($by) : ''), badge('green', '+' . (int)$a['points'] . ' pts')); } $body .= $ul($rows, 'No bonus points yet.'); }
        if ($tab === 'transfers') { $rows = []; foreach ($transfers as $t) $rows[] = $listRow(e($t['source']) . ' → ' . e($t['target']), ui_date($t['created_at']), badge($sb[$t['status']] ?? 'amber', e($t['status']))); $body .= $ul($rows, 'No transfer requests.'); }
        $body .= '</div></section><section class="mt-8"><h3 class="eco-section-title">Personal details</h3><dl class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">';
        foreach ([['First name', $me['first_name'] ?: '—'], ['Last name', $me['last_name'] ?: '—'], ['Username', $me['username']], ['Email', $me['email'] ?: '—'], ['Mobile number', $me['phone_number'] ?: '—'], ['Barangay', $me['barangay'] ?: '—']] as [$t, $d])
            $body .= '<div class="min-w-0"><dt class="eco-meta">' . $t . '</dt><dd class="truncate text-sm font-medium text-slate-900">' . e($d) . '</dd></div>';
        $body .= '</dl></section><section class="mt-8"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="eco-section-title">Valid ID</h3><div class="flex gap-2">'
            . ($me['has_id'] ? '<a href="dashboard.php?m=profile&tab=' . e($tab) . '&showid=1" class="eco-btn eco-btn-ghost eco-btn-sm">' . ic('eye', 'h-4 w-4') . ' ' . (empty($_GET['showid']) ? 'View' : 'Hide') . '</a>' : '')
            . '<form method="post" enctype="multipart/form-data" data-autosubmit>' . csrf('replace_id') . '<label class="eco-btn eco-btn-outline eco-btn-sm cursor-pointer">Change ID photo<input type="file" name="id_image" class="sr-only" accept="image/jpeg,image/png,image/gif,image/webp" data-autosubmit-input></label></form></div></div>'
            . '<p class="mt-1 eco-meta">Changing your ID photo sends your account back to your barangay for verification.</p>'
            . (!empty($_GET['showid']) && $me['has_id'] ? '<div class="mt-3 max-w-sm"><img src="' . e(media_url($me, 'id')) . '" alt="My valid ID" class="w-full rounded-lg border border-slate-200"></div>' : '') . '</section>';
        $modal = modal('My profile', '', $close, $body, '<a href="dashboard.php?m=profile&edit=1" class="eco-btn eco-btn-green">' . ic('user', 'h-4 w-4') . ' Edit details</a>', 'lg');
    }
}

/* bottom bar (mobile) */
$quick = array_slice($items, 0, 4);
$bottom = '<nav class="fixed inset-x-0 bottom-0 z-40 flex items-center justify-around border-t border-slate-200 bg-white px-2 pt-1.5 pb-[max(0.375rem,env(safe-area-inset-bottom))] lg:hidden" aria-label="Quick">';
foreach ($quick as $q) $bottom .= '<a href="' . e($q[1]) . '"' . ($q[3] ? ' aria-current="page"' : '') . ' class="flex flex-1 flex-col items-center gap-0.5 rounded-lg py-1.5 text-[11px] ' . ($q[3] ? 'font-semibold text-brand-800' : 'text-slate-500') . '">' . ic($q[2], 'h-5 w-5') . ' ' . e($q[0]) . '</a>';
$bottom .= '</nav>';
shell_end($modal . $bottom);
