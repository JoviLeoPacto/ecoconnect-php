<?php
require_once __DIR__ . '/lib.php';
$me = require_official();
$ev = one('SELECT * FROM events WHERE id = ? AND barangay_id = ?', [(int)($_GET['id'] ?? 0), $me['barangay_id']]);
if (!$ev) { flash('Event not found in your barangay.', 'err'); redirect('admin.php'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $title = post('title'); $loc = post('location'); $desc = post('description');
        if ($title === '' || $loc === '' || $desc === '') throw new UserError('Title, description and location are required.');
        $date = DateTime::createFromFormat('Y-m-d', post('date')); $time = substr(post('time'), 0, 5);
        if (!$date || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new UserError('Please choose a valid date and time.');
        $cat = post_int('category_id', 1); $max = post('max_participants') === '' ? null : post_int('max_participants', 1);
        if (!val('SELECT 1 FROM event_categories WHERE id = ?', [$cat])) throw new UserError('Choose a category.');
        q('UPDATE events SET title=?, description=?, location=?, date=?, time=?, category_id=?, points_reward=?, max_participants=? WHERE id=?', [$title, $desc, $loc, $date->format('Y-m-d'), $time . ':00', $cat, post_int('points_reward', 0, 1000), $max, $ev['id']]);
        flash('Event updated.'); redirect('admin.php');
    } catch (UserError $x) { flash($x->getMessage(), 'err'); redirect('edit-event.php?id=' . $ev['id']); }
}
$cats = array_column(all('SELECT id, name FROM event_categories ORDER BY name'), 'name', 'id');
simple_start('Edit event', $ev['title'], 'admin.php', 'Dashboard');
echo '<form method="post" class="eco-card space-y-5 p-5 sm:p-6">' . csrf() . field('Title', 'title', $ev['title'], 'required maxlength="100"') . area('Description', 'description', $ev['description'], 'required rows="3"')
  . '<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">' . select('Category', 'category_id', $cats, $ev['category_id'], 'required') . field('Points reward', 'points_reward', $ev['points_reward'], 'min="0" max="1000" required', '', 'number')
  . field('Date', 'date', $ev['date'], 'required', '', 'date') . field('Time', 'time', substr($ev['time'], 0, 5), 'required', '', 'time') . field('Max participants', 'max_participants', $ev['max_participants'], 'min="1" placeholder="No limit"', '', 'number') . '</div>'
  . '<div><div class="mb-1.5 flex items-baseline justify-between"><span class="eco-label mb-0">Location</span><span class="eco-meta" data-map-label>Click the map to move the pin</span></div><input type="hidden" name="location" id="evloc" value="' . e($ev['location']) . '"><div data-map-pick="evloc" class="relative z-0 h-64 overflow-hidden rounded-lg border border-slate-200"></div></div>'
  . '<div class="flex justify-end gap-2"><a href="admin.php" class="eco-btn eco-btn-outline">Cancel</a><button class="eco-btn eco-btn-green">Save changes</button></div></form>';
simple_end();
