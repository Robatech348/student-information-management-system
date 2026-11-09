<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('audit.view');

$tab = get('tab') === 'logins' ? 'logins' : 'audit';
$from = get('from');
$to = get('to');
$validDate = fn($d) => $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$validDate($from)) $from = '';
if (!$validDate($to)) $to = '';

if ($tab === 'audit') {
    $actions = q('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    $entities = q('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
    $action = in_array(get('action'), $actions, true) ? get('action') : '';
    $entity = in_array(get('entity'), $entities, true) ? get('entity') : '';
    $search = get('q');
    $where = ['1=1']; $params = [];
    if ($action) { $where[] = 'a.action = ?'; $params[] = $action; }
    if ($entity) { $where[] = 'a.entity_type = ?'; $params[] = $entity; }
    if ($search !== '') { $where[] = '(a.description LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR a.ip_address = ?)'; array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", $search); }
    if ($from) { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to) { $where[] = 'a.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $w = implode(' AND ', $where);
    $total = (int) q_val("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE {$w}", $params);
    $p = paginate($total, 25);
    $rows = q("SELECT a.*, u.name user_name, u.email user_email FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE {$w} ORDER BY a.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params)->fetchAll();
} else {
    $result = get('result');
    $search = get('q');
    $where = ['1=1']; $params = [];
    if ($result === 'success') $where[] = 'l.success = 1';
    if ($result === 'failed') $where[] = 'l.success = 0';
    if ($search !== '') { $where[] = '(l.email LIKE ? OR l.ip_address = ?)'; array_push($params, "%{$search}%", $search); }
    if ($from) { $where[] = 'l.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to) { $where[] = 'l.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $w = implode(' AND ', $where);
    $total = (int) q_val("SELECT COUNT(*) FROM login_history l WHERE {$w}", $params);
    $p = paginate($total, 25);
    $rows = q("SELECT l.*, u.name user_name FROM login_history l LEFT JOIN users u ON u.id = l.user_id WHERE {$w} ORDER BY l.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params)->fetchAll();
    $failed24 = (int) q_val('SELECT COUNT(*) FROM login_history WHERE success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
}

render_header('Audit logs', 'audit');
page_header('Audit & compliance', 'Who accessed or changed sensitive information, and when. Entries cannot be edited from the application.');
?>
<nav class="tabs">
  <a href="audit.php" class="<?= $tab === 'audit' ? 'is-active' : '' ?>"><?= icon('activity') ?> Audit trail</a>
  <a href="audit.php?tab=logins" class="<?= $tab === 'logins' ? 'is-active' : '' ?>"><?= icon('key') ?> Sign-in history</a>
</nav>

<form class="card filters" method="get" style="margin-bottom:16px">
  <?php if ($tab === 'logins'): ?><input type="hidden" name="tab" value="logins"><?php endif; ?>
  <div class="field grow"><label for="aq">Search</label><input id="aq" type="search" name="q" value="<?= e($search) ?>" placeholder="<?= $tab === 'audit' ? 'Description, user or IP address' : 'Email or IP address' ?>"></div>
  <?php if ($tab === 'audit'): ?>
    <div class="field"><label for="aa">Action</label><select id="aa" name="action" data-autosubmit><option value="">All actions</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e(humanize($a)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="ae">Record type</label><select id="ae" name="entity" data-autosubmit><option value="">All types</option><?php foreach ($entities as $en): ?><option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e(humanize($en)) ?></option><?php endforeach; ?></select></div>
  <?php else: ?>
    <div class="field"><label for="ar">Result</label><select id="ar" name="result" data-autosubmit><option value="">All</option><option value="success" <?= $result === 'success' ? 'selected' : '' ?>>Successful</option><option value="failed" <?= $result === 'failed' ? 'selected' : '' ?>>Failed</option></select></div>
  <?php endif; ?>
  <div class="field"><label for="af">From</label><input id="af" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label for="at">To</label><input id="at" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="toolbar"><button class="btn btn-navy" type="submit">Filter</button></div>
</form>

<?php if ($tab === 'logins' && $failed24): ?><div class="alert alert-warning"><?= $failed24 ?> failed sign-in attempt<?= $failed24 === 1 ? '' : 's' ?> in the last 24 hours.</div><?php endif; ?>

<div class="card card-flush"><div class="table-wrap"><table class="table">
<?php if ($tab === 'audit'): ?>
  <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Description</th><th>IP address</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $meta = $r['metadata'] ? json_decode($r['metadata'], true) : null; ?>
    <tr><td style="white-space:nowrap"><?= e(format_date($r['created_at'], 'M j, Y H:i:s')) ?></td>
      <td><?= e($r['user_name'] ?? 'System') ?><br><small class="muted"><?= e($r['user_email'] ?? '') ?></small></td>
      <td><?= badge(in_array($r['action'], ['delete', 'archive', 'access_denied'], true) ? 'failed' : 'neutral', humanize($r['action'])) ?></td>
      <td><?php if ($r['entity_type'] === 'student' && $r['entity_id']): ?><a href="student_view.php?id=<?= e($r['entity_id']) ?>">Student #<?= e($r['entity_id']) ?></a><?php else: ?><?= e(humanize($r['entity_type']) . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?><?php endif; ?></td>
      <td><?= e($r['description']) ?><?php if (is_array($meta) && $meta): ?><details class="small"><summary class="muted">Details</summary><pre class="mono" style="white-space:pre-wrap;margin:6px 0"><?= e(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td>
      <td class="mono"><?= e($r['ip_address']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr class="empty-row"><td colspan="6">No audit entries match.</td></tr><?php endif; ?>
<?php else: ?>
  <thead><tr><th>When</th><th>Email</th><th>Result</th><th>IP address</th><th>Browser</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td style="white-space:nowrap"><?= e(format_date($r['created_at'], 'M j, Y H:i:s')) ?></td><td><?= e($r['email']) ?><?= $r['user_name'] ? '<br><small class="muted">' . e($r['user_name']) . '</small>' : '' ?></td>
      <td><?= $r['success'] ? badge('success', 'Success') : badge('failed', 'Failed') ?></td><td class="mono"><?= e($r['ip_address']) ?></td><td class="small muted" style="max-width:320px"><?= e(mb_strimwidth((string) $r['user_agent'], 0, 80, '…')) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr class="empty-row"><td colspan="5">No sign-in records match.</td></tr><?php endif; ?>
<?php endif; ?>
</tbody></table></div>
<?php render_pagination($p); ?></div>
<?php render_footer();
