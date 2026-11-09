<?php
/** Day-to-day school programme. Everyone signed in can view; schedule.manage can add, edit and delete. */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canManage = can('schedule.manage');
$student = current_student();
$isStudentView = $student && !$canManage;
$cats = schedule_category_meta();
$faculties = q('SELECT id, code, name FROM faculties ORDER BY name')->fetchAll();
$programs = q('SELECT id, code, name FROM programs ORDER BY code')->fetchAll();

$date = get('date');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = date('Y-m-d');
}

/* ---------------- Manage ---------------- */
if (is_post()) {
    if (!$canManage) abort(403);
    verify_csrf();
    $action = post('action');
    $back = 'programme.php?date=' . urlencode(post('item_date') ?: $date);
    try {
        if ($action === 'save') {
            $id = (int) post('id');
            $item = [
                'title' => post('title'), 'description' => post('description') ?: null, 'item_date' => post('item_date'),
                'start_time' => post('start_time'), 'end_time' => post('end_time') ?: null, 'venue' => post('venue') ?: null,
                'category' => post('category'), 'audience' => post('audience'),
                'faculty_id' => null, 'program_id' => null, 'year_of_study' => null,
            ];
            if ($item['title'] === '' || mb_strlen($item['title']) > 150) throw new RuntimeException('Enter a title (up to 150 characters).');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['item_date']) || !strtotime($item['item_date'])) throw new RuntimeException('Choose a valid date.');
            if (!preg_match('/^\d{2}:\d{2}$/', $item['start_time'])) throw new RuntimeException('Enter a start time.');
            if ($item['end_time'] !== null && (!preg_match('/^\d{2}:\d{2}$/', $item['end_time']) || $item['end_time'] <= $item['start_time'])) throw new RuntimeException('End time must be after the start time.');
            if (!isset($cats[$item['category']])) throw new RuntimeException('Choose a category.');
            switch ($item['audience']) {
                case 'all': break;
                case 'faculty':
                    $item['faculty_id'] = (int) post('faculty_id');
                    if (!in_array($item['faculty_id'], array_map('intval', array_column($faculties, 'id')), true)) throw new RuntimeException('Choose a school.');
                    break;
                case 'program':
                    $item['program_id'] = (int) post('program_id');
                    if (!in_array($item['program_id'], array_map('intval', array_column($programs, 'id')), true)) throw new RuntimeException('Choose a programme.');
                    $item['year_of_study'] = post('year_of_study') !== '' ? max(1, min(7, (int) post('year_of_study'))) : null;
                    break;
                case 'year':
                    $item['year_of_study'] = max(1, min(7, (int) post('year_of_study')));
                    break;
                default: throw new RuntimeException('Choose who this is for.');
            }

            if ($id) {
                $existing = q_one('SELECT * FROM schedule_items WHERE id = ?', [$id]);
                if (!$existing) throw new RuntimeException('That item no longer exists.');
                $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($item)));
                q("UPDATE schedule_items SET {$sets} WHERE id = ?", array_merge(array_values($item), [$id]));
                audit('update', 'schedule', $id, "Updated programme item \"{$item['title']}\" on {$item['item_date']}");
                flash('success', 'Programme item updated.');
            } else {
                // Repeat: none | weekdays | weekly, until a date (max 120 occurrences)
                $repeat = post('repeat', 'none');
                $until = post('repeat_until');
                $dates = [$item['item_date']];
                if ($repeat !== 'none') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < $item['item_date']) throw new RuntimeException('Choose a "repeat until" date after the first date.');
                    $dates = [];
                    for ($t = strtotime($item['item_date']); $t <= strtotime($until) && count($dates) < 120; $t = strtotime('+1 day', $t)) {
                        if ($repeat === 'weekdays' && date('N', $t) >= 6) continue;
                        if ($repeat === 'weekly' && date('N', $t) !== date('N', strtotime($item['item_date']))) continue;
                        $dates[] = date('Y-m-d', $t);
                    }
                }
                $series = count($dates) > 1 ? bin2hex(random_bytes(8)) : null;
                $cols = array_merge(array_keys($item), ['series_id', 'created_by']);
                $stmt = db()->prepare('INSERT INTO schedule_items (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
                db()->beginTransaction();
                foreach ($dates as $d) {
                    $item['item_date'] = $d;
                    $stmt->execute(array_merge(array_values($item), [$series, $user['id']]));
                }
                db()->commit();
                audit('create', 'schedule', db()->lastInsertId(), "Added programme item \"{$item['title']}\"" . (count($dates) > 1 ? ' × ' . count($dates) . ' dates' : " on {$dates[0]}"));
                flash('success', count($dates) > 1 ? 'Added ' . count($dates) . ' programme entries.' : 'Programme item added.');
                $back = 'programme.php?date=' . urlencode($dates[0]);
            }
        } elseif ($action === 'delete') {
            $it = q_one('SELECT * FROM schedule_items WHERE id = ?', [(int) post('id')]);
            if ($it) {
                if (post('scope') === 'series' && $it['series_id']) {
                    $n = q('DELETE FROM schedule_items WHERE series_id = ? AND item_date >= ?', [$it['series_id'], $it['item_date']])->rowCount();
                    audit('delete', 'schedule', $it['id'], "Deleted {$n} entries of \"{$it['title']}\" from {$it['item_date']} onwards");
                    flash('success', "Deleted {$n} entries.");
                } else {
                    q('DELETE FROM schedule_items WHERE id = ?', [$it['id']]);
                    audit('delete', 'schedule', $it['id'], "Deleted programme item \"{$it['title']}\" on {$it['item_date']}");
                    flash('success', 'Programme item deleted.');
                }
                $back = 'programme.php?date=' . urlencode($it['item_date']);
            }
        }
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

/* ---------------- View ---------------- */
[$audSql, $audParams] = schedule_audience_filter($isStudentView ? $student : null);
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
$weekEnd = date('Y-m-d', strtotime('+6 days', strtotime($weekStart)));
$counts = q("SELECT item_date, COUNT(*) c FROM schedule_items si WHERE item_date BETWEEN ? AND ? AND {$audSql} GROUP BY item_date",
    array_merge([$weekStart, $weekEnd], $audParams))->fetchAll(PDO::FETCH_KEY_PAIR);
$items = q("SELECT si.*, f.code faculty_code, p.code program_code FROM schedule_items si
              LEFT JOIN faculties f ON f.id = si.faculty_id LEFT JOIN programs p ON p.id = si.program_id
             WHERE si.item_date = ? AND {$audSql} ORDER BY si.start_time, si.title", array_merge([$date], $audParams))->fetchAll();
$events = q('SELECT * FROM calendar_events WHERE event_date = ?', [$date])->fetchAll();
$editing = $canManage && get('edit') ? q_one('SELECT * FROM schedule_items WHERE id = ?', [(int) get('edit')]) : null;
$now = date('H:i:s');
$isToday = $date === date('Y-m-d');

function audience_label(array $it): string
{
    switch ($it['audience']) {
        case 'faculty': return 'School: ' . $it['faculty_code'];
        case 'program': return $it['program_code'] . ($it['year_of_study'] ? ' · Year ' . $it['year_of_study'] : ' · all years');
        case 'year': return 'All Year ' . $it['year_of_study'] . ' students';
        default: return 'Everyone';
    }
}

render_header('Daily programme', 'programme');
page_header('Daily programme', $isStudentView ? 'Your day-to-day schedule: classes, exams, assemblies and university events.' : 'The day-to-day programme for the whole university.',
    '<button class="btn btn-ghost" type="button" data-print>' . icon('printer') . ' Print</button>');
?>
<div class="card day-nav">
  <a class="btn btn-ghost btn-sm" href="programme.php?date=<?= date('Y-m-d', strtotime('-7 days', strtotime($date))) ?>" aria-label="Previous week">‹‹</a>
  <div class="week-strip">
    <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("+{$i} days", strtotime($weekStart))); ?>
      <a href="programme.php?date=<?= $d ?>" class="day<?= $d === $date ? ' is-active' : '' ?><?= $d === date('Y-m-d') ? ' is-today' : '' ?>">
        <span><?= date('D', strtotime($d)) ?></span><b><?= date('j', strtotime($d)) ?></b><small><?= isset($counts[$d]) ? $counts[$d] . ' item' . ($counts[$d] == 1 ? '' : 's') : '—' ?></small>
      </a>
    <?php endfor; ?>
  </div>
  <a class="btn btn-ghost btn-sm" href="programme.php?date=<?= date('Y-m-d', strtotime('+7 days', strtotime($date))) ?>" aria-label="Next week">››</a>
  <?php if (!$isToday): ?><a class="btn btn-navy btn-sm" href="programme.php">Today</a><?php endif; ?>
</div>

<div class="grid grid-3" style="margin-top:16px">
  <div class="<?= $canManage ? 'span-2' : 'span-3' ?> card" style="<?= $canManage ? '' : 'grid-column:1/-1' ?>">
    <div class="card-head"><h2><?= e(date('l, j F Y', strtotime($date))) ?></h2><?= $isToday ? '<span class="badge badge-teal">Today</span>' : '' ?></div>
    <?php foreach ($events as $ev): ?><div class="notice" style="margin-bottom:12px"><?= icon('calendar') ?><div><strong><?= e($ev['title']) ?></strong> <span class="muted">· <?= e(humanize($ev['event_type'])) ?></span><?= $ev['description'] ? '<br><span class="muted">' . e($ev['description']) . '</span>' : '' ?></div></div><?php endforeach; ?>
    <?php if (!$items): ?>
      <p class="muted" style="padding:24px 0;text-align:center">Nothing scheduled for this day<?= $isStudentView ? ' for you' : '' ?>.</p>
    <?php endif; ?>
    <ol class="timeline">
      <?php foreach ($items as $it):
          [$catLabel, $catIcon, $tone] = $cats[$it['category']];
          $live = $isToday && $it['start_time'] <= $now && ($it['end_time'] ?? $it['start_time']) >= $now;
          $past = $isToday && ($it['end_time'] ?? $it['start_time']) < $now; ?>
        <li class="<?= $live ? 'is-live' : '' ?><?= $past ? ' is-past' : '' ?>">
          <div class="tl-time"><b><?= e(substr($it['start_time'], 0, 5)) ?></b><?= $it['end_time'] ? '<span>' . e(substr($it['end_time'], 0, 5)) . '</span>' : '' ?></div>
          <div class="tl-dot kpi-icon <?= $tone ?>"><?= icon($catIcon) ?></div>
          <div class="tl-body">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><strong><?= e($it['title']) ?></strong><?= $live ? '<span class="badge badge-success">Happening now</span>' : '' ?></div>
            <div class="muted small"><?= e($catLabel) ?><?= $it['venue'] ? ' · ' . icon('map', 'icon icon-xs') . ' ' . e($it['venue']) : '' ?><?= !$isStudentView ? ' · ' . e(audience_label($it)) : '' ?></div>
            <?php if ($it['description']): ?><div class="small" style="margin-top:4px"><?= e($it['description']) ?></div><?php endif; ?>
          </div>
          <?php if ($canManage): ?>
            <div class="tl-actions no-print">
              <a class="btn btn-ghost btn-sm" href="programme.php?date=<?= $date ?>&amp;edit=<?= $it['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a>
              <form method="post" class="inline-form" data-confirm="Delete “<?= e($it['title']) ?>” on <?= e(format_date($it['item_date'])) ?>?" data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $it['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
              <?php if ($it['series_id']): ?><form method="post" class="inline-form" data-confirm="Delete “<?= e($it['title']) ?>” on this and all later dates in the series?" data-confirm-button="Delete series"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="scope" value="series"><input type="hidden" name="id" value="<?= $it['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="Delete this and later repeats">Series</button></form><?php endif; ?>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <?php if ($canManage):
      $f = $editing ?: ['title' => '', 'description' => '', 'item_date' => $date, 'start_time' => '08:00', 'end_time' => '', 'venue' => '', 'category' => 'class', 'audience' => 'all', 'faculty_id' => null, 'program_id' => null, 'year_of_study' => null]; ?>
  <form class="card no-print" method="post" style="align-self:start">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="card-head"><h2><?= $editing ? 'Edit item' : 'Add to programme' ?></h2><?php if ($editing): ?><a class="small" href="programme.php?date=<?= $date ?>">Cancel</a><?php endif; ?></div>
    <div class="stack">
      <div class="field"><label for="pt">Title</label><input id="pt" name="title" value="<?= e($f['title']) ?>" required maxlength="150" placeholder="e.g. CSC 201 Lecture, Morning assembly"></div>
      <div class="form-grid">
        <div class="field"><label for="pc">Category</label><select id="pc" name="category"><?php foreach ($cats as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="pd">Date</label><input id="pd" type="date" name="item_date" value="<?= e($f['item_date']) ?>" required></div>
        <div class="field"><label for="ps">Starts</label><input id="ps" type="time" name="start_time" value="<?= e(substr((string) $f['start_time'], 0, 5)) ?>" required></div>
        <div class="field"><label for="pe">Ends</label><input id="pe" type="time" name="end_time" value="<?= e(substr((string) $f['end_time'], 0, 5)) ?>"></div>
      </div>
      <div class="field"><label for="pv">Venue</label><input id="pv" name="venue" value="<?= e($f['venue']) ?>" maxlength="120" placeholder="e.g. LT 3, Main Hall"></div>
      <div class="field"><label for="pa">Who is it for?</label>
        <select id="pa" name="audience" data-audience>
          <?php foreach (['all' => 'Everyone', 'faculty' => 'A school / faculty', 'program' => 'A programme', 'year' => 'A year of study (all programmes)'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['audience'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" data-aud-show="faculty"><label for="pf">School</label><select id="pf" name="faculty_id"><?php foreach ($faculties as $fc): ?><option value="<?= $fc['id'] ?>" <?= (int) $f['faculty_id'] === (int) $fc['id'] ? 'selected' : '' ?>><?= e($fc['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" data-aud-show="program"><label for="pp">Programme</label><select id="pp" name="program_id"><?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>" <?= (int) $f['program_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' – ' . $p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" data-aud-show="program year"><label for="py">Year of study</label><select id="py" name="year_of_study"><option value="" data-aud-only="program">All years</option><?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>" <?= (int) $f['year_of_study'] === $y ? 'selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?></select></div>
      <div class="field"><label for="pds">Details</label><textarea id="pds" name="description" maxlength="500" rows="2" placeholder="Optional notes for students"><?= e($f['description']) ?></textarea></div>
      <?php if (!$editing): ?>
        <div class="form-grid">
          <div class="field"><label for="pr">Repeat</label><select id="pr" name="repeat" data-repeat><option value="none">Does not repeat</option><option value="weekdays">Every weekday (Mon–Fri)</option><option value="weekly">Weekly on this day</option></select></div>
          <div class="field" data-repeat-until hidden><label for="pu">Until</label><input id="pu" type="date" name="repeat_until" value="<?= e(date('Y-m-d', strtotime('+4 weeks', strtotime($date)))) ?>"></div>
        </div>
      <?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit"><?= icon($editing ? 'check' : 'plus') ?> <?= $editing ? 'Save changes' : 'Add to programme' ?></button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php render_footer();
