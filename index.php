<?php
require_once __DIR__ . '/lib.php';
if (user()) redirect('dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = post('username');
    $u = one('SELECT id, password FROM users WHERE username = ? OR phone_number = ?', [$id, $id]);
    if ($u && password_verify($_POST['password'] ?? '', $u['password'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        redirect('dashboard.php');
    }
    flash('Wrong username/phone or password.', 'err');
    redirect('index.php');
}
header_html('Welcome to EcoConnect', 'Sign in to join cleanup drives, earn Eco-Points, and manage your barangay.');
?>
<form method="post"><?= csrf_field() ?>
  <label>Username or phone number</label><input name="username" placeholder="Enter your username" required autofocus>
  <label>Password</label><input type="password" name="password" placeholder="Enter your password" required>
  <div style="margin-top:20px"><button class="w100">Log in</button></div>
</form>
<div class="pub-note">New resident? <a class="link" href="register.php">Create an account</a>
  <small>Barangay official accounts are created by the system administrator.</small></div>
<?php footer_html();
