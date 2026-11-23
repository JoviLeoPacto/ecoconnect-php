<?php
require_once __DIR__ . '/lib.php';
if ($u = user()) redirect(home_for($u));

$step = (int)($_GET['step'] ?? 1);
if ($step === 3 && empty($_SESSION['signup'])) $step = 1;
if ($step !== 3) $step = 1;
$v = $_SESSION['signup'] ?? [];
$errors = []; $error = null;
$name_ok = fn(string $s) => $s !== '' && mb_strlen($s) <= 50 && preg_match('/^\p{L}[\p{L} .\'-]*$/u', $s);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'details') {
        $v = ['first_name' => post('first_name'), 'last_name' => post('last_name'), 'username' => post('username'), 'email' => post('email'),
              'phone_number' => post('phone_number'), 'pw' => $_POST['password'] ?? '', 'confirm' => $_POST['confirm'] ?? ''];
        $phone = preg_replace('/[\s-]/', '', $v['phone_number']);
        if (!$name_ok($v['first_name'])) $errors['first_name'] = $v['first_name'] === '' ? 'First name is required.' : 'First name can only use letters, spaces, dots, dashes or apostrophes.';
        if (!$name_ok($v['last_name'])) $errors['last_name'] = $v['last_name'] === '' ? 'Last name is required.' : 'Last name can only use letters, spaces, dots, dashes or apostrophes.';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $v['username'])) $errors['username'] = '3–30 characters: letters, numbers, dot, dash or underscore.';
        elseif (val('SELECT 1 FROM users WHERE username = ?', [$v['username']])) $errors['username'] = 'That username is already taken.';
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $v['email'])) $errors['email'] = 'Enter a valid email address.';
        if ($v['phone_number'] === '') $errors['phone_number'] = 'Phone number is required.';
        elseif (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) $errors['phone_number'] = 'Use a PH mobile number, e.g. 09171234567.';
        elseif (val('SELECT 1 FROM users WHERE phone_number = ?', [$phone])) $errors['phone_number'] = 'That mobile number is already registered.';
        if ($e = pw_error($v['pw'])) $errors['password'] = $e;
        if ($v['confirm'] === '' || $v['confirm'] !== $v['pw']) $errors['confirm'] = 'Passwords do not match.';
        if (!$errors) { $v['phone'] = $phone; $v['hash'] = password_hash($v['pw'], PASSWORD_DEFAULT); unset($v['pw'], $v['confirm']); $_SESSION['signup'] = $v; redirect('register.php?step=3'); }
        $step = 1;
    } elseif (post('action') === 'create' && !empty($_SESSION['signup'])) {
        $v = $_SESSION['signup']; $step = 3;
        $bid = (int)post('barangay_id');
        if (!val('SELECT 1 FROM barangays WHERE id = ? AND is_participating = 1', [$bid])) $error = 'Please choose your barangay.';
        elseif ($m = image_error($_FILES['id_image'] ?? null)) $error = $m;
        elseif (post('consent') !== '1') $error = 'Please agree to the Data Privacy Policy to continue.';
        elseif (val('SELECT 1 FROM users WHERE username = ? OR phone_number = ?', [$v['username'], $v['phone']])) { $error = 'That username or mobile number was just taken. Please go back and change it.'; }
        else {
            q("INSERT INTO users (username, password, first_name, last_name, email, phone_number, role, barangay_id, id_image) VALUES (?,?,?,?,?,?, 'resident', ?, ?)",
              [$v['username'], $v['hash'], $v['first_name'], $v['last_name'], $v['email'], $v['phone'], $bid, image_data_uri($_FILES['id_image'])]);
            unset($_SESSION['signup']);
            flash('Account created! You can sign in while your ID is being verified.');
            redirect('login.php');
        }
    }
}

$labels = [1 => 'Account details', 3 => 'Barangay & verification'];
$back = $step === 3 ? '<a href="register.php?step=1" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">' . ic('back', 'w-4 h-4') . ' Back</a>' : '';
auth_start('Create account', 'Join cleanup drives and earn Eco-Points for your community.', '', $back);
$bar = '';
foreach ([1, 2, 3] as $n) $bar .= '<span class="w-6 h-1 rounded-full ' . ($step >= $n ? 'bg-brand-700' : 'bg-slate-200') . '"></span>';
echo '<div class="flex items-center justify-between mb-5 text-xs font-medium text-slate-500"><span>Step ' . $step . ' of 3 · ' . $labels[$step] . '</span><span class="flex gap-1" aria-hidden="true">' . $bar . '</span></div>';
echo form_error($error);
$err = fn(string $k) => isset($errors[$k]) ? '<p class="mt-1.5 text-xs font-medium text-red-600">' . e($errors[$k]) . '</p>' : '';
$fld = function (string $label, string $name, string $attrs, string $hint = '', string $type = 'text') use ($v, $errors, $err) {
    $h = field($label, $name, $v[$name] ?? '', $attrs . (isset($errors[$name]) ? ' aria-invalid="true"' : ''), isset($errors[$name]) ? '' : $hint, $type);
    return isset($errors[$name]) ? substr($h, 0, -6) . $err($name) . '</div>' : $h;
};

if ($step === 1): ?>
<form method="post" class="space-y-5" novalidate><?= csrf('details') ?>
  <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
    <?= $fld('First name', 'first_name', 'placeholder="e.g. Juan" autocomplete="given-name" maxlength="50"') ?>
    <?= $fld('Last name', 'last_name', 'placeholder="e.g. Dela Cruz" autocomplete="family-name" maxlength="50"') ?>
  </div>
  <?= $fld('Username', 'username', 'placeholder="e.g. juan.delacruz" autocomplete="username" maxlength="30"', "You'll use this to sign in.") ?>
  <?= $fld('Email address', 'email', 'placeholder="example@gmail.com" autocomplete="email" maxlength="120"', 'You can use it to find your account if you forget your password.', 'email') ?>
  <?= $fld('Mobile number', 'phone_number', 'placeholder="09171234567" autocomplete="tel" inputmode="tel" maxlength="13"', 'Used to contact you about your barangay activities.', 'tel') ?>
  <div>
    <?= password_field('Password', 'password', 'placeholder="At least 8 characters" autocomplete="new-password" id="pw1"') ?>
    <?= $err('password') ?>
    <div class="mt-2 hidden" data-strength-for="p_password" aria-live="polite"><div class="flex gap-1" aria-hidden="true"><?= str_repeat('<span data-bar class="h-1 flex-1 rounded-full bg-slate-200"></span>', 4) ?></div><p data-strength-label class="mt-1 text-xs font-medium"></p></div>
  </div>
  <div><?= password_field('Confirm password', 'confirm', 'placeholder="Re-enter your password" autocomplete="new-password"') ?><?= $err('confirm') ?></div>
  <div class="pt-1"><button class="eco-btn-primary">Continue</button></div>
</form>
<?php else:
    $bars = all('SELECT id, name FROM barangays WHERE is_participating = 1 ORDER BY name');
    $opts = ['Urban barangays' => array_column($bars, 'name', 'id')]; ?>
<form method="post" enctype="multipart/form-data" class="space-y-5" novalidate><?= csrf('create') ?>
  <?= select('Your barangay', 'barangay_id', $opts, (int)post('barangay_id', (string)($bars[0]['id'] ?? '')), '', 'You will see events and rewards for this barangay.', true) ?>
  <div>
    <span class="eco-label">Valid ID photo</span>
    <div data-id-preview class="hidden flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
      <img src="" alt="Selected ID preview" class="w-14 h-14 rounded-md object-cover border border-slate-200">
      <div class="min-w-0 flex-1"><p data-id-name class="text-sm font-medium text-slate-800 truncate"></p><p data-id-size class="text-xs text-slate-500"></p></div>
      <button type="button" data-id-clear class="p-2 text-slate-400 hover:text-red-600 rounded-md" aria-label="Remove photo"><?= ic('x', 'w-4 h-4') ?></button>
    </div>
    <label data-id-picker class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center cursor-pointer hover:border-brand-600 hover:bg-brand-50/40 transition-colors focus-within:ring-4 focus-within:ring-brand-600/10">
      <?= ic('imageplus', 'w-6 h-6 text-slate-400') ?>
      <span class="text-sm font-medium text-slate-700">Upload a clear photo of your ID</span>
      <span class="text-xs text-slate-500">JPG, PNG or WEBP · up to 5 MB</span>
      <input type="file" name="id_image" data-id-file accept="image/jpeg,image/png,image/gif,image/webp" class="sr-only">
    </label>
    <p class="mt-1.5 text-xs text-slate-500">Only your barangay officials can view this to verify your residency.</p>
  </div>
  <label class="flex items-start gap-3 text-sm text-slate-600 cursor-pointer">
    <input type="checkbox" id="consent" name="consent" value="1" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600/30">
    <span>I agree to EcoConnect collecting my name, contact details and ID photo under the <button type="button" data-modal-open="privacy" class="eco-link">Data Privacy Policy</button>.</span>
  </label>
  <div class="pt-1"><button class="eco-btn-primary" data-needs-consent disabled>Create account</button></div>
</form>
<?php endif;
auth_end('Already have an account? <a href="login.php" class="eco-link">Sign in</a>');
