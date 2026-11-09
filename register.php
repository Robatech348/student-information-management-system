<?php
/** Student self-registration. Staff accounts are never created here – only by an administrator. */
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}
$mode = setting('student_registration_mode', 'approval'); // approval | open | closed
$programs = q('SELECT p.id, p.code, p.name, f.name faculty FROM programs p JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id ORDER BY f.name, p.name')->fetchAll();
$programIds = array_map('intval', array_column($programs, 'id'));
$campuses = q('SELECT id, name FROM campuses ORDER BY name')->fetchAll();
$campusIds = array_map('intval', array_column($campuses, 'id'));

$d = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'gender' => '', 'date_of_birth' => '', 'national_id' => '', 'program_id' => '', 'campus_id' => ''];
$errors = [];

if ($mode !== 'closed' && is_post()) {
    verify_csrf();
    foreach ($d as $k => $_) {
        $d[$k] = post($k);
    }
    $d['email'] = strtolower($d['email']);
    $password = (string) ($_POST['password'] ?? '');

    // Bot & abuse protection: hidden honeypot field + max 5 registrations per IP per hour.
    $recent = (int) q_val("SELECT COUNT(*) FROM audit_logs WHERE action = 'register' AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)", [client_ip()]);
    if (post('website') !== '' || $recent >= 5) {
        $errors['form'] = 'Too many registrations from this network. Please try again later or visit the Registrar\'s office.';
    }

    if ($d['first_name'] === '' || mb_strlen($d['first_name']) > 80) $errors['first_name'] = 'Enter your first name.';
    if ($d['last_name'] === '' || mb_strlen($d['last_name']) > 80) $errors['last_name'] = 'Enter your last name.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    elseif (q_val('SELECT id FROM users WHERE email = ?', [$d['email']]) || q_val('SELECT id FROM students WHERE email = ? AND deleted_at IS NULL', [$d['email']])) $errors['email'] = 'An account with this email already exists. Try signing in instead.';
    if (!preg_match('/^\+?[\d\s()-]{9,20}$/', $d['phone'])) $errors['phone'] = 'Enter a valid phone number, e.g. +254 712 345 678.';
    if (!in_array($d['gender'], ['female', 'male', 'other'], true)) $errors['gender'] = 'Select an option.';
    $dob = DateTime::createFromFormat('Y-m-d', $d['date_of_birth']);
    if (!$dob || $dob->format('Y-m-d') !== $d['date_of_birth'] || $dob > new DateTime('-15 years') || $dob < new DateTime('-80 years')) $errors['date_of_birth'] = 'Enter a valid date of birth.';
    if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $d['national_id'])) $errors['national_id'] = 'Enter your national ID, birth certificate or passport number.';
    elseif (q_val('SELECT id FROM students WHERE national_id = ? AND deleted_at IS NULL', [$d['national_id']])) $errors['national_id'] = 'A student with this ID number is already registered. Contact the Registrar\'s office.';
    if (!in_array((int) $d['program_id'], $programIds, true)) $errors['program_id'] = 'Choose the programme you were admitted to.';
    if (!in_array((int) $d['campus_id'], $campusIds, true)) $errors['campus_id'] = 'Choose your campus.';
    if ($problem = password_problem($password)) $errors['password'] = $problem;
    elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) $errors['password_confirm'] = 'The passwords do not match.';
    if (post('consent') !== '1') $errors['consent'] = 'You must agree before registering.';

    if (!$errors) {
        $auto = $mode === 'open';
        $name = $d['first_name'] . ' ' . $d['last_name'];
        db()->beginTransaction();
        try {
            q('INSERT INTO users (name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?)', [$name, $d['email'], $d['phone'], hash_password($password), $auto ? 'active' : 'pending']);
            $uid = (int) db()->lastInsertId();
            q("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'student'", [$uid]);
            $studentNo = $auto ? next_student_no() : applicant_no();
            q('INSERT INTO students (student_no, user_id, first_name, last_name, gender, date_of_birth, national_id, email, phone, program_id, campus_id,
                                     year_of_study, enrollment_status, admission_date, registration_source, approved_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, CURDATE(), \'self\', ?)',
                [$studentNo, $uid, $d['first_name'], $d['last_name'], $d['gender'], $d['date_of_birth'], strtoupper($d['national_id']), $d['email'], $d['phone'],
                 (int) $d['program_id'], (int) $d['campus_id'], $auto ? 'active' : 'pending', $auto ? date('Y-m-d H:i:s') : null]);
            $sid = (int) db()->lastInsertId();
            audit('register', 'student', $sid, "Self-registration by {$name} ({$studentNo})", ['email' => $d['email'], 'mode' => $mode]);
            foreach (users_with_permission('registrations.approve') as $approver) {
                notify($approver, $auto ? 'New student registered' : 'Registration awaiting approval',
                    "{$name} registered for " . ($programs[array_search((int) $d['program_id'], $programIds, true)]['code'] ?? 'a programme') . '.',
                    $auto ? 'student_view.php?id=' . $sid : 'registrations.php');
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        if ($auto) {
            [$ok] = attempt_login($d['email'], $password, 'student');
            notify($uid, 'Welcome to ' . setting('institution_name', 'the university'), "Your student number is {$studentNo}. You can use it to sign in.", 'dashboard.php');
            flash('success', "Welcome! Your account is ready. Your student number is {$studentNo}.");
            redirect($ok ? 'dashboard.php' : 'login.php?as=student');
        }
        $_SESSION['registered'] = ['name' => $d['first_name'], 'ref' => $studentNo, 'email' => $d['email']];
        redirect('register.php?done=1');
    }
}
$done = get('done') === '1' ? ($_SESSION['registered'] ?? null) : null;
unset($_SESSION['registered']);

$err = fn($k) => isset($errors[$k]) ? '<span class="error">' . e($errors[$k]) . '</span>' : '';
$cls = fn($k) => isset($errors[$k]) ? ' has-error' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student registration · <?= e(config('app_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<script src="assets/js/theme.js"></script>
</head>
<body class="auth-body">
<main class="card register-card">
  <div class="brand brand-dark" style="padding:0 0 8px"><img src="assets/img/logo.svg" alt="" width="36" height="36"><div class="brand-text"><strong><?= e(config('app_name')) ?></strong><span><?= e(setting('institution_name', 'Northstar University')) ?></span></div></div>

<?php if ($done): ?>
  <div class="empty-state" style="margin:10px auto;padding:24px 0">
    <div class="empty-icon"><?= icon('check') ?></div>
    <h1>Registration received</h1>
    <p>Thank you, <?= e($done['name']) ?>. Your application reference is</p>
    <p><span class="temp-pass"><?= e($done['ref']) ?></span></p>
    <p class="muted">The Registrar's office will review your details. Once approved you'll get your official student number and can sign in with <strong><?= e($done['email']) ?></strong> and the password you chose.</p>
    <a class="btn btn-primary" href="login.php?as=student">Back to sign in</a>
  </div>

<?php elseif ($mode === 'closed'): ?>
  <h1>Registration closed</h1>
  <p class="muted">Online student registration is currently closed. Please contact the Registrar's office.</p>
  <a class="btn btn-ghost" href="login.php?as=student">Back to sign in</a>

<?php else: ?>
  <h1>Create your student account</h1>
  <p class="muted"><?= $mode === 'approval'
      ? 'Fill in a few details. The Registrar\'s office will verify them and approve your account, usually within one working day.'
      : 'Fill in a few details to get instant access to the student portal.' ?></p>
  <?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div>
  <?php elseif ($errors): ?><div class="alert alert-danger" role="alert">Please correct the highlighted fields.</div><?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="form-grid">
      <div class="field<?= $cls('first_name') ?>"><label for="first_name">First name</label><input id="first_name" name="first_name" value="<?= e($d['first_name']) ?>" autocomplete="given-name" required><?= $err('first_name') ?></div>
      <div class="field<?= $cls('last_name') ?>"><label for="last_name">Last name</label><input id="last_name" name="last_name" value="<?= e($d['last_name']) ?>" autocomplete="family-name" required><?= $err('last_name') ?></div>
      <div class="field<?= $cls('email') ?>"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($d['email']) ?>" autocomplete="email" required><?= $err('email') ?></div>
      <div class="field<?= $cls('phone') ?>"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="<?= e($d['phone']) ?>" autocomplete="tel" placeholder="+254 7XX XXX XXX" required><?= $err('phone') ?></div>
      <div class="field<?= $cls('gender') ?>"><label for="gender">Gender</label>
        <select id="gender" name="gender" required><option value="">Select…</option><?php foreach (['female' => 'Female', 'male' => 'Male', 'other' => 'Other / prefer not to say'] as $k => $l): ?><option value="<?= $k ?>" <?= $d['gender'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><?= $err('gender') ?></div>
      <div class="field<?= $cls('date_of_birth') ?>"><label for="date_of_birth">Date of birth</label><input type="date" id="date_of_birth" name="date_of_birth" value="<?= e($d['date_of_birth']) ?>" required><?= $err('date_of_birth') ?></div>
      <div class="field full<?= $cls('national_id') ?>"><label for="national_id">National ID / Birth cert. / Passport no.</label><input id="national_id" name="national_id" value="<?= e($d['national_id']) ?>" maxlength="20" required><?= $err('national_id') ?></div>
      <div class="field full<?= $cls('program_id') ?>"><label for="program_id">Programme admitted to</label>
        <select id="program_id" name="program_id" required><option value="">Choose a programme…</option>
          <?php $lastFac = null; foreach ($programs as $p):
              if ($p['faculty'] !== $lastFac) { echo ($lastFac ? '</optgroup>' : '') . '<optgroup label="' . e($p['faculty']) . '">'; $lastFac = $p['faculty']; } ?>
            <option value="<?= $p['id'] ?>" <?= (int) $d['program_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' – ' . $p['name']) ?></option>
          <?php endforeach; echo $lastFac ? '</optgroup>' : ''; ?>
        </select><?= $err('program_id') ?></div>
      <div class="field full<?= $cls('campus_id') ?>"><label for="campus_id">Campus</label>
        <select id="campus_id" name="campus_id" required><option value="">Choose a campus…</option><?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $d['campus_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= $err('campus_id') ?></div>
      <div class="field<?= $cls('password') ?>"><label for="password" style="display:flex;justify-content:space-between">Password <button type="button" class="link-btn small" data-toggle-password="password">Show</button></label><input type="password" id="password" name="password" autocomplete="new-password" required><span class="hint">10+ characters, upper- and lower-case letters and a number.</span><?= $err('password') ?></div>
      <div class="field<?= $cls('password_confirm') ?>"><label for="password_confirm">Confirm password</label><input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required><?= $err('password_confirm') ?></div>
      <div class="field full<?= $cls('consent') ?>"><label class="checkbox" style="align-items:flex-start;font-weight:400"><input type="checkbox" name="consent" value="1" <?= post('consent') === '1' ? 'checked' : '' ?> style="margin-top:3px"> I confirm these details are accurate and consent to the university processing my personal data for academic administration, in line with the Data Protection Act, 2019.</label><?= $err('consent') ?></div>
    </div>
    <div class="form-actions" style="justify-content:space-between;align-items:center">
      <a href="login.php?as=student">Already registered? Sign in</a>
      <button class="btn btn-primary" type="submit"><?= icon('user-plus') ?> Register</button>
    </div>
  </form>
<?php endif; ?>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>
