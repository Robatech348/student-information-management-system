<?php
require __DIR__ . '/includes/bootstrap.php';
const DEMO_PASSWORD_HINT = 'Unisphere@2026'; // matches database/seed.php; shown only while demo mode is on

if (current_user()) {
    redirect('dashboard.php');
}

$portal = (get('as') === 'staff' || post('portal') === 'staff') ? 'staff' : 'student';
$error = null;
$identifier = '';
if (is_post()) {
    verify_csrf();
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    [$ok, $error] = attempt_login($identifier, (string) ($_POST['password'] ?? ''), $portal);
    if ($ok) {
        $to = $_SESSION['intended'] ?? 'dashboard.php';
        unset($_SESSION['intended']);
        redirect(preg_match('/^[a-z_]+\.php(\?[^\s]*)?$/i', $to) ? $to : 'dashboard.php');
    }
}
$flashes = take_flashes();
$institution = setting('institution_name', 'Northstar University');
$demo = setting('show_demo_credentials') === '1';
$registrationOpen = setting('student_registration_mode', 'approval') !== 'closed';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e(config('app_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<script src="assets/js/theme.js"></script>
</head>
<body class="auth-body">
<main class="auth-split">
  <section class="auth-brand">
    <div>
      <div class="brand" style="padding:0"><img src="assets/img/logo.svg" alt="" width="40" height="40"><div class="brand-text"><strong><?= e(config('app_name')) ?></strong><span><?= e($institution) ?></span></div></div>
      <h1>One platform for the whole university.</h1>
      <p><?= e(config('app_tagline')) ?> — admissions, academics, finance and student services, connected and secure.</p>
    </div>
    <ul class="auth-features">
      <li><?= icon('calendar') ?> Daily school programme and timetable</li>
      <li><?= icon('bell') ?> Announcements from the university</li>
      <li><?= icon('users') ?> Complete digital student records</li>
      <li><?= icon('shield') ?> Separate, secure staff and student access</li>
    </ul>
    <small style="color:#7B8CA3">Built by <?= e(config('vendor')) ?></small>
  </section>

  <section class="auth-form">
    <div class="portal-tabs" role="tablist" aria-label="Choose portal">
      <a role="tab" href="login.php?as=student" aria-selected="<?= $portal === 'student' ? 'true' : 'false' ?>" class="<?= $portal === 'student' ? 'is-active' : '' ?>"><?= icon('graduation') ?> Student</a>
      <a role="tab" href="login.php?as=staff" aria-selected="<?= $portal === 'staff' ? 'true' : 'false' ?>" class="<?= $portal === 'staff' ? 'is-active' : '' ?>"><?= icon('briefcase') ?> Staff</a>
    </div>

    <h2><?= $portal === 'student' ? 'Student sign in' : 'Staff sign in' ?></h2>
    <p class="muted"><?= $portal === 'student'
        ? 'Use your student number (e.g. STU-2026-10482) or the email you registered with.'
        : 'Administrators, registry, finance and lecturers. Accounts are issued by the system administrator.' ?></p>
    <?php foreach ($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="portal" value="<?= $portal ?>">
      <div class="field">
        <label for="email"><?= $portal === 'student' ? 'Student number or email' : 'Staff email address' ?></label>
        <input type="<?= $portal === 'student' ? 'text' : 'email' ?>" id="email" name="identifier" value="<?= e($identifier) ?>" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password" style="display:flex;justify-content:space-between">Password <button type="button" class="link-btn small" data-toggle-password="password">Show</button></label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>

    <?php if ($portal === 'student'): ?>
      <div class="register-cta">
        <?php if ($registrationOpen): ?>
          <span>New student?</span> <a class="btn btn-ghost btn-sm" href="register.php"><?= icon('user-plus') ?> Create your student account</a>
        <?php else: ?>
          <span class="muted">Online registration is currently closed. Contact the Registrar's office.</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($demo):
        $accounts = $portal === 'student'
            ? [['STU-2026-10482', 'Student (Jordan Lee)']]
            : [['admin@northstar.ac.ke', 'Super Administrator'], ['registrar@northstar.ac.ke', 'Registrar'], ['finance@northstar.ac.ke', 'Finance Officer'], ['lecturer@northstar.ac.ke', 'Lecturer']]; ?>
    <div class="demo-box">
      <strong>Demo <?= $portal ?> account<?= count($accounts) > 1 ? 's' : '' ?></strong> <span class="muted">· password <code><?= e(DEMO_PASSWORD_HINT) ?></code></span>
      <table>
        <?php foreach ($accounts as [$id, $role]): ?>
          <tr><td><?= e($role) ?></td><td style="text-align:right"><button type="button" class="link-btn" data-fill-login="<?= e($id) ?>" data-password="<?= e(DEMO_PASSWORD_HINT) ?>"><?= e($id) ?></button></td></tr>
        <?php endforeach; ?>
      </table>
      <p class="muted" style="margin:8px 0 0">Turn this panel off in Settings before going live.</p>
    </div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>
