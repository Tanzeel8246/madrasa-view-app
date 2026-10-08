<?php require_once 'db.php'; include 'header.php'; 

// صرف ایڈمن کو اجازت ہے
if ($_SESSION['role'] != 'admin') { echo "<script>window.location='index.php';</script>"; exit; }

// نوٹس محفوظ کرنا
if (isset($_POST['add_notice'])) {
    $stmt = $pdo->prepare("INSERT INTO notices (title, message, date) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['title'], $_POST['message'], $_POST['date']]);
    echo "<div class='alert alert-success'>✅ نوٹس بورڈ پر اعلان لگ گیا!</div>";
}

// نوٹس ڈیلیٹ کرنا
if (isset($_GET['del'])) {
    $pdo->prepare("UPDATE notices SET is_deleted = 1 WHERE id=?")->execute([$_GET['del']]);
    echo "<script>window.location='manage_notices.php';</script>";
}

// لسٹ لائیں
$notices = $pdo->query("SELECT * FROM notices WHERE is_deleted = 0 ORDER BY date DESC")->fetchAll();
?>

<div class="row">
    <div class="col-md-4">
        <div class="card shadow border-top border-4 border-danger">
            <div class="card-header bg-white fw-bold"><i class="fas fa-bullhorn me-2 text-danger"></i> نیا اعلان (Notice)</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label>عنوان (Title)</label>
                        <input type="text" name="title" class="form-control" placeholder="مثلاً: چھٹی کا اعلان" required>
                    </div>
                    <div class="mb-3">
                        <label><?php echo t('date_ur'); ?></label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>تفصیل (Message)</label>
                        <textarea name="message" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" name="add_notice" class="btn btn-danger w-100 fw-bold">نوٹس بورڈ پر لگائیں</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-dark text-white fw-bold">موجودہ اعلانات</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th><?php echo t('date_ur'); ?></th><th>عنوان / تفصیل</th><th><?php echo t('action_ur'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach($notices as $n): ?>
                        <tr>
                            <td style="width: 120px;"><?php echo date('d-M', strtotime($n['date'])); ?></td>
                            <td>
                                <strong class="text-danger"><?php echo $n['title']; ?></strong><br>
                                <small class="text-muted"><?php echo $n['message']; ?></small>
                            </td>
                            <td>
                                <a href="manage_notices.php?del=<?php echo $n['id']; ?>" onclick="return confirm('حذف کریں؟')" class="btn btn-sm btn-light text-danger"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>