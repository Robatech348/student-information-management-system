<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('students.view');

$programs = q('SELECT p.id, p.code, p.name, f.code faculty FROM programs p JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id ORDER BY f.code, p.name')->fetchAll();
$campuses = q('SELECT id, name FROM campuses ORDER BY name')->fetchAll();
$statuses = ['active', 'graduated', 'deferred', 'suspended', 'withdrawn'];
$standings = ['good' => 'Good standing', 'dean_list' => "Dean's list", 'warning' => 'Academic warning', 'probation' => 'Probation'];

$f = [
    'q'        => get('q'),
    'program'  => (int) get('program', '0'),
    'campus'   => (int) get('campus', '0'),
    'status'   => in_array(get('status'), $statuses, true) ? get('status') : '',
    'standing' => isset($standings[get('standing')]) ? get('standing') : '',
    'year'     => (int) get('year', '0'),
    'sort'     => get('sort', 'name'),
];

$where = ["s.deleted_at IS NULL", "s.enrollment_status <> 'pending'"];
$params = [];
if ($f['q'] !== '') {
    $where[] = "(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE ? OR CONCAT_WS(' ', s.last_name, s.first_name) LIKE ? OR s.student_no LIKE ? OR s.email LIKE ? OR s.national_id = ? OR s.phone LIKE ?)";
    $like = '%' . $f['q'] . '%';
    array_push($params, $like, $like, $like, $like, $f['q'], $like);
}
if ($f['program']) { $where[] = 's.program_id = ?'; $params[] = $f['program']; }
if ($f['campus']) { $where[] = 's.campus_id = ?'; $params[] = $f['campus']; }
if ($f['status']) { $where[] = 's.enrollment_status = ?'; $params[] = $f['status']; }
if ($f['standing']) { $where[] = 's.academic_standing = ?'; $params[] = $f['standing']; }
if ($f['year']) { $where[] = 's.year_of_study = ?'; $params[] = $f['year']; }
$sorts = [
    'name'    => 's.last_name, s.first_name',
    'newest'  => 's.admission_date DESC, s.id DESC',
    'number'  => 's.student_no',
    'gpa'     => 's.current_gpa IS NULL, s.current_gpa DESC',
    'gpa_low' => 's.current_gpa IS NULL, s.current_gpa ASC',
];
$orderBy = $sorts[$f['sort']] ?? $sorts['name'];
$whereSql = implode(' AND ', $where);
$from = "FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN campuses c ON c.id = s.campus_id WHERE {$whereSql}";

/* ---------- CSV export (opens in Excel) ---------- */
if (get('export') === 'csv') {
    require_permission('students.export');
    $rows = q("SELECT s.student_no, s.first_name, s.middle_name, s.last_name, s.gender, s.email, s.phone, p.code program_code, p.name program,
                      c.name campus, s.year_of_study, s.enrollment_status, s.academic_standing, s.current_gpa, s.credits_completed, s.admission_date
               {$from} ORDER BY {$orderBy}", $params);
    $count = 0;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel detects encoding
    fputcsv($out, ['Student No', 'First name', 'Middle name', 'Last name', 'Gender', 'Email', 'Phone', 'Program code', 'Program', 'Campus', 'Year', 'Status', 'Standing', 'GPA', 'Credits', 'Admission date']);
    while ($r = $rows->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, array_map('csv_safe', $r));
        $count++;
    }
    fclose($out);
    audit('export', 'student', null, "Exported {$count} student records", array_filter($f));
    exit;
}

$total = (int) q_val("SELECT COUNT(*) {$from}", $params);
$p = paginate($total, 15);
$students = q("SELECT s.id, s.student_no, s.first_name, s.middle_name, s.last_name, s.email, s.photo_path, s.year_of_study, s.enrollment_status,
                      s.academic_standing, s.current_gpa, p.code program_code, p.name program_name, c.name campus_name
               {$from} ORDER BY {$orderBy} LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params)->fetchAll();

render_header('Students', 'students');
$actions = (can('students.export') ? '<a class="btn btn-ghost" href="' . e(qs(['export' => 'csv', 'page' => null])) . '">' . icon('download') . ' Export CSV</a>' : '')
         . (can('students.create') ? '<a class="btn btn-primary" href="student_form.php">' . icon('user-plus') . ' Add student</a>' : '');
page_header('Students', number_format($total) . ' matching record' . ($total === 1 ? '' : 's'), $actions);
?>
<div class="card" style="margin-bottom:16px">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, student ID, email, phone or national ID"></div>
    <div class="field"><label for="program">Program</label>
      <select id="program" name="program" data-autosubmit><option value="">All programs</option>
        <?php foreach ($programs as $pr): ?><option value="<?= $pr['id'] ?>" <?= $f['program'] === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['code'] . ' – ' . $pr['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="status">Status</label>
      <select id="status" name="status" data-autosubmit><option value="">Any status</option>
        <?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(humanize($s)) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="standing">Standing</label>
      <select id="standing" name="standing" data-autosubmit><option value="">Any standing</option>
        <?php foreach ($standings as $k => $v): ?><option value="<?= $k ?>" <?= $f['standing'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="max-width:110px"><label for="year">Year</label>
      <select id="year" name="year" data-autosubmit><option value="">Any</option>
        <?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>" <?= $f['year'] === $y ? 'selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
      </select></div>
    <div class="field"><label for="campus">Campus</label>
      <select id="campus" name="campus" data-autosubmit><option value="">All campuses</option>
        <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>" <?= $f['campus'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="sort">Sort by</label>
      <select id="sort" name="sort" data-autosubmit>
        <?php foreach (['name' => 'Last name', 'newest' => 'Newest admissions', 'number' => 'Student ID', 'gpa' => 'GPA (high → low)', 'gpa_low' => 'GPA (low → high)'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
    <div class="toolbar"><button class="btn btn-navy" type="submit"><?= icon('filter') ?> Apply</button><a class="btn btn-ghost" href="students.php">Reset</a></div>
  </form>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Student</th><th>Student ID</th><th>Program</th><th>Year</th><th>Campus</th><th>Status</th><th>Standing</th><th class="num">GPA</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($students as $s): $name = full_name($s); ?>
        <tr>
          <td><div class="person">
            <?php if ($s['photo_path']): ?><img class="avatar" src="photo.php?id=<?= $s['id'] ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($s['first_name'] . ' ' . $s['last_name'])) ?></span><?php endif; ?>
            <div><a href="student_view.php?id=<?= $s['id'] ?>"><strong><?= e($name) ?></strong></a><small><?= e($s['email']) ?></small></div></div></td>
          <td class="mono"><?= e($s['student_no']) ?></td>
          <td title="<?= e($s['program_name']) ?>"><?= e($s['program_code']) ?></td>
          <td><?= (int) $s['year_of_study'] ?></td>
          <td><?= e($s['campus_name'] ?? '—') ?></td>
          <td><?= badge($s['enrollment_status']) ?></td>
          <td><?= badge($s['academic_standing'], $standings[$s['academic_standing']]) ?></td>
          <td class="num"><?= $s['current_gpa'] !== null ? e(number_format((float) $s['current_gpa'], 2)) : '—' ?></td>
          <td class="actions">
            <a class="btn btn-ghost btn-sm" href="student_view.php?id=<?= $s['id'] ?>" aria-label="View <?= e($name) ?>"><?= icon('eye') ?></a>
            <?php if (can('students.edit')): ?><a class="btn btn-ghost btn-sm" href="student_form.php?id=<?= $s['id'] ?>" aria-label="Edit <?= e($name) ?>"><?= icon('edit') ?></a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$students): ?><tr class="empty-row"><td colspan="9">No students match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($p); ?>
</div>
<?php render_footer();
