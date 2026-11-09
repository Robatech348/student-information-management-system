<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    if (post('action') === 'read_all') {
        q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$user['id']]);
    } elseif (post('action') === 'open') {
        $n = q_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [(int) post('id'), $user['id']]);
        if ($n) {
            q('UPDATE notifications SET is_read = 1 WHERE id = ?', [$n['id']]);
            if ($n['link'] && preg_match('/^[a-z_]+\.php(\?[^\s]*)?$/i', $n['link'])) redirect($n['link']);
        }
    }
    redirect('notifications.php');
}

$total = (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$user['id']]);
$p = paginate($total, 20);
$items = q("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", [$user['id']])->fetchAll();
$unread = (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$user['id']]);

render_header('Notifications', 'notifications');
page_header('Notifications', $unread ? "{$unread} unread" : 'You\'re all caught up.',
    $unread ? '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><button class="btn btn-ghost" type="submit">' . icon('check') . ' Mark all as read</button></form>' : '');
?>
<div class="card">
  <ul class="list">
    <?php foreach ($items as $n): ?>
      <li style="<?= $n['is_read'] ? '' : 'background:color-mix(in srgb, var(--teal-soft) 45%, transparent);margin:0 -20px;padding:12px 20px' ?>">
        <span class="list-icon"><?= icon('bell') ?></span>
        <div class="list-body"><strong><?= e($n['title']) ?></strong><span><?= e($n['body']) ?> · <?= e(time_ago($n['created_at'])) ?></span></div>
        <?php if ($n['link'] || !$n['is_read']): ?>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?= $n['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $n['link'] ? 'Open' : 'Mark read' ?></button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    <?php if (!$items): ?><li class="muted">No notifications yet.</li><?php endif; ?>
  </ul>
</div>
<?php render_pagination($p);
render_footer();
