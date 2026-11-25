<?php
require_once 'db.php';

// خلاصہ شیٹ (Summary Sheet) — پوری کلاس کی ایک ہی شیٹ میں سبق/سبقی/منزل و حاضری کے مجموعے
// گنتی کی تعریفیں عین hifz_report.php کی انفرادی رپورٹ والی ہیں تاکہ اعداد ہمیشہ یکساں رہیں۔

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
}
$app_name = !empty($settings['madrasa_name']) ? $settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : "مدرسہ مینجمنٹ سسٹم");

$period = $_GET['period'] ?? 'current_week';
$start_date = $_GET['from'] ?? '';
$end_date = $_GET['to'] ?? '';
$hifz_section_filter = $_GET['hifz_section'] ?? 'all';
// ناغہ کیٹیگری فلٹر (جز + کیٹیگری) — hifz_report کی مجموعی ناغہ سمری جیسا ہی
$filter_comp = $_GET['comp'] ?? 'sabaq';
if (!in_array($filter_comp, ['sabaq', 'sabqi', 'manzil'], true)) $filter_comp = 'sabaq';
$filter_cat = $_GET['cat'] ?? '';
if (!in_array($filter_cat, ['', '0', '1', '2', '3', 'more', 'inactive'], true)) $filter_cat = '';
// ترتیب (سارٹ) — ڈیفالٹ: کارکردگی (کم ناغے پہلے)
$sort_by = $_GET['sort'] ?? 'perf_asc';
if (!in_array($sort_by, ['perf_asc', 'perf_desc', 'name_asc', 'name_desc', 'adm_asc', 'adm_desc'], true)) $sort_by = 'perf_asc';

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

$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys_type === 'Masjid') ? "(s.system_type = 'Masjid')" : "(s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')";

try {
    $class_id = 0;
    try {
        $cStmt = $pdo->prepare("SELECT id FROM classes WHERE (name = ? OR TRIM(name) = ?) AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
        $cStmt->execute([$selected_class, trim($selected_class), $cur_tenant_id]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($cRow) $class_id = (int)$cRow['id'];
    } catch (Exception $eC) {}

    $dh_sids = [];
    try {
        $dhStmt = $pdo->prepare("SELECT DISTINCT student_id FROM daily_hifz WHERE (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
        $dhStmt->execute([$cur_tenant_id]);
        $dh_sids = $dhStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $eDh) {}

    $studentCols = [];
    try {
        $studentCols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
    $hasClassName = in_array('class_name', $studentCols, true);

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

    if (empty($all_class_students) && $sys_type === 'Masjid') {
        try {
            $m_stmt = $pdo->prepare("
                SELECT s.*, $student_roll_select
                FROM maktab_students s
                WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL)
                  AND (s.tenant_id = ? OR s.tenant_id IS NULL OR s.tenant_id = 0)
                  AND (s.class = ? OR TRIM(s.class) = ? OR s.class LIKE ?)
                ORDER BY s.name ASC
            ");
            $m_stmt->execute([$cur_tenant_id, $selected_class, trim($selected_class), '%' . trim($selected_class) . '%']);
            $all_class_students = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eM) {}
    }

    if (empty($all_class_students) && !empty($dh_sids)) {
        $inDh = implode(',', array_map('intval', $dh_sids));
        try {
            $dhStdStmt = $pdo->prepare("SELECT s.*, $student_roll_select FROM students s WHERE s.id IN ($inDh) AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND $sys_cond AND $classMatchSql ORDER BY s.name ASC");
            $dhStdStmt->execute($matchParams);
            $all_class_students = $dhStdStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eDhStds) {}
    }

    if ($hifz_section_filter !== 'all') {
        $all_class_students = array_values(array_filter($all_class_students, function($st) use ($hifz_section_filter) {
            $sec = $st['hifz_section'] ?? 'regular';
            if (empty($sec)) $sec = 'regular';
            return $sec === $hifz_section_filter;
        }));
    }

    $ref_end = !empty($end_date) ? $end_date : date('Y-m-d');
    $filtered_active = array_values(array_filter($all_class_students, function($st) use ($ref_end) {
        return !is_student_inactive($st, $ref_end);
    }));
    $all_hifz_students = !empty($filtered_active) ? $filtered_active : $all_class_students;
} catch (PDOException $e) {
    echo "<div class='container mt-5'><div class='alert alert-danger'><strong>ڈیٹا بیس ایرر (Students):</strong> " . $e->getMessage() . "</div></div>";
    $all_class_students = [];
    $all_hifz_students = [];
}

// Date normalization helper (hifz_report.php جیسا ہی)
$norm_date = function($dRaw) {
    $dRaw = explode(' ', trim((string)$dRaw))[0];
    if (empty($dRaw)) return '';
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $dRaw, $m)) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    $ts = strtotime($dRaw);
    return $ts ? date('Y-m-d', $ts) : $dRaw;
};

// Fetch Hifz records for class students
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

// Fetch Attendance (attendance + period_attendance + maktab_attendance)
$hifz_student_ids = array_map(function($st) { return (int)$st['id']; }, $all_hifz_students);
$att_map = [];

if (!empty($hifz_student_ids)) {
    $in_clause = implode(',', $hifz_student_ids);

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

// ===== فی طالب علم خلاصہ — انفرادی رپورٹ کی گنتی کا عین پورٹ =====
if (!function_exists('sheet_grade_points')) {
    function sheet_grade_points($grade, $max) {
        if (!$grade) return 0;
        $g = trim($grade);
        if (in_array($g, ['Excellent', 'ممتاز', 'بہترین', 'اعلیٰ'])) return $max;
        if (in_array($g, ['Good', 'بہتر', 'اچھا'])) return $max * 0.8;
        if (in_array($g, ['Average', 'معمولی', 'مناسب'])) return $max * 0.6;
        if (in_array($g, ['Weak', 'کمزور', 'کچا', 'توجہ طلب'])) return $max * 0.4;
        return 0;
    }
}
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

$report_dates = [];
for ($ct = strtotime($end_date); $ct >= strtotime($start_date); $ct -= 86400) {
    $report_dates[] = date('Y-m-d', $ct);
}

$sheet_rows = [];
foreach ($all_hifz_students as $st) {
    $sid = (int)$st['id'];
    $is_gardan = (($st['hifz_section'] ?? 'regular') === 'manzil_gardan');

    $c = [
        'sabaq_lines' => 0, 'sabaq_nagha' => 0, 'sabaq_days' => 0,
        'sabqi_days' => 0, 'sabqi_nagha' => 0,
        'manzil_paras' => 0.0, 'manzil_nagha' => 0, 'manzil_days' => 0,
        'present' => 0, 'absent' => 0, 'leave' => 0, 'unrecorded' => 0,
        'score_sabaq' => 0.0, 'score_sabqi' => 0.0, 'score_manzil' => 0.0,
        'active_days' => 0, 'manzil_active_days' => 0,
    ];

    foreach ($report_dates as $date) {
        if (is_student_inactive($st, $date)) continue;
        $rec = $hifz_map[$date][$sid] ?? null;
        $att = $att_map[$date][$sid] ?? ['s1'=>'', 's2'=>'', 's3'=>'', 'main'=>''];

        $dow = date('N', strtotime($date));
        $is_thursday = ($dow == 4);
        $is_friday = ($dow == 5);

        $as1 = $att['s1'] ?? ''; $as2 = $att['s2'] ?? ''; $as3 = $att['s3'] ?? ''; $asMain = $att['main'] ?? '';

        if ($rec) {
            $chk_hifz_stat = function($s) {
                $s = mb_strtoupper(trim((string)$s), 'UTF-8');
                if (strpos($s, 'غیر حاضر') !== false || strpos($s, 'غیرحاضر') !== false || strpos($s, 'غائب') !== false || strpos($s, 'ABSENT') !== false || $s === 'A') return 'A';
                if (strpos($s, 'رخصت') !== false || strpos($s, 'LEAVE') !== false || $s === 'L') return 'L';
                return '';
            };
            $t1 = $chk_hifz_stat($rec['sabaq_status'] ?? ''); if ($t1) $as1 = $t1;
            $t2 = $chk_hifz_stat($rec['sabqi_status'] ?? ''); if ($t2) $as2 = $t2;
            $t3 = $chk_hifz_stat($rec['manzil_status'] ?? ''); if ($t3) $as3 = $t3;
        }

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

        $has_any_rec = ($chk_has_rec('sabaq') || $chk_has_rec('sabqi') || $chk_has_rec('manzil'));
        $is_day_leave = ($asMain === 'L' || $as1 === 'L' || $as2 === 'L' || $as3 === 'L');
        $is_day_absent = ($asMain === 'A' || $as1 === 'A' || $as2 === 'A' || $as3 === 'A');
        $is_day_present_att = ($asMain === 'P' || $as1 === 'P' || $as2 === 'P' || $as3 === 'P');
        $is_present = ($has_any_rec || $is_day_present_att);

        if (!$is_friday) {
            if ($is_present) $c['present']++;
            elseif ($is_day_leave) $c['leave']++;
            elseif ($is_day_absent) $c['absent']++;
            else $c['unrecorded']++;
        }

        if ($is_present) {
            $classify = function($prefix, $stat) use (&$rec, $is_friday, $is_thursday, $is_present, $is_gardan) {
                if ($is_friday) return 'friday';
                if ($is_thursday && $prefix === 'manzil') return 'mashq';
                $m_p = $prefix == 'manzil' ? 'manzil_details' : ($prefix.'_parah');
                if ($prefix == 'manzil' && empty(trim((string)($rec[$m_p] ?? '')))) {
                    $m_p = 'manzil_parah';
                }
                $m_a = $prefix == 'sabaq' ? 'sabaq_lines' : ($prefix.'_amount');
                $st_val = trim((string)($rec[$prefix.'_status'] ?? ''));
                $pa_val = trim((string)($rec[$m_p] ?? ''));
                $am_val = trim((string)($rec[$m_a] ?? ''));

                if (strpos($st_val, 'نہیں سنا') !== false || strpos($pa_val, 'نہیں سنا') !== false || strpos($st_val, 'نہیں سنائی') !== false || strpos($pa_val, 'نہیں سنائی') !== false) {
                    return 'not_recited';
                }
                $has_recitation = $rec && (!empty($st_val) || !empty($pa_val) || !empty($am_val))
                    && $st_val !== 'ناغہ' && $st_val !== '-' && $st_val !== 'غیر حاضر' && $st_val !== 'Absent' && $st_val !== 'رخصت' && $st_val !== 'Leave'
                    && $pa_val !== '-' && $pa_val !== 'غیر حاضر' && $pa_val !== 'رخصت' && $pa_val !== 'ناغہ';
                if ($has_recitation) return 'recited';
                if ($stat === 'A' || $st_val === 'غیر حاضر' || $st_val === 'Absent' || $pa_val === 'غیر حاضر') return 'absent';
                if ($stat === 'L' || $st_val === 'رخصت' || $st_val === 'Leave' || $pa_val === 'رخصت') return 'leave';
                if ($st_val === 'ناغہ' || $pa_val === 'ناغہ') return 'missed';
                if ($is_present) {
                    if ($is_gardan && ($prefix == 'sabaq' || $prefix == 'sabqi')) return 'exempt';
                    return 'missed';
                }
                return 'unrecorded';
            };

            $ty_sabaq = $classify('sabaq', $as1 ?: $asMain);
            $ty_sabqi = $classify('sabqi', $as2 ?: $asMain);
            $ty_manzil = $classify('manzil', $as3 ?: $asMain);

            $c['active_days']++;
            if (!$is_friday && !$is_thursday) $c['manzil_active_days']++;
            if ($is_gardan) {
                if ($ty_manzil === 'recited') {
                    $m_amt = $parse_manzil_paras($rec['manzil_amount'] ?? '');
                    if ($m_amt == 0.0 && !empty($rec['manzil_parah'])) $m_amt = 1.0;
                    $c['manzil_paras'] += $m_amt;
                    $c['manzil_days']++;
                    $c['score_manzil'] += sheet_grade_points($rec['manzil_status'] ?? '', 100);
                } elseif ($ty_manzil === 'missed' || $ty_manzil === 'not_recited') {
                    $c['manzil_nagha']++;
                }
            } else {
                if ($ty_sabaq === 'recited') { $c['sabaq_lines'] += (int)($rec['sabaq_lines'] ?? 0); $c['sabaq_days']++; $c['score_sabaq'] += sheet_grade_points($rec['sabaq_status'] ?? '', 30); }
                elseif ($ty_sabaq === 'missed' || $ty_sabaq === 'not_recited') $c['sabaq_nagha']++;

                if ($ty_sabqi === 'recited') { $c['sabqi_days']++; $c['score_sabqi'] += sheet_grade_points($rec['sabqi_status'] ?? '', 30); }
                elseif ($ty_sabqi === 'missed' || $ty_sabqi === 'not_recited') $c['sabqi_nagha']++;

                if (!$is_thursday) {
                    if ($ty_manzil === 'recited') {
                        $m_amt = $parse_manzil_paras($rec['manzil_amount'] ?? '');
                        if ($m_amt == 0.0 && !empty($rec['manzil_parah'])) $m_amt = 1.0;
                        $c['manzil_paras'] += $m_amt;
                        $c['manzil_days']++;
                        $c['score_manzil'] += sheet_grade_points($rec['manzil_status'] ?? '', 40);
                    } elseif ($ty_manzil === 'missed' || $ty_manzil === 'not_recited') {
                        $c['manzil_nagha']++;
                    }
                }
            }
        }
    }

    $c['academic_days'] = $c['present'] + $c['absent'] + $c['leave'] + $c['unrecorded'];
    // فیصد: ہر جز کے حاصل کردہ نمبرات ÷ اس جز کے زیادہ سے زیادہ ممکنہ نمبرات
    // (سبق 30 و سبقی 30 فی حاضر دن؛ منزل 40 فی قابلِ شمار دن — جمعرات مشق شمار نہیں؛ گارڈن منزل 100 فی دن)
    $c['denom_sabaq'] = $is_gardan ? 0 : 30 * $c['active_days'];
    $c['denom_sabqi'] = $is_gardan ? 0 : 30 * $c['active_days'];
    $c['denom_manzil'] = ($is_gardan ? 100 : 40) * $c['manzil_active_days'];
    $c['denom_total'] = 100 * $c['active_days'];
    $c['perf_sabaq'] = $c['denom_sabaq'] > 0 ? min(100, (int)round(100 * $c['score_sabaq'] / $c['denom_sabaq'])) : null;
    $c['perf_sabqi'] = $c['denom_sabqi'] > 0 ? min(100, (int)round(100 * $c['score_sabqi'] / $c['denom_sabqi'])) : null;
    $c['perf_manzil'] = $c['denom_manzil'] > 0 ? min(100, (int)round(100 * $c['score_manzil'] / $c['denom_manzil'])) : 0;
    $c['perf'] = $c['denom_total'] > 0 ? min(100, (int)round(100 * ($c['score_sabaq'] + $c['score_sabqi'] + $c['score_manzil']) / $c['denom_total'])) : 0;
    $c['roll_val'] = '';
    foreach (['roll_number', 'admission_number', 'roll_no', 'admission_no', 'gr_number', 'serial_no'] as $rk) {
        if (!empty($st[$rk]) && trim((string)$st[$rk]) !== '-' && trim((string)$st[$rk]) !== '0') { $c['roll_val'] = trim((string)$st[$rk]); break; }
    }
    $c['student'] = $st;
    $c['is_gardan'] = $is_gardan;
    $sheet_rows[] = $c;
}

if (!function_exists('sheet_urdu_date')) {
function sheet_urdu_date($dateStr) {
    if (!$dateStr) return '-';
    $ts = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'ستمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $ts) . ' ' . ($months[date('M', $ts)] ?? '') . ' ' . date('Y', $ts) . ' (' . ($days[date('D', $ts)] ?? '') . ')';
}
}

// ===== ناغہ کیٹیگری فلٹر (موجودہ رپورٹ کی سمری جیسی تعریفیں) =====
$comp_labels = ['sabaq' => 'سبق', 'sabqi' => 'سبقی', 'manzil' => 'منزل'];
$cat_labels = ['' => 'تمام طلبہ', '0' => 'بلا ناغہ (0 ناغہ)', '1' => 'صرف 1 ناغہ', '2' => '2 ناغے', '3' => '3 ناغے', 'more' => '3 سے زیادہ ناغے', 'inactive' => 'بغیر تعلیمی ریکارڈ / غیر فعال'];
$sort_labels = ['perf_asc' => 'کارکردگی — کم ناغے پہلے', 'perf_desc' => 'کارکردگی — زیادہ ناغے پہلے', 'name_asc' => 'نام — الف سے ی', 'name_desc' => 'نام — ی سے الف', 'adm_asc' => 'داخلہ نمبر — چھوٹے سے بڑا', 'adm_desc' => 'داخلہ نمبر — بڑے سے چھوٹا'];
$display_rows = $sheet_rows;
if ($filter_cat !== '') {
    $display_rows = array_values(array_filter($sheet_rows, function($r) use ($filter_comp, $filter_cat) {
        if ($filter_comp === 'sabaq') { $nag = $r['sabaq_nagha']; $rec = $r['sabaq_days']; }
        elseif ($filter_comp === 'sabqi') { $nag = $r['sabqi_nagha']; $rec = $r['sabqi_days']; }
        else { $nag = $r['manzil_nagha']; $rec = $r['manzil_days']; }
        switch ($filter_cat) {
            case '0': return $rec > 0 && $nag === 0;
            case '1': return $nag === 1;
            case '2': return $nag === 2;
            case '3': return $nag === 3;
            case 'more': return $nag > 3;
            case 'inactive': return $rec === 0 && $nag === 0;
        }
        return true;
    }));
}

// ===== ترتیب و پارٹیشن: ریگولر حفظ پہلے، استثناء (منزل گارڈن) آخر میں؛
// ہر حصے کے اندر منتخب ترتیب (ڈیفالٹ: کم ناغوں والے اوپر، زیادہ ناغوں والے نیچے) =====
$sheet_sort_cmp = function($a, $b) use ($sort_by) {
    $name_cmp = strcmp((string)($a['student']['name'] ?? ''), (string)($b['student']['name'] ?? ''));
    if ($sort_by === 'name_asc') return $name_cmp;
    if ($sort_by === 'name_desc') return -$name_cmp;
    if ($sort_by === 'adm_asc' || $sort_by === 'adm_desc') {
        $ra = (string)($a['roll_val'] ?? '');
        $rb = (string)($b['roll_val'] ?? '');
        if ($ra === '' && $rb === '') return $name_cmp;
        if ($ra === '') return 1; // خالی داخلہ نمبر ہمیشہ آخر میں
        if ($rb === '') return -1;
        $nc = strnatcmp($ra, $rb);
        if ($nc === 0) return $name_cmp;
        return $sort_by === 'adm_asc' ? $nc : -$nc;
    }
    $na = $a['sabaq_nagha'] + $a['sabqi_nagha'] + $a['manzil_nagha'];
    $nb = $b['sabaq_nagha'] + $b['sabqi_nagha'] + $b['manzil_nagha'];
    $dir = $sort_by === 'perf_desc' ? -1 : 1;
    if ($na !== $nb) return $dir * ($na <=> $nb);
    if ($a['present'] !== $b['present']) return $dir * ($b['present'] <=> $a['present']);
    if ($a['sabaq_lines'] !== $b['sabaq_lines']) return $dir * ($b['sabaq_lines'] <=> $a['sabaq_lines']);
    return $name_cmp;
};
usort($display_rows, function($a, $b) use ($sheet_sort_cmp) {
    if ($a['is_gardan'] !== $b['is_gardan']) return $a['is_gardan'] ? 1 : -1;
    return $sheet_sort_cmp($a, $b);
});
$sections = [
    'regular' => ['label' => 'ریگولر حفظ طلبہ', 'rows' => array_values(array_filter($display_rows, function($r) { return !$r['is_gardan']; }))],
    'gardan'  => ['label' => 'استثناء (منزل گارڈن) طلبہ', 'rows' => array_values(array_filter($display_rows, function($r) { return $r['is_gardan']; }))],
];

if (!function_exists('sheet_totals')) {
    function sheet_totals($rows) {
        $t = ['sabaq_lines'=>0, 'sabaq_nagha'=>0, 'sabqi_days'=>0, 'sabqi_nagha'=>0, 'manzil_paras'=>0.0, 'manzil_nagha'=>0, 'present'=>0, 'absent'=>0, 'leave'=>0, 'unrecorded'=>0, 'academic_days'=>0,
              'score_sabaq'=>0.0, 'score_sabqi'=>0.0, 'score_manzil'=>0.0, 'denom_sabaq'=>0, 'denom_sabqi'=>0, 'denom_manzil'=>0, 'denom_total'=>0];
        foreach ($rows as $r) {
            foreach (['sabaq_lines','sabaq_nagha','sabqi_days','sabqi_nagha','manzil_nagha','present','absent','leave','unrecorded','academic_days','denom_sabaq','denom_sabqi','denom_manzil','denom_total'] as $k) $t[$k] += $r[$k];
            $t['manzil_paras'] += $r['manzil_paras'];
            $t['score_sabaq'] += $r['score_sabaq'];
            $t['score_sabqi'] += $r['score_sabqi'];
            $t['score_manzil'] += $r['score_manzil'];
        }
        $t['perf_sabaq'] = $t['denom_sabaq'] > 0 ? min(100, (int)round(100 * $t['score_sabaq'] / $t['denom_sabaq'])) : null;
        $t['perf_sabqi'] = $t['denom_sabqi'] > 0 ? min(100, (int)round(100 * $t['score_sabqi'] / $t['denom_sabqi'])) : null;
        $t['perf_manzil'] = $t['denom_manzil'] > 0 ? min(100, (int)round(100 * $t['score_manzil'] / $t['denom_manzil'])) : 0;
        $t['perf'] = $t['denom_total'] > 0 ? min(100, (int)round(100 * ($t['score_sabaq'] + $t['score_sabqi'] + $t['score_manzil']) / $t['denom_total'])) : 0;
        return $t;
    }
}
$tot = sheet_totals($display_rows);
foreach ($sections as $sk => $sec) { $sections[$sk]['totals'] = sheet_totals($sec['rows']); }

$perf_cell = function($p) {
    if ($p === null) return '<span class="text-muted">—</span>';
    $color = $p >= 80 ? '#1e7d46' : ($p >= 50 ? '#b97d00' : '#c0392b');
    return '<div class="fw-bold" style="color:' . $color . '">' . $p . '%</div><div class="perf-bar"><span style="width:' . $p . '%;background:' . $color . '"></span></div>';
};
?>

<style>
    .sheet-wrap { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(27,67,50,.10); overflow: hidden; }
    .sheet-head { background: linear-gradient(135deg, #1b4332 0%, #2d6a4f 60%, #40916c 100%); color: #fff; padding: 18px 20px 14px; text-align: center; }
    .sheet-head h4 { color: #fff !important; font-weight: 800; margin: 0; }
    .sheet-head .inst { color: #d8f3dc !important; font-weight: 700; margin-top: 2px; }
    .sheet-head .meta { margin-top: 8px; }
    .sheet-head .meta .chip { display: inline-block; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.35); color: #fff; border-radius: 999px; padding: 2px 12px; margin: 2px 3px; font-weight: 700; font-size: 12.5px; }
    .sheet-head .meta .chip-filter { background: #ffc107; border-color: #ffc107; color: #212529; }
    .sheet-table { border-collapse: separate !important; border-spacing: 0; }
    .sheet-table th, .sheet-table td { white-space: normal !important; line-height: 1.3 !important; vertical-align: middle !important; border-color: #b9c4bd !important; }
    .sheet-table thead .grp th { color: #fff !important; font-size: 13.5px; font-weight: 800; padding: 7px 4px !important; border-bottom: none !important; }
    .sheet-table thead .grp .g-sabaq { background: #1e7d46 !important; }
    .sheet-table thead .grp .g-sabqi { background: #1c5fb8 !important; }
    .sheet-table thead .grp .g-manzil { background: #d99a00 !important; color: #212529 !important; }
    .sheet-table thead .grp .g-att { background: #0e7490 !important; }
    .sheet-table thead .grp .g-base { background: #495057 !important; }
    .sheet-table thead .sub th { font-size: 11px; font-weight: 700; padding: 5px 3px !important; }
    .sheet-table thead .sub .s-sabaq { background: #d9f0e1 !important; }
    .sheet-table thead .sub .s-sabqi { background: #dbeafe !important; }
    .sheet-table thead .sub .s-manzil { background: #fdf0c3 !important; }
    .sheet-table thead .sub .s-att { background: #d7eef3 !important; }
    .sheet-table thead .sub .s-base { background: #e9ecef !important; }
    .sheet-table col.c-sabaq { background: #f2faf4; }
    .sheet-table col.c-sabqi { background: #f2f7fe; }
    .sheet-table col.c-manzil { background: #fffaf0; }
    .sheet-table col.c-att { background: #f3fafc; }
    .sheet-table tbody tr:nth-child(even) td { background-color: rgba(0,0,0,.022); }
    .sheet-table tbody tr:hover td { background-color: #fff8d6; }
    .sheet-table .tot-row td { background: #e8ecef !important; border-top: 2px solid #495057 !important; }
    .sheet-table thead .grp .g-perf { background: #6d28d9 !important; }
    .sheet-table thead .sub .s-perf { background: #e7ddf9 !important; }
    .sheet-table col.c-perf { background: #f7f3fe; }
    .perf-bar { height: 5px; background: #e5e7eb; border-radius: 3px; overflow: hidden; margin-top: 2px; }
    .perf-bar span { display: block; height: 100%; border-radius: 3px; }
    .sec-row td { font-weight: 800; font-size: 12.5px; text-align: right !important; padding: 6px 10px !important; }
    .sec-regular td { background: #1b4332 !important; color: #fff !important; }
    .sec-gardan td { background: #6c757d !important; color: #fff !important; }
    .subtot-row td { background: #f1f3f5 !important; border-top: 1px dashed #868e96 !important; }
    .nag-zero { color: #1e7d46; font-weight: 700; }
    /* سکرول حل: ٹیبل اپنے خانے میں سکرول ہوگا؛ ہیڈر اوپر اور نام والے کالم دائیں جانب رکے رہیں گے */
    .sheet-scroll { max-height: calc(100vh - 130px); overflow: auto !important; }
    .sheet-table thead th { position: sticky; z-index: 5; }
    .sheet-table thead tr.grp th { top: 0; }
    .sheet-table thead tr.sub th { top: 32px; }
    .sheet-table thead tr.grp th:nth-child(1) { right: 0; z-index: 6; }
    .sheet-table thead tr.grp th:nth-child(2) { right: 34px; z-index: 6; }
    .sheet-table thead tr.grp th:nth-child(3) { right: 108px; z-index: 6; }
    .sheet-table tbody tr td.stc { position: sticky; z-index: 3; background-color: #fff; }
    .sheet-table tbody tr:nth-child(even) td.stc { background-color: #f5f5f5; }
    .sheet-table tbody tr:hover td.stc { background-color: #fff8d6; }
    .sheet-table tbody td.st1 { right: 0; }
    .sheet-table tbody td.st2 { right: 34px; }
    .sheet-table tbody td.st3 { right: 108px; box-shadow: -4px 0 6px -3px rgba(0,0,0,.28); }
    .sheet-table tbody td.stl { position: sticky; right: 0; z-index: 3; box-shadow: -4px 0 6px -3px rgba(0,0,0,.28); }
    .sheet-table tbody tr.sec-row td { z-index: 2; }
    .sec-label { position: sticky; right: 12px; display: inline-block; }
    @media print {
        @page { size: A4 landscape; margin: 6mm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .d-print-none { display: none !important; }
        .sheet-wrap { box-shadow: none !important; border-radius: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .sheet-table { width: 100% !important; min-width: 0 !important; table-layout: fixed !important; }
        .sheet-table th, .sheet-table td { font-size: 9.5px !important; padding: 2px 1px !important; overflow-wrap: break-word; position: static !important; }
        .sec-label { position: static !important; }
        .sheet-scroll { max-height: none !important; overflow: visible !important; }
        .sheet-table tbody tr:hover td { background-color: transparent; }
    }
</style>

<div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end" dir="rtl">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="hifz_report.php?class=<?= urlencode($selected_class) ?>&from=<?= $start_date ?>&to=<?= $end_date ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس رپورٹ</a>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-end m-0">
            <input type="hidden" name="class" value="<?= htmlspecialchars($selected_class) ?>">
            <div>
                <label class="fw-bold small mb-1">از تاریخ:</label>
                <input type="date" name="from" class="form-control form-control-sm fw-bold" value="<?= $start_date ?>" required>
            </div>
            <div>
                <label class="fw-bold small mb-1">تا تاریخ:</label>
                <input type="date" name="to" class="form-control form-control-sm fw-bold" value="<?= $end_date ?>" required>
            </div>
            <div>
                <label class="fw-bold small mb-1">ناغہ فلٹر — جز:</label>
                <select name="comp" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <option value="sabaq" <?= $filter_comp === 'sabaq' ? 'selected' : '' ?>>سبق</option>
                    <option value="sabqi" <?= $filter_comp === 'sabqi' ? 'selected' : '' ?>>سبقی</option>
                    <option value="manzil" <?= $filter_comp === 'manzil' ? 'selected' : '' ?>>منزل</option>
                </select>
            </div>
            <div>
                <label class="fw-bold small mb-1">کیٹیگری:</label>
                <select name="cat" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <?php foreach ($cat_labels as $cv => $cl): ?>
                    <option value="<?= $cv ?>" <?= $filter_cat === (string)$cv ? 'selected' : '' ?>><?= $cl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="fw-bold small mb-1">ترتیب:</label>
                <select name="sort" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                    <?php foreach ($sort_labels as $sv => $sl): ?>
                    <option value="<?= $sv ?>" <?= $sort_by === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-success btn-sm fw-bold"><i class="fas fa-search me-1"></i> فلٹر کریں</button>
            <button type="button" onclick="window.print()" class="btn btn-dark btn-sm fw-bold"><i class="fas fa-print me-1"></i> پرنٹ / PDF</button>
        </form>
    </div>
</div>

<div class="sheet-wrap text-end" dir="rtl">
    <div class="sheet-head">
        <h4><i class="fas fa-table me-2"></i>خلاصہ شیٹ — حفظ کارکردگی و حاضری</h4>
        <div class="inst"><?= htmlspecialchars($app_name) ?></div>
        <div class="meta">
            <span class="chip">کلاس: <?= htmlspecialchars($selected_class) ?></span>
            <span class="chip"><?= sheet_urdu_date($start_date) ?> تا <?= sheet_urdu_date($end_date) ?></span>
            <span class="chip">کل طلبہ: <?= count($sheet_rows) ?></span>
            <?php if ($filter_cat !== ''): ?>
            <span class="chip chip-filter">فلٹر: <?= $comp_labels[$filter_comp] ?> — <?= $cat_labels[$filter_cat] ?> (<?= count($display_rows) ?> طلبہ)</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive sheet-scroll">
        <table class="table table-bordered text-center table-sm align-middle sheet-table" style="font-size: 11px; min-width: 1450px; table-layout: fixed;">
            <colgroup>
                <col style="width: 34px;"><col style="width: 74px;"><col style="width: 170px;">
                <col class="c-sabaq" style="width: 5.5%;"><col class="c-sabaq" style="width: 6.5%;"><col class="c-sabaq" style="width: 5%;">
                <col class="c-sabqi" style="width: 5.5%;"><col class="c-sabqi" style="width: 6%;"><col class="c-sabqi" style="width: 5%;">
                <col class="c-manzil" style="width: 5.5%;"><col class="c-manzil" style="width: 6.5%;"><col class="c-manzil" style="width: 5%;">
                <col class="c-att" style="width: 6.5%;"><col class="c-att" style="width: 5.5%;"><col class="c-att" style="width: 5.5%;"><col class="c-att" style="width: 5%;"><col class="c-att" style="width: 5%;">
                <col class="c-perf" style="width: 5.5%;">
            </colgroup>
            <thead>
                <tr class="grp">
                    <th rowspan="2" class="g-base">#</th>
                    <th rowspan="2" class="g-base">رول نمبر</th>
                    <th rowspan="2" class="g-base">طالب علم</th>
                    <th colspan="3" class="g-sabaq"><i class="fas fa-book-open me-1"></i> سبق</th>
                    <th colspan="3" class="g-sabqi"><i class="fas fa-book me-1"></i> سبقی</th>
                    <th colspan="3" class="g-manzil"><i class="fas fa-quran me-1"></i> منزل</th>
                    <th colspan="5" class="g-att"><i class="fas fa-user-check me-1"></i> حاضری</th>
                    <th rowspan="2" class="g-perf"><i class="fas fa-chart-line me-1"></i> مجموعی کارکردگی</th>
                </tr>
                <tr class="sub">
                    <th class="s-sabaq">کل ناغے</th><th class="s-sabaq">سنائی گئی کل لائنیں</th><th class="s-sabaq">کارکردگی %</th>
                    <th class="s-sabqi">کل ناغے</th><th class="s-sabqi">سنائے گئے دن</th><th class="s-sabqi">کارکردگی %</th>
                    <th class="s-manzil">کل ناغے</th><th class="s-manzil">سنائے گئے کل پارے</th><th class="s-manzil">کارکردگی %</th>
                    <th class="s-att">کل تعلیمی ایام</th><th class="s-att">حاضری کے ایام</th><th class="s-att">غیر حاضری</th><th class="s-att">رخصتیں</th><th class="s-att">غیر درج شدہ</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($display_rows)): ?>
                <tr><td colspan="18" class="text-danger fw-bold p-3"><?= $filter_cat !== '' ? 'اس فلٹر میں کوئی طالب علم نہیں ملا۔' : 'اس کلاس میں کوئی طالب علم نہیں ملا۔' ?></td></tr>
            <?php else: $sr = 1;
                foreach ($sections as $sec_key => $sec):
                    if (empty($sec['rows'])) continue;
                    $st = $sec['totals'];
            ?>
                <tr class="sec-row <?= $sec_key === 'gardan' ? 'sec-gardan' : 'sec-regular' ?>">
                    <td colspan="18"><span class="sec-label"><i class="fas <?= $sec_key === 'gardan' ? 'fa-user-check' : 'fa-users' ?> me-1"></i> <?= $sec['label'] ?> (<?= count($sec['rows']) ?> طلبہ)</span></td>
                </tr>
                <?php foreach ($sec['rows'] as $r): $student = $r['student'];
                    $roll_val = '';
                    foreach (['roll_number', 'admission_number', 'roll_no', 'admission_no', 'gr_number', 'serial_no'] as $rk) {
                        if (!empty($student[$rk]) && trim((string)$student[$rk]) !== '-' && trim((string)$student[$rk]) !== '0') { $roll_val = trim((string)$student[$rk]); break; }
                    }
                    $f_disp = !empty($student['father_name']) ? "<br><small class='text-muted fw-normal'>ولد " . htmlspecialchars($student['father_name']) . "</small>" : "";
                ?>
                <tr>
                    <td class="stc st1"><?= $sr++ ?></td>
                    <td class="stc st2"><?= $roll_val !== '' ? htmlspecialchars($roll_val) : '-' ?></td>
                    <td class="stc st3 fw-bold text-start ps-2"><?= htmlspecialchars($student['name'] ?? '') ?><?= $f_disp ?></td>
                    <?php if ($r['is_gardan']): ?>
                    <td colspan="6" class="text-muted fw-bold">استثناء (منزل گارڈن)</td>
                    <?php else: ?>
                    <td class="<?= $r['sabaq_nagha'] > 0 ? 'text-danger fw-bold' : 'nag-zero' ?>"><?= $r['sabaq_nagha'] ?></td>
                    <td class="fw-bold"><?= $r['sabaq_lines'] ?></td>
                    <td><?= $perf_cell($r['perf_sabaq']) ?></td>
                    <td class="<?= $r['sabqi_nagha'] > 0 ? 'text-danger fw-bold' : 'nag-zero' ?>"><?= $r['sabqi_nagha'] ?></td>
                    <td class="fw-bold"><?= $r['sabqi_days'] ?></td>
                    <td><?= $perf_cell($r['perf_sabqi']) ?></td>
                    <?php endif; ?>
                    <td class="<?= $r['manzil_nagha'] > 0 ? 'text-danger fw-bold' : 'nag-zero' ?>"><?= $r['manzil_nagha'] ?></td>
                    <td class="fw-bold"><?= number_format($r['manzil_paras'], 1) ?></td>
                    <td><?= $perf_cell($r['perf_manzil']) ?></td>
                    <td class="fw-bold"><?= $r['academic_days'] ?></td>
                    <td class="text-success fw-bold"><?= $r['present'] ?></td>
                    <td class="<?= $r['absent'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= $r['absent'] ?></td>
                    <td class="<?= $r['leave'] > 0 ? 'text-warning fw-bold' : '' ?>"><?= $r['leave'] ?></td>
                    <td class="text-muted"><?= $r['unrecorded'] ?></td>
                    <td><?= $perf_cell($r['perf']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="subtot-row fw-bold">
                    <td class="stl" colspan="3"><?= $sec['label'] ?> — مجموعہ (<?= count($sec['rows']) ?> طلبہ)</td>
                    <?php if ($sec_key === 'gardan'): ?>
                    <td colspan="6" class="text-muted">—</td>
                    <?php else: ?>
                    <td><?= $st['sabaq_nagha'] ?></td>
                    <td><?= $st['sabaq_lines'] ?></td>
                    <td><?= $perf_cell($st['perf_sabaq']) ?></td>
                    <td><?= $st['sabqi_nagha'] ?></td>
                    <td><?= $st['sabqi_days'] ?></td>
                    <td><?= $perf_cell($st['perf_sabqi']) ?></td>
                    <?php endif; ?>
                    <td><?= $st['manzil_nagha'] ?></td>
                    <td><?= number_format($st['manzil_paras'], 1) ?></td>
                    <td><?= $perf_cell($st['perf_manzil']) ?></td>
                    <td><?= $st['academic_days'] ?></td>
                    <td><?= $st['present'] ?></td>
                    <td><?= $st['absent'] ?></td>
                    <td><?= $st['leave'] ?></td>
                    <td><?= $st['unrecorded'] ?></td>
                    <td><?= $perf_cell($st['perf']) ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="tot-row fw-bold">
                    <td class="stl" colspan="3"><?= $filter_cat !== '' ? 'منتخب طلبہ کا مجموعہ' : 'کلاس مجموعہ' ?> (<?= count($display_rows) ?> طلبہ)</td>
                    <td><?= $tot['sabaq_nagha'] ?></td>
                    <td><?= $tot['sabaq_lines'] ?></td>
                    <td><?= $perf_cell($tot['perf_sabaq']) ?></td>
                    <td><?= $tot['sabqi_nagha'] ?></td>
                    <td><?= $tot['sabqi_days'] ?></td>
                    <td><?= $perf_cell($tot['perf_sabqi']) ?></td>
                    <td><?= $tot['manzil_nagha'] ?></td>
                    <td><?= number_format($tot['manzil_paras'], 1) ?></td>
                    <td><?= $perf_cell($tot['perf_manzil']) ?></td>
                    <td><?= $tot['academic_days'] ?></td>
                    <td><?= $tot['present'] ?></td>
                    <td><?= $tot['absent'] ?></td>
                    <td><?= $tot['leave'] ?></td>
                    <td><?= $tot['unrecorded'] ?></td>
                    <td><?= $perf_cell($tot['perf']) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="small text-muted mt-2 mb-0 px-3 pb-3">
        نوٹ: ناغہ = حاضر دن میں متعلقہ جز نہ سنانا («نہیں سنایا» بھی ناغہ شمار ہوتا ہے)۔ جمعہ کسی گنتی میں شامل نہیں؛ جمعرات کو منزل مشق شمار ہوتی ہے۔ منزل کے پارے مقدار کے مطابق گنے گئے ہیں (پاؤ 0.25، آدھا 0.5، ڈیڑھ 1.5)۔ یہ اعداد انفرادی رپورٹ کے عین مطابق ہیں۔ فلٹر میں «بلا ناغہ» سے مراد وہ طالب علم ہے جس نے اس مدت میں وہ جز سنایا بھی ہو اور اس کے ناغے صفر ہوں؛ «بغیر تعلیمی ریکارڈ / غیر فعال» وہ ہے جس کا منتخب جز میں نہ کوئی اندراج ہو نہ ناغہ۔
        <br>ترتیب: پہلے ریگولر حفظ طلبہ، آخر میں استثناء (منزل گارڈن)؛ ڈیفالٹ ترتیب میں ہر حصے میں کم ناغوں والے اوپر اور زیادہ ناغوں والے نیچے ہیں — اوپر والے «ترتیب» فلٹر سے نام یا داخلہ نمبر کے اعتبار سے بھی ترتیب بدلی جا سکتی ہے، اور فلٹر منتخب ہوتے ہی رپورٹ خود لوڈ ہو جاتی ہے۔
        <br>کارکردگی فیصد: ہر جز کی فیصد اسی جز کے نمبرات سے ہے (کیفیت کے نمبر: ممتاز 100٪، بہتر 80٪، معمولی 60٪، کمزور 40٪) — سبق و سبقی فی حاضر دن 30 نمبرات میں سے، منزل فی قابلِ شمار دن 40 میں سے (جمعرات کا مشق دن منزل کی فیصد میں شمار نہیں)؛ مجموعی فیصد انفرادی رپورٹ والا ہی ہے۔
    </p>
</div>

<script>
// دوسری ہیڈر قطار کی چپکنے والی اونچائی پہلی قطار کی اصل اونچائی کے برابر رکھیں (فونٹ/سکرین کے فرق سے خودکار درست)
(function () {
    var grp = document.querySelector('.sheet-table thead tr.grp');
    if (!grp) return;
    var h = grp.offsetHeight;
    document.querySelectorAll('.sheet-table thead tr.sub th').forEach(function (th) { th.style.top = h + 'px'; });
})();
</script>

<?php include 'footer.php'; ?>
