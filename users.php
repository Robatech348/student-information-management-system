<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_permission('users.manage');

$roles = q('SELECT id, slug, name FROM roles ORDER BY id')->fetchAll();
$roleIds = array_map('intval', array_column($roles, 'id'));
$superRoleId = (int) q_val("SELECT id FROM roles WHERE slug = 'super_admin'");
$isSuper = has_role('super_admin');
$errors = [];
$form = ['name' => '', 'email' => '', 'phone' => '', 'roles' => []];
$editId = (int) get('edit', '0');

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $uid = (int) post('user_id', '0');
    $target = $uid ? q_one('SELECT * FROM users WHERE id = ?', [$uid]) : null;
    $targetIsSuper = $target && q_val('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$uid, $superRoleId]);
    if ($targetIsSuper && !$isSuper) {
        abort(403, 'Only a Super Administrator can modify another Super Administrator.');
    }

    if ($action === 'create' || $action === 'update') {
        $form = ['name' => post('name'), 'email' => strtolower(post('email')), 'phone' => post('phone'),
                 'roles' => array_values(array_intersect(array_map('intval', (array) ($_POST['roles'] ?? [])), $roleIds))];
        if ($form['name'] === '') $errors['name'] = 'Name is required.';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
        elseif (q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$form['email'], $uid])) $errors['email'] = 'That email is already in use.';
        if (!$form['roles']) $errors['roles'] = 'Assign at least one role.';
        if (in_array($superRoleId, $form['roles'], true) && !$isSuper) $errors['roles'] = 'Only a Super Administrator can grant that role.';
        if ($action === 'update' && $uid === (int) $me['id'] && $isSuper && !in_array($superRoleId, $form['roles'], true)) $errors['roles'] = 'You cannot remove your own Super Administrator role.';

        if (!$errors) {
            db()->beginTransaction();
            if ($action === 'create') {
                $temp = temp_password();
                q('INSERT INTO users (name, email, phone, password_hash, must_change_password) VALUES (?, ?, ?, ?, 1)', [$form['name'], $form['email'], $form['phone'] ?: null, hash_password($temp)]);
                $uid = (int) db()->lastInsertId();
            } else {
                q('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [$form['name'], $form['email'], $form['phone'] ?: null, $uid]);
                q('DELETE FROM user_roles WHERE user_id = ?', [$uid]);
            }
            foreach ($form['roles'] as $rid) {
                q('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$uid, $rid]);
            }
            db()->commit();
            $roleNames = implode(', ', array_column(array_filter($roles, fn($r) => in_array((int) $r['id'], $form['roles'], true)), 'name'));
            audit($action, 'user', $uid, ($action === 'create' ? 'Created' : 'Updated') . " user {$form['email']}", ['roles' => $roleNames]);
            if ($action === 'create') {
                $_SESSION['temp_password'] = ['email' => $form['email'], 'password' => $temp];
                flash('success', 'User account created.');
            } else {
                flash('success', 'User account updated.');
            }
            redirect('users.php');
        }
        if ($action === 'update') $editId = $uid;
    } elseif ($target && $action === 'toggle_status') {
        if ($uid === (int) $me['id']) {
            flash('danger', 'You cannot suspend your own account.');
        } elseif (in_array($target['status'], ['pending', 'rejected'], true)) {
            flash('danger', 'Student self-registrations are approved or rejected under Registrations.');
        } else {
            $new = $target['status'] === 'active' ? 'suspended' : 'active';
            q('UPDATE users SET status = ? WHERE id = ?', [$new, $uid]);
            audit('status_change', 'user', $uid, ($new === 'active' ? 'Reactivated' : 'Suspended') . " user {$target['email']}");
            flash('success', $target['name'] . ($new === 'active' ? ' reactivated.' : ' suspended. They can no longer sign in.'));
        }
        redirect('users.php' . qs(['edit' => null]));
    } elseif ($target && $action === 'reset_password') {
        $temp = temp_password();
        q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [hash_password($temp), $uid]);
        audit('update', 'user', $uid, "Reset password for {$target['email']}");
        notify($uid, 'Your password was reset', 'An administrator reset your password. You will be asked to choose a new one.', 'account.php');
        $_SESSION['temp_password'] = ['email' => $target['email'], 'password' => $temp];
        flash('success', 'Password reset.');
        redirect('users.php');
    }
}

$editing = $editId ? q_one('SELECT * FROM users WHERE id = ?', [$editId]) : null;
if ($editing && !is_post()) {
    $form = ['name' => $editing['name'], 'email' => $editing['email'], 'phone' => $editing['phone'] ?? '',
             'roles' => array_map('intval', q('SELECT role_id FROM user_roles WHERE user_id = ?', [$editId])->fetchAll(PDO::FETCH_COLUMN))];
}

$search = get('q');
$roleFilter = (int) get('role', '0');
$where = ['1=1'];
$params = [];
if ($search !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
if ($roleFilter) { $where[] = 'EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = u.id AND x.role_id = ?)'; $params[] = $roleFilter; }
$total = (int) q_val('SELECT COUNT(*) FROM users u WHERE ' . implode(' AND ', $where), $params);
$p = paginate($total, 20);
$users = q('SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR \', \') role_names, MAX(r.slug = \'super_admin\') is_super
              FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
             WHERE ' . implode(' AND ', $where) . " GROUP BY u.id ORDER BY u.name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params)->fetchAll();
$temp = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

render_header('Users & access', 'users');
page_header('Users & access', 'Accounts, role assignments and sign-in access.', '<a class="btn btn-ghost" href="roles.php">' . icon('shield') . ' Roles & permissions</a>');
if ($temp): ?>
  <div class="alert alert-info" role="status"><div>Temporary password for <strong><?= e($temp['email']) ?></strong> — share it securely; it won't be shown again. The user must change it at next sign-in.<br><span class="temp-pass"><?= e($temp['password']) ?></span></div></div>
<?php endif; ?>

<div class="grid grid-3">
  <div class="span-2">
    <form class="card filters" method="get" style="margin-bottom:16px">
      <div class="field grow"><label for="uq">Search</label><input id="uq" type="search" name="q" value="<?= e($search) ?>" placeholder="Name or email"></div>
      <div class="field"><label for="ur">Role</label><select id="ur" name="role" data-autosubmit><option value="">All roles</option><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>" <?= $roleFilter === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <div class="toolbar"><button class="btn btn-navy" type="submit">Filter</button></div>
    </form>
    <div class="card card-flush">
      <div class="table-wrap"><table class="table">
        <thead><tr><th>User</th><th>Roles</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $locked = $u['is_super'] && !$isSuper; ?>
          <tr>
            <td><div class="person"><span class="avatar"><?= e(initials($u['name'])) ?></span><div><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></div></div></td>
            <td><?= e($u['role_names'] ?: '—') ?></td>
            <td><?= badge($u['status']) ?><?= $u['must_change_password'] ? ' ' . badge('warning', 'Temp password') : '' ?></td>
            <td><?= e(time_ago($u['last_login_at'])) ?></td>
            <td class="actions">
              <?php if (!$locked): ?>
                <a class="btn btn-ghost btn-sm" href="users.php<?= e(qs(['edit' => $u['id']])) ?>" aria-label="Edit"><?= icon('edit') ?></a>
                <form method="post" class="inline-form" data-confirm="Generate a new temporary password for <?= e($u['name']) ?>? Their current password stops working immediately." data-confirm-button="Reset password"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Reset password"><?= icon('key') ?></button></form>
                <?php if ($u['status'] === 'pending'): ?>
                <a class="btn btn-ghost btn-sm" href="registrations.php">Review</a>
                <?php elseif ((int) $u['id'] !== (int) $me['id'] && $u['status'] !== 'rejected'): ?>
                <form method="post" class="inline-form" data-confirm="<?= $u['status'] === 'active' ? 'Suspend ' . e($u['name']) . '? They will be unable to sign in.' : 'Reactivate ' . e($u['name']) . '?' ?>" data-confirm-button="<?= $u['status'] === 'active' ? 'Suspend' : 'Reactivate' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><button class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-danger-ghost' : 'btn-ghost' ?>" type="submit"><?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?></button></form>
                <?php endif; ?>
              <?php else: ?><span class="muted small"><?= icon('lock') ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr class="empty-row"><td colspan="5">No users found.</td></tr><?php endif; ?>
        </tbody></table></div>
      <?php render_pagination($p); ?>
    </div>
  </div>

  <form class="card" method="post" style="align-self:start" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="user_id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="card-head"><h2><?= $editing ? 'Edit user' : 'New user' ?></h2><?php if ($editing): ?><a class="small" href="users.php">Cancel</a><?php endif; ?></div>
    <div class="stack">
      <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>"><label for="n">Full name</label><input id="n" name="name" value="<?= e($form['name']) ?>" maxlength="150"><?= isset($errors['name']) ? '<span class="error">' . e($errors['name']) . '</span>' : '' ?></div>
      <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>"><label for="em">Email</label><input id="em" type="email" name="email" value="<?= e($form['email']) ?>" maxlength="190"><?= isset($errors['email']) ? '<span class="error">' . e($errors['email']) . '</span>' : '' ?></div>
      <div class="field"><label for="ph">Phone</label><input id="ph" type="tel" name="phone" value="<?= e($form['phone']) ?>" maxlength="30"></div>
      <fieldset class="field" style="border:0;padding:0;margin:0"><legend class="label" style="margin-bottom:6px">Roles</legend>
        <?php foreach ($roles as $r): if ($r['slug'] === 'super_admin' && !$isSuper) continue; ?>
          <label class="checkbox"><input type="checkbox" name="roles[]" value="<?= $r['id'] ?>" <?= in_array((int) $r['id'], $form['roles'], true) ? 'checked' : '' ?>> <?= e($r['name']) ?></label>
        <?php endforeach; ?>
        <?= isset($errors['roles']) ? '<span class="error">' . e($errors['roles']) . '</span>' : '' ?>
      </fieldset>
      <?php if (!$editing): ?><p class="muted small" style="margin:0">A temporary password is generated and shown once after saving.</p><?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit"><?= icon($editing ? 'check' : 'user-plus') ?> <?= $editing ? 'Save changes' : 'Create user' ?></button>
    </div>
  </form>
</div>
<?php render_footer();
