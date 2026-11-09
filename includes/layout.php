<?php
/**
 * Page shell: sidebar + top navigation. Navigation items are filtered by permission;
 * modules scheduled for later phases are shown to staff as locked "roadmap" items.
 */

function nav_items(): array
{
    $student = has_role('student') && count(current_user()['roles']) === 1;
    if ($student) {
        return [
            'My portal' => [
                ['dashboard', 'Dashboard', 'dashboard.php', 'home'],
                ['programme', 'Daily programme', 'programme.php', 'calendar'],
                ['announcements', 'Announcements', 'announcements.php', 'message'],
                ['my_profile', 'My profile', 'student_view.php', 'user'],
                ['my_documents', 'My documents', 'student_view.php?tab=documents', 'file'],
                ['notifications', 'Notifications', 'notifications.php', 'bell'],
            ],
            'Coming soon' => [
                ['', 'Registered units', '', 'book', 'Phase 3'],
                ['', 'Results & transcripts', '', 'award', 'Phase 3'],
                ['', 'Fee statement', '', 'wallet', 'Phase 4'],
                ['', 'Learning materials', '', 'library', 'Phase 5'],
            ],
            'Account' => [
                ['account', 'Account & security', 'account.php', 'key'],
            ],
        ];
    }
    return [
        'Overview' => [
            ['dashboard', 'Dashboard', 'dashboard.php', 'home'],
            ['programme', 'Daily programme', 'programme.php', 'calendar'],
            ['announcements', 'Announcements', 'announcements.php', 'message'],
        ],
        'Student services' => [
            ['students', 'Students', 'students.php', 'users', null, 'students.view'],
            ['registrations', 'Registrations', 'registrations.php', 'clipboard', null, 'registrations.approve'],
            ['', 'Advising & mentorship', '', 'message', 'Phase 5'],
            ['', 'Graduation & clearance', '', 'graduation', 'Phase 5'],
        ],
        'Academics' => [
            ['academics', 'Programs & structure', 'academics.php', 'layers', null, 'academics.manage'],
            ['', 'Course registration', '', 'book', 'Phase 3'],
            ['', 'Exams & results', '', 'award', 'Phase 3'],
            ['', 'Attendance', '', 'check', 'Phase 3'],
            ['', 'Timetable & rooms', '', 'calendar', 'Phase 3'],
            ['', 'Learning (LMS)', '', 'library', 'Phase 5'],
        ],
        'Finance' => [
            ['', 'Fees & M-Pesa', '', 'wallet', 'Phase 4'],
        ],
        'People' => [
            ['staff', 'Staff directory', 'staff.php', 'briefcase', null, 'staff.view'],
        ],
        'Campus life' => [
            ['', 'Hostels', '', 'bed', 'Later'],
            ['', 'Transport', '', 'bus', 'Later'],
            ['', 'Procurement', '', 'box', 'Later'],
        ],
        'Intelligence' => [
            ['', 'AI insights', '', 'sparkles', 'Phase 6'],
        ],
        'Administration' => [
            ['users', 'Users & access', 'users.php', 'user-plus', null, 'users.manage'],
            ['roles', 'Roles & permissions', 'roles.php', 'shield', null, 'roles.manage'],
            ['audit', 'Audit logs', 'audit.php', 'activity', null, 'audit.view'],
            ['settings', 'Settings', 'settings.php', 'settings', null, 'settings.manage'],
        ],
    ];
}

function render_header(string $title, string $active = ''): void
{
    $u = current_user();
    $unread = $u ? (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']]) : 0;
    $institution = setting('institution_name', 'Northstar University');
    $showRoadmap = can('dashboard.analytics');
    $pendingRegs = can('registrations.approve') ? (int) q_val("SELECT COUNT(*) FROM students WHERE enrollment_status = 'pending' AND deleted_at IS NULL") : 0;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e(config('app_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<script src="assets/js/theme.js"></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="brand">
      <img src="assets/img/logo.svg" alt="" width="34" height="34">
      <div class="brand-text">
        <strong><?= e(config('app_name')) ?></strong>
        <span><?= e($institution) ?></span>
      </div>
    </div>
    <nav class="nav">
      <?php foreach (nav_items() as $section => $items):
          $visible = array_filter($items, function ($i) use ($showRoadmap) {
              if (!empty($i[4])) return $showRoadmap || has_role('student');
              return empty($i[5]) || can($i[5]);
          });
          if (!$visible) continue; ?>
        <div class="nav-section"><?= e($section) ?></div>
        <?php foreach ($visible as $i):
            if (!empty($i[4])): ?>
              <span class="nav-link is-locked" title="Planned for <?= e($i[4]) ?>">
                <?= icon($i[3]) ?><span class="nav-label"><?= e($i[1]) ?></span><span class="nav-phase"><?= e($i[4]) ?></span>
              </span>
            <?php else: ?>
              <a class="nav-link<?= $active === $i[0] ? ' is-active' : '' ?>" href="<?= e($i[2]) ?>"<?= $active === $i[0] ? ' aria-current="page"' : '' ?>>
                <?= icon($i[3]) ?><span class="nav-label"><?= e($i[1]) ?></span>
                <?php if ($i[0] === 'notifications' && $unread): ?><span class="nav-count"><?= $unread ?></span><?php endif; ?>
                <?php if ($i[0] === 'registrations' && $pendingRegs): ?><span class="nav-count nav-count-warn"><?= $pendingRegs ?></span><?php endif; ?>
              </a>
            <?php endif;
        endforeach;
      endforeach; ?>
    </nav>
    <div class="sidebar-foot">v1.0 · Phase 1–2</div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>

  <div class="main-wrap">
    <header class="topbar">
      <button class="icon-btn" type="button" data-sidebar-toggle aria-label="Toggle navigation"><?= icon('menu') ?></button>
      <form class="global-search" action="search.php" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Search students, staff, programs…" value="<?= e(get('q')) ?>" aria-label="Global search" autocomplete="off">
        <kbd>/</kbd>
      </form>
      <div class="topbar-actions">
        <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode">
          <span class="theme-icon-light"><?= icon('moon') ?></span><span class="theme-icon-dark"><?= icon('sun') ?></span>
        </button>
        <a class="icon-btn bell" href="notifications.php" aria-label="Notifications">
          <?= icon('bell') ?><span class="bell-dot" data-unread-count <?= $unread ? '' : 'hidden' ?>><?= $unread ?></span>
        </a>
        <details class="user-menu">
          <summary>
            <span class="avatar"><?= e(initials($u['name'])) ?></span>
            <span class="user-meta"><strong><?= e($u['name']) ?></strong><small><?= e(reset($u['roles']) ?: 'User') ?></small></span>
          </summary>
          <div class="menu">
            <div class="menu-head"><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></div>
            <a href="account.php"><?= icon('key') ?> Account &amp; security</a>
            <a href="notifications.php"><?= icon('bell') ?> Notifications</a>
            <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?> Sign out</button></form>
          </div>
        </details>
      </div>
    </header>

    <main class="content" id="main">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?><button type="button" class="alert-close" aria-label="Dismiss">&times;</button></div>
      <?php endforeach;
}

function render_footer(): void
{
    ?>
    </main>
    <footer class="app-footer">
      <span>&copy; <?= date('Y') ?> <?= e(setting('institution_name', 'Northstar University')) ?></span>
      <span><?= e(config('app_name')) ?> by <?= e(config('vendor')) ?></span>
    </footer>
  </div>
</div>

<dialog class="confirm-dialog" id="confirm-dialog">
  <form method="dialog">
    <h3 data-confirm-title>Are you sure?</h3>
    <p data-confirm-message></p>
    <div class="dialog-actions">
      <button class="btn btn-ghost" value="cancel">Cancel</button>
      <button class="btn btn-danger" value="ok" data-confirm-ok>Confirm</button>
    </div>
  </form>
</dialog>

<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}

/** Page heading with optional action buttons (raw HTML). */
function page_header(string $title, string $subtitle = '', string $actions = ''): void
{
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>'
       . ($subtitle ? '<p class="muted">' . e($subtitle) . '</p>' : '')
       . '</div>' . ($actions ? '<div class="page-actions">' . $actions . '</div>' : '') . '</div>';
}

function render_pagination(array $p): void
{
    if ($p['pages'] <= 1) {
        echo '<div class="pagination"><span class="muted">' . $p['total'] . ' record' . ($p['total'] === 1 ? '' : 's') . '</span></div>';
        return;
    }
    $from = $p['offset'] + 1;
    $to = min($p['total'], $p['offset'] + $p['per_page']);
    echo '<div class="pagination"><span class="muted">Showing ' . $from . '–' . $to . ' of ' . $p['total'] . '</span><div class="pages">';
    echo $p['page'] > 1 ? '<a href="' . e(qs(['page' => $p['page'] - 1])) . '">‹ Prev</a>' : '<span class="disabled">‹ Prev</span>';
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        echo $i === $p['page'] ? '<span class="current">' . $i . '</span>' : '<a href="' . e(qs(['page' => $i])) . '">' . $i . '</a>';
    }
    echo $p['page'] < $p['pages'] ? '<a href="' . e(qs(['page' => $p['page'] + 1])) . '">Next ›</a>' : '<span class="disabled">Next ›</span>';
    echo '</div></div>';
}
