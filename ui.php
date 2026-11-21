<?php
// UI kit: PHP versions of the React components (AppShell, Modal, StatCard, AuthLayout, ...).
// They output the same Tailwind classes as the React site; styles are compiled into assets/app.css.
require_once __DIR__ . '/icons.php';

function ic(string $name, string $class = 'h-[18px] w-[18px]'): string {
    $p = ICONS[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="' . $class . '" aria-hidden="true">' . $p . '</svg>';
}
function mins(): string { return (string)@filemtime(__DIR__ . '/assets/app.css'); }
function media_url(?array $u, string $type = 'photo'): string {
    if (!$u) return '';
    if ($type === 'photo') return !empty($u['has_photo']) ? 'media.php?t=photo&u=' . (int)$u['id'] . '&v=' . urlencode((string)($u['photo_v'] ?? '')) : '';
    return !empty($u['has_id']) ? 'media.php?t=id&u=' . (int)$u['id'] . '&v=' . urlencode((string)($u['photo_v'] ?? '')) : '';
}
function avatar(?string $src, string $name, string $size = 'md'): string {
    $sizes = ['sm' => 'h-8 w-8 text-xs', 'md' => 'h-10 w-10 text-sm', 'lg' => 'h-16 w-16 text-lg', 'xl' => 'h-24 w-24 text-2xl'];
    $inner = $src ? '<img src="' . e($src) . '" alt="" class="h-full w-full object-cover">' : e(mb_strtoupper(mb_substr($name ?: '?', 0, 1)));
    return '<span class="' . $sizes[$size] . ' inline-grid shrink-0 place-items-center overflow-hidden rounded-full bg-brand-50 font-semibold text-brand-800 border border-slate-200">' . $inner . '</span>';
}
function user_avatar(array $u, string $size = 'md'): string { return avatar(media_url($u), full_name($u), $size); }
function badge(string $tone, string $html, string $extra = ''): string {
    return '<span class="eco-badge eco-badge-' . $tone . ($extra ? ' ' . $extra : '') . '">' . $html . '</span>';
}
function stat_card(string $icon, string $label, $value, string $hint = '', bool $alert = false): string {
    return '<div class="eco-card h-full p-4 sm:p-5' . ($alert ? ' border-red-200' : '') . '"><div class="flex items-start justify-between gap-2">'
        . '<p class="flex items-center gap-1.5 text-sm font-medium text-slate-500">' . ($alert ? '<span class="h-2 w-2 shrink-0 rounded-full bg-red-600" aria-label="Needs attention"></span>' : '') . e($label) . '</p>'
        . '<span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700 sm:h-9 sm:w-9">' . ic($icon, 'h-5 w-5') . '</span></div>'
        . '<p class="mt-2 text-2xl font-bold tracking-tight sm:mt-3 sm:text-3xl ' . ($alert ? 'text-red-600' : 'text-slate-900') . '">' . $value . '</p>'
        . ($hint !== '' ? '<p class="mt-1 eco-meta">' . e($hint) . '</p>' : '') . '</div>';
}
function empty_state(string $icon, string $title, string $text = '', string $action = ''): string {
    return '<div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-6 py-10 text-center">'
        . ($icon ? '<span class="mb-3 grid h-11 w-11 place-items-center rounded-lg bg-white text-slate-400 border border-slate-200">' . ic($icon, 'h-5 w-5') . '</span>' : '')
        . '<p class="text-sm font-semibold text-slate-800">' . e($title) . '</p>'
        . ($text !== '' ? '<p class="mt-1 max-w-sm text-sm text-slate-500">' . e($text) . '</p>' : '')
        . ($action !== '' ? '<div class="mt-4">' . $action . '</div>' : '') . '</div>';
}
function section_header(string $title, string $subtitle = '', string $action = ''): string {
    return '<div class="mb-4 flex flex-wrap items-end justify-between gap-3"><div><h2 class="eco-section-title">' . e($title) . '</h2>'
        . ($subtitle !== '' ? '<p class="mt-0.5 text-sm text-slate-500">' . e($subtitle) . '</p>' : '') . '</div>' . $action . '</div>';
}
function form_error(?string $m): string {
    return $m ? '<div role="alert" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700 mb-5">' . ic('alert', 'w-4 h-4 mt-0.5 shrink-0') . '<span>' . e($m) . '</span></div>' : '';
}
function csrf(string $action = ''): string { return csrf_field() . ($action !== '' ? '<input type="hidden" name="action" value="' . e($action) . '">' : ''); }
function hid(string $name, $value): string { return '<input type="hidden" name="' . e($name) . '" value="' . e((string)$value) . '">'; }
/** Text field in the React InputField style. $attrs is a raw attribute string. */
function field(string $label, string $name, $value = '', string $attrs = '', string $hint = '', string $type = 'text', string $class = ''): string {
    $id = 'f_' . preg_replace('/\W+/', '_', $name) . '_' . substr(md5($label . $attrs), 0, 4);
    return '<div class="' . $class . '"><label for="' . $id . '" class="eco-label">' . e($label) . '</label><input id="' . $id . '" name="' . e($name) . '" type="' . $type . '" class="eco-input" value="' . e((string)$value) . '" ' . $attrs . '>'
        . ($hint !== '' ? '<p class="mt-1.5 eco-meta">' . e($hint) . '</p>' : '') . '</div>';
}
function area(string $label, string $name, $value = '', string $attrs = '', string $hint = ''): string {
    $id = 'a_' . preg_replace('/\W+/', '_', $name);
    return '<div><label for="' . $id . '" class="eco-label">' . e($label) . '</label><textarea id="' . $id . '" name="' . e($name) . '" class="eco-textarea" ' . $attrs . '>' . e((string)$value) . '</textarea>'
        . ($hint !== '' ? '<p class="mt-1.5 eco-meta">' . e($hint) . '</p>' : '') . '</div>';
}
/** $options: [value => label] or [group => [value => label]] when $grouped. */
function select(string $label, string $name, array $options, $selected = '', string $attrs = '', string $hint = '', bool $grouped = false): string {
    $id = 's_' . preg_replace('/\W+/', '_', $name);
    $html = '';
    $opt = function (array $list) use (&$html, $selected) { foreach ($list as $v => $l) $html .= '<option value="' . e((string)$v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . e($l) . '</option>'; };
    if ($grouped) foreach ($options as $g => $list) { $html .= '<optgroup label="' . e($g) . '">'; $opt($list); $html .= '</optgroup>'; } else $opt($options);
    return '<div>' . ($label !== '' ? '<label for="' . $id . '" class="eco-label">' . e($label) . '</label>' : '') . '<div class="relative"><select id="' . $id . '" name="' . e($name) . '" class="eco-input appearance-none pr-10" ' . $attrs . '>' . $html . '</select>'
        . ic('chev', 'pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400') . '</div>' . ($hint !== '' ? '<p class="mt-1.5 eco-meta">' . e($hint) . '</p>' : '') . '</div>';
}
function password_field(string $label, string $name, string $attrs = '', string $aside = '', string $hint = ''): string {
    $id = 'p_' . preg_replace('/\W+/', '_', $name);
    return '<div><div class="flex items-baseline justify-between"><label for="' . $id . '" class="eco-label">' . e($label) . '</label>' . $aside . '</div><div class="relative"><input id="' . $id . '" name="' . e($name) . '" type="password" class="eco-input pr-11" ' . $attrs . '>'
        . '<button type="button" data-toggle-pw="' . $id . '" class="absolute inset-y-0 right-0 w-11 grid place-items-center text-slate-400 hover:text-slate-700 rounded-r-lg focus:outline-none focus-visible:text-brand-700" aria-label="Show password"><span data-eye>' . ic('eye', 'w-[18px] h-[18px]') . '</span><span data-eye-off class="hidden">' . ic('eyeoff', 'w-[18px] h-[18px]') . '</span></button></div>'
        . ($hint !== '' ? '<p class="mt-1.5 text-xs text-slate-500">' . e($hint) . '</p>' : '') . '</div>';
}
function theme_toggle(): string {
    return '<button type="button" data-theme-toggle class="eco-icon-btn" aria-label="Switch theme" title="Switch theme"><span class="dark:hidden">' . ic('moon') . '</span><span class="hidden dark:inline">' . ic('sun') . '</span></button>';
}
function brand_link(string $href, string $extra = ''): string {
    return '<a href="' . e($href) . '" class="inline-flex items-center gap-2 ' . $extra . '"><span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-700 text-white">' . ic('leaf', 'h-5 w-5') . '</span><span class="text-base font-bold tracking-tight text-slate-900">EcoConnect</span></a>';
}

/* ---------- page frame ---------- */
function page_head(string $title): void {
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
        . '<link rel="icon" type="image/svg+xml" href="assets/ecoconnect.svg"><link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png"><link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png"><link rel="manifest" href="assets/manifest.webmanifest">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><meta name="theme-color" content="#15803d">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="assets/app.css?v=' . mins() . '">'
        . '<script>(function(){try{var t=localStorage.getItem("ecoconnect_theme");if(t!=="light"&&t!=="dark"){t=window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}if(t==="dark")document.documentElement.classList.add("dark")}catch(e){}})();</script>'
        . '<title>' . e($title) . '</title></head><body>';
}
function toasts_html(): string {
    $out = '';
    foreach (flash() as [$type, $msg]) {
        $err = $type === 'err';
        $out .= '<div data-toast class="eco-toast-in pointer-events-auto relative overflow-hidden flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-lg shadow-slate-900/5">'
            . '<span class="absolute left-0 top-0 bottom-0 w-1 ' . ($err ? 'bg-red-500' : 'bg-brand-600') . '" aria-hidden="true"></span>'
            . ic($err ? 'alert' : 'check', 'w-5 h-5 mt-0.5 shrink-0 ' . ($err ? 'text-red-600' : 'text-brand-700'))
            . '<p class="flex-1 text-sm font-medium text-slate-800 leading-snug">' . e($msg) . '</p>'
            . '<button type="button" data-toast-close class="p-0.5 -mr-1 text-slate-400 hover:text-slate-700 rounded" aria-label="Dismiss notification">' . ic('x', 'w-4 h-4') . '</button></div>';
    }
    return '<div class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-4 z-[3000] flex flex-col gap-2 sm:w-96 pointer-events-none" role="status" aria-live="polite">' . $out . '</div>';
}
function page_foot(string $extra = ''): void {
    echo toasts_html() . $extra . '<script src="assets/app.js?v=' . (string)@filemtime(__DIR__ . '/assets/app.js') . '"></script></body></html>';
}

/* ---------- Modal (server-rendered: closing goes back to $closeHref; $closeHref = '' keeps it client-side) ---------- */
function modal(string $title, string $subtitle, string $closeHref, string $body, string $footer = '', string $size = 'md', string $id = '', bool $hidden = false): string {
    $widths = ['sm' => 'max-w-md', 'md' => 'max-w-xl', 'lg' => 'max-w-3xl', 'xl' => 'max-w-5xl'];
    return '<div ' . ($id ? 'id="' . e($id) . '" ' : '') . 'data-modal' . ($closeHref !== '' ? ' data-close-href="' . e($closeHref) . '"' : '') . ' class="' . ($hidden ? 'hidden ' : 'flex ') . 'fixed inset-0 items-end sm:items-center justify-center bg-slate-900/40 p-0 sm:p-4" style="z-index:1001" role="dialog" aria-modal="true" aria-label="' . e($title) . '">'
        . '<div class="flex w-full ' . $widths[$size] . ' max-h-[92vh] flex-col overflow-hidden rounded-t-2xl sm:rounded-xl border border-slate-200 bg-white shadow-xl">'
        . '<div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6"><div class="min-w-0"><h2 class="text-lg font-bold tracking-tight text-slate-900">' . e($title) . '</h2>'
        . ($subtitle !== '' ? '<p class="mt-0.5 text-sm text-slate-500">' . e($subtitle) . '</p>' : '') . '</div>'
        . '<button type="button" data-modal-close class="eco-icon-btn -mr-2 shrink-0" aria-label="Close">' . ic('x', 'h-5 w-5') . '</button></div>'
        . '<div class="flex-1 overflow-y-auto px-5 py-5 sm:px-6">' . $body . '</div>'
        . ($footer !== '' ? '<div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">' . $footer . '</div>' : '')
        . '</div></div>';
}
/** Buffer helper: capture the output of $fn as a string. */
function html(callable $fn): string { ob_start(); $fn(); return (string)ob_get_clean(); }

/* ---------- AuthLayout ---------- */
function privacy_modal(): string {
    $sections = [
        ['1. Information we collect', 'To run community cleanup drives and verify residency, we collect:', ['Name and contact details', 'Barangay of residence', 'A valid ID (for verification)', 'Event attendance and Eco-Points records']],
        ['2. Why we process it', '', ['To verify that you live in a Puerto Princesa barangay.', 'To organize cleanup drives and keep participants safe.', 'To record, credit and redeem Eco-Points.', 'To send notices about barangay activities.']],
        ['3. Confidentiality and protection', 'Only authorized barangay officials and system administrators can see your personal data and ID. We do not sell, trade or share your data with unauthorized third parties or businesses.', []],
        ['4. Your rights under RA 10173', '', ['Be informed about how your data is processed', 'Access your personal records', 'Correct inaccurate information', 'Ask for your data to be removed or blocked']],
    ];
    $b = '<p class="text-sm leading-relaxed text-slate-600">EcoConnect follows the Philippine Data Privacy Act of 2012 and its implementing rules. This notice explains what we collect, why, and how we protect it.</p><div class="mt-6 space-y-6">';
    foreach ($sections as [$t, $body, $list]) {
        $b .= '<section><h3 class="text-sm font-semibold text-slate-900">' . e($t) . '</h3>' . ($body ? '<p class="mt-1.5 text-sm leading-relaxed text-slate-600">' . e($body) . '</p>' : '');
        if ($list) { $b .= '<ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600 marker:text-brand-600">'; foreach ($list as $i) $b .= '<li>' . e($i) . '</li>'; $b .= '</ul>'; }
        $b .= '</section>';
    }
    $b .= '</div>';
    return modal('Data Privacy Notice', 'Republic Act No. 10173 — Data Privacy Act of 2012', '', $b,
        '<button type="button" data-modal-close class="eco-btn eco-btn-outline">Close</button><button type="button" data-accept-privacy data-modal-close class="eco-btn eco-btn-green">I understand and accept</button>', 'lg', 'privacy', true);
}
function auth_start(string $title, string $subtitle = '', string $aside = '', string $back = ''): void {
    page_head($title . ' · EcoConnect');
    echo '<div class="min-h-screen bg-white flex flex-col"><header class="px-5 sm:px-8 pt-6 flex items-center justify-between">'
        . brand_link('index.php', 'rounded-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/20')
        . '<div class="flex items-center gap-3">' . $back . theme_toggle() . '</div></header>'
        . '<main class="flex-1 flex items-start sm:items-center justify-center px-5 py-10"><div class="' . ($aside ? 'grid w-full max-w-[400px] items-center gap-10 lg:max-w-[920px] lg:grid-cols-[400px_1fr] lg:gap-16' : 'w-full max-w-[400px]') . '"><div class="w-full">'
        . '<div class="text-center mb-8"><h1 class="text-[28px] sm:text-[32px] leading-tight font-bold tracking-tight text-slate-900">' . e($title) . '</h1>'
        . ($subtitle !== '' ? '<p class="mt-2 text-sm text-slate-500">' . e($subtitle) . '</p>' : '') . '</div>';
    $GLOBALS['__aside'] = $aside;
}
function auth_end(string $footer = ''): void {
    echo ($footer !== '' ? '<div class="mt-6 text-center text-sm text-slate-600">' . $footer . '</div>' : '') . '</div>' . ($GLOBALS['__aside'] ?? '') . '</div></main>'
        . '<footer class="px-5 pb-8 text-center text-xs leading-relaxed text-slate-500">By continuing you agree to our <button type="button" data-modal-open="privacy" class="eco-link font-medium">Terms &amp; Data Privacy Policy</button><br class="hidden sm:block"> in line with the Data Privacy Act of 2012 (RA 10173).</footer></div>';
    page_foot(privacy_modal());
}

/* ---------- SimplePage (header + centred column) ---------- */
function simple_start(string $title, string $subtitle = '', string $backTo = '', string $backLabel = 'Back', string $actions = '', string $width = 'max-w-3xl'): void {
    page_head($title . ' · EcoConnect');
    $u = user();
    echo '<div class="min-h-screen bg-slate-50"><header class="border-b border-slate-200 bg-white"><div class="mx-auto flex h-16 ' . $width . ' items-center justify-between px-4 sm:px-6">'
        . brand_link($u ? home_for($u) : 'index.php') . '<div class="flex items-center gap-3">'
        . ($backTo ? '<a href="' . e($backTo) . '" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">' . ic('back', 'h-4 w-4') . ' ' . e($backLabel) . '</a>' : '')
        . theme_toggle() . '</div></div></header><main class="mx-auto ' . $width . ' px-4 py-8 sm:px-6"><div class="mb-6 flex flex-wrap items-end justify-between gap-3"><div>'
        . '<h1 class="text-2xl font-bold tracking-tight text-slate-900">' . e($title) . '</h1>' . ($subtitle !== '' ? '<p class="mt-1 text-sm text-slate-500">' . e($subtitle) . '</p>' : '') . '</div>' . $actions . '</div>';
}
function simple_end(string $extra = ''): void { echo '</main></div>'; page_foot($extra); }

/* ---------- AppShell (sidebar + top bar) ---------- */
function home_for(array $u): string { return is_official($u) ? 'admin.php' : 'dashboard.php'; }
/** $o: nav (list of [label, href, icon, active, badge, danger, desktopOnly]), title, subtitle, actions, home, bottom (html) */
function shell_start(array $o): void {
    $u = user();
    page_head(($o['title'] ?? 'EcoConnect') . ' · EcoConnect');
    $nav = $o['nav'];
    $hasAlerts = false; foreach ($nav as $n) if (($n[4] ?? 0) > 0) $hasAlerts = true;
    echo '<div class="flex h-screen overflow-hidden bg-slate-50"><div id="drawerBg" class="fixed inset-0 z-40 hidden bg-slate-900/40 lg:hidden"></div>'
        . '<aside id="drawer" class="fixed inset-y-0 left-0 z-50 hidden w-64 flex-col border-r border-slate-200 bg-white lg:static lg:flex"><div class="flex h-16 items-center justify-between px-5">'
        . brand_link($o['home'] ?? 'dashboard.php') . '<button type="button" data-drawer-close class="eco-icon-btn lg:hidden" aria-label="Close menu">' . ic('x', 'h-5 w-5') . '</button></div>'
        . '<nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-3" aria-label="Main">';
    foreach ($nav as $n) {
        [$label, $href, $icon, $active, $badge, $danger, $desk] = $n + [4 => 0, 5 => false, 6 => false];
        $cls = $danger ? 'font-medium text-red-600 hover:bg-red-50' : ($active ? 'bg-brand-50 font-semibold text-brand-800' : 'font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900');
        $icls = $danger ? 'text-red-600' : ($active ? 'text-brand-700' : 'text-slate-400');
        echo '<a href="' . e($href) . '"' . ($active ? ' aria-current="page"' : '') . ($danger ? ' data-confirm-title="Sign out?" data-confirm-text="Are you sure you want to sign out of EcoConnect?" data-confirm-label="Sign out" data-confirm-danger="1"' : '')
            . ' class="' . ($desk ? 'hidden lg:flex' : 'flex') . ' w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors ' . $cls . '"><span class="' . $icls . '">' . ic($icon) . '</span><span class="flex-1 text-left">' . e($label) . '</span>'
            . ($badge > 0 ? '<span class="grid h-5 min-w-[20px] place-items-center rounded-full bg-red-600 px-1.5 text-[11px] font-bold text-white" aria-label="' . (int)$badge . ' need attention">' . (int)$badge . '</span>' : '') . '</a>';
    }
    echo '</nav><div class="border-t border-slate-200 p-3"><div class="flex items-center gap-3 rounded-lg px-2 py-2">' . user_avatar($u)
        . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-slate-900">' . e(full_name($u)) . '</p><p class="truncate eco-meta">' . (is_official($u) ? 'Official' : 'Resident') . ' · ' . e($u['barangay']) . '</p></div>'
        . (($o['footer_logout'] ?? true) ? '<a href="logout.php" data-confirm-title="Sign out?" data-confirm-text="Are you sure you want to sign out of EcoConnect?" data-confirm-label="Sign out" data-confirm-danger="1" class="eco-icon-btn hover:text-red-600" aria-label="Sign out" title="Sign out">' . ic('logout', 'h-[18px] w-[18px]') . '</a>' : '') . '</div></div></aside>'
        . '<div class="flex min-w-0 flex-1 flex-col"><header class="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 sm:px-6"><div class="flex min-w-0 items-center gap-2">'
        . '<button type="button" data-drawer-open class="eco-icon-btn relative -ml-2 lg:hidden" aria-label="Open menu">' . ic('menu', 'h-5 w-5') . ($hasAlerts ? '<span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-red-600 ring-2 ring-white" aria-hidden="true"></span>' : '') . '</button>'
        . '<div class="min-w-0"><h1 class="truncate text-lg font-bold tracking-tight text-slate-900">' . e($o['title']) . '</h1>' . (!empty($o['subtitle']) ? '<p class="hidden truncate text-sm text-slate-500 sm:block">' . e($o['subtitle']) . '</p>' : '') . '</div></div>'
        . '<div class="flex shrink-0 items-center gap-2">' . ($o['actions'] ?? '') . theme_toggle() . '</div></header>'
        . '<main class="flex-1 overflow-y-auto"><div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">';
}
function shell_end(string $extra = ''): void { echo '</div></main></div></div>'; page_foot($extra); }
