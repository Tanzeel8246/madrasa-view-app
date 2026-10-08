<?php 
require_once 'db.php'; 
include 'header.php'; 

// سیکیورٹی چیک
if (!isset($_SESSION['user_id'])) { 
    echo "<script>window.location='login.php';</script>"; 
    exit; 
}
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_erp')) { 
    echo "<script>window.location='dashboard.php';</script>"; 
    exit; 
}

$msg = "";

// --- محفوظ کریں (Save Department) ---
if (isset($_POST['save_dept'])) {
    $name = $_POST['name'];
    $desc = $_POST['description'];
    $status = $_POST['status'];
    
    try {
        if (!empty($_POST['edit_id'])) {
            $stmt = $pdo->prepare("UPDATE departments SET name=?, description=?, status=? WHERE id=?");
            $stmt->execute([$name, $desc, $status, $_POST['edit_id']]);
            $msg = "<div class='alert alert-success fw-bold'>✅ شعبہ کامیابی سے اپڈیٹ ہو گیا!</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)");
            $stmt->execute([$name, $desc, $status]);
            $msg = "<div class='alert alert-success fw-bold'>✅ نیا شعبہ شامل کر لیا گیا!</div>";
        }
    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger'>❌ ڈیٹا بیس ایرر: " . $e->getMessage() . "</div>";
    }
}

// --- ڈیلیٹ (Delete) ---
if (isset($_GET['delete'])) {
    try {
        // Soft delete ya inactive
        $pdo->prepare("UPDATE departments SET status='Inactive' WHERE id=?")->execute([$_GET['delete']]);
        $msg = "<div class='alert alert-info'>شعبہ کو Inactive کر دیا گیا ہے۔</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>اس ریکارڈ کو ڈیلیٹ نہیں کیا جا سکتا۔</div>";
    }
}

// --- ڈیٹا لائیں ---
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();

$edit_data = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
}
?>

<div class="container-fluid py-4">
    <div class="row mb-3 align-items-center">
        <div class="col-md-6"><h3 class="text-primary fw-bold"><i class="fas fa-building me-2"></i> شعبہ جات (Departments)</h3></div>
    </div>
    
    <?php echo $msg; ?>

    <div class="row">
        <!-- Add / Edit Form -->
        <div class="col-md-4">
            <div class="card shadow-sm border-top border-5 border-primary">
                <div class="card-header bg-white">
                    <h5 class="m-0 fw-bold text-primary"><?php echo $edit_data ? 'شعبہ تبدیل کریں' : 'نیا شعبہ شامل کریں'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="edit_id" value="<?php echo $edit_data['id'] ?? ''; ?>">
                        <div class="mb-3">
                            <label class="fw-bold">نام شعبہ <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required value="<?php echo $edit_data['name'] ?? ''; ?>" placeholder="مثلاً: حفظ، ناظرہ، ہاسٹل">
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">تفصیل (Description)</label>
                            <textarea name="description" class="form-control" rows="3"><?php echo $edit_data['description'] ?? ''; ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">اسٹیٹس</label>
                            <select name="status" class="form-select">
                                <option value="Active" <?php echo ($edit_data['status']??'')=='Active'?'selected':''; ?>>Active (فعال)</option>
                                <option value="Inactive" <?php echo ($edit_data['status']??'')=='Inactive'?'selected':''; ?>>Inactive (غیر فعال)</option>
                            </select>
                        </div>
                        <div class="text-end">
                            <?php if($edit_data): ?>
                                <a href="manage_departments.php" class="btn btn-secondary me-2">کینسل</a>
                            <?php endif; ?>
                            <button type="submit" name="save_dept" class="btn btn-primary fw-bold px-4">
                                <i class="fas fa-save me-2"></i> محفوظ کریں
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- List -->
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle text-center m-0">
                            <thead class="table-dark">
                                <tr>
                                    <th style="width: 10%">#</th>
                                    <th style="width: 30%">نام شعبہ</th>
                                    <th style="width: 30%">تفصیل</th>
                                    <th style="width: 15%">اسٹیٹس</th>
                                    <th style="width: 15%">ایکشن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($departments) > 0): ?>
                                    <?php $i = 1; foreach ($departments as $d): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td class="fw-bold text-primary"><?php echo $d['name']; ?></td>
                                        <td><?php echo $d['description']; ?></td>
                                        <td>
                                            <?php if ($d['status'] == 'Active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="manage_departments.php?edit=<?php echo $d['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                            <a href="manage_departments.php?delete=<?php echo $d['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('کیا آپ واقعی اس شعبے کو Inactive کرنا چاہتے ہیں؟')"><i class="fas fa-trash-alt"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="py-4 text-muted">کوئی شعبہ موجود نہیں ہے۔</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
