<?php 
require_once 'db.php'; 
include 'header.php'; 

$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_view_dashboard.php');
    exit;
}

$period = $_GET['period'] ?? 'current_week';
$start_date = $_GET['from'] ?? '';
$end_date = $_GET['to'] ?? '';

$week_start_day = 6;
try {
    $settings_stmt = $pdo->query("SELECT week_start_day FROM settings WHERE id=1");
    if ($settings_row = $settings_stmt->fetch()) {
        $week_start_day = isset($settings_row['week_start_day']) ? (int)$settings_row['week_start_day'] : 6;
    }
} catch (Exception $e) {}

if (empty($start_date) || empty($end_date)) {
    $range = get_period_date_range($period, $start_date, $end_date, $week_start_day);
    $start_date = $range['from'];
    $end_date = $range['to'];
}

$selected_student_id = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : 0;
$students_stmt = $pdo->prepare("SELECT id, name, admission_number FROM students WHERE class = ? AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY name ASC");
$students_stmt->execute([$selected_class]);
$filter_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

function translateDateUrduFull($dateStr) {
    if(!$dateStr) return '-';
    $timestamp = strtotime($dateStr);
    $months = ['Jan'=>'جنوری', 'Feb'=>'فروری', 'Mar'=>'مارچ', 'Apr'=>'اپریل', 'May'=>'مئی', 'Jun'=>'جون', 'Jul'=>'جولائی', 'Aug'=>'اگست', 'Sep'=>'تمبر', 'Oct'=>'اکتوبر', 'Nov'=>'نومبر', 'Dec'=>'دسمبر'];
    $days = ['Mon'=>'پیر', 'Tue'=>'منگل', 'Wed'=>'بدھ', 'Thu'=>'جمعرات', 'Fri'=>'جمعہ', 'Sat'=>'ہفتہ', 'Sun'=>'اتوار'];
    return date('d', $timestamp) . '-' . ($months[date('M', $timestamp)] ?? '') . ' (' . ($days[date('D', $timestamp)] ?? '') . ')';
}
?>

<style>
/* Full Responsiveness without horizontal scroll */
.report-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
}

.table-responsive-no-scroll {
    width: 100% !important;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.report-table {
    width: 100% !important;
    table-layout: auto !important;
    font-size: clamp(11px, 1.2vw, 14px);
}

.report-table th, .report-table td {
    word-break: break-word !important;
    white-space: normal !important;
    padding: 6px 8px !important;
    vertical-align: middle;
}

@media (max-width: 768px) {
    .report-table {
        font-size: 11px !important;
    }
    .report-table th, .report-table td {
        padding: 4px 4px !important;
    }
}

@media print {
    @page {
        size: A4 portrait;
        margin: 8mm;
    }
    body {
        background: #fff !important;
        color: #000 !important;
        font-size: 11pt !important;
    }
    .d-print-none, header, footer, nav, .navbar, .sidebar {
        display: none !important;
    }
    #printableArea {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        border: none !important;
    }
    .report-table {
        width: 100% !important;
        table-layout: fixed !important;
    }
}
</style>

<div class="report-wrapper container-fluid px-2 px-md-4 py-3" dir="rtl">
    <!-- Filter Panel -->
    <div class="d-print-none card p-3 mb-4 bg-light shadow-sm text-end border-0 rounded-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold text-primary mb-0"><i class="fas fa-filter me-2"></i> تعلیمی رپورٹ فلٹرز & پرنٹ</h4>
            <a href="academic_view_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
        </div>
        <form method="GET" id="reportFilterForm" class="row g-2 align-items-end">
            <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
            
            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1"><i class="fas fa-calendar-alt me-1"></i> دورانیہ (Period):</label>
                <select name="period" id="periodSelect" class="form-select fw-bold form-select-sm" onchange="applyPeriodDates(this.value)">
                    <option value="current_week" <?php echo $period==='current_week'?'selected':''; ?>>رواں ہفتہ (ڈیفالٹ)</option>
                    <option value="weekly" <?php echo $period==='weekly'?'selected':''; ?>>ہفتہ وار (آخری 7 دن)</option>
                    <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>ماہانہ (آخری 30 دن)</option>
                    <option value="quarterly" <?php echo $period==='quarterly'?'selected':''; ?>>سہ ماہی (آخری 90 دن)</option>
                    <option value="semi_annually" <?php echo $period==='semi_annually'?'selected':''; ?>>شش ماہی (آخری 180 دن)</option>
                    <option value="annually" <?php echo $period==='annually'?'selected':''; ?>>سالانہ (پورا سال)</option>
                    <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>کسٹم ڈیٹس (Custom)</option>
                </select>
            </div>
            
            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1">تاریخ سے:</label>
                <input type="date" name="from" id="fromDateInput" class="form-control form-select-sm fw-bold" value="<?php echo htmlspecialchars($start_date); ?>" required>
            </div>
            
            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1">تاریخ تک:</label>
                <input type="date" name="to" id="toDateInput" class="form-control form-select-sm fw-bold" value="<?php echo htmlspecialchars($end_date); ?>" required>
            </div>

            <div class="col-6 col-md-3">
                <label class="fw-bold text-dark small mb-1"><i class="fas fa-user-graduate me-1 text-primary"></i> طالب علم (سرچ کریں):</label>
                <select name="student_id" class="form-select form-select-sm fw-bold">
                    <option value="">-- تمام طلبہ (ڈیفالٹ) --</option>
                    <?php foreach($filter_students as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $selected_student_id == $st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['admission_number'] . ' - ' . $st['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill fw-bold"><i class="fas fa-search me-1"></i> رپورٹ دکھائیں</button>
                <button type="button" onclick="window.print()" class="btn btn-dark btn-sm flex-fill fw-bold"><i class="fas fa-print me-1"></i> <?php echo t('btn_print'); ?></button>
            </div>
        </form>
    </div>

    <!-- Printable Report View Area -->
    <div id="printableArea" class="bg-white p-3 p-md-4 border rounded-3 shadow-sm text-end">
        <div class="d-flex justify-content-between align-items-center border-bottom border-2 border-dark mb-4 pb-2">
            <div>
                <h3 class="m-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i> عمومی تعلیمی رپورٹ - <?= htmlspecialchars($selected_class) ?></h3>
                <small class="text-muted fw-bold">مدرسہ اشرف المدارس</small>
            </div>
            <div class="text-start">
                <p class="mb-0 fw-bold fs-6">دورانیہ: <span dir="ltr"><?php echo date('d-M-Y', strtotime($start_date)); ?></span> سے <span dir="ltr"><?php echo date('d-M-Y', strtotime($end_date)); ?></span></p>
                <span class="badge bg-secondary"><?php 
                    $period_names = ['weekly'=>'ہفتہ وار', 'monthly'=>'ماہانہ', 'quarterly'=>'سہ ماہی (3 ماہ)', 'semi_annually'=>'شش ماہی (6 ماہ)', 'annually'=>'سالانہ', 'custom'=>'کسٹم ڈیٹس'];
                    echo $period_names[$period] ?? 'ماہانہ';
                ?></span>
            </div>
        </div>

        <?php
        $ar_params = [$start_date, $end_date, $selected_class];
        $ar_sql = "SELECT ar.*, s.name as student_name, s.admission_number FROM academic_reports ar 
                   JOIN students s ON ar.student_id = s.id 
                   WHERE ar.date BETWEEN ? AND ? AND ar.is_deleted = 0 AND ar.class_name = ?";
        if ($selected_student_id) {
            $ar_sql .= " AND ar.student_id = ?";
            $ar_params[] = $selected_student_id;
        }
        $ar_sql .= " ORDER BY ar.date DESC, ar.student_id ASC";
        
        $ar_stmt = $pdo->prepare($ar_sql);
        $ar_stmt->execute($ar_params);
        $ar_rows = $ar_stmt->fetchAll();

        // Fetch attendance map for status links
        try {
            $att_stmt = $pdo->query("SELECT date, student_id, status, status_1, status_2, status_3, status_4 FROM attendance");
            $att_rows = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $att_rows = [];
        }
        $att_map = [];
        foreach ($att_rows as $a) {
            $dt = trim($a['date'] ?? '');
            $sid = (int)($a['student_id'] ?? 0);
            $st = strtoupper(trim(($a['status'] ?? '') . ' ' . ($a['status_1'] ?? '') . ' ' . ($a['status_2'] ?? '') . ' ' . ($a['status_3'] ?? '') . ' ' . ($a['status_4'] ?? '')));
            
            $tokens = preg_split('/\s+/', $st);
            $val = 'P';
            if (strpos($st, 'ABSENT') !== false || strpos($st, 'غیر حاضر') !== false || strpos($st, 'غائب') !== false || in_array('A', $tokens, true) || in_array('غ', $tokens, true)) {
                $val = 'A';
            } elseif (strpos($st, 'LEAVE') !== false || strpos($st, 'رخصت') !== false || in_array('L', $tokens, true) || in_array('ر', $tokens, true)) {
                $val = 'L';
            }
            $att_map["{$dt}_{$sid}"] = $val;
            
            $dNorm = '';
            if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dt, $m)) {
                $dNorm = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            } elseif (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dt, $m)) {
                $dNorm = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if ($dNorm) {
                $att_map["{$dNorm}_{$sid}"] = $val;
            }
        }

        $recited_cnt = 0;
        $naagha_cnt = 0;
        $absent_cnt = 0;
        $leave_cnt = 0;

        foreach ($ar_rows as $row) {
            $dt = $row['date'] ?? '';
            $sid = $row['student_id'] ?? '';
            $att = $att_map["{$dt}_{$sid}"] ?? 'P';
            if ($att === 'A') {
                $absent_cnt++;
            } elseif ($att === 'L') {
                $leave_cnt++;
            } else {
                $grd = $row['grade'] ?? $row['lesson_details'] ?? '';
                if (strpos($grd, 'ناغہ') !== false || empty($grd) || $grd === '-') {
                    $naagha_cnt++;
                } else {
                    $recited_cnt++;
                }
            }
        }

        if (count($ar_rows) > 0):
        ?>
        <!-- Summary Cards -->
        <div class="row g-2 text-center mb-3">
            <div class="col-3">
                <div class="p-2 border rounded bg-light">
                    <div class="fw-bold text-success fs-5"><?= $recited_cnt ?></div>
                    <div class="small fw-bold text-muted">سنانے والے طلبہ</div>
                </div>
            </div>
            <div class="col-3">
                <div class="p-2 border rounded bg-light">
                    <div class="fw-bold text-danger fs-5"><?= $naagha_cnt ?></div>
                    <div class="small fw-bold text-muted">ناغہ کرنے والے</div>
                </div>
            </div>
            <div class="col-3">
                <div class="p-2 border rounded bg-light">
                    <div class="fw-bold text-danger fs-5" style="color: #990000 !important;"><?= $absent_cnt ?></div>
                    <div class="small fw-bold text-muted">غیر حاضر طلبہ</div>
                </div>
            </div>
            <div class="col-3">
                <div class="p-2 border rounded bg-light">
                    <div class="fw-bold text-warning fs-5" style="color: #d97706 !important;"><?= $leave_cnt ?></div>
                    <div class="small fw-bold text-muted">رخصت طلبہ</div>
                </div>
            </div>
        </div>

        <div class="table-responsive-no-scroll">
            <table class="table table-bordered border-dark text-center align-middle report-table">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 20%;"><?php echo t('student'); ?></th>
                        <th style="width: 12%;">حاضری</th>
                        <th style="width: 13%;">کلاس</th>
                        <th style="width: 20%;"><?php echo t('subject'); ?> / کتاب</th>
                        <th style="width: 23%;">تفصیل <?php echo t('sabaq'); ?></th>
                        <th style="width: 12%;">کیفیت / درجہ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $current_date = null;
                    foreach($ar_rows as $row) {
                        if ($current_date !== $row['date']) {
                            $current_date = $row['date'];
                            echo "<tr class='table-secondary'><td colspan='6' class='fw-bold py-2 text-center border-dark'>تاریخ: ".translateDateUrduFull($current_date)."</td></tr>";
                        }
                        $grade = $row['grade'] ?? '';
                        $c = ($grade == 'کمزور' || $grade == 'Weak') ? 'danger' : (($grade == 'بہترین' || $grade == 'Excellent') ? 'success' : 'primary');
                        
                        $dt = $row['date'] ?? '';
                        $sid = $row['student_id'] ?? '';
                        $att_val = $att_map["{$dt}_{$sid}"] ?? 'P';
                        $att_badge = "<span class='badge bg-success'>حاضر</span>";
                        $lesson_det = htmlspecialchars($row['lesson_details'] ?? '');
                        $grade_det = htmlspecialchars($row['grade'] ?? '');

                        if ($att_val === 'A') {
                            $att_badge = "<span class='badge bg-danger'>غیر حاضر</span>";
                            if (empty($lesson_det) || $lesson_det === '-') $lesson_det = "<span class='badge bg-danger'>غیر حاضر</span>";
                            if (empty($grade_det) || $grade_det === '-') $grade_det = "<span class='badge bg-danger'>غیر حاضر</span>";
                        } elseif ($att_val === 'L') {
                            $att_badge = "<span class='badge bg-warning text-dark'>رخصت</span>";
                            if (empty($lesson_det) || $lesson_det === '-') $lesson_det = "<span class='badge bg-warning text-dark'>رخصت</span>";
                            if (empty($grade_det) || $grade_det === '-') $grade_det = "<span class='badge bg-warning text-dark'>رخصت</span>";
                        }

                        echo "<tr>
                            <td class='fw-bold text-start ps-2'>{$row['student_name']} <small class='text-muted'>({$row['admission_number']})</small></td>
                            <td>{$att_badge}</td>
                            <td>{$row['class_name']}</td>
                            <td class='fw-bold'>{$row['subject']}</td>
                            <td class='text-start ps-2'>{$lesson_det}</td>
                            <td class='fw-bold text-$c'>{$grade_det}</td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="alert alert-info text-center fw-bold py-4 my-3">منتخب کردہ دورانیہ میں کوئی جنرل تعلیمی رپورٹ موجود نہیں ہے۔</div>
        <?php endif; ?>

        <!-- Complaints Section -->
        <?php
        $c_params = [$start_date, $end_date, $selected_class];
        $c_sql = "SELECT c.id, c.date, c.complaint as description, s.name as student_name, 'admin' as type 
                  FROM complaints c JOIN students s ON c.student_id = s.id 
                  WHERE c.date BETWEEN ? AND ? AND s.class = ?";
        if ($selected_student_id) {
            $c_sql .= " AND c.student_id = ?";
            $c_params[] = $selected_student_id;
        }
        
        $pc_params = [$start_date, $end_date, $selected_class];
        $pc_sql = "SELECT p.id, p.date, CONCAT('[', p.category, '] ', p.description) as description, s.name as student_name, 'parent' as type 
                   FROM parent_complaints p JOIN students s ON p.student_id = s.id 
                   WHERE p.date BETWEEN ? AND ? AND s.class = ?";
        if ($selected_student_id) {
            $pc_sql .= " AND p.student_id = ?";
            $pc_params[] = $selected_student_id;
        }
        
        $all_c_sql = "$c_sql UNION ALL $pc_sql ORDER BY date DESC, student_name ASC";
        $c_stmt = $pdo->prepare($all_c_sql);
        $all_params = array_merge($c_params, $pc_params);
        $c_stmt->execute($all_params);
        $complaints = $c_stmt->fetchAll();

        if (count($complaints) > 0):
        ?>
        <div class="mt-4">
            <h4 class="fw-bold text-danger border-bottom border-2 border-dark pb-2 mb-3"><i class="fas fa-exclamation-triangle me-2"></i> شکایات و نوٹس ریکارڈ</h4>
            <div class="table-responsive-no-scroll">
                <table class="table table-bordered border-dark text-center align-middle report-table">
                    <thead class="table-secondary">
                        <tr>
                            <th style="width: 15%;">تاریخ</th>
                            <th style="width: 25%;"><?php echo t('student'); ?></th>
                            <th style="width: 45%;">شکایت کی تفصیل</th>
                            <th style="width: 15%;">ذریعہ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($complaints as $c): ?>
                        <tr>
                            <td class="fw-bold"><?php echo translateDateUrduFull($c['date']); ?></td>
                            <td class="fw-bold text-start ps-2"><?php echo htmlspecialchars($c['student_name']); ?></td>
                            <td class="text-start ps-2"><?php echo nl2br(htmlspecialchars($c['description'])); ?></td>
                            <td>
                                <?php if($c['type'] == 'admin'): ?>
                                    <span class="badge bg-danger">مدرسہ</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">والدین</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function applyPeriodDates(val) {
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
        document.getElementById('fromDateInput').value = formatDate(fromDate);
        document.getElementById('toDateInput').value = formatDate(toDate);
    }
}
</script>

<?php include 'footer.php'; ?>


