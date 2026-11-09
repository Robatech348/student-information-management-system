<?php
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
echo json_encode(['unread' => (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']])]);
