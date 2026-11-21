<?php
require_once __DIR__ . '/lib.php';
$me = require_login();
$official = is_official($me);

function own_event(array $me, int $id): array {
    $ev = one('SELECT * FROM events WHERE id = ? AND barangay_id = ?', [$id, $me['barangay_id']]);
    if (!$ev) throw new UserError('Event not found in your barangay.');
    return $ev;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'join' && !$official) run(function () use ($me) {
        if (!$me['is_verified']) throw new UserError('Your account must be verified by a Barangay Official before you can join events.');
        tx(function () use ($me) {
            $ev = one('SELECT * FROM events WHERE id = ? AND barangay_id = ? FOR UPDATE', [post_int('event_id', 1), $me['barangay_id']]);
            if (!$ev) throw new UserError('Event not found.');
            if ($ev['status'] !== 'upcoming') throw new UserError('This event is no longer open.');
            if (val('SELECT 1 FROM event_participants WHERE user_id = ? AND event_id = ?', [$me['id'], $ev['id']])) throw new UserError('You already joined this event.');
            if ($ev['max_participants'] && val('SELECT COUNT(*) FROM event_participants WHERE event_id = ?', [$ev['id']]) >= $ev['max_participants'])
                throw new UserError('This event is full.');
            q('INSERT INTO event_participants (user_id, event_id, ticket_code) VALUES (?,?,?)', [$me['id'], $ev['id'], new_code('event_participants', 'ticket_code')]);
        });
    }, 'Joined! Your ticket code is shown under “My tickets”.');

    if ($action === 'leave' && !$official) run(function () use ($me) {
        $p = one("SELECT p.id, p.status, e.status AS es FROM event_participants p JOIN events e ON e.id = p.event_id WHERE p.user_id = ? AND p.event_id = ?", [$me['id'], post_int('event_id', 1)]);
        if (!$p) throw new UserError('You have not joined this event.');
        if ($p['status'] === 'attended') throw new UserError('You already attended this event.');
        if ($p['es'] !== 'upcoming') throw new UserError('This event is closed.');
        q('DELETE FROM event_participants WHERE id = ?', [$p['id']]);
    }, 'You left the event.');

    if ($official && $action === 'create') run(function () use ($me) {
        $title = post('title'); $loc = post('location');
        if ($title === '' || $loc === '' || post('description') === '') throw new UserError('Title, description and location are required.');
        $date = DateTime::createFromFormat('Y-m-d', post('date')); $time = post('time');
        if (!$date || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new UserError('Please choose a valid date and time.');
        $cat = post_int('category_id', 1);
        if (!val('SELECT 1 FROM event_categories WHERE id = ?', [$cat])) throw new UserError('Choose a category.');
        $max = post('max_participants') === '' ? null : post_int('max_participants', 1);
        q('INSERT INTO events (barangay_id, organizer_id, category_id, title, description, location, date, time, points_reward, max_participants) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$me['barangay_id'], $me['id'], $cat, $title, post('description'), $loc, $date->format('Y-m-d'), $time . ':00', post_int('points_reward', 0, 1000), $max]);
    }, 'Event created.');

    if ($official && in_array($action, ['complete', 'reopen', 'cancel'], true)) run(function () use ($me, $action) {
        $ev = own_event($me, post_int('event_id', 1));
        if ($action === 'complete') q("UPDATE events SET status='completed', completed_at=NOW() WHERE id=?", [$ev['id']]);
        elseif ($action === 'reopen') q("UPDATE events SET status='upcoming', completed_at=NULL WHERE id=?", [$ev['id']]);
        else q("UPDATE events SET status='cancelled' WHERE id=?", [$ev['id']]);
    }, 'Event updated.');

    if ($official && $action === 'delete') run(function () use ($me) {
        tx(function () use ($me) {
            $ev = own_event($me, post_int('event_id', 1));
            // Reverse the points that attendees earned from this event.
            foreach (all("SELECT user_id FROM event_participants WHERE event_id = ? AND status = 'attended'", [$ev['id']]) as $a) {
                q('UPDATE users SET points = GREATEST(points - ?, 0), peak_points = GREATEST(peak_points - ?, 0) WHERE id = ?', [$ev['points_reward'], $ev['points_reward'], $a['user_id']]);
                refresh_title((int)$a['user_id']);
            }
            q('DELETE FROM events WHERE id = ?', [$ev['id']]);
        });
    }, 'Event deleted and points reversed for attendees.');

    redirect('dashboard.php');
}

header_html($official ? 'Welcome, ' . ($me['first_name'] ?: $me['username']) : 'Welcome, ' . ($me['first_name'] ?: $me['username']),
    ($official ? 'Barangay Official of ' : 'Resident of ') . $me['barangay'],
    $official ? '' : '<span class="badge">' . icon('award') . (int)$me['points'] . ' pts</span>');
$events = all("SELECT e.*, c.name AS category,
        (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS joined
    FROM events e JOIN event_categories c ON c.id = e.category_id
    WHERE e.barangay_id = ? ORDER BY (e.status='upcoming') DESC, e.date, e.time", [$me['barangay_id']]);

if ($official):
    $cats = all('SELECT id, name FROM event_categories ORDER BY name'); ?>
  <h2 class="sect" style="margin-top:0">Events in <?= e($me['barangay']) ?></h2>
  <div class="card"><h2>Create an event</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="row">
        <div><label>Title</label><input name="title" maxlength="100" required></div>
        <div><label>Category</label><select name="category_id"><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <label>Description</label><textarea name="description" required></textarea>
      <div class="row">
        <div><label>Location</label><input name="location" maxlength="200" required></div>
        <div><label>Date</label><input type="date" name="date" required></div>
        <div><label>Time</label><input type="time" name="time" required></div>
        <div><label>Points reward</label><input type="number" name="points_reward" value="10" min="0" max="1000"></div>
        <div><label>Max participants (optional)</label><input type="number" name="max_participants" min="1"></div>
      </div>
      <p><button>Create event</button></p>
    </form>
  </div>
  <?php foreach ($events as $ev): ?>
    <div class="card">
      <h2><?= e($ev['title']) ?> <span class="tag <?= e($ev['status']) ?>"><?= e($ev['status']) ?></span></h2>
      <p class="muted"><?= e($ev['category']) ?> · <?= e($ev['date']) ?> <?= e(substr($ev['time'], 0, 5)) ?> · <?= e($ev['location']) ?> · <?= (int)$ev['points_reward'] ?> pts · <?= (int)$ev['joined'] ?><?= $ev['max_participants'] ? '/' . (int)$ev['max_participants'] : '' ?> joined</p>
      <p><?= nl2br(e($ev['description'])) ?></p>
      <a class="btn" href="event.php?id=<?= $ev['id'] ?>">Participants &amp; check-in</a>
      <?php foreach (['upcoming' => [['complete', 'Mark completed', ''], ['cancel', 'Cancel event', 'alt']], 'completed' => [['reopen', 'Reopen', 'alt']], 'cancelled' => [['reopen', 'Reopen', 'alt']]][$ev['status']] as [$act, $label, $cls]): ?>
        <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="event_id" value="<?= $ev['id'] ?>"><button class="<?= $cls ?>"><?= $label ?></button></form>
      <?php endforeach; ?>
      <form class="inline" method="post" onsubmit="return confirm('Delete this event? Points earned from it will be reversed.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="event_id" value="<?= $ev['id'] ?>"><button class="danger">Delete</button></form>
    </div>
  <?php endforeach; ?>
  <?php if (!$events): ?><p class="muted">No events yet.</p><?php endif; ?>
<?php else:
    $mine = [];
    foreach (all('SELECT * FROM event_participants WHERE user_id = ?', [$me['id']]) as $p) $mine[$p['event_id']] = $p;
    $reserved = reserved_points((int)$me['id']); ?>
  <?php if (!$me['is_verified']): ?><div class="msg warn"><b>Verification pending.</b> Your barangay is reviewing your account. You can join events once you're verified.</div><?php endif; ?>
  <div class="grid">
    <div class="card stat"><b><?= (int)$me['points'] ?></b>Points</div>
    <div class="card stat"><b><?= max($me['points'] - $reserved, 0) ?></b>Available (<?= $reserved ?> reserved)</div>
    <div class="card stat"><b style="font-size:18px"><?= e($me['title'] ?: '—') ?></b>Title</div>
  </div>
  <h2 class="sect">Events in <?= e($me['barangay']) ?></h2>
  <?php foreach ($events as $ev): $p = $mine[$ev['id']] ?? null; ?>
    <div class="card">
      <h2><?= e($ev['title']) ?> <span class="tag <?= e($ev['status']) ?>"><?= e($ev['status']) ?></span></h2>
      <p class="muted"><?= e($ev['category']) ?> · <?= e($ev['date']) ?> <?= e(substr($ev['time'], 0, 5)) ?> · <?= e($ev['location']) ?> · <b><?= (int)$ev['points_reward'] ?> pts</b><?= $ev['max_participants'] ? ' · ' . (int)$ev['joined'] . '/' . (int)$ev['max_participants'] . ' slots taken' : '' ?></p>
      <p><?= nl2br(e($ev['description'])) ?></p>
      <?php if ($p): ?>
        <p>My ticket: <span class="code"><?= e(fmt_code($p['ticket_code'])) ?></span> <span class="tag <?= e($p['status']) ?>"><?= e($p['status']) ?></span></p>
        <?php if ($p['status'] === 'joined' && $ev['status'] === 'upcoming'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leave"><input type="hidden" name="event_id" value="<?= $ev['id'] ?>"><button class="alt">Leave event</button></form>
        <?php endif; ?>
      <?php elseif ($ev['status'] === 'upcoming'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="join"><input type="hidden" name="event_id" value="<?= $ev['id'] ?>"><button>Join this event</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$events): ?><p class="muted">No events yet. Check back soon!</p><?php endif; ?>
<?php endif;
footer_html();
