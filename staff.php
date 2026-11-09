<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('staff.view');

$departments = q('SELECT d.id, d.name, f.code FROM departments d JOIN faculties f ON f.id = d.faculty_id ORDER BY f.code, d.name')->fetchAll();
$cats = ['lecturer' => 'Lecturer', 'administrator' => 'Administrator', 'support' => 'Support staff'];
$search = get('q');
$cat = isset($cats[get('category')]) ? get('category') : '';
$dept = (int) get('department', '0');

$where = ["st.status <> 'exited'"];
$params = [];
if ($search !== '') { $where[] = "(CONCAT(st.first_name, ' ', st.last_name) LIKE ? OR st.staff_no LIKE ? OR st.email LIKE ?)"; array_push($params, "%{$search}%", "%{$search}%", "%{$search}%"); }
if ($cat) { $where[] = 'st.category = ?'; $params[] = $cat; }
if ($dept) { $where[] = 'st.department_id = ?'; $params[] = $dept; }
$w = implode(' AND ', $where);
$total = (int) q_val("SELECT COUNT(*) FROM staff st WHERE {$w}", $params);
$p = paginate($total, 20);
$rows = q("SELECT st.*, d.name dept_name FROM staff st LEFT JOIN departments d ON d.id = st.department_id WHERE {$w} ORDER BY st.last_name, st.first_name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params)->fetchAll();
$counts = q("SELECT category, COUNT(*) c FROM staff WHERE status <> 'exited' GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Staff directory', 'staff');
page_header('Staff directory', 'Full HR management – workload, leave and performance – arrives with the Staff & HR module.');
?>
<div class="grid grid-3" style="margin-bottom:16px">
  <?php foreach ($cats as $k => $l): ?>
    <a class="card kpi" href="staff.php?category=<?= $k ?>" style="color:inherit;text-decoration:none"><span class="kpi-label"><?= e($l) ?>s</span><span class="kpi-value"><?= (int) ($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>
<form class="card filters" method="get" style="margin-bottom:16px">
  <div class="field grow"><label for="sq">Search</label><input id="sq" type="search" name="q" value="<?= e($search) ?>" placeholder="Name, staff number or email"></div>
  <div class="field"><label for="sc">Category</label><select id="sc" name="category" data-autosubmit><option value="">All</option><?php foreach ($cats as $k => $l): ?><option value="<?= $k ?>" <?= $cat === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="sd">Department</label><select id="sd" name="department" data-autosubmit><option value="">All</option><?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>" <?= $dept === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code'] . ' – ' . $d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="toolbar"><button class="btn btn-navy" type="submit">Filter</button><a class="btn btn-ghost" href="staff.php">Reset</a></div>
</form>
<div class="card card-flush">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Staff no.</th><th>Designation</th><th>Category</th><th>Department</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><div class="person"><span class="avatar"><?= e(initials($r['first_name'] . ' ' . $r['last_name'])) ?></span><div><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><small><?= e($r['email']) ?></small></div></div></td>
        <td class="mono"><?= e($r['staff_no']) ?></td><td><?= e($r['designation']) ?></td><td><?= e($cats[$r['category']]) ?></td><td><?= e($r['dept_name'] ?? '—') ?></td><td><?= badge($r['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="6">No staff found.</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php render_pagination($p); ?>
</div>
<?php render_footer();
