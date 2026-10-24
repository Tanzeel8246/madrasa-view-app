<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'middleware/tenant.php';

$is_admin = (($_SESSION['role'] ?? '') === 'admin');
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$tenant_id = (int)get_current_tenant_id();

// Helper function to calculate Islamic Madrasa grade
function get_madrasa_grade_info($obtained, $total, $is_absent = 0) {
    if ($is_absent) return ['grade' => 'غیر حاضر', 'class' => 'text-secondary'];
    if ($total <= 0) return ['grade' => '—', 'class' => 'text-muted'];
    $pct = ($obtained / $total) * 100;
    if ($pct >= 80) return ['grade' => 'ممتاز', 'class' => 'text-success fw-bold'];
    if ($pct >= 70) return ['grade' => 'جید جدا', 'class' => 'text-primary fw-bold'];
    if ($pct >= 60) return ['grade' => 'جید', 'class' => 'text-info fw-bold'];
    if ($pct >= 40) return ['grade' => 'مقبول', 'class' => 'text-warning fw-bold'];
    return ['grade' => 'راسب', 'class' => 'text-danger fw-bold'];
}

$exam_id = (int)($_GET['exam_id'] ?? 0);
$course_id = (int)($_GET['course_id'] ?? 0);
$year_no = (int)($_GET['year_no'] ?? 1);
$student_id = (int)($_GET['student_id'] ?? 0);

// Fetch Tenant Settings (Name, Phone, Address, Logo)
$stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? LIMIT 1");
$stmt->execute([$tenant_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'madrasa_name' => (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : 'ادارہ'),
    'phone' => '',
    'address' => ''
];

// Fetch Exam Info
$exam_stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND tenant_id = ?");
$exam_stmt->execute([$exam_id, $tenant_id]);
$exam = $exam_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Course Info
$course_stmt = $pdo->prepare("SELECT * FROM course_structure WHERE id = ? AND tenant_id = ?");
$course_stmt->execute([$course_id, $tenant_id]);
$course = $course_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Subjects for this Course and Year
$sub_stmt = $pdo->prepare("SELECT * FROM course_subjects WHERE course_id = ? AND year_no = ? AND tenant_id = ? ORDER BY subject_order ASC, id ASC");
$sub_stmt->execute([$course_id, $year_no, $tenant_id]);
$subjects = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Students
$std_where = "e.course_id = ? AND e.current_year = ? AND e.tenant_id = ? AND (s.is_deleted = 0 OR s.is_deleted IS NULL)";
$std_params = [$course_id, $year_no, $tenant_id];
if ($student_id > 0) {
    $std_where .= " AND s.id = ?";
    $std_params[] = $student_id;
}
$std_stmt = $pdo->prepare("
    SELECT s.id, s.name, s.father_name, s.roll_number, s.photo, e.academic_session, e.current_year
    FROM student_course_enrollment e
    JOIN students s ON s.id = e.student_id
    WHERE $std_where
    ORDER BY s.roll_number ASC, s.name ASC
");
$std_stmt->execute($std_params);
$students = $std_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Marks for this Exam & Year
$marks_stmt = $pdo->prepare("
    SELECT student_id, subject_id, marks_obtained, max_marks, is_absent, grade, remarks
    FROM exam_subject_results
    WHERE exam_id = ? AND course_id = ? AND year_no = ? AND tenant_id = ?
");
$marks_stmt->execute([$exam_id, $course_id, $year_no, $tenant_id]);
$results_map = [];
while ($row = $marks_stmt->fetch(PDO::FETCH_ASSOC)) {
    $results_map[(int)$row['student_id']][(int)$row['subject_id']] = $row;
}

$darse_nizami_year_names = [
    1 => 'سال اول (درجہ اولیٰ)',
    2 => 'سال دوم (درجہ ثانیہ)',
    3 => 'سال سوم (درجہ ثالثہ)',
    4 => 'سال چہارم (درجہ رابعہ)',
    5 => 'سال پنجم (درجہ خامسہ)',
    6 => 'سال ششم (درجہ سادسہ)',
    7 => 'سال ہفتم (درجہ عالیہ)',
    8 => 'سال ہشتم (درجہ عالمیہ / دورۂ حدیث)'
];
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتیجہ نامہ — <?php echo htmlspecialchars($exam['exam_name'] ?? 'امتحان'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Noto Nastaliq Urdu', 'Segoe UI', Tahoma, sans-serif;
            background-color: #f4f6f9;
            color: #111;
            margin: 0;
            padding: 0;
        }

        .result-card-page {
            width: 210mm;
            min-height: 297mm;
            padding: 15mm 20mm;
            margin: 15px auto;
            background: #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            box-sizing: border-box;
            position: relative;
            page-break-after: always;
        }

        .inner-border {
            border: 3px double #1b4332;
            padding: 15px;
            height: 100%;
            min-height: 260mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .letterhead-spacing {
            transition: height 0.2s ease;
        }

        .header-section {
            text-align: center;
            border-bottom: 2px solid #1b4332;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .header-title {
            font-size: 24px;
            font-weight: 700;
            color: #1b4332;
            margin: 0;
        }

        .sub-title {
            font-size: 16px;
            color: #555;
            margin-top: 3px;
        }

        .card-badge {
            background-color: #1b4332;
            color: #fff;
            padding: 6px 20px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: bold;
            display: inline-block;
            margin-top: 8px;
        }

        .student-meta-table td {
            padding: 6px 10px;
            font-size: 14px;
            border: 1px solid #ddd;
        }

        .marks-table th, .marks-table td {
            border: 1px solid #333;
            padding: 6px 8px;
            text-align: center;
            font-size: 13px;
        }

        .marks-table th {
            background-color: #e9ecef;
            color: #1b4332;
            font-weight: bold;
        }

        .summary-box {
            background: #f8f9fa;
            border: 2px solid #1b4332;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 15px;
        }

        .signatures-row {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            text-align: center;
        }

        .signature-item {
            width: 30%;
            border-top: 1px dashed #333;
            padding-top: 5px;
            font-size: 13px;
            font-weight: bold;
        }

        /* Adjustment Toolbar */
        .controls-toolbar {
            position: fixed;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(27, 67, 50, 0.95);
            color: #fff;
            padding: 10px 20px;
            border-radius: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 15px;
            font-family: sans-serif;
        }

        @media print {
            body {
                background: none;
            }
            .no-print, .controls-toolbar {
                display: none !important;
            }
            .result-card-page {
                box-shadow: none;
                margin: 0;
                width: 100%;
                min-height: 100%;
                padding: 10mm 15mm;
                page-break-after: always;
            }
            .inner-border {
                border-color: #000;
            }
        }
    </style>
</head>
<body>

<!-- Floating Print & Margin Adjustment Controls -->
<div class="controls-toolbar no-print">
    <button onclick="window.print()" class="btn btn-warning text-dark fw-bold rounded-pill px-3">
        <i class="fas fa-print me-1"></i> پرنٹ کریں
    </button>
    
    <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="hideHeaderToggle" onchange="toggleHeader(this)">
        <label class="form-check-label fw-bold text-white" for="hideHeaderToggle">لیٹر پیڈ پر پرنٹ (ہیڈر چھپائیں)</label>
    </div>

    <div class="d-flex align-items-center gap-2">
        <span class="small">ٹاپ مارجن:</span>
        <input type="range" id="topMarginSlider" min="0" max="90" value="0" oninput="adjustTopMargin(this.value)" style="width: 100px;">
        <span id="marginVal" class="small fw-bold">0mm</span>
    </div>

    <a href="academic_results_entry.php?exam_id=<?php echo $exam_id; ?>&course_id=<?php echo $course_id; ?>&year_no=<?php echo $year_no; ?>" class="btn btn-sm btn-outline-light rounded-pill">
        <i class="fas fa-arrow-right me-1"></i> واپسی
    </a>
</div>

<?php if (empty($students)): ?>
    <div class="container py-5 text-center mt-5">
        <div class="alert alert-warning shadow-sm p-5 rounded-4">
            <h4><i class="fas fa-exclamation-triangle me-2"></i> کوئی رزلٹ کارڈ دستیاب نہیں</h4>
            <p>براہ کرم اس کلاس کے امتحانی نتائج پہلے درج کریں۔</p>
            <a href="academic_results_entry.php?exam_id=<?php echo $exam_id; ?>&course_id=<?php echo $course_id; ?>&year_no=<?php echo $year_no; ?>" class="btn btn-success rounded-pill px-4">نتائج درج کریں</a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($students as $st): ?>
        <?php 
        $sid = (int)$st['id'];
        $st_total_obtained = 0;
        $st_total_max = 0;
        $st_all_absent = true;
        $st_has_fail = false;
        ?>
        <div class="result-card-page">
            <div class="letterhead-spacing" style="height: 0mm;"></div>
            
            <div class="inner-border">
                <div>
                    <!-- Header Section (Can be hidden for pre-printed letterhead) -->
                    <div class="header-section">
                        <div style="font-size: 13px; color: #1b4332;">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>
                        <h1 class="header-title"><?php echo htmlspecialchars($settings['madrasa_name'] ?? ($_SESSION['tenant_name'] ?? 'ادارہ')); ?></h1>
                        <div class="sub-title">شعبہ درس نظامی و عصری علوم • سالانہ امتحانی نتیجہ نامہ</div>
                        <div class="card-badge"><?php echo htmlspecialchars($exam['exam_name'] ?? 'امتحانی نتیجہ کارڈ'); ?></div>
                    </div>

                    <!-- Student Metadata Table -->
                    <table class="table student-meta-table mb-3">
                        <tr>
                            <td class="fw-bold bg-light" style="width: 15%;">طالب علم کا نام:</td>
                            <td class="fw-bold fs-6" style="width: 35%;"><?php echo htmlspecialchars($st['name']); ?></td>
                            <td class="fw-bold bg-light" style="width: 15%;">رول نمبر:</td>
                            <td class="fw-bold text-success" style="width: 35%;"><?php echo htmlspecialchars($st['roll_number'] ?? '—'); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold bg-light">والد کا نام:</td>
                            <td><?php echo htmlspecialchars($st['father_name'] ?? '—'); ?></td>
                            <td class="fw-bold bg-light">تعلیمی کورس:</td>
                            <td class="fw-bold"><?php echo htmlspecialchars($course['name'] ?? 'درس نظامی'); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold bg-light">سال / درجہ:</td>
                            <td class="text-primary fw-bold"><?php echo $darse_nizami_year_names[$st['current_year']] ?? "سال {$st['current_year']}"; ?></td>
                            <td class="fw-bold bg-light">تعلیمی سیشن:</td>
                            <td><?php echo htmlspecialchars($st['academic_session'] ?? '1446-1447ھ'); ?></td>
                        </tr>
                    </table>

                    <!-- Subjects Marks Table -->
                    <table class="table marks-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 45px;">نمبر</th>
                                <th class="text-end">مضمون</th>
                                <th class="text-end">مقررہ کتاب</th>
                                <th style="width: 80px;">کل نمبر</th>
                                <th style="width: 80px;">پاسنگ نمبر</th>
                                <th style="width: 90px;">حاصل کردہ نمبر</th>
                                <th style="width: 80px;">کیفیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $idx = 1;
                            foreach ($subjects as $sub): 
                                $subid = (int)$sub['id'];
                                $res = $results_map[$sid][$subid] ?? null;
                                $max_m = (float)$sub['max_marks'];
                                $pass_m = (float)$sub['pass_marks'];
                                $is_abs = ($res !== null && (int)$res['is_absent'] === 1);
                                $obt = ($res !== null && !$is_abs) ? (float)$res['marks_obtained'] : 0.0;
                                
                                $st_total_max += $max_m;
                                if ($res !== null) {
                                    if (!$is_abs) {
                                        $st_all_absent = false;
                                        $st_total_obtained += $obt;
                                        if ($obt < $pass_m) {
                                            $st_has_fail = true;
                                        }
                                    }
                                }

                                $grade_info = get_madrasa_grade_info($obt, $max_m, $is_abs);
                            ?>
                                <tr>
                                    <td><?php echo $idx++; ?></td>
                                    <td class="text-end fw-bold"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                    <td class="text-end text-muted"><?php echo !empty($sub['book_name']) ? htmlspecialchars($sub['book_name']) : '—'; ?></td>
                                    <td><?php echo $max_m; ?></td>
                                    <td><?php echo $pass_m; ?></td>
                                    <td class="fw-bold <?php echo ($obt < $pass_m && !$is_abs) ? 'text-danger' : ''; ?>">
                                        <?php echo $is_abs ? 'غیر حاضر' : ($res !== null ? $obt : '—'); ?>
                                    </td>
                                    <td class="<?php echo $grade_info['class']; ?>">
                                        <?php echo $grade_info['grade']; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Summary & Final Standing Box -->
                    <?php 
                    $overall_pct = ($st_total_max > 0) ? round(($st_total_obtained / $st_total_max) * 100, 1) : 0;
                    $final_grade = get_madrasa_grade_info($st_total_obtained, $st_total_max, $st_all_absent ? 1 : 0);
                    $next_year = ($st['current_year'] < 8) ? ($darse_nizami_year_names[$st['current_year'] + 1] ?? "سال " . ($st['current_year'] + 1)) : 'تکمیل / فراغت';
                    ?>
                    <div class="summary-box">
                        <div class="row text-center align-items-center g-2">
                            <div class="col-3 border-start">
                                <div class="small text-muted">کل نمبرات</div>
                                <div class="fw-bold fs-6"><?php echo $st_total_max; ?></div>
                            </div>
                            <div class="col-3 border-start">
                                <div class="small text-muted">حاصل کردہ نمبرات</div>
                                <div class="fw-bold fs-6 text-success"><?php echo $st_total_obtained; ?></div>
                            </div>
                            <div class="col-3 border-start">
                                <div class="small text-muted">فیصد</div>
                                <div class="fw-bold fs-6"><?php echo $overall_pct; ?>%</div>
                            </div>
                            <div class="col-3">
                                <div class="small text-muted">مجموعی درجہ / پوزیشن</div>
                                <div class="fs-6 <?php echo $final_grade['class']; ?>"><?php echo $final_grade['grade']; ?></div>
                            </div>
                        </div>
                        <div class="border-top mt-2 pt-1 text-center small">
                            <strong>سالانہ ترقی کی کیفیت:</strong> 
                            <?php if ($final_grade['grade'] !== 'راسب' && !$st_has_fail && !$st_all_absent): ?>
                                <span class="text-success fw-bold">کامیاب — اگلے تعلیمی سال (<?php echo $next_year; ?>) میں ترقی دی گئی۔</span>
                            <?php else: ?>
                                <span class="text-danger fw-bold">ناکام / مشروط — اسی سال میں دوبارہ جائزہ لیا جائے گا۔</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Footer Signatures -->
                <div class="signatures-row">
                    <div class="signature-item">دستخط نگران / ممتحن</div>
                    <div class="signature-item">دستخط ناظمِ تعلیمات</div>
                    <div class="signature-item">مہر و دستخط مہتمم / صدر مدرس</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
function toggleHeader(chk) {
    const headers = document.querySelectorAll('.header-section');
    headers.forEach(h => {
        h.style.display = chk.checked ? 'none' : 'block';
    });
}

function adjustTopMargin(val) {
    document.getElementById('marginVal').innerText = val + 'mm';
    const spacers = document.querySelectorAll('.letterhead-spacing');
    spacers.forEach(s => {
        s.style.height = val + 'mm';
    });
}
</script>

</body>
</html>
