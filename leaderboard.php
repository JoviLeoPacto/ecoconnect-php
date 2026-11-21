<?php
require_once __DIR__ . '/lib.php';
$me = user(); if (!$me) redirect('index.php');
$bid = (int)$me['barangay_id'];
$lead = all("SELECT " . USER_COLS . " FROM users u WHERE u.role='resident' AND u.is_verified=1 AND u.barangay_id = ? ORDER BY u.points DESC, u.username LIMIT 50", [$bid]);
simple_start('Leaderboard', 'Top environmental contributors in ' . $me['barangay'], home_for($me), 'Back', '', 'max-w-2xl');
if (!$lead) echo empty_state('award', 'No rankings yet', 'Join a cleanup drive and be the first on the board.');
else { $medals = ['🥇', '🥈', '🥉']; echo '<ol class="eco-card divide-y divide-slate-100">'; foreach ($lead as $i => $l)
    echo '<li class="flex items-center gap-3 px-4 py-3"><span class="w-8 shrink-0 text-center text-sm font-semibold text-slate-500">' . ($medals[$i] ?? '#' . ($i + 1)) . '</span>' . user_avatar($l) . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($l)) . '</p><p class="truncate eco-meta">' . e($l['title'] ?: 'No title yet') . '</p></div><span class="text-sm font-bold text-brand-800">' . (int)$l['points'] . ' pts</span></li>';
    echo '</ol>'; }
simple_end();
