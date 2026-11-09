<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('roles.manage');

$roles = q('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) members FROM roles r ORDER BY r.id')->fetchAll();
$perms = q('SELECT * FROM permissions ORDER BY module, id')->fetchAll();
$grants = [];
foreach (q('SELECT role_id, permission_id FROM role_permissions')->fetchAll() as $g) {
    $grants[$g['role_id']][$g['permission_id']] = true;
}

if (is_post()) {
    verify_csrf();
    $posted = (array) ($_POST['grants'] ?? []);
    $permIds = array_map('intval', array_column($perms, 'id'));
    $changes = [];
    db()->beginTransaction();
    foreach ($roles as $r) {
        if ($r['slug'] === 'super_admin') continue; // always has every permission
        $new = array_values(array_intersect(array_map('intval', (array) ($posted[$r['id']] ?? [])), $permIds));
        $old = array_map('intval', array_keys($grants[$r['id']] ?? []));
        sort($new); sort($old);
        if ($new === $old) continue;
        q('DELETE FROM role_permissions WHERE role_id = ?', [$r['id']]);
        foreach ($new as $pid) q('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$r['id'], $pid]);
        $slugs = array_column($perms, 'slug', 'id');
        $changes[$r['name']] = [
            'from' => implode(', ', array_map(fn($i) => $slugs[$i], array_diff($old, $new))) ?: null,
            'to'   => implode(', ', array_map(fn($i) => $slugs[$i], array_diff($new, $old))) ?: null,
        ];
    }
    db()->commit();
    if ($changes) {
        audit('update', 'role', null, 'Updated role permissions (' . implode(', ', array_keys($changes)) . ')', $changes);
        flash('success', 'Permissions saved.');
    } else {
        flash('info', 'No changes to save.');
    }
    redirect('roles.php');
}

render_header('Roles & permissions', 'roles');
page_header('Roles & permissions', 'Control what each role can see and do. Changes apply on the user\'s next page load.');
?>
<div class="grid grid-<?= min(5, count($roles)) ?>" style="margin-bottom:16px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
  <?php foreach ($roles as $r): ?>
    <div class="card"><strong><?= e($r['name']) ?></strong><p class="muted small" style="margin:4px 0 8px"><?= e($r['description']) ?></p><span class="badge badge-teal"><?= (int) $r['members'] ?> user<?= $r['members'] == 1 ? '' : 's' ?></span></div>
  <?php endforeach; ?>
</div>

<form class="card card-flush" method="post" data-confirm="Permission changes take effect immediately for every user with these roles." data-confirm-title="Save permission changes?" data-confirm-button="Save">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="table matrix">
    <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php $module = null; foreach ($perms as $pm):
        if ($pm['module'] !== $module): $module = $pm['module']; ?>
          <tr class="module-row"><td colspan="<?= count($roles) + 1 ?>"><?= e($module) ?></td></tr>
        <?php endif; ?>
      <tr>
        <td><?= e($pm['name']) ?><br><small class="muted mono"><?= e($pm['slug']) ?></small></td>
        <?php foreach ($roles as $r): $super = $r['slug'] === 'super_admin'; ?>
          <td><input type="checkbox" name="grants[<?= $r['id'] ?>][]" value="<?= $pm['id'] ?>" aria-label="<?= e($r['name'] . ': ' . $pm['name']) ?>"
                <?= $super || isset($grants[$r['id']][$pm['id']]) ? 'checked' : '' ?> <?= $super ? 'disabled title="Super Administrators always have every permission"' : '' ?>></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="form-actions" style="padding:0 20px 20px"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Save permissions</button></div>
</form>
<?php render_footer();
