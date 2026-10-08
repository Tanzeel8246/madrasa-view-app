<?php
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle AJAX read/unread toggle for smart alerts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_smart_alert_read') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access: Please log in again.']);
        exit;
    }
    $alert_id = trim($_POST['alert_id'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    if (empty($alert_id) || !in_array($status, ['read', 'unread'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
        exit;
    }
    
    if ($status === 'read') {
        $stmt = $pdo->prepare("INSERT INTO smart_notification_reads (alert_id, user_id, is_deleted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE is_deleted=0");
        $stmt->execute([$alert_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE smart_notification_reads SET is_deleted=1 WHERE alert_id=? AND user_id=?");
        $stmt->execute([$alert_id, $user_id]);
    }
    echo json_encode(['status' => 'success', 'message' => 'Status updated']);
    exit;
}

include 'header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location='login.php';</script>";
    exit;
}

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$is_masjid = ($sys === 'Masjid');
$today = date('Y-m-d');

// Date Filter Logic
$date_filter = $_GET['date_filter'] ?? 'today';
$window_start = $today;
$window_end = $today;

if ($date_filter === 'this_week') {
    $window_start = date('Y-m-d', strtotime('monday this week'));
    $window_end = date('Y-m-d', strtotime('sunday this week'));
} elseif ($date_filter === 'this_month') {
    $window_start = date('Y-m-01');
    $window_end = date('Y-m-t');
} elseif ($date_filter === 'all') {
    $window_start = '2000-01-01'; // A long time ago
    $window_end = '2099-12-31';
} elseif ($date_filter === 'custom' && !empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $window_start = $_GET['start_date'];
    $window_end = $_GET['end_date'];
}

// Fetch Read Notifications for the current user
$user_id = $_SESSION['user_id'];
$read_alerts_stmt = $pdo->prepare("SELECT alert_id FROM smart_notification_reads WHERE user_id = ? AND is_deleted = 0");
$read_alerts_stmt->execute([$user_id]);
$read_alerts = $read_alerts_stmt->fetchAll(PDO::FETCH_COLUMN);

// Status Filter Logic
$status_filter = $_GET['status_filter'] ?? 'all'; // all, read, unread

$std_tbl = $is_masjid ? 'maktab_students' : 'students';
$hifz_tbl = 'daily_hifz';
$att_tbl = $is_masjid ? 'period_attendance' : 'attendance';
$classes_tbl = $is_masjid ? 'maktab_classes' : 'classes';

$academic_alerts = [];
$attendance_alerts = [];
$finance_alerts = [];
$admin_alerts = [];

$cur_tid = (int)get_current_tenant_id();

// ==========================================
// 1. تعلیمی و حفظ الرٹس (Academic & Hifz)
// ==========================================
try {
    // 1a. حالیہ ناغہ جات (پچھلے 7 دن کے دوران سبق، سبقی، منزل)
    $todayRecordsStmt = $pdo->prepare("
        SELECT h.*, s.name as student_name, s.class as class_name, s.contact_number, s.roll_number
        FROM $hifz_tbl h
        JOIN $std_tbl s ON h.student_id = s.id
        WHERE h.date >= ? AND h.date <= ? AND h.tenant_id = ? AND s.tenant_id = ? AND (h.is_deleted = 0 OR h.is_deleted IS NULL)
          AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
          AND (s.status = 'Active' OR s.status IS NULL OR s.status = '')
        ORDER BY h.date DESC, h.id DESC LIMIT 300
    ");
    $todayRecordsStmt->execute([$window_start, $window_end, $cur_tid, $cur_tid]);
    $todayRecords = $todayRecordsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($todayRecords as $r) {
        $stdName = $r['student_name'];
        $cls = $r['class_name'] ?? '';
        $phone = $r['contact_number'] ?? '';
        $sGrade = trim($r['sabaq_status'] ?? $r['sabaq_grade'] ?? '');
        $sbGrade = trim($r['sabqi_status'] ?? $r['sabqi_grade'] ?? '');
        $mGrade = trim($r['manzil_status'] ?? $r['manzil_grade'] ?? '');
        $rDate = $r['date'];
        $dateLabel = ($rDate === $today) ? 'آج' : $rDate;

        $sabaqActionUrl = ($is_masjid ? 'maktab_hifz.php' : 'hifz.php') . (!empty($cls) ? ('?class=' . urlencode($cls)) : '');
        $viewUrl = "parent_report.php?std_id=" . $r['student_id'];

        $isSabaqMissed = ($sGrade === 'نہیں سنایا' || $sGrade === 'ناغہ' || $sGrade === 'غیر حاضر' || (isset($r['sabaq_parah']) && $r['sabaq_parah'] === 'نہیں سنایا'));
        if ($isSabaqMissed) {
            $academic_alerts[] = [
                'type' => 'daily_sabaq',
                'severity' => 'warning',
                'title' => ($rDate === $today) ? 'آج سبق کا ناغہ' : "سبق کا ناغہ ($rDate)",
                'description' => "$stdName ($cls) نے $dateLabel کا نیا سبق نہیں سنایا۔",
                'student_id' => $r['student_id'],
                'student_name' => $stdName,
                'class_name' => $cls,
                'contact_number' => $phone,
                'date' => $rDate,
                'view_url' => $viewUrl,
                'action_url' => $sabaqActionUrl
            ];
        }

        $isSabqiMissed = ($sbGrade === 'نہیں سنائی' || $sbGrade === 'ناغہ' || $sbGrade === 'غیر حاضر');
        if ($isSabqiMissed) {
            $academic_alerts[] = [
                'type' => 'daily_sabqi',
                'severity' => 'warning',
                'title' => ($rDate === $today) ? 'آج سبقی کا ناغہ' : "سبقی کا ناغہ ($rDate)",
                'description' => "$stdName ($cls) نے $dateLabel سبقی (گزشتہ اسباق) نہیں سنائی۔",
                'student_id' => $r['student_id'],
                'student_name' => $stdName,
                'class_name' => $cls,
                'contact_number' => $phone,
                'date' => $rDate,
                'view_url' => $viewUrl,
                'action_url' => $sabaqActionUrl
            ];
        }

        $isManzilMissed = ($mGrade === 'نہیں سنائی' || $mGrade === 'ناغہ' || $mGrade === 'غیر حاضر');
        if ($isManzilMissed) {
            $academic_alerts[] = [
                'type' => 'daily_manzil',
                'severity' => 'warning',
                'title' => ($rDate === $today) ? 'آج منزل کا ناغہ' : "منزل کا ناغہ ($rDate)",
                'description' => "$stdName ($cls) نے $dateLabel منزل نہیں سنائی۔",
                'student_id' => $r['student_id'],
                'student_name' => $stdName,
                'class_name' => $cls,
                'contact_number' => $phone,
                'date' => $rDate,
                'view_url' => $viewUrl,
                'action_url' => $sabaqActionUrl
            ];
        }
    }

    // 1b. مسلسل 3 دن سبق / سبقی / منزل کا ناغہ
    $actStdStmt = $pdo->prepare("
        SELECT id, name, class, contact_number 
        FROM $std_tbl 
        WHERE tenant_id = ? AND (status = 'Active' OR status IS NULL OR status = '') 
          AND (is_deleted = 0 OR is_deleted IS NULL)
        LIMIT 200
    ");
    $actStdStmt->execute([$cur_tid]);
    $activeStudents = $actStdStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($activeStudents as $astd) {
        $sid = $astd['id'];
        $recentStmt = $pdo->prepare("
            SELECT sabaq_grade, sabaq_status, sabqi_grade, sabqi_status, manzil_grade, manzil_status, date 
            FROM $hifz_tbl 
            WHERE student_id = ? AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY date DESC, id DESC LIMIT 3
        ");
        $recentStmt->execute([$sid, $cur_tid]);
        $recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($recentRows) >= 3) {
            $allThreeSabaq = true;
            $allThreeSabqi = true;
            $allThreeManzil = true;
            foreach ($recentRows as $rr) {
                $sg = trim($rr['sabaq_status'] ?? $rr['sabaq_grade'] ?? '');
                $sbg = trim($rr['sabqi_status'] ?? $rr['sabqi_grade'] ?? '');
                $mg = trim($rr['manzil_status'] ?? $rr['manzil_grade'] ?? '');
                if ($sg !== 'نہیں سنایا' && $sg !== 'ناغہ' && $sg !== 'غیر حاضر') $allThreeSabaq = false;
                if ($sbg !== 'نہیں سنائی' && $sbg !== 'ناغہ' && $sbg !== 'غیر حاضر') $allThreeSabqi = false;
                if ($mg !== 'نہیں سنائی' && $mg !== 'ناغہ' && $mg !== 'غیر حاضر') $allThreeManzil = false;
            }

            $astdCls = $astd['class'] ?? '';
            $astdActionUrl = ($is_masjid ? 'maktab_hifz.php' : 'hifz.php') . (!empty($astdCls) ? ('?class=' . urlencode($astdCls)) : '');
            $chronicViewUrl = "parent_report.php?std_id=" . $sid;

            if ($allThreeSabaq) {
                $academic_alerts[] = [
                    'type' => 'chronic_sabaq',
                    'severity' => 'critical',
                    'title' => 'مسلسل 3 دن سبق کا ناغہ! 🚨',
                    'description' => "{$astd['name']} ({$astd['class']}) نے پچھلے 3 اندراجات میں مسلسل سبق نہیں سنایا۔ استاد سے استفسار فرمائیں۔",
                    'student_id' => $sid,
                    'student_name' => $astd['name'],
                    'class_name' => $astd['class'],
                    'contact_number' => $astd['contact_number'],
                    'date' => $recentRows[0]['date'],
                    'view_url' => $chronicViewUrl,
                    'action_url' => $astdActionUrl
                ];
            }

            if ($allThreeSabqi) {
                $academic_alerts[] = [
                    'type' => 'chronic_sabqi',
                    'severity' => 'critical',
                    'title' => 'مسلسل 3 دن سبقی کا ناغہ! ⚠️',
                    'description' => "{$astd['name']} ({$astd['class']}) نے پچھلے 3 اندراجات میں مسلسل سبقی نہیں سنائی۔ پچھلے اسباق کچے ہونے کا اندیشہ ہے۔",
                    'student_id' => $sid,
                    'student_name' => $astd['name'],
                    'class_name' => $astd['class'],
                    'contact_number' => $astd['contact_number'],
                    'date' => $recentRows[0]['date'],
                    'view_url' => $chronicViewUrl,
                    'action_url' => $astdActionUrl
                ];
            }

            if ($allThreeManzil) {
                $academic_alerts[] = [
                    'type' => 'chronic_manzil',
                    'severity' => 'critical',
                    'title' => 'مسلسل 3 دن منزل کا ناغہ! ⚠️',
                    'description' => "{$astd['name']} ({$astd['class']}) نے پچھلے 3 اندراجات میں منزل نہیں سنائی۔ حفظ کچا ہونے کا خدشہ ہے۔",
                    'student_id' => $sid,
                    'student_name' => $astd['name'],
                    'class_name' => $astd['class'],
                    'contact_number' => $astd['contact_number'],
                    'date' => $recentRows[0]['date'],
                    'view_url' => $chronicViewUrl,
                    'action_url' => $astdActionUrl
                ];
            }
        }
    }
} catch (Exception $e) {}

// ==========================================
// 2. حاضری و عملہ الرٹس (Attendance Alerts)
// ==========================================
try {
    // 2a. حالیہ غیر حاضر طلبہ (پچھلے 7 دن)
    if ($is_masjid) {
        $absentStmt = $pdo->prepare("
            SELECT a.student_id, a.date, s.name as student_name, s.class as class_name, s.contact_number
            FROM period_attendance a
            JOIN maktab_students s ON a.student_id = s.id
            WHERE a.date >= ? AND a.tenant_id = ? AND s.tenant_id = ? AND (a.status IN ('A', 'Absent', 'غیر حاضر'))
              AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
              AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
            ORDER BY a.date DESC, a.id DESC LIMIT 100
        ");
        $absentStmt->execute([$window_start, $cur_tid, $cur_tid]);
    } else {
        $absentStmt = $pdo->prepare("
            SELECT a.student_id, a.date, s.name as student_name, s.class as class_name, s.contact_number
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            WHERE a.date >= ? AND a.tenant_id = ? AND s.tenant_id = ?
              AND (a.status_1 IN ('A', 'Absent', 'غیر حاضر') OR a.status_2 IN ('A', 'Absent', 'غیر حاضر') OR a.status_3 IN ('A', 'Absent', 'غیر حاضر') OR a.status_4 IN ('A', 'Absent', 'غیر حاضر') OR a.status IN ('A', 'Absent', 'غیر حاضر'))
              AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
              AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
              AND (s.status = 'Active' OR s.status = '' OR s.status IS NULL)
            ORDER BY a.date DESC, a.id DESC LIMIT 100
        ");
        $absentStmt->execute([$window_start, $cur_tid, $cur_tid]);
    }
    $absentees = $absentStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($absentees as $ab) {
        $aDate = $ab['date'];
        $attCls = $ab['class_name'] ?? '';
        $attActionUrl = ($is_masjid ? 'maktab_attendance.php' : 'attendance.php') . (!empty($attCls) ? ('?class=' . urlencode($attCls) . '&date=' . urlencode($aDate)) : ('?date=' . urlencode($aDate)));
        $attendance_alerts[] = [
            'type' => 'daily_absent',
            'severity' => 'warning',
            'title' => ($aDate === $today) ? 'آج کی غیر حاضری' : "غیر حاضری ($aDate)",
            'description' => "{$ab['student_name']} ({$ab['class_name']}) تاریخ $aDate کو جامعہ سے غیر حاضر رہا۔",
            'student_id' => $ab['student_id'],
            'student_name' => $ab['student_name'],
            'class_name' => $ab['class_name'],
            'contact_number' => $ab['contact_number'],
            'date' => $aDate,
            'view_url' => "parent_report.php?std_id=" . $ab['student_id'],
            'action_url' => $attActionUrl
        ];
    }

    // 2b. مسلسل 3 دن سے غیر حاضر طلبہ (Chronic Absenteeism - Critical 🚨)
    $threeDaysAgo = date('Y-m-d', strtotime('-3 days'));
    if (!$is_masjid) {
        $chronicAttStmt = $pdo->prepare("
            SELECT s.id as student_id, s.name as student_name, s.class as class_name, s.contact_number, COUNT(DISTINCT sub.date) as absent_days
            FROM students s
            JOIN (
                SELECT student_id, date FROM attendance WHERE date >= ? AND tenant_id = ? AND (status_1 IN ('A','Absent') OR status_2 IN ('A','Absent')) AND (is_deleted=0 OR is_deleted IS NULL)
                UNION
                SELECT student_id, date FROM period_attendance WHERE date >= ? AND tenant_id = ? AND status IN ('A','Absent') AND (is_deleted=0 OR is_deleted IS NULL)
            ) sub ON s.id = sub.student_id
            WHERE s.tenant_id = ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
            GROUP BY s.id
            HAVING absent_days >= 3
        ");
        $chronicAttStmt->execute([$threeDaysAgo, $cur_tid, $threeDaysAgo, $cur_tid, $cur_tid]);
        $chronicAbsentees = $chronicAttStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chronicAbsentees as $ca) {
            $caCls = $ca['class_name'] ?? '';
            $caActionUrl = 'attendance.php' . (!empty($caCls) ? ('?class=' . urlencode($caCls)) : '');
            $attendance_alerts[] = [
                'type' => 'chronic_absent',
                'severity' => 'critical',
                'title' => 'مسلسل 3 دن سے غیر حاضر! 🚨',
                'description' => "طالب علم {$ca['student_name']} ({$ca['class_name']}) پچھلے 3 دنوں سے مسلسل غیر حاضر ہے۔ سرپرست سے فوری رابطہ کریں۔",
                'student_id' => $ca['student_id'],
                'student_name' => $ca['student_name'],
                'class_name' => $ca['class_name'],
                'contact_number' => $ca['contact_number'],
                'date' => $today,
                'view_url' => "parent_report.php?std_id=" . $ca['student_id'],
                'action_url' => $caActionUrl
            ];
        }
    }

    // 2c. غیر حاضر اساتذہ (پچھلے 7 دن)
    $teacherAbsent = $pdo->prepare("
        SELECT t.name, t.phone, at.date 
        FROM attendance_teachers at
        JOIN teachers t ON at.teacher_id = t.id
        WHERE at.date >= ? AND at.tenant_id = ? AND t.tenant_id = ? AND at.status IN ('A', 'Absent', 'غیر حاضر')
          AND (at.is_deleted = 0 OR at.is_deleted IS NULL)
        ORDER BY at.date DESC LIMIT 20
    ");
    $teacherAbsent->execute([$window_start, $cur_tid, $cur_tid]);
    $tabRows = $teacherAbsent->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tabRows as $tab) {
        $tDate = $tab['date'];
        $teaActionUrl = ($is_masjid ? 'maktab_teacher_attendance.php' : 'teachers_attendance.php') . (!empty($tDate) ? ('?date=' . urlencode($tDate)) : '');
        $attendance_alerts[] = [
            'type' => 'teacher_absent',
            'severity' => 'warning',
            'title' => ($tDate === $today) ? 'استاد آج غیر حاضر' : "استاد غیر حاضری ($tDate)",
            'description' => "محترم {$tab['name']} صاحب تاریخ $tDate کو جامعہ سے غیر حاضر رہے۔",
            'student_id' => null,
            'student_name' => $tab['name'],
            'class_name' => 'استاد',
            'contact_number' => $tab['phone'],
            'date' => $tDate,
            'view_url' => null,
            'action_url' => $teaActionUrl
        ];
    }
} catch (Exception $e) {}

// ==========================================
// 3. فیس و مالیات الرٹس (Finance Alerts)
// ==========================================
try {
    // 3a. آن لائن فیس کی زیر التواء درخواستیں
    $pendingFeesStmt = $pdo->prepare("
        SELECT f.*, s.name as student_name, s.class as class_name, s.contact_number
        FROM fee_submissions f
        LEFT JOIN students s ON f.student_id = s.id
        WHERE f.tenant_id = ? AND f.status = 'Pending' AND (f.is_deleted = 0 OR f.is_deleted IS NULL)
        ORDER BY f.id DESC LIMIT 20
    ");
    $pendingFeesStmt->execute([$cur_tid]);
    $pendingFees = $pendingFeesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingFees as $pf) {
        $finance_alerts[] = [
            'type' => 'pending_fee',
            'severity' => 'info',
            'title' => 'آن لائن فیس تصدیق کی منتظر',
            'description' => "طالب علم {$pf['student_name']} کی فیس مبلغ Rs. " . number_format($pf['amount']) . " منظوری کی منتظر ہے۔",
            'student_id' => $pf['student_id'],
            'student_name' => $pf['student_name'],
            'class_name' => $pf['class_name'],
            'contact_number' => $pf['contact_number'],
            'date' => $pf['submission_date'] ?? $today,
            'view_url' => !empty($pf['student_id']) ? ("parent_report.php?std_id=" . $pf['student_id']) : null,
            'action_url' => "admin_requests.php"
        ];
    }

    // 3b. رواں ماہ کے فیس ڈیفالٹرز (Fee Defaulters)
    $currentMonth = date('Y-m');
    $feeDefaultersStmt = $pdo->prepare("
        SELECT s.id, s.name, s.class, s.contact_number, s.monthly_fee
        FROM $std_tbl s
        WHERE s.tenant_id = ?
          AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
          AND (s.status = 'Active' OR s.status IS NULL OR s.status = '')
          AND s.monthly_fee > 0
          AND s.id NOT IN (
              SELECT student_id FROM fee_submissions 
              WHERE tenant_id = ? AND (date LIKE ? OR submission_date LIKE ?) AND (LOWER(status) IN ('approved', 'paid', 'ادا شدہ') OR status IS NULL)
          )
        ORDER BY s.class ASC, s.name ASC LIMIT 25
    ");
    $feeDefaultersStmt->execute([$cur_tid, $cur_tid, "$currentMonth%", "$currentMonth%"]);
    $feeDefaulters = $feeDefaultersStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($feeDefaulters as $fd) {
        $finance_alerts[] = [
            'type' => 'fee_defaulter',
            'severity' => 'warning',
            'title' => 'ماہانہ فیس بقایا جات',
            'description' => "{$fd['name']} ({$fd['class']}) کی رواں ماہ کی فیس مبلغ Rs. " . number_format($fd['monthly_fee']) . " تاحال جمع نہیں ہوئی۔",
            'student_id' => $fd['id'],
            'student_name' => $fd['name'],
            'class_name' => $fd['class'],
            'contact_number' => $fd['contact_number'],
            'date' => $today,
            'view_url' => "parent_report.php?std_id=" . $fd['id'],
            'action_url' => "fee_defaulters.php?month=" . $currentMonth
        ];
    }
} catch (Exception $e) {}

// ==========================================
// 4. انتظامیہ الرٹس (Admin Alerts)
// ==========================================
try {
    // 4a. رخصتیں
    $pendingLeavesStmt = $pdo->prepare("
        SELECT l.*, s.name as student_name, s.class as class_name, s.contact_number
        FROM leave_applications l
        LEFT JOIN students s ON l.student_id = s.id
        WHERE l.tenant_id = ? AND l.status = 'Pending' AND (l.is_deleted = 0 OR l.is_deleted IS NULL)
        ORDER BY l.id DESC LIMIT 20
    ");
    $pendingLeavesStmt->execute([$cur_tid]);
    $pendingLeaves = $pendingLeavesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingLeaves as $pl) {
        $admin_alerts[] = [
            'type' => 'pending_leave',
            'severity' => 'warning',
            'title' => 'درخواست برائے رخصت زیر التواء',
            'description' => "طالب علم {$pl['student_name']} کی رخصت کی درخواست ({$pl['reason']}) منظوری کی منتظر ہے۔",
            'student_id' => $pl['student_id'],
            'student_name' => $pl['student_name'],
            'class_name' => $pl['class_name'],
            'contact_number' => $pl['contact_number'],
            'date' => $pl['date_from'] ?? $today,
            'view_url' => !empty($pl['student_id']) ? ("parent_report.php?std_id=" . $pl['student_id']) : null,
            'action_url' => "admin_requests.php"
        ];
    }

    // 4b. شکایات
    $pendingComplaintsStmt = $pdo->prepare("
        SELECT c.*, s.name as student_name, s.class as class_name, s.contact_number
        FROM parent_complaints c
        LEFT JOIN students s ON c.student_id = s.id
        WHERE c.tenant_id = ? AND c.status = 'Pending' AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
        ORDER BY c.id DESC LIMIT 20
    ");
    $pendingComplaintsStmt->execute([$cur_tid]);
    $pendingComplaints = $pendingComplaintsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingComplaints as $pc) {
        $admin_alerts[] = [
            'type' => 'pending_complaint',
            'severity' => 'critical',
            'title' => 'والدین کی نئی شکایت! ⚠️',
            'description' => "طالب علم {$pc['student_name']} کے سرپرست کی شکایت: \"{$pc['description']}\"",
            'student_id' => $pc['student_id'],
            'student_name' => $pc['student_name'],
            'class_name' => $pc['class_name'],
            'contact_number' => $pc['contact_number'],
            'date' => $pc['date'] ?? $today,
            'view_url' => !empty($pc['student_id']) ? ("parent_report.php?std_id=" . $pc['student_id']) : null,
            'action_url' => "admin_requests.php"
        ];
    }
} catch (Exception $e) {}

// Post-process alerts: assign IDs, check read status, and filter
$process_alerts = function(&$alerts) use ($read_alerts, $status_filter) {
    foreach ($alerts as &$a) {
        $a['id'] = $a['type'] . '_' . ($a['student_id'] ?? '0') . '_' . ($a['date'] ?? 'nodate');
        $a['is_read'] = in_array($a['id'], $read_alerts);
    }
    unset($a);
    if ($status_filter !== 'all') {
        $alerts = array_filter($alerts, function($a) use ($status_filter) {
            if ($status_filter === 'read') return $a['is_read'];
            if ($status_filter === 'unread') return !$a['is_read'];
            return true;
        });
    }
};

$process_alerts($academic_alerts);
$process_alerts($attendance_alerts);
$process_alerts($finance_alerts);
$process_alerts($admin_alerts);

$total_alerts = count($academic_alerts) + count($attendance_alerts) + count($finance_alerts) + count($admin_alerts);
?>

<div class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h3 class="fw-bold text-primary mb-1">
                <i class="fas fa-bell text-warning me-2"></i> اسمارٹ نوٹیفیکیشن و الرٹ پینل
            </h3>
            <p class="text-muted small mb-0">
                روزانہ ناغہ جات، غیر حاضریاں، مسلسل غفلت، فیس بقایا جات اور فوری توجہ طلب معاملات کی جامع رپورٹ
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill">
                کل فعال الرٹس: <?php echo $total_alerts; ?>
            </span>
            <button class="btn btn-outline-secondary btn-sm rounded-pill" onclick="window.location.reload();">
                <i class="fas fa-sync-alt me-1"></i> تازہ کریں
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-4 rounded-4">
        <div class="card-body bg-light rounded-4">
            <form method="GET" class="row g-3 align-items-end" id="filterForm">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">تاریخ کا فلٹر</label>
                    <select name="date_filter" class="form-select rounded-pill" onchange="toggleCustomDates(this.value)">
                        <option value="today" <?php if($date_filter=='today') echo 'selected'; ?>>آج</option>
                        <option value="this_week" <?php if($date_filter=='this_week') echo 'selected'; ?>>رواں ہفتہ</option>
                        <option value="this_month" <?php if($date_filter=='this_month') echo 'selected'; ?>>رواں مہینہ</option>
                        <option value="all" <?php if($date_filter=='all') echo 'selected'; ?>>تمام تاریخیں</option>
                        <option value="custom" <?php if($date_filter=='custom') echo 'selected'; ?>>منتخب تاریخیں (Custom)</option>
                    </select>
                </div>
                <div class="col-md-2 custom-date-col" style="<?php echo ($date_filter=='custom')?'':'display:none;'; ?>">
                    <label class="form-label small fw-bold">شروع کی تاریخ</label>
                    <input type="date" name="start_date" class="form-control rounded-pill" value="<?php echo $_GET['start_date'] ?? date('Y-m-01'); ?>">
                </div>
                <div class="col-md-2 custom-date-col" style="<?php echo ($date_filter=='custom')?'':'display:none;'; ?>">
                    <label class="form-label small fw-bold">ختم ہونے کی تاریخ</label>
                    <input type="date" name="end_date" class="form-control rounded-pill" value="<?php echo $_GET['end_date'] ?? date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">اسٹیٹس فلٹر</label>
                    <select name="status_filter" class="form-select rounded-pill">
                        <option value="all" <?php if($status_filter=='all') echo 'selected'; ?>>تمام</option>
                        <option value="unread" <?php if($status_filter=='unread') echo 'selected'; ?>>ان دیکھے (Unread)</option>
                        <option value="read" <?php if($status_filter=='read') echo 'selected'; ?>>دیکھے گئے (Read)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="fas fa-filter me-1"></i> فلٹر کریں
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function toggleCustomDates(val) {
            const cols = document.querySelectorAll('.custom-date-col');
            cols.forEach(col => col.style.display = (val === 'custom') ? 'block' : 'none');
        }
    </script>


    <!-- Category Tabs -->
    <ul class="nav nav-pills mb-4 gap-2 border-bottom pb-3" id="alertTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active rounded-pill px-3 fw-bold" id="all-tab" data-bs-toggle="pill" data-bs-target="#tab-all" type="button">
                تمام الرٹس (<?php echo $total_alerts; ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-3 fw-bold" id="academic-tab" data-bs-toggle="pill" data-bs-target="#tab-academic" type="button">
                <i class="fas fa-book-quran me-1"></i> حفظ و تعلیم (<?php echo count($academic_alerts); ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-3 fw-bold" id="attendance-tab" data-bs-toggle="pill" data-bs-target="#tab-attendance" type="button">
                <i class="fas fa-user-check me-1"></i> حاضری و غیر حاضری (<?php echo count($attendance_alerts); ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-3 fw-bold" id="finance-tab" data-bs-toggle="pill" data-bs-target="#tab-finance" type="button">
                <i class="fas fa-wallet me-1"></i> فیس و مالیات (<?php echo count($finance_alerts); ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-3 fw-bold" id="admin-tab" data-bs-toggle="pill" data-bs-target="#tab-admin" type="button">
                <i class="fas fa-user-shield me-1"></i> رخصت و شکایات (<?php echo count($admin_alerts); ?>)
            </button>
        </li>
    </ul>

    <!-- Tab Contents -->
    <div class="tab-content" id="alertTabContent">
        <!-- 1. All Alerts -->
        <div class="tab-pane fade show active" id="tab-all" role="tabpanel">
            <?php 
            $all = array_merge($academic_alerts, $attendance_alerts, $finance_alerts, $admin_alerts);
            usort($all, function($a, $b) {
                $p = ['critical' => 0, 'warning' => 1, 'info' => 2];
                return ($p[$a['severity']] ?? 2) - ($p[$b['severity']] ?? 2);
            });
            render_alert_list($all); 
            ?>
        </div>

        <!-- 2. Academic Alerts -->
        <div class="tab-pane fade" id="tab-academic" role="tabpanel">
            <?php render_alert_list($academic_alerts); ?>
        </div>

        <!-- 3. Attendance Alerts -->
        <div class="tab-pane fade" id="tab-attendance" role="tabpanel">
            <?php render_alert_list($attendance_alerts); ?>
        </div>

        <!-- 4. Finance Alerts -->
        <div class="tab-pane fade" id="tab-finance" role="tabpanel">
            <?php render_alert_list($finance_alerts); ?>
        </div>

        <!-- 5. Admin Alerts -->
        <div class="tab-pane fade" id="tab-admin" role="tabpanel">
            <?php render_alert_list($admin_alerts); ?>
        </div>
    </div>
</div>

<?php
function render_alert_list($items) {
    if (empty($items)) {
        echo '
        <div class="text-center py-5">
            <i class="fas fa-check-circle text-success fs-1 mb-3"></i>
            <h5 class="fw-bold">کوئی الرٹ یا انتباہ موجود نہیں ہے!</h5>
            <p class="text-muted">تمام طلبہ، اساتذہ اور انتظامی شعبہ جات کا ریکارڈ مکمل اور درست ہے۔</p>
        </div>';
        return;
    }

    echo '<div class="row g-3">';
    foreach ($items as $item) {
        $bColor = ($item['severity'] === 'critical') ? 'border-danger' : (($item['severity'] === 'warning') ? 'border-warning' : 'border-info');
        $badgeClass = ($item['severity'] === 'critical') ? 'bg-danger text-white' : (($item['severity'] === 'warning') ? 'bg-warning text-dark' : 'bg-info text-white');
        $badgeText = ($item['severity'] === 'critical') ? 'فوری توجہ' : (($item['severity'] === 'warning') ? 'انتباہ' : 'معلومات');

        echo '
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm border-0 border-start border-4 ' . $bColor . '" style="border-radius: 12px;">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0 text-dark">' . htmlspecialchars($item['title']) . '</h6>
                        <span class="badge rounded-pill ' . $badgeClass . ' small px-2 py-1">' . $badgeText . '</span>
                    </div>
                    <p class="card-text text-secondary small flex-grow-1 mb-3">
                        ' . htmlspecialchars($item['description']) . '
                    </p>
                    <div class="border-top pt-2 d-flex justify-content-between align-items-center small text-muted">
                        <div>
                            <i class="fas fa-calendar-alt me-1"></i> ' . htmlspecialchars($item['date']) . '
                            ' . (!empty($item['class_name']) ? ' | <i class="fas fa-graduation-cap me-1"></i> ' . htmlspecialchars($item['class_name']) : '') . '
                        </div>
                        <div class="d-flex align-items-center gap-2">';
                        
        if (!empty($item['contact_number'])) {
            $rawPhone = preg_replace('/[^0-9]/', '', $item['contact_number']);
            $waPhone = (substr($rawPhone, 0, 1) === '0') ? '92' . substr($rawPhone, 1) : $rawPhone;
            echo '
                <a href="tel:' . htmlspecialchars($rawPhone) . '" class="btn btn-sm btn-outline-success rounded-circle" title="کال کریں" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0;">
                    <i class="fas fa-phone-alt"></i>
                </a>
                <a href="https://wa.me/' . htmlspecialchars($waPhone) . '" target="_blank" class="btn btn-sm btn-outline-success rounded-circle" title="واٹس ایپ" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; color: #25D366; border-color: #25D366;">
                    <i class="fab fa-whatsapp"></i>
                </a>';
        }

        $viewUrl = !empty($item['view_url']) ? $item['view_url'] : (!empty($item['student_id']) ? ("parent_report.php?std_id=" . $item['student_id']) : '');
        if (!empty($viewUrl)) {
            echo '
                <a href="' . htmlspecialchars($viewUrl) . '" target="_blank" class="btn btn-sm btn-outline-info rounded-circle" title="رپورٹ دیکھیں (تفصیل)" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0;">
                    <i class="fas fa-eye"></i>
                </a>';
        }

        if (!empty($item['action_url'])) {
            echo '
                <a href="' . htmlspecialchars($item['action_url']) . '" class="btn btn-sm btn-primary rounded-pill px-3 py-1">
                    کارروائی <i class="fas fa-arrow-left ms-1"></i>
                </a>';
        }

        $readBtnClass = $item['is_read'] ? 'btn-success text-white' : 'btn-outline-secondary';
        $readBtnIcon = $item['is_read'] ? 'fa-check-double' : 'fa-check';
        $readBtnTitle = $item['is_read'] ? 'پڑھا ہوا (ان دیکھا کریں)' : 'دیکھا ہوا مارک کریں';
        
        echo '
                <button type="button" class="btn btn-sm ' . $readBtnClass . ' rounded-circle ms-1" title="' . $readBtnTitle . '" onclick="toggleReadStatus(\'' . htmlspecialchars($item['id']) . '\', ' . ($item['is_read'] ? 'true' : 'false') . ', this)" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0;">
                    <i class="fas ' . $readBtnIcon . '"></i>
                </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }
    echo '</div>';
}
?>

<style>
.nav-pills .nav-link {
    background: #f8f9fa;
    color: #495057;
    border: 1px solid #dee2e6;
}
.nav-pills .nav-link.active {
    background: #284D3D;
    color: #ffffff;
    border-color: #284D3D;
}
</style>

<script>
function toggleReadStatus(alertId, isCurrentlyRead, btnElement) {
    const newStatus = isCurrentlyRead ? 'unread' : 'read';
    const formData = new FormData();
    formData.append('action', 'toggle_smart_alert_read');
    formData.append('alert_id', alertId);
    formData.append('status', newStatus);

    fetch('smart_notifications.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => console.error('Error:', error));
}
</script>

<?php include 'footer.php'; ?>
