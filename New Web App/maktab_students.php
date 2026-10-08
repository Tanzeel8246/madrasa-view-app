<?php 
// ایرر رپورٹنگ
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

require_once 'db.php'; 
include 'header.php'; 

// --- ایکشنز (Actions) ---

// 1. فارغ التحصیل کرنا (Alumni)
if (isset($_POST['graduate_student'])) {
    $id = $_POST['student_id'];
    $g_date = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;
    $reason = $_POST['reason'];
    $duration = !empty($_POST['duration']) ? $_POST['duration'] : null;

    $stmt = $pdo->prepare("UPDATE maktab_students SET status = 'Alumni', graduation_date = ?, leaving_reason = ?, duration = ? WHERE id = ?");
    $stmt->execute([$g_date, $reason, $duration, $id]);
    echo "<script>window.location='maktab_students.php';</script>";
}

// 2. بحال کرنا (Restore - اگر غلطی سے فارغ ہو گیا ہو)
if (isset($_GET['restore'])) {
    $stmt = $pdo->prepare("UPDATE maktab_students SET status = 'Active' WHERE id = ?");
    $stmt->execute([$_GET['restore']]);
    echo "<script>window.location='maktab_students.php';</script>";
}

// 2.5 پروموٹ کرنا (Promote)
if (isset($_POST['promote_student'])) {
    $id = $_POST['student_id'];
    $new_class = $_POST['new_class'];
    $stmt = $pdo->prepare("UPDATE maktab_students SET class = ? WHERE id = ?");
    $stmt->execute([$new_class, $id]);
    echo "<script>window.location='maktab_students.php';</script>";
}

// 3. ڈیلیٹ کرنا (Delete)
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("UPDATE maktab_students SET is_deleted = 1 WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    echo "<script>window.location='maktab_students.php';</script>";
}

// 4. محفوظ کرنا (Save)
if (isset($_POST['save_student'])) {
    try {
        $name = $_POST['name'];
        $fname = $_POST['father_name'];
        $adm_no = $_POST['admission_number'];
        $cls = $_POST['class'];
        $contact = $_POST['contact_number'];
        $addr = $_POST['address'];
        $residence_status = $_POST['residence_status'] ?? '';
        $quran_type = $_POST['quran_type'] ?? '16 Line';
        $pages_per_parah = $_POST['pages_per_parah'] ?? 18;
        $hifz_direction = $_POST['hifz_direction'] ?? '1_to_30';

        // Photo Handling
        $photo_path = $_POST['old_photo'] ?? '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/maktab_students/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_name = "std_" . time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $photo_name)) {
                $photo_path = $upload_dir . $photo_name;
            }
        }

        if(empty($contact)) {
            throw new Exception("رابطہ نمبر لکھنا لازمی ہے!");
        }

        $allowed_maktab_classes_check = get_allowed_maktab_classes($pdo);
        $allowed_class_names_check = array_column($allowed_maktab_classes_check, 'name');
        if (($_SESSION['role'] ?? '') !== 'admin' && !in_array($cls, $allowed_class_names_check)) {
            throw new Exception("آپ کو اس کلاس میں اندراج یا ترمیم کی اجازت نہیں ہے!");
        }
        
        // اپڈیٹ
        if(!empty($_POST['edit_id'])) {
            $sql = "UPDATE maktab_students SET name=?, father_name=?, admission_number=?, class=?, contact_number=?, address=?, admission_date=?, graduation_date=?, duration=?, photo=?, quran_type=?, pages_per_parah=?, hifz_direction=?, residence_status=? WHERE id=?";
            $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : null;
            $grad_date = !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null;
            $duration = !empty($_POST['duration']) ? $_POST['duration'] : null;
            $pdo->prepare($sql)->execute([$name, $fname, $adm_no, $cls, $contact, $addr, $adm_date, $grad_date, $duration, $photo_path, $quran_type, $pages_per_parah, $hifz_direction, $residence_status, $_POST['edit_id']]);
            $msg = "<div class='alert alert-success fw-bold'>✅ ریکارڈ اپڈیٹ ہو گیا!</div>";
        } 
        // نیا داخلہ
        else {
            // Check for duplicate admission number
            $check = $pdo->prepare("SELECT id FROM maktab_students WHERE admission_number = ?");
            $check->execute([$adm_no]);
            if($check->fetch()) {
                throw new Exception("یہ داخلہ نمبر پہلے ہی موجود ہے!");
            }

            // Extract serial and hijri year from adm_no (Format: 1447-01)
            $parts = explode('-', $adm_no);
            $h_year = (int)$parts[0];
            $s_no = isset($parts[1]) ? (int)$parts[1] : 0;

            $adm_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : date('Y-m-d');
            $sql = "INSERT INTO maktab_students (name, father_name, admission_number, serial_no, hijri_year, class, contact_number, address, status, admission_date, photo, quran_type, pages_per_parah, hifz_direction, residence_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $fname, $adm_no, $s_no, $h_year, $cls, $contact, $addr, $adm_date, $photo_path, $quran_type, $pages_per_parah, $hifz_direction, $residence_status]);
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا طالب علم ایڈ ہو گیا!</div>";
        }
        echo "<script>window.location='maktab_students.php';</script>";
        
    } catch(Exception $e) { 
        $msg = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>"; 
    }
}

// --- ڈیٹا لائیں (دو الگ الگ لسٹیں) ---
// رجسٹریشن نمبر لاجک
$current_hijri_year = $pdo->query("SELECT current_hijri_year FROM settings LIMIT 1")->fetchColumn() ?: '1447';
$last_serial = $pdo->query("SELECT MAX(serial_no) FROM (SELECT serial_no FROM maktab_students UNION SELECT serial_no FROM admission_applications) as combined_serials")->fetchColumn() ?: 0;
$next_serial = $last_serial + 1;
$suggested_adm_no = $current_hijri_year . "-" . sprintf("%02d", $next_serial);

// 1. موجودہ طلباء (Active)
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$allowed_maktab_classes = get_allowed_maktab_classes($pdo);
$allowed_class_names = array_column($allowed_maktab_classes, 'name');

$class_condition = "1=1";
if (!$is_admin) {
    if (empty($allowed_class_names)) {
        $class_condition = "1=0"; // No access
    } else {
        $inMaktab Classes = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names));
        $class_condition = "class IN ($inMaktab Classes)";
    }
}

if (!empty($_GET['class_filter'])) {
    $stmt = $pdo->prepare("SELECT * FROM maktab_students WHERE status = 'Active' AND is_deleted = 0 AND class = ? AND ($class_condition) ORDER BY admission_number DESC");
    $stmt->execute([$_GET['class_filter']]);
    $active_maktab_students = $stmt->fetchAll();
} else {
    $active_maktab_students = $pdo->query("SELECT * FROM maktab_students WHERE status = 'Active' AND is_deleted = 0 AND ($class_condition) ORDER BY admission_number DESC")->fetchAll();
}

// Group active maktab_students by class
$grouped_maktab_students = [];
foreach ($active_maktab_students as $s) {
    $c = empty($s['class']) ? 'دیگر' : $s['class'];
    if (!isset($grouped_maktab_students[$c])) $grouped_maktab_students[$c] = [];
    $grouped_maktab_students[$c][] = $s;
}

// Predefined order
$predefined_order = ['حفظ', 'گردان', 'تجوید', 'حسن صوت', 'اولیٰ', 'اولیٰ دہم', 'اولیٰ نہم', 'مڈل'];
$ordered_maktab_classes = [];

foreach ($predefined_order as $pc) {
    if (isset($grouped_maktab_students[$pc])) {
        $ordered_maktab_classes[] = $pc;
    }
}
foreach (array_keys($grouped_maktab_students) as $c) {
    if (!in_array($c, $predefined_order)) {
        $ordered_maktab_classes[] = $c;
    }
}

// 2. فارغ التحصیل طلباء (Alumni)
$alumni_maktab_students = $pdo->query("SELECT * FROM maktab_students WHERE status = 'Alumni' AND is_deleted = 0 AND ($class_condition) ORDER BY graduation_date DESC")->fetchAll();

// --- ایڈٹ موڈ ---
$edit_data = null;
$show_form = false; 
if(isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM maktab_students WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
    $show_form = true;
}
?>

<div class="container-fluid">
    
    <div class="sticky-top pt-2" style="z-index: 100;">
        <div class="card mb-3 bg-dark text-white shadow-lg border-0" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="card-body text-center py-2">
                <div class="d-flex justify-content-center align-items-center gap-3">
                    <button type="button" id="mic_btn" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow" onclick="toggleVoice()">
                        <i class="fas fa-microphone me-2"></i> <span>بولیں (Start)</span>
                    </button>
                    <div id="status_text" class="text-white small text-start border-start ps-3" style="min-width: 250px;">
                        <span class="opacity-75">مائیک بند ہے۔</span>
                    </div>
                </div>
                <div class="mt-2 small"><b>طریقہ:</b> "نام احمد، ولدیت علی، رول نمبر 50، کلاس حفظ، رابطہ 03001234567، پتہ لاہور... اوکے"</div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="text-primary fw-bold m-0"><i class="fas fa-users"></i> طلباء کا ریکارڈ</h3>
        <button class="btn btn-success fw-bold shadow" onclick="toggleForm()">
            <i class="fas fa-user-plus me-2"></i> نیا طالب علم شامل کریں
        </button>
    </div>

    <?php if(isset($msg)) echo $msg; ?>

    <div id="student_form_container" class="card shadow-sm mb-4 border-top border-5 border-primary" style="display: <?php echo $show_form ? 'block' : 'none'; ?>;">
        <div class="card-header bg-light">
            <h5 class="fw-bold m-0 text-primary"><?php echo $edit_data ? 'ریکارڈ اپڈیٹ کریں' : 'نیا اندراج'; ?></h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']??''; ?>">
                <input type="hidden" name="old_photo" value="<?php echo $edit_data['photo']??''; ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold">نام طالب علم <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="s_name" class="form-control" required value="<?php echo $edit_data['name']??''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">ولدیت <span class="text-danger">*</span></label>
                        <input type="text" name="father_name" id="s_fname" class="form-control" required value="<?php echo $edit_data['father_name']??''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">داخلہ نمبر (Registration No) <span class="text-danger">*</span></label>
                        <input type="text" name="admission_number" id="s_adm" class="form-control" required value="<?php echo $edit_data['admission_number'] ?? $suggested_adm_no; ?>" placeholder="مثلاً <?php echo $suggested_adm_no; ?>">
                        <small class="text-muted">آخری نمبر: <b><?php echo $current_hijri_year . "-" . sprintf("%02d", $last_serial); ?></b></small>
                    </div>
                    <div class="col-md-3">
                        <label class="fw-bold">کلاس / درجہ</label>
                        <select name="class" id="s_class" class="form-control">
                            <option value="">کلاس منتخب کریں</option>
                            <?php 
                            foreach($allowed_maktab_classes as $cl) {
                                if ($cl['status'] !== 'Active') continue;
                                $sel = (isset($edit_data['class']) && $edit_data['class'] == $cl['name']) ? 'selected' : '';
                                echo "<option value='{$cl['name']}' $sel>{$cl['name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="fw-bold">رہائشی حیثیت (Residential Status)</label>
                        <select name="residence_status" class="form-control">
                            <option value="">منتخب کریں</option>
                            <option value="residential_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'residential_aided') ? 'selected' : ''; ?>>اقامتی، امدادی</option>
                            <option value="non_residential_non_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'non_residential_non_aided') ? 'selected' : ''; ?>>غیر اقامتی، غیر امدادی</option>
                            <option value="residential_non_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'residential_non_aided') ? 'selected' : ''; ?>>اقامتی، غیر امدادی</option>
                            <option value="non_residential_aided" <?php echo (isset($edit_data['residence_status']) && $edit_data['residence_status'] == 'non_residential_aided') ? 'selected' : ''; ?>>غیر اقامتی، امدادی</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="fw-bold">رابطہ نمبر <span class="text-danger">*</span></label>
                        <input type="text" name="contact_number" id="s_contact" class="form-control" required placeholder="0300xxxxxxx" value="<?php echo $edit_data['contact_number']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold">تاریخ داخلہ</label>
                        <input type="date" name="admission_date" class="form-control" value="<?php echo $edit_data['admission_date']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold">تاریخ فراغت (Alumni Only)</label>
                        <input type="date" name="graduation_date" class="form-control" value="<?php echo $edit_data['graduation_date']??''; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="fw-bold">تعلیمی دورانیہ</label>
                        <input type="text" name="duration" class="form-control" placeholder="مثلاً 3 سال" value="<?php echo $edit_data['duration']??''; ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="fw-bold">مکمل پتہ</label>
                        <input type="text" name="address" id="s_addr" class="form-control" value="<?php echo $edit_data['address']??''; ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="fw-bold">تعلیمی قرآن (Hifz/Nazira)</label>
                        <select name="quran_type" class="form-control" onchange="document.getElementById('pages_per_parah').value = (this.value == '16 Line' ? 18 : (this.value == '15 Line' ? 20 : (this.value == '13 Line' ? 22 : (this.value == '18 Line' ? 16 : 20))))">
                            <option value="16 Line" <?php echo (isset($edit_data['quran_type']) && $edit_data['quran_type']=='16 Line') ? 'selected' : ''; ?>>16 لائن</option>
                            <option value="15 Line" <?php echo (isset($edit_data['quran_type']) && $edit_data['quran_type']=='15 Line') ? 'selected' : ''; ?>>15 لائن</option>
                            <option value="13 Line" <?php echo (isset($edit_data['quran_type']) && $edit_data['quran_type']=='13 Line') ? 'selected' : ''; ?>>13 لائن</option>
                            <option value="18 Line" <?php echo (isset($edit_data['quran_type']) && $edit_data['quran_type']=='18 Line') ? 'selected' : ''; ?>>18 لائن</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="fw-bold">صفحات فی پارہ</label>
                        <input type="number" name="pages_per_parah" id="pages_per_parah" class="form-control" value="<?php echo $edit_data['pages_per_parah'] ?? 18; ?>" min="1">
                    </div>

                    <div class="col-md-3">
                        <label class="fw-bold">حفظ کی سمت (Direction)</label>
                        <select name="hifz_direction" class="form-control">
                            <option value="1_to_30" <?php echo (isset($edit_data['hifz_direction']) && $edit_data['hifz_direction']=='1_to_30') ? 'selected' : ''; ?>>شروع سے آخر (1 سے 30)</option>
                            <option value="30_to_1" <?php echo (isset($edit_data['hifz_direction']) && $edit_data['hifz_direction']=='30_to_1') ? 'selected' : ''; ?>>آخر سے شروع (30 سے 1)</option>
                            <option value="30_then_1" <?php echo (isset($edit_data['hifz_direction']) && $edit_data['hifz_direction']=='30_then_1') ? 'selected' : ''; ?>>پہلے 30، پھر 1 سے 29</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="fw-bold">تصویر (Photo)</label>
                        <input type="file" name="photo" class="form-control">
                        <?php if(!empty($edit_data['photo'])): ?>
                            <img src="<?php echo $edit_data['photo']; ?>" class="mt-2 rounded" width="50">
                        <?php endif; ?>
                    </div>

                    <div class="col-12 text-end">
                        <button type="button" class="btn btn-secondary me-2" onclick="toggleForm()">کینسل</button>
                        <button type="submit" name="save_student" id="save_btn" class="btn btn-primary fw-bold px-4"><?php echo t('btn_save_ur'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php 
    $residence_tabs = [
        'all' => 'تمام',
        'residential_aided' => 'اقامتی، امدادی',
        'non_residential_non_aided' => 'غیر اقامتی، غیر امدادی',
        'residential_non_aided' => 'اقامتی، غیر امدادی',
        'non_residential_aided' => 'غیر اقامتی، امدادی'
    ];
    ?>
    <ul class="nav nav-tabs mb-4" id="residenceTabs" role="tablist">
        <?php $tab_first = true; foreach($residence_tabs as $tab_key => $tab_label): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold <?php echo $tab_first ? 'active' : ''; ?>" id="<?php echo $tab_key; ?>-tab" data-bs-toggle="tab" data-bs-target="#<?php echo $tab_key; ?>" type="button" role="tab" aria-controls="<?php echo $tab_key; ?>" aria-selected="<?php echo $tab_first ? 'true' : 'false'; ?>"><?php echo $tab_label; ?></button>
            </li>
        <?php $tab_first = false; endforeach; ?>
    </ul>
    
    <div class="tab-content" id="residenceTabsContent">
        <?php $tab_first = true; foreach($residence_tabs as $tab_key => $tab_label): ?>
            <div class="tab-pane fade <?php echo $tab_first ? 'show active' : ''; ?>" id="<?php echo $tab_key; ?>" role="tabpanel" aria-labelledby="<?php echo $tab_key; ?>-tab">
                <?php if(empty($ordered_maktab_classes)): ?>
                    <div class="alert alert-info text-center fw-bold">کوئی موجودہ طالب علم نہیں</div>
                <?php else: ?>
                    <?php foreach($ordered_maktab_classes as $cName): ?>
                        <?php 
                            // Filter students for this tab
                            $tab_students = array_filter($grouped_maktab_students[$cName], function($s) use ($tab_key) {
                                $s_status = $s['residence_status'] ?? '';
                                if ($tab_key === 'all') return true;
                                return $s_status === $tab_key;
                            });
                            if(empty($tab_students)) continue; // Skip if no students for this class in this tab
                        ?>
                        <div class="card shadow-sm border-0 mb-4 border-top border-3 border-primary">
                            <div class="card-header bg-light">
                                <h5 class="m-0 fw-bold text-primary"><i class="fas fa-users me-2"></i> کلاس: <?php echo $cName; ?></h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle text-center m-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>نمبرشمار</th>
                                                <th>رول نمبر</th>
                                                <th><?php echo t('photo'); ?></th>
                                                <th>نام طالب علم</th>
                                                <th><?php echo t('father_name'); ?></th>
                                                <th><?php echo t('contact'); ?></th>
                                                <th style="width: 200px;"><?php echo t('action_col'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $serial_num = 1; foreach($tab_students as $s): ?>
                                            <tr>
                                                <td class="fw-bold text-muted"><?php echo $serial_num++; ?></td>
                                                <td class="fw-bold"><?php echo $s['admission_number']; ?></td>
                                                <td>
                                                    <?php if(!empty($s['photo'])): ?>
                                                        <img src="<?php echo $s['photo']; ?>" class="rounded-circle" width="35" height="35" style="object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="bg-secondary text-white rounded-circle d-inline-block text-center" style="width:35px; height:35px; line-height:35px;"><i class="fas fa-user"></i></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-start ps-3 fw-bold"><?php echo e($s['name']); ?></td>
                                                <td><?php echo e($s['father_name']); ?></td>
                                                <td><?php echo e($s['contact_number']); ?></td>
                                                <td>
                                                    <div class="btn-group shadow-sm">
                                                        <a href="print_form.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-info text-white" title="فارم پرنٹ کریں" target="_blank">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                        <a href="print_id_card.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-success text-white" title="آئی ڈی کارڈ" target="_blank">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>
                                                        <a href="maktab_students.php?edit=<?php echo $s['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo t('btn_edit'); ?>"><i class="fas fa-edit"></i></a>
                                                        
                                                        <button type="button" class="btn btn-sm btn-dark text-warning" title="فارغ التحصیل کریں" onclick="openGraduateModal('<?php echo $s['id']; ?>', '<?php echo addslashes($s['name']); ?>', '<?php echo $s['admission_date']; ?>')">
                                                            <i class="fas fa-user-graduate"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn btn-sm btn-info text-white" title="پروموٹ کریں" onclick="openPromoteModal('<?php echo $s['id']; ?>', '<?php echo addslashes($s['name']); ?>')">
                                                            <i class="fas fa-level-up-alt"></i>
                                                        </button>
                                                        
                                                        <a href="maktab_students.php?delete=<?php echo $s['id']; ?>" class="btn btn-sm btn-danger" title="ڈیلیٹ" onclick="return confirm('ریکارڈ ہمیشہ کے لیے ختم ہو جائے گا!')"><i class="fas fa-trash"></i></a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php $tab_first = false; endforeach; ?>
    </div>

    <?php if(count($alumni_maktab_students) > 0): ?>
    <div class="card shadow-sm border-0 border-top border-5 border-secondary">
        <div class="card-header bg-secondary text-white">
            <h5 class="m-0 fw-bold"><i class="fas fa-graduation-cap me-2"></i> فارغ التحصیل طلباء (Alumni / Passed Out)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center m-0 bg-light">
                    <thead class="table-secondary">
                        <tr>
                            <th>نمبرشمار</th>
                            <th>رول نمبر</th>
                            <th>نام طالب علم</th>
                            <th>تاریخ داخلہ</th>
                            <th>تاریخ فراغت</th>
                            <th>دورانیہ</th>
                            <th><?php echo t('action_col'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $serial_num_alumni = 1; foreach($alumni_maktab_students as $s): ?>
                        <tr class="text-muted">
                            <td class="fw-bold"><?php echo $serial_num_alumni++; ?></td>
                            <td><?php echo $s['admission_number']; ?></td>
                            <td class="text-start ps-3 fw-bold"><?php echo $s['name']; ?></td>
                            <td><?php echo $s['admission_date']; ?></td>
                            <td><?php echo $s['graduation_date']; ?></td>
                            <td><?php echo $s['duration']; ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="maktab_students.php?restore=<?php echo $s['id']; ?>" class="btn btn-sm btn-success" title="دوبارہ داخل (Restore)" onclick="return confirm('کیا اسے دوبارہ موجودہ لسٹ میں شامل کرنا ہے؟')">
                                        <i class="fas fa-undo"></i>
                                    </a>
                                    <a href="maktab_students.php?delete=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-danger" title="ہمیشہ کے لیے ڈیلیٹ" onclick="return confirm('ڈیلیٹ؟')"><i class="fas fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function toggleForm() {
    var form = document.getElementById('student_form_container');
    if (form.style.display === 'none') {
        form.style.display = 'block';
        document.getElementById('s_name').focus();
    } else {
        form.style.display = 'none';
    }
}

// Voice Logic
var recognition; var isListening = false;
function toggleVoice() { if(isListening) stopRecognition(); else startRecognition(); }

function startRecognition() {
    if (!('webkitSpeechRecognition' in window)) { alert("Use Google Chrome"); return; }
    
    document.getElementById('student_form_container').style.display = 'block'; // فارم کھولیں

    recognition = new webkitSpeechRecognition();
    recognition.lang = 'ur-PK'; recognition.continuous = true; recognition.interimResults = true;
    
    var btn = document.getElementById('mic_btn');
    btn.innerHTML = '<i class="fas fa-stop"></i> رک جائیں'; 
    btn.className = 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow pulse-btn';
    
    isListening = true;
    recognition.onresult = function(event) {
        var txt = ""; for (var i = event.resultIndex; i < event.results.length; ++i) txt += event.results[i][0].transcript;
        document.getElementById('status_text').innerHTML = "<b>سنا گیا:</b> " + txt;
        processStudentData(txt);
    };
    recognition.onerror = function() { stopRecognition(); };
    recognition.start();
}

function stopRecognition() { isListening = false; if(recognition) recognition.stop(); 
    var btn = document.getElementById('mic_btn'); btn.innerHTML = 'بولیں (Start)'; btn.className = 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow';
    document.getElementById('status_text').innerHTML = "مائیک بند ہے۔";
}

function processStudentData(text) {
    if (text.match(/(?:سیو|محفوظ|اوکے|ڈن)/i)) { stopRecognition(); document.getElementById('save_btn').click(); return; }

    var name = text.match(/(?:نام|name)\s*([ا-ی\w\s]+?)(?:ولدیت|باپ|رول|$)/);
    if(name) { document.getElementById('s_name').value = name[1].trim(); highlight('s_name'); }

    var fname = text.match(/(?:ولدیت|سن آف|باپ|والد)\s*([ا-ی\w\s]+?)(?:رول|کلاس|پتہ|$)/);
    if(fname) { document.getElementById('s_fname').value = fname[1].trim(); highlight('s_fname'); }

    var roll = text.match(/(?:رول نمبر|آئی ڈی|نمبر)\s*(\d+|[ا-ی]+)/);
    if(roll && urduToNum(roll[1])) { document.getElementById('s_adm').value = urduToNum(roll[1]); highlight('s_adm'); }

    var cls = text.match(/(?:کلاس|درجہ)\s*([ا-ی\w\s]+?)(?:پتہ|رابطہ|$)/);
    if(cls) { document.getElementById('s_class').value = cls[1].trim(); highlight('s_class'); }

    // Contact Number (Voice)
    var contact = text.match(/(?:رابطہ|موبائل|فون|نمبر)\s*([\d\s]+)/);
    if(contact) {
        var cleanNum = contact[1].replace(/\s/g, '');
        document.getElementById('s_contact').value = cleanNum;
        highlight('s_contact');
    }

    var addr = text.match(/(?:پتہ|ایڈریس|رہائش)\s*([ا-ی\w\s]+)/);
    if(addr) { document.getElementById('s_addr').value = addr[1].trim(); highlight('s_addr'); }
}

function highlight(id) { var el = document.getElementById(id); el.style.border = "2px solid green"; setTimeout(function(){ el.style.border = ""; }, 1000); }
function urduToNum(str) { if(str.match(/^\d+$/)) return parseInt(str); var map = {'ایک':1,'دو':2,'پاںچ':5,'دس':10}; for(var k in map) if(str.includes(k)) return map[k]; return str; } 
</script>

<!-- Graduation Modal -->
<div class="modal fade" id="graduateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content shadow-lg border-0" style="border-radius: 15px;">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">فارغ التحصیل ریکارڈ (Alumni Details)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="student_id" id="grad_std_id">
                <div class="mb-3">
                    <label class="fw-bold mb-1">نام طالب علم:</label>
                    <input type="text" id="grad_name" class="form-control bg-light" readonly>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="fw-bold mb-1">تاریخ داخلہ:*</label>
                        <input type="date" name="admission_date" id="grad_adm_date" class="form-control border-primary" required onchange="calcDuration()">
                    </div>
                    <div class="col-6">
                        <label class="fw-bold mb-1 text-primary">تاریخ فراغت:*</label>
                        <input type="date" name="graduation_date" id="grad_date" class="form-control border-primary" required onchange="calcDuration()">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="fw-bold mb-1">کل تعلیمی دورانیہ (تیار شدہ):</label>
                    <input type="text" name="duration" id="grad_duration" class="form-control fw-bold text-success" readonly placeholder="خودکار کیلکولیٹ ہوگا">
                </div>
                <div class="mb-3">
                    <label class="fw-bold mb-1">فراغت کی وجہ:*</label>
                    <select name="reason" class="form-select border-primary" required>
                        <option value="">منتخب کریں...</option>
                        <option value="تعلیم مکمل ہونے کی وجہ سے">تعلیم مکمل ہونے کی وجہ سے</option>
                        <option value="بغیر تعلیم مکمل کیے (درمیان میں چھوڑا)">بغیر تعلیم مکمل کیے (درمیان میں چھوڑا)</option>
                        <option value="مدرسہ سے خارج کیا گیا (تادیبی کارروائی)">مدرسہ سے خارج کیا گیا (تادیبی کارروائی)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                <button type="submit" name="graduate_student" class="btn btn-dark text-warning fw-bold px-4">فارغ التحصیل کریں</button>
            </div>
        </form>
    </div>
</div>

<!-- Promote Modal -->
<div class="modal fade" id="promoteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 15px; overflow: hidden;">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-level-up-alt me-2"></i> طالب علم کو پروموٹ کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="student_id" id="promote_std_id">
                <div class="mb-3">
                    <label class="fw-bold mb-1">نام طالب علم:</label>
                    <input type="text" id="promote_name" class="form-control bg-light" readonly>
                </div>
                <div class="mb-3">
                    <label class="fw-bold mb-1 text-primary">نئی کلاس منتخب کریں:*</label>
                    <select name="new_class" class="form-select border-primary" required>
                        <option value="">منتخب کریں...</option>
                        <?php 
                        foreach($allowed_maktab_classes as $cl) {
                            if ($cl['status'] !== 'Active') continue;
                            echo "<option value='{$cl['name']}'>{$cl['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                <button type="submit" name="promote_student" class="btn btn-info text-white fw-bold px-4">پروموٹ کریں</button>
            </div>
        </form>
    </div>
</div>

<script>
function openGraduateModal(id, name, admDate) {
    document.getElementById('grad_std_id').value = id;
    document.getElementById('grad_name').value = name;
    document.getElementById('grad_adm_date').value = admDate;
    document.getElementById('grad_date').value = new Date().toISOString().split('T')[0];
    calcDuration();
    new bootstrap.Modal(document.getElementById('graduateModal')).show();
}

function openPromoteModal(id, name) {
    document.getElementById('promote_std_id').value = id;
    document.getElementById('promote_name').value = name;
    new bootstrap.Modal(document.getElementById('promoteModal')).show();
}

function calcDuration() {
    let admDateVal = document.getElementById('grad_adm_date').value;
    let gradDateVal = document.getElementById('grad_date').value;
    
    if(!admDateVal || !gradDateVal) return;

    let d1 = new Date(admDateVal);
    let d2 = new Date(gradDateVal);
    
    if(d2 < d1) {
        document.getElementById('grad_duration').value = "غلط تاریخ!";
        return;
    }

    let diff = d2 - d1;
    let daysTotal = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    let years = Math.floor(daysTotal / 365);
    let remainingDays = daysTotal % 365;
    let months = Math.floor(remainingDays / 30);
    let days = remainingDays % 30;

    let res = "";
    if(years > 0) res += years + " سال ";
    if(months > 0) res += months + " ماہ ";
    if(days > 0) res += days + " دن";
    
    document.getElementById('grad_duration').value = res.trim() || "0 دن";
}
</script>

<style>.pulse-btn { animation: pulse 1s infinite; }</style>

<?php include 'footer.php'; ?>