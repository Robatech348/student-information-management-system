<?php
require __DIR__ . '/includes/bootstrap.php';

$id = (int) get('id', '0');
$editing = $id > 0;
require_permission($editing ? 'students.edit' : 'students.create');

$student = $editing ? q_one('SELECT * FROM students WHERE id = ? AND deleted_at IS NULL', [$id]) : null;
if ($editing && !$student) abort(404, 'That student record does not exist.');
$guardian = $editing ? q_one('SELECT * FROM guardians WHERE student_id = ? ORDER BY is_emergency_contact DESC, id LIMIT 1', [$id]) : null;

$programs = q('SELECT p.id, p.code, p.name, p.duration_years, p.total_credits, f.name faculty FROM programs p JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id ORDER BY f.name, p.name')->fetchAll();
$programMap = array_column($programs, null, 'id');
$campuses = q('SELECT id, name FROM campuses ORDER BY name')->fetchAll();
$statuses = ['active', 'graduated', 'deferred', 'suspended', 'withdrawn'];
$standings = ['good' => 'Good standing', 'dean_list' => "Dean's list", 'warning' => 'Academic warning', 'probation' => 'Probation'];

$fields = ['first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'national_id', 'nationality', 'email', 'phone', 'address',
           'program_id', 'campus_id', 'year_of_study', 'enrollment_status', 'academic_standing', 'admission_date', 'expected_graduation',
           'current_gpa', 'credits_completed'];
$data = $student ?: ['nationality' => 'Kenyan', 'year_of_study' => 1, 'enrollment_status' => 'active', 'academic_standing' => 'good',
                     'admission_date' => date('Y-m-d'), 'credits_completed' => 0];
$g = $guardian ?: [];
$errors = [];

$validDate = function (?string $d): bool {
    if ($d === null || $d === '') return true;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
};

if (is_post()) {
    verify_csrf();
    foreach ($fields as $k) {
        $data[$k] = post($k);
    }
    $g = ['full_name' => post('g_name'), 'relationship' => post('g_relationship'), 'phone' => post('g_phone'), 'email' => post('g_email')];

    if ($data['first_name'] === '') $errors['first_name'] = 'First name is required.';
    if ($data['last_name'] === '') $errors['last_name'] = 'Last name is required.';
    if (!isset($programMap[(int) $data['program_id']])) $errors['program_id'] = 'Choose a program.';
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($data['email'] !== '' && q_val('SELECT id FROM students WHERE email = ? AND id <> ? AND deleted_at IS NULL', [$data['email'], $id])) $errors['email'] = 'Another student already uses this email.';
    if ($data['national_id'] !== '' && q_val('SELECT id FROM students WHERE national_id = ? AND id <> ? AND deleted_at IS NULL', [$data['national_id'], $id])) $errors['national_id'] = 'Another student already has this ID number.';
    if (!in_array($data['gender'], ['', 'male', 'female', 'other'], true)) $errors['gender'] = 'Invalid value.';
    if (!in_array($data['enrollment_status'], $statuses, true)) $errors['enrollment_status'] = 'Invalid status.';
    if (!isset($standings[$data['academic_standing']])) $errors['academic_standing'] = 'Invalid standing.';
    if ($data['campus_id'] !== '' && !in_array((int) $data['campus_id'], array_map('intval', array_column($campuses, 'id')), true)) $errors['campus_id'] = 'Invalid campus.';
    foreach (['date_of_birth', 'admission_date', 'expected_graduation'] as $d) {
        if (!$validDate($data[$d])) $errors[$d] = 'Use a valid date.';
    }
    if ($data['admission_date'] === '') $errors['admission_date'] = 'Admission date is required.';
    if (!isset($errors['date_of_birth']) && $data['date_of_birth'] !== '' && strtotime($data['date_of_birth']) > strtotime('-14 years')) $errors['date_of_birth'] = 'Date of birth looks too recent.';
    $prog = $programMap[(int) $data['program_id']] ?? null;
    $year = (int) $data['year_of_study'];
    if ($prog && ($year < 1 || $year > $prog['duration_years'])) $errors['year_of_study'] = "Must be between 1 and {$prog['duration_years']} for this program.";
    if ($data['current_gpa'] !== '' && (!is_numeric($data['current_gpa']) || $data['current_gpa'] < 0 || $data['current_gpa'] > 4)) $errors['current_gpa'] = 'GPA must be between 0.00 and 4.00.';
    if (!ctype_digit((string) $data['credits_completed']) || ($prog && (int) $data['credits_completed'] > $prog['total_credits'])) $errors['credits_completed'] = 'Enter whole credits up to the program total' . ($prog ? " ({$prog['total_credits']})" : '') . '.';
    $anyGuardian = $g['full_name'] !== '' || $g['phone'] !== '';
    if ($anyGuardian && ($g['full_name'] === '' || $g['phone'] === '' || $g['relationship'] === '')) $errors['guardian'] = 'Guardian name, relationship and phone are all required when adding a guardian.';
    if ($g['email'] !== '' && !filter_var($g['email'], FILTER_VALIDATE_EMAIL)) $errors['guardian'] = 'Enter a valid guardian email.';

    $createLogin = !$editing && post('create_login') === '1';
    if ($createLogin) {
        if ($data['email'] === '') $errors['email'] = 'An email is required to create a portal login.';
        elseif (q_val('SELECT id FROM users WHERE email = ?', [strtolower($data['email'])])) $errors['email'] = 'A user account with this email already exists.';
    }

    $photo = null;
    if (!$errors && !empty($_FILES['photo']['name'])) {
        try {
            $photo = store_upload($_FILES['photo'], 'photos', config('uploads.photo_mimes'), config('uploads.max_photo_bytes'));
        } catch (RuntimeException $e) {
            $errors['photo'] = $e->getMessage();
        }
    }

    if (!$errors) {
        $values = [];
        foreach ($fields as $k) {
            $values[$k] = $data[$k] === '' ? null : $data[$k];
        }
        $values['email'] = $values['email'] ? strtolower($values['email']) : null;
        $values['credits_completed'] = (int) $data['credits_completed'];
        if ($values['current_gpa'] !== null) $values['current_gpa'] = number_format((float) $values['current_gpa'], 2, '.', '');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($editing) {
                $changes = [];
                foreach ($fields as $k) {
                    if ((string) ($student[$k] ?? '') !== (string) ($values[$k] ?? '')) {
                        $changes[$k] = ['from' => $student[$k], 'to' => $values[$k]];
                    }
                }
                $set = implode(', ', array_map(fn($k) => "{$k} = :{$k}", $fields));
                $stmt = $pdo->prepare("UPDATE students SET {$set} WHERE id = :id");
                $stmt->execute($values + ['id' => $id]);
                $studentId = $id;
                if ($photo) {
                    q('UPDATE students SET photo_path = ? WHERE id = ?', [$photo['path'], $id]);
                    if ($student['photo_path'] && is_file(STORAGE_PATH . '/' . $student['photo_path'])) @unlink(STORAGE_PATH . '/' . $student['photo_path']);
                    $changes['photo'] = 'updated';
                }
                audit('update', 'student', $id, 'Updated student ' . $student['student_no'], $changes);
            } else {
                $studentNo = next_student_no($values['admission_date']);
                $cols = array_merge($fields, ['student_no', 'photo_path', 'created_by']);
                $values += ['student_no' => $studentNo, 'photo_path' => $photo['path'] ?? null, 'created_by' => $_SESSION['user_id']];
                $stmt = $pdo->prepare('INSERT INTO students (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')');
                $stmt->execute($values);
                $studentId = (int) $pdo->lastInsertId();
                audit('create', 'student', $studentId, "Created student {$studentNo} – " . full_name($values));
            }

            if ($anyGuardian) {
                if ($guardian) {
                    q('UPDATE guardians SET full_name = ?, relationship = ?, phone = ?, email = ? WHERE id = ?', [$g['full_name'], $g['relationship'], $g['phone'], $g['email'] ?: null, $guardian['id']]);
                } else {
                    q('INSERT INTO guardians (student_id, full_name, relationship, phone, email, is_emergency_contact) VALUES (?, ?, ?, ?, ?, 1)', [$studentId, $g['full_name'], $g['relationship'], $g['phone'], $g['email'] ?: null]);
                }
            }

            $tempPassword = null;
            if ($createLogin) {
                $tempPassword = temp_password();
                q('INSERT INTO users (name, email, password_hash, must_change_password) VALUES (?, ?, ?, 1)', [full_name($values), $values['email'], hash_password($tempPassword)]);
                $uid = (int) $pdo->lastInsertId();
                q('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = \'student\'', [$uid]);
                q('UPDATE students SET user_id = ? WHERE id = ?', [$uid, $studentId]);
                notify($uid, 'Welcome to the student portal', 'Your UniSphere account is ready.', 'dashboard.php');
                audit('create', 'user', $uid, 'Created student portal login for ' . $values['email']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($photo) @unlink(STORAGE_PATH . '/' . $photo['path']);
            throw $e;
        }
        flash('success', $editing ? 'Student record updated.' : 'Student record created.');
        if ($tempPassword) {
            $_SESSION['temp_password'] = ['email' => $values['email'], 'password' => $tempPassword];
        }
        redirect('student_view.php?id=' . $studentId);
    }
}

function err(array $errors, string $k): string
{
    return isset($errors[$k]) ? '<span class="error">' . e($errors[$k]) . '</span>' : '';
}
function cls(array $errors, string $k): string
{
    return isset($errors[$k]) ? ' has-error' : '';
}
$v = fn($k) => e($data[$k] ?? '');

render_header($editing ? 'Edit student' : 'Add student', 'students');
echo '<div class="breadcrumb"><a href="students.php">Students</a> / ' . ($editing ? e(full_name($student)) : 'New') . '</div>';
page_header($editing ? 'Edit ' . full_name($student) : 'Add a new student', $editing ? $student['student_no'] : 'A student ID is generated automatically when you save.');
if ($errors): ?><div class="alert alert-danger" role="alert">Please fix the highlighted fields<?= isset($errors['guardian']) ? ': ' . e($errors['guardian']) : '' ?>.</div><?php endif; ?>

<form class="card" method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="form-section">
    <h2>Personal details</h2>
    <div class="form-grid form-grid-3">
      <div class="field<?= cls($errors, 'first_name') ?>"><label for="first_name">First name <span class="req">*</span></label><input id="first_name" name="first_name" value="<?= $v('first_name') ?>" required maxlength="80"><?= err($errors, 'first_name') ?></div>
      <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" value="<?= $v('middle_name') ?>" maxlength="80"></div>
      <div class="field<?= cls($errors, 'last_name') ?>"><label for="last_name">Last name <span class="req">*</span></label><input id="last_name" name="last_name" value="<?= $v('last_name') ?>" required maxlength="80"><?= err($errors, 'last_name') ?></div>
      <div class="field<?= cls($errors, 'gender') ?>"><label for="gender">Gender</label>
        <select id="gender" name="gender"><option value="">Prefer not to say</option>
          <?php foreach (['female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= ($data['gender'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></div>
      <div class="field<?= cls($errors, 'date_of_birth') ?>"><label for="date_of_birth">Date of birth</label><input type="date" id="date_of_birth" name="date_of_birth" value="<?= $v('date_of_birth') ?>"><?= err($errors, 'date_of_birth') ?></div>
      <div class="field<?= cls($errors, 'national_id') ?>"><label for="national_id">National ID / Passport</label><input id="national_id" name="national_id" value="<?= $v('national_id') ?>" maxlength="30"><?= err($errors, 'national_id') ?></div>
      <div class="field"><label for="nationality">Nationality</label><input id="nationality" name="nationality" value="<?= $v('nationality') ?>" maxlength="60"></div>
      <div class="field<?= cls($errors, 'email') ?>"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= $v('email') ?>" maxlength="190"><?= err($errors, 'email') ?></div>
      <div class="field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" maxlength="30" placeholder="+254 7XX XXX XXX"></div>
      <div class="field full"><label for="address">Address</label><input id="address" name="address" value="<?= $v('address') ?>" maxlength="255"></div>
      <div class="field full<?= cls($errors, 'photo') ?>"><label for="photo">Photograph</label>
        <div class="photo-preview">
          <?php if (!empty($student['photo_path'])): ?><img class="avatar avatar-lg" src="photo.php?id=<?= $id ?>" alt="Current photo"><?php endif; ?>
          <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
        </div>
        <span class="hint">JPG, PNG or WebP, up to 2 MB.</span><?= err($errors, 'photo') ?></div>
    </div>
  </div>

  <div class="form-section">
    <h2>Academic details</h2>
    <div class="form-grid form-grid-3">
      <div class="field full<?= cls($errors, 'program_id') ?>"><label for="program_id">Program <span class="req">*</span></label>
        <select id="program_id" name="program_id" required><option value="">Choose a program…</option>
          <?php $lastFac = null; foreach ($programs as $pr):
              if ($pr['faculty'] !== $lastFac) { echo ($lastFac ? '</optgroup>' : '') . '<optgroup label="' . e($pr['faculty']) . '">'; $lastFac = $pr['faculty']; } ?>
            <option value="<?= $pr['id'] ?>" <?= (int) ($data['program_id'] ?? 0) === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['code'] . ' – ' . $pr['name'] . ' (' . $pr['duration_years'] . ' yrs, ' . $pr['total_credits'] . ' credits)') ?></option>
          <?php endforeach; echo $lastFac ? '</optgroup>' : ''; ?>
        </select><?= err($errors, 'program_id') ?></div>
      <div class="field<?= cls($errors, 'campus_id') ?>"><label for="campus_id">Campus</label>
        <select id="campus_id" name="campus_id"><option value="">—</option>
          <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>" <?= (int) ($data['campus_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field<?= cls($errors, 'year_of_study') ?>"><label for="year_of_study">Year of study</label><input type="number" id="year_of_study" name="year_of_study" min="1" max="7" value="<?= $v('year_of_study') ?>"><?= err($errors, 'year_of_study') ?></div>
      <div class="field<?= cls($errors, 'admission_date') ?>"><label for="admission_date">Admission date <span class="req">*</span></label><input type="date" id="admission_date" name="admission_date" value="<?= $v('admission_date') ?>" required><?= err($errors, 'admission_date') ?></div>
      <div class="field<?= cls($errors, 'enrollment_status') ?>"><label for="enrollment_status">Enrollment status</label>
        <select id="enrollment_status" name="enrollment_status">
          <?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= ($data['enrollment_status'] ?? '') === $s ? 'selected' : '' ?>><?= e(humanize($s)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field<?= cls($errors, 'academic_standing') ?>"><label for="academic_standing">Academic standing</label>
        <select id="academic_standing" name="academic_standing">
          <?php foreach ($standings as $k => $l): ?><option value="<?= $k ?>" <?= ($data['academic_standing'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field<?= cls($errors, 'expected_graduation') ?>"><label for="expected_graduation">Expected graduation</label><input type="date" id="expected_graduation" name="expected_graduation" value="<?= $v('expected_graduation') ?>"><?= err($errors, 'expected_graduation') ?></div>
      <div class="field<?= cls($errors, 'current_gpa') ?>"><label for="current_gpa">Current GPA</label><input type="number" step="0.01" min="0" max="4" id="current_gpa" name="current_gpa" value="<?= $v('current_gpa') ?>"><span class="hint">Calculated automatically once exams &amp; results go live (Phase 3).</span><?= err($errors, 'current_gpa') ?></div>
      <div class="field<?= cls($errors, 'credits_completed') ?>"><label for="credits_completed">Credits completed</label><input type="number" min="0" id="credits_completed" name="credits_completed" value="<?= $v('credits_completed') ?>"><?= err($errors, 'credits_completed') ?></div>
    </div>
  </div>

  <div class="form-section">
    <h2>Guardian / emergency contact</h2>
    <div class="form-grid">
      <div class="field<?= cls($errors, 'guardian') ?>"><label for="g_name">Full name</label><input id="g_name" name="g_name" value="<?= e($g['full_name'] ?? '') ?>" maxlength="150"></div>
      <div class="field<?= cls($errors, 'guardian') ?>"><label for="g_relationship">Relationship</label>
        <select id="g_relationship" name="g_relationship"><option value="">—</option>
          <?php foreach (['Mother', 'Father', 'Guardian', 'Spouse', 'Sibling', 'Uncle', 'Aunt', 'Sponsor', 'Other'] as $r): ?><option <?= ($g['relationship'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
        </select></div>
      <div class="field<?= cls($errors, 'guardian') ?>"><label for="g_phone">Phone</label><input type="tel" id="g_phone" name="g_phone" value="<?= e($g['phone'] ?? '') ?>" maxlength="30"></div>
      <div class="field"><label for="g_email">Email</label><input type="email" id="g_email" name="g_email" value="<?= e($g['email'] ?? '') ?>" maxlength="190"></div>
    </div>
  </div>

  <?php if (!$editing): ?>
  <div class="form-section">
    <h2>Student portal</h2>
    <label class="checkbox"><input type="checkbox" name="create_login" value="1" <?= post('create_login') === '1' ? 'checked' : '' ?>> Create a portal login using the student's email</label>
    <p class="hint muted small" style="margin:6px 0 0">A temporary password is shown once after saving; the student must change it at first sign-in.</p>
  </div>
  <?php endif; ?>

  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= $editing ? 'student_view.php?id=' . $id : 'students.php' ?>">Cancel</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> <?= $editing ? 'Save changes' : 'Create student' ?></button>
  </div>
</form>
<?php render_footer();
