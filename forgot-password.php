<?php
require_once __DIR__ . '/lib.php';
auth_start('Forgot password?', 'Password reset codes by text message are not turned on in this version of EcoConnect.');
?>
<div class="rounded-lg border border-brand-200 bg-brand-50 px-3.5 py-3 text-sm text-brand-800 mb-5" role="status">Please contact your Barangay Official or the system administrator. They can help you get back into your account.</div>
<a href="login.php" class="eco-btn-primary">Back to sign in</a>
<?php auth_end();
