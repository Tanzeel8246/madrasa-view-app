<?php require_once 'db.php'; include 'header.php'; 

if ($_SESSION['role'] != 'parent') { echo "<script>window.location='index.php';</script>"; exit; }
$std_id = $_SESSION['user_id'];

// درخواست جمع کریں
if (isset($_POST['apply_leave'])) {
    $photo = "";
    if (!empty($_FILES['app_photo']['name'])) {
        $target_dir = "uploads/leaves/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $photo = $target_dir . time() . "_" . basename($_FILES["app_photo"]["name"]);
        move_uploaded_file($_FILES["app_photo"]["tmp_name"], $photo);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO leave_applications (student_id, date_from, date_to, reason, application_photo, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$std_id, $_POST['from'], $_POST['to'], $_POST['reason'], $photo]);
        echo "<div class='alert alert-success fw-bold'><i class='fas fa-check-circle me-2'></i> <?php echo t('leave_application'); ?> جمع ہو گئی ہے۔</div>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger fw-bold'>ایرر: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ہسٹری
$leaves = $pdo->prepare("SELECT * FROM leave_applications WHERE student_id = ? ORDER BY id DESC");
$leaves->execute([$std_id]);
?>

<div class="row">
    <div class="col-md-5">
        <div class="card shadow-sm border-0 mb-4" style="border-radius:15px;">
            <div class="card-header bg-info text-white fw-bold py-3"><i class="fas fa-paper-plane me-2"></i> <?php echo t('leave_application'); ?></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">تاریخ (سے)</label>
                            <input type="date" name="from" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">تاریخ (تک)</label>
                            <input type="date" name="to" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">وجہ</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="چھٹی کی وجہ لکھیں" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ہاتھ سے لکھی درخواست (مع دستخط)</label>
                        <input type="file" name="app_photo" class="form-control" accept="image/*" required>
                        <small class="text-danger">* سٹوڈنٹ خود <?php echo t('leave_application'); ?> نہیں دے سکتا، پیرنٹس کی تحریر لازمی ہے۔</small>
                    </div>
                    <button type="submit" name="apply_leave" class="btn btn-info w-100 fw-bold py-2 text-white"><?php echo t('send_application'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm border-0 overflow-hidden" style="border-radius:15px;">
            <div class="card-header bg-secondary text-white fw-bold py-3"><i class="fas fa-history me-2"></i> سابقہ درخواستیں</div>
            <div class="table-responsive">
                <table class="table table-sm text-center align-middle">
                    <thead class="bg-light"><tr><th>تاریخ</th><th>وجہ</th><th>سٹیٹس</th><th>درخواست</th></tr></thead>
                    <tbody>
                        <?php while($row = $leaves->fetch()): ?>
                        <tr>
                            <td><small><?php echo date('d-M', strtotime($row['date_from']))." تا ".date('d-M', strtotime($row['date_to'])); ?></small></td>
                            <td><?php echo $row['reason']; ?></td>
                            <td><span class="badge bg-dark"><?php echo $row['status']; ?></span></td>
                            <td>
                                <?php if($row['application_photo']): ?>
                                    <a href="<?php echo $row['application_photo']; ?>" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-image"></i></a>
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