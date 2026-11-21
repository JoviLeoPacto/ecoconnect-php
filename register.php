<?php
require_once __DIR__ . '/lib.php';
if (user()) redirect('dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    run(function () {
        $first = post('first_name'); $last = post('last_name'); $username = post('username');
        $phone = preg_replace('/[\s-]/', '', post('phone_number')); $email = post('email') ?: null;
        $pw = $_POST['password'] ?? ''; $bid = (int)post('barangay_id');
        if ($first === '' || $last === '') throw new UserError('Please enter your first and last name.');
        if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) throw new UserError('Username must be 3–30 letters, numbers, dots or underscores.');
        if (!preg_match('/^(09|\+639)\d{9}$/', $phone)) throw new UserError('Enter a valid Philippine mobile number (e.g. 09123456789).');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UserError('That email address is not valid.');
        if (strlen($pw) < 8) throw new UserError('Password must be at least 8 characters.');
        if ($pw !== ($_POST['password2'] ?? '')) throw new UserError('Passwords do not match.');
        if (!val('SELECT 1 FROM barangays WHERE id = ? AND is_participating = 1', [$bid])) throw new UserError('Please choose your barangay.');
        if (val('SELECT 1 FROM users WHERE username = ?', [$username])) throw new UserError('That username is taken.');
        if (val('SELECT 1 FROM users WHERE phone_number = ?', [$phone])) throw new UserError('That phone number is already registered.');
        q("INSERT INTO users (username, password, first_name, last_name, email, phone_number, role, barangay_id) VALUES (?,?,?,?,?,?, 'resident', ?)",
          [$username, password_hash($pw, PASSWORD_DEFAULT), $first, $last, $email, $phone, $bid]);
        return 'Account created! A Barangay Official will verify you before you can join events. You can log in now.';
    }, '', 'index.php');
}
header_html('Create your account', 'Join your barangay\'s cleanup drives and earn Eco-Points.', '', 'form');
$barangays = all('SELECT id, name FROM barangays WHERE is_participating = 1 ORDER BY name');
?>
<form method="post"><?= csrf_field() ?>
    <div class="row">
      <div><label>First name</label><input name="first_name" required></div>
      <div><label>Last name</label><input name="last_name" required></div>
    </div>
    <div class="row">
      <div><label>Username</label><input name="username" required></div>
      <div><label>Mobile number</label><input name="phone_number" placeholder="09123456789" required></div>
    </div>
    <label>Email (optional)</label><input type="email" name="email">
    <label>Barangay</label>
    <select name="barangay_id" required><option value="">Choose…</option>
      <?php foreach ($barangays as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="row">
      <div><label>Password</label><input type="password" name="password" minlength="8" required></div>
      <div><label>Confirm password</label><input type="password" name="password2" minlength="8" required></div>
    </div>
    <div style="margin-top:20px"><button class="w100">Create account</button></div>
  </form>
<div class="pub-note">Already have an account? <a class="link" href="index.php">Log in</a></div>
<?php footer_html();
