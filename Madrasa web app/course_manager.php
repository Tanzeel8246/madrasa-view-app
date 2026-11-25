<?php
/**
 * Course & Subject Manager (درس نظامی و عصری علوم — کورسز و مضامین)
 * 110% Tri-Platform Parity with Flutter Mobile App & Multi-Tenant Isolation
 */
require_once 'db.php';
ensure_academic_courses_tables($pdo);
include 'header.php';

$cur_tenant_id = (int)get_current_tenant_id();
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'], true) || app_has_perm('manage_classes');

if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$msg = '';
$msg_type = 'success';

// 1. نیا کورس شامل کرنا یا اپڈیٹ کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_course') {
        $c_id = (int)($_POST['course_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $course_type = trim($_POST['course_type'] ?? 'Darse_Nizami');
        $total_years = max(1, min(12, (int)($_POST['total_years'] ?? 8)));
        $gender = trim($_POST['gender'] ?? 'بنین');
        $desc = trim($_POST['description'] ?? '');

        if (!empty($name)) {
            if ($c_id > 0) {
                $stmt = $pdo->prepare("UPDATE course_structure SET name=?, course_type=?, total_years=?, gender=?, description=?, updated_at=NOW() WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $course_type, $total_years, $gender, $desc, $c_id, $cur_tenant_id]);
                $msg = "کورس کی تفصیلات کامیابی سے تبدیل ہو گئیں۔";
            } else {
                $stmt = $pdo->prepare("INSERT INTO course_structure (name, course_type, total_years, gender, description, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $course_type, $total_years, $gender, $desc, $sys, $cur_tenant_id]);
                $msg = "نیا کورس کامیابی سے شامل کر دیا گیا۔";
            }
        } else {
            $msg = "براہ کرم کورس کا نام درج کریں۔";
            $msg_type = 'danger';
        }
    }

    // 2. نیا مضمون شامل کرنا
    if ($_POST['action'] === 'save_subject') {
        $s_id = (int)($_POST['subject_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $year_no = max(1, (int)($_POST['year_no'] ?? 1));
        $year_name = trim($_POST['year_name'] ?? '');
        $subject_name = trim($_POST['subject_name'] ?? '');
        $book_name = trim($_POST['book_name'] ?? '');
        $max_marks = (float)($_POST['max_marks'] ?? 100);
        $pass_marks = (float)($_POST['pass_marks'] ?? 40);
        $is_optional = isset($_POST['is_optional']) ? 1 : 0;
        $order = (int)($_POST['subject_order'] ?? 1);

        if (!empty($subject_name) && $course_id > 0) {
            if ($s_id > 0) {
                $stmt = $pdo->prepare("UPDATE course_subjects SET year_no=?, year_name=?, subject_name=?, book_name=?, max_marks=?, pass_marks=?, is_optional=?, subject_order=?, updated_at=NOW() WHERE id=? AND tenant_id=?");
                $stmt->execute([$year_no, $year_name, $subject_name, $book_name, $max_marks, $pass_marks, $is_optional, $order, $s_id, $cur_tenant_id]);
                $msg = "مضمون کامیابی سے تبدیل ہو گیا۔";
            } else {
                $stmt = $pdo->prepare("INSERT INTO course_subjects (course_id, year_no, year_name, subject_name, book_name, max_marks, pass_marks, is_optional, subject_order, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$course_id, $year_no, $year_name, $subject_name, $book_name, $max_marks, $pass_marks, $is_optional, $order, $cur_tenant_id]);
                $msg = "نیا مضمون کامیابی سے شامل کر دیا گیا۔";
            }
        } else {
            $msg = "مضمون کا نام اور کورس لازمی ہے۔";
            $msg_type = 'danger';
        }
    }

    // 2.5 مکرر مضامین کی خودکار صفائی (D-25: Deduplicate Subjects)
    if ($_POST['action'] === 'deduplicate_subjects') {
        try {
            $del_dupes_sql = "
                DELETE s1 FROM course_subjects s1
                INNER JOIN course_subjects s2 
                   ON s1.course_id = s2.course_id 
                  AND s1.year_no = s2.year_no 
                  AND TRIM(s1.subject_name) = TRIM(s2.subject_name)
                  AND s1.tenant_id = s2.tenant_id
                  AND s1.id > s2.id
                WHERE s1.tenant_id = ?
            ";
            $stmt = $pdo->prepare($del_dupes_sql);
            $stmt->execute([$cur_tenant_id]);
            $deleted_count = $stmt->rowCount();
            $msg = "دورہ حدیث اور تمام کلاسز کے $deleted_count مکرر (Duplicate) مضامین کامیابی سے صاف ہو گئے۔";
        } catch (Throwable $eDup) {
            $msg = "ڈپلیکیٹ صفائی کے دوران ایرر: " . $eDup->getMessage();
            $msg_type = 'danger';
        }
    }

    // 3. ڈیفالٹ سانچہ جات تیار کرنا (Seed Template)
    if ($_POST['action'] === 'seed_darse_nizami') {
        $preset = $_POST['preset'] ?? 'banin_8';
        if ($preset === 'banin_8') {
            $c_name = "درس نظامی (8 سالہ بنین کورس)";
            $c_type = "Darse_Nizami";
            $tot_years = 8;
            $gnd = "بنین";
            $d_desc = "عامہ تا دورہ حدیث (وفاق المدارس کے نصاب کے مطابق)";
            $stmt = $pdo->prepare("INSERT INTO course_structure (name, course_type, total_years, gender, description, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$c_name, $c_type, $tot_years, $gnd, $d_desc, $sys, $cur_tenant_id]);
            $new_c_id = $pdo->lastInsertId();

            $standard_years = [
                1 => ['name' => 'اولیٰ (عامہ سال اول)', 'subjects' => [['نحو میر', 'نحو میر'], ['صرف میر', 'صرف میر'], ['تسہیل الادب', 'تسہیل الادب'], ['زاد الطالبین', 'زاد الطالبین'], ['فقہ حنفی', 'نور الایضاح']]],
                2 => ['name' => 'ثانیہ (عامہ سال دوم)', 'subjects' => [['ہدایۃ النحو', 'ہدایۃ النحو'], ['علم الصیغہ', 'علم الصیغہ'], ['قدوری', 'مختصر القدوری'], ['نفحة الیمن', 'نفحة الیمن'], ['قصص النبیین', 'قصص النبیین']]],
                3 => ['name' => 'ثالثہ (خاصہ سال اول)', 'subjects' => [['کافیہ', 'الکافیہ'], ['اصول الشاشی', 'اصول الشاشی'], ['قدوری (مکمل)', 'مختصر القدوری'], ['مقامات حریری', 'مقامات حریری']]],
                4 => ['name' => 'رابعہ (خاصہ سال دوم)', 'subjects' => [['شرح جامی', 'شرح جامی'], ['کنز الدقائق', 'کنز الدقائق'], ['نور الانوار', 'نور الانوار'], ['شرح التہذیب', 'شرح التہذیب']]],
                5 => ['name' => 'خامسہ (عالیہ سال اول)', 'subjects' => [['ہدایہ اول', 'الہدایۃ جلد 1'], ['حسامی', 'المنتخب فی اصول المذہب'], ['دیوان الحماسہ', 'دیوان الحماسہ'], ['مختصر المعانی', 'مختصر المعانی']]],
                6 => ['name' => 'سادسہ (عالیہ سال دوم)', 'subjects' => [['ہدایہ ثالث', 'الہدایۃ جلد 3'], ['توضیح و تلویح', 'التوضیح والتلویح'], ['جلالین', 'تفسیر الجلالین'], ['مسلم الثبوت', 'مسلم الثبوت']]],
                7 => ['name' => 'سابعہ (عالمیہ سال اول / موقوف علیہ)', 'subjects' => [['مشکوٰۃ المصابیح', 'مشکوٰۃ شریف جلد 1 و 2'], ['ہدایہ رابع', 'الہدایۃ جلد 4'], ['شرح العقائد النسفیۃ', 'شرح العقائد'], ['بیضاوی', 'تفسیر البیضاوی']]],
                8 => ['name' => 'دورہ حدیث شریف (عالمیہ سال دوم)', 'subjects' => [['صحیح البخاری', 'بخاری شریف'], ['صحیح مسلم', 'مسلم شریف'], ['جامع الترمذی', 'ترمذی شریف'], ['سنن ابی داؤد', 'ابو داؤد شریف'], ['سنن النسائی', 'نسائی شریف'], ['سنن ابن ماجہ', 'ابن ماجہ شریف'], ['موطا امام مالک / امام محمد', 'موطا'], ['شرح معانی الآثار', 'طحاوی شریف']]]
            ];

            foreach ($standard_years as $y_num => $y_data) {
                $order = 1;
                foreach ($y_data['subjects'] as $sb) {
                    $pdo->prepare("INSERT INTO course_subjects (course_id, year_no, year_name, subject_name, book_name, max_marks, pass_marks, subject_order, tenant_id) VALUES (?, ?, ?, ?, ?, 100, 40, ?, ?)")
                        ->execute([$new_c_id, $y_num, $y_data['name'], $sb[0], $sb[1], $order++, $cur_tenant_id]);
                }
            }
            $msg = "8 سالہ درس نظامی بنین کورس مع تمام سالوں کے مضامین کامیابی سے تیار ہو گیا۔";
        } elseif ($preset === 'banat_6') {
            $c_name = "درس نظامی (6 سالہ بنات کورس)";
            $c_type = "Darse_Nizami";
            $tot_years = 6;
            $gnd = "بنات";
            $d_desc = "عامہ تا دورہ حدیث للبنات";
            $stmt = $pdo->prepare("INSERT INTO course_structure (name, course_type, total_years, gender, description, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$c_name, $c_type, $tot_years, $gnd, $d_desc, $sys, $cur_tenant_id]);
            $msg = "6 سالہ درس نظامی بنات کورس کامیابی سے تیار ہو گیا۔";
        } elseif ($preset === 'asri_middle_matric') {
            $c_name = "عصری علوم (مڈل تا میٹرک)";
            $c_type = "Asri";
            $tot_years = 4;
            $gnd = "مشترک";
            $d_desc = "ہفتم، ہشتم، نہم، دہم";
            $stmt = $pdo->prepare("INSERT INTO course_structure (name, course_type, total_years, gender, description, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$c_name, $c_type, $tot_years, $gnd, $d_desc, $sys, $cur_tenant_id]);
            $new_c_id = $pdo->lastInsertId();

            $asri_years = [
                1 => ['name' => 'ہفتم (7th)', 'subjects' => ['انگریزی', 'ریاضی', 'اردو', 'سائنس', 'اسلامیات', 'مطالعہ پاکستان']],
                2 => ['name' => 'ہشتم (8th)', 'subjects' => ['انگریزی', 'ریاضی', 'اردو', 'سائنس', 'اسلامیات', 'مطالعہ پاکستان']],
                3 => ['name' => 'نہم (9th)', 'subjects' => ['انگریزی', 'ریاضی', 'اردو', 'فزکس', 'کیمسٹری', 'بائیولوجی / کمپیوٹر', 'اسلامیات']],
                4 => ['name' => 'دہم (10th)', 'subjects' => ['انگریزی', 'ریاضی', 'اردو', 'فزکس', 'کیمسٹری', 'بائیولوجی / کمپیوٹر', 'مطالعہ پاکستان']]
            ];
            foreach ($asri_years as $y_num => $y_data) {
                $order = 1;
                foreach ($y_data['subjects'] as $s_name) {
                    $pdo->prepare("INSERT INTO course_subjects (course_id, year_no, year_name, subject_name, max_marks, pass_marks, subject_order, tenant_id) VALUES (?, ?, ?, ?, 100, 33, ?, ?)")
                        ->execute([$new_c_id, $y_num, $y_data['name'], $s_name, $order++, $cur_tenant_id]);
                }
            }
            $msg = "عصری علوم مڈل تا میٹرک کورس مع مضامین کامیابی سے تیار ہو گیا۔";
        }
    }

    // 4. طالب علم کی انرولمنٹ
    if ($_POST['action'] === 'enroll_student') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $current_year = (int)($_POST['current_year'] ?? 1);
        $roll = trim($_POST['roll_number'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $en_date = !empty($_POST['enrollment_date']) ? $_POST['enrollment_date'] : date('Y-m-d');

        if ($student_id > 0 && $course_id > 0) {
            $check = $pdo->prepare("SELECT id FROM student_course_enrollment WHERE student_id=? AND course_id=? AND tenant_id=?");
            $check->execute([$student_id, $course_id, $cur_tenant_id]);
            $existing_en_id = $check->fetchColumn();
            if ($existing_en_id) {
                $pdo->prepare("UPDATE student_course_enrollment SET current_year=?, roll_number=?, status=?, enrollment_date=?, updated_at=NOW() WHERE id=?")
                    ->execute([$current_year, $roll, $status, $en_date, $existing_en_id]);
                $msg = "طالب علم کی انرولمنٹ اپڈیٹ کر دی گئی۔";
            } else {
                $pdo->prepare("INSERT INTO student_course_enrollment (student_id, course_id, current_year, roll_number, status, enrollment_date, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$student_id, $course_id, $current_year, $roll, $status, $en_date, $cur_tenant_id]);
                $msg = "طالب علم کو کورس میں داخل کر دیا گیا۔";
            }
        }
    }
}

// ڈیلیٹ ہینڈلر
if (isset($_GET['del_course'])) {
    $del_id = (int)$_GET['del_course'];
    $pdo->prepare("UPDATE course_structure SET is_deleted=1 WHERE id=? AND tenant_id=?")->execute([$del_id, $cur_tenant_id]);
    $msg = "کورس حذف کر دیا گیا۔";
}
if (isset($_GET['del_subject'])) {
    $del_s_id = (int)$_GET['del_subject'];
    $pdo->prepare("UPDATE course_subjects SET is_deleted=1 WHERE id=? AND tenant_id=?")->execute([$del_s_id, $cur_tenant_id]);
    $msg = "مضمون حذف کر دیا گیا۔";
}

// ڈیٹا فیچ کرنا
$courses = $pdo->prepare("
    SELECT c.*, 
    (SELECT COUNT(*) FROM course_subjects s WHERE s.course_id = c.id AND s.is_deleted = 0) as total_subjects,
    (SELECT COUNT(*) FROM student_course_enrollment e WHERE e.course_id = c.id AND e.is_deleted = 0 AND e.status = 'Active') as total_enrolled
    FROM course_structure c 
    WHERE c.tenant_id = ? AND c.system_type = ? AND c.is_deleted = 0 
    ORDER BY c.id ASC
");
$courses->execute([$cur_tenant_id, $sys]);
$courses_list = $courses->fetchAll(PDO::FETCH_ASSOC);

$selected_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : ($courses_list[0]['id'] ?? 0);
$selected_course = null;
foreach ($courses_list as $c) {
    if ($c['id'] == $selected_course_id) {
        $selected_course = $c;
        break;
    }
}

// متعلقہ کورس کے مضامین سال وار
$subjects_by_year = [];
if ($selected_course) {
    $sub_stmt = $pdo->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND tenant_id = ? AND is_deleted = 0 ORDER BY year_no ASC, subject_order ASC, id ASC");
    $sub_stmt->execute([$selected_course_id, $cur_tenant_id]);
    $all_subs = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_subs as $s) {
        $y = (int)$s['year_no'];
        $subjects_by_year[$y][] = $s;
    }
}

// انرولڈ طلبہ کی فہرست
$enrollments = [];
if ($selected_course) {
    $en_stmt = $pdo->prepare("
        SELECT e.*, st.name as student_name, st.father_name, st.admission_number, st.class
        FROM student_course_enrollment e
        JOIN students st ON e.student_id = st.id
        WHERE e.course_id = ? AND e.tenant_id = ? AND e.is_deleted = 0
        ORDER BY e.current_year ASC, st.name ASC
    ");
    $en_stmt->execute([$selected_course_id, $cur_tenant_id]);
    $enrollments = $en_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// تمام طلبہ برائے انرولمنٹ ڈراپ ڈاؤن
$all_students_stmt = $pdo->prepare("SELECT id, name, father_name, admission_number, class FROM students WHERE tenant_id = ? AND system_type = ? AND is_deleted = 0 ORDER BY name ASC");
$all_students_stmt->execute([$cur_tenant_id, $sys]);
$all_students = $all_students_stmt->fetchAll(PDO::FETCH_ASSOC);

function get_year_urdu_label($year_no, $course_type = 'Darse_Nizami') {
    $darse_nizami_names = [
        1 => 'سال اول (اولیٰ)',
        2 => 'سال دوم (ثانیہ)',
        3 => 'سال سوم (ثالثہ)',
        4 => 'سال چہارم (رابعہ)',
        5 => 'سال پنجم (خامسہ)',
        6 => 'سال ششم (سادسہ)',
        7 => 'سال ہفتم (سابعہ / موقوف علیہ)',
        8 => 'سال ہشتم (دورہ حدیث شریف)',
        9 => 'تخصص فی الفقہ سال اول',
        10 => 'تخصص فی الفقہ سال دوم'
    ];
    $asri_names = [
        1 => 'کلاس ہفتم (7th)',
        2 => 'کلاس ہشتم (8th)',
        3 => 'کلاس نہم (9th)',
        4 => 'کلاس دہم (10th)',
        5 => 'گیارہویں (11th)',
        6 => 'بارہویں (12th)'
    ];
    if ($course_type === 'Asri' || $course_type === 'School') {
        return $asri_names[$year_no] ?? "سال $year_no";
    }
    return $darse_nizami_names[$year_no] ?? "سال $year_no";
}
?>

<div class="container-fluid py-3" style="direction: rtl; text-align: right;">
    <!-- Title and Action Header -->
    <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); border-radius: 14px; color: white;">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h3 class="fw-bold mb-1"><i class="fas fa-book-reader me-2"></i> انتظام کورسز و مضامین (درس نظامی و عصری علوم)</h3>
                    <p class="mb-0 text-white-50">ادارے کے تمام تعلیمی کورسز، سالانہ نصاب، مضامین کی فہرست، اور طلبہ کی انرولمنٹ کا ہمہ جہت نظام</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-light fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newCourseModal">
                        <i class="fas fa-plus-circle text-primary me-1"></i> نیا کورس بنائیں
                    </button>
                    <button type="button" class="btn btn-outline-light fw-bold" data-bs-toggle="modal" data-bs-target="#seedTemplateModal">
                        <i class="fas fa-magic me-1"></i> ریڈی میڈ نصاب شامل کریں
                    </button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('کیا آپ واقعی تمام سالوں اور دورہ حدیث کے مکرر (Duplicate) مضامین حذف کرنا چاہتے ہیں؟');">
                        <input type="hidden" name="action" value="deduplicate_subjects">
                        <button type="submit" class="btn btn-outline-light fw-bold">
                            <i class="fas fa-broom me-1"></i> مکرر مضامین صاف کریں
                        </button>
                    </form>
                    <a href="academic_results_entry.php<?php echo $selected_course_id ? '?course_id=' . $selected_course_id : ''; ?>" class="btn btn-warning text-dark fw-bold">
                        <i class="fas fa-graduation-cap me-1"></i> امتحانی نتائج (درس نظامی)
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Right Column: Courses List -->
        <div class="col-lg-4 col-md-5">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-layer-group text-primary me-2"></i> فعال کورسز</h5>
                    <span class="badge bg-primary rounded-pill"><?php echo count($courses_list); ?></span>
                </div>
                <div class="card-body p-2">
                    <?php if (empty($courses_list)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">کوئی کورس موجود نہیں ہے!</h6>
                            <p class="small text-muted mb-3">نیا کورس بنائیں یا ریڈی میڈ وفاق نصاب شامل کریں۔</p>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#seedTemplateModal">
                                <i class="fas fa-magic me-1"></i> ریڈی میڈ نصاب منتخب کریں
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($courses_list as $c): 
                                $is_active_item = ($c['id'] == $selected_course_id);
                            ?>
                                <a href="?course_id=<?php echo $c['id']; ?>" class="list-group-item list-group-item-action p-3 rounded-3 mb-2 border <?php echo $is_active_item ? 'bg-light border-primary shadow-sm' : 'border-light'; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="fw-bold mb-0 <?php echo $is_active_item ? 'text-primary' : 'text-dark'; ?>">
                                            <?php echo htmlspecialchars($c['name']); ?>
                                        </h6>
                                        <span class="badge bg-secondary rounded-pill small"><?php echo htmlspecialchars($c['gender']); ?></span>
                                    </div>
                                    <div class="small text-muted mb-2">
                                        <i class="fas fa-calendar-alt me-1"></i> مدت: <strong><?php echo $c['total_years']; ?> سال</strong> | 
                                        نوعیت: <strong><?php echo $c['course_type'] == 'Darse_Nizami' ? 'درس نظامی' : ($c['course_type'] == 'Asri' ? 'عصری علوم' : 'دیگر'); ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light small">
                                        <span class="text-success fw-bold"><i class="fas fa-book me-1"></i> <?php echo $c['total_subjects']; ?> مضامین</span>
                                        <span class="text-info fw-bold"><i class="fas fa-user-graduate me-1"></i> <?php echo $c['total_enrolled']; ?> طلبہ</span>
                                        <span class="text-danger" onclick="event.preventDefault(); if(confirm('کیا آپ واقعی یہ کورس حذف کرنا چاہتے ہیں؟')) window.location='?del_course=<?php echo $c['id']; ?>';" title="حذف کریں">
                                            <i class="fas fa-trash-alt"></i>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Left Column: Selected Course Detail & Subjects Management -->
        <div class="col-lg-8 col-md-7">
            <?php if (!$selected_course): ?>
                <div class="card border-0 shadow-sm rounded-3 p-5 text-center">
                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">براہ کرم دائیں جانب سے کسی کورس کا انتخاب کریں</h5>
                </div>
            <?php else: ?>
                <!-- Course Overview Banner -->
                <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($selected_course['name']); ?></h4>
                                <span class="badge bg-primary me-1"><?php echo $selected_course['course_type'] == 'Darse_Nizami' ? 'درس نظامی نصاب' : 'عصری تعلیمی نصاب'; ?></span>
                                <span class="badge bg-info text-dark me-1"><?php echo $selected_course['gender']; ?></span>
                                <span class="badge bg-success me-1"><?php echo $selected_course['total_years']; ?> سالہ کورس</span>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCourseModal">
                                    <i class="fas fa-edit me-1"></i> ترمیم کورس
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">
                                    <i class="fas fa-user-plus me-1"></i> طالب علم کا داخلہ
                                </button>
                            </div>
                        </div>
                        <?php if (!empty($selected_course['description'])): ?>
                            <p class="text-muted small mb-0"><i class="fas fa-info-circle me-1"></i> <?php echo nl2br(htmlspecialchars($selected_course['description'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs: Subjects Management & Enrolled Students -->
                <ul class="nav nav-tabs nav-fill mb-3" id="courseTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold py-3" id="subjects-tab" data-bs-toggle="tab" data-bs-target="#subjects-pane" type="button" role="tab">
                            <i class="fas fa-books me-1"></i> مضامین و کتب کا نصاب (سال وار)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold py-3" id="students-tab" data-bs-toggle="tab" data-bs-target="#students-pane" type="button" role="tab">
                            <i class="fas fa-users-class me-1"></i> داخل شدہ طلبہ (<?php echo count($enrollments); ?>)
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="courseTabContent">
                    <!-- Tab 1: Subjects by Year -->
                    <div class="tab-pane fade show active" id="subjects-pane" role="tabpanel">
                        <?php for ($y = 1; $y <= $selected_course['total_years']; $y++): 
                            $year_subs = $subjects_by_year[$y] ?? [];
                            $year_label = get_year_urdu_label($y, $selected_course['course_type']);
                            if (!empty($year_subs[0]['year_name'])) {
                                $year_label = $year_subs[0]['year_name'];
                            }
                        ?>
                            <div class="card border-0 shadow-sm rounded-3 mb-3">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;"><?php echo $y; ?></span>
                                        <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($year_label); ?></h6>
                                        <span class="badge bg-light text-dark border ms-2"><?php echo count($year_subs); ?> مضامین</span>
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary" onclick="openAddSubjectModal(<?php echo $y; ?>, '<?php echo addslashes($year_label); ?>')">
                                        <i class="fas fa-plus me-1"></i> مضمون شامل کریں
                                    </button>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($year_subs)): ?>
                                        <div class="text-center py-3 text-muted small">
                                            اس سال کے لیے ابھی کوئی مضمون درج نہیں کیا گیا۔
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0 text-end">
                                                <thead class="table-light small">
                                                    <tr>
                                                        <th style="width: 50px;">ترتیب</th>
                                                        <th>مضمون کا نام</th>
                                                        <th>کتاب</th>
                                                        <th>کل نمبر</th>
                                                        <th>پاسنگ</th>
                                                        <th>نوعیت</th>
                                                        <th style="width: 80px;" class="text-center">ایکشن</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($year_subs as $sub): ?>
                                                        <tr>
                                                            <td class="text-center text-muted small"><?php echo $sub['subject_order']; ?></td>
                                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                                            <td class="text-muted"><?php echo !empty($sub['book_name']) ? htmlspecialchars($sub['book_name']) : '-'; ?></td>
                                                            <td><span class="badge bg-info text-dark"><?php echo (int)$sub['max_marks']; ?></span></td>
                                                            <td><span class="badge bg-secondary"><?php echo (int)$sub['pass_marks']; ?></span></td>
                                                            <td>
                                                                <?php if ($sub['is_optional']): ?>
                                                                    <span class="badge bg-warning text-dark">اختیاری</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-light text-muted border">لازمی</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <a href="?course_id=<?php echo $selected_course_id; ?>&del_subject=<?php echo $sub['id']; ?>" class="text-danger" onclick="return confirm('کیا آپ یہ مضمون حذف کرنا چاہتے ہیں؟');" title="حذف کریں">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Tab 2: Enrolled Students -->
                    <div class="tab-pane fade" id="students-pane" role="tabpanel">
                        <div class="card border-0 shadow-sm rounded-3">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-user-graduate text-success me-2"></i> داخل شدہ طلبہ کی فہرست</h6>
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">
                                    <i class="fas fa-user-plus me-1"></i> نیا داخلہ
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($enrollments)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-user-slash fa-3x mb-3 text-muted"></i>
                                        <h6>اس کورس میں فی الحال کوئی طالب علم داخل نہیں ہے۔</h6>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 text-end">
                                            <thead class="table-light small">
                                                <tr>
                                                    <th>رول نمبر</th>
                                                    <th>نام طالب علم</th>
                                                    <th>ولدیت</th>
                                                    <th>موجودہ سال / درجہ</th>
                                                    <th>تاریخ داخلہ</th>
                                                    <th>حیثیت</th>
                                                    <th class="text-center">ایکشن</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($enrollments as $en): ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($en['roll_number'] ?: $en['admission_number']); ?></strong></td>
                                                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($en['student_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($en['father_name']); ?></td>
                                                        <td>
                                                            <span class="badge bg-primary">
                                                                <?php echo get_year_urdu_label($en['current_year'], $selected_course['course_type']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars($en['enrollment_date']); ?></td>
                                                        <td>
                                                            <span class="badge bg-success"><?php echo htmlspecialchars($en['status']); ?></span>
                                                        </td>
                                                        <td class="text-center">
                                                            <a href="academic_result_card.php?course_id=<?php echo $selected_course_id; ?>&year_no=<?php echo $en['current_year']; ?>&student_id=<?php echo $en['student_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" title="نتیجہ کارڈ (DMC) پرنٹ کریں">
                                                                <i class="fas fa-print me-1"></i> کارڈ
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal 1: New Course Modal -->
<div class="modal fade" id="newCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-end">
            <form method="POST">
                <input type="hidden" name="action" value="save_course">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle text-primary me-1"></i> نیا کورس شامل کریں</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کورس کا نام <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="مثلاً: درس نظامی بنین، یا بنات کورس، یا میٹرک" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">نوعیت نصاب</label>
                            <select name="course_type" class="form-select">
                                <option value="Darse_Nizami">درس نظامی (روایتی)</option>
                                <option value="Asri">عصری علوم (سکول/کالج)</option>
                                <option value="School">سکول ایجوکیشن</option>
                                <option value="Other">دیگر خصوصی کورس</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">صنف</label>
                            <select name="gender" class="form-select">
                                <option value="بنین">بنین (طلباء)</option>
                                <option value="بنات">بنات (طالبات)</option>
                                <option value="مشترک">مشترک</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کل تعلیمی سال</label>
                        <input type="number" name="total_years" class="form-control" value="8" min="1" max="12" required>
                        <div class="form-text">مثلاً درس نظامی بنین کے لیے 8، بنات کے لیے 6 یا 4، عصری مڈل کے لیے 3</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تفصیل / ہدایات</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="کورس کی مختصر وضاحت..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary fw-bold">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Edit Course Modal -->
<?php if ($selected_course): ?>
<div class="modal fade" id="editCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-end">
            <form method="POST">
                <input type="hidden" name="action" value="save_course">
                <input type="hidden" name="course_id" value="<?php echo $selected_course['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-1"></i> ترمیم کورس</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کورس کا نام <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($selected_course['name']); ?>" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">نوعیت نصاب</label>
                            <select name="course_type" class="form-select">
                                <option value="Darse_Nizami" <?php echo $selected_course['course_type'] == 'Darse_Nizami' ? 'selected' : ''; ?>>درس نظامی</option>
                                <option value="Asri" <?php echo $selected_course['course_type'] == 'Asri' ? 'selected' : ''; ?>>عصری علوم</option>
                                <option value="School" <?php echo $selected_course['course_type'] == 'School' ? 'selected' : ''; ?>>سکول</option>
                                <option value="Other" <?php echo $selected_course['course_type'] == 'Other' ? 'selected' : ''; ?>>دیگر</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">صنف</label>
                            <select name="gender" class="form-select">
                                <option value="بنین" <?php echo $selected_course['gender'] == 'بنین' ? 'selected' : ''; ?>>بنین (طلباء)</option>
                                <option value="بنات" <?php echo $selected_course['gender'] == 'بنات' ? 'selected' : ''; ?>>بنات (طالبات)</option>
                                <option value="مشترک" <?php echo $selected_course['gender'] == 'مشترک' ? 'selected' : ''; ?>>مشترک</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کل تعلیمی سال</label>
                        <input type="number" name="total_years" class="form-control" value="<?php echo $selected_course['total_years']; ?>" min="1" max="12" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">تفصیل</label>
                        <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($selected_course['description']); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary fw-bold">تبدیلیاں محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal 3: Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-end">
            <form method="POST">
                <input type="hidden" name="action" value="save_subject">
                <input type="hidden" name="course_id" value="<?php echo $selected_course_id; ?>">
                <input type="hidden" name="year_no" id="modal_year_no" value="1">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fas fa-book text-primary me-1"></i> نیا مضمون شامل کریں</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">سال / درجہ</label>
                        <input type="text" name="year_name" id="modal_year_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مضمون کا نام <span class="text-danger">*</span></label>
                        <input type="text" name="subject_name" class="form-control" placeholder="مثلاً: نحو، فقہ، ریاضی، انگریزی" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کتاب کا نام (اختیاری)</label>
                        <input type="text" name="book_name" class="form-control" placeholder="مثلاً: ہدایۃ النحو، قدوری">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">کل نمبر</label>
                            <input type="number" name="max_marks" class="form-control" value="100" min="1" step="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">پاسنگ مارکس</label>
                            <input type="number" name="pass_marks" class="form-control" value="40" min="1" step="1">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">ترتیب نمبر</label>
                            <input type="number" name="subject_order" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-6 d-flex align-items-center pt-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_optional" id="isOptionalCheck">
                                <label class="form-check-label fw-bold me-2" for="isOptionalCheck">اختیاری مضمون</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary fw-bold">مضمون محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 4: Seed Preset Template Modal -->
<div class="modal fade" id="seedTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-end">
            <form method="POST">
                <input type="hidden" name="action" value="seed_darse_nizami">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fas fa-magic text-warning me-1"></i> ریڈی میڈ نصاب منتخب کریں</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">آپ وفاق المدارس یا عصری نصاب کے تیار شدہ سانچے کو ایک کلک میں امپورٹ کر سکتے ہیں:</p>
                    <div class="list-group">
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 mb-2 border rounded-3 cursor-pointer">
                            <input class="form-check-input flex-shrink-0" type="radio" name="preset" value="banin_8" checked>
                            <div>
                                <h6 class="fw-bold mb-1">درس نظامی (8 سالہ بنین کورس)</h6>
                                <p class="text-muted small mb-0">اولیٰ تا دورہ حدیث شریف مع 40 سے زائد کتب و مضامین</p>
                            </div>
                        </label>
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 mb-2 border rounded-3 cursor-pointer">
                            <input class="form-check-input flex-shrink-0" type="radio" name="preset" value="banat_6">
                            <div>
                                <h6 class="fw-bold mb-1">درس نظامی (6 سالہ بنات کورس)</h6>
                                <p class="text-muted small mb-0">عامہ تا عالمیہ للبنات</p>
                            </div>
                        </label>
                        <label class="list-group-item d-flex gap-3 align-items-center p-3 mb-2 border rounded-3 cursor-pointer">
                            <input class="form-check-input flex-shrink-0" type="radio" name="preset" value="asri_middle_matric">
                            <div>
                                <h6 class="fw-bold mb-1">عصری علوم (مڈل تا میٹرک 4 سالہ)</h6>
                                <p class="text-muted small mb-0">ہفتم، ہشتم، نہم، دہم کے تمام لازمی و اختیاری مضامین</p>
                            </div>
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-warning fw-bold text-dark">ابھی شامل کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 5: Enroll Student Modal -->
<?php if ($selected_course): ?>
<div class="modal fade" id="enrollStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-end">
            <form method="POST">
                <input type="hidden" name="action" value="enroll_student">
                <input type="hidden" name="course_id" value="<?php echo $selected_course['id']; ?>">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-plus text-success me-1"></i> طالب علم کا داخلہ / انرولمنٹ</h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">طالب علم منتخب کریں <span class="text-danger">*</span></label>
                        <select name="student_id" class="form-select" required>
                            <option value="">-- طالب علم کا انتخاب کریں --</option>
                            <?php foreach ($all_students as $st): ?>
                                <option value="<?php echo $st['id']; ?>">
                                    <?php echo htmlspecialchars($st['name']); ?> ولد <?php echo htmlspecialchars($st['father_name']); ?> (<?php echo htmlspecialchars($st['admission_number'] ?: $st['id']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">داخلہ سال / درجہ</label>
                            <select name="current_year" class="form-select">
                                <?php for ($y = 1; $y <= $selected_course['total_years']; $y++): ?>
                                    <option value="<?php echo $y; ?>">
                                        <?php echo get_year_urdu_label($y, $selected_course['course_type']); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">رول نمبر</label>
                            <input type="text" name="roll_number" class="form-control" placeholder="اختیاری">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">تاریخ داخلہ</label>
                            <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">حیثیت</label>
                            <select name="status" class="form-select">
                                <option value="Active">Active (فعال)</option>
                                <option value="Completed">Completed (مکمل)</option>
                                <option value="Dropped">Dropped (خارج)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success fw-bold">داخلہ محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openAddSubjectModal(yearNo, yearLabel) {
    document.getElementById('modal_year_no').value = yearNo;
    document.getElementById('modal_year_name').value = yearLabel;
    var myModal = new bootstrap.Modal(document.getElementById('addSubjectModal'));
    myModal.show();
}
</script>

<?php include 'footer.php'; ?>
