<?php require_once 'db.php'; include 'header.php'; require_once 'notifications.php';

// ایڈمن یا مجاز ناظم/اسٹاف کو اجازت ہے
if (!isset($_SESSION['user_id']) || (!has_perm('manage_notices') && ($_SESSION['role'] ?? '') !== 'admin')) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();
if (function_exists('ensure_column')) {
    ensure_column($pdo, 'notices', 'system_type', "VARCHAR(50) DEFAULT 'Madrasa'");
    ensure_column($pdo, 'notices', 'tenant_id', "INT DEFAULT 1");
}
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

// نوٹس محفوظ کرنا
if (isset($_POST['add_notice'])) {
    $stmt = $pdo->prepare("INSERT INTO notices (title, message, date, system_type, tenant_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['title'], $_POST['message'], $_POST['date'], $sys, $cur_tenant_id]);
    sendPushNotificationFCM($pdo, $_POST['title'], $_POST['message'], 'new_notice');
    $notice_succ = __('نوٹس بورڈ پر اعلان لگ گیا اور پش نوٹیفکیشن بھیج دیا گیا!', 'Notice posted to notice board and push notification sent!');
    echo "<div class='alert alert-success'>✅ " . $notice_succ . "</div>";
}

// نوٹس ڈیلیٹ کرنا
if (isset($_GET['del'])) {
    $pdo->prepare("UPDATE notices SET is_deleted = 1 WHERE id=? AND tenant_id=?")->execute([$_GET['del'], $cur_tenant_id]);
    echo "<script>window.location='manage_notices.php';</script>";
}

// لسٹ لائیں
$notices_stmt = $pdo->prepare("SELECT * FROM notices WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? AND $sys_cond ORDER BY date DESC");
$notices_stmt->execute([$cur_tenant_id]);
$notices = $notices_stmt->fetchAll();
?>

<div class="row">
    <div class="col-md-4">
        <div class="card shadow border-top border-4 border-danger">
            <div class="card-header bg-white fw-bold"><i class="fas fa-bullhorn me-2 text-danger"></i> <?php echo __('نیا اعلان', 'New Notice / Announcement'); ?></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label><?php echo __('عنوان', 'Title'); ?></label>
                        <input type="text" name="title" class="form-control" placeholder="<?php echo __('مثلاً: چھٹی کا اعلان', 'e.g. Holiday Announcement'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label><?php echo t('date_ur'); ?></label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label><?php echo __('تفصیل', 'Message / Details'); ?></label>
                        <textarea name="message" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" name="add_notice" class="btn btn-danger w-100 fw-bold"><?php echo __('نوٹس بورڈ پر لگائیں', 'Publish on Notice Board'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-dark text-white fw-bold"><?php echo __('موجودہ اعلانات', 'Current Notices & Circulars'); ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th><?php echo __('تاریخ', 'Date'); ?></th><th><?php echo __('عنوان / تفصیل', 'Title / Description'); ?></th><th><?php echo __('ایکشن', 'Action'); ?></th></tr></thead>
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