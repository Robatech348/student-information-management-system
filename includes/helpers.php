<?php

function config(string $key, $default = null)
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): string
{
    // Deliberately ignores X-Forwarded-For; configure a trusted proxy before relying on it.
    return substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = q('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function save_setting(string $key, string $value): void
{
    q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

/** Write an entry to the audit trail. Never throws – auditing must not break the request. */
function audit(string $action, string $entityType, $entityId = null, string $description = '', array $meta = []): void
{
    try {
        q('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, metadata, ip_address, user_agent)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $_SESSION['user_id'] ?? null,
            $action,
            $entityType,
            $entityId === null ? null : (string) $entityId,
            mb_substr($description, 0, 255),
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('Audit write failed: ' . $e->getMessage());
    }
}

function notify(int $userId, string $title, string $body = '', ?string $link = null): void
{
    q('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)', [$userId, $title, $body, $link]);
}

/** @return array{page:int,pages:int,offset:int,per_page:int,total:int} */
function paginate(int $total, int $perPage = 15): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage, 'total' => $total];
}

/** Current query string with some keys replaced (null removes the key). */
function qs(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $params ? '?' . http_build_query($params) : '?';
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 86400 * 7) return floor($diff / 86400) . ' d ago';
    return format_date($datetime);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0] ?? '';
    $last  = count($parts) > 1 ? end($parts) : '';
    return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

function full_name(array $p): string
{
    return trim(($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
}

function humanize(string $value): string
{
    return ucfirst(str_replace('_', ' ', $value));
}

/** Coloured status pill. */
function badge(string $status, ?string $label = null): string
{
    $map = [
        'active' => 'success', 'good' => 'success', 'dean_list' => 'info', 'graduated' => 'info',
        'suspended' => 'danger', 'withdrawn' => 'danger', 'probation' => 'danger', 'exited' => 'danger',
        'deferred' => 'warning', 'warning' => 'warning', 'on_leave' => 'warning',
        'success' => 'success', 'failed' => 'danger',
        'pending' => 'warning', 'rejected' => 'danger',
    ];
    $tone = $map[$status] ?? 'neutral';
    return '<span class="badge badge-' . $tone . '">' . e($label ?? humanize($status)) . '</span>';
}

/** Generates a human-friendly random temporary password. */
function temp_password(): string
{
    $words = ['River', 'Cedar', 'Summit', 'Harbor', 'Falcon', 'Meadow', 'Orbit', 'Savanna', 'Comet', 'Baobab'];
    return $words[random_int(0, count($words) - 1)] . '-' . random_int(1000, 9999) . '-' . $words[random_int(0, count($words) - 1)] . '!';
}

/** Neutralise spreadsheet formula injection in CSV exports. */
function csv_safe($value): string
{
    $value = (string) ($value ?? '');
    if (preg_match('/^\+?[\d\s()-]+$/', $value)) {
        return $value; // phone numbers / plain numbers are safe
    }
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

function abort(int $code, string $message = ''): void
{
    http_response_code($code);
    $titles = [403 => 'Access denied', 404 => 'Not found', 419 => 'Session expired', 405 => 'Method not allowed'];
    $title = $titles[$code] ?? 'Error';
    if (function_exists('current_user') && current_user()) {
        render_header($title, '');
        echo '<div class="empty-state card"><div class="empty-icon">' . icon('shield') . '</div><h2>' . e($title) . '</h2><p>' . e($message ?: 'You do not have permission to view this page.') . '</p><a class="btn btn-primary" href="dashboard.php">Back to dashboard</a></div>';
        render_footer();
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>' . e($title) . '</title><body style="font-family:system-ui;padding:40px"><h1>' . e($title) . '</h1><p>' . e($message) . '</p><a href="login.php">Sign in</a></body>';
    }
    exit;
}

/**
 * Validate and store an uploaded file in a non-public storage folder.
 * @return array{path:string,mime:string,size:int,original:string}
 * @throws RuntimeException with a user-facing message
 */
function store_upload(array $file, string $folder, array $allowedMimes, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'The file is larger than the server allows.' : 'The file could not be uploaded.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('The file must be ' . round($maxBytes / 1048576, 1) . ' MB or smaller.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException('That file type is not allowed.');
    }
    $dir = STORAGE_PATH . '/' . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('The file could not be saved.');
    }
    return [
        'path'     => $folder . '/' . $name,
        'mime'     => $mime,
        'size'     => (int) $file['size'],
        'original' => mb_substr(basename($file['name']), 0, 200),
    ];
}

/** Next official student number, e.g. STU-2026-10483. */
function next_student_no(?string $admissionDate = null): string
{
    $prefix = setting('student_no_prefix', 'STU');
    $year = date('Y', $admissionDate ? strtotime($admissionDate) : time());
    $last = q_val("SELECT MAX(CAST(SUBSTRING_INDEX(student_no, '-', -1) AS UNSIGNED)) FROM students WHERE student_no NOT LIKE 'APP-%'");
    return sprintf('%s-%s-%05d', $prefix, $year, max(10001, (int) $last + 1));
}

/** Temporary reference for a self-registration awaiting approval, e.g. APP-2026-482913. */
function applicant_no(): string
{
    do {
        $no = 'APP-' . date('Y') . '-' . random_int(100000, 999999);
    } while (q_val('SELECT id FROM students WHERE student_no = ?', [$no]));
    return $no;
}

/** IDs of active users who hold a permission (super admins always included). */
function users_with_permission(string $permission): array
{
    return array_map('intval', q("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
        LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
        WHERE u.status = 'active' AND (r.slug = 'super_admin' OR p.slug = ?)", [$permission])->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * SQL condition (alias si) + params limiting schedule items to those meant for a student.
 * @return array{0:string,1:array}
 */
function schedule_audience_filter(?array $student): array
{
    if (!$student) {
        return ['1=1', []];
    }
    $facultyId = (int) q_val('SELECT d.faculty_id FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.id = ?', [$student['program_id']]);
    return ["(si.audience = 'all' OR (si.audience = 'faculty' AND si.faculty_id = ?) OR (si.audience = 'program' AND si.program_id = ? AND (si.year_of_study IS NULL OR si.year_of_study = ?)) OR (si.audience = 'year' AND si.year_of_study = ?))",
            [$facultyId, $student['program_id'], $student['year_of_study'], $student['year_of_study']]];
}

/** Announcements visible to the current user. @return array{0:string,1:array} */
function announcement_audience_filter(): array
{
    if (can('announcements.manage')) {
        return ['(a.expires_at IS NULL OR a.expires_at >= CURDATE())', []];
    }
    $isStudentOnly = has_role('student') && count(current_user()['roles']) === 1;
    return ["(a.expires_at IS NULL OR a.expires_at >= CURDATE()) AND a.audience IN ('everyone', ?)", [$isStudentOnly ? 'students' : 'staff']];
}

function schedule_category_meta(): array
{
    return [
        'class'    => ['Class', 'book', 'teal'],
        'exam'     => ['Exam / CAT', 'award', 'rose'],
        'assembly' => ['Assembly', 'users', 'navy'],
        'sports'   => ['Sports', 'activity', 'amber'],
        'meeting'  => ['Meeting', 'briefcase', 'navy'],
        'event'    => ['Event', 'sparkles', 'amber'],
        'holiday'  => ['Holiday', 'sun', 'teal'],
        'other'    => ['Other', 'clock', 'navy'],
    ];
}
