<?php
require_once 'db.php';
include 'header.php';

$student_roll_select = "s.admission_number AS roll_number";
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'roll_number'");
    if ($colStmt && $colStmt->fetch()) {
        $student_roll_select = "s.roll_number";
    }
} catch (Exception $e) {}


$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$selected_class_name = $_GET['class'] ?? '';
$selected_subject = $_GET['subject'] ?? '';

if (!$exam_id) {
    echo "<div class='alert alert-danger'>امتحان منتخب نہیں۔</div>";
    include 'footer.php';
    exit;
}

// کلاس کا ڈیٹا حاصل کریں
$class_data = null;
if ($selected_class_name) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE name = ?");
    $stmt->execute([$selected_class_name]);
    $class_data = $stmt->fetch();
}

// کالم چیک کریں تاکہ وارننگ نہ آئے
try {
    if ($class_data && !isset($class_data['class_type'])) {
        $pdo->exec("ALTER TABLE classes ADD COLUMN class_type VARCHAR(20) DEFAULT 'Regular'");
        $pdo->exec("ALTER TABLE classes ADD COLUMN p1_name VARCHAR(50) DEFAULT 'Period 1'");
        $pdo->exec("ALTER TABLE classes ADD COLUMN p2_name VARCHAR(50) DEFAULT 'Period 2'");
        $pdo->exec("ALTER TABLE classes ADD COLUMN p3_name VARCHAR(50) DEFAULT 'Period 3'");
        $pdo->exec("ALTER TABLE classes ADD COLUMN p4_name VARCHAR(50) DEFAULT 'Period 4'");
        $pdo->exec("ALTER TABLE classes ADD COLUMN p5_name VARCHAR(50) DEFAULT 'Period 5'");
        $pdo->exec("ALTER TABLE classes ADD COLUMN p6_name VARCHAR(50) DEFAULT 'Period 6'");
        // دوبارہ ڈیٹا حاصل کریں تاکہ کالم مل سکیں
        if ($selected_class_name) {
            $stmt->execute([$selected_class_name]);
            $class_data = $stmt->fetch();
        }
    }
} catch (Exception $e) {}

$is_hifz_class = false;
if ($class_data) {
    $c_type = $class_data['class_type'] ?? 'Regular';
    if ($c_type == 'Hifz') {
        $is_hifz_class = true;
    } else {
        $name = $class_data['name'];
        if (str_contains($name, 'حفظ') || str_contains($name, 'گردان') || str_contains($name, 'حسن صوت') || str_contains($name, 'ناظرہ')) {
            $is_hifz_class = true;
        }
    }
}

$exam = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$exam->execute([$exam_id]);
$exam_data = $exam->fetch();

$classes = get_allowed_classes($pdo);

$current_subject = $is_hifz_class ? 'Quran' : ($selected_subject ?: 'Final');

$dynamic_subjects = [];
$dynamic_subjects_marks_max = [];
$total_dynamic_marks = 0;

if ($class_data) {
    // 1. Query class_periods for the given class id
    $stmt_cp = $pdo->prepare("SELECT books FROM class_periods WHERE class_id = ? ORDER BY period_no ASC");
    $stmt_cp->execute([$class_data['id']]);
    $periods_books = $stmt_cp->fetchAll(PDO::FETCH_COLUMN);
    
    $books_found = array_filter(array_map('trim', $periods_books));
    
    if (!$is_hifz_class && !empty($books_found)) {
        // Use books as subjects
        foreach ($books_found as $b) {
            $dynamic_subjects[] = $b;
            $dynamic_subjects_marks_max[$b] = 100;
            $total_dynamic_marks += 100;
        }
    } elseif (!empty($class_data['subjects_list'])) {
        $parts = array_filter(array_map('trim', explode(',', $class_data['subjects_list'])));
        foreach ($parts as $p) {
            $s_parts = explode(':', $p);
            $n = trim($s_parts[0]);
            $m = isset($s_parts[1]) ? (int)trim($s_parts[1]) : 100;
            if ($n) {
                $dynamic_subjects[] = $n;
                $dynamic_subjects_marks_max[$n] = $m;
                $total_dynamic_marks += $m;
            }
        }
    } else {
        for ($i=1; $i<=6; $i++) {
            $n = $class_data["p{$i}_name"] ?? "P$i";
            if ($n) {
                $dynamic_subjects[] = $n;
                $dynamic_subjects_marks_max[$n] = 100;
                $total_dynamic_marks += 100;
            }
        }
    }
}

$sql = "SELECT er.*, s.name, s.father_name, s.admission_number, $student_roll_select, s.class 
        FROM exam_results er 
        JOIN students s ON er.student_id = s.id 
        WHERE er.exam_id = ? AND er.subject = ?";

if ($selected_class_name) {
    $sql .= " AND s.class = " . $pdo->quote($selected_class_name);
}

$sql .= " ORDER BY er.marks_obtained DESC, s.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$exam_id, $current_subject]);
$results = $stmt->fetchAll();

$total_students_in_result = count($results);
$passed_students  = 0;
$failed_students  = 0;
$absent_students  = 0;
$top3 = [];
$perfect_score_students = [];

// پاسنگ فیصد کا تعین (40% عام ، 33% حفظ کلاس)
$passing_pct = $is_hifz_class ? 33 : 40;

if ($total_students_in_result > 0) {
    foreach ($results as $r) {
        $obt   = (float)$r['marks_obtained'];
        $total = (float)$r['total_marks'];
        $avg   = $total > 0 ? ($obt / $total) * 100 : 0;

        if ($r['status'] === 'Absent' || $r['grade'] === 'Absent') {
            $absent_students++;
        } elseif ($r['status'] == 'Pass' && $obt > 0 && $avg >= $passing_pct) {
            $passed_students++;
        } else {
            $failed_students++;
        }
        // پورے نمبر حاصل کرنے والے طلباء (صرف جو Pass بھی ہیں)
        if ($total > 0 && $obt >= $total && $r['status'] == 'Pass') {
            $perfect_score_students[] = $r;
        }
    }
    
    // Top 3: صرف وہ طلباء جن کی سٹیٹس Pass ہو، نمبر صفر سے زیادہ ہوں
    // اور پاسنگ فیصد سے زیادہ ہوں
    $passed_results = array_values(array_filter($results, function($r) use ($passing_pct) {
        $obt = (float)$r['marks_obtained'];
        $total = (float)$r['total_marks'];
        $avg = $total > 0 ? ($obt / $total) * 100 : 0;
        return $r['status'] == 'Pass'
            && $obt > 0
            && $avg >= $passing_pct
            && !in_array($r['grade'], ['F', 'E', '']);
    }));

    // سورٹ نزولی (HIGH to LOW) — بغیر * -1
    usort($passed_results, function($a, $b) {
        return (float)$b['marks_obtained'] <=> (float)$a['marks_obtained'];
    });

    // گروپ پوزیشن: ایک سے زیادہ طلباء برابر نمبر پر ایک ہی پوزیشن پائیں
    // position_groups = [ ['score'=>101, 'students'=>[...]], ['score'=>100, 'students'=>[...]], ... ]
    $position_groups = [];
    foreach ($passed_results as $r) {
        $score = (float)$r['marks_obtained'];
        $found = false;
        foreach ($position_groups as &$grp) {
            if ($grp['score'] == $score) {
                $grp['students'][] = $r;
                $found = true;
                break;
            }
        }
        unset($grp);
        
        if (!$found) {
            // اگر 3 پوزیشنز پوری ہو چکی ہیں اور یہ نیا نمبر (چوتھی پوزیشن) ہے، تو لوپ بریک کر دیں
            if (count($position_groups) >= 3) break;
            
            $position_groups[] = ['score' => $score, 'students' => [$r]];
        }
    }
    // پرانے سادہ متغیر top3 بھی دستیاب رہیں (backward compat)
    $top3 = array_slice($passed_results, 0, 3);
}
$pass_percentage = $total_students_in_result > 0 ? round(($passed_students / $total_students_in_result) * 100, 1) : 0;

// گریڈ آرڈر میں Absent شامل ہے — غیر حاضر طلبہ الگ کالم میں
$grade_order = ['A+', 'A', 'B', 'C', 'D', 'E', 'F', 'Absent'];
$graded = [];
foreach ($grade_order as $g) $graded[$g] = [];
foreach ($results as $r) {
    $g = $r['grade'] ?: 'F';
    if ($r['status'] === 'Absent' || $g === 'Absent') {
        $graded['Absent'][] = $r;
    } elseif (!in_array($g, $grade_order)) {
        $g = 'F';
        $graded[$g][] = $r;
    } else {
        $graded[$g][] = $r;
    }
}

// ہر گریڈ کے اندر طلباء کو نمبروں کے حساب سے نزولی (Descending) ترتیب دیں
foreach (['A+', 'A', 'B', 'C', 'D', 'E', 'F'] as $g) {
    usort($graded[$g], function($a, $b) {
        $mA = (float)$a['marks_obtained'];
        $mB = (float)$b['marks_obtained'];
        if ($mA == $mB) return strcmp($a['name'], $b['name']);
        return ($mA > $mB) ? -1 : 1;
    });
}
?>

<style>
/* ===== PRINT STYLES — LANDSCAPE REGISTER ===== */
@media print {
    @page {
        size: A4 landscape;
        margin: 8mm 6mm;
    }
    /* navbar, sidebar, filter form — چھپائیں */
    header, nav, footer, .sidebar, .navbar,
    .no-print, .neobox.p-3.bg-light,
    #sidebar, .app-sidebar, .top-bar,
    [class*="sidebar"], [id*="sidebar"] {
        display: none !important;
    }
    /* پرنٹ ٹائٹل بار دکھائیں */
    .print-title-bar { display: block !important; }
    html, body { background: #fff !important; font-size: 9pt; margin: 0 !important; padding: 0 !important; }
    .container-fluid { padding: 0 1mm !important; margin: 0 !important; }

    /* === رپورٹ سمری اور پوزیشن گرڈ — پرنٹ میں compact === */
    .print-header-row {
        display: flex !important;
        flex-direction: row !important;
        margin-bottom: 5px !important;
        page-break-inside: avoid;
        gap: 6px;
    }
    .print-header-row .col-md-4 { width: 26% !important; flex: 0 0 26%; padding: 0; }
    .print-header-row .col-md-8 { width: 72% !important; flex: 0 0 72%; padding: 0; }
    .print-header-row .card { border: 1px solid #ccc !important; box-shadow: none !important; margin: 0 !important; }
    .print-header-row .card-header { padding: 3px 7px !important; font-size: 8pt !important; }
    .print-header-row .card-body  { padding: 4px 5px !important; }
    .print-header-row h4  { font-size: 12pt !important; margin-bottom: 0; }
    .print-header-row small { font-size: 7pt !important; }
    .print-header-row .p-2 { padding: 2px 3px !important; }
    .print-header-row .fa-2x { font-size: 1.1em !important; }
    .print-header-row .badge { font-size: 7.5pt !important; padding: 1px 4px !important; }
    .print-header-row .row.g-3 { display: flex !important; flex-direction: row !important; gap: 3px !important; margin: 0; }
    .print-header-row .row.g-3 .col-md-4 { flex: 1 !important; width: auto !important; padding: 0; }
    .print-header-row .rounded-3 { border-radius: 4px !important; padding: 4px !important; }
    .print-header-row * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    /* === پورے نمبر بینر === */
    .perfect-banner { margin-bottom: 4px !important; page-break-inside: avoid; }
    .perfect-banner .card-header { padding: 2px 7px !important; font-size: 8pt !important; }
    .perfect-banner .card-body   { padding: 3px 5px !important; }
    .perfect-banner .d-flex.flex-wrap { gap: 3px !important; }
    .perfect-banner .rounded-pill { font-size: 7pt !important; padding: 2px 5px !important; border-width: 1px !important; }
    .perfect-banner * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    /* === عنوان === */
    .text-center.mb-4 { margin-bottom: 4px !important; }
    .text-center.mb-4 h3 { font-size: 11pt !important; margin-bottom: 1px; }
    .text-center.mb-4 p  { font-size: 8pt !important;  margin: 0; }
    /* === ٹیبل === */
    table { font-size: 8pt !important; border-collapse: collapse !important; width: 100% !important; }
    th, td { padding: 2px 3px !important; border: 0.5pt solid #444 !important; vertical-align: middle !important; }
    thead th { background: var(--primary) !important; color: #fff !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .col-name-print   { width: 108px !important; }
    .col-father-print { width:  95px !important; }
    .col-num-print    { width:  36px !important; }
    .col-sm-print     { width:  26px !important; }
    .grade-section-header {
        background: #e8f5e9 !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    tr[data-absent="1"] td { color: #888 !important; }
    .grade-block { margin-bottom: 10px; }
    .table tr { page-break-inside: avoid; }
    .table th, .table td { white-space: nowrap; }
}
</style>

<div class="container-fluid">

    <!-- Print Title Bar — only visible during print -->
    <div class="print-title-bar" style="display:none;">
        <div style="text-align:center; border-bottom:2px solid var(--primary); margin-bottom:6px; padding-bottom:5px;">
            <h4 style="color:var(--primary); margin:0; font-size:13pt;">رزلٹ رجسٹر</h4>
            <p style="margin:1px 0; font-size:8.5pt;">
                امتحان: <strong><?php echo htmlspecialchars($exam_data['exam_name'] ?? ''); ?></strong>
                &nbsp;|&nbsp; تاریخ: <?php echo $exam_data['exam_date'] ?? ''; ?>
                &nbsp;|&nbsp; کلاس: <?php echo htmlspecialchars($selected_class_name ?: 'تمام'); ?>
            </p>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold text-primary mb-0 no-print">رزلٹ رجسٹر (Result Register)</h2>
        <div class="no-print">
            <button onclick="printLandscape()" class="btn btn-dark btn-sm"><i class="fas fa-print me-1"></i> پرنٹ کریں (Landscape)</button>
            <a href="exams.php" class="btn btn-outline-secondary btn-sm">واپس</a>
        </div>
    </div>

    <div class="neobox p-3 bg-light mb-4 no-print">
        <form class="row g-2 align-items-end">
            <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
            <div class="col-md-4">
                <label class="form-label small fw-bold">کلاس:</label>
                <select name="class" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">تمام کلاسز</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['name']; ?>" <?php echo $selected_class_name == $c['name'] ? 'selected' : ''; ?>><?php echo $c['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$is_hifz_class): ?>
            <div class="col-md-3">
                <label class="form-label small fw-bold">مضمون/سیشن:</label>
                <input type="text" name="subject" class="form-control form-control-sm" value="<?php echo $selected_subject; ?>" placeholder="مثال: Final">
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">فلٹر</button>
            </div>
        </form>
    </div>

    <div class="text-center mb-4">
        <h3 class="fw-bold"><?php echo $exam_data['exam_name']; ?></h3>
        <p class="text-muted">تاریخ: <?php echo $exam_data['exam_date']; ?> | کلاس: <?php echo $selected_class_name ?: 'تمام'; ?></p>
    </div>

    <?php if ($total_students_in_result > 0): ?>
    <div class="row mb-4 print-header-row">
        <!-- Summary Card -->
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-primary text-white fw-bold"><i class="fas fa-chart-pie me-2"></i>رپورٹ سمری</div>
                <div class="card-body">
                    <div class="row text-center g-3">
                        <div class="col-6">
                            <div class="p-2 bg-light rounded border">
                                <h4 class="fw-bold text-dark mb-0"><?php echo $total_students_in_result; ?></h4>
                                <small class="text-muted">کل طلباء</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 bg-success bg-opacity-10 rounded border border-success">
                                <h4 class="fw-bold text-success mb-0"><?php echo $passed_students; ?></h4>
                                <small class="text-success">کامیاب</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-danger bg-opacity-10 rounded border border-danger">
                                <h4 class="fw-bold text-danger mb-0"><?php echo $failed_students; ?></h4>
                                <small class="text-danger">ناکام</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded border alert-warning">
                                <h4 class="fw-bold mb-0"><?php echo $absent_students; ?></h4>
                                <small>غیر حاضر</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-info bg-opacity-10 rounded border border-info">
                                <h4 class="fw-bold text-info mb-0"><?php echo $pass_percentage; ?>%</h4>
                                <small class="text-info">شرح کامیابی</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top 3 Positions Card — گریڈ لے آئٹ -->
        <div class="col-md-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header text-white fw-bold" style="background-color: var(--primary);">
                    <i class="fas fa-trophy me-2" style="color:#D4AC0D;"></i>نمایاں پوزیشنز
                    <small class="fw-normal opacity-75">&mdash; صرف کامیاب (<?php echo $passing_pct; ?>%+) طلباء</small>
                </div>
                <div class="card-body p-3">
                <?php
                $pos_config = [
                    ['label'=>'اول', 'en'=>'1st', 'color'=>'#FFD700', 'bg'=>'#fffbea', 'tc'=>'#5c4300', 'icon'=>'fas fa-trophy'],
                    ['label'=>'دوم', 'en'=>'2nd', 'color'=>'#A8A8A8', 'bg'=>'#f5f5f5', 'tc'=>'#333',    'icon'=>'fas fa-medal'],
                    ['label'=>'سوم', 'en'=>'3rd', 'color'=>'#CD7F32', 'bg'=>'#fff8f4', 'tc'=>'#4a2800','icon'=>'fas fa-award'],
                ];
                if (empty($position_groups)): ?>
                    <p class="text-muted text-center py-4"><i class="fas fa-info-circle me-1"></i>کوئی کامیاب طالب علم نہیں ملا</p>
                <?php else: ?>
                <div class="row g-3">
                <?php for ($i = 0; $i < min(3, count($position_groups)); $i++):
                    $grp = $position_groups[$i];
                    $pc  = $pos_config[$i];
                    $cnt = count($grp['students']);
                ?>
                    <div class="col-md-4">
                        <div class="h-100 rounded-3 p-3 text-center" style="background:<?php echo $pc['bg']; ?>; border: 2px solid <?php echo $pc['color']; ?>; position:relative;">
                            <!-- بیج -->
                            <div class="mb-2">
                                <i class="<?php echo $pc['icon']; ?> fa-2x" style="color:<?php echo $pc['color']; ?>"></i>
                            </div>
                            <span class="badge px-3 py-2 mb-2 d-inline-block fw-bold" style="background:<?php echo $pc['color']; ?>; color:<?php echo $pc['tc']; ?>; font-size:13px;">
                                <?php echo $pc['label']; ?> پوزیشن
                            </span>
                            <div class="small text-muted mb-2"><?php echo $grp['score']; ?> نمبر</div>
                            <!-- طلباء -->
                            <div class="d-flex flex-wrap justify-content-center gap-1 mt-1">
                            <?php foreach ($grp['students'] as $t): ?>
                                <span class="badge rounded-pill px-2 py-1" style="background:#fff; border:1.5px solid <?php echo $pc['color']; ?>; color:#222; font-size:12px;">
                                    <?php echo htmlspecialchars($t['name']); ?>
                                    <small class="ms-1 opacity-75"><?php echo $t['grade']; ?></small>
                                </span>
                            <?php endforeach; ?>
                            </div>
                            <?php if ($cnt > 1): ?>
                            <div class="mt-2"><span class="badge bg-secondary"><?php echo $cnt; ?> طلباء مشترک</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
                </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($perfect_score_students)): ?>
    <div class="card mb-3 border-0 shadow-sm perfect-banner" style="border-right: 5px solid #D4AC0D !important;">
        <div class="card-header fw-bold text-white" style="background-color: var(--primary);">
            <i class="fas fa-star me-2" style="color:#D4AC0D;"></i> ستارہ کامیاب &#x2014; پورے نمبر حاصل کرنے والے طلباء
        </div>
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($perfect_score_students as $ps): ?>
                <div class="d-flex align-items-center px-3 py-2 rounded-pill shadow-sm" style="background:#fff; border:2px solid #FFD700;">
                    <i class="fas fa-star text-warning me-2"></i>
                    <div>
                        <div class="fw-bold" style="font-size:13px;"><?php echo $ps['name']; ?></div>
                        <small class="text-muted"><?php echo $ps['marks_obtained']; ?>/<?php echo $ps['total_marks']; ?> &bull; گریڈ <?php echo $ps['grade']; ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <?php
            $grade_colors = ['A+' => 'success', 'A' => 'primary', 'B' => 'info', 'C' => 'warning', 'D' => 'secondary', 'E' => 'danger', 'F' => 'dark', 'Absent' => 'secondary'];
            $grade_labels = ['A+' => 'گریڈ A+', 'A' => 'گریڈ A', 'B' => 'گریڈ B', 'C' => 'گریڈ C', 'D' => 'گریڈ D', 'E' => 'گریڈ E', 'F' => 'گریڈ F (ناکام)', 'Absent' => 'غیر حاضر'];
            $grade_icons  = ['A+' => 'fa-star', 'A' => 'fa-medal', 'B' => 'fa-award', 'C' => 'fa-certificate', 'D' => 'fa-circle', 'E' => 'fa-exclamation-circle', 'F' => 'fa-times-circle', 'Absent' => 'fa-user-slash'];
            $passing_pct = $is_hifz_class ? 33 : 40;
            foreach ($grade_order as $grade):
                if (empty($graded[$grade])) continue;
                $g_color = $grade_colors[$grade] ?? 'dark';
                $g_label = $grade_labels[$grade] ?? ('گریڈ ' . $grade);
                $g_icon  = $grade_icons[$grade]  ?? 'fa-circle';
                // غیر حاضر سیکشن کا خاص رنگ
                $section_style = ($grade === 'Absent') ? 'style="background:#f5f5f5; border-left:4px solid #aaa;"' : '';
            ?>
            <div class="mb-0 grade-block">
                <div class="px-3 py-2 bg-<?php echo $g_color; ?> bg-opacity-10 border-start border-3 border-<?php echo $g_color; ?> d-flex justify-content-between align-items-center grade-section-header" <?php echo ($grade==='Absent') ? 'style="background:#f0f0f0!important;"' : ''; ?>>
                    <h6 class="mb-0 fw-bold text-<?php echo $g_color; ?>">
                        <i class="fas <?php echo $g_icon; ?> me-2"></i><?php echo $g_label; ?>
                    </h6>
                    <span class="badge bg-<?php echo $g_color; ?>"><?php echo count($graded[$grade]); ?> طلباء</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle text-center m-0">
                        <thead style="background:var(--primary); color:#fff;">
                            <tr>
                                <th width="35" class="col-sm-print">شمار</th>
                                <th width="60" class="col-sm-print">رول نمبر</th>
                                <th class="text-start col-name-print">نام طالب علم</th>
                                <th class="text-start col-father-print">ولدیت</th>
                                <?php if ($is_hifz_class): ?>
                                <th width="140" class="col-num-print">مقدارِ خواندگی</th>
                                <th width="48" class="col-sm-print">پختگی<br><small>(40)</small></th>
                                <th width="48" class="col-sm-print">تلفظ<br><small>(20)</small></th>
                                <th width="48" class="col-sm-print">لہجہ<br><small>(20)</small></th>
                                <th width="48" class="col-sm-print">مسائل<br><small>(20)</small></th>
                                <?php else: ?>
                                <?php foreach($dynamic_subjects as $subj): 
                                    $max_m = $dynamic_subjects_marks_max[$subj] ?? 100;
                                ?>
                                <th width="55" class="col-num-print"><?php echo htmlspecialchars($subj); ?><br><small>(<?php echo $max_m; ?>)</small></th>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                <th width="55" class="col-num-print">حاصل کردہ</th>
                                <th width="50" class="col-num-print">اوسط %</th>
                                <th width="40" class="col-sm-print">گریڈ</th>
                                <th width="60">کیفیت</th>
                                <th class="no-print">ایکشن</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sr = 1;
                            foreach ($graded[$grade] as $r):
                                $avg = $r['total_marks'] > 0 ? ($r['marks_obtained'] / $r['total_marks']) * 100 : 0;
                                $is_absent  = ($r['status'] === 'Absent' || $r['grade'] === 'Absent');
                                $status_class = $is_absent ? 'text-muted' : ($avg < $passing_pct ? 'text-danger' : 'text-success');
                                $is_perfect = (!$is_absent && (float)$r['total_marks'] > 0 && (float)$r['marks_obtained'] >= (float)$r['total_marks']);
                                $row_style  = $is_absent
                                    ? 'background:#f9f9f9; opacity:0.8;'
                                    : ($is_perfect ? 'background: linear-gradient(90deg,#fffbe6,#fff8c5);' : '');
                            ?>
                            <tr class="<?php echo $is_perfect ? 'table-warning fw-bold' : ($is_absent ? 'text-muted' : ''); ?>"
                                style="<?php echo $row_style; ?>"
                                data-absent="<?php echo $is_absent ? 1 : 0; ?>">
                                <td class="text-center"><?php echo $sr++; ?></td>
                                <td><?php echo htmlspecialchars($r['roll_number'] ?: $r['admission_number']); ?></td>
                                <!-- نام — الگ کالم -->
                                <td class="text-start ps-2 col-name-print">
                                    <?php if ($is_absent): ?><span class="badge me-1 d-print-none" style="background:#e67e22;color:#fff;font-size:9px;">غ.ح</span><?php endif; ?>
                                    <?php if ($is_perfect): ?><span class="badge me-1" style="background:#FFD700;color:#3d2c00;font-size:9px;"><i class="fas fa-star"></i></span><?php endif; ?>
                                    <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                                </td>
                                <!-- ولدیت — الگ کالم -->
                                <td class="text-start col-father-print" style="color:#555;font-size:90%;"><?php echo htmlspecialchars($r['father_name']); ?></td>
                                <?php if ($is_hifz_class): ?>
                                <td class="text-muted small"><?php echo $r['miqdar_khwandgi'] ?: '-'; ?></td>
                                <td class="text-muted"><?php echo (int)($r['pukhtagi'] ?? 0); ?></td>
                                <td class="text-muted"><?php echo (int)($r['tajweed'] ?? 0); ?></td>
                                <td class="text-muted"><?php echo (int)($r['lahja'] ?? 0); ?></td>
                                <td class="text-muted"><?php echo (int)($r['masail'] ?? 0); ?></td>
                            <?php else: ?>
                            <?php 
                                $subj_marks_json = json_decode($r['subjects_marks'] ?? '{}', true) ?: [];
                                foreach($dynamic_subjects as $subj): 
                                    $val = isset($subj_marks_json[$subj]) ? (float)$subj_marks_json[$subj] : 0;
                            ?>
                            <td><?php echo $val; ?></td>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <td class="fw-bold"><?php echo (float)$r['marks_obtained']; ?></td>
                            <td class="fw-bold <?php echo ($r['status']==='Absent') ? 'text-muted' : ($avg < $passing_pct ? 'text-danger' : 'text-primary'); ?>">
                                <?php echo ($r['status']==='Absent') ? '-' : number_format($avg, 1).'%'; ?>
                            </td>
                            <td class="fw-bold text-dark"><?php echo $r['grade'] ?: '-'; ?></td>
                            <td class="fw-bold <?php echo $status_class; ?>">
                                <?php
                                    if ($r['status'] === 'Absent')     echo '<span class="badge" style="background:#e67e22">غیر حاضر</span>';
                                    elseif ($r['status'] == 'Pass')    echo 'پاس';
                                    elseif ($r['status'] == 'Fail')    echo 'فیل';
                                    elseif ($r['status'] == 'Grace Pass') echo 'رعایتی';
                                    else echo $r['status'];
                                ?>
                            </td>
                            <td class="no-print">
                                <?php if ($r['status'] !== 'Absent'): ?>
                                <a href="print_result_card.php?exam_id=<?php echo $exam_id; ?>&student_id=<?php echo $r['student_id']; ?>" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 10px;" target="_blank">کارڈ</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($results)): ?>
                <div class="text-center py-5 text-muted">کوئی ریکارڈ نہیں ملا۔ پہلے رزلٹ انٹری کریں۔</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Duplicate print-title-bar removed; moved to top -->

<script>
function printLandscape() {
    document.querySelectorAll('.print-title-bar').forEach(el => el.style.display = 'block');
    window.print();
    document.querySelectorAll('.print-title-bar').forEach(el => el.style.display = 'none');
}
</script>

<?php include 'footer.php'; ?>
