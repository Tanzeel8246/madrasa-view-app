<?php 
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once 'db.php'; 
if (empty($_GET['class']) && empty($_POST['class_name'])) {
    header('Location: academic_entry_dashboard.php');
    exit;
}

include 'header.php'; 

// --- 1. ڈیٹا محفوظ کرنے کا عمل ---
if (isset($_POST['save_academic'])) {
    try {
        $date = $_POST['date'] ?? date('Y-m-d');
        $class_name = $_POST['class_name'] ?? '';
        $subject = $_POST['subject'] ?? '';
        
        if ($class_name && $subject) {
            $std_ids = $_POST['std_ids'] ?? [];
            $lessons = $_POST['lessons'] ?? [];
            $grades = $_POST['grades'] ?? [];
            
            $pdo->beginTransaction();
            foreach ($std_ids as $std_id) {
                // Remove existing reports for this student, date, and subject
                $pdo->prepare("DELETE FROM academic_reports WHERE student_id = ? AND date = ? AND subject = ?")->execute([$std_id, $date, $subject]);
                
                $lesson = trim($lessons[$std_id] ?? '');
                $grade = trim($grades[$std_id] ?? '');
                
                // Save if there's any data
                if ($lesson !== '' || $grade !== '') {
                    $stmt = $pdo->prepare("INSERT INTO academic_reports (student_id, date, class_name, subject, lesson_details, grade) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$std_id, $date, $class_name, $subject, $lesson, $grade]);
                }
            }
            $pdo->commit();
            
            echo "<div class='alert alert-success text-center fw-bold shadow mt-2' id='alert_msg'>✅ تعلیمی رپورٹ کامیابی سے محفوظ ہو گئی!</div>";
            echo "<script>setTimeout(()=>{ document.getElementById('alert_msg').style.display='none'; }, 2000);</script>";
        }
    } catch (Exception $e) { 
        if($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>"; 
    }
}


// 2. فلٹرز کی وصولی
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_class = $_GET['class'] ?? '';
$selected_subject = $_GET['subject'] ?? '';

function academic_normalize_class($value) {
    $value = trim((string)$value);
    $value = str_replace(['ٰ', 'ى', 'ي', 'ك'], ['', 'ی', 'ی', 'ک'], $value);
    $value = preg_replace('/\s+/u', '', $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

// 3. کلاسز لانا
$classes_data = get_allowed_classes($pdo);

// منتخب کلاس کے مضامین نکالنا
$available_subjects = [];
if ($selected_class) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE name = ?");
    $stmt->execute([$selected_class]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($c) {
        $is_hifz = ($c['class_type'] == 'Hifz/Nazira');
        
        $stmt_p = $pdo->prepare("SELECT books FROM class_periods WHERE class_id = ? AND books IS NOT NULL AND books != ''");
        $stmt_p->execute([$c['id']]);
        $periods = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($periods)) {
            foreach ($periods as $p) {
                if (trim($p['books']) !== '') {
                    $available_subjects[] = trim($p['books']);
                }
            }
        } else {
            if (!empty($c['subjects_list'])) {
                $parts = array_filter(array_map('trim', explode(',', $c['subjects_list'])));
                foreach($parts as $p) {
                    $p_parts = explode(':', $p);
                    $available_subjects[] = trim($p_parts[0]);
                }
            } elseif ($is_hifz) {
                $available_subjects[] = 'قرآن';
            }
        }
        $available_subjects = array_values(array_unique($available_subjects));
        
        // Auto-select first subject if none is selected
        if (!$selected_subject && !empty($available_subjects)) {
            $selected_subject = $available_subjects[0];
        }
    }
}

// 4. طلباء لانا
$students = [];
$existing_reports = [];
if ($selected_class && $selected_subject) {
    $studentColumns = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
    $classExpr = in_array('class_name', $studentColumns, true)
        ? "COALESCE(NULLIF(class, ''), NULLIF(class_name, ''))"
        : "class";
    $stmt = $pdo->query("SELECT id, name, father_name, admission_number, $classExpr AS student_class FROM students WHERE (LOWER(TRIM(status)) IN ('active', 'present', '') OR status='حاضر' OR status IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY admission_number ASC");
    $students = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function($student) use ($selected_class) {
        return academic_normalize_class($student['student_class'] ?? '') === academic_normalize_class($selected_class);
    }));
    
    // پہلے سے موجود رپورٹ لانا
    $stmt = $pdo->prepare("SELECT student_id, lesson_details, grade FROM academic_reports WHERE class_name = ? AND subject = ? AND date = ?");
    $stmt->execute([$selected_class, $selected_subject, $selected_date]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($reports as $r) {
        $existing_reports[$r['student_id']] = $r;
    }
}
?>

<style>
    .table-responsive { width: 100% !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table th, .table td { word-break: break-word !important; white-space: normal !important; }
</style>

<div class="container py-3 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h2 class="fw-bold text-primary m-0"><i class="fas fa-book-open me-2"></i> جنرل تعلیمی رپورٹ (اندراج)</h2>
        <a href="academic_entry_dashboard.php" class="btn btn-outline-secondary fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    </div>

    <!-- فلٹرز -->
    <div class="card p-4 shadow-sm border-0 border-top border-5 border-primary mb-4">
        <form method="GET" id="filter_form">
            <div class="row g-3 bg-light p-3 rounded mx-0 border">
                <div class="col-md-4">
                    <label class="fw-bold">تاریخ:</label>
                    <input type="date" name="date" class="form-control fw-bold" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()" required>
                </div>
                <div class="col-md-4">
                    <label class="fw-bold">کلاس:</label>
                    <h4 class="fw-bold text-danger m-0 py-2"><?php echo htmlspecialchars($selected_class); ?></h4>
                    <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
                </div>
                <div class="col-md-4">
                    <label class="fw-bold">مضمون / کتاب کا انتخاب کریں:</label>
                    <select name="subject" id="subject_select" class="form-select fw-bold" onchange="this.form.submit()" required <?php echo empty($available_subjects) ? 'disabled' : ''; ?>>
                        <option value="">-- منتخب کریں --</option>
                        <?php foreach($available_subjects as $subj): ?>
                            <option value="<?php echo htmlspecialchars($subj); ?>" <?php echo $selected_subject === $subj ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subj); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if($selected_class && empty($available_subjects)): ?>
                        <small class="text-danger">اس کلاس کے کوئی مضامین متعین نہیں ہیں۔</small>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- انٹری فارم -->
    <?php if ($selected_class && $selected_subject): ?>
        <?php if (!empty($students)): ?>
            <form method="POST" id="academic_form" class="card p-4 shadow-sm border-0">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <input type="hidden" name="class_name" value="<?php echo htmlspecialchars($selected_class); ?>">
                <input type="hidden" name="subject" value="<?php echo htmlspecialchars($selected_subject); ?>">
                
                <div class="alert alert-info border-info fw-bold mb-3 d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-chalkboard-teacher me-2"></i>
                        کلاس: <span class="text-danger fw-bolder mx-1"><?php echo htmlspecialchars($selected_class); ?></span> | 
                        مضمون: <span class="text-primary fw-bolder mx-1"><?php echo htmlspecialchars($selected_subject); ?></span>
                    </span>
                    <span class="badge bg-primary fs-6">کل طلباء: <?php echo count($students); ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle bg-white">
                        <thead class="table-light text-center">
                            <tr>
                                <th style="width:5%;">#</th>
                                <th style="width:25%;">نام طالب علم</th>
                                <th style="width:45%;">سبق کی تفصیل (صفحہ / ٹاپک)</th>
                                <th style="width:25%;">کیفیت / درجہ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sr = 1; foreach ($students as $s): 
                                $sid = $s['id'];
                                $rep = $existing_reports[$sid] ?? null;
                                $lesson = $rep['lesson_details'] ?? '';
                                $grade = $rep['grade'] ?? '';
                            ?>
                            <tr>
                                <td class="text-center fw-bold text-muted"><?php echo $sr++; ?></td>
                                <td class="fw-bold text-primary">
                                    <?php echo htmlspecialchars($s['name']); ?>
                                    <div class="small text-muted fw-normal">ولد <?php echo htmlspecialchars($s['father_name']); ?></div>
                                    <input type="hidden" name="std_ids[]" value="<?php echo $sid; ?>">
                                </td>
                                <td>
                                    <textarea name="lessons[<?php echo $sid; ?>]" class="form-control" rows="1" placeholder="آج کا سبق..."><?php echo htmlspecialchars($lesson); ?></textarea>
                                </td>
                                <td>
                                    <select name="grades[<?php echo $sid; ?>]" class="form-select fw-bold <?php echo ($grade == 'غیر حاضر') ? 'text-danger' : ''; ?>">
                                        <option value="" class="text-dark">-- درجہ منتخب کریں --</option>
                                        <option value="بہترین" <?php echo $grade == 'بہترین' ? 'selected' : ''; ?>>بہترین</option>
                                        <option value="بہتر" <?php echo $grade == 'بہتر' ? 'selected' : ''; ?>>بہتر</option>
                                        <option value="رعایتی پاس" <?php echo $grade == 'رعایتی پاس' ? 'selected' : ''; ?>>رعایتی پاس</option>
                                        <option value="کچا" <?php echo $grade == 'کچا' ? 'selected' : ''; ?>>کچا</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <button type="submit" name="save_academic" class="btn btn-primary w-100 py-3 fw-bold shadow fs-5">
                            <i class="fas fa-save me-2"></i> مکمل کلاس کا رزلٹ محفوظ کریں
                        </button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-warning text-center fw-bold mt-4">اس کلاس میں فی الحال کوئی طالب علم موجود نہیں ہے۔</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-center mt-5 text-muted">
            <i class="fas fa-hand-pointer fa-3x mb-3"></i>
            <h5 class="fw-bold">براہِ کرم اوپر سے کلاس اور مضمون منتخب کریں</h5>
        </div>
    <?php endif; ?>
</div>

<script>
    // To maintain dropdown colors dynamically if changed
    document.querySelectorAll('select[name^="grades"]').forEach(function(select) {
        select.addEventListener('change', function() {
            if(this.value === 'غیر حاضر') {
                this.classList.add('text-danger');
            } else {
                this.classList.remove('text-danger');
            }
        });
    });
</script>

<?php include 'footer.php'; ?>