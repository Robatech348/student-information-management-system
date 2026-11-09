<?php
/**
 * Authentication, role-based access control (RBAC) and CSRF protection.
 */

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(config('session.name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $timeout = (int) (setting('session_timeout_minutes') ?: 0) * 60 ?: (int) config('session.idle_timeout');
    if (!empty($_SESSION['user_id']) && isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $timeout) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('warning', 'Your session expired after a period of inactivity. Please sign in again.');
    }
    // Background polling must not keep an idle session alive.
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'api_notifications.php') {
        $_SESSION['last_activity'] = time();
    }
}

function hash_password(string $password): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($password, PASSWORD_ARGON2ID)
        : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function password_algo()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

/** @return string|null error message, or null if the password is acceptable */
function password_problem(string $password): ?string
{
    $min = (int) config('security.min_password_length', 10);
    if (strlen($password) < $min) return "Password must be at least {$min} characters.";
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) return 'Password must mix upper- and lower-case letters.';
    if (!preg_match('/\d/', $password)) return 'Password must contain at least one number.';
    return null;
}

/** Loads the signed-in user with roles and permissions (cached per request). */
function current_user(): ?array
{
    static $user = null, $loadedFor = null;
    $sid = $_SESSION['user_id'] ?? null;
    if (empty($sid)) {
        return null;
    }
    if ($loadedFor === $sid && $user !== null) {
        return $user;
    }
    $loadedFor = $sid;
    $u = q_one('SELECT id, name, email, phone, status, must_change_password, last_login_at FROM users WHERE id = ?', [$_SESSION['user_id']]);
    if (!$u || $u['status'] !== 'active') {
        $_SESSION = [];
        return $user = null;
    }
    $u['roles'] = q('SELECT r.slug, r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.id', [$u['id']])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $u['permissions'] = q('SELECT DISTINCT p.slug FROM permissions p
                            JOIN role_permissions rp ON rp.permission_id = p.id
                            JOIN user_roles ur ON ur.role_id = rp.role_id
                           WHERE ur.user_id = ?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
    $u['primary_role'] = array_key_first($u['roles']) ?? 'guest';
    return $user = $u;
}

function has_role(string $slug): bool
{
    $u = current_user();
    return $u && isset($u['roles'][$slug]);
}

function can(string $permission): bool
{
    $u = current_user();
    if (!$u) return false;
    if (isset($u['roles']['super_admin'])) return true;
    return in_array($permission, $u['permissions'], true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $target = basename($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('/^[a-z_]+\.php(\?[^\s]*)?$/i', $target)) {
            $_SESSION['intended'] = $target;
        }
        $studentPages = ['dashboard.php', 'student_view.php', 'programme.php', 'announcements.php', 'notifications.php', 'account.php'];
        redirect('login.php?as=' . (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $studentPages, true) ? 'student' : 'staff'));
    }
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($u['must_change_password'] && !in_array($page, ['account.php', 'logout.php'], true)) {
        flash('warning', 'Please set a new password before continuing.');
        redirect('account.php');
    }
    return $u;
}

function require_permission(string $permission): array
{
    $u = require_login();
    if (!can($permission)) {
        audit('access_denied', 'permission', null, "Denied: {$permission}", ['page' => $_SERVER['SCRIPT_NAME'] ?? '']);
        abort(403);
    }
    return $u;
}

function login_locked(string $email, string $ip): bool
{
    $window = (int) config('security.lockout_window_minutes', 15);
    $since  = date('Y-m-d H:i:s', time() - $window * 60);
    $byEmail = (int) q_val('SELECT COUNT(*) FROM login_history WHERE email = ? AND success = 0 AND created_at >= ?', [$email, $since]);
    $byIp    = (int) q_val('SELECT COUNT(*) FROM login_history WHERE ip_address = ? AND success = 0 AND created_at >= ?', [$ip, $since]);
    return $byEmail >= (int) config('security.max_failed_logins_per_email', 5)
        || $byIp >= (int) config('security.max_failed_logins_per_ip', 20);
}

/**
 * Sign in through one of the two portals.
 *  - 'staff':   email + password; accounts with any non-student role.
 *  - 'student': email OR student number + password; student accounts only.
 * @return array{0:bool,1:string}
 */
function attempt_login(string $identifier, string $password, string $portal = 'staff'): array
{
    $ip = client_ip();
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $identifier = strtolower(trim($identifier));
    $what = $portal === 'student' ? 'student number/email' : 'email';

    if ($identifier === '' || $password === '') {
        return [false, "Enter your {$what} and password."];
    }

    $email = $identifier;
    if ($portal === 'student' && strpos($identifier, '@') === false) {
        $email = (string) q_val('SELECT u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.student_no = ? AND s.deleted_at IS NULL', [strtoupper($identifier)]) ?: $identifier;
    }
    if (login_locked($email, $ip)) {
        return [false, 'Too many failed attempts. Try again in ' . config('security.lockout_window_minutes') . ' minutes.'];
    }

    $user = q_one('SELECT id, password_hash, status FROM users WHERE email = ?', [$email]);
    // Verify against a dummy hash when the user doesn't exist so response time doesn't reveal valid accounts.
    $hash = $user['password_hash'] ?? '$2y$12$3RnRc20UKVNzMCVF6VSWr.XxT1NV/6KgnfsLWlq1rthcbV6/ewuDu';
    $valid = password_verify($password, $hash) && $user !== null;

    $fail = function (string $msg) use ($user, $email, $ip, $ua) {
        q('INSERT INTO login_history (user_id, email, ip_address, user_agent, success) VALUES (?, ?, ?, ?, 0)', [$user['id'] ?? null, $email, $ip, $ua]);
        return [false, $msg];
    };
    if (!$valid) {
        return $fail("The {$what} or password is incorrect.");
    }

    // Correct password: now enforce account state and the right portal.
    $roles = q('SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [$user['id']])->fetchAll(PDO::FETCH_COLUMN);
    $isStaff = (bool) array_diff($roles, ['student']);
    if ($user['status'] === 'pending') {
        return $fail('Your registration has been received and is awaiting approval by the Registrar\'s office. You will be able to sign in once it is approved.');
    }
    if ($user['status'] === 'rejected') {
        $reason = q_val('SELECT rejection_reason FROM students WHERE user_id = ?', [$user['id']]);
        return $fail('Your registration was not approved' . ($reason ? ': ' . rtrim($reason, '. ') . '.' : '.') . ' Contact the Registrar\'s office for help.');
    }
    if ($user['status'] !== 'active') {
        return $fail('This account is suspended. Contact the system administrator.');
    }
    if ($portal === 'student' && $isStaff) {
        return $fail('This is a staff account. Please use the Staff sign-in tab.');
    }
    if ($portal === 'staff' && !$isStaff) {
        return $fail('This is a student account. Please use the Student sign-in tab.');
    }

    if (password_needs_rehash($user['password_hash'], password_algo())) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    q('INSERT INTO login_history (user_id, email, ip_address, user_agent, success) VALUES (?, ?, ?, ?, 1)', [$user['id'], $email, $ip, $ua]);
    audit('login', 'user', $user['id'], 'Signed in');
    return [true, ''];
}

function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        audit('logout', 'user', $_SESSION['user_id'], 'Signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        abort(419, 'Your form expired. Go back, refresh the page and try again.');
    }
}

/** Students linked to the current user account (for the student portal). */
function current_student(): ?array
{
    $u = current_user();
    if (!$u) return null;
    return q_one('SELECT * FROM students WHERE user_id = ? AND deleted_at IS NULL', [$u['id']]);
}
