<?php
/** University announcements. Everyone sees those meant for them; announcements.manage can post and remove. */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$canManage = can('announcements.manage');
$audiences = ['everyone' => 'Everyone', 'students' => 'Students only', 'staff' => 'Staff only'];
$errors = [];
$form = ['title' => '', 'body' => '', 'audience' => 'everyone', 'is_pinned' => '0', 'expires_at' => ''];

if (is_post()) {
    if (!$canManage) abort(403);
    verify_csrf();
    if (post('action') === 'delete') {
        $a = q_one('SELECT * FROM announcements WHERE id = ?', [(int) post('id')]);
        if ($a) {
            q('DELETE FROM announcements WHERE id = ?', [$a['id']]);
            audit('delete', 'announcement', $a['id'], "Deleted announcement \"{$a['title']}\"");
            flash('success', 'Announcement removed.');
        }
        redirect('announcements.php');
    }
    if (post('action') === 'toggle_pin') {
        q('UPDATE announcements SET is_pinned = 1 - is_pinned WHERE id = ?', [(int) post('id')]);
        redirect('announcements.php');
    }
    $form = ['title' => post('title'), 'body' => post('body'), 'audience' => post('audience'), 'is_pinned' => post('is_pinned') === '1' ? '1' : '0', 'expires_at' => post('expires_at')];
    if ($form['title'] === '' || mb_strlen($form['title']) > 150) $errors['title'] = 'Enter a title (up to 150 characters).';
    if ($form['body'] === '' || mb_strlen($form['body']) > 5000) $errors['body'] = 'Write the announcement (up to 5,000 characters).';
    if (!isset($audiences[$form['audience']])) $errors['audience'] = 'Choose an audience.';
    if ($form['expires_at'] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['expires_at']) || $form['expires_at'] < date('Y-m-d'))) $errors['expires_at'] = 'Choose a future date or leave empty.';
    if (!$errors) {
        q('INSERT INTO announcements (title, body, audience, is_pinned, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$form['title'], $form['body'], $form['audience'], (int) $form['is_pinned'], $form['expires_at'] ?: null, $user['id']]);
        $aid = (int) db()->lastInsertId();
        $notified = 0;
        if (post('notify') === '1') {
            $filter = ['everyone' => '1=1', 'students' => "r.slug = 'student'", 'staff' => "r.slug <> 'student'"][$form['audience']];
            $st = q("INSERT INTO notifications (user_id, title, body, link)
                     SELECT DISTINCT u.id, ?, ?, 'announcements.php' FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                      WHERE u.status = 'active' AND u.id <> ? AND {$filter}", ['📢 ' . $form['title'], mb_strimwidth($form['body'], 0, 180, '…'), $user['id']]);
            $notified = $st->rowCount();
        }
        audit('create', 'announcement', $aid, "Posted announcement \"{$form['title']}\" to {$form['audience']}", ['notified' => $notified]);
        flash('success', 'Announcement published' . ($notified ? " and {$notified} people notified." : '.'));
        redirect('announcements.php');
    }
}

[$where, $params] = announcement_audience_filter();
$items = q("SELECT a.*, u.name author FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE {$where} ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 50", $params)->fetchAll();

render_header('Announcements', 'announcements');
page_header('Announcements', $canManage ? 'Post news and notices to students, staff or everyone.' : 'News and notices from the university.');
?>
<div class="grid grid-3">
  <div class="stack <?= $canManage ? 'span-2' : '' ?>" style="<?= $canManage ? '' : 'grid-column:1/-1' ?>">
    <?php foreach ($items as $a): ?>
      <article class="card announcement<?= $a['is_pinned'] ? ' is-pinned' : '' ?>">
        <div class="card-head" style="align-items:flex-start">
          <div><h2 style="margin:0"><?= e($a['title']) ?></h2>
            <div class="muted small"><?= e($a['author'] ?? 'Administration') ?> · <?= e(time_ago($a['created_at'])) ?><?= $a['expires_at'] ? ' · until ' . e(format_date($a['expires_at'])) : '' ?></div></div>
          <div class="toolbar">
            <?php if ($a['is_pinned']): ?><span class="badge badge-teal">Pinned</span><?php endif; ?>
            <?php if ($canManage): ?>
              <span class="badge badge-neutral"><?= e($audiences[$a['audience']]) ?></span>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $a['is_pinned'] ? 'Unpin' : 'Pin' ?></button></form>
              <form method="post" class="inline-form" data-confirm="Remove “<?= e($a['title']) ?>”?" data-confirm-button="Remove"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Remove"><?= icon('trash') ?></button></form>
            <?php endif; ?>
          </div>
        </div>
        <div class="announcement-body"><?= nl2br(e($a['body'])) ?></div>
      </article>
    <?php endforeach; ?>
    <?php if (!$items): ?><div class="card empty-state"><div class="empty-icon"><?= icon('bell') ?></div><h2>No announcements</h2><p class="muted">Check back later.</p></div><?php endif; ?>
  </div>

  <?php if ($canManage): ?>
  <form class="card" method="post" style="align-self:start" novalidate>
    <?= csrf_field() ?>
    <h2>New announcement</h2>
    <div class="stack">
      <div class="field<?= isset($errors['title']) ? ' has-error' : '' ?>"><label for="at">Title</label><input id="at" name="title" value="<?= e($form['title']) ?>" maxlength="150"><?= isset($errors['title']) ? '<span class="error">' . e($errors['title']) . '</span>' : '' ?></div>
      <div class="field<?= isset($errors['body']) ? ' has-error' : '' ?>"><label for="ab">Message</label><textarea id="ab" name="body" rows="6" maxlength="5000"><?= e($form['body']) ?></textarea><?= isset($errors['body']) ? '<span class="error">' . e($errors['body']) . '</span>' : '' ?></div>
      <div class="form-grid">
        <div class="field"><label for="aa">Audience</label><select id="aa" name="audience"><?php foreach ($audiences as $k => $l): ?><option value="<?= $k ?>" <?= $form['audience'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field<?= isset($errors['expires_at']) ? ' has-error' : '' ?>"><label for="ae">Show until</label><input id="ae" type="date" name="expires_at" value="<?= e($form['expires_at']) ?>"><?= isset($errors['expires_at']) ? '<span class="error">' . e($errors['expires_at']) . '</span>' : '' ?></div>
      </div>
      <label class="checkbox"><input type="checkbox" name="is_pinned" value="1" <?= $form['is_pinned'] === '1' ? 'checked' : '' ?>> Pin to the top</label>
      <label class="checkbox"><input type="checkbox" name="notify" value="1" checked> Send a notification to everyone in the audience</label>
      <button class="btn btn-primary btn-block" type="submit"><?= icon('message') ?> Publish</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php render_footer();
