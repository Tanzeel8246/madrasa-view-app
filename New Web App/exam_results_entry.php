<?php
require_once 'db.php';
include 'header.php';

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
if (!$exam_id) {
    echo "<div class='alert alert-danger'>امتحان کا انتخاب نہیں کیا گیا۔</div>";
    include 'footer.php';
    exit;
}

$exam = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$exam->execute([$exam_id]);
$exam_data = $exam->fetch();

if (!$exam_data) {
    echo "<div class='alert alert-danger'>امتحان نہیں ملا۔</div>";
    include 'footer.php';
    exit;
}

$selected_class_name = $_GET['class'] ?? '';
$selected_subject = $_GET['subject'] ?? 'Final';
$view_mode = $_GET['view'] ?? 'entry'; // entry or grade

// کلاس کا ڈیٹا حاصل کریں
$class_data = null;
if ($selected_class_name) {
    // Ensure columns exist (Safely)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM classes LIKE 'class_type'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE classes ADD COLUMN class_type VARCHAR(20) DEFAULT 'Regular', 
                                         ADD COLUMN p1_name VARCHAR(50) DEFAULT 'Period 1',
                                         ADD COLUMN p2_name VARCHAR(50) DEFAULT 'Period 2',
                                         ADD COLUMN p3_name VARCHAR(50) DEFAULT 'Period 3',
                                         ADD COLUMN p4_name VARCHAR(50) DEFAULT 'Period 4',
                                         ADD COLUMN p5_name VARCHAR(50) DEFAULT 'Period 5',
                                         ADD COLUMN p6_name VARCHAR(50) DEFAULT 'Period 6'");
        }
    } catch (Exception $e) {}

    $stmt = $pdo->prepare("SELECT * FROM classes WHERE name = ?");
    $stmt->execute([$selected_class_name]);
    $class_data = $stmt->fetch() ?: []; // Ensure array even if not found
}

$is_hifz_class = false;
if ($class_data) {
    $c_type = $class_data['class_type'] ?? 'Regular';
    if ($c_type == 'Hifz') {
        $is_hifz_class = true;
    } else {
        $name = $class_data['name'] ?? '';
        if (str_contains($name, 'حفظ') || str_contains($name, 'گردان') || str_contains($name, 'حسن صوت') || str_contains($name, 'ناظرہ')) {
            $is_hifz_class = true;
        }
    }
}

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

// تمام کلاسز حاصل کریں (Dropdown Fix)
$classes = get_allowed_classes($pdo);

// رزلٹ محفوظ کرنا
if (isset($_POST['save_results'])) {
    try {
        $pdo->beginTransaction();
        foreach ($_POST['marks'] as $student_id => $marks_raw) {
            $marks = ($marks_raw === '') ? 0 : (float)$marks_raw;
            $total = $_POST['total_marks'][$student_id] ?: ($is_hifz_class ? 100 : 600);
            $status = $_POST['status'][$student_id] ?: 'Pass';
            $remarks = $_POST['remarks'][$student_id] ?? '';
            $grade = $_POST['grade'][$student_id] ?? '';
            
            // غیر حاضر: نمبر صفر اور سٹیٹس Absent
            if ($status === 'Absent') {
                $marks = 0;
                $grade = 'Absent';
            };
            
            $pukhtagi = (int)($_POST['pukhtagi'][$student_id] ?? 0);
            $tajweed = (int)($_POST['tajweed'][$student_id] ?? 0);
            $lahja = (int)($_POST['lahja'][$student_id] ?? 0);
            $masail = (int)($_POST['masail'][$student_id] ?? 0);
            $miqdar = trim($_POST['miqdar_khwandgi'][$student_id] ?? '');
            
            $p1 = (int)($_POST['p1'][$student_id] ?? 0);
            $p2 = (int)($_POST['p2'][$student_id] ?? 0);
            $p3 = (int)($_POST['p3'][$student_id] ?? 0);
            $p4 = (int)($_POST['p4'][$student_id] ?? 0);
            $p5 = (int)($_POST['p5'][$student_id] ?? 0);
            $p6 = (int)($_POST['p6'][$student_id] ?? 0);
            
            $subject = $is_hifz_class ? 'Quran' : $selected_subject;
            
            // Dynamic Subjects Marks
            $subj_marks_array = [];
            if (!$is_hifz_class && isset($_POST['dyn_marks'][$student_id]) && is_array($_POST['dyn_marks'][$student_id])) {
                foreach ($dynamic_subjects as $idx => $subj_name) {
                    $m = $_POST['dyn_marks'][$student_id][$idx] ?? 0;
                    $subj_marks_array[$subj_name] = ($m === '') ? 0 : (float)$m;
                }
            }
            $subjects_marks_json = json_encode($subj_marks_array, JSON_UNESCAPED_UNICODE);
            
            $check = $pdo->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ? AND subject = ?");
            $check->execute([$exam_id, $student_id, $subject]);
            $existing = $check->fetch();

            if ($existing) {
                $stmt = $pdo->prepare("UPDATE exam_results SET marks_obtained=?, total_marks=?, status=?, remarks=?, grade=?, pukhtagi=?, tajweed=?, lahja=?, masail=?, miqdar_khwandgi=?, p1=?, p2=?, p3=?, p4=?, p5=?, p6=?, subjects_marks=? WHERE id=?");
                $stmt->execute([$marks, $total, $status, $remarks, $grade, $pukhtagi, $tajweed, $lahja, $masail, $miqdar, $p1, $p2, $p3, $p4, $p5, $p6, $subjects_marks_json, $existing['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO exam_results (exam_id, student_id, subject, marks_obtained, total_marks, status, remarks, grade, pukhtagi, tajweed, lahja, masail, miqdar_khwandgi, p1, p2, p3, p4, p5, p6, subjects_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$exam_id, $student_id, $subject, $marks, $total, $status, $remarks, $grade, $pukhtagi, $tajweed, $lahja, $masail, $miqdar, $p1, $p2, $p3, $p4, $p5, $p6, $subjects_marks_json]);
            }
        }
        $pdo->commit();
        echo "<div class='alert alert-success fw-bold text-center'><i class='fas fa-check-circle me-2'></i> تمام نتائج کامیابی سے محفوظ کر لیے گئے!</div>";
        $view_mode = 'grade';
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger fw-bold text-center'><i class='fas fa-exclamation-triangle me-2'></i> غلطی: رزلٹ محفوظ نہیں ہو سکا! <br><small class='fw-normal'>" . $e->getMessage() . "</small></div>";
    }
}

// طلباء کی فہرست
$student_query = "SELECT id, name, father_name, admission_number, serial_no, class FROM students WHERE status='Active' AND is_deleted=0";
if ($selected_class_name) {
    $student_query .= " AND class = " . $pdo->quote($selected_class_name);
}
$student_query .= " ORDER BY name ASC";
$students = $pdo->query($student_query)->fetchAll();

$current_subject = $is_hifz_class ? 'Quran' : $selected_subject;
$results_raw = $pdo->prepare("SELECT * FROM exam_results WHERE exam_id = ? AND subject = ?");
$results_raw->execute([$exam_id, $current_subject]);
$existing_results = [];
foreach ($results_raw->fetchAll() as $r) {
    $existing_results[$r['student_id']] = $r;
}

$total_marks_max = $is_hifz_class ? 100 : $total_dynamic_marks;
$passing_pct = $is_hifz_class ? 33 : 40;
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h2 class="fw-bold text-primary m-0">رزلٹ انٹری (Result Entry)</h2>
            <p class="text-muted mb-0">امتحان: <span class="fw-bold text-dark"><?php echo $exam_data['exam_name']; ?></span> (<?php echo $exam_data['exam_date']; ?>)</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?exam_id=<?php echo $exam_id; ?>&class=<?php echo urlencode($selected_class_name); ?>&subject=<?php echo urlencode($selected_subject); ?>&view=entry" class="btn btn-sm <?php echo $view_mode == 'entry' ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="fas fa-edit me-1"></i> انٹری</a>
            <a href="?exam_id=<?php echo $exam_id; ?>&class=<?php echo urlencode($selected_class_name); ?>&subject=<?php echo urlencode($selected_subject); ?>&view=grade" class="btn btn-sm <?php echo $view_mode == 'grade' ? 'btn-success' : 'btn-outline-success'; ?>"><i class="fas fa-layer-group me-1"></i> گریڈ وائز</a>
            <a href="exams.php" class="btn btn-outline-secondary btn-sm">واپس جائیں</a>
        </div>
    </div>

    <!-- فلٹر بار -->
    <div class="neobox mb-4 p-3 bg-light">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
            <div class="col-md-3">
                <label class="form-label fw-bold">کلاس کا انتخاب کریں:</label>
                <select name="class" class="form-select" onchange="this.form.submit()">
                    <option value="">کلاس منتخب کریں</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['name']; ?>" <?php echo $selected_class_name == $c['name'] ? 'selected' : ''; ?>><?php echo $c['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$is_hifz_class): ?>
            <div class="col-md-3">
                <label class="form-label fw-bold">مضمون/سیشن:</label>
                <input type="text" name="subject" class="form-control" value="<?php echo $selected_subject; ?>" placeholder="مثال: Final">
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">فلٹر کریں</button>
            </div>
        </form>
    </div>

    <?php if ($view_mode == 'grade'): ?>
    <!-- گریڈ وائز ویو -->
    <?php
    $graded_results = [];
    $grade_order = ['A+', 'A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($grade_order as $g) $graded_results[$g] = [];

    foreach ($students as $s) {
        $res = $existing_results[$s['id']] ?? null;
        if ($res) {
            $g = $res['grade'] ?: 'F';
            if (!isset($graded_results[$g])) $graded_results[$g] = [];
            $graded_results[$g][] = array_merge($s, $res);
        }
    }
    ?>
    <div class="row g-3">
        <?php
        $grade_colors = ['A+' => 'success', 'A' => 'primary', 'B' => 'info', 'C' => 'warning', 'D' => 'secondary', 'E' => 'danger', 'F' => 'dark'];
        foreach ($grade_order as $grade):
            if (empty($graded_results[$grade])) continue;
        ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-<?php echo $grade_colors[$grade]; ?> text-white d-flex justify-content-between align-items-center py-2">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-medal me-2"></i> گریڈ <?php echo $grade; ?></h5>
                    <span class="badge bg-white text-dark"><?php echo count($graded_results[$grade]); ?> طلباء</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 text-center">
                            <thead class="table-light">
                                <tr>
                                    <th>شمار</th>
                                    <th class="text-start">طالب علم</th>
                                    <?php if ($is_hifz_class): ?>
                                    <th>مقدار خواندگی</th>
                                    <th>پختگی</th><th>تلفظ</th><th>لہجہ</th><th>نماز</th>
                                    <?php else: ?>
                                    <?php foreach($dynamic_subjects as $subj): ?>
                                    <th width="60"><?php echo htmlspecialchars($subj); ?></th>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    <th>حاصل کردہ</th>
                                    <th>%</th>
                                    <th>کیفیت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sr = 1; foreach ($graded_results[$grade] as $r):
                                    $avg = $r['total_marks'] > 0 ? ($r['marks_obtained'] / $r['total_marks']) * 100 : 0;
                                    $status_class = $r['status'] == 'Fail' ? 'text-danger' : ($r['status'] == 'Grace Pass' ? 'text-info' : 'text-success');
                                    $subj_marks_json = json_decode($r['subjects_marks'] ?? '{}', true) ?: [];
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo $sr++; ?></td>
                                    <td class="text-start">
                                        <div class="fw-bold"><?php echo $r['name']; ?></div>
                                        <small class="text-muted"><?php echo $r['father_name']; ?></small>
                                    </td>
                                    <?php if ($is_hifz_class): ?>
                                    <td class="text-muted"><?php echo $r['miqdar_khwandgi'] ?: '-'; ?></td>
                                    <td><?php echo (int)($r['pukhtagi'] ?? 0); ?>/40</td>
                                    <td><?php echo (int)($r['tajweed'] ?? 0); ?>/20</td>
                                    <td><?php echo (int)($r['lahja'] ?? 0); ?>/20</td>
                                    <td><?php echo (int)($r['masail'] ?? 0); ?>/20</td>
                                    <?php else: ?>
                                    <?php foreach($dynamic_subjects as $subj): ?>
                                    <td><?php echo isset($subj_marks_json[$subj]) ? (float)$subj_marks_json[$subj] : 0; ?></td>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    <td class="fw-bold"><?php echo (float)$r['marks_obtained']; ?></td>
                                    <td class="fw-bold <?php echo $avg < $passing_pct ? 'text-danger' : 'text-primary'; ?>"><?php echo number_format($avg, 1); ?>%</td>
                                    <td class="fw-bold <?php echo $status_class; ?>">
                                        <?php
                                            if ($r['status'] == 'Pass') echo 'پاس';
                                            elseif ($r['status'] == 'Fail') echo 'فیل';
                                            elseif ($r['status'] == 'Grace Pass') echo 'رعایتی';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- رزلٹ انٹری فارم -->
    <form method="POST">
        <input type="hidden" name="subject" value="<?php echo $selected_subject; ?>">
        <div class="neobox p-0 overflow-hidden shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle text-center">
                    <thead class="table-dark">
                        <tr>
                            <th width="80">سیریل نمبر</th>
                            <th class="px-3 text-start">طالب علم / ولدیت</th>
                            <?php if ($is_hifz_class): ?>
                            <th width="350">مقدارِ خواندگی</th>
                            <th width="70">پختگی<br><small>(40)</small></th>
                            <th width="70">تلفظ<br><small>(20)</small></th>
                            <th width="70">لہجہ<br><small>(20)</small></th>
                            <th width="70">نماز<br><small>(20)</small></th>
                            <?php else: ?>
                            <?php foreach($dynamic_subjects as $subj): 
                                $max_m = $dynamic_subjects_marks_max[$subj] ?? 100;
                            ?>
                            <th width="85"><?php echo htmlspecialchars($subj); ?><br><small>(<?php echo $max_m; ?>)</small></th>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <th width="80">حاصل کردہ</th>
                            <th width="80">گریڈ</th>
                            <th width="100">کیفیت</th>
                            <th>ریمارکس</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): 
                            $res = $existing_results[$s['id']] ?? null;
                        ?>
                        <tr>
                            <td class="fw-bold"><?php echo $s['serial_no']; ?></td>
                            <td class="text-start ps-3">
                                <div class="fw-bold"><?php echo $s['name']; ?></div>
                                <small class="text-muted"><?php echo $s['father_name']; ?></small>
                            </td>
                            <?php if ($is_hifz_class): ?>
                            <td>
                                <input type="text" name="miqdar_khwandgi[<?php echo $s['id']; ?>]" class="form-control form-control-sm" value="<?php echo $res['miqdar_khwandgi'] ?? ''; ?>" placeholder="مثال: 3 پارے">
                            </td>
                            <td><input type="number" name="pukhtagi[<?php echo $s['id']; ?>]" class="form-control form-control-sm breakdown-marks" data-sid="<?php echo $s['id']; ?>" value="<?php echo $res['pukhtagi'] ?? ''; ?>" max="40"></td>
                            <td><input type="number" name="tajweed[<?php echo $s['id']; ?>]" class="form-control form-control-sm breakdown-marks" data-sid="<?php echo $s['id']; ?>" value="<?php echo $res['tajweed'] ?? ''; ?>" max="20"></td>
                            <td><input type="number" name="lahja[<?php echo $s['id']; ?>]" class="form-control form-control-sm breakdown-marks" data-sid="<?php echo $s['id']; ?>" value="<?php echo $res['lahja'] ?? ''; ?>" max="20"></td>
                            <td><input type="number" name="masail[<?php echo $s['id']; ?>]" class="form-control form-control-sm breakdown-marks" data-sid="<?php echo $s['id']; ?>" value="<?php echo $res['masail'] ?? ''; ?>" max="20"></td>
                            <?php else: ?>
                            <?php 
                            $subj_marks_json = json_decode($res['subjects_marks'] ?? '{}', true) ?: [];
                            foreach($dynamic_subjects as $idx => $subj): 
                                $val = isset($subj_marks_json[$subj]) ? $subj_marks_json[$subj] : '';
                                $max_m = $dynamic_subjects_marks_max[$subj] ?? 100;
                            ?>
                            <td><input type="number" name="dyn_marks[<?php echo $s['id']; ?>][<?php echo $idx; ?>]" class="form-control form-control-sm breakdown-marks dyn-mark-<?php echo $s['id']; ?>" data-sid="<?php echo $s['id']; ?>" value="<?php echo $val; ?>" max="<?php echo $max_m; ?>"></td>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <td>
                                <input type="hidden" name="total_marks[<?php echo $s['id']; ?>]" value="<?php echo $total_marks_max; ?>">
                                <input type="number" step="0.01" name="marks[<?php echo $s['id']; ?>]" class="form-control form-control-sm fw-bold text-success obtained-marks" id="marks-<?php echo $s['id']; ?>" value="<?php echo $res['marks_obtained'] ?? ''; ?>" readonly>
                            </td>
                            <td><input type="text" name="grade[<?php echo $s['id']; ?>]" class="form-control form-control-sm text-center fw-bold grade-field" id="grade-<?php echo $s['id']; ?>" value="<?php echo $res['grade'] ?? ''; ?>" readonly></td>
                            <td>
                                <select name="status[<?php echo $s['id']; ?>]" class="form-select form-select-sm status-select" id="status-<?php echo $s['id']; ?>">
                                    <option value="Pass"       <?php echo ($res['status']??'') == 'Pass'       ? 'selected' : ''; ?>>پاس</option>
                                    <option value="Fail"       <?php echo ($res['status']??'') == 'Fail'       ? 'selected' : ''; ?>>فیل</option>
                                    <option value="Grace Pass" <?php echo ($res['status']??'') == 'Grace Pass' ? 'selected' : ''; ?>>رعایتی</option>
                                    <option value="Absent" style="color:#e67e22;font-weight:bold;" <?php echo ($res['status']??'') == 'Absent' ? 'selected' : ''; ?>>⊘ غیر حاضر</option>
                                </select>
                            </td>
                            <td><input type="text" name="remarks[<?php echo $s['id']; ?>]" class="form-control form-control-sm" value="<?php echo $res['remarks'] ?? ''; ?>" placeholder="..."></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-center mt-4 mb-5">
            <button type="submit" name="save_results" class="btn btn-primary btn-lg px-5 shadow fw-bold">
                <i class="fas fa-save me-2"></i> تمام رزلٹ محفوظ کریں
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function calculateResult(sid) {
    let obtained = 0;
    const isHifz = <?php echo $is_hifz_class ? 'true' : 'false'; ?>;
    const total = <?php echo $total_marks_max; ?>;
    const passingPct = <?php echo $passing_pct; ?>;
    
    if (isHifz) {
        const pukhtagi = parseFloat(document.querySelector(`input[name="pukhtagi[${sid}]"]`).value) || 0;
        const tajweed = parseFloat(document.querySelector(`input[name="tajweed[${sid}]"]`).value) || 0;
        const lahja = parseFloat(document.querySelector(`input[name="lahja[${sid}]"]`).value) || 0;
        const masail = parseFloat(document.querySelector(`input[name="masail[${sid}]"]`).value) || 0;
        obtained = pukhtagi + tajweed + lahja + masail;
    } else {
        const dynInputs = document.querySelectorAll(`.dyn-mark-${sid}`);
        if (dynInputs.length > 0) {
            dynInputs.forEach(inp => { obtained += parseFloat(inp.value) || 0; });
        } else {
            const p1 = parseFloat(document.querySelector(`input[name="p1[${sid}]"]`)?.value) || 0;
            const p2 = parseFloat(document.querySelector(`input[name="p2[${sid}]"]`)?.value) || 0;
            const p3 = parseFloat(document.querySelector(`input[name="p3[${sid}]"]`)?.value) || 0;
            const p4 = parseFloat(document.querySelector(`input[name="p4[${sid}]"]`)?.value) || 0;
            const p5 = parseFloat(document.querySelector(`input[name="p5[${sid}]"]`)?.value) || 0;
            const p6 = parseFloat(document.querySelector(`input[name="p6[${sid}]"]`)?.value) || 0;
            obtained = p1 + p2 + p3 + p4 + p5 + p6;
        }
    }

    const obtainedField = document.getElementById(`marks-${sid}`);
    obtainedField.value = obtained;

    const statusSelect = document.getElementById(`status-${sid}`);
    const gradeField = document.getElementById(`grade-${sid}`);

    if (total > 0) {
        const perc = (obtained / total) * 100;
        // اگر سٹیٹس manually Absent سیٹ ہے تو نہ بدلیں
        if (statusSelect.value !== 'Absent') {
            statusSelect.value = (perc >= passingPct) ? 'Pass' : 'Fail';
        }
        let grade = 'F';
        if (statusSelect.value === 'Absent') { grade = 'Absent'; }
        else if (perc >= 90) grade = 'A+';
        else if (perc >= 80) grade = 'A';
        else if (perc >= 70) grade = 'B';
        else if (perc >= 60) grade = 'C';
        else if (perc >= 50) grade = 'D';
        else if (perc >= 40) grade = 'E';
        gradeField.value = grade;
    }
}

// غیر حاضر سیلیکٹ کرنے پر نمبر صفر اور گریڈ Absent
document.querySelectorAll('.status-select').forEach(sel => {
    sel.addEventListener('change', function() {
        const sid = this.id.replace('status-', '');
        if (this.value === 'Absent') {
            const marksField = document.getElementById(`marks-${sid}`);
            const gradeField = document.getElementById(`grade-${sid}`);
            if (marksField) marksField.value = 0;
            if (gradeField) gradeField.value = 'Absent';
            // تمام بریک ڈاؤن فیلڈز صفر کریں (خالی نہیں، بلکہ 0)
            ['p1','p2','p3','p4','p5','p6','pukhtagi','tajweed','lahja','masail'].forEach(f => {
                const inp = document.querySelector(`input[name="${f}[${sid}]"]`);
                if (inp) inp.value = 0;
            });
            document.querySelectorAll(`.dyn-mark-${sid}`).forEach(inp => {
                inp.value = 0;
            });
            // رو کو ہلکا رنگ دیں
            const row = marksField?.closest('tr');
            if (row) row.style.opacity = '0.6';
        } else {
            // رو کو واپس نارمل کریں
            const marksField = document.getElementById(`marks-${sid}`);
            const row = marksField?.closest('tr');
            if (row) row.style.opacity = '1';
            // گریڈ خودکار لگائیں
            const gradeField = document.getElementById(`grade-${sid}`);
            if (gradeField && gradeField.value === 'Absent') gradeField.value = '';
            calculateResult(sid);
        }
    });
});

document.querySelectorAll('.breakdown-marks').forEach(input => {
    input.addEventListener('input', function() {
        calculateResult(this.dataset.sid);
    });
});
</script>

<?php include 'footer.php'; ?>
