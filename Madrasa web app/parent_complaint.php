<?php require_once 'db.php'; include 'header.php'; 
$cur_tenant_id = (int)get_current_tenant_id();

// صرف پیرنٹ کے لیے
if ($_SESSION['role'] != 'parent') { echo "<script>window.location='index.php';</script>"; exit; }
$std_id = $_SESSION['user_id'];

// شکایت جمع کرنا
if (isset($_POST['submit_complaint'])) {
    $category = $_POST['category'];
    $desc = $_POST['description'];
    $date = $_POST['date'];

    $stmt = $pdo->prepare("INSERT INTO parent_complaints (student_id, category, description, date, tenant_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$std_id, $category, $desc, $date, $cur_tenant_id]);
    
    echo "<div class='alert alert-warning'>شکایت انتظامیہ کو بھیج دی گئی ہے۔ جزاک اللہ!</div>";
}

// سابقہ ہسٹری
$history = $pdo->prepare("SELECT * FROM parent_complaints WHERE student_id = ? AND tenant_id = ? ORDER BY id DESC");
$history->execute([$std_id, $cur_tenant_id]);
?>

<div class="row">
    <div class="col-md-5">
        <div class="card shadow-sm border-danger border-top border-3">
            <div class="card-header bg-white fw-bold text-danger">
                <i class="fas fa-frown me-2"></i> اصلاحی <?php echo t('register_complaint'); ?>
            </div>
            <div class="card-body">
                <p class="small text-muted">اگر بچے نے گھر میں نماز چھوڑی ہے یا بد اخلاقی کی ہے تو یہاں آگاہ کریں تاکہ اس کی اصلاح کی جا سکے۔</p>
                
                <form method="POST">
                    <div class="mb-3">
                        <label>تاریخ وقوع</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label>شکایت کی نوعیت</label>
                        <select name="category" class="form-select" required>
                            <option value="">-- منتخب کریں --</option>
                            <option value="Namaz">نماز میں کوتاہی / چھوڑنا</option>
                            <option value="Disobedience">والدین کی نافرمانی / بدتمیزی</option>
                            <option value="Behavior">محلہ داروں سے بد اخلاقی</option>
                            <option value="Study">گھر کا سبق یاد نہ کرنا</option>
                            <option value="Mobile">موبائل کا غلط استعمال</option>
                            <option value="Other">دیگر</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label>تفصیل</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="مکمل تفصیل یہاں لکھیں..." required></textarea>
                    </div>
                    
                    <button type="submit" name="submit_complaint" class="btn btn-danger w-100 fw-bold">انتظامیہ کو بھیجیں</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">آپ کی بھیجی گئی شکایات</div>
            <div class="table-responsive">
                <table class="table table-sm text-center align-middle">
                    <thead><tr><th>تاریخ</th><th>نوعیت</th><th>سٹیٹس</th></tr></thead>
                    <tbody>
                        <?php while($row = $history->fetch()): ?>
                        <tr>
                            <td><?php echo date('d-M', strtotime($row['date'])); ?></td>
                            <td>
                                <span class="fw-bold text-danger"><?php echo $row['category']; ?></span><br>
                                <small class="text-muted"><?php echo substr($row['description'], 0, 30); ?>...</small>
                            </td>
                            <td>
                                <?php if($row['status']=='Viewed'): ?>
                                    <span class="badge bg-success">انتظامیہ نے دیکھ لی</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">پینڈنگ</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>