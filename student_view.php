<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = (int) get('id', '0');
$own = current_student();
if (!$id && $own) {
    $id = (int) $own['id'];
}
$isOwner = $own && (int) $own['id'] === $id;
if (!$id && can('students.view')) {
    redirect('students.php');
}
if (!$isOwner && !can('students.view')) {
    abort(403);
}

$s = q_one('SELECT s.*, p.code program_code, p.name program_name, p.level, p.duration_years, p.total_credits,
                   d.name dept_name, f.name faculty_name, c.name campus_name, cu.name created_by_name
              FROM students s JOIN programs p ON p.id = s.program_id JOIN departments d ON d.id = p.department_id
              JOIN faculties f ON f.id = d.faculty_id LEFT JOIN campuses c ON c.id = s.campus_id
              LEFT JOIN users cu ON cu.id = s.created_by
             WHERE s.id = ? AND s.deleted_at IS NULL', [$id]);
if (!$s) abort(404, 'That student record does not exist or has been archived.');

$canDocs = can('students.documents');
$back = 'student_view.php?id=' . $id;

/* ---------------- Actions ---------------- */
if (is_post()) {
    verify_csrf();
    $action = post('action');
    try {
        switch ($action) {
            case 'upload_document':
                if (!$canDocs && !$isOwner) abort(403);
                $title = post('title');
                $type = post('doc_type');
                $types = ['national_id', 'transcript', 'certificate', 'admission_letter', 'medical', 'other'];
                if ($title === '' || !in_array($type, $types, true)) throw new RuntimeException('Enter a title and choose a document type.');
                $file = store_upload($_FILES['document'] ?? [], 'documents', config('uploads.document_mimes'), config('uploads.max_document_bytes'));
                q('INSERT INTO student_documents (student_id, title, doc_type, file_path, original_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, mb_substr($title, 0, 150), $type, $file['path'], $file['original'], $file['mime'], $file['size'], $user['id']]);
                audit('upload', 'student', $id, "Uploaded document \"{$title}\" for {$s['student_no']}");
                flash('success', 'Document uploaded.');
                redirect($back . '&tab=documents');

            case 'delete_document':
                if (!$canDocs) abort(403);
                $doc = q_one('SELECT * FROM student_documents WHERE id = ? AND student_id = ?', [(int) post('document_id'), $id]);
                if ($doc) {
                    q('DELETE FROM student_documents WHERE id = ?', [$doc['id']]);
                    @unlink(STORAGE_PATH . '/' . $doc['file_path']);
                    audit('delete', 'student', $id, "Deleted document \"{$doc['title']}\" from {$s['student_no']}");
                    flash('success', 'Document deleted.');
                }
                redirect($back . '&tab=documents');

            case 'change_status':
                if (!can('students.edit')) abort(403);
                $new = post('status');
                $reason = post('reason');
                if (!in_array($new, ['active', 'graduated', 'deferred', 'suspended', 'withdrawn'], true)) throw new RuntimeException('Choose a valid status.');
                if ($reason === '') throw new RuntimeException('Give a reason for the status change – it is kept in the audit trail.');
                if ($new !== $s['enrollment_status']) {
                    q('UPDATE students SET enrollment_status = ? WHERE id = ?', [$new, $id]);
                    audit('status_change', 'student', $id, "Changed {$s['student_no']} from {$s['enrollment_status']} to {$new}", ['reason' => $reason]);
                    if ($s['user_id']) notify((int) $s['user_id'], 'Your enrollment status changed', 'New status: ' . humanize($new) . '.', 'dashboard.php');
                    flash('success', 'Enrollment status updated to ' . humanize($new) . '.');
                }
                redirect($back);

            case 'add_guardian':
                if (!can('students.edit')) abort(403);
                $name = post('full_name'); $rel = post('relationship'); $phone = post('phone'); $email = post('email');
                if ($name === '' || $rel === '' || $phone === '') throw new RuntimeException('Name, relationship and phone are required.');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email.');
                q('INSERT INTO guardians (student_id, full_name, relationship, phone, email, is_emergency_contact) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $name, $rel, $phone, $email ?: null, post('is_emergency') === '1' ? 1 : 0]);
                audit('update', 'student', $id, "Added guardian {$name} to {$s['student_no']}");
                flash('success', 'Guardian added.');
                redirect($back . '&tab=guardians');

            case 'delete_guardian':
                if (!can('students.edit')) abort(403);
                $gd = q_one('SELECT * FROM guardians WHERE id = ? AND student_id = ?', [(int) post('guardian_id'), $id]);
                if ($gd) {
                    q('DELETE FROM guardians WHERE id = ?', [$gd['id']]);
                    audit('update', 'student', $id, "Removed guardian {$gd['full_name']} from {$s['student_no']}");
                    flash('success', 'Guardian removed.');
                }
                redirect($back . '&tab=guardians');

            case 'create_login':
                if (!can('users.manage')) abort(403);
                if ($s['user_id']) throw new RuntimeException('This student already has a portal login.');
                if (!$s['email']) throw new RuntimeException('Add an email address to the student record first.');
                if (q_val('SELECT id FROM users WHERE email = ?', [$s['email']])) throw new RuntimeException('A user with this email already exists.');
                $temp = temp_password();
                q('INSERT INTO users (name, email, password_hash, must_change_password) VALUES (?, ?, ?, 1)', [full_name($s), $s['email'], hash_password($temp)]);
                $uid = (int) db()->lastInsertId();
                q("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'student'", [$uid]);
                q('UPDATE students SET user_id = ? WHERE id = ?', [$uid, $id]);
                audit('create', 'user', $uid, 'Created student portal login for ' . $s['email']);
                $_SESSION['temp_password'] = ['email' => $s['email'], 'password' => $temp];
                flash('success', 'Portal login created.');
                redirect($back);

            case 'archive':
                if (!can('students.delete')) abort(403);
                q('UPDATE students SET deleted_at = NOW() WHERE id = ?', [$id]);
                audit('archive', 'student', $id, "Archived student record {$s['student_no']}");
                flash('success', "Student {$s['student_no']} archived. The record is retained for audit purposes.");
                redirect('students.php');

            default:
                abort(400, 'Unknown action.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect($back . '&tab=' . urlencode(get('tab', 'overview')));
    }
}

/* ---------------- View ---------------- */
$tabs = ['overview' => 'Overview', 'guardians' => 'Guardians', 'documents' => 'Documents', 'academic' => 'Academic record'];
if (can('audit.view') || can('students.edit')) $tabs['history'] = 'History';
$tab = isset($tabs[get('tab')]) ? get('tab') : 'overview';

$guardians = q('SELECT * FROM guardians WHERE student_id = ? ORDER BY is_emergency_contact DESC, id', [$id])->fetchAll();
$documents = q('SELECT d.*, u.name uploader FROM student_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.student_id = ? ORDER BY d.created_at DESC', [$id])->fetchAll();
$pct = $s['total_credits'] ? min(100, round($s['credits_completed'] / $s['total_credits'] * 100)) : 0;
$standings = ['good' => 'Good standing', 'dean_list' => "Dean's list", 'warning' => 'Academic warning', 'probation' => 'Probation'];
$temp = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

if (!$isOwner) {
    audit('view', 'student', $id, "Viewed student record {$s['student_no']}");
}

render_header(full_name($s), $isOwner ? 'my_profile' : 'students');
if (!$isOwner) echo '<div class="breadcrumb"><a href="students.php">Students</a> / ' . e(full_name($s)) . '</div>';
?>
<?php if ($temp): ?>
  <div class="alert alert-info" role="status"><div>Portal login created for <strong><?= e($temp['email']) ?></strong>. Share this temporary password securely — it will not be shown again:<br><span class="temp-pass"><?= e($temp['password']) ?></span></div></div>
<?php endif; ?>

<section class="card profile-hero">
  <?php if ($s['photo_path']): ?><img class="avatar avatar-lg" src="photo.php?id=<?= $id ?>" alt=""><?php else: ?><span class="avatar avatar-lg"><?= e(initials($s['first_name'] . ' ' . $s['last_name'])) ?></span><?php endif; ?>
  <div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h1><?= e(full_name($s)) ?></h1><?= badge($s['enrollment_status'], humanize($s['enrollment_status']) . ' student') ?></div>
    <div class="hero-meta">
      <span class="mono"><?= e($s['student_no']) ?></span>
      <span><?= icon('graduation') ?><?= e($s['program_name']) ?></span>
      <span><?= icon('building') ?><?= e($s['faculty_name']) ?></span>
      <?php if ($s['campus_name']): ?><span><?= icon('map') ?><?= e($s['campus_name']) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="hero-actions">
    <button class="btn btn-ghost" type="button" data-print><?= icon('printer') ?> Print</button>
    <?php if (can('students.edit')): ?><a class="btn btn-ghost" href="student_form.php?id=<?= $id ?>"><?= icon('edit') ?> Edit</a><?php endif; ?>
  </div>
</section>

<div class="card card-flush" style="margin-top:16px">
  <div class="stat-strip">
    <div><span class="label">Current year</span><strong>Year <?= (int) $s['year_of_study'] ?></strong><span class="muted small"> of <?= (int) $s['duration_years'] ?></span></div>
    <div><span class="label">Academic status</span><?= badge($s['academic_standing'], $standings[$s['academic_standing']]) ?></div>
    <div><span class="label">Current GPA</span><strong><?= $s['current_gpa'] !== null ? e(number_format((float) $s['current_gpa'], 2)) : '—' ?></strong></div>
    <div><span class="label">Completed credits</span><strong><?= (int) $s['credits_completed'] ?>/<?= (int) $s['total_credits'] ?></strong></div>
    <div><span class="label">Degree completion</span><strong><?= $pct ?>%</strong><div class="meter" style="margin-top:6px"><span style="width:<?= $pct ?>%"></span></div></div>
  </div>
</div>

<nav class="tabs" style="margin-top:20px" aria-label="Profile sections">
  <?php foreach ($tabs as $k => $label):
      $count = $k === 'guardians' ? count($guardians) : ($k === 'documents' ? count($documents) : null); ?>
    <a href="student_view.php?id=<?= $id ?>&amp;tab=<?= $k ?>" class="<?= $tab === $k ? 'is-active' : '' ?>"><?= e($label) ?><?php if ($count !== null): ?><span class="count"><?= $count ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
  <div class="grid grid-3">
    <div class="card span-2">
      <div class="card-head"><h2>Personal information</h2></div>
      <dl class="dl">
        <dt>Full name</dt><dd><?= e(full_name($s)) ?></dd>
        <dt>Gender</dt><dd><?= e($s['gender'] ? humanize($s['gender']) : '—') ?></dd>
        <dt>Date of birth</dt><dd><?= e(format_date($s['date_of_birth'])) ?></dd>
        <dt>National ID</dt><dd><?= e($s['national_id'] ?: '—') ?></dd>
        <dt>Nationality</dt><dd><?= e($s['nationality'] ?: '—') ?></dd>
        <dt>Email</dt><dd><?= $s['email'] ? '<a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : '—' ?></dd>
        <dt>Phone</dt><dd><?= e($s['phone'] ?: '—') ?></dd>
        <dt>Address</dt><dd><?= e($s['address'] ?: '—') ?></dd>
      </dl>
      <div class="card-head" style="margin-top:24px"><h2>Enrollment</h2></div>
      <dl class="dl">
        <dt>Program</dt><dd><?= e($s['program_code'] . ' – ' . $s['program_name']) ?> <span class="muted">(<?= e(humanize($s['level'])) ?>)</span></dd>
        <dt>Department</dt><dd><?= e($s['dept_name']) ?></dd>
        <dt>School</dt><dd><?= e($s['faculty_name']) ?></dd>
        <dt>Admission date</dt><dd><?= e(format_date($s['admission_date'])) ?></dd>
        <dt>Expected graduation</dt><dd><?= e(format_date($s['expected_graduation'], 'F Y')) ?></dd>
        <dt>Portal login</dt><dd><?= $s['user_id'] ? badge('active', 'Enabled') : '<span class="muted">Not created</span>' ?></dd>
        <dt>Record created</dt><dd><?= e(format_date($s['created_at'])) ?><?= $s['created_by_name'] ? ' by ' . e($s['created_by_name']) : '' ?></dd>
      </dl>
    </div>
    <div class="stack">
      <?php if (can('students.edit')): ?>
      <form class="card" method="post" data-confirm="This changes the student's enrollment status and is recorded in the audit log." data-confirm-title="Change enrollment status?" data-confirm-button="Change status">
        <?= csrf_field() ?><input type="hidden" name="action" value="change_status">
        <h3>Enrollment status</h3>
        <div class="field"><label for="status">New status</label>
          <select id="status" name="status"><?php foreach (['active', 'graduated', 'deferred', 'suspended', 'withdrawn'] as $st): ?><option value="<?= $st ?>" <?= $s['enrollment_status'] === $st ? 'selected' : '' ?>><?= e(humanize($st)) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="margin-top:10px"><label for="reason">Reason <span class="req">*</span></label><input id="reason" name="reason" maxlength="200" placeholder="e.g. Approved deferment request"></div>
        <button class="btn btn-navy btn-block" style="margin-top:12px" type="submit">Update status</button>
      </form>
      <?php endif; ?>
      <div class="card">
        <h3>Emergency contact</h3>
        <?php $em = $guardians[0] ?? null; if ($em): ?>
          <p style="margin:0"><strong><?= e($em['full_name']) ?></strong> <span class="muted">· <?= e($em['relationship']) ?></span><br><?= e($em['phone']) ?><?= $em['email'] ? '<br>' . e($em['email']) : '' ?></p>
        <?php else: ?><p class="muted" style="margin:0">None recorded.</p><?php endif; ?>
      </div>
      <?php if (!$s['user_id'] && can('users.manage')): ?>
      <form class="card" method="post" data-confirm="Create a student portal login for <?= e($s['email'] ?: 'this student') ?>?" data-confirm-button="Create login">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_login">
        <h3>Student portal</h3><p class="muted small">This student can't sign in yet.</p>
        <button class="btn btn-ghost btn-block" type="submit"><?= icon('key') ?> Create portal login</button>
      </form>
      <?php endif; ?>
      <?php if (can('students.delete')): ?>
      <form class="card no-print" method="post" data-confirm="The record will be hidden from lists but kept for audit and compliance. Only a database administrator can restore it." data-confirm-title="Archive this student?" data-confirm-button="Archive">
        <?= csrf_field() ?><input type="hidden" name="action" value="archive">
        <h3>Archive record</h3><p class="muted small">Use for records created in error. Use status changes for withdrawals.</p>
        <button class="btn btn-danger-ghost btn-block" type="submit"><?= icon('trash') ?> Archive student</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'guardians'): ?>
  <div class="grid grid-3">
    <div class="card card-flush span-2">
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Email</th><th></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($guardians as $gd): ?>
          <tr><td><strong><?= e($gd['full_name']) ?></strong></td><td><?= e($gd['relationship']) ?></td><td><?= e($gd['phone']) ?></td><td><?= e($gd['email'] ?: '—') ?></td>
            <td><?= $gd['is_emergency_contact'] ? badge('warning', 'Emergency') : '' ?></td>
            <td class="actions"><?php if (can('students.edit')): ?>
              <form method="post" class="inline-form" data-confirm="Remove <?= e($gd['full_name']) ?> as a guardian?" data-confirm-button="Remove"><?= csrf_field() ?><input type="hidden" name="action" value="delete_guardian"><input type="hidden" name="guardian_id" value="<?= $gd['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Remove"><?= icon('trash') ?></button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$guardians): ?><tr class="empty-row"><td colspan="6">No guardians recorded.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php if (can('students.edit')): ?>
    <form class="card" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_guardian">
      <h3>Add guardian</h3>
      <div class="stack">
        <div class="field"><label for="gn">Full name</label><input id="gn" name="full_name" required maxlength="150"></div>
        <div class="field"><label for="gr">Relationship</label><select id="gr" name="relationship"><?php foreach (['Mother', 'Father', 'Guardian', 'Spouse', 'Sibling', 'Uncle', 'Aunt', 'Sponsor', 'Other'] as $r): ?><option><?= $r ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="gp">Phone</label><input id="gp" name="phone" type="tel" required maxlength="30"></div>
        <div class="field"><label for="ge">Email</label><input id="ge" name="email" type="email" maxlength="190"></div>
        <label class="checkbox"><input type="checkbox" name="is_emergency" value="1"> Emergency contact</label>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('plus') ?> Add guardian</button>
      </div>
    </form>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'documents'): ?>
  <div class="grid grid-3">
    <div class="card card-flush span-2">
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Document</th><th>Type</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
          <tr>
            <td><div class="person"><span class="list-icon"><?= icon('file') ?></span><div><strong><?= e($d['title']) ?></strong><small><?= e($d['original_name']) ?></small></div></div></td>
            <td><?= e(humanize($d['doc_type'])) ?></td>
            <td class="num"><?= number_format($d['file_size'] / 1024, 0) ?> KB</td>
            <td><?= e(format_date($d['created_at'])) ?><br><small class="muted"><?= e($d['uploader'] ?? '—') ?></small></td>
            <td class="actions">
              <a class="btn btn-ghost btn-sm" href="document.php?id=<?= $d['id'] ?>" aria-label="Download"><?= icon('download') ?></a>
              <?php if ($canDocs): ?><form method="post" class="inline-form" data-confirm="Delete “<?= e($d['title']) ?>”? This cannot be undone." data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete_document"><input type="hidden" name="document_id" value="<?= $d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$documents): ?><tr class="empty-row"><td colspan="5">No documents uploaded yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <?php if ($canDocs || $isOwner): ?>
    <form class="card" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_document">
      <h3>Upload document</h3>
      <div class="stack">
        <div class="field"><label for="dt">Title</label><input id="dt" name="title" required maxlength="150" placeholder="e.g. KCSE certificate"></div>
        <div class="field"><label for="dty">Type</label><select id="dty" name="doc_type"><?php foreach (['national_id' => 'National ID / Passport', 'transcript' => 'Transcript', 'certificate' => 'Certificate', 'admission_letter' => 'Admission letter', 'medical' => 'Medical', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="df">File</label><input id="df" type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"><span class="hint">PDF, JPG, PNG or Word, up to 5 MB. Files are stored outside the public web folder.</span></div>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('upload') ?> Upload</button>
      </div>
    </form>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'academic'): ?>
  <div class="card">
    <div class="card-head"><h2>Degree progress</h2><span class="badge badge-teal"><?= $pct ?>% complete</span></div>
    <div class="meter" style="height:12px"><span style="width:<?= $pct ?>%"></span></div>
    <p class="muted small" style="margin-top:8px"><?= (int) $s['credits_completed'] ?> of <?= (int) $s['total_credits'] ?> credits · <?= max(0, $s['total_credits'] - $s['credits_completed']) ?> remaining</p>
    <div class="notice" style="margin-top:18px"><?= icon('sparkles') ?><div><strong>Results, transcripts and registered units arrive in Phase 3</strong><br><span class="muted">Once examinations are live, GPA/CGPA will be computed from posted marks and this tab will show semester-by-semester results, registered units and a printable transcript.</span></div></div>
  </div>

<?php elseif ($tab === 'history'):
    $history = q("SELECT a.*, u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                  WHERE a.entity_type = 'student' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 50", [(string) $id])->fetchAll(); ?>
  <div class="card card-flush">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): $meta = $h['metadata'] ? json_decode($h['metadata'], true) : null; ?>
        <tr><td style="white-space:nowrap"><?= e(format_date($h['created_at'], 'M j, Y H:i')) ?></td><td><?= e($h['user_name'] ?? 'System') ?></td><td><?= badge('neutral', humanize($h['action'])) ?></td>
          <td><?= e($h['description']) ?>
            <?php if (is_array($meta) && $meta): ?><details class="small"><summary class="muted">Details</summary><ul style="margin:6px 0;padding-left:18px">
              <?php foreach ($meta as $k => $m): ?><li><b><?= e(humanize((string) $k)) ?>:</b> <?= is_array($m) ? e(($m['from'] ?? '∅') . ' → ' . ($m['to'] ?? '∅')) : e((string) $m) ?></li><?php endforeach; ?>
            </ul></details><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr class="empty-row"><td colspan="4">No recorded history.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
<?php endif;
render_footer();
