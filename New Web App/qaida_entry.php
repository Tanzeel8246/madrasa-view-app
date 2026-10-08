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

if (isset($_POST['save_qaida'])) {
    try {
        $date = $_POST['date'];
        $std_id = $_POST['std_id'];
        $lesson_type = $_POST['lesson_type'] ?? 'نورانی قاعدہ';
        
        $hearing_time = $_POST['hearing_time'] ?? '';
        
        $s1 = $_POST['sabaq_1'] ?? ''; $q1 = $_POST['qty_1'] ?? ''; $g1 = $_POST['grade_1'] ?? '';
        $s2 = $_POST['sabaq_2'] ?? ''; $q2 = $_POST['qty_2'] ?? ''; $g2 = $_POST['grade_2'] ?? '';
        $s3 = $_POST['sabaq_3'] ?? ''; $q3 = $_POST['qty_3'] ?? ''; $g3 = $_POST['grade_3'] ?? '';
        $s4 = $_POST['sabaq_4'] ?? ''; $q4 = $_POST['qty_4'] ?? ''; $g4 = $_POST['grade_4'] ?? '';
        
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS qaida_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT,
            date DATE,
            lesson_type VARCHAR(100) DEFAULT 'نورانی قاعدہ',
            hearing_time VARCHAR(50),
            sabaq_1 VARCHAR(255), qty_1 VARCHAR(255), grade_1 VARCHAR(50),
            sabaq_2 VARCHAR(255), qty_2 VARCHAR(255), grade_2 VARCHAR(50),
            sabaq_3 VARCHAR(255), qty_3 VARCHAR(255), grade_3 VARCHAR(50),
            sabaq_4 VARCHAR(255), qty_4 VARCHAR(255), grade_4 VARCHAR(50),
            is_deleted TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Add new columns if they don't exist
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN hearing_time VARCHAR(50)"); } catch(PDOException $e) {}
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN qty_1 VARCHAR(255)"); } catch(PDOException $e) {}
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN qty_2 VARCHAR(255)"); } catch(PDOException $e) {}
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN qty_3 VARCHAR(255)"); } catch(PDOException $e) {}
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN qty_4 VARCHAR(255)"); } catch(PDOException $e) {}

        $check = $pdo->prepare("SELECT id FROM qaida_records WHERE student_id = ? AND date = ?");
        $check->execute([$std_id, $date]);
        $existing = $check->fetch();
        
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE qaida_records SET lesson_type=?, hearing_time=?, sabaq_1=?, qty_1=?, grade_1=?, sabaq_2=?, qty_2=?, grade_2=?, sabaq_3=?, qty_3=?, grade_3=?, sabaq_4=?, qty_4=?, grade_4=?, is_deleted=0 WHERE id=?");
            $stmt->execute([$lesson_type, $hearing_time, $s1, $q1, $g1, $s2, $q2, $g2, $s3, $q3, $g3, $s4, $q4, $g4, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO qaida_records (student_id, date, lesson_type, hearing_time, sabaq_1, qty_1, grade_1, sabaq_2, qty_2, grade_2, sabaq_3, qty_3, grade_3, sabaq_4, qty_4, grade_4, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$std_id, $date, $lesson_type, $hearing_time, $s1, $q1, $g1, $s2, $q2, $g2, $s3, $q3, $g3, $s4, $q4, $g4]);
        }
        echo "<script>window.location='qaida_entry.php?class=".urlencode($selected_class)."&date=$date';</script>";
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
$stmt = $pdo->prepare("SELECT * FROM qaida_records WHERE date = ? AND is_deleted = 0");
$stmt->execute([$date]);
while ($r = $stmt->fetch()) {
    $records[$r['student_id']] = $r;
}
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-book-open text-primary me-2"></i> قاعدہ / نماز حنفی اندراج</h2>
            <h5 class="text-muted">کلاس: <?php echo htmlspecialchars($selected_class); ?></h5>
        </div>
        <a href="academic_entry_dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> واپس ڈیش بورڈ</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="class" value="<?php echo htmlspecialchars($selected_class); ?>">
                <div class="col-md-4">
                    <label class="form-label fw-bold">تاریخ منتخب کریں:</label>
                    <input type="date" name="date" class="form-control" value="<?php echo $date; ?>" onchange="this.form.submit()">
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>

    <div class="row">
        <?php foreach ($students as $s): 
            $rec = $records[$s['id']] ?? null;
            $status_color = $rec ? "border-success" : "border-warning";
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 shadow-sm <?php echo $status_color; ?>" style="border-width: 2px;">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <h5 class="card-title fw-bold text-primary"><?php echo htmlspecialchars($s['name']); ?></h5>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($s['roll_number'] ?: $s['admission_number']); ?></span>
                    </div>
                    <?php if ($rec): ?>
                        <p class="mb-1"><strong>شعبہ:</strong> <?php echo htmlspecialchars($rec['lesson_type']); ?></p>
                        <?php if(!empty($rec['hearing_time'])): ?>
                            <p class="mb-1"><strong>سنانے کا وقت:</strong> <?php echo date("h:i A", strtotime($rec['hearing_time'])); ?></p>
                        <?php endif; ?>
                        <?php for($i=1; $i<=4; $i++): ?>
                            <?php if(!empty($rec['sabaq_'.$i])): ?>
                                <p class="mb-1 text-muted" style="font-size:0.9em;">
                                    سبق <?php echo $i; ?>: 
                                    <?php echo htmlspecialchars($rec['sabaq_'.$i]); ?> 
                                    <?php if(!empty($rec['qty_'.$i])) echo ' - ' . htmlspecialchars($rec['qty_'.$i]); ?> 
                                    (<?php echo htmlspecialchars($rec['grade_'.$i]); ?>)
                                </p>
                            <?php endif; ?>
                        <?php endfor; ?>
                    <?php else: ?>
                        <p class="text-danger mb-0"><i class="fas fa-times-circle me-1"></i> اندراج نہیں ہوا</p>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white border-top-0">
                    <button class="btn btn-outline-primary w-100" onclick='openModal(<?php echo json_encode($s); ?>, <?php echo json_encode($rec ?: []); ?>)'>
                        <i class="fas fa-edit me-1"></i> اندراج / ترمیم
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="entryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="modalTitle">اندراج</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light">
        <input type="hidden" name="std_id" id="m_std_id">
        <input type="hidden" name="date" value="<?php echo $date; ?>">
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">شعبہ منتخب کریں:</label>
                <select name="lesson_type" id="m_lesson_type" class="form-select border-primary shadow-sm" style="font-size:1.1em;">
                    <option value="نورانی قاعدہ">نورانی قاعدہ</option>
                    <option value="نماز حنفی">نماز حنفی</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">سنانے کا وقت:</label>
                <input type="time" name="hearing_time" id="m_hearing_time" class="form-control border-primary shadow-sm" style="font-size:1.1em;">
            </div>
        </div>
        
        <div class="row">
            <?php for($i=1; $i<=4; $i++): ?>
            <div class="col-md-6 mb-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-secondary border-bottom pb-2">سبق <?php echo $i; ?></h6>
                        <label class="form-label mt-2">تفصیل (صفحہ/تختی):</label>
                        <select name="sabaq_<?php echo $i; ?>" id="m_sabaq_<?php echo $i; ?>" class="form-select mb-2 qaida-takhti-select">
                        </select>
                        <label class="form-label">مقدار:</label>
                        <input type="text" name="qty_<?php echo $i; ?>" id="m_qty_<?php echo $i; ?>" class="form-control mb-2" placeholder="مثلاً ایک لائن">
                        <label class="form-label">کیفیت / درجہ:</label>
                        <select name="grade_<?php echo $i; ?>" id="m_grade_<?php echo $i; ?>" class="form-select">
                            <option value="">منتخب کریں</option>
                            <option value="بہت اچھا">بہت اچھا</option>
                            <option value="اچھا">اچھا</option>
                            <option value="درمیانہ">درمیانہ</option>
                            <option value="کمزور">کمزور</option>
                            <option value="ناغہ">ناغہ (Absent)</option>
                        </select>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بند کریں</button>
        <button type="submit" name="save_qaida" class="btn btn-primary"><i class="fas fa-save me-1"></i> محفوظ کریں</button>
      </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(student, rec) {
    document.getElementById('modalTitle').innerText = student.name + " (" + (student.roll_number || student.admission_number) + ") - " + "<?php echo $date; ?>";
    document.getElementById('m_std_id').value = student.id;
    
    let lessonType = rec.lesson_type || 'نورانی قاعدہ';
    document.getElementById('m_lesson_type').value = lessonType;
    document.getElementById('m_hearing_time').value = rec.hearing_time || '';

    updateDropdowns(lessonType);

    for(let i=1; i<=4; i++) {
        let sVal = rec['sabaq_'+i] || '';
        let sEl = document.getElementById('m_sabaq_'+i);
        if (sVal && !Array.from(sEl.options).some(o => o.value === sVal)) {
            sEl.innerHTML += `<option value="${sVal}">${sVal}</option>`;
        }
        sEl.value = sVal;
        document.getElementById('m_qty_'+i).value = rec['qty_'+i] || '';
        document.getElementById('m_grade_'+i).value = rec['grade_'+i] || '';
    }
    
    new bootstrap.Modal(document.getElementById('entryModal')).show();
}

function updateDropdowns(lessonType) {
    let isNamaz = lessonType.includes('نماز حنفی') || '<?php echo htmlspecialchars($selected_class); ?>'.includes('نماز حنفی');
    let maxOptions = isNamaz ? 80 : 17;
    let labelText = isNamaz ? 'صفحہ' : 'تختی';
    
    let selects = document.querySelectorAll('.qaida-takhti-select');
    selects.forEach(sel => {
        let currentVal = sel.value;
        sel.innerHTML = '<option value="">منتخب کریں</option>';
        for(let j=1; j<=maxOptions; j++) {
            sel.innerHTML += `<option value="${labelText} ${j}">${labelText} ${j}</option>`;
        }
        if (Array.from(sel.options).some(o => o.value === currentVal)) {
            sel.value = currentVal;
        }
    });
}

document.getElementById('m_lesson_type').addEventListener('change', function() {
    updateDropdowns(this.value);
});
</script>
<?php include 'footer.php'; ?>
