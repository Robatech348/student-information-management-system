<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('academics.manage');

$tabs = ['programs' => 'Programs', 'departments' => 'Departments', 'faculties' => 'Faculties / Schools', 'campuses' => 'Campuses'];
$tab = isset($tabs[get('tab')]) ? get('tab') : 'programs';
$levels = ['certificate', 'diploma', 'bachelor', 'master', 'doctorate'];

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code')));
    $name = post('name');
    try {
        switch ($action) {
            case 'add_faculty':
                if ($code === '' || $name === '') throw new RuntimeException('Code and name are required.');
                q('INSERT INTO faculties (code, name, dean) VALUES (?, ?, ?)', [$code, $name, post('dean') ?: null]);
                audit('create', 'faculty', db()->lastInsertId(), "Created faculty {$code} – {$name}");
                break;
            case 'add_department':
                if ($code === '' || $name === '' || !q_val('SELECT id FROM faculties WHERE id = ?', [(int) post('faculty_id')])) throw new RuntimeException('Code, name and faculty are required.');
                q('INSERT INTO departments (faculty_id, code, name) VALUES (?, ?, ?)', [(int) post('faculty_id'), $code, $name]);
                audit('create', 'department', db()->lastInsertId(), "Created department {$code} – {$name}");
                break;
            case 'add_program':
                $dur = (int) post('duration_years');
                $credits = (int) post('total_credits');
                if ($code === '' || $name === '' || !q_val('SELECT id FROM departments WHERE id = ?', [(int) post('department_id')])) throw new RuntimeException('Code, name and department are required.');
                if (!in_array(post('level'), $levels, true) || $dur < 1 || $dur > 7 || $credits < 1 || $credits > 400) throw new RuntimeException('Check the level, duration (1–7 years) and credits.');
                q('INSERT INTO programs (department_id, code, name, level, duration_years, total_credits) VALUES (?, ?, ?, ?, ?, ?)', [(int) post('department_id'), $code, $name, post('level'), $dur, $credits]);
                audit('create', 'program', db()->lastInsertId(), "Created program {$code} – {$name}");
                break;
            case 'add_campus':
                if ($code === '' || $name === '') throw new RuntimeException('Code and name are required.');
                q('INSERT INTO campuses (code, name, location) VALUES (?, ?, ?)', [$code, $name, post('location') ?: null]);
                audit('create', 'campus', db()->lastInsertId(), "Created campus {$code} – {$name}");
                break;
            case 'delete':
                $map = ['faculty' => ['faculties', 'departments', 'faculty_id'], 'department' => ['departments', 'programs', 'department_id'],
                        'program' => ['programs', 'students', 'program_id'], 'campus' => ['campuses', 'students', 'campus_id']];
                $type = post('type');
                if (!isset($map[$type])) throw new RuntimeException('Unknown item.');
                [$table, $child, $fk] = $map[$type];
                $rid = (int) post('id');
                if (q_val("SELECT COUNT(*) FROM {$child} WHERE {$fk} = ?", [$rid])) throw new RuntimeException("This {$type} is still in use and can't be deleted.");
                $row = q_one("SELECT code, name FROM {$table} WHERE id = ?", [$rid]);
                if ($row) {
                    q("DELETE FROM {$table} WHERE id = ?", [$rid]);
                    audit('delete', $type, $rid, "Deleted {$type} {$row['code']} – {$row['name']}");
                }
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
        flash('success', $action === 'delete' ? 'Deleted.' : 'Saved.');
    } catch (PDOException $e) {
        flash('danger', $e->getCode() === '23000' ? 'That code is already in use.' : 'The change could not be saved.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('academics.php?tab=' . $tab);
}

$faculties = q('SELECT f.*, (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.id) depts FROM faculties f ORDER BY f.name')->fetchAll();
$departments = q('SELECT d.*, f.code faculty_code, (SELECT COUNT(*) FROM programs p WHERE p.department_id = d.id) progs FROM departments d JOIN faculties f ON f.id = d.faculty_id ORDER BY f.code, d.name')->fetchAll();
$programs = q("SELECT p.*, d.code dept_code, f.code faculty_code,
                      (SELECT COUNT(*) FROM students s WHERE s.program_id = p.id AND s.deleted_at IS NULL AND s.enrollment_status = 'active') active_students
                 FROM programs p JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id ORDER BY f.code, p.name")->fetchAll();
$campuses = q('SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.campus_id = c.id AND s.deleted_at IS NULL) students FROM campuses c ORDER BY c.name')->fetchAll();

function delete_btn(string $type, int $id, string $label, bool $inUse): string
{
    if ($inUse) return '<span class="muted small" title="In use">' . icon('lock') . '</span>';
    return '<form method="post" class="inline-form" data-confirm="Delete ' . e($label) . '?" data-confirm-button="Delete">' . csrf_field()
         . '<input type="hidden" name="action" value="delete"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="id" value="' . $id . '">'
         . '<button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete">' . icon('trash') . '</button></form>';
}

render_header('Programs & structure', 'academics');
page_header('Programs & academic structure', 'Faculties contain departments; departments offer programs. Items in use cannot be deleted.');
?>
<nav class="tabs">
  <?php foreach ($tabs as $k => $l): ?><a href="academics.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'is-active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
</nav>
<div class="grid grid-3">
  <div class="card card-flush span-2"><div class="table-wrap"><table class="table">
  <?php if ($tab === 'programs'): ?>
    <thead><tr><th>Code</th><th>Program</th><th>Level</th><th>Dept</th><th class="num">Years</th><th class="num">Credits</th><th class="num">Active</th><th></th></tr></thead><tbody>
    <?php foreach ($programs as $r): ?><tr><td class="mono"><?= e($r['code']) ?></td><td><strong><?= e($r['name']) ?></strong></td><td><?= e(humanize($r['level'])) ?></td><td><?= e($r['faculty_code'] . ' / ' . $r['dept_code']) ?></td><td class="num"><?= (int) $r['duration_years'] ?></td><td class="num"><?= (int) $r['total_credits'] ?></td><td class="num"><a href="students.php?program=<?= $r['id'] ?>"><?= (int) $r['active_students'] ?></a></td><td class="actions"><?= delete_btn('program', (int) $r['id'], $r['name'], (bool) q_val('SELECT COUNT(*) FROM students WHERE program_id = ?', [$r['id']])) ?></td></tr><?php endforeach; ?>
  <?php elseif ($tab === 'departments'): ?>
    <thead><tr><th>Code</th><th>Department</th><th>Faculty</th><th class="num">Programs</th><th></th></tr></thead><tbody>
    <?php foreach ($departments as $r): ?><tr><td class="mono"><?= e($r['code']) ?></td><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['faculty_code']) ?></td><td class="num"><?= (int) $r['progs'] ?></td><td class="actions"><?= delete_btn('department', (int) $r['id'], $r['name'], $r['progs'] > 0) ?></td></tr><?php endforeach; ?>
  <?php elseif ($tab === 'faculties'): ?>
    <thead><tr><th>Code</th><th>Faculty / School</th><th>Dean</th><th class="num">Departments</th><th></th></tr></thead><tbody>
    <?php foreach ($faculties as $r): ?><tr><td class="mono"><?= e($r['code']) ?></td><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['dean'] ?: '—') ?></td><td class="num"><?= (int) $r['depts'] ?></td><td class="actions"><?= delete_btn('faculty', (int) $r['id'], $r['name'], $r['depts'] > 0) ?></td></tr><?php endforeach; ?>
  <?php else: ?>
    <thead><tr><th>Code</th><th>Campus</th><th>Location</th><th class="num">Students</th><th></th></tr></thead><tbody>
    <?php foreach ($campuses as $r): ?><tr><td class="mono"><?= e($r['code']) ?></td><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['location'] ?: '—') ?></td><td class="num"><?= (int) $r['students'] ?></td><td class="actions"><?= delete_btn('campus', (int) $r['id'], $r['name'], $r['students'] > 0) ?></td></tr><?php endforeach; ?>
  <?php endif; ?>
  </tbody></table></div></div>

  <form class="card" method="post" style="align-self:start">
    <?= csrf_field() ?>
    <?php $single = ['programs' => 'program', 'departments' => 'department', 'faculties' => 'faculty', 'campuses' => 'campus'][$tab]; ?>
    <input type="hidden" name="action" value="add_<?= $single ?>">
    <h2>Add <?= e($single) ?></h2>
    <div class="stack">
      <div class="field"><label for="c">Code</label><input id="c" name="code" required maxlength="20" placeholder="e.g. BSCCS"></div>
      <div class="field"><label for="nm">Name</label><input id="nm" name="name" required maxlength="150"></div>
      <?php if ($tab === 'programs'): ?>
        <div class="field"><label for="dp">Department</label><select id="dp" name="department_id"><?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['faculty_code'] . ' – ' . $d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="lv">Level</label><select id="lv" name="level"><?php foreach ($levels as $l): ?><option value="<?= $l ?>" <?= $l === 'bachelor' ? 'selected' : '' ?>><?= e(humanize($l)) ?></option><?php endforeach; ?></select></div>
        <div class="form-grid"><div class="field"><label for="du">Years</label><input id="du" type="number" name="duration_years" value="4" min="1" max="7"></div><div class="field"><label for="cr">Credits</label><input id="cr" type="number" name="total_credits" value="120" min="1" max="400"></div></div>
      <?php elseif ($tab === 'departments'): ?>
        <div class="field"><label for="fc">Faculty</label><select id="fc" name="faculty_id"><?php foreach ($faculties as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?></select></div>
      <?php elseif ($tab === 'faculties'): ?>
        <div class="field"><label for="dn">Dean</label><input id="dn" name="dean" maxlength="150"></div>
      <?php else: ?>
        <div class="field"><label for="lc">Location</label><input id="lc" name="location" maxlength="150"></div>
      <?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit"><?= icon('plus') ?> Add <?= e($single) ?></button>
    </div>
  </form>
</div>
<?php render_footer();
