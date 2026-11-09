<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        if (PHP_SAPI === 'cli' || defined('SKIP_INSTALL_CHECK')) {
            throw $e;
        }
        http_response_code(503);
        echo '<!doctype html><meta charset="utf-8"><title>Database unavailable</title>'
           . '<body style="font-family:system-ui;padding:40px;background:#F5F7FA;color:#10243A">'
           . '<h1>Database unavailable</h1><p>UniSphere could not connect to MySQL. '
           . 'Start MySQL from the XAMPP Control Panel and check <code>config/config.php</code>.</p>'
           . (config('debug') ? '<pre>' . e($e->getMessage()) . '</pre>' : '') . '</body>';
        exit;
    }
    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}
