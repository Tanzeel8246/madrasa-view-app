<?php require_once 'db.php'; include 'header.php'; 
$cur_tenant_id = (int)get_current_tenant_id();

// صرف ایڈمن کو اجازت ہے
if (!in_array($_SESSION['role'] ?? '', ['admin', 'nazim', 'teacher'])) { echo "<script>window.location='dashboard.php';</script>"; exit; }

// شکایت محفوظ کرنا
if (isset($_POST['save_complaint'])) {
    $cat = $_POST['category'];
    if ($cat == '__NEW__' && !empty($_POST['new_category'])) {
        $cat = $_POST['new_category'];
    } elseif ($cat == '__NEW__') {
        $cat = 'اساتذہ/مدرسہ کی طرف سے (نوٹ)';
    }

    $final_complaint = "[$cat] " . $_POST['complaint'];
    $stmt = $pdo->prepare("INSERT INTO complaints (student_id, date, complaint, tenant_id) VALUES (?, ?, ?, ?)");
    
    if (!empty($_POST['std_ids']) && is_array($_POST['std_ids'])) {
        foreach ($_POST['std_ids'] as $std_id) {
            $stmt->execute([$std_id, $_POST['date'], $final_complaint, $cur_tenant_id]);
        }
        echo "<div class='alert alert-success'>✅ شکایت / نوٹ تمام منتخب طلباء کے ریکارڈ میں کامیابی سے شامل کر دیا گیا ہے!</div>";
    } else {
        echo "<div class='alert alert-danger'>⚠️ کوئی طالب علم منتخب نہیں کیا گیا!</div>";
    }
}

// ڈیلیٹ کرنا (اگر غلطی سے درج ہو جائے)
if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM complaints WHERE id=? AND tenant_id=?")->execute([$_GET['del'], $cur_tenant_id]);
    echo "<script>window.location='add_complaint.php';</script>";
}

// طلباء کی لسٹ
$students = $pdo->query("SELECT * FROM students WHERE status='Active' AND is_deleted=0 AND tenant_id = {$cur_tenant_id} ORDER BY name ASC")->fetchAll();

// کیٹیگریز
$categories = $pdo->query("SELECT DISTINCT category FROM parent_complaints WHERE category != '' AND tenant_id = {$cur_tenant_id}")->fetchAll(PDO::FETCH_COLUMN);

// حالیہ شکایات (Recent)
$stmt = $pdo->prepare("SELECT c.id, c.date, c.complaint AS description, s.name, s.father_name, s.admission_number FROM complaints c JOIN students s ON c.student_id = s.id WHERE c.tenant_id = ? AND s.tenant_id = ? ORDER BY c.date DESC, c.id DESC LIMIT 50");
$stmt->execute([$cur_tenant_id, $cur_tenant_id]);
$recent = $stmt->fetchAll();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow border-danger border-top border-3">
                <div class="card-header bg-white fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i> <?php echo __('شکایت / نوٹ درج کریں', 'Enter Complaint / Note'); ?></div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="fw-bold text-primary mb-1"><i class="fas fa-users me-1"></i> <?php echo __('طلباء منتخب کریں (ایک یا ایک سے زیادہ)', 'Select Students (One or more)'); ?></label>
                            <select name="std_ids[]" class="form-select select2" multiple="multiple" required data-placeholder="<?php echo __('-- طلباء منتخب کریں --', '-- Select Students --'); ?>">
                                <?php foreach($students as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?><?php echo !empty($s['father_name']) ? ' ولد ' . htmlspecialchars($s['father_name']) : ''; ?> (ID: <?php echo htmlspecialchars($s['admission_number']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label><?php echo __('تاریخ', 'Date'); ?></label>
                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label><?php echo __('کیٹیگری (Category)', 'Category'); ?></label>
                            <select name="category" id="cat_select" class="form-select" onchange="if(this.value=='__NEW__') document.getElementById('new_cat_div').style.display='block'; else document.getElementById('new_cat_div').style.display='none';" required>
                                <option value="اساتذہ/مدرسہ کی طرف سے (نوٹ)"><?php echo __('اساتذہ/مدرسہ کی طرف سے (نوٹ)', 'From Teacher/Madrasa (Note)'); ?></option>
                                <?php foreach($categories as $c): if($c != 'اساتذہ/مدرسہ کی طرف سے (نوٹ)') echo "<option value='$c'>$c</option>"; endforeach; ?>
                                <option value="__NEW__" class="fw-bold text-danger"><?php echo __('+ نئی کیٹیگری شامل کریں', '+ Add New Category'); ?></option>
                            </select>
                        </div>
                        <div class="mb-3" id="new_cat_div" style="display:none;">
                            <label><?php echo __('نئی کیٹیگری کا نام', 'New Category Name'); ?></label>
                            <input type="text" name="new_category" class="form-control" placeholder="<?php echo __('مثلاً: صفائی، ڈسپلن...', 'e.g. Cleanliness, Discipline...'); ?>">
                        </div>
                        <div class="mb-3">
                            <label><?php echo __('تفصیل (شکایت / نوٹ)', 'Details (Complaint / Note)'); ?></label>
                            <textarea name="complaint" class="form-control" rows="4" placeholder="<?php echo __('مثلاً: آج سبق یاد نہیں کیا، یا کلاس میں شرارت کی۔۔۔', 'e.g. Lesson not memorized, disruption in class...'); ?>" required></textarea>
                        </div>
                        <button type="submit" name="save_complaint" class="btn btn-danger w-100 fw-bold"><?php echo __('محفوظ کریں', 'Save'); ?></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-history me-2"></i> <?php echo __('حالیہ ریکارڈ (آخری 50)', 'Recent Records (Last 50)'); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th><?php echo __('تاریخ', 'Date'); ?></th><th><?php echo __('طالب علم', 'Student'); ?></th><th><?php echo __('شکایت', 'Complaint'); ?></th><th><?php echo __('ایکشن', 'Action'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach($recent as $r): ?>
                            <tr>
                                <td><?php echo date('d-M', strtotime($r['date'])); ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($r['name']); ?><?php echo !empty($r['father_name']) ? ' <small class="text-muted fw-normal">ولد ' . htmlspecialchars($r['father_name']) . '</small>' : ''; ?></td>
                                <td>
                                    <span class="text-muted small"><?php echo nl2br(htmlspecialchars($r['description'])); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-success mb-1 d-block">شامل ہو گئی</span>
                                    <a href="?del=<?php echo $r['id']; ?>" onclick="return confirm('حذف کریں؟')" class="btn btn-sm btn-light text-danger w-100"><i class="fas fa-trash"></i> حذف</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>