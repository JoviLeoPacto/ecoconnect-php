<?php
require_once __DIR__ . '/lib.php';
if ($u = user()) redirect(home_for($u));

/* live numbers (shown only when there is something real to show) */
$stat = ['res' => 0, 'ev' => 0, 'pts' => 0, 'rw' => 0]; $heroes = []; $rw = []; $ev = null;
try {
    $stat['res'] = (int)val("SELECT COUNT(*) FROM users WHERE role='resident' AND is_verified=1");
    $stat['ev'] = (int)val('SELECT COUNT(*) FROM events');
    $stat['pts'] = (int)val("SELECT COALESCE(SUM(e.points_reward),0) FROM event_participants p JOIN events e ON e.id=p.event_id WHERE p.status='attended'") + (int)val('SELECT COALESCE(SUM(points),0) FROM point_awards');
    $stat['rw'] = (int)val("SELECT COUNT(*) FROM reward_claims WHERE status='Claimed'");
    $heroes = all("SELECT " . USER_COLS . ", b.name AS barangay FROM users u JOIN barangays b ON b.id=u.barangay_id WHERE u.role='resident' AND u.is_verified=1 ORDER BY u.points DESC, u.username LIMIT 5");
    $rw = all('SELECT name, icon, points_cost FROM rewards WHERE is_active=1 AND stock>0 ORDER BY points_cost LIMIT 4');
    $ev = one("SELECT title, date, points_reward FROM events WHERE status='upcoming' AND date>=CURDATE() ORDER BY date, time LIMIT 1");
} catch (Throwable $e) {}
$ev = $ev ?: ['title' => 'Barangay Clean-up Drive', 'date' => null, 'points_reward' => 20];
if (!$rw) $rw = [['name' => 'Rice (1 kg)', 'icon' => "\u{1F35A}", 'points_cost' => 40], ['name' => 'Tree seedling', 'icon' => "\u{1F331}", 'points_cost' => 25], ['name' => 'Eco bag', 'icon' => "\u{1F45C}", 'points_cost' => 60]];
$when = $ev['date'] ? date('D, M j', strtotime($ev['date'])) : 'Saturday · 7:00 AM';
$medals = ['🥇', '🥈', '🥉'];

page_head('EcoConnect · Clean up your barangay. Earn real rewards.');
$grad = 'background:radial-gradient(700px 420px at 100% 0%,rgba(74,222,128,.35),transparent 70%),linear-gradient(135deg,#052e16 0%,#14532d 55%,#15803d 100%)';
$btnW = 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-7 py-4 text-lg font-extrabold shadow-lg transition hover:-translate-y-0.5 hover:bg-brand-50';
?>
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur"><div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
  <?= brand_link('landing.php') ?>
    <div class="flex items-center gap-2"><?= theme_toggle() ?><a href="index.php" class="eco-btn eco-btn-outline">Log in</a></div></div></header>

<!-- hero -->
<section class="relative flex items-center overflow-hidden text-white" style="<?= $grad ?>;min-height:calc(100vh - 64px)"><div class="mx-auto grid w-full max-w-6xl items-center gap-12 px-5 py-12 sm:py-16 lg:grid-cols-[1.1fr_.9fr]">
  <div><h1 class="text-[40px] font-extrabold leading-[1.02] tracking-tight sm:text-6xl">Clean up your barangay.<br><span style="color:#86efac">Earn real rewards.</span></h1>
    <p class="mt-5 max-w-lg text-lg leading-relaxed text-white/85">Join cleanup drives, show your QR ticket, and watch your Eco-Points grow. Trade them for rewards at your barangay hall.</p>
    <ul class="mt-6 flex flex-wrap gap-2 text-sm font-semibold"><li class="rounded-full bg-white/15 px-3.5 py-1.5">🧹 Join drives</li><li class="rounded-full bg-white/15 px-3.5 py-1.5">🏅 Earn points</li><li class="rounded-full bg-white/15 px-3.5 py-1.5">🎁 Claim rewards</li></ul>
    <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3"><a href="register.php" class="<?= $btnW ?>" style="color:#14532d">Get started <?= ic('chev', 'h-5 w-5 -rotate-90') ?></a></div>
    <p class="mt-4 text-xs text-white/70"><?= $stat['res'] >= 3 ? 'Join ' . $stat['res'] . ' neighbors already earning Eco-Points. ' : '' ?>You’ll need a valid ID to sign up.</p></div>
  <div class="mx-auto w-full max-w-[380px] space-y-3 lg:mx-0 lg:ml-auto" aria-hidden="true">
    <div class="rounded-2xl bg-white p-5 text-slate-900 shadow-2xl"><p class="text-[11px] font-bold uppercase tracking-wider text-brand-700">Next cleanup drive</p><p class="mt-1 text-lg font-extrabold"><?= e($ev['title']) ?></p><p class="text-sm text-slate-500"><?= e($when) ?></p>
      <div class="mt-4 flex items-center justify-between"><span class="rounded-full bg-brand-100 px-3 py-1 text-sm font-bold text-brand-800">+<?= (int)$ev['points_reward'] ?> pts</span><span class="rounded-lg bg-brand-700 px-6 py-2.5 text-sm font-bold text-white">Join</span></div></div>
    <div class="rounded-2xl bg-white/95 p-4 text-slate-900 shadow-xl"><div class="flex items-center gap-3"><div class="grid h-12 w-12 place-items-center rounded-xl bg-slate-900 p-1.5"><?= str_repeat('<i class="m-px inline-block h-2.5 w-2.5 bg-white"></i>', 0) ?><?= ic('qr', 'h-8 w-8 text-white') ?></div><div><p class="text-sm font-extrabold">Your QR ticket</p><p class="text-xs text-slate-500">Show it at the event. Points land instantly.</p></div></div></div>
    <div class="rounded-2xl bg-white/95 p-4 text-slate-900 shadow-xl"><div class="flex items-baseline justify-between"><span class="text-2xl font-extrabold text-brand-800">45 pts</span><span class="text-xs text-slate-500">20 to your next title</span></div><div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full w-3/5 rounded-full bg-brand-600"></div></div></div>
  </div></div></section>

<?php page_foot();
