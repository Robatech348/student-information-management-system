<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$errors = [];

if (is_post()) {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify($current, $hash)) $errors['current_password'] = 'Your current password is incorrect.';
    if ($problem = password_problem($new)) $errors['new_password'] = $problem;
    elseif (password_verify($new, $hash)) $errors['new_password'] = 'Choose a password you have not used for this account.';
    elseif (stripos($new, explode('@', $user['email'])[0]) !== false) $errors['new_password'] = 'Your password must not contain your email name.';
    if ($new !== $confirm) $errors['confirm_password'] = 'The passwords do not match.';

    if (!$errors) {
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [hash_password($new), $user['id']]);
        session_regenerate_id(true);
        audit('update', 'user', $user['id'], 'Changed own password');
        flash('success', 'Your password has been changed.');
        redirect('account.php');
    }
}

$logins = q('SELECT * FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 8', [$user['id']])->fetchAll();
render_header('Account & security', 'account');
page_header('Account & security', 'Your profile, password and recent sign-in activity.');
if ($user['must_change_password']): ?><div class="alert alert-warning">You're using a temporary password. Choose a new one to continue.</div><?php endif; ?>
<div class="grid grid-2">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h2>Profile</h2></div>
      <dl class="dl">
        <dt>Name</dt><dd><?= e($user['name']) ?></dd>
        <dt>Email</dt><dd><?= e($user['email']) ?></dd>
        <dt>Roles</dt><dd><?= e(implode(', ', $user['roles'])) ?></dd>
        <dt>Last sign-in</dt><dd><?= e(format_date($user['last_login_at'], 'M j, Y H:i')) ?></dd>
      </dl>
    </div>
    <div class="card card-flush">
      <div class="card-head"><h2>Recent sign-ins</h2></div>
      <div class="table-wrap" style="margin-top:12px"><table class="table">
        <thead><tr><th>When</th><th>Result</th><th>IP address</th></tr></thead><tbody>
        <?php foreach ($logins as $l): ?><tr><td><?= e(format_date($l['created_at'], 'M j, H:i')) ?></td><td><?= $l['success'] ? badge('success', 'Success') : badge('failed', 'Failed') ?></td><td class="mono"><?= e($l['ip_address']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <p class="muted small" style="padding:0 20px 16px;margin:0">Don't recognise a sign-in? Change your password and tell the ICT office.</p>
    </div>
  </div>
  <form class="card" method="post" novalidate style="align-self:start">
    <?= csrf_field() ?>
    <div class="card-head"><h2>Change password</h2></div>
    <div class="stack">
      <div class="field<?= isset($errors['current_password']) ? ' has-error' : '' ?>"><label for="cp">Current password</label><input type="password" id="cp" name="current_password" autocomplete="current-password" required><?= isset($errors['current_password']) ? '<span class="error">' . e($errors['current_password']) . '</span>' : '' ?></div>
      <div class="field<?= isset($errors['new_password']) ? ' has-error' : '' ?>"><label for="np">New password</label><input type="password" id="np" name="new_password" autocomplete="new-password" required><span class="hint">At least <?= (int) config('security.min_password_length') ?> characters with upper- and lower-case letters and a number.</span><?= isset($errors['new_password']) ? '<span class="error">' . e($errors['new_password']) . '</span>' : '' ?></div>
      <div class="field<?= isset($errors['confirm_password']) ? ' has-error' : '' ?>"><label for="cf">Confirm new password</label><input type="password" id="cf" name="confirm_password" autocomplete="new-password" required><?= isset($errors['confirm_password']) ? '<span class="error">' . e($errors['confirm_password']) . '</span>' : '' ?></div>
      <button class="btn btn-primary" type="submit"><?= icon('key') ?> Update password</button>
    </div>
  </form>
</div>
<?php render_footer();
