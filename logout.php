<?php
require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    abort(405, 'Use the Sign out button to sign out.');
}
verify_csrf();
$u = current_user();
$wasStaff = $u && array_diff(array_keys($u['roles']), ['student']);
logout_user();
start_secure_session();
flash('success', 'You have been signed out.');
redirect('login.php?as=' . ($wasStaff ? 'staff' : 'student'));
