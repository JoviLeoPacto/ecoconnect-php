<?php
require_once __DIR__ . '/lib.php';
$me = require_login();
$official = is_official($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    // Resident reserves a reward (points are deducted when the official hands it over).
    if (!$official && $action === 'redeem') run(function () use ($me) {
        if (!$me['is_verified']) throw new UserError('Your account must be verified before you can claim rewards.');
        $qty = post_int('quantity', 1);
        tx(function () use ($me, $qty) {
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
        });
    }, 'Reward reserved! Show your claim code to a Barangay Official to receive it.');

    if ($official && $action === 'add') run(function () use ($me) {
        if (post('name') === '') throw new UserError('Reward name is required.');
        q('INSERT INTO rewards (barangay_id, name, points_cost, stock, max_per_claim, icon) VALUES (?,?,?,?,?,?)',
          [$me['barangay_id'], post('name'), post_int('points_cost', 1), post_int('stock', 0), post_int('max_per_claim', 1), post('icon') ?: '🎁']);
    }, 'Reward added.');

    if ($official && in_array($action, ['restock', 'toggle'], true)) run(function () use ($me, $action) {
        $r = one('SELECT * FROM rewards WHERE id = ? AND barangay_id = ?', [post_int('reward_id', 1), $me['barangay_id']]);
        if (!$r) throw new UserError('Reward not found.');
        if ($action === 'restock') q('UPDATE rewards SET stock = ?, points_cost = ? WHERE id = ?', [post_int('stock', 0), post_int('points_cost', 1), $r['id']]);
        else q('UPDATE rewards SET is_active = 1 - is_active WHERE id = ?', [$r['id']]);
    }, 'Reward updated.');

    if ($official && $action === 'release') run(function () use ($me) {
        $c = release_claim(post_int('claim_id', 1), (int)$me['id']);
        return "Reward released: {$c['quantity']} × {$c['item_name']}. Points deducted.";
    }, '');

    if ($official && $action === 'release_code') run(function () use ($me) {
        $code = normalize_code(post('code'));
        $c = strlen($code) === 8 ? one('SELECT c.id FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE c.claim_code = ? AND u.barangay_id = ?', [$code, $me['barangay_id']]) : null;
        if (!$c) throw new UserError('That claim code is not valid in your barangay.');
        $c = release_claim((int)$c['id'], (int)$me['id']);
        return "Give {$c['quantity']} × {$c['item_name']} to the resident. Points deducted.";
    }, '');

    if ($official && $action === 'reject') run(function () use ($me) {
        tx(function () use ($me) {
            $c = one('SELECT c.*, u.barangay_id AS ub FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE c.id = ? FOR UPDATE', [post_int('claim_id', 1)]);
            if (!$c || $c['ub'] !== $me['barangay_id']) throw new UserError('Claim not found in your barangay.');
            if ($c['status'] !== 'Pending') throw new UserError('This claim is already ' . strtolower($c['status']) . '.');
            cancel_claim($c);
        });
    }, 'Claim rejected and the item was put back in stock.');

    redirect('rewards.php');
}

header_html('Rewards', $me['barangay'] . ($official ? ' · manage catalog and claims' : ' · spend your Eco-Points'), $official ? '' : '<span class="badge">' . icon('award') . (int)$me['points'] . ' pts</span>');
$rewards = all('SELECT * FROM rewards WHERE barangay_id = ?' . ($official ? '' : ' AND is_active = 1') . ' ORDER BY name', [$me['barangay_id']]);

if ($official):
    $claims = all("SELECT c.*, u.first_name, u.last_name, u.username FROM reward_claims c JOIN users u ON u.id = c.user_id WHERE u.barangay_id = ? ORDER BY (c.status='Pending') DESC, c.timestamp DESC LIMIT 100", [$me['barangay_id']]); ?>
  <div class="card"><h2>Release by claim code</h2>
    <form method="post" class="row" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="release_code">
      <div><label>Resident's 8-character claim code</label><input name="code" placeholder="ABCD-2345" required></div>
      <div><button>Release reward</button></div></form></div>
  <div class="card"><h2>Pending &amp; recent claims</h2>
  <table><tr><th>Resident</th><th>Item</th><th>Points</th><th>Code</th><th>Status</th><th></th></tr>
  <?php foreach ($claims as $c): ?>
    <tr><td><?= e(full_name($c)) ?></td><td><?= (int)$c['quantity'] ?> × <?= e($c['item_name']) ?></td><td><?= (int)$c['points_spent'] ?></td>
      <td><span class="code" style="font-size:14px"><?= e(fmt_code($c['claim_code'])) ?></span></td><td><span class="tag <?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
      <td><?php if ($c['status'] === 'Pending'): foreach (['release' => 'Release', 'reject' => 'Reject'] as $a => $l): ?>
        <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="claim_id" value="<?= $c['id'] ?>"><button class="<?= $a === 'reject' ? 'danger' : '' ?>"><?= $l ?></button></form>
      <?php endforeach; endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$claims): ?><tr><td colspan="6" class="muted">No claims yet.</td></tr><?php endif; ?>
  </table></div>
  <div class="card"><h2>Catalog</h2>
  <table><tr><th>Item</th><th>Cost &amp; stock</th><th></th></tr>
  <?php foreach ($rewards as $r): ?>
    <tr><td><?= e($r['icon']) ?> <?= e($r['name']) ?> <?= $r['is_active'] ? '' : '<span class="tag Rejected">hidden</span>' ?></td>
      <td><form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restock"><input type="hidden" name="reward_id" value="<?= $r['id'] ?>">
        <input type="number" name="points_cost" value="<?= (int)$r['points_cost'] ?>" min="1" style="width:90px"> pts
        <input type="number" name="stock" value="<?= (int)$r['stock'] ?>" min="0" style="width:90px"> in stock <button class="alt">Save</button></form></td>
      <td><form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="reward_id" value="<?= $r['id'] ?>"><button class="alt"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form></td></tr>
  <?php endforeach; ?>
  </table>
  <h2 style="margin-top:18px">Add a reward</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="row">
      <div><label>Name</label><input name="name" required maxlength="100"></div>
      <div><label>Icon (emoji)</label><input name="icon" value="🎁" maxlength="4"></div>
      <div><label>Points cost</label><input type="number" name="points_cost" value="50" min="1"></div>
      <div><label>Stock</label><input type="number" name="stock" value="10" min="0"></div>
      <div><label>Max per claim</label><input type="number" name="max_per_claim" value="1" min="1"></div>
    </div><p><button>Add reward</button></p></form></div>
<?php else:
    $reserved = reserved_points((int)$me['id']);
    $mine = all('SELECT * FROM reward_claims WHERE user_id = ? ORDER BY timestamp DESC', [$me['id']]); ?>
  <p class="muted">You have <b><?= (int)$me['points'] ?></b> points (<?= max($me['points'] - $reserved, 0) ?> available, <?= $reserved ?> reserved for pickup). Rewards and prices are set by your barangay.</p>
  <div class="grid">
  <?php foreach ($rewards as $r): ?>
    <div class="card"><h2><?= e($r['icon']) ?> <?= e($r['name']) ?></h2>
      <p><b><?= (int)$r['points_cost'] ?> pts</b> · <?= (int)$r['stock'] ?> in stock</p>
      <?php if ($r['stock'] > 0): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="redeem"><input type="hidden" name="reward_id" value="<?= $r['id'] ?>">
        <label>Quantity (max <?= (int)$r['max_per_claim'] ?>)</label><input type="number" name="quantity" value="1" min="1" max="<?= (int)$r['max_per_claim'] ?>">
        <p><button>Reserve</button></p></form>
      <?php else: ?><p class="muted">Out of stock</p><?php endif; ?></div>
  <?php endforeach; ?>
  </div>
  <?php if (!$rewards): ?><p class="muted">Your barangay has not added any rewards yet.</p><?php endif; ?>
  <div class="card"><h2>My claims</h2>
  <table><tr><th>Item</th><th>Points</th><th>Claim code</th><th>Status</th><th>Date</th></tr>
  <?php foreach ($mine as $c): ?>
    <tr><td><?= (int)$c['quantity'] ?> × <?= e($c['item_name']) ?></td><td><?= (int)$c['points_spent'] ?></td>
      <td><?= $c['status'] === 'Pending' ? '<span class="code" style="font-size:14px">' . e(fmt_code($c['claim_code'])) . '</span>' : '' ?></td>
      <td><span class="tag <?= e($c['status']) ?>"><?= e($c['status'] === 'Pending' ? 'Reserved' : $c['status']) ?></span></td><td class="muted"><?= e($c['timestamp']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$mine): ?><tr><td colspan="5" class="muted">No claims yet.</td></tr><?php endif; ?>
  </table></div>
<?php endif;
footer_html();
