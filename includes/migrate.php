<?php
/**
 * Applies database/migrations/*.sql in filename order, once each.
 * Applied migrations are recorded in the schema_migrations table.
 */

function split_sql(string $sql): array
{
    $sql = preg_replace('/^--.*$/m', '', str_replace("\r\n", "\n", $sql));
    return array_values(array_filter(array_map('trim', explode(";\n", $sql . "\n"))));
}

/** @return string[] names of migrations applied in this run */
function run_migrations(): array
{
    db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(190) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = q('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
    sort($files);
    $applied = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $done, true)) {
            continue;
        }
        foreach (split_sql(file_get_contents($file)) as $statement) {
            db()->exec(rtrim($statement, ';'));
        }
        q('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
        $applied[] = $name;
    }
    return $applied;
}

function pending_migrations(): array
{
    try {
        $done = q('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $done = [];
    }
    $files = array_map('basename', glob(APP_ROOT . '/database/migrations/*.sql') ?: []);
    return array_values(array_diff($files, $done));
}
