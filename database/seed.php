<?php
/**
 * Seeds roles, permissions, institution structure and demo data.
 * Called by install.php. Returns the demo accounts that were created.
 */

const DEMO_PASSWORD = 'Unisphere@2026';

function seed_database(): array
{
    mt_srand(2026); // deterministic demo data

    /* ---------- Settings ---------- */
    $settings = [
        'institution_name'        => 'Northstar University',
        'institution_short'       => 'NSU',
        'institution_email'       => 'info@northstar.ac.ke',
        'institution_phone'       => '+254 700 000 000',
        'academic_year'           => '2026/2027',
        'student_no_prefix'       => 'STU',
        'session_timeout_minutes' => '30',
        'show_demo_credentials'   => '1',
    ];
    foreach ($settings as $k => $v) {
        save_setting($k, $v);
    }

    /* ---------- Roles & permissions ---------- */
    $roles = [
        'super_admin' => ['Super Administrator', 'Full access to every module, configuration and security setting.'],
        'registrar'   => ['Registrar / Academic Officer', 'Admissions, student records, academic structure and results approval.'],
        'finance'     => ['Finance Officer', 'Fee structures, invoices, payments and financial reporting.'],
        'lecturer'    => ['Lecturer', 'Assigned courses, learning materials, marks entry and attendance.'],
        'student'     => ['Student', 'Self-service student portal.'],
    ];
    $roleIds = [];
    foreach ($roles as $slug => [$name, $desc]) {
        q('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 1)', [$slug, $name, $desc]);
        $roleIds[$slug] = (int) db()->lastInsertId();
    }

    $permissions = [
        ['dashboard.analytics', 'View institutional analytics dashboard', 'Dashboard'],
        ['students.view',       'View student records',                  'Students'],
        ['students.create',     'Create student records',                'Students'],
        ['students.edit',       'Edit student records',                  'Students'],
        ['students.delete',     'Archive student records',               'Students'],
        ['students.export',     'Export student data',                   'Students'],
        ['students.documents',  'View and upload student documents',     'Students'],
        ['academics.manage',    'Manage faculties, departments, programs', 'Academics'],
        ['staff.view',          'View staff directory',                  'Staff'],
        ['users.manage',        'Manage user accounts',                  'Administration'],
        ['roles.manage',        'Manage roles and permissions',          'Administration'],
        ['audit.view',          'View audit logs and login history',     'Administration'],
        ['settings.manage',     'Manage system settings and calendar',   'Administration'],
    ];
    $permIds = [];
    foreach ($permissions as [$slug, $name, $module]) {
        q('INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)', [$slug, $name, $module]);
        $permIds[$slug] = (int) db()->lastInsertId();
    }
    $grants = [
        'super_admin' => array_keys($permIds),
        'registrar'   => ['dashboard.analytics', 'students.view', 'students.create', 'students.edit', 'students.delete', 'students.export', 'students.documents', 'academics.manage', 'staff.view'],
        'finance'     => ['dashboard.analytics', 'students.view', 'students.export'],
        'lecturer'    => ['students.view', 'staff.view'],
        'student'     => [],
    ];
    foreach ($grants as $role => $perms) {
        foreach ($perms as $p) {
            q('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleIds[$role], $permIds[$p]]);
        }
    }

    /* ---------- Demo user accounts ---------- */
    $hash = hash_password(DEMO_PASSWORD);
    $accounts = [
        ['Alex Mwangi',      'admin@northstar.ac.ke',     'super_admin'],
        ['Grace Wanjiru',    'registrar@northstar.ac.ke', 'registrar'],
        ['Peter Otieno',     'finance@northstar.ac.ke',   'finance'],
        ['Dr. Morgan Chen',  'lecturer@northstar.ac.ke',  'lecturer'],
        ['Jordan Lee',       'student@northstar.ac.ke',   'student'],
    ];
    $userIds = [];
    foreach ($accounts as [$name, $email, $role]) {
        q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', [$name, $email, $hash]);
        $userIds[$role] = (int) db()->lastInsertId();
        q('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userIds[$role], $roleIds[$role]]);
    }

    /* ---------- Campuses, faculties, departments, programs ---------- */
    $campusIds = [];
    foreach ([['MAIN', 'Main Campus', 'Nairobi'], ['CST', 'Coast Campus', 'Mombasa'], ['WST', 'Western Campus', 'Kisumu']] as $c) {
        q('INSERT INTO campuses (code, name, location) VALUES (?, ?, ?)', $c);
        $campusIds[] = (int) db()->lastInsertId();
    }

    $structure = [
        ['SCI', 'School of Computing & Informatics', 'Prof. Amina Yusuf', [
            ['CS', 'Computer Science', [['BSCCS', 'BSc Computer Science', 'bachelor', 4, 120], ['MSCCS', 'MSc Computer Science', 'master', 2, 60]]],
            ['IT', 'Information Technology', [['BSCIT', 'BSc Information Technology', 'bachelor', 4, 120], ['DIT', 'Diploma in Information Technology', 'diploma', 2, 60]]],
            ['DSA', 'Data Science & Analytics', [['BSCDS', 'BSc Data Science', 'bachelor', 4, 120]]],
        ]],
        ['SBE', 'School of Business & Economics', 'Prof. David Kamau', [
            ['ACF', 'Accounting & Finance', [['BCOM', 'Bachelor of Commerce', 'bachelor', 4, 120]]],
            ['MGT', 'Management Science', [['BBA', 'Bachelor of Business Administration', 'bachelor', 4, 120], ['MBA', 'Master of Business Administration', 'master', 2, 60]]],
            ['ECO', 'Economics', [['BAECO', 'BA Economics', 'bachelor', 4, 120]]],
        ]],
        ['SOE', 'School of Engineering', 'Prof. Faith Njeri', [
            ['CIV', 'Civil & Structural Engineering', [['BSCCE', 'BSc Civil Engineering', 'bachelor', 5, 150]]],
            ['EEE', 'Electrical & Electronic Engineering', [['BSCEE', 'BSc Electrical Engineering', 'bachelor', 5, 150]]],
        ]],
        ['SHS', 'School of Health Sciences', 'Prof. Samuel Odhiambo', [
            ['NUR', 'Nursing', [['BSCN', 'BSc Nursing', 'bachelor', 4, 120]]],
            ['PHE', 'Public Health', [['BSCPH', 'BSc Public Health', 'bachelor', 4, 120]]],
        ]],
        ['SEH', 'School of Education & Humanities', 'Prof. Lucy Achieng', [
            ['EDU', 'Education', [['BEDA', 'Bachelor of Education (Arts)', 'bachelor', 4, 120]]],
            ['JMC', 'Journalism & Mass Communication', [['BAJMC', 'BA Journalism & Mass Communication', 'bachelor', 4, 120]]],
        ]],
    ];
    $programs = [];
    $departmentIds = [];
    foreach ($structure as [$fCode, $fName, $dean, $depts]) {
        q('INSERT INTO faculties (code, name, dean) VALUES (?, ?, ?)', [$fCode, $fName, $dean]);
        $fid = (int) db()->lastInsertId();
        foreach ($depts as [$dCode, $dName, $progs]) {
            q('INSERT INTO departments (faculty_id, code, name) VALUES (?, ?, ?)', [$fid, $dCode, $dName]);
            $did = (int) db()->lastInsertId();
            $departmentIds[] = $did;
            foreach ($progs as [$pCode, $pName, $level, $dur, $credits]) {
                q('INSERT INTO programs (department_id, code, name, level, duration_years, total_credits) VALUES (?, ?, ?, ?, ?, ?)',
                    [$did, $pCode, $pName, $level, $dur, $credits]);
                $programs[$pCode] = ['id' => (int) db()->lastInsertId(), 'duration' => $dur, 'credits' => $credits, 'level' => $level];
            }
        }
    }

    /* ---------- Academic calendar ---------- */
    q("INSERT INTO academic_terms (name, academic_year, start_date, end_date, is_current) VALUES
        ('Semester II', '2025/2026', '2026-01-12', '2026-05-01', 0),
        ('Semester I',  '2026/2027', '2026-09-07', '2026-12-18', 1),
        ('Semester II', '2026/2027', '2027-01-11', '2027-04-30', 0)");
    $rel = fn(int $days) => date('Y-m-d', strtotime("+{$days} days"));
    $events = [
        ['Course evaluation window closes', $rel(5), 'deadline', 'Students complete lecturer and unit evaluations.'],
        ['Senate meeting', $rel(9), 'academic', 'Approval of provisional results and academic policies.'],
        ['Fee payment deadline', $rel(18), 'deadline', 'Outstanding balances must be cleared to sit exams.'],
        ['End-of-semester examinations begin', $rel(30), 'exam', 'Exam timetable published on the portal.'],
        ['Jamhuri Day', date('Y') . '-12-12', 'holiday', 'Public holiday – university closed.'],
        ['Semester I ends', $rel(41), 'academic', null],
        ['Semester II registration opens', $rel(55), 'deadline', 'Online unit registration for Semester II.'],
    ];
    foreach ($events as $ev) {
        q('INSERT INTO calendar_events (title, event_date, event_type, description) VALUES (?, ?, ?, ?)', $ev);
    }

    /* ---------- Students ---------- */
    $first = ['Brian', 'Faith', 'Kevin', 'Mercy', 'Dennis', 'Joy', 'Collins', 'Sharon', 'Victor', 'Esther', 'Ian', 'Cynthia', 'Allan', 'Diana',
              'Moses', 'Naomi', 'Emmanuel', 'Lilian', 'Felix', 'Ruth', 'Samuel', 'Tracy', 'Daniel', 'Purity', 'George', 'Winnie', 'Hassan',
              'Amina', 'Omar', 'Zawadi', 'Kipchoge', 'Chebet', 'Wafula', 'Akinyi', 'Mutua', 'Nyambura', 'Abdi', 'Halima', 'Liam', 'Priya'];
    $last  = ['Kamau', 'Otieno', 'Wanjiku', 'Mwangi', 'Ochieng', 'Njoroge', 'Kiprono', 'Mutiso', 'Omondi', 'Wambui', 'Kariuki', 'Achieng',
              'Barasa', 'Cheruiyot', 'Kilonzo', 'Nyaga', 'Mohamed', 'Ali', 'Korir', 'Wekesa', 'Muthoni', 'Odhiambo', 'Simiyu', 'Rotich',
              'Gitau', 'Atieno', 'Maina', 'Juma', 'Patel', 'Langat'];
    $relationships = ['Mother', 'Father', 'Guardian', 'Uncle', 'Aunt', 'Sibling'];
    $programWeights = ['BSCCS' => 14, 'BSCIT' => 12, 'DIT' => 6, 'BSCDS' => 7, 'MSCCS' => 2, 'BCOM' => 12, 'BBA' => 10, 'MBA' => 3,
                       'BAECO' => 5, 'BSCCE' => 6, 'BSCEE' => 5, 'BSCN' => 9, 'BSCPH' => 5, 'BEDA' => 8, 'BAJMC' => 5];
    $pick = function (array $weights) {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $k => $w) {
            if (($r -= $w) <= 0) return $k;
        }
        return array_key_first($weights);
    };

    $seq = 10001;
    $insertStudent = db()->prepare('INSERT INTO students
        (student_no, user_id, first_name, last_name, gender, date_of_birth, national_id, email, phone, address, program_id, campus_id,
         year_of_study, enrollment_status, academic_standing, admission_date, expected_graduation, current_gpa, credits_completed, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insertGuardian = db()->prepare('INSERT INTO guardians (student_id, full_name, relationship, phone, email, is_emergency_contact) VALUES (?, ?, ?, ?, ?, ?)');
    $usedEmails = [];

    // The featured demo student from the prototype brief, linked to the student login.
    $insertStudent->execute(['STU-2026-10482', $userIds['student'], 'Jordan', 'Lee', 'other', '2006-03-14', '38291044', 'student@northstar.ac.ke',
        '+254 712 345 678', 'Westlands, Nairobi', $programs['BSCCS']['id'], $campusIds[0], 2, 'active', 'good', '2025-09-08', '2029-07-31', 3.60, 48, $userIds['super_admin']]);
    $jordanId = (int) db()->lastInsertId();
    $insertGuardian->execute([$jordanId, 'Patricia Lee', 'Mother', '+254 722 111 222', 'patricia.lee@example.com', 1]);

    for ($i = 0; $i < 280; $i++) {
        $fn = $first[mt_rand(0, count($first) - 1)];
        $ln = $last[mt_rand(0, count($last) - 1)];
        $pCode = $pick($programWeights);
        $prog = $programs[$pCode];
        $status = $pick(['active' => 78, 'graduated' => 9, 'deferred' => 5, 'suspended' => 3, 'withdrawn' => 5]);

        if ($status === 'graduated') {
            $admYear = 2026 - $prog['duration'] - mt_rand(0, 2);
            $year = $prog['duration'];
            $credits = $prog['credits'];
        } else {
            $year = mt_rand(1, $prog['duration']);
            $admYear = 2026 - $year + 1;
            $credits = min($prog['credits'], max(0, ($year - 1) * (int) ($prog['credits'] / $prog['duration']) + mt_rand(-6, 12)));
        }
        $admDate = sprintf('%d-%s', $admYear, ['01-11', '05-04', '09-07'][mt_rand(0, 2)]);
        $gpa = round(min(4.0, max(1.4, 2.95 + (mt_rand(-100, 100) / 100) * 0.95 + (mt_rand(0, 40) / 100) - 0.2)), 2);
        if ($year === 1 && mt_rand(0, 2) === 0 && $status === 'active') {
            $gpa = null; // first-semester students without results yet
        }
        $standing = $gpa === null ? 'good' : ($gpa >= 3.7 ? 'dean_list' : ($gpa < 2.0 ? 'probation' : ($gpa < 2.3 ? 'warning' : 'good')));
        $email = strtolower($fn . '.' . $ln) . '@students.northstar.ac.ke';
        if (isset($usedEmails[$email])) {
            $email = strtolower($fn . '.' . $ln . $seq) . '@students.northstar.ac.ke';
        }
        $usedEmails[$email] = true;
        $gender = in_array($fn, ['Faith', 'Mercy', 'Joy', 'Sharon', 'Esther', 'Cynthia', 'Diana', 'Naomi', 'Lilian', 'Ruth', 'Tracy', 'Purity',
                                  'Winnie', 'Amina', 'Zawadi', 'Chebet', 'Akinyi', 'Nyambura', 'Halima', 'Priya'], true) ? 'female' : 'male';
        $dob = sprintf('%d-%02d-%02d', $admYear - mt_rand(18, 22), mt_rand(1, 12), mt_rand(1, 28));
        $insertStudent->execute([
            sprintf('STU-%d-%05d', $admYear, $seq++), null, $fn, $ln, $gender, $dob, (string) mt_rand(20000000, 41999999), $email,
            sprintf('+254 7%02d %03d %03d', mt_rand(0, 99), mt_rand(0, 999), mt_rand(0, 999)),
            ['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Eldoret', 'Thika', 'Machakos', 'Nyeri'][mt_rand(0, 7)],
            $prog['id'], $campusIds[$pick([0 => 70, 1 => 18, 2 => 12])], $year, $status, $standing, $admDate,
            ($admYear + $prog['duration']) . '-07-31', $gpa, $credits, $userIds['registrar'],
        ]);
        $sid = (int) db()->lastInsertId();
        if (mt_rand(0, 9) > 0) {
            $insertGuardian->execute([$sid, $first[mt_rand(0, count($first) - 1)] . ' ' . $ln, $relationships[mt_rand(0, 5)],
                sprintf('+254 7%02d %03d %03d', mt_rand(0, 99), mt_rand(0, 999), mt_rand(0, 999)), null, 1]);
        }
    }

    /* ---------- Staff ---------- */
    $designations = [
        'lecturer'      => ['Lecturer', 'Senior Lecturer', 'Assistant Lecturer', 'Associate Professor', 'Professor'],
        'administrator' => ['Registry Officer', 'Finance Officer', 'Admissions Officer', 'ICT Officer', 'HR Officer', 'Examinations Officer'],
        'support'       => ['Lab Technician', 'Librarian', 'Security Supervisor', 'Driver', 'Facilities Officer'],
    ];
    $staffNo = 501;
    q('INSERT INTO staff (staff_no, user_id, first_name, last_name, email, department_id, category, designation, status, hire_date)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', ['NSU-S-0500', $userIds['lecturer'], 'Morgan', 'Chen', 'lecturer@northstar.ac.ke',
        $departmentIds[0], 'lecturer', 'Senior Lecturer', 'active', '2018-01-15']);
    foreach (['lecturer' => 46, 'administrator' => 18, 'support' => 12] as $cat => $count) {
        for ($i = 0; $i < $count; $i++) {
            $fn = $first[mt_rand(0, count($first) - 1)];
            $ln = $last[mt_rand(0, count($last) - 1)];
            q('INSERT INTO staff (staff_no, first_name, last_name, email, phone, department_id, category, designation, status, hire_date)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                sprintf('NSU-S-%04d', $staffNo), $fn, $ln, strtolower($fn . '.' . $ln . $staffNo) . '@northstar.ac.ke',
                sprintf('+254 7%02d %03d %03d', mt_rand(0, 99), mt_rand(0, 999), mt_rand(0, 999)),
                $cat === 'lecturer' ? $departmentIds[mt_rand(0, count($departmentIds) - 1)] : null,
                $cat, $designations[$cat][mt_rand(0, count($designations[$cat]) - 1)],
                mt_rand(0, 14) === 0 ? 'on_leave' : 'active', sprintf('%d-%02d-01', mt_rand(2008, 2026), mt_rand(1, 12)),
            ]);
            $staffNo++;
        }
    }

    /* ---------- Notifications ---------- */
    notify($userIds['super_admin'], 'Welcome to UniSphere', 'Phase 1 & 2 are live: authentication, RBAC, student records and analytics.', 'dashboard.php');
    notify($userIds['super_admin'], 'Review security settings', 'Change the demo passwords and turn off demo credentials before going live.', 'settings.php');
    notify($userIds['registrar'], 'Students on probation', 'Several students are on academic probation this semester.', 'students.php?standing=probation');
    notify($userIds['student'], 'Welcome to your student portal', 'View your profile, documents and upcoming academic dates here.', 'dashboard.php');

    return [
        'password'  => DEMO_PASSWORD,
        'accounts' => array_map(fn($a) => ['name' => $a[0], 'email' => $a[1], 'role' => $roles[$a[2]][0]], $accounts),
    ];
}

/**
 * Demo content for the student portal (migration 002): a two-week daily programme,
 * announcements and a few self-registrations waiting for approval.
 */
function seed_portal_demo(): void
{
    $prog = q('SELECT code, id FROM programs')->fetchAll(PDO::FETCH_KEY_PAIR);
    $fac  = q('SELECT code, id FROM faculties')->fetchAll(PDO::FETCH_KEY_PAIR);
    $admin = (int) q_val("SELECT id FROM users WHERE email = 'admin@northstar.ac.ke'");
    $registrar = (int) q_val("SELECT id FROM users WHERE email = 'registrar@northstar.ac.ke'") ?: $admin;

    $ins = db()->prepare('INSERT INTO schedule_items (series_id, title, description, item_date, start_time, end_time, venue, category, audience, faculty_id, program_id, year_of_study, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $monday = strtotime('monday this week');
    // [weekday 1-5 or 0 for every weekday, title, desc, start, end, venue, category, audience, faculty, program, year]
    $routine = [
        [0, 'Morning assembly', 'Daily notices and devotion.', '07:30', '08:00', 'Main Hall', 'assembly', 'all', null, null, null],
        [0, 'Lunch break', null, '13:00', '14:00', 'Cafeteria', 'other', 'all', null, null, null],
        [1, 'CSC 201 Data Structures – Lecture', 'Dr. Morgan Chen', '08:00', '10:00', 'LT 3', 'class', 'program', null, 'BSCCS', 2],
        [1, 'CSC 203 Discrete Mathematics', null, '10:30', '12:30', 'LT 1', 'class', 'program', null, 'BSCCS', 2],
        [2, 'CSC 205 Database Systems – Lab', 'Bring your laptop.', '14:00', '17:00', 'Computer Lab B', 'class', 'program', null, 'BSCCS', 2],
        [2, 'First-year orientation clinic', 'Help desk for registration and portal questions.', '10:00', '12:00', 'Student Centre', 'event', 'year', null, null, 1],
        [3, 'CSC 201 Data Structures – Tutorial', null, '08:00', '09:30', 'Room 204', 'class', 'program', null, 'BSCCS', 2],
        [3, 'School of Computing research seminar', 'Guest talk: AI in African agriculture.', '16:00', '17:30', 'SCI Auditorium', 'event', 'faculty', 'SCI', null, null],
        [4, 'CSC 207 Web Development', null, '09:00', '12:00', 'Computer Lab A', 'class', 'program', null, 'BSCCS', 2],
        [4, 'Departmental meeting', 'Staff only – students free study.', '14:00', '15:00', 'Board Room', 'meeting', 'faculty', 'SCI', null, null],
        [5, 'CAT 1: CSC 203 Discrete Mathematics', 'Continuous assessment test. Carry your student ID.', '09:00', '10:30', 'Exam Hall 2', 'exam', 'program', null, 'BSCCS', 2],
        [5, 'Inter-school sports', 'Football, netball and athletics.', '15:00', '18:00', 'Sports Ground', 'sports', 'all', null, null, null],
        [1, 'BCOM 2 Financial Accounting II', null, '08:00', '11:00', 'LT 5', 'class', 'program', null, 'BCOM', 2],
        [3, 'BSc Nursing clinical rotation', 'Report to Ward 4.', '07:00', '13:00', 'Teaching Hospital', 'class', 'program', null, 'BSCN', 3],
    ];
    foreach ([0, 7] as $weekOffset) {
        foreach ($routine as [$day, $title, $desc, $start, $end, $venue, $cat, $aud, $f, $p, $y]) {
            $days = $day === 0 ? [1, 2, 3, 4, 5] : [$day];
            $series = bin2hex(random_bytes(8));
            foreach ($days as $d) {
                $date = date('Y-m-d', strtotime('+' . ($weekOffset + $d - 1) . ' days', $monday));
                $ins->execute([$day === 0 ? $series : null, $title, $desc, $date, $start, $end, $venue, $cat, $aud,
                    $f ? $fac[$f] : null, $p ? $prog[$p] : null, $y, $registrar]);
            }
        }
    }
    $saturday = date('Y-m-d', strtotime('+5 days', $monday));
    $ins->execute([null, 'Career & internship fair', '40+ employers on campus. Dress formally and bring CVs.', $saturday, '09:00', '15:00', 'Main Hall', 'event', 'all', null, null, null, $admin]);

    $ann = db()->prepare('INSERT INTO announcements (title, body, audience, is_pinned, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ann->execute(['End-of-semester examination timetable published', "The final examination timetable is now available under Daily programme. Check your exam venues early and report clashes to the Examinations Office by Friday.", 'students', 1, null, $registrar, date('Y-m-d H:i:s', strtotime('-1 day'))]);
    $ann->execute(['Fee payment deadline reminder', "All outstanding fee balances must be cleared before examinations begin. Students with balances will not receive exam cards.", 'students', 0, date('Y-m-d', strtotime('+20 days')), $admin, date('Y-m-d H:i:s', strtotime('-2 days'))]);
    $ann->execute(['Library extended hours', "The main library will open 7:00 am – 11:00 pm, Monday to Saturday, until the end of exams.", 'everyone', 0, null, $admin, date('Y-m-d H:i:s', strtotime('-3 days'))]);
    $ann->execute(['Senate meeting – results submission', "Lecturers: submit all CAT and coursework marks to departmental heads ahead of the Senate meeting.", 'staff', 0, null, $registrar, date('Y-m-d H:i:s', strtotime('-4 hours'))]);

    // Self-registrations awaiting approval
    $studentRole = (int) q_val("SELECT id FROM roles WHERE slug = 'student'");
    $hash = hash_password('Applicant@2026');
    $pending = [['Wanjiru', 'Kimani', 'female', 'BSCIT'], ['Otieno', 'Ouma', 'male', 'BCOM'], ['Aisha', 'Hassan', 'female', 'BSCN']];
    foreach ($pending as $i => [$fn, $ln, $g, $pc]) {
        $email = strtolower("{$fn}.{$ln}") . '@gmail.com';
        if (q_val('SELECT id FROM users WHERE email = ?', [$email])) continue;
        q("INSERT INTO users (name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, 'pending')", ["$fn $ln", $email, '+254 71' . $i . ' 555 01' . $i, $hash]);
        $uid = (int) db()->lastInsertId();
        q('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$uid, $studentRole]);
        q("INSERT INTO students (student_no, user_id, first_name, last_name, gender, date_of_birth, national_id, email, phone, program_id, campus_id,
                                 year_of_study, enrollment_status, admission_date, registration_source, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT MIN(id) FROM campuses), 1, 'pending', CURDATE(), 'self', ?)",
            [applicant_no(), $uid, $fn, $ln, $g, '2007-0' . ($i + 3) . '-1' . $i, (string) (40100200 + $i), $email, '+254 71' . $i . ' 555 01' . $i, $prog[$pc],
             date('Y-m-d H:i:s', strtotime('-' . (($i + 1) * 5) . ' hours'))]);
    }
}
