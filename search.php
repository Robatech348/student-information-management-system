<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$term = mb_substr(get('q'), 0, 100);
$results = [];
if (mb_strlen($term) >= 2) {
    $like = '%' . $term . '%';
    if (can('students.view')) {
        $results['Students'] = array_map(fn($r) => [
            'url' => 'student_view.php?id=' . $r['id'], 'title' => full_name($r), 'meta' => $r['student_no'] . ' · ' . $r['code'] . ' · Year ' . $r['year_of_study'],
            'badge' => badge($r['enrollment_status']), 'icon' => 'user',
        ], q("SELECT s.id, s.first_name, s.middle_name, s.last_name, s.student_no, s.year_of_study, s.enrollment_status, p.code
                FROM students s JOIN programs p ON p.id = s.program_id
               WHERE s.deleted_at IS NULL AND (CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE ? OR s.student_no LIKE ? OR s.email LIKE ? OR s.national_id = ?)
               ORDER BY s.last_name LIMIT 10", [$like, $like, $like, $term])->fetchAll());
    }
    if (can('staff.view')) {
        $results['Staff'] = array_map(fn($r) => [
            'url' => 'staff.php?q=' . urlencode($r['staff_no']), 'title' => $r['first_name'] . ' ' . $r['last_name'], 'meta' => $r['staff_no'] . ' · ' . $r['designation'],
            'badge' => '', 'icon' => 'briefcase',
        ], q("SELECT staff_no, first_name, last_name, designation FROM staff WHERE status <> 'exited' AND (CONCAT(first_name, ' ', last_name) LIKE ? OR staff_no LIKE ? OR email LIKE ?) LIMIT 8", [$like, $like, $like])->fetchAll());
    }
    $results['Programs'] = array_map(fn($r) => [
        'url' => can('students.view') ? 'students.php?program=' . $r['id'] : '#', 'title' => $r['name'], 'meta' => $r['code'] . ' · ' . $r['dept'], 'badge' => '', 'icon' => 'layers',
    ], q('SELECT p.id, p.code, p.name, d.name dept FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.name LIKE ? OR p.code LIKE ? LIMIT 8', [$like, $like])->fetchAll());
    if (can('users.manage')) {
        $results['User accounts'] = array_map(fn($r) => [
            'url' => 'users.php?edit=' . $r['id'], 'title' => $r['name'], 'meta' => $r['email'], 'badge' => badge($r['status']), 'icon' => 'key',
        ], q('SELECT id, name, email, status FROM users WHERE name LIKE ? OR email LIKE ? LIMIT 8', [$like, $like])->fetchAll());
    }
    $results = array_filter($results);
}

function highlight(string $text, string $term): string
{
    $safe = e($text);
    return $term === '' ? $safe : preg_replace('/' . preg_quote(e($term), '/') . '/i', '<mark>$0</mark>', $safe);
}

render_header('Search', '');
page_header('Search', $term !== '' ? 'Results for “' . $term . '”' : 'Search across students, staff, programs and users.');
?>
<form class="card filters" method="get" style="margin-bottom:16px">
  <div class="field grow"><label for="sq">Search</label><input id="sq" type="search" name="q" value="<?= e($term) ?>" autofocus placeholder="At least 2 characters"></div>
  <div class="toolbar"><button class="btn btn-primary" type="submit"><?= icon('search') ?> Search</button></div>
</form>
<?php if ($term !== '' && mb_strlen($term) < 2): ?>
  <p class="muted">Type at least 2 characters.</p>
<?php elseif ($term !== '' && !$results): ?>
  <div class="card empty-state"><div class="empty-icon"><?= icon('search') ?></div><h2>No matches</h2><p class="muted">Try a student ID, surname or program code.</p></div>
<?php endif; ?>
<?php foreach ($results as $group => $items): ?>
  <div class="card search-group">
    <div class="card-head"><h2><?= e($group) ?></h2><span class="badge badge-neutral"><?= count($items) ?></span></div>
    <ul class="list">
      <?php foreach ($items as $it): ?>
        <li><span class="list-icon"><?= icon($it['icon']) ?></span><div class="list-body"><a href="<?= e($it['url']) ?>"><strong><?= highlight($it['title'], $term) ?></strong></a><span><?= highlight($it['meta'], $term) ?></span></div><?= $it['badge'] ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach;
render_footer();
