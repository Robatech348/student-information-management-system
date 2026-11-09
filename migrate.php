<?php
/**
 * Upgrade an existing installation without losing data.
 *   php migrate.php           apply pending database migrations
 *   php migrate.php --demo    also load demo daily-programme, announcements and sample registrations
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SKIP_INSTALL_CHECK', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/migrate.php';
require __DIR__ . '/database/seed.php';

try {
    $applied = run_migrations();
    echo $applied ? 'Applied: ' . implode(', ', $applied) . "\n" : "Database is up to date.\n";
    if (in_array('--demo', $argv, true)) {
        db()->beginTransaction();
        seed_portal_demo();
        db()->commit();
        echo "Demo programme, announcements and pending registrations loaded.\n";
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
