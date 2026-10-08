<?php
require_once 'db.php';


// 1. فلٹرز - header include سے پہلے
$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_view_dashboard.php');
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();

try {
    $st_stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? LIMIT 1");
    $st_stmt->execute([$cur_tenant_id]);
    $settings = $st_stmt->fetch();
    $week_start_day = $settings['week_start_day'] ?? 6;
} catch (Exception $e) {
    $week_start_day = 6;
    // echo "Settings table error: " . $e->getMessage() . "<br>";
}
$app_name = !empty($settings['madrasa_name']) ? $settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : "جامعہ اشرف المدارس — فاروقہ، سرگودھا");

$period = $_GET['period'] ?? 'current_week';
$start_date = $_GET['from'] ?? '';
$end_date = $_GET['to'] ?? '';
$hifz_section_filter = $_GET['hifz_section'] ?? 'all';

if (empty($start_date) || empty($end_date)) {
    $range = get_period_date_range($period, $start_date, $end_date, $week_start_day);
    $start_date = $range['from'];
    $end_date = $range['to'];
}

include 'header.php';

$student_roll_select = "s.admission_number AS roll_number";
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'roll_number'");
    if ($colStmt && $colStmt->fetch()) {
        $student_roll_select = "s.roll_number";
    }
} catch (Exception $e) {}



$selected_student_id = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : 0;
$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys_type === 'Masjid') ? "(s.system_type = 'Masjid')" : "(s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')";

try {
    // Fetch all relevant Hifz students: Active students + students who were enrolled or have records in this date range
    // Safe query initialization
    // 1. Check if class exists in classes table to resolve numeric ID if applicable
    $class_id = 0;
    try {
        $cStmt = $pdo->prepare("SELECT id FROM classes WHERE (name = ? OR TRIM(name) = ?) AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
        $cStmt->execute([$selected_class, trim($selected_class), $cur_tenant_id]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($cRow) $class_id = (int)$cRow['id'];
    } catch (Exception $eC) {}

    // 2. Also check if daily_hifz has students with lesson records for this tenant
    $dh_sids = [];
    try {
        $dhStmt = $pdo->prepare("SELECT DISTINCT student_id FROM daily_hifz WHERE (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
        $dhStmt->execute([$cur_tenant_id]);
        $dh_sids = $dhStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $eDh) {}

    // Check available columns in students table dynamically
    $studentCols = [];
    try {
        $studentCols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
    $hasClassName = in_array('class_name', $studentCols, true);

    // Build flexible class matching condition:
    $matchConditions = [
        "s.class = ?",
        "TRIM(s.class) = ?",
        "s.class LIKE ?"
    ];
    $matchParams = [
        $selected_class,
        trim($selected_class),
        '%' . trim($selected_class) . '%'
    ];

    if ($hasClassName) {
        $matchConditions[] = "s.class_name = ?";
        $matchConditions[] = "TRIM(s.class_name) = ?";
        $matchConditions[] = "s.class_name LIKE ?";
        $matchParams[] = $selected_class;
        $matchParams[] = trim($selected_class);
        $matchParams[] = '%' . trim($selected_class) . '%';
    }

    if ($class_id > 0) {
        $matchConditions[] = "s.class = '$class_id'";
        if ($hasClassName) {
            $matchConditions[] = "s.class_name = '$class_id'";
        }
    }

    $classMatchSql = "(" . implode(" OR ", $matchConditions) . ")";

    // Query students with strict system_type isolation
    $stmt_lazy = $pdo->prepare("
        SELECT s.*, $student_roll_select 
        FROM students s 
        WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
          AND (s.tenant_id = ? OR s.tenant_id IS NULL OR s.tenant_id = 0)
          AND $sys_cond
          AND (LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
          AND (s.status NOT LIKE '%خارج%' AND s.status NOT LIKE '%فارغ%' AND s.status NOT LIKE '%چھوڑ%' AND s.status NOT LIKE '%ترک%')
          AND $classMatchSql 
        ORDER BY s.name ASC
    ");
    $stmt_lazy->execute(array_merge([$cur_tenant_id], $matchParams));
    $all_class_students = $stmt_lazy->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if tenant_id was restrictive
    if (empty($all_class_students)) {
        $stmt_lazy_fallback = $pdo->prepare("
            SELECT s.*, $student_roll_select 
            FROM students s 
            WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
              AND $sys_cond
              AND (LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
              AND (s.status NOT LIKE '%خارج%' AND s.status NOT LIKE '%فارغ%' AND s.status NOT LIKE '%چھوڑ%' AND s.status NOT LIKE '%ترک%')
              AND $classMatchSql 
            ORDER BY s.name ASC
        ");
        $stmt_lazy_fallback->execute($matchParams);
        $all_class_students = $stmt_lazy_fallback->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback to maktab_students ONLY if system_type is Masjid
    if (empty($all_class_students) && $sys_type === 'Masjid') {
        try {
            $m_stmt = $pdo->prepare("
                SELECT s.*, $student_roll_select 
                FROM maktab_students s 
                WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL) 
                  AND (s.class = ? OR TRIM(s.class) = ? OR s.class LIKE ?) 
                ORDER BY s.name ASC
            ");
            $m_stmt->execute([$selected_class, trim($selected_class), '%' . trim($selected_class) . '%']);
            $all_class_students = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eM) {}
    }

    // If still empty but daily_hifz has students, load students who have daily_hifz records and match system_type & class
    if (empty($all_class_students) && !empty($dh_sids)) {
        $inDh = implode(',', array_map('intval', $dh_sids));
        try {
            $dhStdStmt = $pdo->prepare("SELECT s.*, $student_roll_select FROM students s WHERE s.id IN ($inDh) AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND $sys_cond AND $classMatchSql ORDER BY s.name ASC");
            $dhStdStmt->execute($matchParams);
            $all_class_students = $dhStdStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eDhStds) {}
    }
    /*
    $stmt_lazy = $pdo->prepare("
        SELECT s.id, s.name, s.father_name, s.admission_number, s.admission_date, s.graduation_date, s.status, s.alumni_category, s.leaving_reason, $student_roll_select FROM students s
        WHERE (s.tenant_id = ? OR s.tenant_id IS NULL OR s.tenant_id = 0)
          AND $sys_cond
          AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
          AND (s.class = ? OR s.class_name = ?)
          AND (
              (? > 0 AND s.id = ?)
              OR (
                  (? = 0)
                  AND (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present' OR s.status IS NULL OR s.status = '')
                  AND (LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
                  AND (s.status NOT LIKE '%خارج%' AND s.status NOT LIKE '%فارغ%' AND s.status NOT LIKE '%چھوڑ%' AND s.status NOT LIKE '%ترک%')
                  AND (s.alumni_category IS NULL OR s.alumni_category = '' OR LOWER(TRIM(s.alumni_category)) NOT IN ('completed', 'left', 'dropped', 'expelled'))
                  AND (s.graduation_date IS NULL OR s.graduation_date = '' OR s.graduation_date = '0000-00-00' OR s.graduation_date > $start_date_esc)
              )
          )
        ORDER BY s.name ASC
    ");
    $stmt_lazy->execute([$cur_tenant_id, $selected_class, $selected_class, $selected_student_id, $selected_student_id, $selected_student_id]);
    $all_class_students = $stmt_lazy->fetchAll();

    if (empty($all_class_students)) {
        try {
            $m_stmt = $pdo->prepare("
                SELECT s.id, s.name, s.father_name, s.admission_number, s.admission_date, s.graduation_date, s.status, s.alumni_category, s.leaving_reason, $student_roll_select FROM maktab_students s
                WHERE (s.tenant_id = ? OR s.tenant_id IS NULL OR s.tenant_id = 0)
                  AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
                  AND (s.class = ? OR s.class_name = ?)
                  AND (
                      (? > 0 AND s.id = ?)
                      OR (
                          (? = 0)
                          AND (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present' OR s.status IS NULL OR s.status = '')
                          AND (LOWER(TRIM(COALESCE(s.status, ''))) NOT IN ('alumni', 'left', 'struck off', 'dropped', 'expelled', 'graduated', 'inactive', 'خارج', 'فارغ', 'فارغ التحصیل', 'چھوڑ دیا', 'ترک تعلیم'))
                          AND (s.status NOT LIKE '%خارج%' AND s.status NOT LIKE '%فارغ%' AND s.status NOT LIKE '%چھوڑ%' AND s.status NOT LIKE '%ترک%')
                          AND (s.alumni_category IS NULL OR s.alumni_category = '' OR LOWER(TRIM(s.alumni_category)) NOT IN ('completed', 'left', 'dropped', 'expelled'))
                          AND (s.graduation_date IS NULL OR s.graduation_date = '' OR s.graduation_date = '0000-00-00' OR s.graduation_date > $start_date_esc)
                  )
                ORDER BY s.name ASC
            ");
            $m_stmt->execute([$cur_tenant_id, $selected_class, $selected_class, $selected_student_id, $selected_student_id, $selected_student_id]);
            $all_class_students = $m_stmt->fetchAll();
        } catch (Exception $eM) {}
    } */

    if ($hifz_section_filter !== 'all') {
        $all_class_students = array_values(array_filter($all_class_students, function($st) use ($hifz_section_filter) {
            $sec = $st['hifz_section'] ?? 'regular';
            if (empty($sec)) $sec = 'regular';
            return $sec === $hifz_section_filter;
        }));
    }

    if ($selected_student_id > 0) {
        $all_hifz_students = array_values(array_filter($all_class_students, function($st) use ($selected_student_id) {
            return (int)$st['id'] === $selected_student_id;
        }));
    } else {
        $ref_end = !empty($end_date) ? $end_date : date('Y-m-d');
        $filtered_active = array_values(array_filter($all_class_students, function($st) use ($ref_end) {
            return !is_student_inactive($st, $ref_end);
        }));
        $all_hifz_students = !empty($filtered_active) ? $filtered_active : $all_class_students;
    }
} catch (PDOException $e) {
    echo "<div class='container mt-5'><div class='alert alert-danger'><strong>ڈیٹا بیس ایرر (Students):</strong> " . $e->getMessage() . "</div></div>";
    $all_class_students = [];
    $all_hifz_students = [];
}

// Helpers
function getGradePoints($grade, $max) {
    if (!$grade) return 0;
    $g = trim($grade);
    if (in_array($g, ['Excellent', 'ممتاز', 'بہترین', 'اعلیٰ'])) return $max;
    if (in_array($g, ['Good', 'بہتر', 'اچھا'])) return $max * 0.8;
    if (in_array($g, ['Average', 'معمولی', 'مناسب'])) return $max * 0.6;
    if (in_array($g, ['Weak', 'کمزور', 'کچا', 'توجہ طلب'])) return $max * 0.4;
    return 0;
}

function translateDateUrduFull($dateStr) {
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . '-' . ($months[date('M', $timestamp)] ?? '') . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}

function translateStatusClean($status) {
    $map = [
        'Excellent'=>'ممتاز', 'Good'=>'بہتر', 'Average'=>'معمولی', 'Weak'=>'کمزور', 'بہترین'=>'بہترین', 'بہتر'=>'بہتر', 'کمزور'=>'کمزور', 'کچا'=>'کچا',
        'غير حاضر'=>'غیر حاضر', 'غیر حاضر'=>'غیر حاضر', 'غائب'=>'غیر حاضر', 'Absent'=>'غیر حاضر', 'A'=>'غیر حاضر',
        'رخصت'=>'رخصت', 'Leave'=>'رخصت', 'L'=>'رخصت', 'چھٹی'=>'رخصت'
    ];
    $color = [
        'Excellent'=>'success', 'Good'=>'primary', 'Average'=>'warning', 'Weak'=>'danger', 'بہترین'=>'success', 'بہتر'=>'primary', 'کمزور'=>'danger', 'کچا'=>'danger',
        'غير حاضر'=>'danger', 'غیر حاضر'=>'danger', 'غائب'=>'danger', 'Absent'=>'danger', 'A'=>'danger',
        'رخصت'=>'warning', 'Leave'=>'warning', 'L'=>'warning', 'چھٹی'=>'warning'
    ];
    $urdu = $map[$status] ?? $status;
    $c = $color[$status] ?? 'secondary';
    return "<span class='text-$c fw-bold'>$urdu</span>";
}

function translateQtyClean($qty) {
    $map = ['Quarter'=> 'پاؤ', 'Half'=> 'آدھا', 'Three Quarter'=> 'تین پاؤ', 'Full'=> 'مکمل', '1.5 Parah'=> 'ڈیڑھ', '2 Parah'=> 'دو', '2.5 Parah'=> 'ڈھائی', '3 Parah'=> 'تین'];
    return $map[$qty] ?? $qty;
}

function shortName($name) {
    return (strlen($name) > 15) ? mb_substr($name, 0, 15).'..' : $name;
}

function cleanUrduStr($str) {
    if(empty($str)) return '-';
    $str = str_replace(['(3/4)', '(1/2)', '(1/4)', '(1)', '((', '))', 'پارہ '], ['', '', '', '', '(', ')', ''], $str);
    $str = preg_replace('/(\d|[\x{0600}-\x{06FF}])\(/u', '$1 (', $str);
    $str = preg_replace('/([،,])\s*/u', '$1 ', $str);
    $str = preg_replace('/\(?\b(\d{1,2}:\d{2}(?:\s*[aApP][mM])?)\b\)?/i', '<div class="small text-muted" style="font-size: 8.5px; line-height: 1.1; margin-top: 2px;">$1</div>', $str);
    return trim($str);
}
if (!function_exists('get_hifz_completion_projection')) {
    function get_hifz_completion_projection($pdo, $student, $cur_tenant_id) {
        $sid = (int)($student['id'] ?? 0);
        if ($sid <= 0) return null;

        $edition = !empty($student['quran_edition']) ? trim($student['quran_edition']) : '16 سطری';
        $seq = !empty($student['hifz_sequence']) ? trim($student['hifz_sequence']) : 'reverse';

        // 1. لائنیں فی پارہ (16 سطری: 18 صفحات × 16 سطور = 288 لائنیں فی پارہ)
        $lines_per_para = 288;
        if (strpos($edition, '15') !== false) {
            $lines_per_para = 300;
        } elseif (strpos($edition, '13') !== false) {
            $lines_per_para = 370;
        } elseif (strpos($edition, '18') !== false) {
            $lines_per_para = 324;
        }
        $total_lines = 30 * $lines_per_para;

        // 2. طریقۂ حفظ (3 طریقے)
        $para_sequence = [];
        $seq_title = '';
        if ($seq === 'forward') {
            for ($i = 1; $i <= 30; $i++) { $para_sequence[] = $i; }
            $seq_title = 'پارہ 1 تا 30';
        } elseif ($seq === 'thirty_then_forward') {
            $para_sequence = [30];
            for ($i = 1; $i <= 29; $i++) { $para_sequence[] = $i; }
            $seq_title = 'پارہ عم پھر 1 تا 29';
        } else {
            for ($i = 30; $i >= 1; $i--) { $para_sequence[] = $i; }
            $seq_title = 'پارہ 30 تا 1';
        }

        // 3. طالب علم کا سابقہ تفصیلی ریکارڈ حاصل کریں (daily_hifz، hifz_record یا maktab_hifz_record)
        $all_history = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM daily_hifz 
                WHERE student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) 
                ORDER BY date DESC, id DESC");
            $stmt->execute([$sid]);
            $all_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        if (empty($all_history)) {
            try {
                $stmt2 = $pdo->prepare("SELECT * FROM hifz_record 
                    WHERE student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) 
                    ORDER BY date DESC, id DESC");
                $stmt2->execute([$sid]);
                $all_history = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) {}
        }

        if (empty($all_history)) {
            try {
                $stmt3 = $pdo->prepare("SELECT * FROM maktab_hifz_record 
                    WHERE student_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) 
                    ORDER BY date DESC, id DESC");
                $stmt3->execute([$sid]);
                $all_history = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e3) {}
        }

        $latest_para = null;
        $latest_lines = 0;
        $total_sabaq_lines = 0;
        $sabaq_days_count = 0;
        $avg_daily_lines = 8.0;

        $parse_parah_num = function($str) {
            $str = trim((string)$str);
            if (empty($str) || $str === '-' || $str === 'نہیں سنایا') return null;
            if (preg_match('/\b([1-9]|[12][0-9]|30)\b/', $str, $m)) {
                return (int)$m[1];
            }
            if (preg_match('/(\d+)/', $str, $m)) {
                $v = (int)$m[1];
                if ($v >= 1 && $v <= 30) return $v;
            }
            if (strpos($str, 'عم') !== false || strpos($str, 'النبأ') !== false || strpos($str, 'النباء') !== false) return 30;
            if (strpos($str, 'تبارک') !== false || strpos($str, 'الملک') !== false) return 29;
            if (strpos($str, 'قد سمع') !== false || strpos($str, 'المجادلہ') !== false) return 28;
            if (strpos($str, 'ذاریات') !== false) return 27;
            if (strpos($str, 'حم') !== false || strpos($str, 'الاحقاف') !== false) return 26;
            if (strpos($str, 'فمن اظلم') !== false || strpos($str, 'الزمر') !== false) return 24;
            if (strpos($str, 'وما لی') !== false || strpos($str, 'یس') !== false) return 23;
            if (strpos($str, 'ومن یقنت') !== false) return 22;
            if (strpos($str, 'اتل ما اوحی') !== false) return 21;
            if (strpos($str, 'امن خلق') !== false) return 20;
            if (strpos($str, 'وقال الذین') !== false) return 19;
            if (strpos($str, 'قد افلح') !== false) return 18;
            if (strpos($str, 'اقترب') !== false) return 17;
            if (strpos($str, 'قال الم') !== false || strpos($str, 'الکہف') !== false) return 16;
            if (strpos($str, 'سبحن') !== false) return 15;
            if (strpos($str, 'ربما') !== false) return 14;
            if (strpos($str, 'وما ابرئ') !== false) return 13;
            if (strpos($str, 'سیقول') !== false) return 2;
            if (strpos($str, 'الم') !== false) return 1;
            return null;
        };

        // تاریخ کے تمام ریکارڈز کا تجزیہ
        foreach ($all_history as $row) {
            $raw_lines = trim((string)($row['sabaq_lines'] ?? ''));
            $parsed_lines = 0;
            if (preg_match('/(\d+)/', $raw_lines, $lm)) {
                $parsed_lines = (int)$lm[1];
            }
            $st_raw = trim((string)($row['sabaq_status'] ?? $row['sabaq_grade'] ?? ''));
            $is_missed = (strpos($st_raw, 'ناغہ') !== false || strpos($st_raw, 'نہیں سنا') !== false || strpos($st_raw, 'غیر حاضر') !== false || strpos($st_raw, 'رخصت') !== false);

            if ($parsed_lines > 0 && !$is_missed) {
                $total_sabaq_lines += $parsed_lines;
                $sabaq_days_count++;
            }

            $p_raw = trim((string)($row['sabaq_parah'] ?? $row['sabaq_para'] ?? ''));
            $p_num = $parse_parah_num($p_raw);
            if ($p_num !== null && $latest_para === null) {
                $latest_para = $p_num;
                $latest_lines = $parsed_lines;
            }
        }

        // اگر سبق میں پارہ نہیں ملا تو سبقی یا منزل سے دیکھیں
        if ($latest_para === null) {
            foreach ($all_history as $row) {
                $om_raw = trim((string)($row['sabqi_parah'] ?? $row['sabqi_para'] ?? $row['manzil_parah'] ?? $row['manzil_details'] ?? ''));
                $p_num = $parse_parah_num($om_raw);
                if ($p_num !== null) {
                    $latest_para = $p_num;
                    break;
                }
            }
        }

        // ڈیفالٹ
        if ($latest_para === null) {
            $latest_para = $para_sequence[0];
        }

        // طالب علم کی حقیقی یومیہ اوسط رفتار
        if ($sabaq_days_count > 0) {
            $calc_avg = $total_sabaq_lines / $sabaq_days_count;
            if ($calc_avg >= 0.5) {
                $avg_daily_lines = round($calc_avg, 1);
            }
        }

        // ترتیب میں موجودہ پارے کی پوزیشن
        $para_idx = array_search($latest_para, $para_sequence);
        if ($para_idx === false) {
            $para_idx = 0;
        }

        $completed_paras = $para_idx;

        // موجودہ پارے میں سنائی گئی کل لائنیں
        $current_para_recited_lines = 0;
        foreach ($all_history as $row) {
            $p_raw = trim((string)($row['sabaq_parah'] ?? $row['sabaq_para'] ?? ''));
            $p_num = $parse_parah_num($p_raw);
            if ($p_num === $latest_para) {
                $raw_lines = trim((string)($row['sabaq_lines'] ?? ''));
                if (preg_match('/(\d+)/', $raw_lines, $lm)) {
                    $current_para_recited_lines += (int)$lm[1];
                }
            }
        }

        if ($current_para_recited_lines <= 0 && $latest_lines > 0) {
            $current_para_recited_lines = $latest_lines;
        }
        $current_para_recited_lines = min($current_para_recited_lines, $lines_per_para - 1);

        $completed_lines = ($completed_paras * $lines_per_para) + $current_para_recited_lines;
        $remaining_lines = max(0, $total_lines - $completed_lines);
        $progress_perc = min(100.0, round(($completed_lines / $total_lines) * 100, 1));
        $is_already_completed = ($remaining_lines <= 0 || $progress_perc >= 100.0);

        // 5. درکار خالص تدریسی ایام
        $net_educational_days = ($avg_daily_lines > 0) ? (int)ceil($remaining_lines / $avg_daily_lines) : 0;

        // 6. پروجیکشن مع جمعہ، عیدین، امتحانات اور رخصتیں
        $expected_date_str = date('Y-m-d');
        $skipped_fridays = 0;
        $skipped_vacation_days = 0;
        $projected_calendar_days = 0;

        if (!$is_already_completed && $net_educational_days > 0) {
            $cur_t = time();
            $edu_counter = 0;
            while ($edu_counter < $net_educational_days && $projected_calendar_days < 5000) {
                $cur_t += 86400;
                $projected_calendar_days++;
                $w = (int)date('N', $cur_t); // 5 = Friday
                if ($w === 5) {
                    $skipped_fridays++;
                    continue; // ہفتہ وار جمعہ کی چھٹی
                }
                // سالانہ عیدین، امتحانات اور متوقع رخصتوں کا متناسب بفر (ہر 6 تدریسی دنوں پر 1 چھٹی)
                if (($edu_counter + $skipped_vacation_days) % 6 === 5) {
                    $skipped_vacation_days++;
                    continue;
                }
                $edu_counter++;
            }
            $expected_date_str = date('Y-m-d', $cur_t);
        }

        $diff_days = $projected_calendar_days;
        $years = (int)floor($diff_days / 365);
        $rem_after_y = $diff_days % 365;
        $months = (int)round($rem_after_y / 30);
        if ($months >= 12) { $years++; $months = 0; }

        $duration_text = '';
        if ($years > 0) $duration_text .= "$years سال ";
        if ($months > 0) $duration_text .= "$months ماہ";
        if (empty($duration_text)) $duration_text = "$diff_days دن";
        $duration_text = trim($duration_text);

        return [
            'edition' => $edition,
            'lines_per_para' => $lines_per_para,
            'total_lines' => $total_lines,
            'sequence_code' => $seq,
            'sequence_title' => $seq_title,
            'current_para' => $latest_para,
            'completed_paras' => $completed_paras,
            'completed_lines' => $completed_lines,
            'remaining_lines' => $remaining_lines,
            'progress_perc' => $progress_perc,
            'avg_daily_lines' => $avg_daily_lines,
            'sabaq_days_count' => $sabaq_days_count,
            'net_educational_days' => $net_educational_days,
            'skipped_fridays' => $skipped_fridays,
            'skipped_vacation_days' => $skipped_vacation_days,
            'total_calendar_days' => $projected_calendar_days,
            'expected_date' => $expected_date_str,
            'duration_text' => $duration_text,
            'is_completed' => $is_already_completed
        ];
    }
}


if (!function_exists('formatUrduDateClean')) {
    function formatUrduDateClean($dateStr) {
        if (!$dateStr) return '-';
        $ts = strtotime($dateStr);
        $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
        $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
        $d = date('j', $ts);
        $m = $months[date('M', $ts)] ?? date('M', $ts);
        $y = date('Y', $ts);
        $day = $days[date('D', $ts)] ?? '';
        return "$d $m $y" . ($day ? " ($day)" : "");
    }
}
?>

<style>
    .report-wrapper { width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; }
    .table-responsive { width: 100% !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table th, .table td { word-break: break-word !important; white-space: normal !important; padding: 3px 4px !important; font-size: clamp(12px, 1.2vw, 14px); vertical-align: middle; }
    .nowrap-col { white-space: nowrap !important; word-break: normal !important; word-wrap: normal !important; }
    .report-table { 
        min-width: 1520px !important; 
        font-size: 13px !important; 
        table-layout: fixed !important; 
    }
    .report-table thead th { 
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #284D3D !important;
        color: #ffffff !important;
        font-size: 13px !important; 
        font-weight: 700 !important; 
        padding: 6px 4px !important; 
        vertical-align: middle !important; 
    }
    .report-table td { 
        font-size: 13px !important; 
        padding: 3px 4px !important; 
        line-height: 1.25 !important; 
        vertical-align: middle !important; 
        white-space: normal !important;
        word-break: normal !important;
        overflow-wrap: break-word !important;
    }
    .report-table .badge { 
        font-size: 11px !important; 
        font-weight: 600 !important; 
        padding: 2px 6px !important; 
    }
    .report-table .small, .report-table small { 
        font-size: 10px !important; 
        line-height: 1.15 !important; 
    }
    .report-table .nowrap-col { 
        white-space: normal !important; 
        word-break: normal !important; 
        line-height: 1.2 !important;
    }
    .bg-light-purple { background-color: #f3e5f5; }
    .gauge-container { position: relative; width: 80px; height: 80px; margin: 0 auto; }
    .gauge-svg { transform: rotate(-90deg); width: 100%; height: 100%; }
    .gauge-bg { fill: none; stroke: #eee; stroke-width: 8; }
    .gauge-fill { fill: none; stroke: var(--primary); stroke-width: 8; stroke-linecap: round; }
    .gauge-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; font-size: 14px; }
    
    .student-hero-card {
        background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%) !important;
        background-color: #1b4332 !important;
        border-radius: 14px !important;
        color: #ffffff !important;
    }
    .student-hero-card .card-body {
        color: #ffffff !important;
    }
    
    @media print {
        @page { size: A4 landscape; margin: 4mm 5mm; }
        body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; background: #fff !important; margin: 0 !important; padding: 0 !important; }
        .d-print-none, header, footer, nav, .navbar, .sidebar { display: none !important; }
        #printableArea { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; border: none !important; }
        .table-responsive { overflow: visible !important; width: 100% !important; max-width: 100% !important; min-width: 0 !important; }
        .report-table, table.table { width: 100% !important; max-width: 100% !important; min-width: 0 !important; table-layout: auto !important; font-size: 7.5pt !important; }
        .table th, .table td { 
            white-space: normal !important; 
            word-break: break-word !important; 
            word-wrap: break-word !important; 
            padding: 2px 2px !important; 
            line-height: 1.1 !important;
            font-size: 7.5pt !important;
        }
        .nowrap-col { 
            white-space: nowrap !important; 
            word-break: normal !important; 
            word-wrap: normal !important; 
        }
        .small { font-size: 6.5pt !important; }

        .student-hero-card {
            background: #f8fafc !important;
            background-color: #f8fafc !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
            box-shadow: none !important;
        }
        .student-hero-card h3,
        .student-hero-card h3 span {
            color: #0f172a !important;
            text-shadow: none !important;
        }
        .student-hero-card i.fa-user-graduate {
            color: #ca8a04 !important;
        }
        .student-hero-card p,
        .student-hero-card p span,
        .student-hero-card p strong {
            color: #1e293b !important;
        }
        .student-hero-card .badge {
            background: #f1f5f9 !important;
            color: #1e293b !important;
            border: 1px solid #cbd5e1 !important;
        }
        .student-hero-card .badge strong {
            color: #15803d !important;
        }
        .student-hero-card .badge span {
            color: #0f172a !important;
        }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h4 class="fw-bold text-success mb-0"><i class="fas fa-filter me-2"></i> حفظ و ناظرہ رپورٹ فلٹرز & پرنٹ</h4>
        <a href="academic_view_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
        <div class="col-6 col-md-3">
            <label class="fw-bold small mb-1"><i class="fas fa-user-graduate me-1 text-success"></i> طالب علم (سرچ کریں):</label>
            <select name="student_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                <option value="">-- تمام طلبہ (ڈیفالٹ) --</option>
                <?php foreach($all_class_students as $st): ?>
                    <option value="<?php echo $st['id']; ?>" <?php echo $selected_student_id == $st['id'] ? 'selected' : ''; ?>>
                        <?php $secBadge = (($st['hifz_section'] ?? '') === 'manzil_gardan') ? ' [منزل/گردان]' : ''; ?>
                        <?php echo htmlspecialchars($st['admission_number'] . ' - ' . $st['name'] . $secBadge); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1"><i class="fas fa-layer-group me-1 text-primary"></i> سیکشن:</label>
            <select name="hifz_section" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                <option value="all" <?php echo $hifz_section_filter==='all'?'selected':''; ?>>تمام سیکشنز</option>
                <option value="regular" <?php echo $hifz_section_filter==='regular'?'selected':''; ?>>سیکشن A: ریگولر حفظ</option>
                <option value="manzil_gardan" <?php echo $hifz_section_filter==='manzil_gardan'?'selected':''; ?>>سیکشن B: منزل/گردان</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">دورانیہ (Period):</label>
            <select name="period" id="hifzPeriodSelect" class="form-select form-select-sm fw-bold" onchange="applyHifzPeriodDates(this.value)">
                <option value="all" <?php echo $period==='all'?'selected':''; ?>>تمام تاریخیں / تمام ریکارڈز (All)</option>
                <option value="this_month" <?php echo $period==='this_month'?'selected':''; ?>>رواں مہینہ (This Month)</option>
                <option value="current_week" <?php echo $period==='current_week'?'selected':''; ?>>رواں ہفتہ (ڈیفالٹ)</option>
                <option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>ہفتہ وار (آخری 7 دن)</option>
                <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>ماہانہ (آخری 30 دن)</option>
                <option value="quarterly" <?php echo $period==='quarterly'?'selected':''; ?>>سہ ماہی (آخری 90 دن)</option>
                <option value="semi_annually" <?php echo $period==='semi_annually'?'selected':''; ?>>شش ماہی (آخری 180 دن)</option>
                <option value="annually" <?php echo $period==='annually'?'selected':''; ?>>سالانہ (پورا سال)</option>
                <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>کسٹم ڈیٹس (Custom)</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ سے:</label>
            <input type="date" name="from" id="hifzFromDate" class="form-control form-control-sm fw-bold" value="<?php echo $start_date; ?>" onchange="if(this.value && document.getElementById('hifzToDate').value) this.form.submit();" required>
        </div>
        <div class="col-6 col-md-2">
            <label class="fw-bold small mb-1">تاریخ تک:</label>
            <input type="date" name="to" id="hifzToDate" class="form-control form-control-sm fw-bold" value="<?php echo $end_date; ?>" onchange="if(this.value && document.getElementById('hifzFromDate').value) this.form.submit();" required>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-success btn-sm w-100 fw-bold"><i class="fas fa-search me-1"></i> فلٹر کریں</button>
            <button type="button" onclick="window.print()" class="btn btn-dark btn-sm w-100 fw-bold"><i class="fas fa-print me-1"></i> پرنٹ</button>
        </div>
    </form>
</div>

<script>
function applyHifzPeriodDates(val) {
    const today = new Date();
    const formatDate = (d) => {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    let fromDate = new Date();
    let toDate = new Date();
    const weekStartDay = <?php echo (int)$week_start_day; ?>;

    if (val === 'current_week') {
        const currentDayNum = today.getDay();
        const daysSinceStart = (currentDayNum - weekStartDay + 7) % 7;
        fromDate.setDate(today.getDate() - daysSinceStart);
        toDate = today;
    } else if (val === 'weekly') {
        fromDate.setDate(today.getDate() - 6);
        toDate = today;
    } else if (val === 'monthly') {
        fromDate.setDate(today.getDate() - 29);
        toDate = today;
    } else if (val === 'quarterly') {
        fromDate.setDate(today.getDate() - 89);
        toDate = today;
    } else if (val === 'semi_annually') {
        fromDate.setDate(today.getDate() - 179);
        toDate = today;
    } else if (val === 'annually') {
        fromDate.setDate(today.getDate() - 364);
        toDate = today;
    }
    if (val !== 'custom') {
        document.getElementById('hifzFromDate').value = formatDate(fromDate);
        document.getElementById('hifzToDate').value = formatDate(toDate);
        document.getElementById('hifzPeriodSelect').form.submit();
    }
}
</script>

<div id="printableArea" class="bg-white p-2 p-md-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-3 pb-2">
        <div><h2 class="m-0 fw-bold text-success"><i class="fas fa-quran me-2"></i> حفظ رپورٹس - <?= htmlspecialchars($selected_class) ?></h2></div>
        <div class="text-start"><p class="mb-0 fw-bold">دورانیہ: <span dir="ltr"><?php echo date('d M', strtotime($start_date)); ?> - <?php echo date('d M', strtotime($end_date)); ?></span></p></div>
    </div>

    <?php
    // Date normalization helper
    $norm_date = function($dRaw) {
        $dRaw = explode(' ', trim((string)$dRaw))[0];
        if (empty($dRaw)) return '';
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        $ts = strtotime($dRaw);
        return $ts ? date('Y-m-d', $ts) : $dRaw;
    };

    // Fetch Hifz records using student IDs of this class to prevent class naming mismatches
    $all_class_student_ids = array_map(function($st) { return (int)$st['id']; }, $all_class_students);
    $hifz_records = [];
    if (!empty($all_class_student_ids)) {
        $in_class_ids = implode(',', $all_class_student_ids);
        $sql = "SELECT d.* FROM daily_hifz d 
                WHERE (d.tenant_id = ? OR d.tenant_id IS NULL OR d.tenant_id = 0)
                  AND d.student_id IN ($in_class_ids)
                ORDER BY d.id ASC";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cur_tenant_id]);
            $hifz_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $hifz_records = [];
        }
    }
    // Fallback if class students array was empty or has additional students matching class
    if (empty($hifz_records)) {
        $sql_fallback = "SELECT d.* FROM daily_hifz d 
                JOIN students s ON d.student_id = s.id 
                WHERE (d.tenant_id = ? OR d.tenant_id IS NULL OR d.tenant_id = 0)
                  AND (s.tenant_id = ? OR s.tenant_id IS NULL OR s.tenant_id = 0)
                  AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND $sys_cond
                  AND (s.class = ? OR s.class_name = ?)
                ORDER BY d.id ASC";
        try {
            $stmt = $pdo->prepare($sql_fallback);
            $stmt->execute([$cur_tenant_id, $cur_tenant_id, $selected_class, $selected_class]);
            $hifz_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    $hifz_map = [];
    foreach ($hifz_records as $rec) {
        $dNorm = $norm_date($rec['date'] ?? '');
        if ($dNorm >= $start_date && $dNorm <= $end_date) {
            $sid = (int)$rec['student_id'];
            if (!isset($hifz_map[$dNorm][$sid])) {
                $hifz_map[$dNorm][$sid] = $rec;
            } else {
                $existing = $hifz_map[$dNorm][$sid];
                $has_data_new = !empty(trim((string)($rec['sabaq_parah'] ?? ''))) ||
                                !empty(trim((string)($rec['sabqi_parah'] ?? ''))) ||
                                !empty(trim((string)($rec['manzil_parah'] ?? ''))) ||
                                !empty(trim((string)($rec['manzil_details'] ?? '')));
                $has_data_old = !empty(trim((string)($existing['sabaq_parah'] ?? ''))) ||
                                !empty(trim((string)($existing['sabqi_parah'] ?? ''))) ||
                                !empty(trim((string)($existing['manzil_parah'] ?? ''))) ||
                                !empty(trim((string)($existing['manzil_details'] ?? '')));
                if ($has_data_new && !$has_data_old) {
                    $hifz_map[$dNorm][$sid] = $rec;
                } elseif ($has_data_new && $has_data_old) {
                    foreach ($rec as $k => $v) {
                        if (trim((string)$v) !== '') {
                            $existing[$k] = $v;
                        }
                    }
                    $hifz_map[$dNorm][$sid] = $existing;
                }
            }
        }
    }

    // Fetch Attendance for all students in class to link Absent / Leave students
    $hifz_student_ids = array_map(function($st) { return (int)$st['id']; }, $all_hifz_students);
    $att_map = [];

    if (!empty($hifz_student_ids)) {
        $in_clause = implode(',', $hifz_student_ids);
        
        // Parse attendance from attendance table
        try {
            $att_sql = "SELECT * FROM attendance WHERE (tenant_id = $cur_tenant_id OR tenant_id IS NULL OR tenant_id = 0) AND student_id IN ($in_clause)";
            $att_stmt = $pdo->query($att_sql);
            $att_records = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($att_records as $a) {
                $sid = (int)$a['student_id'];
                $dNorm = $norm_date($a['date'] ?? '');
                if (!$dNorm) continue;

                $parse_s = function($str) {
                    $str = mb_strtoupper(trim((string)$str), 'UTF-8');
                    if ($str === '' || $str === '-') return '';
                    $tokens = preg_split('/\s+/', $str);
                    if (strpos($str, 'ABSENT') !== false || strpos($str, 'غیر حاضر') !== false || strpos($str, 'غیرحاضر') !== false || strpos($str, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) return 'A';
                    if (strpos($str, 'LEAVE') !== false || strpos($str, 'رخصت') !== false || strpos($str, 'چھٹی') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) return 'L';
                    if ($str !== '') return 'P';
                    return '';
                };

                $mStat = $parse_s($a['status'] ?? '');
                $v1 = $parse_s($a['status_1'] ?? '') ?: $mStat;
                $v2 = $parse_s($a['status_2'] ?? '') ?: $mStat;
                $v3 = $parse_s($a['status_3'] ?? '') ?: $mStat;
                $v4 = $parse_s($a['status_4'] ?? '') ?: $mStat;
                $v5 = $parse_s($a['status_5'] ?? '') ?: $mStat;
                $v6 = $parse_s($a['status_6'] ?? '') ?: $mStat;
                $v7 = $parse_s($a['status_7'] ?? '') ?: $mStat;
                $v8 = $parse_s($a['status_8'] ?? '') ?: $mStat;

                $mainDayStat = $parse_s($a['daily_summary'] ?? '') ?: ($v1 ?: ($v2 ?: ($v3 ?: $mStat)));

                if (!isset($att_map[$dNorm][$sid])) {
                    $att_map[$dNorm][$sid] = ['s1'=>'', 's2'=>'', 's3'=>'', 'main'=>''];
                }
                if ($v1) $att_map[$dNorm][$sid]['s1'] = $v1;
                if ($v2) $att_map[$dNorm][$sid]['s2'] = $v2;
                if ($v3) $att_map[$dNorm][$sid]['s3'] = $v3;
                if ($mainDayStat === 'A') $att_map[$dNorm][$sid]['main'] = 'A';
                elseif ($mainDayStat === 'L' && ($att_map[$dNorm][$sid]['main'] ?? '') !== 'A') $att_map[$dNorm][$sid]['main'] = 'L';
                elseif ($mainDayStat !== '') $att_map[$dNorm][$sid]['main'] = $mainDayStat;
            }
        } catch (PDOException $e) {}

        // Parse attendance from period_attendance & maktab_attendance
        try {
            $pa_sql = "SELECT student_id, date, period_num, status FROM period_attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_clause)";
            $pa_stmt = $pdo->query($pa_sql);
            $pa_records = $pa_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pa_records as $pa) {
                $sid = (int)$pa['student_id'];
                $dNorm = $norm_date($pa['date'] ?? '');
                if (!$dNorm) continue;

                $rawPnum = strtolower(trim((string)($pa['period_num'] ?? '')));
                $pnum = 0;
                if ($rawPnum === '1' || strpos($rawPnum, 'sabaq') !== false || strpos($rawPnum, 'morning') !== false || strpos($rawPnum, 'صبح') !== false || strpos($rawPnum, 'fajr') !== false) {
                    $pnum = 1;
                } elseif ($rawPnum === '2' || strpos($rawPnum, 'sabqi') !== false || strpos($rawPnum, 'breakfast') !== false || strpos($rawPnum, 'اشراق') !== false) {
                    $pnum = 2;
                } elseif ($rawPnum === '3' || strpos($rawPnum, 'manzil') !== false || strpos($rawPnum, 'noon') !== false || strpos($rawPnum, 'ظہر') !== false) {
                    $pnum = 3;
                } elseif (is_numeric($rawPnum)) {
                    $pnum = (int)$rawPnum;
                }

                $stat = mb_strtoupper(trim((string)($pa['status'] ?? '')), 'UTF-8');
                $tokens = preg_split('/\s+/', $stat);
                $normStat = '';
                if (strpos($stat, 'ABSENT') !== false || strpos($stat, 'غیر حاضر') !== false || strpos($stat, 'غیرحاضر') !== false || strpos($stat, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) $normStat = 'A';
                elseif (strpos($stat, 'LEAVE') !== false || strpos($stat, 'رخصت') !== false || strpos($stat, 'چھٹی') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) $normStat = 'L';
                elseif ($stat !== '') $normStat = 'P';

                if ($normStat !== '') {
                    if (!isset($att_map[$dNorm][$sid])) {
                        $att_map[$dNorm][$sid] = ['s1'=>'', 's2'=>'', 's3'=>'', 'main'=>''];
                    }
                    if ($pnum == 1) $att_map[$dNorm][$sid]['s1'] = $normStat;
                    elseif ($pnum == 2) $att_map[$dNorm][$sid]['s2'] = $normStat;
                    elseif ($pnum == 3) $att_map[$dNorm][$sid]['s3'] = $normStat;
                    
                    if ($normStat === 'A') $att_map[$dNorm][$sid]['main'] = 'A';
                    elseif ($normStat === 'L' && ($att_map[$dNorm][$sid]['main'] ?? '') !== 'A') $att_map[$dNorm][$sid]['main'] = 'L';
                }
            }
        } catch (PDOException $e) {}
        
        if ($sys_type === 'Masjid') {
        try {
            $ma_sql = "SELECT student_id, date, status FROM maktab_attendance WHERE tenant_id = $cur_tenant_id AND student_id IN ($in_clause)";
            $ma_stmt = $pdo->query($ma_sql);
            $ma_records = $ma_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ma_records as $pa) {
                $sid = (int)$pa['student_id'];
                $dNorm = $norm_date($pa['date'] ?? '');
                if (!$dNorm) continue;

                $stat = mb_strtoupper(trim((string)($pa['status'] ?? '')), 'UTF-8');
                $tokens = preg_split('/\s+/', $stat);
                $normStat = '';
                if (strpos($stat, 'ABSENT') !== false || strpos($stat, 'غیر حاضر') !== false || strpos($stat, 'غیرحاضر') !== false || strpos($stat, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) $normStat = 'A';
                elseif (strpos($stat, 'LEAVE') !== false || strpos($stat, 'رخصت') !== false || strpos($stat, 'چھٹی') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) $normStat = 'L';
                elseif ($stat !== '') $normStat = 'P';

                if ($normStat !== '') {
                    if (!isset($att_map[$dNorm][$sid])) {
                        $att_map[$dNorm][$sid] = ['s1'=>'', 's2'=>'', 's3'=>'', 'main'=>''];
                    }
                    if ($normStat === 'A') $att_map[$dNorm][$sid]['main'] = 'A';
                    elseif ($normStat === 'L' && ($att_map[$dNorm][$sid]['main'] ?? '') !== 'A') $att_map[$dNorm][$sid]['main'] = 'L';
                }
            }
        } catch (PDOException $e) {}
        }
    }

    // Fetch Complaints for the date range
    $c_sql = "SELECT c.student_id, c.date, c.complaint as description, 'admin' as type FROM complaints c WHERE c.tenant_id = ? AND c.date BETWEEN ? AND ?";
    $pc_sql = "SELECT p.student_id, p.date, CONCAT('[', p.category, '] ', p.description) as description, 'parent' as type FROM parent_complaints p WHERE p.tenant_id = ? AND p.date BETWEEN ? AND ?";
    
    $all_c_sql = "$c_sql UNION ALL $pc_sql";
    try {
        $c_stmt = $pdo->prepare($all_c_sql);
        $c_stmt->execute([$cur_tenant_id, $start_date, $end_date, $cur_tenant_id, $start_date, $end_date]);
        $complaints_data = $c_stmt->fetchAll();
    } catch (PDOException $e) {
        $c_stmt = $pdo->prepare($c_sql);
        $c_stmt->execute([$cur_tenant_id, $start_date, $end_date]);
        $complaints_data = $c_stmt->fetchAll();
    }
    
    $complaints_map = [];
    foreach($complaints_data as $cd) {
        $key = $cd['student_id'] . '_' . $cd['date'];
        if(!isset($complaints_map[$key])) $complaints_map[$key] = [];
        $complaints_map[$key][] = ($cd['type'] == 'parent' ? '⚠️(والدین)' : '') . $cd['description'];
    }

    // Generate dates in range (descending)
    $report_dates = [];
    $start_time = strtotime($start_date);
    $end_time = strtotime($end_date);
    for ($current_time = $end_time; $current_time >= $start_time; $current_time -= 86400) {
        $report_dates[] = date('Y-m-d', $current_time);
    }

    $totalSabaq = 0; $totalSabqi = 0; $totalManzil = 0; $h_count = 0;
    $students_with_records = [];
    
    // Weekly Frequency Trackers
    $freq_sabaq = [];
    $freq_sabqi = [];
    $freq_manzil = [];
    $dates_sabaq_naaghah = [];
    $dates_sabqi_naaghah = [];
    $dates_manzil_naaghah = [];
    $student_recited_sabaq_count = [];
    $student_recited_sabqi_count = [];
    $student_recited_manzil_count = [];
    ?>

    <?php if ($selected_student_id > 0 && !empty($all_hifz_students)): 
        $selected_st = $all_hifz_students[0];
        $sid = (int)$selected_st['id'];
        $st_name = htmlspecialchars($selected_st['name'] ?? '');
        $st_father = htmlspecialchars($selected_st['father_name'] ?: '-');
        $st_roll_val = '';
        foreach (['roll_number', 'admission_number', 'roll_no', 'admission_no', 'gr_number', 'serial_no'] as $rk) {
            if (!empty($selected_st[$rk]) && trim((string)$selected_st[$rk]) !== '-' && trim((string)$selected_st[$rk]) !== '0') {
                $st_roll_val = trim((string)$selected_st[$rk]);
                break;
            }
        }
        $st_roll = !empty($st_roll_val) ? htmlspecialchars($st_roll_val) : '-';
        $is_manzil_gardan = (($selected_st['hifz_section'] ?? 'regular') === 'manzil_gardan');

        $parse_manzil_paras = function($str) {
            $str = trim((string)$str);
            if (empty($str)) return 0.0;
            if (strpos($str, '1/4') !== false || strpos($str, 'پاؤ') !== false || stripos($str, 'quarter') !== false) return 0.25;
            if (strpos($str, '1/2') !== false || strpos($str, 'آدھا') !== false || stripos($str, 'half') !== false) return 0.5;
            if (strpos($str, '3/4') !== false || strpos($str, 'تین پاؤ') !== false) return 0.75;
            if (strpos($str, '1.5') !== false || strpos($str, 'ڈیڑھ') !== false) return 1.5;
            if (strpos($str, '2.5') !== false || strpos($str, 'ڈھائی') !== false) return 2.5;
            if (preg_match('/(\d+(?:\.\d+)?)/', $str, $m)) return (float)$m[1];
            return 1.0;
        };

        $ind_total_sabaq_lines = 0;
        $ind_sabaq_naghas = 0;
        $ind_sabqi_days = 0;
        $ind_sabqi_naghas = 0;
        $ind_manzil_paras = 0.0;
        $ind_manzil_naghas = 0;
        $ind_present_days = 0;
        $ind_absent_days = 0;
        $ind_leave_days = 0;
        $ind_unrecorded_days = 0;
        $ind_complaints_count = 0;

        $ind_total_score_points = 0;
        $ind_active_days_count = 0;

        $ind_daily_rows = [];

        $sAdm = !empty($selected_st['admission_date']) ? $norm_date($selected_st['admission_date']) : '';
        $sGrad = !empty($selected_st['graduation_date']) ? $norm_date($selected_st['graduation_date']) : '';

        foreach ($report_dates as $date) {
            $rec = $hifz_map[$date][$sid] ?? null;
            $att = $att_map[$date][$sid] ?? ['s1'=>'', 's2'=>'', 's3'=>'', 'main'=>''];

            $day_of_week = date('N', strtotime($date));
            $is_thursday = ($day_of_week == 4);
            $is_friday = ($day_of_week == 5);

            $as1 = $att['s1'] ?? '';
            $as2 = $att['s2'] ?? '';
            $as3 = $att['s3'] ?? '';
            $asMain = $att['main'] ?? '';

            $hasRecOnDay = !empty($rec) || !empty($as1) || !empty($as2) || !empty($as3) || !empty($asMain);
            if (is_student_inactive($selected_st, $date)) continue;






            if ($rec) {
                $chk_hifz_stat = function($st) {
                    $st = mb_strtoupper(trim((string)$st), 'UTF-8');
                    if (strpos($st, 'غیر حاضر') !== false || strpos($st, 'غیرحاضر') !== false || strpos($st, 'غائب') !== false || strpos($st, 'ABSENT') !== false || $st === 'A') return 'A';
                    if (strpos($st, 'رخصت') !== false || strpos($st, 'LEAVE') !== false || $st === 'L') return 'L';
                    return '';
                };
                $st1 = $chk_hifz_stat($rec['sabaq_status'] ?? '');
                $st2 = $chk_hifz_stat($rec['sabqi_status'] ?? '');
                $st3 = $chk_hifz_stat($rec['manzil_status'] ?? '');
                if ($st1) $as1 = $st1;
                if ($st2) $as2 = $st2;
                if ($st3) $as3 = $st3;
            }

            // Check if student recited in any period today
            $chk_has_rec = function($p_type) use (&$rec) {
                if (!$rec) return false;
                $m_p = $p_type == 'manzil' ? 'manzil_details' : ($p_type.'_parah');
                if ($p_type == 'manzil' && empty(trim((string)($rec[$m_p] ?? '')))) {
                    $m_p = 'manzil_parah';
                }
                $m_a = $p_type == 'sabaq' ? 'sabaq_lines' : ($p_type.'_amount');
                $st_val = trim((string)($rec[$p_type.'_status'] ?? ''));
                $pa_val = trim((string)($rec[$m_p] ?? ''));
                $am_val = trim((string)($rec[$m_a] ?? ''));
                return (!empty($st_val) || !empty($pa_val) || !empty($am_val)) 
                    && $st_val !== 'ناغہ' && $st_val !== '-' && $st_val !== 'غیر حاضر' && $st_val !== 'Absent' && $st_val !== 'رخصت' && $st_val !== 'Leave' 
                    && $pa_val !== '-' && $pa_val !== 'غیر حاضر' && $pa_val !== 'رخصت' && $pa_val !== 'ناغہ'
                    && strpos($st_val, 'نہیں سنا') === false && strpos($pa_val, 'نہیں سنا') === false && strpos($st_val, 'نہیں سنائی') === false && strpos($pa_val, 'نہیں سنائی') === false;
            };

            $has_sabaq_rec = $chk_has_rec('sabaq');
            $has_sabqi_rec = $chk_has_rec('sabqi');
            $has_manzil_rec = $chk_has_rec('manzil');
            $has_any_rec = ($has_sabaq_rec || $has_sabqi_rec || $has_manzil_rec);

            $is_day_leave = ($asMain === 'L' || $as1 === 'L' || $as2 === 'L' || $as3 === 'L');
            $is_day_absent = ($asMain === 'A' || $as1 === 'A' || $as2 === 'A' || $as3 === 'A');
            $is_day_present_att = ($asMain === 'P' || $as1 === 'P' || $as2 === 'P' || $as3 === 'P');

            $is_present = ($has_any_rec || $is_day_present_att);

            $proc_ind = function($stat, $prefix, $is_present) use (&$rec, $date, $is_thursday, $is_friday, $is_manzil_gardan) {
                $p = [];
                if ($is_friday) {
                    $b = "<span class='text-primary fw-bold'>جمعہ</span>";
                    $p['parah'] = $b; $p['amount'] = '-'; $p['status'] = $b; $p['listener'] = '-'; $p['time'] = ''; $p['type'] = 'friday';
                    return $p;
                }
                if ($is_thursday && $prefix === 'manzil') {
                    $b = "<span class='text-info fw-bold'>مشق</span>";
                    $p['parah'] = $b; $p['amount'] = '-'; $p['status'] = $b; $p['listener'] = '-'; $p['time'] = ''; $p['type'] = 'mashq';
                    return $p;
                }

                $m_p = $prefix == 'manzil' ? 'manzil_details' : ($prefix.'_parah');
                if ($prefix == 'manzil' && empty(trim((string)($rec[$m_p] ?? '')))) {
                    $m_p = 'manzil_parah';
                }
                $m_a = $prefix == 'sabaq' ? 'sabaq_lines' : ($prefix.'_amount');

                $st_val = trim((string)($rec[$prefix.'_status'] ?? ''));
                $pa_val = trim((string)($rec[$m_p] ?? ''));
                $am_val = trim((string)($rec[$m_a] ?? ''));

                $is_not_recited = (strpos($st_val, 'نہیں سنا') !== false || strpos($pa_val, 'نہیں سنا') !== false || strpos($st_val, 'نہیں سنائی') !== false || strpos($pa_val, 'نہیں سنائی') !== false);

                if ($is_not_recited) {
                    $p['parah'] = cleanUrduStr($rec[$m_p] ?? 'نہیں سنایا');
                    $p['amount'] = htmlspecialchars($rec[$m_a] ?? 'بغیر وجہ');
                    $p['status'] = "<span class='badge bg-warning text-dark'>نہیں سنایا</span>";
                    $p['listener'] = '-';
                    $p['time'] = '';
                    $p['type'] = 'not_recited';
                    return $p;
                }

                $has_recitation = $rec && (!empty($st_val) || !empty($pa_val) || !empty($am_val)) 
                    && $st_val !== 'ناغہ' && $st_val !== '-' && $st_val !== 'غیر حاضر' && $st_val !== 'Absent' && $st_val !== 'رخصت' && $st_val !== 'Leave' 
                    && $pa_val !== '-' && $pa_val !== 'غیر حاضر' && $pa_val !== 'رخصت' && $pa_val !== 'ناغہ';

                if ($has_recitation) {
                    $p['parah'] = !empty($pa_val) ? cleanUrduStr($rec[$m_p] ?? '') : '-';
                    $p['amount'] = htmlspecialchars($rec[$m_a] ?? '-');
                    $p['status'] = !empty($st_val) ? translateStatusClean($rec[$prefix.'_status'] ?? '') : "<span class='badge bg-success'>سنایا</span>";
                    $p['time'] = !empty($rec[$prefix.'_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($rec[$prefix.'_time']))."</small>" : "";
                    $p['listener'] = htmlspecialchars($rec[$prefix.'_listener'] ?? '-');
                    $p['type'] = 'recited';
                    return $p;
                }

                if ($stat === 'A' || $st_val === 'غیر حاضر' || $st_val === 'Absent' || $pa_val === 'غیر حاضر') {
                    $b = "<span class='text-danger fw-bold'>غیر حاضر</span>";
                    $p['parah'] = $b; $p['amount'] = '-'; $p['status'] = $b; $p['listener'] = '-'; $p['time'] = ''; $p['type'] = 'absent';
                    return $p;
                }
                if ($stat === 'L' || $st_val === 'رخصت' || $st_val === 'Leave' || $pa_val === 'رخصت') {
                    $b = "<span class='text-success fw-bold'>رخصت</span>";
                    $p['parah'] = $b; $p['amount'] = '-'; $p['status'] = $b; $p['listener'] = '-'; $p['time'] = ''; $p['type'] = 'leave';
                    return $p;
                }

                // Explicitly marked naaghah
                if ($st_val === 'ناغہ' || $pa_val === 'ناغہ') {
                    $p['parah'] = '-';
                    $p['amount'] = '-';
                    $p['status'] = "<span class='badge bg-danger'>ناغہ</span>";
                    $p['listener'] = '-';
                    $p['time'] = '';
                    $p['type'] = 'missed';
                    return $p;
                }

                // If student was present in class (marked P or recited in another period) but did not recite this period:
                if ($is_present) {
                    if ($is_manzil_gardan && ($prefix == 'sabaq' || $prefix == 'sabqi')) {
                        $p['parah'] = '-';
                        $p['amount'] = '-';
                        $p['status'] = "<span class='badge bg-light text-dark border'>استثناء (منزل)</span>";
                        $p['listener'] = '-';
                        $p['time'] = '';
                        $p['type'] = 'exempt';
                        return $p;
                    }
                    $p['parah'] = '-';
                    $p['amount'] = '-';
                    $p['status'] = "<span class='badge bg-danger'>ناغہ</span>";
                    $p['listener'] = '-';
                    $p['time'] = '';
                    $p['type'] = 'missed';
                    return $p;
                }

                // Otherwise unrecorded
                $p['parah'] = '-';
                $p['amount'] = '-';
                $p['status'] = "<span class='text-muted small'>-</span>";
                $p['listener'] = '-';
                $p['time'] = '';
                $p['type'] = 'unrecorded';
                return $p;
            };

            $ps = $proc_ind($as1 ?: $asMain, 'sabaq', $is_present);
            $psq = $proc_ind($as2 ?: $asMain, 'sabqi', $is_present);
            $pm = $proc_ind($as3 ?: $asMain, 'manzil', $is_present);

            if ($is_friday) {
                $att_badge = "<span class='badge bg-primary'>جمعہ</span>";
            } elseif ($is_present) {
                $ind_present_days++;
                $att_badge = "<span class='badge bg-success'>حاضر</span>";
            } elseif ($is_day_leave) {
                $ind_leave_days++;
                $att_badge = "<span class='badge bg-warning text-dark'>رخصت</span>";
            } elseif ($is_day_absent) {
                $ind_absent_days++;
                $att_badge = "<span class='badge bg-danger'>غیر حاضر</span>";
            } else {
                $ind_unrecorded_days++;
                $att_badge = "<span class='badge bg-light text-muted border'>غیر درج شدہ</span>";
            }

            if ($is_present) {
                if ($is_manzil_gardan) {
                    if ($pm['type'] === 'recited') {
                        $m_amt = $parse_manzil_paras($rec['manzil_amount'] ?? '');
                        if ($m_amt == 0.0 && !empty($rec['manzil_parah'])) $m_amt = 1.0;
                        $ind_manzil_paras += $m_amt;
                        $ind_total_score_points += getGradePoints($rec['manzil_status'] ?? '', 100);
                    } elseif ($pm['type'] === 'missed' || $pm['type'] === 'not_recited') {
                        $ind_manzil_naghas++;
                    }
                } else {
                if ($ps['type'] === 'recited') {
                    $ind_total_sabaq_lines += (int)($rec['sabaq_lines'] ?? 0);
                    $ind_total_score_points += getGradePoints($rec['sabaq_status'] ?? '', 30);
                } elseif ($ps['type'] === 'missed' || $ps['type'] === 'not_recited') {
                    $ind_sabaq_naghas++;
                }

                if ($psq['type'] === 'recited') {
                    $ind_sabqi_days++;
                    $ind_total_score_points += getGradePoints($rec['sabqi_status'] ?? '', 30);
                } elseif ($psq['type'] === 'missed' || $psq['type'] === 'not_recited') {
                    $ind_sabqi_naghas++;
                }

                if (!$is_thursday) {
                    if ($pm['type'] === 'recited') {
                        $m_amt = $parse_manzil_paras($rec['manzil_amount'] ?? '');
                        if ($m_amt == 0.0 && !empty($rec['manzil_parah'])) $m_amt = 1.0;
                        $ind_manzil_paras += $m_amt;
                        $ind_total_score_points += getGradePoints($rec['manzil_status'] ?? '', 40);
                    } elseif ($pm['type'] === 'missed' || $pm['type'] === 'not_recited') {
                        $ind_manzil_naghas++;
                    }
                }
                }
                $ind_active_days_count++;
            }

            $comp_key = $sid . '_' . $date;
            $comp_html = '-';
            if (isset($complaints_map[$comp_key])) {
                $comp_html = implode('<br><hr class="my-1 border-danger">', $complaints_map[$comp_key]);
                $ind_complaints_count += count($complaints_map[$comp_key]);
            }

            $ind_daily_rows[] = [
                'date' => $date,
                'att_badge' => $att_badge,
                'sabaq' => $ps,
                'sabqi' => $psq,
                'manzil' => $pm,
                'complaints' => $comp_html,
            ];
        }

        $ind_academic_score = ($ind_active_days_count > 0) ? min(100, round($ind_total_score_points / $ind_active_days_count)) : 0;
        ?>

        <!-- ==================== انفرادی طالب علم ویو (Individual Student View) ==================== -->
        <!-- طالب علم پروفائل کارڈ -->
        <div class="card mb-3 border-0 shadow-sm text-white student-hero-card" style="background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 100%) !important; background-color: #1b4332 !important; border-radius: 14px !important; color: #ffffff !important;">
            <div class="card-body p-3 p-md-4" style="color: #ffffff !important;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h3 class="fw-bold mb-2 d-flex align-items-center" style="color: #ffffff !important; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">
                            <i class="fas fa-user-graduate me-2" style="color: #facc15 !important;"></i> 
                            <span style="color: #ffffff !important;"><?= $st_name ?></span>
                        </h3>
                        <p class="mb-0 fs-6 d-flex flex-wrap align-items-center gap-2" style="color: #f8fafc !important;">
                            <span class="badge px-3 py-1" style="background: rgba(0, 0, 0, 0.25) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; color: #ffffff !important; font-size: 0.95rem; font-weight: normal;">
                                <strong style="color: #a7f3d0 !important;">ولدیت:</strong> <span style="color: #ffffff !important; font-weight: bold;"><?= $st_father ?></span>
                            </span>
                            <span class="badge px-3 py-1" style="background: rgba(0, 0, 0, 0.25) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; color: #ffffff !important; font-size: 0.95rem; font-weight: normal;">
                                <strong style="color: #a7f3d0 !important;">رول نمبر:</strong> <span style="color: #ffffff !important; font-weight: bold;"><?= $st_roll ?></span>
                            </span>
                            <span class="badge px-3 py-1" style="background: rgba(0, 0, 0, 0.25) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; color: #ffffff !important; font-size: 0.95rem; font-weight: normal;">
                                <strong style="color: #a7f3d0 !important;">کلاس:</strong> <span style="color: #ffffff !important; font-weight: bold;"><?= htmlspecialchars($selected_class) ?></span>
                            </span>
                            <?php if ($is_manzil_gardan): ?>
                            <span class="badge bg-warning text-dark px-3 py-1" style="font-size: 0.95rem; font-weight: bold;">
                                <i class="fas fa-bookmark me-1"></i> منزل/گردان (پارٹ ٹائم)
                            </span>
                            <?php else: ?>
                            <span class="badge bg-success text-white px-3 py-1" style="font-size: 0.95rem; font-weight: bold;">
                                <i class="fas fa-user-clock me-1"></i> ریگولر حفظ (مکمل وقت)
                            </span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-start bg-white text-dark p-2 px-3 rounded-3 shadow-sm border border-2 border-white">
                        <div class="small fw-bold text-muted">رپورٹ کا دورانیہ</div>
                        <div class="fw-bold text-success fs-6" dir="ltr"><?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4 کلیدی سمری کارڈز -->
        <div class="row g-3 mb-4">
            <!-- سبق سمری -->
            <div class="col-6 col-md-3">
                <div class="card h-100 shadow-sm border-0 border-top border-4 border-success p-3 text-center bg-white rounded-3">
                    <div class="d-flex align-items-center justify-content-center text-success mb-2">
                        <i class="fas fa-book-open me-2 fs-5"></i>
                        <h6 class="fw-bold mb-0">سبق کی سمری</h6>
                    </div>
                    <div class="d-flex justify-content-around align-items-center mt-2">
                        <div>
                            <div class="fs-4 fw-bold text-dark"><?= $ind_total_sabaq_lines ?></div>
                            <small class="text-muted fw-bold">کل لائنیں</small>
                        </div>
                        <div class="border-start ps-3">
                            <?php if ($is_manzil_gardan): ?>
                            <span class="badge bg-secondary fs-6 px-2 py-1">استثناء</span>
                            <?php else: ?>
                            <span class="badge <?= $ind_sabaq_naghas > 0 ? 'bg-danger' : 'bg-success' ?> fs-6 px-3 py-1"><?= $ind_sabaq_naghas ?></span>
                            <?php endif; ?>
                            <div class="small text-muted fw-bold mt-1">ناغے</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- سبقی سمری -->
            <div class="col-6 col-md-3">
                <div class="card h-100 shadow-sm border-0 border-top border-4 border-primary p-3 text-center bg-white rounded-3">
                    <div class="d-flex align-items-center justify-content-center text-primary mb-2">
                        <i class="fas fa-book me-2 fs-5"></i>
                        <h6 class="fw-bold mb-0">سبقی کی سمری</h6>
                    </div>
                    <div class="d-flex justify-content-around align-items-center mt-2">
                        <div>
                            <div class="fs-4 fw-bold text-dark"><?= $ind_sabqi_days ?> دن</div>
                            <small class="text-muted fw-bold">سنائے گئے ایام</small>
                        </div>
                        <div class="border-start ps-3">
                            <?php if ($is_manzil_gardan): ?>
                            <span class="badge bg-secondary fs-6 px-2 py-1">استثناء</span>
                            <?php else: ?>
                            <span class="badge <?= $ind_sabqi_naghas > 0 ? 'bg-danger' : 'bg-success' ?> fs-6 px-3 py-1"><?= $ind_sabqi_naghas ?></span>
                            <?php endif; ?>
                            <div class="small text-muted fw-bold mt-1">ناغے</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- منزل سمری -->
            <div class="col-6 col-md-3">
                <div class="card h-100 shadow-sm border-0 border-top border-4 border-warning p-3 text-center bg-white rounded-3">
                    <div class="d-flex align-items-center justify-content-center text-warning text-dark mb-2">
                        <i class="fas fa-quran me-2 fs-5 text-warning"></i>
                        <h6 class="fw-bold mb-0 text-dark">منزل کی سمری</h6>
                    </div>
                    <div class="d-flex justify-content-around align-items-center mt-2">
                        <div>
                            <div class="fs-4 fw-bold text-dark"><?= number_format($ind_manzil_paras, 1) ?> پارے</div>
                            <small class="text-muted fw-bold">کل پارے</small>
                        </div>
                        <div class="border-start ps-3">
                            <span class="badge <?= $ind_manzil_naghas > 0 ? 'bg-danger' : 'bg-success' ?> fs-6 px-3 py-1"><?= $ind_manzil_naghas ?></span>
                            <div class="small text-muted fw-bold mt-1">ناغے</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- حاضری سمری -->
            <div class="col-6 col-md-3">
                <div class="card h-100 shadow-sm border-0 border-top border-4 border-info p-3 text-center bg-white rounded-3">
                    <div class="d-flex align-items-center justify-content-center text-info mb-2">
                        <i class="fas fa-user-check me-2 fs-5"></i>
                        <h6 class="fw-bold mb-0 text-dark">حاضری سمری</h6>
                    </div>
                    <div class="d-flex justify-content-around align-items-center mt-2">
                        <div>
                            <div class="fs-4 fw-bold text-dark"><?= $ind_present_days ?> دن</div>
                            <small class="text-muted fw-bold">کل حاضری</small>
                        </div>
                        <div class="border-start ps-3">
                            <span class="badge <?= $ind_absent_days > 0 ? 'bg-danger' : 'bg-success' ?> fs-6 px-3 py-1"><?= $ind_absent_days ?> دن</span>
                            <div class="small text-muted fw-bold mt-1">غیر حاضری</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- تعلیمی کارکردگی و گیج سیکشن -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card p-3 shadow-sm border-0 bg-light text-center h-100 rounded-3">
                    <h6 class="fw-bold text-success mb-2"><i class="fas fa-award me-1"></i> حفظ کی مجموعی کارکردگی</h6>
                    <div class="gauge-container my-2">
                        <svg class="gauge-svg" viewBox="0 0 100 100">
                            <circle class="gauge-bg" cx="50" cy="50" r="40"></circle>
                            <circle class="gauge-fill" cx="50" cy="50" r="40" style="stroke-dasharray: <?= ($ind_academic_score * 2.51); ?>, 251.2; stroke: #198754;"></circle>
                        </svg>
                        <div class="gauge-text fs-5"><?= $ind_academic_score; ?>%</div>
                    </div>
                    <div class="fw-bold mt-2">
                        <?php 
                        if ($ind_active_days_count == 0) {
                            echo "<span class='badge bg-secondary fs-6 py-2 px-3'>غیر فعال (کوئی تعلیمی ریکارڈ نہیں)</span>";
                        } elseif ($ind_academic_score >= 85) {
                            echo "<span class='badge bg-success fs-6'>ممتاز (Excellent)</span>";
                        } elseif ($ind_academic_score >= 70) {
                            echo "<span class='badge bg-primary fs-6'>بہتر (Good)</span>";
                        } elseif ($ind_academic_score >= 50) {
                            echo "<span class='badge bg-warning text-dark fs-6'>مناسب (Average)</span>";
                        } else {
                            echo "<span class='badge bg-danger fs-6'>توجہ طلب (Weak)</span>";
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card p-3 shadow-sm border-0 bg-white h-100 rounded-3">
                    <h6 class="fw-bold text-dark mb-3"><i class="fas fa-clipboard-check text-primary me-2"></i> کارکردگی سمری و تفصیلات (Performance Overview)</h6>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 text-center bg-light">
                                <span class="text-muted small d-block">کل تدریسی ایام</span>
                                <span class="fw-bold fs-6 text-dark"><?= count($report_dates) ?> دن</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 text-center bg-light">
                                <span class="text-muted small d-block">رخصت کے دن</span>
                                <span class="fw-bold fs-6 text-warning text-dark"><?= $ind_leave_days ?> دن</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 text-center bg-light">
                                <span class="text-muted small d-block">غیر درج شدہ دن</span>
                                <span class="fw-bold fs-6 text-secondary"><?= $ind_unrecorded_days ?> دن</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 text-center bg-light">
                                <span class="text-muted small d-block">کل شکایات</span>
                                <span class="fw-bold fs-6 <?= $ind_complaints_count > 0 ? 'text-danger' : 'text-success' ?>"><?= $ind_complaints_count ?></span>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="fas fa-info-circle me-1 text-info"></i>
                        یہ رپورٹ طالب علم کے روزانہ کے تمام اسباق، سبقی، منزل، اور حاضری کے مکمل ریکارڈ پر مبنی ہے۔ تفصیلی تاریخ وار انٹریز نیچے درج ہیں۔
                    </p>
                </div>
            </div>
        </div>

        <!-- ==================== تخمینہ تکمیلِ حفظ (Hifz Completion Estimate) ==================== -->
        <?php 
        $hifz_est = get_hifz_completion_projection($pdo, $selected_st, $cur_tenant_id);
        if ($hifz_est): 
            $urdu_date_clean = formatUrduDateClean($hifz_est['expected_date']);
        ?>
        <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden" style="background-color: #0f3d2e !important; border: 1px solid #1e6b52 !important; border-right: 6px solid #eab308 !important; border-radius: 12px !important;">
            <div class="card-body p-3 p-md-3" style="color: #ffffff !important;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 pb-2" style="border-bottom: 1px solid rgba(255, 255, 255, 0.2) !important;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-calendar-check" style="color: #fde047 !important; font-size: 1.25rem;"></i>
                        <h6 class="mb-0 fw-bold fs-6" style="color: #ffffff !important; font-weight: 700;">تخمینہ تکمیلِ حفظ</h6>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span style="background: rgba(0, 0, 0, 0.35) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; border-radius: 20px; padding: 2px 10px; font-size: 11px; color: #ffffff !important;">
                            <strong style="color: #6ee7b7 !important;">نسخہ:</strong> <span style="color: #fde047 !important; font-weight: bold;"><?= htmlspecialchars($hifz_est['edition']) ?></span>
                        </span>
                        <span style="background: rgba(0, 0, 0, 0.35) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; border-radius: 20px; padding: 2px 10px; font-size: 11px; color: #ffffff !important;">
                            <strong style="color: #6ee7b7 !important;">ترتیب:</strong> <span style="color: #fde047 !important; font-weight: bold;"><?= htmlspecialchars($hifz_est['sequence_title']) ?></span>
                        </span>
                        <span style="background: rgba(0, 0, 0, 0.35) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; border-radius: 20px; padding: 2px 10px; font-size: 11px; color: #ffffff !important;">
                            <strong style="color: #6ee7b7 !important;">یومیہ رفتار:</strong> <span style="color: #fde047 !important; font-weight: bold;"><?= $hifz_est['avg_daily_lines'] ?> لائنیں</span>
                        </span>
                        <span style="background: rgba(0, 0, 0, 0.35) !important; border: 1px solid rgba(255, 255, 255, 0.25) !important; border-radius: 20px; padding: 2px 10px; font-size: 11px; color: #ffffff !important;">
                            <strong style="color: #6ee7b7 !important;">مکمل:</strong> <span style="color: #fde047 !important; font-weight: bold;"><?= $hifz_est['completed_paras'] ?>/30 پارے</span>
                        </span>
                    </div>
                </div>

                <?php if ($hifz_est['is_completed']): ?>
                    <div class="text-center py-2">
                        <h5 class="fw-bold mb-0" style="color: #fde047 !important;"><i class="fas fa-award me-2"></i> مبارک ہو! حفظِ قرآن کریم مکمل ہو چکا ہے</h5>
                    </div>
                <?php else: ?>
                    <div class="row align-items-center g-3">
                        <div class="col-12 col-md-7">
                            <div class="d-flex align-items-baseline gap-2 flex-wrap">
                                <span style="color: #cbd5e1 !important; font-size: 13px; font-weight: 600;">متوقع تاریخ فراغت:</span>
                                <span style="color: #fde047 !important; font-size: 1.45rem; font-weight: 800; text-shadow: 0 1px 3px rgba(0,0,0,0.5);" dir="rtl"><?= $urdu_date_clean ?></span>
                            </div>
                            <div style="color: #cbd5e1 !important; font-size: 12px; margin-top: 4px;">
                                متوقع درکار دورانیہ: <strong style="color: #ffffff !important; font-weight: bold;"><?= $hifz_est['duration_text'] ?></strong> 
                                &nbsp;•&nbsp; خالص تدریسی ایام: <strong style="color: #ffffff !important; font-weight: bold;"><?= number_format($hifz_est['net_educational_days']) ?> دن</strong>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 12px;">
                                <span style="color: #cbd5e1 !important;">پیش رفت: <strong style="color: #fde047 !important; font-weight: bold;"><?= $hifz_est['progress_perc'] ?>%</strong></span>
                                <span style="color: #ffffff !important; font-weight: 600;"><?= number_format($hifz_est['completed_lines']) ?> / <?= number_format($hifz_est['total_lines']) ?> لائنیں</span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 6px; background-color: rgba(255,255,255,0.2) !important; border: 1px solid rgba(255,255,255,0.25) !important;">
                                <div class="progress-bar" role="progressbar" style="width: <?= $hifz_est['progress_perc'] ?>%; background: linear-gradient(90deg, #eab308 0%, #facc15 100%) !important; border-radius: 5px;" aria-valuenow="<?= $hifz_est['progress_perc'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- روزانہ کا تفصیلی رجسٹر لاگ -->
        <div class="card shadow-sm border-0 mb-4 rounded-3">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
                <h5 class="mb-0 fw-bold fs-6"><i class="fas fa-list me-2"></i> روزانہ کا تفصیلی رجسٹر (Daily Detailed Register)</h5>
                <span class="badge bg-light text-dark fw-bold"><?= count($ind_daily_rows) ?> دن کا ریکارڈ</span>
            </div>
            <div class="table-responsive" style="width: 100%; max-width: 100%; border-radius: 0 0 8px 8px;">
                <table class="table table-bordered border-dark text-center table-sm align-middle mb-0 report-table" style="font-size: 10.5px; width: 100% !important; min-width: 1400px !important; table-layout: fixed !important;">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" style="width: 3%;">#</th>
                            <th rowspan="2" style="width: 10%;">تاریخ</th>
                            <th rowspan="2" style="width: 6%;">حاضری</th>
                            <th colspan="3" class="bg-success text-white">سبق (نیا)</th>
                            <th colspan="4" class="bg-primary text-white">سبقی (پچھلا سبق)</th>
                            <th colspan="4" class="bg-warning text-dark">منزل (پختہ)</th>
                            <th rowspan="2" style="width: 7%;" class="bg-danger text-white">شکایات / کیفیت</th>
                        </tr>
                        <tr>
                            <th style="width: 3.5%;">پارہ</th><th style="width: 3.5%;">لائنیں</th><th style="width: 5.5%;">کیفیت</th>
                            <th style="width: 11%;">تفصیل</th><th style="width: 4%;">مقدار</th><th style="width: 5.5%;">کیفیت</th><th style="width: 10%;">سننے والا</th>
                            <th style="width: 13.5%;">تفصیل</th><th style="width: 4%;">مقدار</th><th style="width: 5.5%;">کیفیت</th><th style="width: 10%;">سننے والا</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sr = 1;
                        foreach ($ind_daily_rows as $row): 
                            $fDate = translateDateUrduFull($row['date']);
                            $ps = $row['sabaq'];
                            $psq = $row['sabqi'];
                            $pm = $row['manzil'];
                        ?>
                        <tr>
                            <td><?= $sr ?></td>
                            <td class="fw-bold" style="font-size: 11px;"><?= $fDate ?></td>
                            <td><?= $row['att_badge'] ?></td>
                            <td><?= $ps['parah'] ?></td>
                            <td><?= $ps['amount'] ?></td>
                            <td><?= $ps['status'] ?><?= $ps['time'] ?></td>
                            <td><?= $psq['parah'] ?></td>
                            <td><?= $psq['amount'] ?></td>
                            <td><?= $psq['status'] ?><?= $psq['time'] ?></td>
                            <td><?= $psq['listener'] ?></td>
                            <td><?= $pm['parah'] ?></td>
                            <td><?= $pm['amount'] ?></td>
                            <td><?= $pm['status'] ?><?= $pm['time'] ?></td>
                            <td><?= $pm['listener'] ?></td>
                            <td class="small"><?= $row['complaints'] ?></td>
                        </tr>
                        <?php 
                            $sr++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- ==================== اختتام انفرادی طالب علم ویو ==================== -->

    <?php else: ?>
        <!-- ==================== کلاس کی مجموعی رپورٹ (Class-Wide Report) ==================== -->
        <div class="table-responsive" style="width: 100%; max-width: 100%; border-radius: 8px;">
            <table class="table table-bordered border-dark text-center table-sm align-middle report-table" style="width: 100% !important; max-width: 100% !important; min-width: 1520px; table-layout: fixed !important;">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" style="width: 2.5%;">#</th>
                        <th rowspan="2" style="width: 4%;">رول نمبر</th>
                        <th rowspan="2" style="width: 13.5%;"><?php echo t('student'); ?></th>
                        <th colspan="3" class="bg-success text-white"><?php echo t('sabaq'); ?> (Sabaq)</th>
                        <th colspan="4" class="bg-primary text-white"><?php echo t('sabaq'); ?>ی (Sabqi)</th>
                        <th colspan="4" class="bg-warning text-dark"><?php echo t('manzil'); ?> (Manzil)</th>
                        <th rowspan="2" style="width: 9.5%;" class="bg-danger text-white">شکایات / کیفیت</th>
                    </tr>
                    <tr>
                        <th style="width: 3.5%;">پارہ</th><th style="width: 3.5%;">لائنیں</th><th style="width: 5.5%;">کیفیت</th>
                        <th style="width: 10.5%;">تفصیل</th><th style="width: 4%;">مقدار</th><th style="width: 5.5%;">کیفیت</th><th style="width: 8.5%;">سننے والا</th>
                        <th style="width: 11.5%;">تفصیل</th><th style="width: 4%;">مقدار</th><th style="width: 5.5%;">کیفیت</th><th style="width: 8.5%;">سننے والا</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (count($all_hifz_students) > 0) {
                        foreach ($report_dates as $date) {
                            $formatted_date = translateDateUrduFull($date);
                            echo "<tr class='table-secondary'><td colspan='15' class='fw-bold py-2 bg-light text-center'>تاریخ: {$formatted_date}</td></tr>";
                        
                        $summary_missed_sabaq = 0;
                        $summary_missed_sabqi = 0;
                        $summary_missed_manzil = 0;
                        $summary_absent_cnt = 0;
                        $summary_leave_cnt = 0;
                        $summary_sabaq_absent = 0;
                        $summary_sabaq_leave = 0;
                        $summary_sabqi_absent = 0;
                        $summary_sabqi_leave = 0;
                        $summary_manzil_absent = 0;
                        $summary_manzil_leave = 0;
                        $daily_complaints = 0;
                        
                        $list_absent = []; $list_leave = []; $list_complaints = []; $list_all_students_today = [];
                        $list_sabaq_0 = []; $list_sabaq_A = []; $list_sabaq_L = []; $list_sabaq_missed = []; $list_sabaq_not_recited = [];
                        $list_sabqi_0 = []; $list_sabqi_A = []; $list_sabqi_L = []; $list_sabqi_missed = []; $list_sabqi_not_recited = [];
                        $list_manzil_0 = []; $list_manzil_A = []; $list_manzil_L = []; $list_manzil_missed = []; $list_manzil_not_recited = [];

                        $day_of_week = date('N', strtotime($date));
                        $is_thursday = ($day_of_week == 4);
                        $is_friday = ($day_of_week == 5);
                        
                        $sr_no = 1;
                        foreach ($all_hifz_students as $student) {
                            $student_id = $student['id'];
                            $roll_val = '';
                            foreach (['roll_number', 'admission_number', 'roll_no', 'admission_no', 'gr_number', 'serial_no'] as $rk) {
                                if (!empty($student[$rk]) && trim((string)$student[$rk]) !== '-' && trim((string)$student[$rk]) !== '0') {
                                    $roll_val = trim((string)$student[$rk]);
                                    break;
                                }
                            }
                            $roll_no_display = !empty($roll_val) ? htmlspecialchars($roll_val) : '-';
                            $father_name_disp = htmlspecialchars($student['father_name'] ?: '-');
                            $std_disp = "{$sr_no}. " . htmlspecialchars($student['name']) . " ولد " . $father_name_disp . " (رول: " . $roll_no_display . ")";
                            $list_all_students_today[] = $std_disp;
                            $rec = $hifz_map[$date][$student_id] ?? null;
                            
                            $attStatus = $att_map[$date][$student_id] ?? ['s1'=>'', 's2'=>'', 's3'=>'', 'main'=>''];
                            
                            $as1 = $attStatus['s1'] ?? '';
                            $as2 = $attStatus['s2'] ?? '';
                            $as3 = $attStatus['s3'] ?? '';
                            $asMain = $attStatus['main'] ?? '';

                            $sAdm = !empty($student['admission_date']) ? $norm_date($student['admission_date']) : '';
                            $sGrad = !empty($student['graduation_date']) ? $norm_date($student['graduation_date']) : '';
                            $hasRecOnDay = !empty($rec) || !empty($as1) || !empty($as2) || !empty($as3) || !empty($asMain);
                            if (is_student_inactive($student, $date)) continue;





                            if ($rec) {
                                $chk_hifz_stat = function($st) {
                                    $st = mb_strtoupper(trim((string)$st), 'UTF-8');
                                    if (strpos($st, 'غیر حاضر') !== false || strpos($st, 'غیرحاضر') !== false || strpos($st, 'غائب') !== false || strpos($st, 'ABSENT') !== false || $st === 'A') return 'A';
                                    if (strpos($st, 'رخصت') !== false || strpos($st, 'LEAVE') !== false || $st === 'L') return 'L';
                                    return '';
                                };
                                $st1 = $chk_hifz_stat($rec['sabaq_status'] ?? '');
                                $st2 = $chk_hifz_stat($rec['sabqi_status'] ?? '');
                                $st3 = $chk_hifz_stat($rec['manzil_status'] ?? '');
                                if ($st1) $as1 = $st1;
                                if ($st2) $as2 = $st2;
                                if ($st3) $as3 = $st3;
                            }

                            // Check if student has actual recitation in any period today
                            $chk_has_rec_cls = function($p_type) use (&$rec) {
                                if (!$rec) return false;
                                $m_p = $p_type == 'manzil' ? 'manzil_details' : ($p_type.'_parah');
                                if ($p_type == 'manzil' && empty(trim((string)($rec[$m_p] ?? '')))) {
                                    $m_p = 'manzil_parah';
                                }
                                $m_a = $p_type == 'sabaq' ? 'sabaq_lines' : ($p_type.'_amount');
                                $st_val = trim((string)($rec[$p_type.'_status'] ?? ''));
                                $pa_val = trim((string)($rec[$m_p] ?? ''));
                                $am_val = trim((string)($rec[$m_a] ?? ''));
                                return (!empty($st_val) || !empty($pa_val) || !empty($am_val)) 
                                    && $st_val !== 'ناغہ' && $st_val !== '-' && $st_val !== 'غیر حاضر' && $st_val !== 'Absent' && $st_val !== 'رخصت' && $st_val !== 'Leave' 
                                    && $pa_val !== '-' && $pa_val !== 'غیر حاضر' && $pa_val !== 'رخصت' && $pa_val !== 'ناغہ'
                                    && strpos($st_val, 'نہیں سنا') === false && strpos($pa_val, 'نہیں سنا') === false && strpos($st_val, 'نہیں سنائی') === false && strpos($pa_val, 'نہیں سنائی') === false;
                            };

                            $has_any_rec_cls = $chk_has_rec_cls('sabaq') || $chk_has_rec_cls('sabqi') || $chk_has_rec_cls('manzil');
                            $is_day_present_att_cls = ($asMain === 'P' || $as1 === 'P' || $as2 === 'P' || $as3 === 'P');
                            $is_present_cls = ($has_any_rec_cls || $is_day_present_att_cls);

                            // Per-period process function that prioritizes actual recitation
                            $process_period = function($stat, $prefix) use (&$rec, &$summary_missed_sabaq, &$summary_missed_sabqi, &$summary_missed_manzil, &$freq_sabaq, &$freq_sabqi, &$freq_manzil, &$dates_sabaq_naaghah, &$dates_sabqi_naaghah, &$dates_manzil_naaghah, &$student_recited_sabaq_count, &$student_recited_sabqi_count, &$student_recited_manzil_count, $student_id, $date, $is_thursday, $is_friday, $is_present_cls) {
                                $p = [];
                                if ($is_friday) {
                                    $b = "<span class='text-primary fw-bold'>جمعہ</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'friday';
                                    return $p;
                                }
                                if ($is_thursday && $prefix === 'manzil') {
                                    $b = "<span class='text-info fw-bold'>مشق</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'mashq';
                                    return $p;
                                }

                                $m_p = $prefix == 'manzil' ? 'manzil_details' : ($prefix.'_parah');
                                if ($prefix == 'manzil' && empty(trim($rec[$m_p] ?? ''))) {
                                    $m_p = 'manzil_parah';
                                }
                                $m_a = $prefix == 'sabaq' ? 'sabaq_lines' : ($prefix.'_amount');

                                $st_val = trim((string)($rec[$prefix.'_status'] ?? ''));
                                $pa_val = trim((string)($rec[$m_p] ?? ''));
                                $am_val = trim((string)($rec[$m_a] ?? ''));
                                
                                $is_not_recited = (strpos($st_val, 'نہیں سنا') !== false || strpos($pa_val, 'نہیں سنا') !== false || strpos($st_val, 'نہیں سنائی') !== false || strpos($pa_val, 'نہیں سنائی') !== false);

                                if ($is_not_recited) {
                                    $p['parah'] = "<span class='text-warning text-dark fw-bold'>".cleanUrduStr($rec[$m_p] ?? 'نہیں سنایا')."</span>";
                                    $p['amount'] = htmlspecialchars($rec[$m_a] ?? 'بغیر وجہ');
                                    $p['status'] = "<span class='badge bg-warning text-dark'>نہیں سنایا</span>";
                                    $p['time'] = '';
                                    $p['listener'] = '-';
                                    $p['type'] = 'not_recited';
                                    if ($prefix == 'sabaq') {

                                        $freq_sabaq[$student_id] = ($freq_sabaq[$student_id] ?? 0) + 1;
                                        $dates_sabaq_naaghah[$student_id][] = $date;
                                    } elseif ($prefix == 'sabqi') {

                                        $freq_sabqi[$student_id] = ($freq_sabqi[$student_id] ?? 0) + 1;
                                        $dates_sabqi_naaghah[$student_id][] = $date;
                                    } elseif ($prefix == 'manzil') {
                                        $summary_missed_manzil++;
                                        $freq_manzil[$student_id] = ($freq_manzil[$student_id] ?? 0) + 1;
                                        $dates_manzil_naaghah[$student_id][] = $date;
                                    }
                                    return $p;
                                }

                                $has_recitation = $rec && (!empty($st_val) || !empty($pa_val) || !empty($am_val)) 
                                    && $st_val !== 'ناغہ' && $st_val !== '-' && $st_val !== 'غیر حاضر' && $st_val !== 'Absent' && $st_val !== 'رخصت' && $st_val !== 'Leave' 
                                    && $pa_val !== '-' && $pa_val !== 'غیر حاضر' && $pa_val !== 'رخصت' && $pa_val !== 'ناغہ';

                                if ($has_recitation) {
                                    $p['parah'] = !empty($pa_val) ? cleanUrduStr($rec[$m_p] ?? '') : '-';
                                    $p['amount'] = htmlspecialchars($rec[$m_a] ?? '-');
                                    $p['status'] = !empty($st_val) ? translateStatusClean($rec[$prefix.'_status'] ?? '') : "<span class='badge bg-success'>سنایا</span>";
                                    $p['time'] = !empty($rec[$prefix.'_time']) ? "<br><small class='text-muted' dir='ltr'>".date("h:i A", strtotime($rec[$prefix.'_time']))."</small>" : "";
                                    $p['listener'] = htmlspecialchars($rec[$prefix.'_listener'] ?? '-');
                                    $p['type'] = 'recited';
                                    if ($prefix == 'sabaq') {
                                        $student_recited_sabaq_count[$student_id] = ($student_recited_sabaq_count[$student_id] ?? 0) + 1;
                                    } elseif ($prefix == 'sabqi') {
                                        $student_recited_sabqi_count[$student_id] = ($student_recited_sabqi_count[$student_id] ?? 0) + 1;
                                    } elseif ($prefix == 'manzil') {
                                        $student_recited_manzil_count[$student_id] = ($student_recited_manzil_count[$student_id] ?? 0) + 1;
                                    }
                                    return $p;
                                }

                                if ($stat === 'A' || $st_val === 'غیر حاضر' || $st_val === 'Absent' || $pa_val === 'غیر حاضر') {
                                    $b = "<span class='text-danger fw-bold'>غیر حاضر</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'absent';
                                    return $p;
                                }
                                if ($stat === 'L' || $st_val === 'رخصت' || $st_val === 'Leave' || $pa_val === 'رخصت') {
                                    $b = "<span class='text-success fw-bold'>رخصت</span>";
                                    $p['parah'] = $b; $p['status'] = $b; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'leave';
                                    return $p;
                                }

                                // Explicitly marked naaghah
                                if ($st_val === 'ناغہ' || $pa_val === 'ناغہ') {
                                    if ($prefix == 'sabaq') {
                                        $summary_missed_sabaq++;
                                        $freq_sabaq[$student_id] = ($freq_sabaq[$student_id] ?? 0) + 1;
                                        $dates_sabaq_naaghah[$student_id][] = $date;
                                    } elseif ($prefix == 'sabqi') {
                                        $summary_missed_sabqi++;
                                        $freq_sabqi[$student_id] = ($freq_sabqi[$student_id] ?? 0) + 1;
                                        $dates_sabqi_naaghah[$student_id][] = $date;
                                    } elseif ($prefix == 'manzil') {
                                        $summary_missed_manzil++;
                                        $freq_manzil[$student_id] = ($freq_manzil[$student_id] ?? 0) + 1;
                                        $dates_manzil_naaghah[$student_id][] = $date;
                                    }
                                    $p['status'] = "<span class='badge bg-danger'>ناغہ</span>"; $p['parah'] = '-'; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'missed';
                                    return $p;
                                }

                                // If student was present in class today (marked P or recited in another period) but did not recite this period:
                                if ($is_present_cls) {
                                    $is_mg = (($student['hifz_section'] ?? 'regular') === 'manzil_gardan');
                                    if ($is_mg && ($prefix == 'sabaq' || $prefix == 'sabqi')) {
                                        $p['status'] = "<span class='badge bg-light text-dark border'>استثناء (منزل)</span>";
                                        $p['parah'] = '-'; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-';
                                        $p['type'] = 'exempt';
                                        return $p;
                                    }
                                    if ($prefix == 'sabaq') {
                                        $summary_missed_sabaq++;
                                        $freq_sabaq[$student_id] = ($freq_sabaq[$student_id] ?? 0) + 1;
                                        $dates_sabaq_naaghah[$student_id][] = $date;
                                    } elseif ($prefix == 'sabqi') {
                                        $summary_missed_sabqi++;
                                        $freq_sabqi[$student_id] = ($freq_sabqi[$student_id] ?? 0) + 1;
                                        $dates_sabqi_naaghah[$student_id][] = $date;
                                    } elseif ($prefix == 'manzil') {
                                        $summary_missed_manzil++;
                                        $freq_manzil[$student_id] = ($freq_manzil[$student_id] ?? 0) + 1;
                                        $dates_manzil_naaghah[$student_id][] = $date;
                                    }
                                    $p['status'] = "<span class='badge bg-danger'>ناغہ</span>"; $p['parah'] = '-'; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'missed';
                                    return $p;
                                }

                                // Otherwise unrecorded
                                $p['status'] = "<span class='text-muted small'>-</span>"; $p['parah'] = '-'; $p['amount'] = '-'; $p['time'] = ''; $p['listener'] = '-'; $p['type'] = 'unrecorded';
                                return $p;
                            };

                            $p_sabaq = $process_period($as1 ?: $asMain, 'sabaq');
                            $p_sabqi = $process_period($as2 ?: $asMain, 'sabqi');
                            $p_manzil = $process_period($as3 ?: $asMain, 'manzil');

                            $sabaq_parah = $p_sabaq['parah']; $sabaq_lines = $p_sabaq['amount']; $sabaq_status = $p_sabaq['status']; $sabaq_time = $p_sabaq['time'];
                            $sabqi_parah = $p_sabqi['parah']; $sabqi_amount = $p_sabqi['amount']; $sabqi_status = $p_sabqi['status']; $sabqi_time = $p_sabqi['time']; $sabqi_listener = $p_sabqi['listener'];
                            $manzil_parah = $p_manzil['parah']; $manzil_amount = $p_manzil['amount']; $manzil_status = $p_manzil['status']; $manzil_time = $p_manzil['time']; $manzil_listener = $p_manzil['listener'];

                            // Senders vs Not Recited vs Missed vs Absent vs Leave lists
                            if ($p_sabaq['type'] === 'recited') { $list_sabaq_0[] = $std_disp; }
                            elseif ($p_sabaq['type'] === 'not_recited') { $list_sabaq_not_recited[] = $std_disp . " (" . strip_tags($sabaq_lines) . ")"; }
                            elseif ($p_sabaq['type'] === 'absent') { $summary_sabaq_absent++; $list_sabaq_A[] = $std_disp; }
                            elseif ($p_sabaq['type'] === 'leave') { $summary_sabaq_leave++; $list_sabaq_L[] = $std_disp; }
                            elseif ($p_sabaq['type'] === 'missed') { $list_sabaq_missed[] = $std_disp; }

                            if ($p_sabqi['type'] === 'recited') { $list_sabqi_0[] = $std_disp; }
                            elseif ($p_sabqi['type'] === 'not_recited') { $list_sabqi_not_recited[] = $std_disp . " (" . strip_tags($sabqi_amount) . ")"; }
                            elseif ($p_sabqi['type'] === 'absent') { $summary_sabqi_absent++; $list_sabqi_A[] = $std_disp; }
                            elseif ($p_sabqi['type'] === 'leave') { $summary_sabqi_leave++; $list_sabqi_L[] = $std_disp; }
                            elseif ($p_sabqi['type'] === 'missed') { $list_sabqi_missed[] = $std_disp; }

                            if ($p_manzil['type'] === 'recited') { $list_manzil_0[] = $std_disp; }
                            elseif ($p_manzil['type'] === 'not_recited') { $list_manzil_not_recited[] = $std_disp . " (" . strip_tags($manzil_amount) . ")"; }
                            elseif ($p_manzil['type'] === 'absent') { $summary_manzil_absent++; $list_manzil_A[] = $std_disp; }
                            elseif ($p_manzil['type'] === 'leave') { $summary_manzil_leave++; $list_manzil_L[] = $std_disp; }
                            elseif ($p_manzil['type'] === 'missed') { $list_manzil_missed[] = $std_disp; }

                            // Global day-level absent/leave tracking (only if not recited in all)
                            $has_any_recitation = ($p_sabaq['type'] === 'recited' || $p_sabqi['type'] === 'recited' || $p_manzil['type'] === 'recited');
                            if (!$has_any_recitation) {
                                if ($p_sabaq['type'] === 'absent' || ($p_sabaq['type'] !== 'leave' && $asMain === 'A')) {
                                    $summary_absent_cnt++; $list_absent[] = $std_disp;
                                } elseif ($p_sabaq['type'] === 'leave' || $asMain === 'L') {
                                    $summary_leave_cnt++; $list_leave[] = $std_disp;
                                }
                            }

                            if ($rec && ($asMain !== 'A' && $asMain !== 'L')) {
                                $students_with_records[] = $student_id;
                                $totalSabaq += getGradePoints($rec['sabaq_status'], 30);
                                $totalSabqi += getGradePoints($rec['sabqi_status'], 30);
                                $totalManzil += getGradePoints($rec['manzil_status'], 40);
                                $h_count++;
                            }
                            
                            $comp_key = $student_id . '_' . $date;
                            if (isset($complaints_map[$comp_key])) {
                                $complaints_html = implode('<br><hr class="my-1 border-danger">', $complaints_map[$comp_key]);
                                $daily_complaints += count($complaints_map[$comp_key]);
                                $list_complaints[] = $std_disp . " (" . implode(' | ', $complaints_map[$comp_key]) . ")";
                            } else {
                                $complaints_html = '-';
                            }
                            
                            echo "<tr>
                                    <td>{$sr_no}</td>
                                    <td>{$roll_no_display}</td>
                                    <td class='text-start fw-bold' style='font-size: 11px; line-height: 1.2;'>".htmlspecialchars($student['name'])." ".((($student['hifz_section'] ?? '') === 'manzil_gardan') ? "<span class='badge bg-warning text-dark' style='font-size: 8px;'>منزل/گردان</span>" : "")."<br><span class='text-muted fw-normal' style='font-size: 9px; line-height: 1.1;'>{$father_name_disp}</span></td>
                                    <td>{$sabaq_parah}</td>
                                    <td>{$sabaq_lines}</td>
                                    <td>{$sabaq_status}{$sabaq_time}</td>
                                    <td>{$sabqi_parah}</td>
                                    <td>{$sabqi_amount}</td>
                                    <td>{$sabqi_status}{$sabqi_time}</td>
                                    <td>{$sabqi_listener}</td>
                                    <td>{$manzil_parah}</td>
                                    <td>{$manzil_amount}</td>
                                    <td>{$manzil_status}{$manzil_time}</td>
                                    <td>{$manzil_listener}</td>
                                    <td class='small text-start'>{$complaints_html}</td>
                                  </tr>";
                            $sr_no++;
                        }
                        
                        // Daily Summary Row
                        $total_st = count($all_hifz_students);
                        $sabaq_missed_cnt = count($list_sabaq_missed);
                        $sabqi_missed_cnt = count($list_sabqi_missed);
                        $manzil_missed_cnt = count($list_manzil_missed);
                        $url_base = "hifz_filtered_report.php?class=".urlencode($selected_class)."&start_date=$start_date&end_date=$end_date&filter_date=$date";
                        
                        $sabaq_sender = count($list_sabaq_0);
                        $sabaq_not_recited_cnt = count($list_sabaq_not_recited);
                        $sabqi_sender = count($list_sabqi_0);
                        $sabqi_not_recited_cnt = count($list_sabqi_not_recited);
                        $manzil_sender = count($list_manzil_0);
                        $manzil_not_recited_cnt = count($list_manzil_not_recited);

                        echo "<tr class='border-top border-dark border-2' style='background:#f0f4f8;'>
                            <td colspan='15' class='p-2'>
                              <div class='d-flex justify-content-between align-items-center mb-1' dir='rtl'>
                                <div class='d-flex gap-3'>
                                  <a href='#' onclick='showSummaryModal(\"کل طلبہ (آج)\", \"primary\", ".htmlspecialchars(json_encode(array_values($list_all_students_today)), ENT_QUOTES)."); return false;' class='text-primary fw-bold text-decoration-none' style='font-size:11px;'>تعداد طلبہ: <u>{$total_st}</u></a>
                                  <a href='#' onclick='showSummaryModal(\"مکمل غیر حاضر (آج)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_absent)), ENT_QUOTES)."); return false;' class='text-danger fw-bold text-decoration-none' style='font-size:11px;'>غیر حاضر: <u>{$summary_absent_cnt}</u></a>
                                  <a href='#' onclick='showSummaryModal(\"مکمل رخصت (آج)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_leave)), ENT_QUOTES)."); return false;' class='text-warning fw-bold text-decoration-none' style='font-size:11px;'>رخصت: <u>{$summary_leave_cnt}</u></a>
                                  <a href='#' onclick='showSummaryModal(\"شکایات (آج)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_complaints)), ENT_QUOTES)."); return false;' class='text-danger fw-bold text-decoration-none' style='font-size:11px;'>شکایات: <u>$daily_complaints</u></a>
                                </div>
                                <span class='fw-bold text-dark' style='font-size:12px;'>روزانہ سمری ({$formatted_date})</span>
                              </div>
                              <div class='row g-2' dir='rtl'>
                                <div class='col-md-4'>
                                  <div class='border border-success rounded p-2 text-center shadow-sm' style='font-size:11px; background:rgba(25,135,84,0.06)'>
                                    <div class='fw-bold text-success mb-2 fs-6'><i class='fas fa-book-open me-1'></i> سبق</div>
                                    <div class='d-flex justify-content-between align-items-center text-center'>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"سنانے والے (سبق)\", \"success\", ".htmlspecialchars(json_encode(array_values($list_sabaq_0)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-success rounded-pill px-2 py-1 fs-6'>$sabaq_sender</span>
                                          <div class='text-success fw-bold mt-1' style='font-size:10px;'>سنایا</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"نہ سنانے والے (سبق)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_sabaq_not_recited)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$sabaq_not_recited_cnt</span>
                                          <div class='text-dark fw-bold mt-1' style='font-size:10px;'>نہ سنایا</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"ناغہ (سبق)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_sabaq_missed)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-danger rounded-pill px-2 py-1 fs-6'>$sabaq_missed_cnt</span>
                                          <div class='text-danger fw-bold mt-1' style='font-size:10px;'>ناغہ</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"غیر حاضر (سبق)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_sabaq_A)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'><span class='badge bg-danger rounded-pill px-2 py-1 fs-6'>$summary_sabaq_absent</span><div class='text-danger fw-bold mt-1' style='font-size:10px;'>غیر حاضر</div></a></div><div style='flex:1;'><a href='#' onclick='showSummaryModal(\"رخصت (سبق)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_sabaq_L)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'><span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$summary_sabaq_leave</span><div class='text-dark fw-bold mt-1' style='font-size:10px;'>رخصت</div></a>



                                      </div>
                                    </div>
                                  </div>
                                </div>
                                <div class='col-md-4'>
                                  <div class='border border-primary rounded p-2 text-center shadow-sm' style='font-size:11px; background:rgba(13,110,253,0.06)'>
                                    <div class='fw-bold text-primary mb-2 fs-6'><i class='fas fa-book me-1'></i> سبقی</div>
                                    <div class='d-flex justify-content-between align-items-center text-center'>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"سنانے والے (سبقی)\", \"primary\", ".htmlspecialchars(json_encode(array_values($list_sabqi_0)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-primary rounded-pill px-2 py-1 fs-6'>$sabqi_sender</span>
                                          <div class='text-primary fw-bold mt-1' style='font-size:10px;'>سنایا</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"نہ سنانے والے (سبقی)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_sabqi_not_recited)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$sabqi_not_recited_cnt</span>
                                          <div class='text-dark fw-bold mt-1' style='font-size:10px;'>نہ سنایا</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"ناغہ (سبقی)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_sabqi_missed)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-danger rounded-pill px-2 py-1 fs-6'>$sabqi_missed_cnt</span>
                                          <div class='text-danger fw-bold mt-1' style='font-size:10px;'>ناغہ</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"غیر حاضر (سبقی)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_sabqi_A)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'><span class='badge bg-danger rounded-pill px-2 py-1 fs-6'>$summary_sabqi_absent</span><div class='text-danger fw-bold mt-1' style='font-size:10px;'>غیر حاضر</div></a></div><div style='flex:1;'><a href='#' onclick='showSummaryModal(\"رخصت (سبقی)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_sabqi_L)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'><span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$summary_sabqi_leave</span><div class='text-dark fw-bold mt-1' style='font-size:10px;'>رخصت</div></a>



                                      </div>
                                    </div>
                                  </div>
                                </div>
                                <div class='col-md-4'>
                                  <div class='border border-warning rounded p-2 text-center shadow-sm' style='font-size:11px; background:rgba(255,193,7,0.06)'>
                                    <div class='fw-bold text-dark mb-2 fs-6'><i class='fas fa-quran me-1 text-warning'></i> منزل</div>
                                    <div class='d-flex justify-content-between align-items-center text-center'>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"سنانے والے (منزل)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_manzil_0)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$manzil_sender</span>
                                          <div class='text-dark fw-bold mt-1' style='font-size:10px;'>سنایا</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"نہ سنانے والے (منزل)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_manzil_not_recited)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$manzil_not_recited_cnt</span>
                                          <div class='text-dark fw-bold mt-1' style='font-size:10px;'>نہ سنایا</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"ناغہ (منزل)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_manzil_missed)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'>
                                          <span class='badge bg-danger rounded-pill px-2 py-1 fs-6'>$manzil_missed_cnt</span>
                                          <div class='text-danger fw-bold mt-1' style='font-size:10px;'>ناغہ</div>
                                        </a>
                                      </div>
                                      <div style='flex:1;'>
                                        <a href='#' onclick='showSummaryModal(\"غیر حاضر (منزل)\", \"danger\", ".htmlspecialchars(json_encode(array_values($list_manzil_A)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'><span class='badge bg-danger rounded-pill px-2 py-1 fs-6'>$summary_manzil_absent</span><div class='text-danger fw-bold mt-1' style='font-size:10px;'>غیر حاضر</div></a></div><div style='flex:1;'><a href='#' onclick='showSummaryModal(\"رخصت (منزل)\", \"warning\", ".htmlspecialchars(json_encode(array_values($list_manzil_L)), ENT_QUOTES)."); return false;' class='text-decoration-none d-block'><span class='badge bg-warning text-dark rounded-pill px-2 py-1 fs-6'>$summary_manzil_leave</span><div class='text-dark fw-bold mt-1' style='font-size:10px;'>رخصت</div></a>



                                      </div>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </td>
                          </tr>";

                    }
                } else {
                    echo "<tr><td colspan='14' class='text-danger p-4 fw-bold'>اس کلاس میں کوئی طالب علم نہیں ملا۔</td></tr>";
                }
                
                $students_with_records = array_unique($students_with_records);
                $academicScore = ($h_count > 0) ? round(($totalSabaq + $totalSabqi + $totalManzil) / $h_count) : 0;
                ?>
            </tbody>
        </table>
    </div>

    <?php if (count($report_dates) >= 1): 
        $sabaq_0_list = []; $sabaq_1_list = []; $sabaq_2_list = []; $sabaq_3_list = []; $sabaq_more_list = []; $sabaq_inactive_list = [];
        $sabqi_0_list = []; $sabqi_1_list = []; $sabqi_2_list = []; $sabqi_3_list = []; $sabqi_more_list = []; $sabqi_inactive_list = [];
        $manzil_0_list = []; $manzil_1_list = []; $manzil_2_list = []; $manzil_3_list = []; $manzil_more_list = []; $manzil_inactive_list = [];
        
        $effective_hifz_students = $all_hifz_students;
        $existing_sids = array_column($effective_hifz_students, 'id');
        if (!empty($hifz_map)) {
            foreach ($hifz_map as $d => $dayRecs) {
                foreach ($dayRecs as $sId => $rec) {
                    if (!in_array($sId, $existing_sids)) {
                        $existing_sids[] = $sId;
                        $effective_hifz_students[] = [
                            'id' => $sId,
                            'name' => $rec['student_name'] ?? "طالب علم $sId",
                            'father_name' => $rec['father_name'] ?? '-',
                            'roll_number' => $rec['roll_number'] ?? (!empty($rec['admission_number']) ? $rec['admission_number'] : $sId),
                            'admission_number' => $rec['admission_number'] ?? $sId,
                            'class_name' => $selected_class ?? '',
                            'class' => $selected_class ?? '',
                        ];
                    }
                }
            }
        }
        foreach($effective_hifz_students as $s) {
            if (is_student_inactive($s, !empty($end_date) ? $end_date : date('Y-m-d'))) continue;
            $sid = $s['id'];
            $stData = [
                'id' => $sid,
                'name' => $s['name'],
                'father_name' => !empty($s['father_name']) ? $s['father_name'] : '-',
                'roll_number' => !empty($s['roll_number']) ? $s['roll_number'] : (!empty($s['admission_number']) ? $s['admission_number'] : '-'),
                'class' => $s['class_name'] ?? ($s['class'] ?? ''),
            ];

            // Sabaq
            $s_rec = $student_recited_sabaq_count[$sid] ?? 0;
            $sq = $freq_sabaq[$sid] ?? 0;
            $item_sq = $stData;
            $item_sq['count'] = $sq;
            $item_sq['recited_count'] = $s_rec;
            $item_sq['dates'] = array_values(array_unique($dates_sabaq_naaghah[$sid] ?? []));
            if ($s_rec > 0 && $sq == 0) {
                $item_sq['status_type'] = 'regular';
                $sabaq_0_list[] = $item_sq;
            } elseif ($sq == 1) {
                $item_sq['status_type'] = 'naaghah';
                $sabaq_1_list[] = $item_sq;
            } elseif ($sq == 2) {
                $item_sq['status_type'] = 'naaghah';
                $sabaq_2_list[] = $item_sq;
            } elseif ($sq == 3) {
                $item_sq['status_type'] = 'naaghah';
                $sabaq_3_list[] = $item_sq;
            } elseif ($sq > 3) {
                $item_sq['status_type'] = 'naaghah';
                $sabaq_more_list[] = $item_sq;
            } else {
                // s_rec == 0 and sq == 0 -> Inactive / No lessons recorded in period
                $item_sq['status_type'] = 'inactive';
                $sabaq_inactive_list[] = $item_sq;
            }
            
            // Sabqi
            $sqi_rec = $student_recited_sabqi_count[$sid] ?? 0;
            $sqi = $freq_sabqi[$sid] ?? 0;
            $item_sqi = $stData;
            $item_sqi['count'] = $sqi;
            $item_sqi['recited_count'] = $sqi_rec;
            $item_sqi['dates'] = array_values(array_unique($dates_sabqi_naaghah[$sid] ?? []));
            if ($sqi_rec > 0 && $sqi == 0) {
                $item_sqi['status_type'] = 'regular';
                $sabqi_0_list[] = $item_sqi;
            } elseif ($sqi == 1) {
                $item_sqi['status_type'] = 'naaghah';
                $sabqi_1_list[] = $item_sqi;
            } elseif ($sqi == 2) {
                $item_sqi['status_type'] = 'naaghah';
                $sabqi_2_list[] = $item_sqi;
            } elseif ($sqi == 3) {
                $item_sqi['status_type'] = 'naaghah';
                $sabqi_3_list[] = $item_sqi;
            } elseif ($sqi > 3) {
                $item_sqi['status_type'] = 'naaghah';
                $sabqi_more_list[] = $item_sqi;
            } else {
                $item_sqi['status_type'] = 'inactive';
                $sabqi_inactive_list[] = $item_sqi;
            }
            
            // Manzil
            $mz_rec = $student_recited_manzil_count[$sid] ?? 0;
            $mz = $freq_manzil[$sid] ?? 0;
            $item_mz = $stData;
            $item_mz['count'] = $mz;
            $item_mz['recited_count'] = $mz_rec;
            $item_mz['dates'] = array_values(array_unique($dates_manzil_naaghah[$sid] ?? []));
            if ($mz_rec > 0 && $mz == 0) {
                $item_mz['status_type'] = 'regular';
                $manzil_0_list[] = $item_mz;
            } elseif ($mz == 1) {
                $item_mz['status_type'] = 'naaghah';
                $manzil_1_list[] = $item_mz;
            } elseif ($mz == 2) {
                $item_mz['status_type'] = 'naaghah';
                $manzil_2_list[] = $item_mz;
            } elseif ($mz == 3) {
                $item_mz['status_type'] = 'naaghah';
                $manzil_3_list[] = $item_mz;
            } elseif ($mz > 3) {
                $item_mz['status_type'] = 'naaghah';
                $manzil_more_list[] = $item_mz;
            } else {
                $item_mz['status_type'] = 'inactive';
                $manzil_inactive_list[] = $item_mz;
            }
        }
    ?>
    <div class="card shadow-sm mb-4 border-top border-5 border-info page-break">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-info"><i class="fas fa-chart-bar me-2"></i> مجموعی ناغہ جات سمری (Period Missed Lessons Summary)</h5>
                <small class="text-muted"><i class="fas fa-info-circle me-1"></i> طلبہ کی تفصیلی فہرست اور ناغہ تاریخیں دیکھنے کے لیے تعداد پر کلک کریں</small>
            </div>
            <table class="table table-bordered table-sm text-center mb-0" style="font-size: 14px;">
                <thead class="bg-light">
                    <tr>
                        <th>کیٹیگری</th>
                        <th class="text-success">سبق کا ناغہ</th>
                        <th class="text-primary">سبقی کا ناغہ</th>
                        <th class="text-warning text-dark">منزل کا ناغہ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-success">
                        <td class="fw-bold text-success"><i class="fas fa-check-circle me-1"></i> بلاناغہ (0 ناغہ)</td>
                        <td class="fw-bold text-success">
                            <?php if(!empty($sabaq_0_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبق بلاناغہ طلبہ (0 ناغہ)", <?php echo htmlspecialchars(json_encode($sabaq_0_list), ENT_QUOTES); ?>)' class="text-success text-decoration-underline" style="cursor:pointer;" title="کلک کر کے بلاناغہ طلبہ کی فہرست دیکھیں">
                                    <?php echo count($sabaq_0_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-success">
                            <?php if(!empty($sabqi_0_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبقی بلاناغہ طلبہ (0 ناغہ)", <?php echo htmlspecialchars(json_encode($sabqi_0_list), ENT_QUOTES); ?>)' class="text-success text-decoration-underline" style="cursor:pointer;" title="کلک کر کے بلاناغہ طلبہ کی فہرست دیکھیں">
                                    <?php echo count($sabqi_0_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-success">
                            <?php if(!empty($manzil_0_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("منزل بلاناغہ طلبہ (0 ناغہ)", <?php echo htmlspecialchars(json_encode($manzil_0_list), ENT_QUOTES); ?>)' class="text-success text-decoration-underline" style="cursor:pointer;" title="کلک کر کے بلاناغہ طلبہ کی فہرست دیکھیں">
                                    <?php echo count($manzil_0_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold">صرف 1 ناغہ</td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabaq_1_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبق کا ناغہ — صرف 1 ناغہ", <?php echo htmlspecialchars(json_encode($sabaq_1_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabaq_1_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabqi_1_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبقی کا ناغہ — صرف 1 ناغہ", <?php echo htmlspecialchars(json_encode($sabqi_1_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabqi_1_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($manzil_1_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("منزل کا ناغہ — صرف 1 ناغہ", <?php echo htmlspecialchars(json_encode($manzil_1_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($manzil_1_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold">2 ناغے</td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabaq_2_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبق کا ناغہ — 2 ناغے", <?php echo htmlspecialchars(json_encode($sabaq_2_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabaq_2_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabqi_2_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبقی کا ناغہ — 2 ناغے", <?php echo htmlspecialchars(json_encode($sabqi_2_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabqi_2_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($manzil_2_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("منزل کا ناغہ — 2 ناغے", <?php echo htmlspecialchars(json_encode($manzil_2_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($manzil_2_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-bold">3 ناغے</td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabaq_3_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبق کا ناغہ — 3 ناغے", <?php echo htmlspecialchars(json_encode($sabaq_3_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabaq_3_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabqi_3_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبقی کا ناغہ — 3 ناغے", <?php echo htmlspecialchars(json_encode($sabqi_3_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabqi_3_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($manzil_3_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("منزل کا ناغہ — 3 ناغے", <?php echo htmlspecialchars(json_encode($manzil_3_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($manzil_3_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="table-danger">
                        <td class="fw-bold text-danger">3 سے زیادہ ناغے</td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabaq_more_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبق کا ناغہ — 3 سے زیادہ ناغے", <?php echo htmlspecialchars(json_encode($sabaq_more_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabaq_more_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($sabqi_more_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبقی کا ناغہ — 3 سے زیادہ ناغے", <?php echo htmlspecialchars(json_encode($sabqi_more_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($sabqi_more_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php if(!empty($manzil_more_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("منزل کا ناغہ — 3 سے زیادہ ناغے", <?php echo htmlspecialchars(json_encode($manzil_more_list), ENT_QUOTES); ?>)' class="text-danger text-decoration-underline" style="cursor:pointer;" title="کلک کر کے طلبہ اور تاریخیں دیکھیں">
                                    <?php echo count($manzil_more_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="table-light border-top border-2">
                        <td class="fw-bold text-secondary"><i class="fas fa-user-slash me-1"></i> بغیر تعلیمی ریکارڈ / غیر فعال طلبہ</td>
                        <td class="fw-bold text-secondary">
                            <?php if(!empty($sabaq_inactive_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبق کا کوئی ریکارڈ نہیں / غیر فعال طلبہ", <?php echo htmlspecialchars(json_encode($sabaq_inactive_list), ENT_QUOTES); ?>)' class="text-secondary text-decoration-underline" style="cursor:pointer;" title="کلک کر کے غیر فعال طلبہ کی فہرست دیکھیں">
                                    <?php echo count($sabaq_inactive_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-secondary">
                            <?php if(!empty($sabqi_inactive_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("سبقی کا کوئی ریکارڈ نہیں / غیر فعال طلبہ", <?php echo htmlspecialchars(json_encode($sabqi_inactive_list), ENT_QUOTES); ?>)' class="text-secondary text-decoration-underline" style="cursor:pointer;" title="کلک کر کے غیر فعال طلبہ کی فہرست دیکھیں">
                                    <?php echo count($sabqi_inactive_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-secondary">
                            <?php if(!empty($manzil_inactive_list)): ?>
                                <a href="javascript:void(0)" onclick='openNaaghahDetailModal("منزل کا کوئی ریکارڈ نہیں / غیر فعال طلبہ", <?php echo htmlspecialchars(json_encode($manzil_inactive_list), ENT_QUOTES); ?>)' class="text-secondary text-decoration-underline" style="cursor:pointer;" title="کلک کر کے غیر فعال طلبہ کی فہرست دیکھیں">
                                    <?php echo count($manzil_inactive_list); ?> طلبہ <i class="fas fa-external-link-alt ms-1" style="font-size:10px;"></i>
                                </a>
                            <?php else: ?>
                                0 طلبہ
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $students_with_records = array_unique($students_with_records);
    $lazy_students = [];
    foreach($all_hifz_students as $hs) {
        if(!in_array($hs['id'], $students_with_records)) {
            $lazy_students[] = $hs;
        }
    }
    usort($lazy_students, function($a, $b) {
        return $a['admission_number'] <=> $b['admission_number'];
    });
    ?>
    
    <?php if(count($lazy_students) > 0): ?>
    <div class="mt-5">
        <h4 class="text-danger border-bottom border-danger pb-2 fw-bold"><i class="fas fa-exclamation-circle me-2"></i> سست طلبہ (جن کی کوئی رپورٹ درج نہیں ہوئی)</h4>
        <table class="table table-bordered border-danger text-center table-sm mt-3" style="font-size: 13px;">
            <thead class="bg-danger text-white">
                <tr>
                    <th style="width: 10%;">رول نمبر</th>
                    <th style="width: 30%;">نام <?php echo t('student'); ?></th>
                    <th style="width: 30%;">ولدیت</th>
                    <th style="width: 30%;">کیفیت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($lazy_students as $ls): ?>
                <tr>
                    <td class="fw-bold bg-light h6 m-0"><?php echo $ls['admission_number']; ?></td>
                    <td class="text-start ps-3 fw-bold"><?php echo $ls['name']; ?></td>
                    <td class="text-start ps-3 text-muted"><?php echo $ls['father_name']; ?></td>
                    <td class="text-danger fw-bold">کوئی <?php echo t('sabaq'); ?> نہیں سنایا</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if($h_count > 0): ?>
    <div class="row mt-4">
        <div class="col-md-4 text-center">
            <div class="card p-3 shadow-sm border-0 bg-light">
                <h6 class="fw-bold mb-3">حفظ کی مجموعی کارکردگی</h6>
                <div class="gauge-container">
                    <svg class="gauge-svg" viewBox="0 0 100 100">
                        <circle class="gauge-bg" cx="50" cy="50" r="40"></circle>
                        <circle class="gauge-fill" cx="50" cy="50" r="40" style="stroke-dasharray: <?php echo ($academicScore * 2.51); ?>, 251.2;"></circle>
                    </svg>
                    <div class="gauge-text"><?php echo $academicScore; ?>%</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; // End selected_student_id check ?>
</div>

<div class="modal fade" id="dynamicSummaryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content text-end">
            <div class="modal-header" id="dynamicSummaryModalHeader">
                <h5 class="modal-title fw-bold text-white mb-0" id="dynamicSummaryModalTitle">فہرست</h5>
                <div class="d-flex align-items-center gap-2 ms-0 me-auto">
                    <button type="button" class="btn btn-light btn-sm fw-bold shadow-sm" onclick="printDynamicSummaryModal()" title="پرنٹ کریں">
                        <i class="fas fa-print me-1 text-dark"></i> پرنٹ
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <ul class="list-group list-group-flush text-end" id="dynamicSummaryModalList">
                </ul>
            </div>
            <div class="modal-footer justify-content-between bg-light">
                <span class="text-muted small fw-bold" id="dynamicSummaryModalTotalCount">کل طلبہ: 0</span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">بند کریں</button>
                    <button type="button" class="btn btn-dark btn-sm fw-bold shadow-sm" onclick="printDynamicSummaryModal()">
                        <i class="fas fa-print me-1"></i> پرنٹ نکالیں
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ناغہ جات تفصیلی و پرنٹ ایبل موڈل -->
<div class="modal fade" id="naaghahReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content text-end">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold mb-0" id="naaghahModalTitle"><i class="fas fa-calendar-times me-2"></i> ناغہ جات تفصیلی سمری رپورٹ</h5>
                <div class="d-flex align-items-center gap-2 ms-0 me-auto">
                    <button type="button" class="btn btn-light btn-sm fw-bold shadow-sm" onclick="printNaaghahDetailReport()" title="پرنٹ کریں">
                        <i class="fas fa-print me-1 text-dark"></i> پرنٹ
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-3" id="naaghahModalPrintArea">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <h5 class="fw-bold text-danger mb-1" id="naaghahModalSubtitle">کیٹیگری</h5>
                        <small class="text-muted"><?php echo htmlspecialchars($app_name); ?> — ناغہ کرنے والے طلبہ اور متعلقہ تاریخوں کی فہرست</small>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold no-print" onclick="printNaaghahDetailReport()">
                        <i class="fas fa-print me-1"></i> یہ رپورٹ پرنٹ کریں
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover text-center align-middle mb-0" style="font-size: 13px;" id="naaghahModalTable">
                        <thead class="bg-light">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 15%;">رول نمبر</th>
                                <th style="width: 25%; text-align: right;">نام طالب علم</th>
                                <th style="width: 20%; text-align: right;">ولدیت</th>
                                <th style="width: 10%;">کل ناغے</th>
                                <th style="width: 25%; text-align: right;">ناغہ کی تاریخیں</th>
                            </tr>
                        </thead>
                        <tbody id="naaghahModalBody">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="text-muted small fw-bold" id="naaghahModalTotalCount">کل طلبہ: 0</span>
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">بند کریں</button>
                    <button type="button" class="btn btn-danger btn-sm fw-bold shadow-sm" onclick="printNaaghahDetailReport()">
                        <i class="fas fa-print me-1"></i> پرنٹ نکالیں
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var currentNaaghahTitle = '';
var currentNaaghahList = [];
var currentSummaryTitle = '';
var currentSummaryItems = [];
var currentSummaryColor = '';

function showSummaryModal(title, colorClass, items) {
    currentSummaryTitle = title;
    currentSummaryItems = items || [];
    currentSummaryColor = colorClass || 'primary';
    document.getElementById('dynamicSummaryModalTitle').innerText = title + ' (' + currentSummaryItems.length + ')';
    document.getElementById('dynamicSummaryModalHeader').className = 'modal-header bg-' + colorClass + ' text-white';
    var totalEl = document.getElementById('dynamicSummaryModalTotalCount');
    if (totalEl) totalEl.innerText = 'کل طلبہ: ' + currentSummaryItems.length;
    var listHtml = '';
    if (currentSummaryItems.length === 0) {
        listHtml = '<li class="list-group-item text-muted text-center fw-bold py-3">کوئی طالب علم نہیں</li>';
    } else {
        for (var i = 0; i < currentSummaryItems.length; i++) {
            listHtml += '<li class="list-group-item d-flex justify-content-between align-items-center py-2">' +
                '<span><i class="fas fa-user text-' + colorClass + ' ms-2"></i> ' + currentSummaryItems[i] + '</span>' +
                '<span class="badge bg-light text-dark border">' + (i + 1) + '</span>' +
                '</li>';
        }
    }
    document.getElementById('dynamicSummaryModalList').innerHTML = listHtml;
    var mod = bootstrap.Modal.getOrCreateInstance(document.getElementById('dynamicSummaryModal'));
    mod.show();
}

function openNaaghahDetailModal(title, list) {
    currentNaaghahTitle = title;
    currentNaaghahList = list || [];
    var isZeroNaaghah = title.indexOf('بلاناغہ') !== -1;
    var isInactive = title.indexOf('غیر فعال') !== -1 || title.indexOf('کوئی ریکارڈ نہیں') !== -1;
    document.getElementById('naaghahModalTitle').innerHTML = '<i class="fas fa-calendar-times me-2"></i> ' + title + ' (' + currentNaaghahList.length + ' طلبہ)';
    var modalHeader = document.querySelector('#naaghahReportModal .modal-header');
    if (modalHeader) {
        if (isZeroNaaghah) modalHeader.className = 'modal-header text-white bg-success';
        else if (isInactive) modalHeader.className = 'modal-header text-white bg-secondary';
        else modalHeader.className = 'modal-header text-white bg-danger';
    }
    var subEl = document.getElementById('naaghahModalSubtitle');
    if (subEl) {
        if (isZeroNaaghah) subEl.className = 'fw-bold mb-1 text-success';
        else if (isInactive) subEl.className = 'fw-bold mb-1 text-secondary';
        else subEl.className = 'fw-bold mb-1 text-danger';
        subEl.innerText = title;
    }
    document.getElementById('naaghahModalTotalCount').innerText = 'کل طلبہ: ' + currentNaaghahList.length;
    var tbody = document.getElementById('naaghahModalBody');
    if (!currentNaaghahList || currentNaaghahList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">کوئی طالب علم نہیں ملا۔</td></tr>';
    } else {
        var html = '';
        for (var i = 0; i < currentNaaghahList.length; i++) {
            var st = currentNaaghahList[i];
            var datesHtml = '';
            var badgeHtml = '';
            if (st.status_type === 'inactive' || isInactive) {
                datesHtml = '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 py-1 px-2" style="font-size:11px;"><i class="fas fa-info-circle me-1"></i> اس دورانیہ میں کوئی نیا سبق درج نہیں ہوا</span>';
                badgeHtml = '<span class="badge bg-secondary rounded-pill">کوئی سبق درج نہیں</span>';
            } else if (st.count === 0 || (!st.dates || st.dates.length === 0)) {
                datesHtml = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 py-1 px-2" style="font-size:11px;"><i class="fas fa-star me-1 text-warning"></i> ماشاءاللہ پابند (کوئی ناغہ نہیں)</span>';
                badgeHtml = '<span class="badge bg-success rounded-pill"><i class="fas fa-check me-1"></i> بلاناغہ</span>';
            } else {
                datesHtml = '<span class="text-danger fw-bold" style="font-size:12px;">' + (st.dates ? st.dates.join(' ، ') : '-') + '</span>';
                badgeHtml = '<span class="badge bg-danger rounded-pill">' + st.count + ' ناغے</span>';
            }
            html += '<tr>' +
                '<td class="fw-bold">' + (i + 1) + '</td>' +
                '<td class="fw-bold">' + (st.roll_number || '-') + '</td>' +
                '<td class="text-start fw-bold">' + st.name + '</td>' +
                '<td class="text-start">' + (st.father_name || '-') + '</td>' +
                '<td>' + badgeHtml + '</td>' +
                '<td class="text-start">' + datesHtml + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html;
    }
    var mod = bootstrap.Modal.getOrCreateInstance(document.getElementById('naaghahReportModal'));
    mod.show();
}

function executeCleanPrint(title, contentHtml) {
    var iframe = document.getElementById('report_print_frame');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'report_print_frame';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);
    }
    var doc = iframe.contentWindow.document;
    doc.open();
    doc.write('<!DOCTYPE html><html dir="rtl" lang="ur"><head><meta charset="UTF-8"><title>' + title + '</title>');
    doc.write('<style>');
    doc.write('@page { size: A4 portrait; margin: 12mm; }');
    doc.write('body { font-family: "Noto Nastaliq Urdu", Tahoma, Arial, sans-serif; direction: rtl; text-align: right; margin: 0; padding: 10px; font-size: 11pt; color: #111; }');
    doc.write('.print-header { text-align: center; border-bottom: 2px solid #284D3D; padding-bottom: 10px; margin-bottom: 15px; }');
    doc.write('.print-header h2 { margin: 0; color: #284D3D; font-size: 18pt; font-weight: bold; }');
    doc.write('.print-header h3 { margin: 6px 0 0 0; color: #1a1a1a; font-size: 14pt; }');
    doc.write('.print-meta { margin: 6px 0 0 0; color: #555; font-size: 9.5pt; font-weight: bold; }');
    doc.write('table { width: 100%; border-collapse: collapse; margin-top: 10px; }');
    doc.write('th { background: #f2f4f7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: 1px solid #444; padding: 7px 8px; font-size: 10.5pt; text-align: center; font-weight: bold; }');
    doc.write('td { border: 1px solid #666; padding: 6px 8px; font-size: 10pt; }');
    doc.write('.badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9pt; }');
    doc.write('</style></head><body>');
    doc.write(contentHtml);
    doc.write('</body></html>');
    doc.close();
    setTimeout(function() {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch(e) {
            console.error("Iframe print error", e);
        }
    }, 250);
}

function printDynamicSummaryModal() {
    var title = currentSummaryTitle || 'خلاصہ فہرست';
    var items = currentSummaryItems || [];
    var madrasaName = <?php echo json_encode($app_name ?? 'مدرسہ اشرف المدارس', JSON_UNESCAPED_UNICODE); ?>;
    var className = <?php echo json_encode($selected_class ?? '', JSON_UNESCAPED_UNICODE); ?>;
    
    var rowsHtml = '';
    for (var i = 0; i < items.length; i++) {
        rowsHtml += '<tr>' +
            '<td style="padding:6px; border:1px solid #666; text-align:center; width:10%; font-weight:bold;">' + (i + 1) + '</td>' +
            '<td style="padding:6px; border:1px solid #666; text-align:right; font-weight:bold;">' + items[i] + '</td>' +
            '</tr>';
    }
    if (items.length === 0) {
        rowsHtml = '<tr><td colspan="2" style="padding:15px; text-align:center; border:1px solid #666;">کوئی طالب علم نہیں ملا۔</td></tr>';
    }
    
    var contentHtml = '<div class="print-header">' +
        '<h2>' + madrasaName + '</h2>' +
        '<h3>' + title + '</h3>' +
        '<p class="print-meta">کلاس: ' + className + ' | تاریخ پرنٹ: ' + new Date().toLocaleDateString('en-GB') + ' | کل تعداد: ' + items.length + '</p>' +
        '</div>' +
        '<table>' +
        '<thead><tr><th style="width:10%;">#</th><th style="text-align:right;">طالب علم کی تفصیل</th></tr></thead>' +
        '<tbody>' + rowsHtml + '</tbody>' +
        '</table>';
        
    executeCleanPrint(title, contentHtml);
}

function printNaaghahDetailReport() {
    var title = currentNaaghahTitle || 'ناغہ جات سمری رپورٹ';
    var list = currentNaaghahList || [];
    var isZeroNaaghah = title.indexOf('بلاناغہ') !== -1;
    var isInactiveReport = title.indexOf('غیر فعال') !== -1 || title.indexOf('کوئی ریکارڈ نہیں') !== -1;
    var madrasaName = <?php echo json_encode($app_name ?? 'مدرسہ اشرف المدارس', JSON_UNESCAPED_UNICODE); ?>;
    var className = <?php echo json_encode($selected_class ?? '', JSON_UNESCAPED_UNICODE); ?>;
    
    var rowsHtml = '';
    for (var i = 0; i < list.length; i++) {
        var st = list[i];
        var isInactive = (st.status_type === 'inactive');
        var isZero = !isInactive && (st.count === 0 || !st.dates || st.dates.length === 0);
        var datesStr = '';
        var countStr = '';
        if (isInactive) {
            datesStr = '<span style="color:#6c757d;">اس دورانیہ میں کوئی نیا سبق درج نہیں ہوا</span>';
            countStr = '<span style="color:#6c757d; font-weight:bold;">غیر فعال</span>';
        } else if (isZero) {
            datesStr = '<span style="color:#198754; font-weight:bold;">ماشاءاللہ باقاعدہ و پابند</span>';
            countStr = '<span style="color:#198754; font-weight:bold;">0 (بلاناغہ)</span>';
        } else {
            datesStr = (st.dates ? st.dates.join(' ، ') : '-');
            countStr = '<span style="color:#dc3545; font-weight:bold;">' + (st.count || 0) + '</span>';
        }

        rowsHtml += '<tr>' +
            '<td style="padding:6px; border:1px solid #666; text-align:center;">' + (i + 1) + '</td>' +
            '<td style="padding:6px; border:1px solid #666; text-align:center; font-weight:bold;">' + (st.roll_number || '-') + '</td>' +
            '<td style="padding:6px; border:1px solid #666; text-align:right; font-weight:bold;">' + st.name + '</td>' +
            '<td style="padding:6px; border:1px solid #666; text-align:right;">' + (st.father_name || '-') + '</td>' +
            '<td style="padding:6px; border:1px solid #666; text-align:center;">' + countStr + '</td>' +
            '<td style="padding:6px; border:1px solid #666; text-align:right; font-size:9.5pt;">' + datesStr + '</td>' +
            '</tr>';
    }
    if (list.length === 0) {
        rowsHtml = '<tr><td colspan="6" style="padding:15px; text-align:center; border:1px solid #666;">کوئی طالب علم نہیں ملا۔</td></tr>';
    }
    
    var headerColor = isZeroNaaghah ? '#198754' : (isInactiveReport ? '#6c757d' : '#dc3545');
    var contentHtml = '<div class="print-header">' +
        '<h2>' + madrasaName + '</h2>' +
        '<h3 style="color:' + headerColor + ';">' + title + '</h3>' +
        '<p class="print-meta">کلاس: ' + className + ' | تاریخ پرنٹ: ' + new Date().toLocaleDateString('en-GB') + ' | کل طلبہ: ' + list.length + '</p>' +
        '</div>' +
        '<table>' +
        '<thead><tr><th style="width:5%;">#</th><th style="width:12%;">رول نمبر</th><th style="width:25%; text-align:right;">نام طالب علم</th><th style="width:20%; text-align:right;">ولدیت</th><th style="width:10%;">کل ناغے</th><th style="width:28%; text-align:right;">ناغہ کی تاریخیں</th></tr></thead>' +
        '<tbody>' + rowsHtml + '</tbody>' +
        '</table>';
        
    executeCleanPrint(title, contentHtml);
}

// کی بورڈ شارٹ کٹ Ctrl+P کے ذریعے بھی اوپن موڈل پرنٹ کرنے کی سہولت
window.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
        var naaghahModal = document.getElementById('naaghahReportModal');
        if (naaghahModal && (naaghahModal.classList.contains('show') || naaghahModal.style.display === 'block')) {
            e.preventDefault();
            printNaaghahDetailReport();
            return;
        }
        var summaryModal = document.getElementById('dynamicSummaryModal');
        if (summaryModal && (summaryModal.classList.contains('show') || summaryModal.style.display === 'block')) {
            e.preventDefault();
            printDynamicSummaryModal();
            return;
        }
    }
});
</script>

<?php include 'footer.php'; ?>
