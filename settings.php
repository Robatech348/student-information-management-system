<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('settings.manage');

$tabs = ['general' => 'General', 'calendar' => 'Academic calendar', 'terms' => 'Terms / semesters', 'system' => 'System & security'];
$tab = isset($tabs[get('tab')]) ? get('tab') : 'general';
$eventTypes = ['academic', 'exam', 'deadline', 'holiday', 'event'];
$isDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);

if (is_post()) {
    verify_csrf();
    $action = post('action');
    try {
        if ($action === 'save_general') {
            $keys = ['institution_name', 'institution_short', 'institution_email', 'institution_phone', 'academic_year', 'student_no_prefix', 'session_timeout_minutes'];
            if (post('institution_name') === '') throw new RuntimeException('Institution name is required.');
            if (post('institution_email') !== '' && !filter_var(post('institution_email'), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid institution email.');
            $timeout = (int) post('session_timeout_minutes');
            if ($timeout < 5 || $timeout > 480) throw new RuntimeException('Session timeout must be between 5 and 480 minutes.');
            if (!preg_match('/^[A-Z]{2,6}$/', post('student_no_prefix'))) throw new RuntimeException('Student number prefix must be 2–6 capital letters.');
            $changes = [];
            foreach ($keys as $k) {
                $new = $k === 'session_timeout_minutes' ? (string) $timeout : mb_substr(post($k), 0, 190);
                if ((string) setting($k) !== $new) $changes[$k] = ['from' => setting($k), 'to' => $new];
                save_setting($k, $new);
            }
            $regMode = post('student_registration_mode');
            if (!in_array($regMode, ['approval', 'open', 'closed'], true)) throw new RuntimeException('Choose a registration mode.');
            if (setting('student_registration_mode') !== $regMode) $changes['student_registration_mode'] = ['from' => setting('student_registration_mode'), 'to' => $regMode];
            save_setting('student_registration_mode', $regMode);
            $demo = post('show_demo_credentials') === '1' ? '1' : '0';
            if (setting('show_demo_credentials') !== $demo) $changes['show_demo_credentials'] = ['from' => setting('show_demo_credentials'), 'to' => $demo];
            save_setting('show_demo_credentials', $demo);
            if ($changes) audit('update', 'settings', null, 'Updated system settings', $changes);
            flash('success', 'Settings saved.');
        } elseif ($action === 'add_event') {
            if (post('title') === '' || !$isDate(post('event_date')) || !in_array(post('event_type'), $eventTypes, true)) throw new RuntimeException('Enter a title, valid date and type.');
            q('INSERT INTO calendar_events (title, event_date, event_type, description) VALUES (?, ?, ?, ?)', [post('title'), post('event_date'), post('event_type'), post('description') ?: null]);
            audit('create', 'calendar_event', db()->lastInsertId(), 'Added calendar event "' . post('title') . '"');
            flash('success', 'Event added.');
        } elseif ($action === 'delete_event') {
            $ev = q_one('SELECT * FROM calendar_events WHERE id = ?', [(int) post('id')]);
            if ($ev) {
                q('DELETE FROM calendar_events WHERE id = ?', [$ev['id']]);
                audit('delete', 'calendar_event', $ev['id'], "Deleted calendar event \"{$ev['title']}\"");
            }
            flash('success', 'Event deleted.');
        } elseif ($action === 'add_term') {
            if (post('name') === '' || post('academic_year') === '' || !$isDate(post('start_date')) || !$isDate(post('end_date'))) throw new RuntimeException('All term fields are required.');
            if (post('end_date') <= post('start_date')) throw new RuntimeException('End date must be after the start date.');
            q('INSERT INTO academic_terms (name, academic_year, start_date, end_date) VALUES (?, ?, ?, ?)', [post('name'), post('academic_year'), post('start_date'), post('end_date')]);
            audit('create', 'term', db()->lastInsertId(), 'Added term ' . post('name') . ' ' . post('academic_year'));
            flash('success', 'Term added.');
        } elseif ($action === 'set_current_term') {
            $t = q_one('SELECT * FROM academic_terms WHERE id = ?', [(int) post('id')]);
            if ($t) {
                db()->beginTransaction();
                q('UPDATE academic_terms SET is_current = 0');
                q('UPDATE academic_terms SET is_current = 1 WHERE id = ?', [$t['id']]);
                save_setting('academic_year', $t['academic_year']);
                db()->commit();
                audit('update', 'term', $t['id'], "Set current term to {$t['name']} {$t['academic_year']}");
                flash('success', "{$t['name']} {$t['academic_year']} is now the current term.");
            }
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('settings.php?tab=' . $tab);
}

render_header('Settings', 'settings');
page_header('Settings', 'Institution profile, academic calendar and security configuration.');
?>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="settings.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'is-active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'general'): ?>
  <form class="card" method="post" style="max-width:860px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
    <div class="form-grid">
      <div class="field"><label for="in">Institution name</label><input id="in" name="institution_name" value="<?= e(setting('institution_name')) ?>" required></div>
      <div class="field"><label for="is">Short name</label><input id="is" name="institution_short" value="<?= e(setting('institution_short')) ?>"></div>
      <div class="field"><label for="ie">Contact email</label><input id="ie" type="email" name="institution_email" value="<?= e(setting('institution_email')) ?>"></div>
      <div class="field"><label for="ip">Contact phone</label><input id="ip" name="institution_phone" value="<?= e(setting('institution_phone')) ?>"></div>
      <div class="field"><label for="ay">Academic year</label><input id="ay" name="academic_year" value="<?= e(setting('academic_year')) ?>" placeholder="2026/2027"></div>
      <div class="field"><label for="sp">Student number prefix</label><input id="sp" name="student_no_prefix" value="<?= e(setting('student_no_prefix', 'STU')) ?>" maxlength="6"><span class="hint">Format: PREFIX-YEAR-NNNNN, e.g. <?= e(setting('student_no_prefix', 'STU')) ?>-<?= date('Y') ?>-10482</span></div>
      <div class="field"><label for="st">Session idle timeout (minutes)</label><input id="st" type="number" min="5" max="480" name="session_timeout_minutes" value="<?= e(setting('session_timeout_minutes', '30')) ?>"></div>
      <div class="field full" id="registration"><label for="rm">Student self-registration</label>
        <select id="rm" name="student_registration_mode">
          <?php foreach (['approval' => 'Open – Registrar must approve each new student (recommended)', 'open' => 'Open – students get instant access after registering', 'closed' => 'Closed – only staff can add students'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= setting('student_registration_mode', 'approval') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Staff accounts are never self-registered – they are created by an administrator under Users &amp; access.</span></div>
      <div class="field" style="justify-content:flex-end"><label class="checkbox"><input type="checkbox" name="show_demo_credentials" value="1" <?= setting('show_demo_credentials') === '1' ? 'checked' : '' ?>> Show demo accounts on the sign-in page</label><span class="hint">Turn off before real use.</span></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Save settings</button></div>
  </form>

<?php elseif ($tab === 'calendar'):
    $events = q('SELECT * FROM calendar_events ORDER BY event_date >= CURDATE() DESC, ABS(DATEDIFF(event_date, CURDATE()))')->fetchAll(); ?>
  <div class="grid grid-3">
    <div class="card card-flush span-2"><div class="table-wrap"><table class="table">
      <thead><tr><th>Date</th><th>Event</th><th>Type</th><th></th></tr></thead><tbody>
      <?php foreach ($events as $ev): $past = $ev['event_date'] < date('Y-m-d'); ?>
        <tr<?= $past ? ' style="opacity:.55"' : '' ?>><td style="white-space:nowrap"><?= e(format_date($ev['event_date'], 'D, M j, Y')) ?></td><td><strong><?= e($ev['title']) ?></strong><?= $ev['description'] ? '<br><small class="muted">' . e($ev['description']) . '</small>' : '' ?></td><td><?= badge('neutral', humanize($ev['event_type'])) ?></td>
          <td class="actions"><form method="post" class="inline-form" data-confirm="Delete “<?= e($ev['title']) ?>”?" data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Delete"><?= icon('trash') ?></button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$events): ?><tr class="empty-row"><td colspan="4">No events.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <form class="card" method="post" style="align-self:start"><?= csrf_field() ?><input type="hidden" name="action" value="add_event">
      <h2>Add event</h2>
      <div class="stack">
        <div class="field"><label for="et">Title</label><input id="et" name="title" required maxlength="150"></div>
        <div class="field"><label for="ed">Date</label><input id="ed" type="date" name="event_date" required></div>
        <div class="field"><label for="ety">Type</label><select id="ety" name="event_type"><?php foreach ($eventTypes as $t): ?><option value="<?= $t ?>"><?= e(humanize($t)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="eds">Description</label><input id="eds" name="description" maxlength="255"></div>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('plus') ?> Add event</button>
      </div>
    </form>
  </div>

<?php elseif ($tab === 'terms'):
    $terms = q('SELECT * FROM academic_terms ORDER BY start_date DESC')->fetchAll(); ?>
  <div class="grid grid-3">
    <div class="card card-flush span-2"><div class="table-wrap"><table class="table">
      <thead><tr><th>Term</th><th>Academic year</th><th>Starts</th><th>Ends</th><th></th></tr></thead><tbody>
      <?php foreach ($terms as $t): ?>
        <tr><td><strong><?= e($t['name']) ?></strong> <?= $t['is_current'] ? badge('active', 'Current') : '' ?></td><td><?= e($t['academic_year']) ?></td><td><?= e(format_date($t['start_date'])) ?></td><td><?= e(format_date($t['end_date'])) ?></td>
          <td class="actions"><?php if (!$t['is_current']): ?><form method="post" class="inline-form" data-confirm="Make <?= e($t['name'] . ' ' . $t['academic_year']) ?> the current term for the whole university?" data-confirm-button="Set current"><?= csrf_field() ?><input type="hidden" name="action" value="set_current_term"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-ghost btn-sm">Set current</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <form class="card" method="post" style="align-self:start"><?= csrf_field() ?><input type="hidden" name="action" value="add_term">
      <h2>Add term</h2>
      <div class="stack">
        <div class="field"><label for="tn">Name</label><input id="tn" name="name" required placeholder="Semester I" maxlength="100"></div>
        <div class="field"><label for="ty">Academic year</label><input id="ty" name="academic_year" required placeholder="2027/2028" maxlength="20"></div>
        <div class="form-grid"><div class="field"><label for="ts">Start</label><input id="ts" type="date" name="start_date" required></div><div class="field"><label for="te">End</label><input id="te" type="date" name="end_date" required></div></div>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('plus') ?> Add term</button>
      </div>
    </form>
  </div>

<?php else:
    $dbSize = q_val('SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
    $storageFiles = 0; $storageBytes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STORAGE_PATH, FilesystemIterator::SKIP_DOTS)) as $file) { $storageFiles++; $storageBytes += $file->getSize(); }
    $checks = [
        ['Password hashing', defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt (cost 12)', true],
        ['HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'Enabled' : 'Not enabled – required in production', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'],
        ['Debug mode', config('debug') ? 'On – turn off in production' : 'Off', !config('debug')],
        ['Demo credentials on sign-in page', setting('show_demo_credentials') === '1' ? 'Visible – turn off before go-live' : 'Hidden', setting('show_demo_credentials') !== '1'],
        ['Database password', config('db.pass') === '' ? 'Empty (XAMPP default) – set one in production' : 'Set', config('db.pass') !== ''],
        ['Sign-in throttling', config('security.max_failed_logins_per_email') . ' failures / ' . config('security.lockout_window_minutes') . ' min per account', true],
        ['CSRF protection', 'All forms', true],
        ['Document storage', 'Outside public access, served after permission checks', true],
    ]; ?>
  <div class="grid grid-2">
    <div class="card">
      <div class="card-head"><h2>Security checklist</h2></div>
      <ul class="list">
        <?php foreach ($checks as [$label, $value, $ok]): ?>
          <li><span class="list-icon" style="<?= $ok ? 'color:var(--success)' : 'color:var(--warning)' ?>"><?= icon($ok ? 'check' : 'shield') ?></span><div class="list-body"><strong><?= e($label) ?></strong><span><?= e($value) ?></span></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="card">
      <div class="card-head"><h2>System information</h2></div>
      <dl class="dl">
        <dt>Application</dt><dd><?= e(config('app_name')) ?> 1.0 (Phase 1–2)</dd>
        <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
        <dt>Database</dt><dd><?= e(q_val('SELECT VERSION()')) ?> · <?= e($dbSize) ?> MB</dd>
        <dt>Stored files</dt><dd><?= $storageFiles ?> files · <?= number_format($storageBytes / 1048576, 2) ?> MB</dd>
        <dt>Server time</dt><dd><?= e(date('Y-m-d H:i:s T')) ?></dd>
      </dl>
      <div class="notice" style="margin-top:18px"><?= icon('shield') ?><div><strong>Backups</strong><br><span class="muted">Schedule a nightly <code>mysqldump</code> of the <code><?= e(config('db.name')) ?></code> database plus a copy of the <code>storage/</code> folder. See README for a ready-made command.</span></div></div>
    </div>
  </div>
<?php endif;
render_footer();
