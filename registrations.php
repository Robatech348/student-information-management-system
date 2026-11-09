<?php
/** Review queue for student self-registrations (admin / registrar). */
require __DIR__ . '/includes/bootstrap.php';
$me = require_permission('registrations.approve');

$programs = q('SELECT p.id, p.code, p.name, p.duration_years FROM programs p ORDER BY p.code')->fetchAll();
$programMap = array_column($programs, null, 'id');

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $ids = $action === 'approve_selected' ? array_map('intval', (array) ($_POST['ids'] ?? [])) : [(int) post('id')];
    $approved = 0;
    foreach ($ids as $sid) {
        $s = q_one("SELECT * FROM students WHERE id = ? AND enrollment_status = 'pending' AND deleted_at IS NULL", [$sid]);
        if (!$s) continue;

        if ($action === 'approve' || $action === 'approve_selected') {
            $programId = $action === 'approve' && isset($programMap[(int) post('program_id')]) ? (int) post('program_id') : (int) $s['program_id'];
            $year = $action === 'approve' ? max(1, min((int) post('year_of_study', '1'), (int) $programMap[$programId]['duration_years'])) : 1;
            db()->beginTransaction();
            $studentNo = next_student_no();
            q("UPDATE students SET student_no = ?, program_id = ?, year_of_study = ?, enrollment_status = 'active', admission_date = CURDATE(),
                     expected_graduation = DATE_ADD(CURDATE(), INTERVAL ? YEAR), approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$studentNo, $programId, $year, (int) $programMap[$programId]['duration_years'] - $year + 1, $me['id'], $sid]);
            q("UPDATE users SET status = 'active' WHERE id = ?", [$s['user_id']]);
            db()->commit();
            notify((int) $s['user_id'], 'Your registration has been approved', "Welcome! Your student number is {$studentNo}. You can now sign in with it or your email.", 'dashboard.php');
            audit('approve', 'student', $sid, "Approved registration {$s['student_no']} → {$studentNo} (" . full_name($s) . ')',
                $programId !== (int) $s['program_id'] ? ['program' => ['from' => $programMap[$s['program_id']]['code'] ?? '', 'to' => $programMap[$programId]['code']]] : []);
            $approved++;
        } elseif ($action === 'reject') {
            $reason = post('reason');
            if ($reason === '') {
                flash('danger', 'Give a reason when rejecting a registration – the applicant will see it.');
                redirect('registrations.php');
            }
            q('UPDATE students SET rejection_reason = ?, deleted_at = NOW(), approved_by = ?, approved_at = NOW() WHERE id = ?', [mb_substr($reason, 0, 255), $me['id'], $sid]);
            q("UPDATE users SET status = 'rejected' WHERE id = ?", [$s['user_id']]);
            audit('reject', 'student', $sid, "Rejected registration {$s['student_no']} (" . full_name($s) . ')', ['reason' => $reason]);
            flash('success', full_name($s) . "'s registration was rejected.");
        }
    }
    if ($approved) {
        flash('success', $approved === 1 ? 'Registration approved – the student can now sign in.' : "{$approved} registrations approved.");
    }
    redirect('registrations.php' . (get('tab') ? '?tab=' . urlencode(get('tab')) : ''));
}

$tab = get('tab') === 'decided' ? 'decided' : 'pending';
$pendingCount = (int) q_val("SELECT COUNT(*) FROM students WHERE enrollment_status = 'pending' AND deleted_at IS NULL");
if ($tab === 'pending') {
    $rows = q("SELECT s.*, p.code program_code, p.name program_name, c.name campus_name FROM students s JOIN programs p ON p.id = s.program_id
               LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.enrollment_status = 'pending' AND s.deleted_at IS NULL ORDER BY s.created_at")->fetchAll();
} else {
    $rows = q("SELECT s.*, p.code program_code, u.name decided_by, us.status account_status FROM students s JOIN programs p ON p.id = s.program_id
               LEFT JOIN users u ON u.id = s.approved_by LEFT JOIN users us ON us.id = s.user_id
               WHERE s.registration_source = 'self' AND s.approved_at IS NOT NULL ORDER BY s.approved_at DESC LIMIT 50")->fetchAll();
}
$mode = setting('student_registration_mode', 'approval');

render_header('Student registrations', 'registrations');
page_header('Student registrations', 'Students who signed up online. Approving assigns an official student number and unlocks the student portal.',
    can('settings.manage') ? '<a class="btn btn-ghost" href="settings.php#registration">' . icon('settings') . ' Registration mode: ' . e(['approval' => 'Requires approval', 'open' => 'Instant access', 'closed' => 'Closed'][$mode]) . '</a>' : '');
?>
<nav class="tabs">
  <a href="registrations.php" class="<?= $tab === 'pending' ? 'is-active' : '' ?>">Awaiting approval <span class="count"><?= $pendingCount ?></span></a>
  <a href="registrations.php?tab=decided" class="<?= $tab === 'decided' ? 'is-active' : '' ?>">Recent decisions</a>
</nav>

<?php if ($tab === 'pending'): ?>
  <?php if (!$rows): ?>
    <div class="card empty-state"><div class="empty-icon"><?= icon('check') ?></div><h2>All caught up</h2><p class="muted">There are no registrations waiting for review.</p></div>
  <?php else: ?>
    <form method="post" id="bulk" data-confirm="Approve all selected registrations with the programme they chose, in Year 1?" data-confirm-button="Approve selected">
      <?= csrf_field() ?><input type="hidden" name="action" value="approve_selected">
    </form>
    <div class="stack">
    <?php foreach ($rows as $s): ?>
      <div class="card reg-card">
        <div class="reg-head">
          <label class="checkbox"><input type="checkbox" name="ids[]" value="<?= $s['id'] ?>" form="bulk" aria-label="Select <?= e(full_name($s)) ?>"></label>
          <span class="avatar"><?= e(initials($s['first_name'] . ' ' . $s['last_name'])) ?></span>
          <div style="flex:1;min-width:0"><strong><?= e(full_name($s)) ?></strong> <?= badge('pending', 'Pending') ?><br><span class="muted small mono"><?= e($s['student_no']) ?></span> <span class="muted small">· submitted <?= e(time_ago($s['created_at'])) ?></span></div>
        </div>
        <dl class="dl reg-details">
          <dt>Email</dt><dd><?= e($s['email']) ?></dd>
          <dt>Phone</dt><dd><?= e($s['phone']) ?></dd>
          <dt>ID number</dt><dd class="mono"><?= e($s['national_id']) ?></dd>
          <dt>Date of birth</dt><dd><?= e(format_date($s['date_of_birth'])) ?> · <?= e(humanize((string) $s['gender'])) ?></dd>
          <dt>Programme chosen</dt><dd><?= e($s['program_code'] . ' – ' . $s['program_name']) ?></dd>
          <dt>Campus</dt><dd><?= e($s['campus_name'] ?? '—') ?></dd>
        </dl>
        <div class="reg-actions">
          <form method="post" class="reg-approve">
            <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <select name="program_id" aria-label="Programme"><?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>" <?= (int) $p['id'] === (int) $s['program_id'] ? 'selected' : '' ?>><?= e($p['code']) ?></option><?php endforeach; ?></select>
            <select name="year_of_study" aria-label="Year of study"><?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?></select>
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Approve</button>
          </form>
          <form method="post" class="reg-reject" data-confirm="Reject <?= e(full_name($s)) ?>'s registration? They will see your reason when they try to sign in." data-confirm-title="Reject registration?" data-confirm-button="Reject">
            <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <input name="reason" placeholder="Reason for rejection" maxlength="255" aria-label="Reason for rejection">
            <button class="btn btn-danger-ghost" type="submit"><?= icon('x') ?> Reject</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn btn-navy" type="submit" form="bulk"><?= icon('check') ?> Approve selected</button></div>
  <?php endif; ?>
<?php else: ?>
  <div class="card card-flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>Student</th><th>Number</th><th>Programme</th><th>Decision</th><th>By</th><th>When</th></tr></thead><tbody>
    <?php foreach ($rows as $s): $rejected = $s['account_status'] === 'rejected'; ?>
      <tr><td><?= $rejected ? e(full_name($s)) : '<a href="student_view.php?id=' . $s['id'] . '">' . e(full_name($s)) . '</a>' ?><br><small class="muted"><?= e($s['email']) ?></small></td>
        <td class="mono"><?= e($s['student_no']) ?></td><td><?= e($s['program_code']) ?></td>
        <td><?= $rejected ? badge('rejected', 'Rejected') . '<br><small class="muted">' . e($s['rejection_reason']) . '</small>' : badge('active', 'Approved') ?></td>
        <td><?= e($s['decided_by'] ?? 'Automatic') ?></td><td><?= e(time_ago($s['approved_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="6">No decisions yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif;
render_footer();
