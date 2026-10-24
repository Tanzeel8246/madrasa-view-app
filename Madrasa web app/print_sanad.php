<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// Authentication & Authorization check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$is_staff = in_array($user_role, ['admin', 'superadmin', 'nazim', 'teacher', 'mudarris'], true) || has_permission($user_role, $_SESSION['permissions'] ?? [], 'manage_students');
$student_id = (int)($_GET['student_id'] ?? $_GET['id'] ?? 0);

if (!$is_staff) {
    $logged_uid = (int)($_SESSION['user_id'] ?? 0);
    if ($user_role !== 'student' || $student_id !== $logged_uid) {
        die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>آپ کو سند دیکھنے کی اجازت نہیں ہے۔</h2><a href='dashboard.php'>واپس جائیں</a></div>");
    }
}

// Handle AJAX Save Layout request
if (isset($_POST['action']) && $_POST['action'] === 'save_layout') {
    header('Content-Type: application/json');
    if (!$is_staff) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $layoutJson = $_POST['layout_json'] ?? '';
    if (!empty($layoutJson)) {
        try {
            // Ensure column exists
            $cols = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('sanad_layout_json', $cols)) {
                $pdo->exec("ALTER TABLE settings ADD COLUMN sanad_layout_json TEXT DEFAULT NULL");
            }
            $stmt = $pdo->prepare("UPDATE settings SET sanad_layout_json = ? WHERE id = 1");
            $stmt->execute([$layoutJson]);
            echo json_encode(['status' => 'success', 'message' => 'لے آؤٹ کوآرڈینیٹس کامیابی سے محفوظ ہو گئے!']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    exit;
}

if (!$student_id) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>طالب علم کی آئی ڈی درکار ہے۔ (Student ID Required)</h2><a href='alumni.php'>واپس جائیں</a></div>");
}

// Ensure table issued_sanads exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS issued_sanads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        madrasa_id INT DEFAULT 1,
        student_id INT NOT NULL,
        sanad_serial_no VARCHAR(100) UNIQUE,
        sanad_title VARCHAR(255) DEFAULT 'سند الفراغ فی حفظ القرآن الکریم',
        department VARCHAR(100) DEFAULT 'شعبہ حفظ القرآن الکریم',
        completion_date DATE NULL,
        issue_date DATE NOT NULL,
        wifaq_roll_no VARCHAR(100) NULL,
        wifaq_marks VARCHAR(50) NULL,
        grade VARCHAR(50) DEFAULT 'ممتاز',
        remarks TEXT NULL,
        issued_by INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(student_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Fetch Student
$cur_tenant_id = (int)get_current_tenant_id();
$is_maktab = (isset($_GET['type']) && $_GET['type'] === 'maktab');
$target_table = $is_maktab ? 'maktab_students' : 'students';
$stmt = $pdo->prepare("SELECT * FROM $target_table WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$student_id, $cur_tenant_id]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$s && !$is_maktab) {
    // Fallback to maktab_students if not found in students
    $stmt = $pdo->prepare("SELECT * FROM maktab_students WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$student_id, $cur_tenant_id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$s) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>طالب علم کا ریکارڈ نہیں ملا۔</h2><a href='javascript:history.back()'>واپس جائیں</a></div>");
}

$cat = resolve_alumni_category($s);
if ($cat !== 'completed') {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; direction:rtl;'>
        <h2 style='color:#c0392b;'>سند کا اجراء ممکن نہیں ہے!</h2>
        <p style='font-size:16px; color:#555;'>یہ سند صرف اور صرف <b>\"تکمیلِ تعلیم\"</b> والے طلبہ کے لیے مختص ہے۔ ترکِ تعلیم یا خارج شدہ طلبہ کے لیے سند جاری نہیں کی جا سکتی۔</p>
        <br>
        <a href='javascript:history.back()' style='text-decoration:none; background:#284D3D; color:#fff; padding:10px 20px; border-radius:5px;'>واپس جائیں</a>
    </div>");
}

// Fetch Settings
$settings = get_tenant_settings($pdo, $cur_tenant_id);
$madrasa_name = !empty($settings['madrasa_name']) ? $settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : 'ادارہ');
$logo_path = (!empty($settings['logo_path']) && file_exists(__DIR__ . '/' . $settings['logo_path'])) ? $settings['logo_path'] : 'logo.png';
$sanad_bg = (!empty($settings['sanad_bg']) && file_exists(__DIR__ . '/' . $settings['sanad_bg'])) ? $settings['sanad_bg'] : null;
$stamp_path = (!empty($settings['signature_stamp_path']) && file_exists(__DIR__ . '/' . $settings['signature_stamp_path'])) ? $settings['signature_stamp_path'] : null;
$tagline = !empty($settings['tagline']) ? $settings['tagline'] : 'العلم نور - تعلیم و تربیت کا گہوارہ';
$current_hijri_year = !empty($settings['current_hijri_year']) ? $settings['current_hijri_year'] : '1447';
$madrasa_address = !empty($settings['address']) ? $settings['address'] : '';
$savedLayout = !empty($settings['sanad_layout_json']) ? json_decode($settings['sanad_layout_json'], true) : null;

// Resolve Academic Course Enrollment (Darse Nizami / Asri) if any
$courseEnrollStmt = $pdo->prepare("
    SELECT e.*, c.name as course_name, c.course_type, c.total_years
    FROM student_course_enrollment e
    JOIN course_structure c ON c.id = e.course_id
    WHERE e.student_id = ? AND e.tenant_id = ?
    ORDER BY e.id DESC LIMIT 1
");
$courseEnrollStmt->execute([$student_id, $cur_tenant_id]);
$enrolledCourse = $courseEnrollStmt->fetch(PDO::FETCH_ASSOC);

$defaultSanadTitle = 'سند الفراغ فی حفظ القرآن الکریم';
$defaultDept = !empty($s['class']) ? $s['class'] : 'شعبہ حفظ القرآن الکریم';

if ($enrolledCourse) {
    $cName = $enrolledCourse['course_name'];
    $cYear = (int)$enrolledCourse['current_year'];
    $totalYears = (int)($enrolledCourse['total_years'] ?? 8);
    $yearMap = [
        1 => 'سال اول (درجہ اولیٰ)', 2 => 'سال دوم (درجہ ثانیہ)', 3 => 'سال سوم (درجہ ثالثہ)',
        4 => 'سال چہارم (درجہ رابعہ)', 5 => 'سال پنجم (درجہ خامسہ)', 6 => 'سال ششم (درجہ سادسہ)',
        7 => 'سال ہفتم (درجہ عالیہ)', 8 => 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)'
    ];
    $yearStr = $yearMap[$cYear] ?? "سال $cYear";
    
    if ($cYear >= $totalYears) {
        $defaultSanadTitle = ($enrolledCourse['course_type'] === 'Darse_Nizami') 
            ? 'سند الفراغ و شہادۃ العالمیہ فی العلوم الاسلامیہ والعربیہ'
            : "سند الفراغ و تکمیل کورس $cName";
    } else {
        $defaultSanadTitle = "سند کامیابی تعلیمی سال - $cName ($yearStr)";
    }
    $defaultDept = "$cName - $yearStr";
}

// Resolve Sanad Record
$sanadStmt = $pdo->prepare("SELECT * FROM issued_sanads WHERE student_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 1");
$sanadStmt->execute([$student_id, $cur_tenant_id]);
$issued = $sanadStmt->fetch(PDO::FETCH_ASSOC);

$sanad_serial = $issued['sanad_serial_no'] ?? null;
if (empty($sanad_serial)) {
    $serialSuffix = str_pad((string)$student_id, 4, '0', STR_PAD_LEFT);
    $sanad_serial = "SANAD-" . $current_hijri_year . "-" . $serialSuffix;
    
    try {
        $ins = $pdo->prepare("INSERT INTO issued_sanads (madrasa_id, student_id, sanad_serial_no, sanad_title, department, completion_date, issue_date, wifaq_marks, grade) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)");
        $compDate = !empty($s['graduation_date']) ? $s['graduation_date'] : date('Y-m-d');
        $wifaqM = !empty($s['wifaq_marks']) ? $s['wifaq_marks'] : '';
        $ins->execute([$student_id, $sanad_serial, $defaultSanadTitle, $defaultDept, $compDate, date('Y-m-d'), $wifaqM, 'ممتاز']);
    } catch (Exception $e) {}
}

// Convert English digits to Urdu numerals
function toUrduDigits($num) {
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $urdu = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($eng, $urdu, (string)$num);
}

// Format Hijri Date helper
function formatSanadDate($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00') {
        return date('d-m-Y');
    }
    return date('d-m-Y', strtotime($dateStr));
}

$student_name = $s['name'] ?? '';
$father_name = $s['father_name'] ?? '';
$admission_no = $s['admission_number'] ?? ('STD-' . $student_id);
$grad_date = !empty($s['graduation_date']) ? formatSanadDate($s['graduation_date']) : formatSanadDate(date('Y-m-d'));
$dept_name = $issued['department'] ?? (!empty($s['class']) ? $s['class'] : 'شعبہ حفظ القرآن الکریم');
$sanad_title = $issued['sanad_title'] ?? 'سند الفراغ فی حفظ القرآن الکریم';
$wifaq_marks = $s['wifaq_marks'] ?? ($issued['wifaq_marks'] ?? '');
$grade = $issued['grade'] ?? 'ممتاز';

// Active Template Mode (Custom Background or Royal Islamic Frame)
$templateMode = isset($_GET['mode']) ? $_GET['mode'] : ($sanad_bg ? 'custom' : 'islamic');

// Default Layout Positions for Custom Background Calibration
$defaultLayout = [
    'serial_no' => ['label' => 'سیریل نمبر', 'x' => 15, 'y' => 28, 'size' => 16, 'color' => '#284D3D', 'align' => 'right', 'visible' => true],
    'admission_no' => ['label' => 'داخلہ نمبر', 'x' => 82, 'y' => 28, 'size' => 16, 'color' => '#284D3D', 'align' => 'left', 'visible' => true],
    'sanad_title' => ['label' => 'سند کا عنوان', 'x' => 50, 'y' => 35, 'size' => 28, 'color' => '#284D3D', 'align' => 'center', 'visible' => true],
    'student_name' => ['label' => 'طالب علم کا نام', 'x' => 58, 'y' => 45, 'size' => 26, 'color' => '#111111', 'align' => 'center', 'visible' => true],
    'father_name' => ['label' => 'ولدیت', 'x' => 32, 'y' => 45, 'size' => 24, 'color' => '#111111', 'align' => 'center', 'visible' => true],
    'department' => ['label' => 'شعبہ / کلاس', 'x' => 50, 'y' => 53, 'size' => 25, 'color' => '#16a085', 'align' => 'center', 'visible' => true],
    'grad_date' => ['label' => 'تاریخ فراغت', 'x' => 50, 'y' => 61, 'size' => 20, 'color' => '#284D3D', 'align' => 'center', 'visible' => true],
    'wifaq_info' => ['label' => 'وفاق نمبرات / تفصیل', 'x' => 50, 'y' => 68, 'size' => 19, 'color' => '#333333', 'align' => 'center', 'visible' => true],
    'sig_nazim' => ['label' => 'دستخط ناظم', 'x' => 18, 'y' => 84, 'size' => 16, 'color' => '#284D3D', 'align' => 'center', 'visible' => true],
    'stamp' => ['label' => 'مہر / اسٹیمپ', 'x' => 50, 'y' => 83, 'size' => 90, 'color' => '#000000', 'align' => 'center', 'visible' => true],
    'sig_mohtamim' => ['label' => 'دستخط مہتمم', 'x' => 78, 'y' => 84, 'size' => 16, 'color' => '#284D3D', 'align' => 'center', 'visible' => true],
    'qr_code' => ['label' => 'کیو آر کوڈ', 'x' => 90, 'y' => 83, 'size' => 75, 'color' => '#000000', 'align' => 'center', 'visible' => true]
];

$layout = $defaultLayout;
if (is_array($savedLayout)) {
    foreach ($savedLayout as $k => $cfg) {
        if (is_array($cfg)) {
            $def = $defaultLayout[$k] ?? ['label' => $k, 'visible' => true, 'is_custom' => true];
            $layout[$k] = array_merge($def, $cfg);
        }
    }
}

function getSanadFieldDefaultText($key, $vars) {
    switch ($key) {
        case 'serial_no': return $vars['sanad_serial'];
        case 'admission_no': return $vars['admission_no'];
        case 'sanad_title': return $vars['sanad_title'];
        case 'student_name': return $vars['student_name'];
        case 'father_name': return $vars['father_name'];
        case 'department': return $vars['dept_name'];
        case 'grad_date': return 'بتاریخ: ' . $vars['grad_date'] . 'ء';
        case 'wifaq_info': 
            return !empty($vars['wifaq_marks']) 
                ? 'امتحان وفاق: ' . $vars['wifaq_marks'] . ' نمبرات (درجہ ' . $vars['grade'] . ')' 
                : 'تعلیمی کارکردگی و اخلاق: تسلی بخش';
        case 'sig_nazim': return 'دستخط ناظمِ تعلیمات';
        case 'sig_mohtamim': return 'دستخط مہتمم / پرنسپل';
        default: return '';
    }
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سند الفراغ - <?php echo htmlspecialchars($student_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Amiri:ital,wght@0,400;0,700;1,400&family=Reem+Kufi:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: local('Jameel Noori Nastaleeq'), url('fonts/JameelNooriNastaleeq.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: local('Jameel Noori Nastaleeq'), url('fonts/JameelNooriNastaleeq.ttf') format('truetype');
            font-weight: bold;
            font-style: normal;
        }
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: local('Jameel Noori Nastaleeq'), url('fonts/JameelNooriNastaleeq.ttf') format('truetype');
            font-weight: 100 900;
            font-style: normal;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            background: #eef1f5;
            font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #1a1a1a;
            user-select: none;
        }

        /* Top Action Toolbar */
        .action-toolbar {
            width: 100%;
            background: #284D3D;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            padding: 7px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            font-family: sans-serif;
            font-size: 13px;
            transition: 0.2s;
        }

        .btn-print { background: #d4af37; color: #1a1a1a; }
        .btn-print:hover { background: #e6c35c; }
        .btn-toggle { background: #16a085; color: white; }
        .btn-toggle:hover { background: #13876f; }
        .btn-calib { background: #34495e; color: white; }
        .btn-calib.active { background: #e67e22; }
        .btn-close { background: rgba(255,255,255,0.2); color: white; }
        .btn-close:hover { background: rgba(255,255,255,0.3); }

        /* Floating Calibration Inspector Panel */
        .calibration-panel {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 340px;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 2px solid #284D3D;
            z-index: 2000;
            padding: 15px;
            display: none;
            font-family: sans-serif;
            font-size: 13px;
            text-align: right;
            direction: rtl;
        }

        .calibration-panel.open { display: block; }
        .calib-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #ddd; padding-bottom: 8px; font-weight: bold; color: #284D3D; font-size: 15px; }
        .calib-row { margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .calib-row label { width: 90px; margin: 0; font-weight: bold; color: #444; }
        .calib-row input[type="range"] { flex: 1; }
        .calib-row input[type="number"] { width: 65px; padding: 4px; border: 1px solid #ccc; border-radius: 4px; text-align: center; }

        /* Certificate Container: Standard A4 Landscape (297mm x 210mm) */
        .sanad-wrapper {
            padding: 25px 15px;
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .sanad-container {
            width: 297mm;
            height: 210mm;
            position: relative;
            background-color: #ffffff;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 14mm 16mm;
        }

        /* 1. Custom Background Mode */
        .sanad-custom-bg {
            background-image: url('<?= htmlspecialchars(!empty($sanad_bg) ? $sanad_bg : 'uploads/receipt_bg.webp') ?>');
            background-size: 100% 100%;
            background-repeat: no-repeat;
            background-position: center;
            border: none;
        }

        /* 2. Default Royal Islamic Frame Mode */
        .sanad-islamic-frame {
            background: radial-gradient(circle at center, #ffffff 65%, #fbf9f3 100%);
            border: 14px double #284D3D;
            outline: 5px solid #d4af37;
            outline-offset: -10px;
            position: relative;
        }

        .corner-ribbon {
            position: absolute;
            width: 80px;
            height: 80px;
            border: 3px solid #d4af37;
            pointer-events: none;
        }
        .corner-tl { top: 12px; left: 12px; border-right: none; border-bottom: none; }
        .corner-tr { top: 12px; right: 12px; border-left: none; border-bottom: none; }
        .corner-bl { bottom: 12px; left: 12px; border-right: none; border-top: none; }
        .corner-br { bottom: 12px; right: 12px; border-left: none; border-top: none; }

        /* Draggable Calibration Fields for Custom Background */
        .draggable-field {
            position: absolute;
            white-space: nowrap;
            cursor: default;
            transition: outline 0.15s;
            line-height: 1.3 !important;
            display: inline-block !important;
            user-select: none;
            font-family: 'Amiri', 'Traditional Arabic', 'Scheherazade New', serif;
        }

        .draggable-field.field-hidden {
            display: none !important;
        }

        body.calibration-active .draggable-field.field-hidden {
            display: inline-block !important;
            opacity: 0.38 !important;
            outline: 2px dotted #e74c3c !important;
            background: rgba(231, 76, 60, 0.1) !important;
        }

        body.calibration-active .draggable-field {
            cursor: move !important;
            outline: 2px dashed #e67e22;
            background: rgba(230, 126, 34, 0.08);
            border-radius: 4px;
            padding: 2px 6px;
        }

        body.calibration-active .draggable-field:hover,
        body.calibration-active .draggable-field.selected {
            outline: 2px solid #27ae60;
            background: rgba(39, 174, 96, 0.18);
        }

        /* Elements inside Royal Islamic Frame */
        .islamic-header { text-align: center; }
        .bismillah { font-family: 'Amiri', serif; font-size: 26px; color: #284D3D; margin-bottom: 2px; }
        .quran-verse { font-family: 'Amiri', serif; font-size: 15px; color: #8c7335; margin-bottom: 4px; }
        .madrasa-row { display: flex; align-items: center; justify-content: center; gap: 15px; }
        .madrasa-logo { width: 70px; height: 70px; object-fit: contain; }
        .madrasa-title { font-size: 38px; font-weight: bold; color: #284D3D; margin: 0; line-height: 1.1; }
        .madrasa-sub { font-size: 15px; color: #555; }
        
        .sanad-main-badge {
            display: inline-block;
            background: linear-gradient(135deg, #284D3D 0%, #1a3328 100%);
            color: #d4af37;
            padding: 3px 35px;
            border-radius: 30px;
            font-size: 26px;
            font-weight: bold;
            margin: 6px auto 0 auto;
            border: 2px solid #d4af37;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .meta-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 15px;
            color: #333;
            margin-top: 4px;
            padding: 0 10px;
        }

        .meta-num {
            font-family: Arial, sans-serif;
            font-weight: bold;
            color: #284D3D;
            direction: ltr;
            display: inline-block;
        }

        .sanad-prose {
            text-align: justify;
            font-size: 22px;
            line-height: 2.1;
            padding: 0 15px;
            color: #222;
        }

        .highlight-text {
            font-weight: bold;
            color: #284D3D;
            font-size: 25px;
            padding: 0 8px;
            border-bottom: 2px dotted #8c7335;
            display: inline-block;
        }

        .islamic-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding: 0 20px 5px 20px;
        }

        .sig-block { text-align: center; width: 170px; }
        .sig-underline { border-bottom: 2px solid #284D3D; margin-bottom: 5px; height: 40px; position: relative; }
        .sig-caption { font-size: 16px; font-weight: bold; color: #284D3D; }

        .stamp-block { text-align: center; }
        .stamp-graphic { max-width: 90px; max-height: 90px; object-fit: contain; }

        .qr-block { text-align: center; }
        .qr-graphic { width: 70px; height: 70px; border: 2px solid #d4af37; border-radius: 6px; padding: 2px; background: white; }
        .qr-caption { font-size: 10px; font-family: Arial, sans-serif; color: #666; margin-top: 2px; }

        /* Print Media Styles */
        @media print {
            body { background: white; padding: 0; }
            .action-toolbar, .calibration-panel { display: none !important; }
            .sanad-wrapper { padding: 0; margin: 0; }
            .sanad-container {
                box-shadow: none !important;
                width: 297mm !important;
                height: 210mm !important;
                margin: 0 !important;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .draggable-field { outline: none !important; background: transparent !important; }
            .draggable-field.field-hidden { display: none !important; }
            @page { size: A4 landscape; margin: 0; }
        }
    </style>
</head>
<body>

    <!-- Action Toolbar (Hidden during Print) -->
    <div class="action-toolbar">
        <div style="display:flex; gap:12px; align-items:center;">
            <a href="alumni.php" class="btn btn-close"><i class="fas fa-arrow-right"></i> واپسی رجسٹر فارغین</a>
            <span style="font-weight:bold; font-size:15px;">سند الفراغ - <?= htmlspecialchars($student_name) ?></span>
            <span style="background:rgba(255,255,255,0.2); padding:2px 10px; border-radius:12px; font-size:12px; font-family:Arial;">
                <?= htmlspecialchars($sanad_serial) ?>
            </span>
        </div>

        <div style="display:flex; gap:8px; align-items:center; flex-wrap: wrap;">
            <!-- Toggle Mode: Islamic Frame vs Custom Uploaded Background -->
            <?php if ($templateMode === 'custom'): ?>
                <a href="print_sanad.php?student_id=<?= $student_id ?>&mode=islamic" class="btn btn-toggle">
                    <i class="fas fa-palette"></i> شاہکار اسلامی ڈیزائن موڈ
                </a>
                <button type="button" class="btn btn-calib" id="toggleCalibBtn" onclick="toggleCalibrationMode()">
                    <i class="fas fa-arrows-alt"></i> کیلیبریشن و ڈریگ موڈ
                </button>
            <?php else: ?>
                <a href="print_sanad.php?student_id=<?= $student_id ?>&mode=custom" class="btn btn-toggle">
                    <i class="fas fa-image"></i> کسٹم بیک گراؤنڈ و کیلیبریشن موڈ
                </a>
            <?php endif; ?>

            <button class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> پرنٹ / پی ڈی ایف
            </button>
        </div>
    </div>

    <!-- Floating Calibration Inspector (Visual Drag & Drop + Numeric Calibration) -->
    <div class="calibration-panel" id="calibPanel">
        <div class="calib-header">
            <span><i class="fas fa-sliders-h me-1"></i> کوآرڈینیٹس و فیلڈز کیلیبریٹر</span>
            <button type="button" onclick="toggleCalibrationMode()" style="background:none; border:none; color:#888; font-size:18px; cursor:pointer;">&times;</button>
        </div>

        <div style="display:flex; gap:6px; margin-bottom:12px;">
            <button type="button" class="btn" style="flex:1; background:#2980b9; color:white; padding:6px 8px; font-size:12px;" onclick="addNewCustomField()">
                <i class="fas fa-plus-circle me-1"></i> + نئی فیلڈ ایڈ کریں
            </button>
            <button type="button" id="toggleVisibilityBtn" class="btn" style="background:#f39c12; color:white; padding:6px 10px; font-size:12px;" onclick="toggleCurrentFieldVisibility()" title="اس فیلڈ کو ریموو/ہائڈ یا ظاہر کریں">
                <i class="fas fa-eye-slash me-1"></i> <span id="visibilityBtnText">ریموو کریں</span>
            </button>
            <button type="button" id="deleteCustomFieldBtn" class="btn" style="background:#c0392b; color:white; padding:6px 10px; font-size:12px; display:none;" onclick="deleteCurrentCustomField()" title="کسٹم فیلڈ حذف کریں">
                <i class="fas fa-trash"></i>
            </button>
        </div>

        <div class="calib-row">
            <label>منتخب فیلڈ:</label>
            <select id="fieldSelector" class="form-select" style="flex:1; padding:4px; font-family:sans-serif;" onchange="selectFieldFromDropdown(this.value)">
                <?php foreach ($layout as $fKey => $cfg): ?>
                    <option value="<?= $fKey ?>"><?= htmlspecialchars($cfg['label'] ?? $fKey) ?><?= (($cfg['visible'] ?? true) === false) ? ' [مخفی/حذف شدہ]' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="calib-row" id="labelRow">
            <label>فیلڈ کا نام (لیبل):</label>
            <input type="text" id="fieldLabelInput" class="form-control" style="flex:1; padding:4px; font-family:sans-serif; border:1px solid #ccc; border-radius:4px;" oninput="updateFieldLabelFromInput()" placeholder="فیلڈ کا نام...">
        </div>

        <div class="calib-row" id="textRow">
            <label>متن / مواد (Text):</label>
            <input type="text" id="fieldTextInput" class="form-control" style="flex:1; padding:4px; font-family:'Amiri', 'Traditional Arabic', 'Scheherazade New', serif; border:1px solid #ccc; border-radius:4px; font-size:16px;" oninput="updateFieldTextFromInput()" placeholder="فیلڈ کا متن لکھیں...">
        </div>

        <div class="calib-row">
            <label>افقی پوزیشن (X %):</label>
            <input type="range" id="xSlider" min="0" max="100" step="0.5" oninput="updateCurrentFieldFromInputs()">
            <input type="number" id="xNum" min="0" max="100" step="0.5" oninput="syncInputs('x')">
        </div>

        <div class="calib-row">
            <label>عمودی پوزیشن (Y %):</label>
            <input type="range" id="ySlider" min="0" max="100" step="0.5" oninput="updateCurrentFieldFromInputs()">
            <input type="number" id="yNum" min="0" max="100" step="0.5" oninput="syncInputs('y')">
        </div>

        <div class="calib-row">
            <label>سائز / فونٹ (px):</label>
            <input type="range" id="sizeSlider" min="8" max="160" step="1" oninput="updateCurrentFieldFromInputs()">
            <input type="number" id="sizeNum" min="8" max="160" oninput="syncInputs('size')">
        </div>

        <div class="calib-row">
            <label>رنگ (Color):</label>
            <input type="color" id="colorPicker" style="width:50px; height:30px; border:none; cursor:pointer;" onchange="updateCurrentFieldFromInputs()">
            <select id="alignPicker" style="flex:1; padding:4px;" onchange="updateCurrentFieldFromInputs()">
                <option value="center">درمیان (Center)</option>
                <option value="right">دائیں (Right)</option>
                <option value="left">بائیں (Left)</option>
            </select>
        </div>

        <div style="margin-top:12px; display:flex; gap:8px;">
            <button type="button" class="btn" style="flex:1; background:#27ae60; color:white;" onclick="saveCalibrationCoordinates()">
                <i class="fas fa-save"></i> تمام ترامیم محفوظ کریں
            </button>
            <button type="button" class="btn" style="background:#e74c3c; color:white;" onclick="resetCalibrationCoordinates()">
                <i class="fas fa-undo"></i> اصل حالت
            </button>
        </div>
        <div id="saveMsg" style="margin-top:8px; font-weight:bold; text-align:center; font-size:12px;"></div>
    </div>

    <!-- Certificate Wrapper -->
    <div class="sanad-wrapper">
        <div class="sanad-container <?= ($templateMode === 'custom') ? 'sanad-custom-bg' : 'sanad-islamic-frame' ?>" id="sanadContainer">
            
            <?php if ($templateMode === 'custom'): ?>
                <!-- ==================== MODE 1: CUSTOM BACKGROUND WITH DRAGGABLE CALIBRATED FIELDS ==================== -->
                <?php
                $templateVars = [
                    'sanad_serial' => $sanad_serial,
                    'admission_no' => $admission_no,
                    'sanad_title'  => $sanad_title,
                    'student_name' => $student_name,
                    'father_name'  => $father_name,
                    'dept_name'    => $dept_name,
                    'grad_date'    => $grad_date,
                    'wifaq_marks'  => $wifaq_marks,
                    'grade'        => $grade
                ];

                foreach ($layout as $fKey => $fCfg):
                    $isVisible = !isset($fCfg['visible']) || $fCfg['visible'] !== false;
                    $hiddenClass = $isVisible ? '' : ' field-hidden';
                    $posX = $fCfg['x'] ?? 50;
                    $posY = $fCfg['y'] ?? 50;
                    $size = $fCfg['size'] ?? 24;
                    $color = $fCfg['color'] ?? '#111111';
                    $align = $fCfg['align'] ?? 'center';
                    $isUrdu = !in_array($fKey, ['serial_no', 'admission_no']);
                    $fontFamily = $isUrdu ? "'Amiri', 'Traditional Arabic', 'Scheherazade New', serif" : "Arial, sans-serif";
                    $direction = $isUrdu ? "rtl" : "ltr";
                ?>
                    <?php if ($fKey === 'stamp'): ?>
                        <div id="field_stamp" class="draggable-field<?= $hiddenClass ?>" data-field="stamp" style="right: <?= $posX ?>%; top: <?= $posY ?>%; width: <?= $size ?>px; text-align: center;">
                            <?php if ($stamp_path): ?>
                                <img src="<?= htmlspecialchars($stamp_path) ?>" style="max-width:100%; max-height:100%;" alt="Stamp">
                            <?php else: ?>
                                <div style="width:75px; height:75px; border:2px dashed #d4af37; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; color:#d4af37;">مقامِ مہر</div>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($fKey === 'qr_code'): ?>
                        <div id="field_qr_code" class="draggable-field<?= $hiddenClass ?>" data-field="qr_code" style="right: <?= $posX ?>%; top: <?= $posY ?>%; width: <?= $size ?>px; text-align: center;">
                            <?php 
                                $qrData = urlencode("SANAD:" . $student_id . ":" . $admission_no . ":" . $sanad_serial);
                                $qrImgUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . $qrData;
                            ?>
                            <img src="<?= $qrImgUrl ?>" style="width:100%; height:auto; border:1px solid #ccc; border-radius:4px; background:white; padding:2px;" alt="QR">
                        </div>
                    <?php else: 
                        $displayText = (isset($fCfg['text']) && trim($fCfg['text']) !== '') 
                            ? $fCfg['text'] 
                            : getSanadFieldDefaultText($fKey, $templateVars);
                    ?>
                        <div id="field_<?= $fKey ?>" class="draggable-field<?= $hiddenClass ?>" data-field="<?= $fKey ?>" 
                             style="right: <?= $posX ?>%; top: <?= $posY ?>%; font-size: <?= $size ?>px; line-height: <?= max(20, (int)round($size * 1.35)) ?>px; color: <?= $color ?>; text-align: <?= $align ?>; font-family: <?= $fontFamily ?>; direction: <?= $direction ?>; font-weight: normal;">
                            <?= htmlspecialchars($displayText) ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

            <?php else: ?>
                <!-- ==================== MODE 2: ROYAL ISLAMIC DESIGN TEMPLATE ==================== -->
                
                <div class="corner-ribbon corner-tl"></div>
                <div class="corner-ribbon corner-tr"></div>
                <div class="corner-ribbon corner-bl"></div>
                <div class="corner-ribbon corner-br"></div>

                <!-- Header -->
                <div class="islamic-header">
                    <div class="bismillah">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>
                    <div class="quran-verse">يَرْفَعِ اللَّهُ الَّذِينَ آمَنُوا مِنكُمْ وَالَّذِينَ أُوتُوا الْعِلْمَ دَرَجَاتٍ</div>

                    <div class="madrasa-row">
                        <img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo" class="madrasa-logo">
                        <div>
                            <h1 class="madrasa-title"><?= htmlspecialchars($madrasa_name) ?></h1>
                            <div class="madrasa-sub"><?= htmlspecialchars(!empty($madrasa_address) ? $madrasa_address : $tagline) ?></div>
                        </div>
                    </div>

                    <div class="sanad-main-badge"><?= htmlspecialchars($sanad_title) ?></div>

                    <div class="meta-strip">
                        <div>
                            <strong>سیریل نمبر: </strong>
                            <span class="meta-num"><?= htmlspecialchars($sanad_serial) ?></span>
                        </div>
                        <div>
                            <strong>داخلہ نمبر: </strong>
                            <span class="meta-num"><?= htmlspecialchars($admission_no) ?></span>
                        </div>
                        <div>
                            <strong>تاریخِ اجراء: </strong>
                            <span><?= formatSanadDate(date('Y-m-d')) ?>ء</span>
                        </div>
                    </div>
                </div>

                <!-- Body Prose -->
                <div class="sanad-prose">
                    الحمد لله رب العالمين والصلاة والسلام على رسوله محمد وعلى آله وصحبه أجمعين، أما بعد:
                    <br>
                    تصدیق کی جاتی ہے کہ محترم / عزیزم 
                    <span class="highlight-text"><?= htmlspecialchars($student_name) ?></span>
                    فرزندِ ارجمند 
                    <span class="highlight-text"><?= htmlspecialchars($father_name) ?></span>
                    نے اس ادارے میں باقاعدہ داخلہ لے کر حسنِ سعی اور انتھک محنت کے ساتھ 
                    <span class="highlight-text"><?= htmlspecialchars($dept_name) ?></span>
                    کا تمام نصاب مکمل کر کے بتاریخ 
                    <span class="highlight-text"><?= htmlspecialchars($grad_date) ?>ء</span>
                    فراغت و تکمیل کی سعادت حاصل کی ہے۔
                    <?php if (!empty($wifaq_marks)): ?>
                        اور وفاق المدارس العربیہ کے سالانہ امتحان میں 
                        <span class="highlight-text"><?= htmlspecialchars($wifaq_marks) ?></span>
                        نمبرات حاصل کر کے درجہ 
                        <span class="highlight-text"><?= htmlspecialchars($grade) ?></span>
                        میں کامیابی پائی ہے۔
                    <?php else: ?>
                        موصوف کے اخلاق و کردار اور تعلیمی کارکردگی کو تسلی بخش پایا گیا۔
                    <?php endif; ?>
                    <br>
                    ادارہ ان کے روشن مستقبل، دارین کی سعادت اور دینِ مبین کی مخلصانہ خدمت کے لیے دست بدعا ہے۔
                </div>

                <!-- Footer -->
                <div class="islamic-footer">
                    <div class="sig-block">
                        <div class="sig-underline"></div>
                        <div class="sig-caption">دستخط ناظمِ تعلیمات</div>
                    </div>

                    <div class="stamp-block">
                        <?php if ($stamp_path): ?>
                            <img src="<?= htmlspecialchars($stamp_path) ?>" alt="Stamp" class="stamp-graphic">
                        <?php else: ?>
                            <div style="width: 80px; height: 80px; border: 2px dashed #8c7335; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #8c7335; font-weight: bold;">
                                مقامِ مہر
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="sig-block">
                        <div class="sig-underline">
                            <?php if ($stamp_path): ?>
                                <img src="<?= htmlspecialchars($stamp_path) ?>" style="max-height: 40px; position: absolute; opacity: 0.85; right: 25%;" alt="Signature">
                            <?php endif; ?>
                        </div>
                        <div class="sig-caption">دستخط مہتمم / پرنسپل</div>
                    </div>

                    <div class="qr-block">
                        <?php 
                            $qrData = urlencode("SANAD:" . $student_id . ":" . $admission_no . ":" . $sanad_serial);
                            $qrImgUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . $qrData;
                        ?>
                        <img src="<?= $qrImgUrl ?>" alt="Verification QR" class="qr-graphic">
                        <div class="qr-caption">تصدیقی کیو آر کوڈ</div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <!-- Calibration & Drag-and-Drop JavaScript Engine -->
    <script>
        let layoutData = <?= json_encode($layout) ?>;
        const defaultLayout = <?= json_encode($defaultLayout) ?>;
        let activeFieldKey = 'student_name';
        let isCalibActive = false;
        let isDragging = false;
        let dragElem = null;
        let startX = 0, startY = 0;
        let origRight = 0, origTop = 0;
        const container = document.getElementById('sanadContainer');

        function initDraggable(elem) {
            elem.addEventListener('mousedown', function(e) {
                if (!isCalibActive) return;
                e.preventDefault();
                dragElem = this;
                const fieldKey = this.getAttribute('data-field');
                selectFieldFromDropdown(fieldKey);

                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;

                const cRect = container.getBoundingClientRect();
                const eRect = dragElem.getBoundingClientRect();
                
                origRight = (layoutData[fieldKey] && layoutData[fieldKey].x !== undefined)
                    ? layoutData[fieldKey].x
                    : ((cRect.right - eRect.right) / cRect.width * 100);
                origTop = (layoutData[fieldKey] && layoutData[fieldKey].y !== undefined)
                    ? layoutData[fieldKey].y
                    : ((eRect.top - cRect.top) / cRect.height * 100);
            });

            elem.addEventListener('dblclick', function(e) {
                if (!isCalibActive) return;
                e.preventDefault();
                const fieldKey = this.getAttribute('data-field');
                selectFieldFromDropdown(fieldKey);
                if (fieldKey !== 'stamp' && fieldKey !== 'qr_code') {
                    const input = document.getElementById('fieldTextInput');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }
            });
        }

        function toggleCalibrationMode() {
            isCalibActive = !isCalibActive;
            const btn = document.getElementById('toggleCalibBtn');
            const panel = document.getElementById('calibPanel');
            
            if (isCalibActive) {
                document.body.classList.add('calibration-active');
                if (btn) btn.classList.add('active');
                if (panel) panel.classList.add('open');
                selectFieldFromDropdown(activeFieldKey);
            } else {
                document.body.classList.remove('calibration-active');
                if (btn) btn.classList.remove('active');
                if (panel) panel.classList.remove('open');
            }
        }

        function selectFieldFromDropdown(fieldKey) {
            if (!layoutData[fieldKey]) {
                const firstKey = Object.keys(layoutData)[0];
                if (!firstKey) return;
                fieldKey = firstKey;
            }
            activeFieldKey = fieldKey;
            document.querySelectorAll('.draggable-field').forEach(el => el.classList.remove('selected'));
            const el = document.getElementById('field_' + fieldKey);
            if (el) el.classList.add('selected');

            const data = layoutData[fieldKey] || { x: 50, y: 50, size: 24, color: '#111111', align: 'center', visible: true, label: fieldKey };
            
            // Selector dropdown
            const selector = document.getElementById('fieldSelector');
            if (selector) selector.value = fieldKey;

            // Field Label Input
            const labelInput = document.getElementById('fieldLabelInput');
            if (labelInput) labelInput.value = data.label || fieldKey;

            // Text Input & Row
            const textRow = document.getElementById('textRow');
            const textInput = document.getElementById('fieldTextInput');
            if (fieldKey === 'stamp' || fieldKey === 'qr_code') {
                if (textRow) textRow.style.display = 'none';
            } else {
                if (textRow) textRow.style.display = 'flex';
                if (textInput) {
                    textInput.value = (data.text !== undefined) ? data.text : (el ? el.innerText.trim() : '');
                }
            }

            // Numeric inputs & sliders
            document.getElementById('xSlider').value = data.x ?? 50;
            document.getElementById('xNum').value = data.x ?? 50;
            document.getElementById('ySlider').value = data.y ?? 50;
            document.getElementById('yNum').value = data.y ?? 50;
            document.getElementById('sizeSlider').value = data.size ?? 24;
            document.getElementById('sizeNum').value = data.size ?? 24;
            document.getElementById('colorPicker').value = data.color || '#111111';
            document.getElementById('alignPicker').value = data.align || 'center';

            // Visibility Toggle Button State
            const isVis = (data.visible !== false);
            const visBtn = document.getElementById('toggleVisibilityBtn');
            if (visBtn) {
                if (isVis) {
                    visBtn.style.background = '#f39c12';
                    visBtn.innerHTML = '<i class="fas fa-eye-slash me-1"></i> <span id="visibilityBtnText">ریموو کریں</span>';
                    visBtn.title = 'اس فیلڈ کو ریموو / چھپائیں';
                } else {
                    visBtn.style.background = '#27ae60';
                    visBtn.innerHTML = '<i class="fas fa-eye me-1"></i> <span id="visibilityBtnText">ظاہر کریں</span>';
                    visBtn.title = 'اس فیلڈ کو دوبارہ سند پر ظاہر کریں';
                }
            }

            // Delete Button (Custom Fields Only)
            const delBtn = document.getElementById('deleteCustomFieldBtn');
            if (delBtn) {
                delBtn.style.display = data.is_custom ? 'inline-block' : 'none';
            }
        }

        function syncInputs(type) {
            if (type === 'x') document.getElementById('xSlider').value = document.getElementById('xNum').value;
            if (type === 'y') document.getElementById('ySlider').value = document.getElementById('yNum').value;
            if (type === 'size') document.getElementById('sizeSlider').value = document.getElementById('sizeNum').value;
            updateCurrentFieldFromInputs();
        }

        function updateCurrentFieldFromInputs() {
            const x = parseFloat(document.getElementById('xSlider').value);
            const y = parseFloat(document.getElementById('ySlider').value);
            const size = parseInt(document.getElementById('sizeSlider').value);
            const color = document.getElementById('colorPicker').value;
            const align = document.getElementById('alignPicker').value;

            document.getElementById('xNum').value = x;
            document.getElementById('yNum').value = y;
            document.getElementById('sizeNum').value = size;

            if (!layoutData[activeFieldKey]) {
                layoutData[activeFieldKey] = {};
            }
            layoutData[activeFieldKey].x = x;
            layoutData[activeFieldKey].y = y;
            layoutData[activeFieldKey].size = size;
            layoutData[activeFieldKey].color = color;
            layoutData[activeFieldKey].align = align;

            const el = document.getElementById('field_' + activeFieldKey);
            if (el) {
                el.style.right = x + '%';
                el.style.top = y + '%';
                if (activeFieldKey === 'stamp' || activeFieldKey === 'qr_code') {
                    el.style.width = size + 'px';
                } else {
                    el.style.setProperty('font-size', size + 'px', 'important');
                    el.style.setProperty('line-height', Math.max(20, Math.round(size * 1.35)) + 'px', 'important');
                    el.style.color = color;
                    el.style.textAlign = align;
                }
            }
        }

        function updateFieldTextFromInput() {
            const text = document.getElementById('fieldTextInput').value;
            if (!layoutData[activeFieldKey]) layoutData[activeFieldKey] = {};
            layoutData[activeFieldKey].text = text;

            const el = document.getElementById('field_' + activeFieldKey);
            if (el && activeFieldKey !== 'stamp' && activeFieldKey !== 'qr_code') {
                el.innerText = text;
            }
        }

        function updateFieldLabelFromInput() {
            const label = document.getElementById('fieldLabelInput').value.trim();
            if (!layoutData[activeFieldKey]) layoutData[activeFieldKey] = {};
            layoutData[activeFieldKey].label = label || activeFieldKey;

            const opt = document.querySelector('#fieldSelector option[value="' + activeFieldKey + '"]');
            if (opt) {
                const isHidden = (layoutData[activeFieldKey].visible === false);
                opt.innerText = (label || activeFieldKey) + (isHidden ? ' [مخفی/حذف شدہ]' : '');
            }
        }

        function toggleCurrentFieldVisibility() {
            if (!layoutData[activeFieldKey]) return;
            const currentVis = (layoutData[activeFieldKey].visible !== false);
            const newVis = !currentVis;
            layoutData[activeFieldKey].visible = newVis;

            const el = document.getElementById('field_' + activeFieldKey);
            if (el) {
                if (newVis) {
                    el.classList.remove('field-hidden');
                } else {
                    el.classList.add('field-hidden');
                }
            }

            const opt = document.querySelector('#fieldSelector option[value="' + activeFieldKey + '"]');
            if (opt) {
                const label = layoutData[activeFieldKey].label || activeFieldKey;
                opt.innerText = label + (newVis ? '' : ' [مخفی/حذف شدہ]');
            }

            selectFieldFromDropdown(activeFieldKey);
        }

        function addNewCustomField() {
            const fieldName = prompt('نئی فیلڈ کا نام (لیبل) درج کریں:\n(مثلاً: ناظمِ اعلیٰ، تاریخِ پیدائش، شناختی کارڈ وغیرہ)');
            if (!fieldName || !fieldName.trim()) return;

            const initialText = prompt('اس فیلڈ کا متن / مواد درج کریں:', fieldName.trim());
            if (initialText === null) return;

            const newKey = 'custom_' + Date.now();
            const newConfig = {
                label: fieldName.trim(),
                text: initialText.trim(),
                x: 50,
                y: 50,
                size: 24,
                color: '#111111',
                align: 'center',
                visible: true,
                is_custom: true
            };

            layoutData[newKey] = newConfig;

            // Create DOM element
            const newEl = document.createElement('div');
            newEl.id = 'field_' + newKey;
            newEl.className = 'draggable-field';
            newEl.setAttribute('data-field', newKey);
            newEl.style.right = '50%';
            newEl.style.top = '50%';
            newEl.style.setProperty('font-size', '24px', 'important');
            newEl.style.setProperty('line-height', '32px', 'important');
            newEl.style.color = '#111111';
            newEl.style.textAlign = 'center';
            newEl.style.fontFamily = "'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif";
            newEl.style.direction = 'rtl';
            newEl.style.fontWeight = 'normal';
            newEl.innerText = initialText.trim();
            
            container.appendChild(newEl);
            initDraggable(newEl);

            // Add option to dropdown
            const opt = document.createElement('option');
            opt.value = newKey;
            opt.innerText = fieldName.trim();
            document.getElementById('fieldSelector').appendChild(opt);

            selectFieldFromDropdown(newKey);

            const msgEl = document.getElementById('saveMsg');
            if (msgEl) {
                msgEl.innerHTML = "<span style='color:#2980b9;'>✔ نئی فیلڈ شامل ہو گئی! پوزیشن سیٹ کر کے محفوظ کریں۔</span>";
                setTimeout(() => msgEl.innerHTML = '', 4000);
            }
        }

        function deleteCurrentCustomField() {
            if (!layoutData[activeFieldKey] || !layoutData[activeFieldKey].is_custom) {
                alert('صرف کسٹم فیلڈز کو مستقل ڈیلیٹ کیا جا سکتا ہے۔ بنیادی فیلڈز کو ریموو/چھپانے کے لیے "ریموو کریں" کا بٹن استعمال کریں۔');
                return;
            }

            const fieldLabel = layoutData[activeFieldKey].label || activeFieldKey;
            if (!confirm(`کیا آپ واقعی فیلڈ "${fieldLabel}" کو مستقل حذف کرنا چاہتے ہیں؟`)) return;

            const el = document.getElementById('field_' + activeFieldKey);
            if (el) el.remove();

            const opt = document.querySelector('#fieldSelector option[value="' + activeFieldKey + '"]');
            if (opt) opt.remove();

            delete layoutData[activeFieldKey];

            const remainingKeys = Object.keys(layoutData);
            const nextKey = remainingKeys.includes('student_name') ? 'student_name' : remainingKeys[0];
            if (nextKey) {
                selectFieldFromDropdown(nextKey);
            }

            const msgEl = document.getElementById('saveMsg');
            if (msgEl) {
                msgEl.innerHTML = "<span style='color:#c0392b;'>✔ فیلڈ حذف کر دی گئی۔ ترامیم محفوظ کرنا نہ بھولیں۔</span>";
                setTimeout(() => msgEl.innerHTML = '', 4000);
            }
        }

        // Initialize drag & drop on existing fields
        document.querySelectorAll('.draggable-field').forEach(elem => {
            initDraggable(elem);
        });

        window.addEventListener('mousemove', function(e) {
            if (!isDragging || !dragElem) return;
            const cRect = container.getBoundingClientRect();
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;

            let newX = origRight - (dx / cRect.width * 100);
            let newY = origTop + (dy / cRect.height * 100);

            newX = Math.max(0, Math.min(100, Math.round(newX * 10) / 10));
            newY = Math.max(0, Math.min(100, Math.round(newY * 10) / 10));

            if (!layoutData[activeFieldKey]) layoutData[activeFieldKey] = {};
            layoutData[activeFieldKey].x = newX;
            layoutData[activeFieldKey].y = newY;

            dragElem.style.right = newX + '%';
            dragElem.style.top = newY + '%';

            document.getElementById('xSlider').value = newX;
            document.getElementById('xNum').value = newX;
            document.getElementById('ySlider').value = newY;
            document.getElementById('yNum').value = newY;
        });

        window.addEventListener('mouseup', function() {
            isDragging = false;
            dragElem = null;
        });

        // AJAX Save to Server
        function saveCalibrationCoordinates() {
            const msgEl = document.getElementById('saveMsg');
            msgEl.innerHTML = "<span style='color:#3498db;'><i class='fas fa-spinner fa-spin'></i> محفوظ ہو رہا ہے...</span>";

            const fd = new FormData();
            fd.append('action', 'save_layout');
            fd.append('layout_json', JSON.stringify(layoutData));

            fetch('print_sanad.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success') {
                    msgEl.innerHTML = "<span style='color:#27ae60;'>✔ لے آؤٹ اور تمام فیلڈز کامیابی سے محفوظ ہو گئیں!</span>";
                    setTimeout(() => msgEl.innerHTML = '', 3500);
                } else {
                    msgEl.innerHTML = "<span style='color:#e74c3c;'>خرابی: " + res.message + "</span>";
                }
            })
            .catch(err => {
                msgEl.innerHTML = "<span style='color:#e74c3c;'>محفوظ کرنے میں نیٹ ورک خرابی پیدا ہوئی۔</span>";
            });
        }

        function resetCalibrationCoordinates() {
            if (!confirm('کیا آپ تمام پوزیشنز اور سیٹنگز کو اصل ڈیفالٹ حالت پر بحال کرنا چاہتے ہیں؟ (اس سے تمام کسٹم فیلڈز اور ترامیم بھی ختم ہو جائیں گی)')) return;
            
            for (const k in layoutData) {
                if (layoutData[k] && layoutData[k].is_custom) {
                    const el = document.getElementById('field_' + k);
                    if (el) el.remove();
                }
            }

            layoutData = JSON.parse(JSON.stringify(defaultLayout));

            const selector = document.getElementById('fieldSelector');
            selector.innerHTML = '';
            for (const k in layoutData) {
                const opt = document.createElement('option');
                opt.value = k;
                opt.innerText = layoutData[k].label || k;
                selector.appendChild(opt);

                const el = document.getElementById('field_' + k);
                if (el) {
                    el.classList.remove('field-hidden');
                    el.style.right = layoutData[k].x + '%';
                    el.style.top = layoutData[k].y + '%';
                    if (k === 'stamp' || k === 'qr_code') {
                        el.style.width = layoutData[k].size + 'px';
                    } else {
                        el.style.setProperty('font-size', layoutData[k].size + 'px', 'important');
                        el.style.setProperty('line-height', Math.max(20, Math.round(layoutData[k].size * 1.35)) + 'px', 'important');
                        el.style.color = layoutData[k].color;
                        el.style.textAlign = layoutData[k].align;
                    }
                }
            }

            selectFieldFromDropdown('student_name');
            saveCalibrationCoordinates();
        }
    </script>

</body>
</html>
