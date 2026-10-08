<?php 
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once 'db.php'; 
$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_entry_dashboard.php');
    exit;
}

$allowed_classes = get_allowed_classes($pdo);
$allowed_class_names = array_column($allowed_classes, 'name');
if (!in_array($selected_class, $allowed_class_names) && $_SESSION['role'] !== 'admin') {
    die("<div style='text-align:center;margin-top:50px;font-family:sans-serif;'><h2>Access Denied</h2><p>You don't have permission to access this class.</p></div>");
}

include 'header.php'; 

$stmt = $pdo->prepare("SELECT mashq_periods FROM classes WHERE name = ?");
$stmt->execute([$selected_class]);
$c = $stmt->fetch();
$mashq_periods = (int)($c['mashq_periods'] ?? 2);
if ($mashq_periods < 1) $mashq_periods = 1;
if ($mashq_periods > 4) $mashq_periods = 4;

if (isset($_POST['save_husn_saut'])) {
    try {
        $date = $_POST['date'];
        $std_id = $_POST['std_id'];
        
        $manzil_sabaq = $_POST['manzil_sabaq'] ?? '';
        $manzil_listener = $_POST['manzil_listener'] ?? '';
        $manzil_grade = $_POST['manzil_grade'] ?? '';
        
        $mashq_1_sabaq = $_POST['mashq_1_sabaq'] ?? '';
        $mashq_1_time = $_POST['mashq_1_time'] ?? '';
        
        $mashq_2_sabaq = $_POST['mashq_2_sabaq'] ?? '';
        $mashq_2_time = $_POST['mashq_2_time'] ?? '';
        
        $mashq_3_sabaq = $_POST['mashq_3_sabaq'] ?? '';
        $mashq_3_time = $_POST['mashq_3_time'] ?? '';
        
        $mashq_4_sabaq = $_POST['mashq_4_sabaq'] ?? '';
        $mashq_4_time = $_POST['mashq_4_time'] ?? '';

        $tilawat_qty = $_POST['tilawat_qty'] ?? '';
        $tilawat_parahs = $_POST['tilawat_parahs'] ?? '';

        $check = $pdo->prepare("SELECT id FROM husn_saut_record WHERE student_id = ? AND date = ?");
        $check->execute([$std_id, $date]);
        $existing = $check->fetch();
        
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE husn_saut_record SET 
                manzil_sabaq=?, manzil_listener=?, manzil_grade=?, 
                mashq_1_sabaq=?, mashq_1_time=?, 
                mashq_2_sabaq=?, mashq_2_time=?, 
                mashq_3_sabaq=?, mashq_3_time=?, 
                mashq_4_sabaq=?, mashq_4_time=?, 
                tilawat_qty=?, tilawat_parahs=?,
                is_deleted=0, is_synced=0 WHERE id=?");
            $stmt->execute([
                $manzil_sabaq, $manzil_listener, $manzil_grade,
                $mashq_1_sabaq, $mashq_1_time,
                $mashq_2_sabaq, $mashq_2_time,
                $mashq_3_sabaq, $mashq_3_time,
                $mashq_4_sabaq, $mashq_4_time,
                $tilawat_qty, $tilawat_parahs,
                $existing['id']
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO husn_saut_record (
                student_id, date, 
                manzil_sabaq, manzil_listener, manzil_grade,
                mashq_1_sabaq, mashq_1_time,
                mashq_2_sabaq, mashq_2_time,
                mashq_3_sabaq, mashq_3_time,
                mashq_4_sabaq, mashq_4_time,
                tilawat_qty, tilawat_parahs,
                is_deleted, is_synced
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
            $stmt->execute([
                $std_id, $date,
                $manzil_sabaq, $manzil_listener, $manzil_grade,
                $mashq_1_sabaq, $mashq_1_time,
                $mashq_2_sabaq, $mashq_2_time,
                $mashq_3_sabaq, $mashq_3_time,
                $mashq_4_sabaq, $mashq_4_time,
                $tilawat_qty, $tilawat_parahs
            ]);
        }
        echo "<script>window.location='husn_saut.php?class=".urlencode($selected_class)."&date=$date';</script>";
        exit;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

$date = $_GET['date'] ?? date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM students WHERE class = ? AND status IN ('Active', 'Present', 'حاضر') AND (is_deleted=0 OR is_deleted IS NULL)");
$stmt->execute([$selected_class]);
$students = $stmt->fetchAll();

$records = [];
$stmt = $pdo->prepare("SELECT * FROM husn_saut_record WHERE date = ? AND is_deleted = 0");
$stmt->execute([$date]);
while ($r = $stmt->fetch()) {
    $records[$r['student_id']] = $r;
}
?>
<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-book-open text-primary me-2"></i> حسن صوت اندراج</h2>
            <h5 class="text-muted">کلاس: <?php echo htmlspecialchars($selected_class); ?></h5>
        </div>
        <a href="academic_entry_dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> واپس ڈیش بورڈ</a>
    </div>

    <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
                <div class="col-md-4">
                    <label class="form-label fw-bold">تاریخ منتخب کریں:</label>
                    <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date); ?>" onchange="this.form.submit()">
                </div>
            </form>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
        <?php foreach ($students as $s): 
            $r = $records[$s['id']] ?? [];
            $saved = !empty($r);
            $bg = $saved ? 'bg-light-success' : 'bg-white';
        ?>
        <div class="col">
            <div class="card h-100 shadow-sm <?php echo $bg; ?> border-<?php echo $saved?'success':'secondary'; ?>">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <span class="fw-bold fs-5 text-truncate" style="max-width: 70%;" title="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></span>
                    <?php if($saved): ?>
                        <span class="badge bg-success"><i class="fas fa-check"></i> محفوظ ہے</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="fas fa-clock"></i> باقی ہے</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-3">
                    <form method="POST">
                        <input type="hidden" name="std_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
                        
                        <!-- Manzil -->
                        <div class="mb-3 p-2 bg-light rounded border">
                            <label class="fw-bold text-primary mb-2">منزل</label>
                            <div class="row g-2">
                                <div class="col-12 col-sm-4">
                                    <input type="text" name="manzil_sabaq" class="form-control form-control-sm" placeholder="پارہ" value="<?= htmlspecialchars($r['manzil_sabaq'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-sm-4">
                                    <input type="text" name="manzil_listener" class="form-control form-control-sm" placeholder="سننے والا" value="<?= htmlspecialchars($r['manzil_listener'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-sm-4">
                                    <select name="manzil_grade" class="form-select form-select-sm">
                                        <option value="">کیفیت</option>
                                        <?php foreach(['ممتاز','بہتر','معمولی','کمزور'] as $g): ?>
                                            <option value="<?= $g ?>" <?= ($r['manzil_grade']??'') == $g ? 'selected' : '' ?>><?= $g ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Mashq Periods -->
                        <?php for ($i=1; $i<=$mashq_periods; $i++): ?>
                            <div class="mb-3 p-2 bg-light rounded border">
                                <label class="fw-bold text-info mb-2">مشق <?= $i ?></label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="text" name="mashq_<?= $i ?>_sabaq" class="form-control form-control-sm" placeholder="آیات" value="<?= htmlspecialchars($r['mashq_'.$i.'_sabaq'] ?? '') ?>">
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="mashq_<?= $i ?>_time" class="form-control form-control-sm" list="time_opts_<?= $i ?>" placeholder="وقت (منٹ)" value="<?= htmlspecialchars($r['mashq_'.$i.'_time'] ?? '') ?>">
                                        <datalist id="time_opts_<?= $i ?>">
                                            <option value="10 منٹ">
                                            <option value="20 منٹ">
                                            <option value="30 منٹ">
                                            <option value="40 منٹ">
                                            <option value="50 منٹ">
                                            <option value="1 گھنٹہ">
                                        </datalist>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>

                        <!-- Tilawat -->
                        <div class="mb-3 p-2 bg-light rounded border">
                            <label class="fw-bold text-success mb-2">تلاوت</label>
                            <div class="row g-2">
                                <div class="col-12 col-sm-6">
                                    <select name="tilawat_qty" class="form-select form-select-sm">
                                        <option value="">تلاوت مقدار</option>
                                        <?php foreach(['1 پارہ','2 پارے','3 پارے','4 پارے','5 پارے'] as $qty): ?>
                                            <option value="<?= $qty ?>" <?= ($r['tilawat_qty']??'') == $qty ? 'selected' : '' ?>><?= $qty ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <input type="text" name="tilawat_parahs" class="form-control form-control-sm" placeholder="پارہ نمبرز (مثلاً 1, 2)" value="<?= htmlspecialchars($r['tilawat_parahs'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid mt-3">
                            <button type="submit" name="save_husn_saut" class="btn <?= $saved ? 'btn-outline-success' : 'btn-primary' ?> fw-bold">
                                <?= $saved ? '<i class="fas fa-edit me-1"></i> اپڈیٹ کریں' : '<i class="fas fa-save me-1"></i> محفوظ کریں' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($students)): ?>
            <div class="col-12"><div class="alert alert-info">اس کلاس میں کوئی طالب علم موجود نہیں۔</div></div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
