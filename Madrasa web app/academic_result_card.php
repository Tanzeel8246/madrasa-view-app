<?php
/**
 * Academic Detailed Marks Certificate & Result Card (کشف الدرجات / نتیجہ کارڈ درس نظامی و عصری علوم)
 * With Live Adjustable Margins for Pre-printed Letterheads & Blank Paper
 * 110% Tri-Platform Parity with Multi-Tenant Isolation
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
ensure_academic_courses_tables($pdo);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_staff = in_array($u_role, ['admin', 'superadmin', 'administrator', 'nazim', 'teacher', 'mudarris'], true) || app_has_perm('manage_exams');

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$year_no = isset($_GET['year_no']) ? (int)$_GET['year_no'] : 1;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (!$is_staff) {
    $logged_uid = (int)($_SESSION['user_id'] ?? 0);
    if ($u_role !== 'student' || $student_id !== $logged_uid) {
        die("<div style='font-family:sans-serif; text-align:center; padding:50px; direction:rtl;'><h2>آپ کو نتیجہ کارڈ دیکھنے کی اجازت نہیں ہے۔</h2><a href='dashboard.php'>واپس جائیں</a></div>");
    }
}

// امتحان کا ڈیٹا
$exam_stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND tenant_id = ?");
$exam_stmt->execute([$exam_id, $cur_tenant_id]);
$exam_data = $exam_stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam_data && $exam_id > 0) {
    die("Exam not found.");
}

// کورس کا ڈیٹا
$course_stmt = $pdo->prepare("SELECT * FROM course_structure WHERE id = ? AND tenant_id = ?");
$course_stmt->execute([$course_id, $cur_tenant_id]);
$course_data = $course_stmt->fetch(PDO::FETCH_ASSOC);

// مضامین نکالیں
$subjects_stmt = $pdo->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ? AND is_deleted = 0 ORDER BY subject_order ASC, id ASC");
$subjects_stmt->execute([$course_id, $year_no, $cur_tenant_id]);
$subjects_list = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_max_marks = 0;
foreach ($subjects_list as $sb) {
    $total_max_marks += (float)$sb['max_marks'];
}

// طلباء کی فہرست (اگر سنگل طالب علم منتخب ہو تو صرف وہ، ورنہ اس کورس و سال کے تمام انرولڈ طلباء)
$students_query = "
    SELECT e.roll_number as course_roll, e.current_year, st.id as student_id, st.name, st.father_name, st.admission_number, st.class, st.photo
    FROM student_course_enrollment e
    JOIN students st ON e.student_id = st.id
    WHERE e.course_id = ? AND e.current_year = ? AND e.tenant_id = ? AND e.is_deleted = 0 AND e.status = 'Active' AND (st.is_deleted = 0 OR st.is_deleted IS NULL)
";
$params = [$course_id, $year_no, $cur_tenant_id];

if ($student_id > 0) {
    $students_query .= " AND st.id = ?";
    $params[] = $student_id;
}
$students_query .= " ORDER BY COALESCE(e.roll_number, '') ASC, st.name ASC";

$std_stmt = $pdo->prepare($students_query);
$std_stmt->execute($params);
$students_to_print = $std_stmt->fetchAll(PDO::FETCH_ASSOC);

// اگر طالب علم انرولمنٹ میں نہیں تھا لیکن student_id دی گئی ہے
if (empty($students_to_print) && $student_id > 0) {
    $fallback_stmt = $pdo->prepare("SELECT id as student_id, name, father_name, admission_number, class, photo, '' as course_roll, ? as current_year FROM students WHERE id = ? AND tenant_id = ?");
    $fallback_stmt->execute([$year_no, $student_id, $cur_tenant_id]);
    $st_single = $fallback_stmt->fetch(PDO::FETCH_ASSOC);
    if ($st_single) {
        $students_to_print = [$st_single];
    }
}

// تمام امتحانی نتائج حاصل کریں
$results_map = [];
if ($exam_id > 0 && $course_id > 0) {
    $res_stmt = $pdo->prepare("SELECT * FROM exam_subject_results WHERE exam_id = ? AND course_id = ? AND year_no = ? AND tenant_id = ? AND is_deleted = 0");
    $res_stmt->execute([$exam_id, $course_id, $year_no, $cur_tenant_id]);
    $all_res = $res_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_res as $r) {
        $results_map[$r['student_id']][$r['subject_id']] = $r;
    }
}

// ادارہ کا نام و لوگو (ٹیننٹ سیٹنگز کے مطابق)
$tSettings = function_exists('get_tenant_settings') ? get_tenant_settings($pdo, $cur_tenant_id) : [];
$tenant_name = !empty($tSettings['madrasa_name']) ? $tSettings['madrasa_name'] : ($_SESSION['tenant_name'] ?? 'مدرسہ مینجمنٹ سسٹم');
$customLogo = !empty($tSettings['logo_path']) ? (__DIR__ . '/' . ltrim($tSettings['logo_path'], '/')) : '';
$logo_path = ($customLogo !== '' && file_exists($customLogo)) ? $customLogo : (__DIR__ . '/logo.png');
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logoMime = (strtolower(pathinfo($logo_path, PATHINFO_EXTENSION)) === 'jpg' || strtolower(pathinfo($logo_path, PATHINFO_EXTENSION)) === 'jpeg') ? 'image/jpeg' : 'image/png';
    $logo_base64 = 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logo_path));
}

function get_darjah_label($yr, $c_type = 'Darse_Nizami') {
    $d_names = [
        1 => 'سال اول (اولیٰ - عامہ اول)',
        2 => 'سال دوم (ثانیہ - عامہ دوم)',
        3 => 'سال سوم (ثالثہ - خاصہ اول)',
        4 => 'سال چہارم (رابعہ - خاصہ دوم)',
        5 => 'سال پنجم (خامسہ - عالیہ اول)',
        6 => 'سال ششم (سادسہ - عالیہ دوم)',
        7 => 'سال ہفتم (سابعہ - عالمیہ اول / موقوف علیہ)',
        8 => 'سال ہشتم (دورہ حدیث شریف - عالمیہ دوم)'
    ];
    $asri_names = [
        1 => 'ہفتم (7th Grade)',
        2 => 'ہشتم (8th Grade)',
        3 => 'نہم (9th Matric)',
        4 => 'دہم (10th Matric)'
    ];
    if ($c_type === 'Asri' || $c_type === 'School') {
        return $asri_names[$yr] ?? "سال $yr";
    }
    return $d_names[$yr] ?? "سال $yr";
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>کشف الدرجات (DMC) - <?php echo htmlspecialchars($course_data['name'] ?? 'درس نظامی'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dmc-top-margin: 15mm;
            --dmc-bottom-margin: 15mm;
            --dmc-left-margin: 15mm;
            --dmc-right-margin: 15mm;
            --dmc-font-scale: 1.0;
        }

        body {
            background-color: #525659;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            color: #222;
        }

        .urdu-font {
            font-family: 'Noto Nastaliq Urdu', 'Amiri', serif;
        }

        .arabic-font {
            font-family: 'Amiri', 'Noto Nastaliq Urdu', serif;
        }

        /* لائیو مارجن کنٹرول پینل (نو پرنٹ) */
        .controls-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(30, 60, 114, 0.96);
            backdrop-filter: blur(8px);
            color: white;
            padding: 12px 24px;
            z-index: 9999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .slider-group {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .slider-group input[type="range"] {
            width: 100px;
        }

        .main-print-container {
            margin-top: 100px;
            padding-bottom: 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 30px;
        }

        /* سنگل DMC شیٹ (A4 Portrait) */
        .dmc-card {
            background: white;
            width: 210mm;
            min-height: 297mm;
            box-sizing: border-box;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            position: relative;
            padding-top: var(--dmc-top-margin);
            padding-bottom: var(--dmc-bottom-margin);
            padding-left: var(--dmc-left-margin);
            padding-right: var(--dmc-right-margin);
            transform-origin: top center;
            transition: all 0.2s ease;
        }

        /* بارڈرز و فریم (سادہ کاغذ کے لیے) */
        .dmc-frame {
            border: 12px double #284D3D;
            outline: 2px solid #C59B27;
            outline-offset: -8px;
            padding: 18px;
            min-height: calc(297mm - var(--dmc-top-margin) - var(--dmc-bottom-margin));
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
            background-color: #fff;
            position: relative;
        }

        /* پری پرنٹڈ لیٹر پیڈ موڈ (Border & Header Hidden) */
        .preprinted-mode .dmc-frame {
            border: none !important;
            outline: none !important;
            padding: 0 !important;
            box-shadow: none !important;
        }

        .preprinted-mode .header-section {
            visibility: hidden !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }

        .preprinted-mode .letterhead-space {
            display: block;
        }

        /* ہیڈر سیکشن */
        .header-section {
            text-align: center;
            border-bottom: 2px solid #284D3D;
            padding-bottom: 12px;
            margin-bottom: 15px;
            position: relative;
        }

        .logo-box {
            position: absolute;
            left: 10px;
            top: 5px;
            width: 65px;
            height: 65px;
        }
        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .madrasa-title {
            font-size: 26px;
            font-weight: bold;
            color: #284D3D;
            margin-bottom: 2px;
        }

        .sub-title {
            font-size: 13px;
            color: #555;
            font-weight: 500;
        }

        .dmc-badge {
            background: linear-gradient(135deg, #284D3D, #1E3C72);
            color: #F8D153;
            display: inline-block;
            padding: 4px 30px;
            border-radius: 25px;
            font-weight: bold;
            font-size: 16px;
            margin: 8px 0;
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
            letter-spacing: 0.5px;
        }

        /* کوائفِ طالب علم */
        .student-info-box {
            border: 1px solid #284D3D;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 15px;
            background-color: #FAFAFA;
            font-size: calc(13px * var(--dmc-font-scale));
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            row-gap: 6px;
            column-gap: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dotted #ccc;
            padding-bottom: 3px;
        }

        .info-label {
            font-weight: bold;
            color: #284D3D;
        }

        .info-val {
            font-weight: 600;
            color: #111;
        }

        /* جدول مضامین و نمبرات */
        .marks-table {
            width: 100%;
            border-collapse: collapse;
            font-size: calc(12.5px * var(--dmc-font-scale));
            margin-bottom: 15px;
        }

        .marks-table th {
            background-color: #284D3D;
            color: #FFF;
            border: 1px solid #1e3a2e;
            padding: 7px 6px;
            text-align: center;
            font-weight: bold;
        }

        .marks-table td {
            border: 1px solid #284D3D;
            padding: 6px 8px;
            text-align: center;
        }

        .marks-table tr:nth-child(even) {
            background-color: #FDFDFD;
        }

        /* سمری و ریمارکس */
        .summary-panel {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
        }

        .grade-box {
            flex: 1.2;
            border: 2px solid #284D3D;
            border-radius: 8px;
            padding: 8px;
            text-align: center;
            background: #F8F9FA;
        }

        .grade-title {
            font-size: 12px;
            color: #666;
            font-weight: bold;
        }

        .grade-value {
            font-size: 28px;
            font-weight: 900;
            color: #284D3D;
            line-height: 1.2;
        }

        .totals-box {
            flex: 2;
            border: 1px solid #284D3D;
            border-radius: 8px;
            padding: 8px 12px;
            background: #FFF;
            display: flex;
            flex-direction: column;
            justify-content: space-around;
            font-size: calc(13px * var(--dmc-font-scale));
        }

        .remarks-box {
            flex: 2;
            border: 1px solid #284D3D;
            border-radius: 8px;
            padding: 8px 12px;
            background: #FFF;
            font-size: calc(12px * var(--dmc-font-scale));
        }

        /* فوٹر دستخط */
        .signatures-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 25px;
            padding: 0 10px;
        }

        .sig-col {
            text-align: center;
            width: 140px;
        }

        .sig-line {
            border-top: 1.5px solid #284D3D;
            margin-bottom: 4px;
        }

        .sig-title {
            font-size: 11.5px;
            font-weight: bold;
            color: #333;
        }

        /* پرنٹنگ میڈیا کوئریز */
        @media print {
            body {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .no-print, .controls-toolbar {
                display: none !important;
            }

            .main-print-container {
                margin: 0 !important;
                padding: 0 !important;
                gap: 0 !important;
            }

            .dmc-card {
                box-shadow: none !important;
                margin: 0 !important;
                width: 100% !important;
                min-height: 297mm !important;
                page-break-after: always;
                break-after: page;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <!-- فلیکس ایبل کنٹرولز ٹول بار (صرف اسکرین پر ظاہر ہوگا) -->
    <div class="controls-toolbar no-print">
        <div class="d-flex align-items-center gap-3">
            <a href="academic_results_entry.php?exam_id=<?php echo $exam_id; ?>&course_id=<?php echo $course_id; ?>&year_no=<?php echo $year_no; ?>" class="btn btn-outline-light btn-sm fw-bold">
                <i class="fas fa-arrow-right me-1"></i> واپسی
            </a>
            <span class="fw-bold fs-6">
                <i class="fas fa-sliders-h me-1 text-warning"></i> پرنٹ و مارجن کنٹرولر
            </span>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-3">
            <!-- موڈ کا انتخاب -->
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" id="modeToggle" onchange="togglePreprintedMode(this.checked)">
                <label class="form-check-label fw-bold text-white small" for="modeToggle">
                    پری پرنٹڈ لیٹر پیڈ / سند (ہیڈر چھپائیں)
                </label>
            </div>

            <!-- اوپر کا مارجن -->
            <div class="slider-group">
                <span>اوپر (Top):</span>
                <input type="range" id="topMarginSlider" min="0" max="140" value="15" oninput="updateMargin('top', this.value)">
                <span id="topMarginVal" class="badge bg-light text-dark font-monospace">15mm</span>
            </div>

            <!-- نیچے کا مارجن -->
            <div class="slider-group">
                <span>نیچے (Bottom):</span>
                <input type="range" id="bottomMarginSlider" min="0" max="100" value="15" oninput="updateMargin('bottom', this.value)">
                <span id="bottomMarginVal" class="badge bg-light text-dark font-monospace">15mm</span>
            </div>

            <!-- دائیں / بائیں مارجن -->
            <div class="slider-group">
                <span>سائیڈز (Sides):</span>
                <input type="range" id="sidesMarginSlider" min="0" max="40" value="15" oninput="updateMargin('sides', this.value)">
                <span id="sidesMarginVal" class="badge bg-light text-dark font-monospace">15mm</span>
            </div>

            <!-- فونٹ اسکیلر -->
            <div class="slider-group">
                <span>فونٹ:</span>
                <input type="range" id="fontScaleSlider" min="80" max="120" value="100" oninput="updateFontScale(this.value)">
                <span id="fontScaleVal" class="badge bg-light text-dark font-monospace">100%</span>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-warning text-dark btn-sm fw-bold shadow-sm" onclick="saveSettingsToStorage()">
                <i class="fas fa-save me-1"></i> مارجنز محفوظ کریں
            </button>
            <button type="button" class="btn btn-success btn-sm fw-bold shadow-sm px-3" onclick="window.print()">
                <i class="fas fa-print me-1"></i> پرنٹ کارڈ (Print)
            </button>
        </div>
    </div>

    <!-- پرنٹنگ کنٹینر -->
    <div class="main-print-container">
        <?php if (empty($students_to_print)): ?>
            <div class="card p-5 text-center shadow" style="width: 500px;">
                <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                <h5 class="fw-bold">کوئی نتیجہ کارڈ دستیاب نہیں ہے!</h5>
                <p class="text-muted">اس سال یا درجہ میں کوئی طالب علم انرول نہیں ہے یا رزلٹ درج نہیں ہوا۔</p>
                <a href="academic_results_entry.php?exam_id=<?php echo $exam_id; ?>&course_id=<?php echo $course_id; ?>&year_no=<?php echo $year_no; ?>" class="btn btn-primary fw-bold">
                    واپس جائیں
                </a>
            </div>
        <?php else: ?>
            <?php 
            foreach ($students_to_print as $std): 
                $s_id = (int)$std['student_id'];
                $s_name = $std['name'];
                $f_name = $std['father_name'] ?: '-';
                $adm_no = $std['admission_number'] ?: '-';
                $roll_no = $std['course_roll'] ?: ($adm_no ?: '-');
                $c_name = $course_data['name'] ?? 'درس نظامی';
                $darjah_label = get_darjah_label($year_no, $course_data['course_type'] ?? 'Darse_Nizami');

                // اس طالب علم کے نمبرات کا حساب
                $std_obtained = 0;
                $std_total_max = 0;
                $has_fail = false;
                $all_absent = true;
                $std_remarks = '';
            ?>
                <div class="dmc-card">
                    <div class="dmc-frame">
                        <!-- ہیڈر (سادہ کاغذ کے لیے) -->
                        <div class="header-section">
                            <div class="logo-box">
                                <?php if ($logo_base64): ?>
                                    <img src="<?php echo $logo_base64; ?>" alt="Logo">
                                <?php endif; ?>
                            </div>
                            <div class="madrasa-title urdu-font"><?php echo htmlspecialchars($tenant_name); ?></div>
                            <div class="sub-title">جامعہ اسلامیہ برائے علومِ دینیہ و عصریہ</div>
                            <div class="dmc-badge arabic-font">كشف الدرجات (Detailed Marks Certificate)</div>
                            <div class="fw-bold text-dark mt-1" style="font-size: 13.5px;">
                                <?php echo htmlspecialchars($exam_data['exam_name'] ?? 'سالانہ امتحان'); ?>
                                <?php if (!empty($exam_data['exam_date'])): ?>
                                    <span class="text-muted font-monospace">(<?php echo $exam_data['exam_date']; ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- کوائفِ طالب علم -->
                        <div class="student-info-box">
                            <div class="info-grid">
                                <div class="info-row">
                                    <span class="info-label">نام طالب علم:</span>
                                    <span class="info-val"><?php echo htmlspecialchars($s_name); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">رول نمبر:</span>
                                    <span class="info-val font-monospace"><?php echo htmlspecialchars($roll_no); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">ولدیت:</span>
                                    <span class="info-val"><?php echo htmlspecialchars($f_name); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">داخلہ نمبر:</span>
                                    <span class="info-val font-monospace"><?php echo htmlspecialchars($adm_no); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">تعلیمی کورس:</span>
                                    <span class="info-val"><?php echo htmlspecialchars($c_name); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">سال / درجہ:</span>
                                    <span class="info-val"><?php echo htmlspecialchars($darjah_label); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- جدول مضامین و نمبرات -->
                        <table class="marks-table">
                            <thead>
                                <tr>
                                    <th style="width: 35px;">#</th>
                                    <th style="text-align: right; padding-right: 12px;">مضمون / کتاب (Subject & Book)</th>
                                    <th style="width: 75px;">کل نمبر</th>
                                    <th style="width: 75px;">پاسنگ</th>
                                    <th style="width: 95px;">حاصل کردہ</th>
                                    <th style="width: 90px;">درجہ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sub_idx = 1;
                                foreach ($subjects_list as $sb): 
                                    $sub_id = (int)$sb['id'];
                                    $res = $results_map[$s_id][$sub_id] ?? null;
                                    $max_m = (float)$sb['max_marks'];
                                    $pass_m = (float)$sb['pass_marks'];
                                    $is_opt = (int)($sb['is_optional'] ?? 0) === 1;

                                    $obt = ($res !== null) ? (float)$res['marks_obtained'] : 0.0;
                                    $is_abs = ($res !== null && !empty($res['is_absent']));
                                    $grade = $res['grade'] ?? '';

                                    if (!empty($res['remarks']) && empty($std_remarks)) {
                                        $std_remarks = $res['remarks'];
                                    }

                                    $std_total_max += $max_m;

                                    if (!$is_abs) {
                                        $all_absent = false;
                                        $std_obtained += $obt;
                                        if (!$is_opt && $obt < $pass_m) {
                                            $has_fail = true;
                                        }
                                    } else {
                                        if (!$is_opt) $has_fail = true;
                                    }
                                ?>
                                    <tr>
                                        <td class="text-muted fw-bold"><?php echo $sub_idx++; ?></td>
                                        <td style="text-align: right; padding-right: 12px;">
                                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($sb['subject_name']); ?></span>
                                            <?php if (!empty($sb['book_name'])): ?>
                                                <small class="text-muted d-block" style="font-size: 11px;">(<?php echo htmlspecialchars($sb['book_name']); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-monospace fw-bold"><?php echo $max_m; ?></td>
                                        <td class="font-monospace text-muted"><?php echo $pass_m; ?></td>
                                        <td class="font-monospace fw-bold fs-6 <?php echo $is_abs ? 'text-danger' : ($obt < $pass_m ? 'text-danger' : 'text-primary'); ?>">
                                            <?php echo $is_abs ? 'غیر حاضر' : $obt; ?>
                                        </td>
                                        <td class="fw-bold">
                                            <?php if ($is_abs): ?>
                                                <span class="badge bg-danger-subtle text-danger border">غ/ح</span>
                                            <?php elseif ($grade === 'ممتاز'): ?>
                                                <span class="badge bg-warning-subtle text-dark border">ممتاز</span>
                                            <?php elseif ($grade === 'جید جدا'): ?>
                                                <span class="badge bg-primary-subtle text-primary border">جید جدا</span>
                                            <?php elseif ($grade === 'جید'): ?>
                                                <span class="badge bg-info-subtle text-info border">جید</span>
                                            <?php elseif ($grade === 'مقبول'): ?>
                                                <span class="badge bg-success-subtle text-success border">مقبول</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border">راسب</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php 
                        // مجموعی نتائج
                        $percentage = ($std_total_max > 0) ? round(($std_obtained / $std_total_max) * 100, 2) : 0;
                        $overall_grade = 'راسب';
                        $status_text = 'کامیاب';

                        if ($all_absent) {
                            $overall_grade = 'غیر حاضر';
                            $status_text = 'غیر حاضر';
                        } elseif ($has_fail || $percentage < 40) {
                            $overall_grade = 'راسب';
                            $status_text = 'راسب (ناکام)';
                        } elseif ($percentage >= 80) {
                            $overall_grade = 'ممتاز';
                            $status_text = 'کامیاب باامتیاز';
                        } elseif ($percentage >= 70) {
                            $overall_grade = 'جید جدا';
                            $status_text = 'کامیاب بااعزاز';
                        } elseif ($percentage >= 50) {
                            $overall_grade = 'جید';
                            $status_text = 'کامیاب';
                        } else {
                            $overall_grade = 'مقبول';
                            $status_text = 'کامیاب';
                        }
                        ?>

                        <!-- سمری و مجموعہ پینل -->
                        <div class="summary-panel">
                            <div class="grade-box">
                                <div class="grade-title">حتمی درجہ (Grade)</div>
                                <div class="grade-value urdu-font"><?php echo $overall_grade; ?></div>
                                <small class="fw-bold text-muted"><?php echo $status_text; ?></small>
                            </div>

                            <div class="totals-box">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold text-secondary">کل نمبرات:</span>
                                    <span class="font-monospace fw-bold fs-6"><?php echo $std_total_max; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold text-primary">حاصل کردہ نمبر:</span>
                                    <span class="font-monospace fw-bold fs-6 text-primary"><?php echo $std_obtained; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold text-dark">حاصل کردہ فیصد:</span>
                                    <span class="font-monospace fw-bold fs-6 text-success"><?php echo $percentage; ?>%</span>
                                </div>
                            </div>

                            <div class="remarks-box">
                                <div class="fw-bold text-dark mb-1"><i class="fas fa-pen-nib me-1"></i> تاثرات و ہدایات:</div>
                                <p class="text-muted small mb-0">
                                    <?php echo !empty($std_remarks) ? htmlspecialchars($std_remarks) : 'ماشاء اللہ محنت اور حاضری کو برقرار رکھیں۔ اللہ تعالیٰ مزید ترقیات سے نوازے۔'; ?>
                                </p>
                            </div>
                        </div>

                        <!-- فوٹر دستخطیں -->
                        <div class="signatures-row">
                            <div class="sig-col">
                                <div class="sig-line"></div>
                                <div class="sig-title">دستخط نگرانِ امتحانات</div>
                            </div>
                            <div class="sig-col">
                                <div class="sig-line"></div>
                                <div class="sig-title">دستخط صدر مدرس / مہتمم</div>
                            </div>
                            <div class="sig-col">
                                <div class="sig-line"></div>
                                <div class="sig-title">مہر جامعہ</div>
                            </div>
                            <div class="sig-col">
                                <div class="sig-line"></div>
                                <div class="sig-title">دستخط سرپرست / والد</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // مارجنز اور لے آؤٹ ایڈجسٹمنٹ اسکرپٹ
        function updateMargin(type, val) {
            val = parseInt(val) || 0;
            if (type === 'top') {
                document.documentElement.style.setProperty('--dmc-top-margin', val + 'mm');
                document.getElementById('topMarginVal').textContent = val + 'mm';
            } else if (type === 'bottom') {
                document.documentElement.style.setProperty('--dmc-bottom-margin', val + 'mm');
                document.getElementById('bottomMarginVal').textContent = val + 'mm';
            } else if (type === 'sides') {
                document.documentElement.style.setProperty('--dmc-left-margin', val + 'mm');
                document.documentElement.style.setProperty('--dmc-right-margin', val + 'mm');
                document.getElementById('sidesMarginVal').textContent = val + 'mm';
            }
        }

        function updateFontScale(val) {
            var scale = (parseInt(val) || 100) / 100;
            document.documentElement.style.setProperty('--dmc-font-scale', scale);
            document.getElementById('fontScaleVal').textContent = val + '%';
        }

        function togglePreprintedMode(isPreprinted) {
            var cards = document.querySelectorAll('.dmc-card');
            cards.forEach(function(c) {
                if (isPreprinted) {
                    c.classList.add('preprinted-mode');
                } else {
                    c.classList.remove('preprinted-mode');
                }
            });
        }

        function saveSettingsToStorage() {
            var settings = {
                top: document.getElementById('topMarginSlider').value,
                bottom: document.getElementById('bottomMarginSlider').value,
                sides: document.getElementById('sidesMarginSlider').value,
                font: document.getElementById('fontScaleSlider').value,
                preprinted: document.getElementById('modeToggle').checked
            };
            localStorage.setItem('madrasa_dmc_print_settings', JSON.stringify(settings));
            alert('✅ پرنٹ و مارجن سیٹنگز کامیابی سے محفوظ ہو گئیں!');
        }

        // لوڈ ہوتے ہی سیوڈ سیٹنگز لاگو کریں
        document.addEventListener('DOMContentLoaded', function() {
            var saved = localStorage.getItem('madrasa_dmc_print_settings');
            if (saved) {
                try {
                    var s = JSON.parse(saved);
                    if (s.top !== undefined) {
                        document.getElementById('topMarginSlider').value = s.top;
                        updateMargin('top', s.top);
                    }
                    if (s.bottom !== undefined) {
                        document.getElementById('bottomMarginSlider').value = s.bottom;
                        updateMargin('bottom', s.bottom);
                    }
                    if (s.sides !== undefined) {
                        document.getElementById('sidesMarginSlider').value = s.sides;
                        updateMargin('sides', s.sides);
                    }
                    if (s.font !== undefined) {
                        document.getElementById('fontScaleSlider').value = s.font;
                        updateFontScale(s.font);
                    }
                    if (s.preprinted !== undefined) {
                        document.getElementById('modeToggle').checked = s.preprinted;
                        togglePreprintedMode(s.preprinted);
                    }
                } catch(e) {}
            }
        });
    </script>
</body>
</html>
