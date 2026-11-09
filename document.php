<?php
/** Streams a stored student document after an authorization check. Files live outside public access (storage/). */
require __DIR__ . '/includes/bootstrap.php';
require_login();

$doc = q_one('SELECT d.*, s.user_id, s.student_no FROM student_documents d JOIN students s ON s.id = d.student_id WHERE d.id = ?', [(int) get('id', '0')]);
if (!$doc) abort(404, 'Document not found.');
$isOwner = $doc['user_id'] && (int) $doc['user_id'] === (int) current_user()['id'];
if (!$isOwner && !can('students.documents')) abort(403);

$path = realpath(STORAGE_PATH . '/' . $doc['file_path']);
if (!$path || strpos($path, realpath(STORAGE_PATH)) !== 0 || !is_file($path)) abort(404, 'The file is missing from storage.');

audit('download', 'student', $doc['student_id'], "Downloaded document \"{$doc['title']}\" ({$doc['student_no']})");
$name = preg_replace('/[^\w.\- ]+/', '_', $doc['original_name']);
header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: private, no-store');
readfile($path);
