<?php
/** Serves a student photograph to authorised users only. */
require __DIR__ . '/includes/bootstrap.php';
require_login();

$s = q_one('SELECT id, user_id, photo_path FROM students WHERE id = ?', [(int) get('id', '0')]);
if (!$s || !$s['photo_path']) abort(404);
$isOwner = $s['user_id'] && (int) $s['user_id'] === (int) current_user()['id'];
if (!$isOwner && !can('students.view')) abort(403);

$path = realpath(STORAGE_PATH . '/' . $s['photo_path']);
if (!$path || strpos($path, realpath(STORAGE_PATH)) !== 0 || !is_file($path)) abort(404);
header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
