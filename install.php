<?php
/**
 * UniSphere installer.
 *   Browser: http://localhost/ims/install.php
 *   CLI:     php install.php [--fresh]     (--fresh drops and recreates the database)
 * Once installed, storage/installed.lock blocks the web installer.
 */
define('SKIP_INSTALL_CHECK', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/database/seed.php';
require __DIR__ . '/includes/migrate.php';

$cli   = PHP_SAPI === 'cli';
$fresh = $cli && in_array('--fresh', $argv ?? [], true);
$lock  = STORAGE_PATH . '/installed.lock';

function run_install(bool $fresh): array
{
    $c = config('db');
    $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = str_replace('`', '', $c['name']);
    if ($fresh) {
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
    $exists = $server->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $server->quote($name))->fetchColumn();
    if ($exists > 0) {
        throw new RuntimeException("Database '{$name}' already contains tables. Run `php install.php --fresh` to rebuild it.");
    }
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $sql = file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql)))) as $statement) {
        db()->exec($statement);
    }
    db()->beginTransaction();
    try {
        $result = seed_database();
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    run_migrations();
    db()->beginTransaction();
    seed_portal_demo();
    db()->commit();
    foreach (['', '/photos', '/documents'] as $dir) {
        if (!is_dir(STORAGE_PATH . $dir)) mkdir(STORAGE_PATH . $dir, 0750, true);
    }
    file_put_contents(STORAGE_PATH . '/installed.lock', date('c'));
    return $result;
}

if ($cli) {
    if (is_file($lock) && !$fresh) {
        fwrite(STDERR, "Already installed. Use --fresh to rebuild (this ERASES all data).\n");
        exit(1);
    }
    try {
        $r = run_install($fresh);
        echo "UniSphere installed.\nDemo password for all accounts: {$r['password']}\n";
        foreach ($r['accounts'] as $a) {
            printf("  %-32s %s\n", $a['email'], $a['role']);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

start_secure_session();
$result = null;
$error = null;
if (is_file($lock)) {
    $error = 'UniSphere is already installed. To reinstall, delete storage/installed.lock or run "php install.php --fresh" from the command line.';
} elseif (is_post()) {
    verify_csrf();
    try {
        $result = run_install(false);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · UniSphere</title>
<link rel="stylesheet" href="assets/css/app.css">
<script src="assets/js/theme.js"></script>
</head>
<body class="auth-body">
<main class="install-card card">
  <div class="brand brand-dark"><img src="assets/img/logo.svg" alt="" width="40" height="40"><div class="brand-text"><strong>UniSphere</strong><span>Installer</span></div></div>
  <?php if ($result): ?>
    <h1>Installation complete</h1>
    <p>The database, roles and demo data were created. All demo accounts use the password <code><?= e($result['password']) ?></code>.</p>
    <table class="table"><thead><tr><th>Email</th><th>Role</th></tr></thead><tbody>
      <?php foreach ($result['accounts'] as $a): ?><tr><td><?= e($a['email']) ?></td><td><?= e($a['role']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="muted">Change these passwords and disable demo credentials in Settings before real use.</p>
    <a class="btn btn-primary" href="login.php">Go to sign in</a>
  <?php elseif ($error): ?>
    <h1>Installer</h1>
    <div class="alert alert-danger"><?= e($error) ?></div>
    <a class="btn btn-ghost" href="login.php">Go to sign in</a>
  <?php else: ?>
    <h1>Install UniSphere</h1>
    <p>This creates the <code><?= e(config('db.name')) ?></code> database on <code><?= e(config('db.host')) ?></code>, builds the schema and loads demo data.</p>
    <ul class="checklist">
      <li><?= icon('check') ?> PHP <?= e(PHP_VERSION) ?> <?= version_compare(PHP_VERSION, '8.0', '>=') ? '' : '(8.0+ required)' ?></li>
      <li><?= icon('check') ?> PDO MySQL: <?= extension_loaded('pdo_mysql') ? 'available' : 'missing' ?></li>
      <li><?= icon('check') ?> Fileinfo: <?= extension_loaded('fileinfo') ? 'available' : 'missing' ?></li>
      <li><?= icon('check') ?> Password hashing: <?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?></li>
      <li><?= icon('check') ?> Storage writable: <?= is_writable(STORAGE_PATH) ? 'yes' : 'no' ?></li>
    </ul>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Install now</button></form>
  <?php endif; ?>
</main>
</body>
</html>
