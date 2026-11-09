<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

function chart_data(string $id, array $cfg): string
{
    return '<script type="application/json" id="' . e($id) . '">' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}

function greeting(): string
{
    $h = (int) date('G');
    return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
}

$firstName = preg_replace('/^(Dr\.|Prof\.)\s+/', '', $user['name']);
$firstName = explode(' ', $firstName)[0];
$term = q_one('SELECT * FROM academic_terms WHERE is_current = 1 LIMIT 1');
$upcoming = q('SELECT * FROM calendar_events WHERE event_date >= CURDATE() ORDER BY event_date LIMIT 5')->fetchAll();
$eventIcons = ['exam' => 'award', 'deadline' => 'clock', 'holiday' => 'sun', 'academic' => 'calendar', 'event' => 'sparkles'];

function render_upcoming(array $upcoming, array $eventIcons): void
{ ?>
  <div class="card-head"><h2>Upcoming dates</h2><a class="small" href="<?= can('settings.manage') ? 'settings.php?tab=calendar' : '#' ?>">Calendar</a></div>
  <?php if (!$upcoming): ?><p class="muted">No upcoming dates.</p><?php endif; ?>
  <ul class="list">
    <?php foreach ($upcoming as $ev): ?>
      <li>
        <div class="date-chip"><b><?= e(date('M', strtotime($ev['event_date']))) ?></b><span><?= e(date('j', strtotime($ev['event_date']))) ?></span></div>
        <div class="list-body"><strong><?= e($ev['title']) ?></strong><span><?= e(humanize($ev['event_type'])) ?> · <?= e(date('l', strtotime($ev['event_date']))) ?><?php
            $days = (int) floor((strtotime($ev['event_date']) - strtotime('today')) / 86400);
            echo $days === 0 ? ' · today' : ($days === 1 ? ' · tomorrow' : " · in {$days} days"); ?></span></div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php }

function render_today_programme(?array $student, string $title = "Today's programme"): void
{
    [$sql, $params] = schedule_audience_filter($student);
    $items = q("SELECT si.* FROM schedule_items si WHERE si.item_date = CURDATE() AND {$sql} ORDER BY si.start_time LIMIT 8", $params)->fetchAll();
    $nextDay = $items ? null : q("SELECT MIN(si.item_date) FROM schedule_items si WHERE si.item_date > CURDATE() AND {$sql}", $params)->fetchColumn();
    $cats = schedule_category_meta();
    $now = date('H:i:s'); ?>
  <div class="card-head"><h2><?= e($title) ?></h2><a class="small" href="programme.php">Full programme</a></div>
  <?php if (!$items): ?>
    <p class="muted" style="margin:0">Nothing scheduled for today<?= $nextDay ? '. Next: <a href="programme.php?date=' . e($nextDay) . '">' . e(format_date($nextDay, 'l, M j')) . '</a>' : '' ?>.</p>
  <?php else: ?>
  <ol class="timeline compact">
    <?php foreach ($items as $it):
        [$label, $ic, $tone] = $cats[$it['category']];
        $live = $it['start_time'] <= $now && ($it['end_time'] ?? $it['start_time']) >= $now;
        $past = ($it['end_time'] ?? $it['start_time']) < $now; ?>
      <li class="<?= $live ? 'is-live' : '' ?><?= $past ? ' is-past' : '' ?>">
        <div class="tl-time"><b><?= e(substr($it['start_time'], 0, 5)) ?></b><?= $it['end_time'] ? '<span>' . e(substr($it['end_time'], 0, 5)) . '</span>' : '' ?></div>
        <div class="tl-dot kpi-icon <?= $tone ?>"><?= icon($ic) ?></div>
        <div class="tl-body"><strong><?= e($it['title']) ?></strong> <?= $live ? '<span class="badge badge-success">Now</span>' : '' ?><div class="muted small"><?= e($label) ?><?= $it['venue'] ? ' · ' . e($it['venue']) : '' ?></div></div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif;
}

function render_announcements(int $limit = 3): void
{
    [$where, $params] = announcement_audience_filter();
    $items = q("SELECT a.*, u.name author FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE {$where} ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT {$limit}", $params)->fetchAll(); ?>
  <div class="card-head"><h2>Announcements</h2><a class="small" href="announcements.php">View all</a></div>
  <ul class="list">
    <?php foreach ($items as $a): ?>
      <li><span class="list-icon"><?= icon($a['is_pinned'] ? 'sparkles' : 'message') ?></span><div class="list-body"><a href="announcements.php"><strong><?= e($a['title']) ?></strong></a><span><?= e(mb_strimwidth($a['body'], 0, 110, '…')) ?></span><br><span><?= e($a['author'] ?? 'Administration') ?> · <?= e(time_ago($a['created_at'])) ?></span></div></li>
    <?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No announcements right now.</li><?php endif; ?>
  </ul>
<?php }

/* ======================================================================
 * Student portal
 * ==================================================================== */
if (!can('dashboard.analytics') && has_role('student')) {
    $s = q_one('SELECT s.*, p.name program_name, p.total_credits, p.duration_years, d.name dept_name, f.name faculty_name
                  FROM students s JOIN programs p ON p.id = s.program_id JOIN departments d ON d.id = p.department_id
                  JOIN faculties f ON f.id = d.faculty_id WHERE s.user_id = ? AND s.deleted_at IS NULL', [$user['id']]);
    render_header('Dashboard', 'dashboard');
    page_header(greeting() . ', ' . $firstName, $term ? "{$term['name']} · {$term['academic_year']}" : '');
    if (!$s): ?>
      <div class="card empty-state"><div class="empty-icon"><?= icon('user') ?></div><h2>No student record linked</h2><p>Your account isn't linked to a student record yet. Please contact the Registrar's office.</p></div>
    <?php else:
        $pct = $s['total_credits'] ? min(100, round($s['credits_completed'] / $s['total_credits'] * 100)) : 0;
        $docs = (int) q_val('SELECT COUNT(*) FROM student_documents WHERE student_id = ?', [$s['id']]); ?>
      <div class="grid grid-4">
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Current GPA</span><span class="kpi-icon"><?= icon('award') ?></span></div><div class="kpi-value"><?= $s['current_gpa'] !== null ? e(number_format((float) $s['current_gpa'], 2)) : '—' ?></div><div class="kpi-breakdown"><?= badge($s['academic_standing'], $s['academic_standing'] === 'good' ? 'Good standing' : null) ?></div></div>
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Credits completed</span><span class="kpi-icon navy"><?= icon('book') ?></span></div><div class="kpi-value"><?= (int) $s['credits_completed'] ?><span class="muted" style="font-size:1rem"> / <?= (int) $s['total_credits'] ?></span></div><div class="meter"><span style="width:<?= $pct ?>%"></span></div></div>
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Degree completion</span><span class="kpi-icon"><?= icon('graduation') ?></span></div><div class="kpi-value"><?= $pct ?>%</div><div class="kpi-breakdown">Expected <?= e(format_date($s['expected_graduation'], 'M Y')) ?></div></div>
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Year of study</span><span class="kpi-icon amber"><?= icon('calendar') ?></span></div><div class="kpi-value">Year <?= (int) $s['year_of_study'] ?></div><div class="kpi-breakdown">of <?= (int) $s['duration_years'] ?> · <?= badge($s['enrollment_status']) ?></div></div>
      </div>
      <div class="grid grid-3" style="margin-top:16px">
        <div class="card span-2"><?php render_today_programme($s); ?></div>
        <div class="card"><?php render_announcements(); ?></div>
      </div>
      <div class="grid grid-3" style="margin-top:16px">
        <div class="card span-2">
          <div class="card-head"><h2>My programme of study</h2><a class="btn btn-ghost btn-sm" href="student_view.php">View full profile</a></div>
          <dl class="dl">
            <dt>Student ID</dt><dd class="mono"><?= e($s['student_no']) ?></dd>
            <dt>Programme</dt><dd><?= e($s['program_name']) ?></dd>
            <dt>Department</dt><dd><?= e($s['dept_name']) ?></dd>
            <dt>School</dt><dd><?= e($s['faculty_name']) ?></dd>
            <dt>Admitted</dt><dd><?= e(format_date($s['admission_date'])) ?></dd>
            <dt>Documents on file</dt><dd><?= $docs ?> · <a href="student_view.php?tab=documents">Manage</a></dd>
          </dl>
          <div class="notice" style="margin-top:18px"><?= icon('sparkles') ?><div><strong>Coming to your portal</strong><br><span class="muted">Unit registration, results and transcripts (Phase 3), fee statements and M-Pesa payments (Phase 4), and learning materials (Phase 5).</span></div></div>
        </div>
        <div class="card"><?php render_upcoming($upcoming, $eventIcons); ?></div>
      </div>
    <?php endif;
    render_footer();
    exit;
}

/* ======================================================================
 * Lecturer / limited staff dashboard
 * ==================================================================== */
if (!can('dashboard.analytics')) {
    $staff = q_one('SELECT st.*, d.name dept_name FROM staff st LEFT JOIN departments d ON d.id = st.department_id WHERE st.user_id = ?', [$user['id']]);
    $deptStudents = $staff && $staff['department_id']
        ? (int) q_val("SELECT COUNT(*) FROM students s JOIN programs p ON p.id = s.program_id WHERE p.department_id = ? AND s.enrollment_status = 'active' AND s.deleted_at IS NULL", [$staff['department_id']])
        : 0;
    render_header('Dashboard', 'dashboard');
    page_header(greeting() . ', ' . $firstName, ($staff ? ($staff['designation'] . ' · ' . ($staff['dept_name'] ?? '')) : reset($user['roles'])) . ($term ? " · {$term['name']} {$term['academic_year']}" : '')); ?>
    <div class="grid grid-3">
      <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Active students in my department</span><span class="kpi-icon"><?= icon('users') ?></span></div><div class="kpi-value"><?= number_format($deptStudents) ?></div><div class="kpi-breakdown"><?= e($staff['dept_name'] ?? 'No department assigned') ?></div></div>
      <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Assigned courses</span><span class="kpi-icon navy"><?= icon('book') ?></span></div><div class="kpi-value">—</div><div class="kpi-breakdown">Available when course registration launches (Phase 3)</div></div>
      <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Marks pending entry</span><span class="kpi-icon amber"><?= icon('clipboard') ?></span></div><div class="kpi-value">—</div><div class="kpi-breakdown">Available with exams &amp; results (Phase 3)</div></div>
    </div>
    <div class="grid grid-2" style="margin-top:16px">
      <div class="card"><?php render_today_programme(null, "Today's university programme"); ?></div>
      <div class="card"><?php render_announcements(); ?></div>
    </div>
    <div class="grid grid-2" style="margin-top:16px">
      <div class="card">
        <div class="card-head"><h2>Quick actions</h2></div>
        <div class="quick-actions">
          <?php if (can('students.view')): ?><a class="quick-action" href="students.php"><?= icon('search') ?> Find a student</a><?php endif; ?>
          <?php if (can('staff.view')): ?><a class="quick-action" href="staff.php"><?= icon('briefcase') ?> Staff directory</a><?php endif; ?>
          <a class="quick-action" href="notifications.php"><?= icon('bell') ?> Notifications</a>
          <a class="quick-action" href="account.php"><?= icon('key') ?> Account security</a>
        </div>
      </div>
      <div class="card"><?php render_upcoming($upcoming, $eventIcons); ?></div>
    </div>
    <?php render_footer();
    exit;
}

/* ======================================================================
 * Institutional analytics dashboard (admin, registrar, finance)
 * ==================================================================== */
$status = q("SELECT enrollment_status, COUNT(*) c FROM students WHERE deleted_at IS NULL AND enrollment_status <> 'pending' GROUP BY enrollment_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingRegistrations = can('registrations.approve') ? (int) q_val("SELECT COUNT(*) FROM students WHERE enrollment_status = 'pending' AND deleted_at IS NULL") : 0;
$statuses = ['active', 'graduated', 'deferred', 'suspended', 'withdrawn'];
foreach ($statuses as $st) $status[$st] = (int) ($status[$st] ?? 0);
$totalStudents = array_sum($status);
$retention = $totalStudents ? round(($totalStudents - $status['withdrawn']) / $totalStudents * 100, 1) : 0;

$structure = q_one('SELECT (SELECT COUNT(*) FROM faculties) faculties, (SELECT COUNT(*) FROM departments) departments, (SELECT COUNT(*) FROM programs) programs, (SELECT COUNT(*) FROM campuses) campuses');
$staffCounts = q("SELECT category, COUNT(*) c FROM staff WHERE status <> 'exited' GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalStaff = array_sum($staffCounts);

$byFaculty = q("SELECT f.code, f.name, COUNT(s.id) c FROM faculties f
                 LEFT JOIN departments d ON d.faculty_id = f.id
                 LEFT JOIN programs p ON p.department_id = d.id
                 LEFT JOIN students s ON s.program_id = p.id AND s.deleted_at IS NULL AND s.enrollment_status = 'active'
                GROUP BY f.id, f.code, f.name ORDER BY c DESC")->fetchAll();
$intake = q("SELECT YEAR(admission_date) y, COUNT(*) c FROM students WHERE deleted_at IS NULL AND enrollment_status <> 'pending' AND admission_date >= DATE_SUB(CURDATE(), INTERVAL 6 YEAR) GROUP BY y ORDER BY y")->fetchAll(PDO::FETCH_KEY_PAIR);
$gpaBands = ['Below 2.0' => 0, '2.0–2.49' => 0, '2.5–2.99' => 0, '3.0–3.49' => 0, '3.5–4.0' => 0];
foreach (q("SELECT CASE WHEN current_gpa >= 3.5 THEN '3.5–4.0' WHEN current_gpa >= 3.0 THEN '3.0–3.49' WHEN current_gpa >= 2.5 THEN '2.5–2.99'
                         WHEN current_gpa >= 2.0 THEN '2.0–2.49' ELSE 'Below 2.0' END band, COUNT(*) c
              FROM students WHERE current_gpa IS NOT NULL AND enrollment_status = 'active' AND deleted_at IS NULL GROUP BY band")->fetchAll() as $r) {
    $gpaBands[$r['band']] = (int) $r['c'];
}
$gpaRated = array_sum($gpaBands);
$passRate = $gpaRated ? round(($gpaRated - $gpaBands['Below 2.0']) / $gpaRated * 100, 1) : 0;
$avgGpa = q_val("SELECT ROUND(AVG(current_gpa), 2) FROM students WHERE current_gpa IS NOT NULL AND enrollment_status = 'active' AND deleted_at IS NULL");
$atRisk = (int) q_val("SELECT COUNT(*) FROM students WHERE academic_standing IN ('probation','warning') AND enrollment_status = 'active' AND deleted_at IS NULL");
$byYear = q("SELECT year_of_study, COUNT(*) c FROM students WHERE enrollment_status = 'active' AND deleted_at IS NULL GROUP BY year_of_study ORDER BY year_of_study")->fetchAll(PDO::FETCH_KEY_PAIR);
$byCampus = q("SELECT c.name, COUNT(s.id) n FROM campuses c LEFT JOIN students s ON s.campus_id = c.id AND s.enrollment_status = 'active' AND s.deleted_at IS NULL GROUP BY c.id, c.name ORDER BY n DESC")->fetchAll();
$recent = q('SELECT a.*, u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
              WHERE a.action NOT IN (\'login\',\'logout\',\'access_denied\',\'view\') ORDER BY a.id DESC LIMIT 7')->fetchAll();
$recentLogins = (int) q_val('SELECT COUNT(DISTINCT user_id) FROM login_history WHERE success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');

$actionIcons = ['create' => 'plus', 'update' => 'edit', 'delete' => 'trash', 'archive' => 'trash', 'upload' => 'upload', 'export' => 'download', 'status_change' => 'activity'];

render_header('Dashboard', 'dashboard');
$actions = '<div class="rel"><button class="btn btn-ghost" type="button" data-widget-customize>' . icon('sliders') . ' Customize</button><div class="widget-panel" data-widget-panel hidden><strong class="small">Show widgets</strong></div></div>'
         . '<button class="btn btn-ghost" type="button" data-print>' . icon('printer') . ' PDF</button>'
         . (can('students.export') ? '<a class="btn btn-ghost" href="students.php?export=csv">' . icon('download') . ' Excel (CSV)</a>' : '')
         . (can('students.create') ? '<a class="btn btn-primary" href="student_form.php">' . icon('user-plus') . ' Add student</a>' : '');
page_header(greeting() . ', ' . $firstName, ($term ? "{$term['name']} · {$term['academic_year']} · " : '') . date('l, j F Y'), $actions);
?>
<?php if ($pendingRegistrations): ?>
  <div class="alert alert-warning" style="align-items:center"><span><?= icon('clipboard') ?> <strong><?= $pendingRegistrations ?> student registration<?= $pendingRegistrations === 1 ? '' : 's' ?></strong> waiting for your approval.</span><a class="btn btn-sm btn-navy" href="registrations.php">Review now</a></div>
<?php endif; ?>

<div class="grid grid-4" data-widget="kpis" data-widget-title="Key figures">
  <div class="card kpi">
    <div class="kpi-top"><span class="kpi-label">Total students</span><span class="kpi-icon"><?= icon('users') ?></span></div>
    <div class="kpi-value"><?= number_format($totalStudents) ?></div>
    <div class="kpi-breakdown"><span>Active <b><?= number_format($status['active']) ?></b></span><span>Graduated <b><?= $status['graduated'] ?></b></span><span>Deferred <b><?= $status['deferred'] ?></b></span><span>Suspended <b><?= $status['suspended'] ?></b></span></div>
  </div>
  <div class="card kpi">
    <div class="kpi-top"><span class="kpi-label">Academic programs</span><span class="kpi-icon navy"><?= icon('layers') ?></span></div>
    <div class="kpi-value"><?= (int) $structure['programs'] ?></div>
    <div class="kpi-breakdown"><span>Faculties <b><?= (int) $structure['faculties'] ?></b></span><span>Departments <b><?= (int) $structure['departments'] ?></b></span><span>Campuses <b><?= (int) $structure['campuses'] ?></b></span></div>
  </div>
  <div class="card kpi">
    <div class="kpi-top"><span class="kpi-label">Academic performance</span><span class="kpi-icon amber"><?= icon('award') ?></span></div>
    <div class="kpi-value"><?= $passRate ?>%</div>
    <div class="kpi-breakdown"><span>Pass rate (GPA ≥ 2.0)</span><span>Mean GPA <b><?= e($avgGpa ?? '—') ?></b></span><span>At risk <b><?= $atRisk ?></b></span></div>
  </div>
  <div class="card kpi">
    <div class="kpi-top"><span class="kpi-label">Staff</span><span class="kpi-icon rose"><?= icon('briefcase') ?></span></div>
    <div class="kpi-value"><?= number_format($totalStaff) ?></div>
    <div class="kpi-breakdown"><span>Lecturers <b><?= (int) ($staffCounts['lecturer'] ?? 0) ?></b></span><span>Admin <b><?= (int) ($staffCounts['administrator'] ?? 0) ?></b></span><span>Support <b><?= (int) ($staffCounts['support'] ?? 0) ?></b></span></div>
  </div>
</div>

<div class="grid grid-3" style="margin-top:16px">
  <div class="card span-2" data-widget="faculty" data-widget-title="Enrollment by school">
    <div class="card-head"><h2>Active enrollment by school</h2><span class="badge badge-teal"><?= number_format($status['active']) ?> active</span></div>
    <div class="chart-box"><canvas data-chart="c-faculty" aria-label="Active enrollment by school"></canvas></div>
    <?= chart_data('c-faculty', ['type' => 'bar', 'horizontal' => true, 'labels' => array_column($byFaculty, 'name'), 'datasets' => [['label' => 'Active students', 'data' => array_map('intval', array_column($byFaculty, 'c')), 'color' => 0]]]) ?>
  </div>
  <div class="card" data-widget="status" data-widget-title="Student status">
    <div class="card-head"><h2>Student status</h2><span class="muted small">Retention <?= $retention ?>%</span></div>
    <div class="chart-box"><canvas data-chart="c-status" aria-label="Students by status"></canvas></div>
    <?= chart_data('c-status', ['type' => 'doughnut', 'labels' => array_map('humanize', $statuses), 'datasets' => [['label' => 'Students', 'data' => array_values(array_map(fn($s) => $status[$s], $statuses)), 'colors' => [0, 1, 3, 4, 5]]]]) ?>
  </div>
</div>

<div class="grid grid-3" style="margin-top:16px">
  <div class="card" data-widget="intake" data-widget-title="Admissions trend">
    <div class="card-head"><h2>Intake by year</h2><span class="muted small">Admissions</span></div>
    <div class="chart-box sm"><canvas data-chart="c-intake" aria-label="Student intake by year"></canvas></div>
    <?= chart_data('c-intake', ['type' => 'line', 'labels' => array_map('strval', array_keys($intake)), 'datasets' => [['label' => 'New students', 'data' => array_map('intval', array_values($intake)), 'color' => 0]]]) ?>
  </div>
  <div class="card" data-widget="gpa" data-widget-title="GPA distribution">
    <div class="card-head"><h2>GPA distribution</h2><span class="muted small"><?= $gpaRated ?> graded</span></div>
    <div class="chart-box sm"><canvas data-chart="c-gpa" aria-label="GPA distribution"></canvas></div>
    <?= chart_data('c-gpa', ['type' => 'bar', 'labels' => array_keys($gpaBands), 'datasets' => [['label' => 'Students', 'data' => array_values($gpaBands), 'color' => 1]]]) ?>
  </div>
  <div class="card" data-widget="year" data-widget-title="Year of study">
    <div class="card-head"><h2>By year of study</h2><span class="muted small">Active</span></div>
    <div class="chart-box sm"><canvas data-chart="c-year" aria-label="Students by year of study"></canvas></div>
    <?= chart_data('c-year', ['type' => 'bar', 'labels' => array_map(fn($y) => "Year $y", array_keys($byYear)), 'datasets' => [['label' => 'Students', 'data' => array_map('intval', array_values($byYear)), 'color' => 2]]]) ?>
  </div>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card" data-widget="today" data-widget-title="Today's programme"><?php render_today_programme(null, "Today's university programme"); ?></div>
  <div class="card" data-widget="announcements" data-widget-title="Announcements"><?php render_announcements(); ?></div>
</div>

<div class="grid grid-3" style="margin-top:16px">
  <div class="card" data-widget="activity" data-widget-title="Recent activity">
    <div class="card-head"><h2>Recent activity</h2><?php if (can('audit.view')): ?><a class="small" href="audit.php">Audit log</a><?php endif; ?></div>
    <ul class="list">
      <?php foreach ($recent as $a): ?>
        <li><span class="list-icon"><?= icon($actionIcons[$a['action']] ?? 'activity') ?></span>
          <div class="list-body"><strong><?= e($a['description'] ?: humanize($a['action']) . ' ' . $a['entity_type']) ?></strong><span><?= e($a['user_name'] ?? 'System') ?> · <?= e(time_ago($a['created_at'])) ?></span></div></li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li class="muted">No activity recorded yet. Changes to records will appear here.</li><?php endif; ?>
    </ul>
  </div>
  <div class="card" data-widget="calendar" data-widget-title="Upcoming dates"><?php render_upcoming($upcoming, $eventIcons); ?></div>
  <div class="card" data-widget="actions" data-widget-title="Quick actions">
    <div class="card-head"><h2>Quick actions</h2></div>
    <div class="quick-actions">
      <?php if (can('students.create')): ?><a class="quick-action" href="student_form.php"><?= icon('user-plus') ?> Add student</a><?php endif; ?>
      <?php if (can('registrations.approve')): ?><a class="quick-action" href="registrations.php"><?= icon('clipboard') ?> Approve registrations</a><?php endif; ?>
      <?php if (can('schedule.manage')): ?><a class="quick-action" href="programme.php"><?= icon('calendar') ?> Edit daily programme</a><?php endif; ?>
      <?php if (can('announcements.manage')): ?><a class="quick-action" href="announcements.php"><?= icon('message') ?> Post announcement</a><?php endif; ?>
      <?php if (can('students.view')): ?><a class="quick-action" href="students.php?standing=probation"><?= icon('activity') ?> At-risk students</a><?php endif; ?>
      <?php if (can('students.export')): ?><a class="quick-action" href="students.php?export=csv"><?= icon('download') ?> Enrollment report</a><?php endif; ?>
      <?php if (can('academics.manage')): ?><a class="quick-action" href="academics.php"><?= icon('layers') ?> Programs</a><?php endif; ?>
      <?php if (can('users.manage')): ?><a class="quick-action" href="users.php"><?= icon('shield') ?> Manage users</a><?php endif; ?>
      <?php if (can('settings.manage')): ?><a class="quick-action" href="settings.php?tab=calendar"><?= icon('calendar') ?> Academic calendar</a><?php endif; ?>
    </div>
    <div class="muted small" style="margin-top:14px"><?= $recentLogins ?> user<?= $recentLogins === 1 ? '' : 's' ?> signed in during the last 24 hours.</div>
  </div>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card" data-widget="campus" data-widget-title="Campus distribution">
    <div class="card-head"><h2>Active students by campus</h2></div>
    <?php $maxC = max(1, ...array_map(fn($c) => (int) $c['n'], $byCampus ?: [['n' => 1]])); ?>
    <div class="stack">
      <?php foreach ($byCampus as $c): ?>
        <div><div style="display:flex;justify-content:space-between" class="small"><span><?= e($c['name']) ?></span><b><?= number_format((int) $c['n']) ?></b></div><div class="meter"><span style="width:<?= round($c['n'] / $maxC * 100) ?>%"></span></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card" data-widget="roadmap" data-widget-title="Implementation roadmap">
    <div class="card-head"><h2>Implementation roadmap</h2><span class="badge badge-teal">Phase 1–2 live</span></div>
    <div class="roadmap">
      <?php foreach ([
          ['Phase 1', 'Foundation', true], ['Phase 2', 'Student records', true], ['Phase 3', 'Academics', false], ['Phase 4', 'Finance & M-Pesa', false],
          ['Phase 5', 'Portals & comms', false], ['Phase 6', 'AI & analytics', false], ['Phase 7', 'Deployment', false],
      ] as [$p, $label, $done]): ?>
        <div class="phase<?= $done ? ' done' : '' ?>"><b><?= e($p) ?></b><?= e($label) ?></div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin:12px 0 0">Finance, admissions and attendance widgets appear here as those modules go live.</p>
  </div>
</div>
<?php render_footer();
