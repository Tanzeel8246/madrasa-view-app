<?php require_once 'db.php'; include 'header.php'; 

// لاگ ان شدہ یوزر کی ID اور رول
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$msg = "";

if (isset($_POST['change_pass'])) {
    $old_pass = $_POST['old_pass'] ?? '';
    $new_pass = $_POST['new_pass'];
    
    if (empty($old_pass) || empty($new_pass)) {
        $msg = "<div class='alert alert-danger'>" . __('موجودہ اور نیا پاس ورڈ دونوں ضروری ہیں۔', 'Both current and new passwords are required.') . "</div>";
    } else {
        if (in_array($role, ['admin', 'nazim', 'teacher'])) {
            $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
        } elseif ($role == 'parent') {
            $stmt = $pdo->prepare("SELECT password FROM parents WHERE id = ? AND is_active = 1 LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT password FROM students WHERE id = ? AND is_deleted = 0 LIMIT 1");
        }
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !verify_pass($old_pass, $row['password'])) {
            $msg = "<div class='alert alert-danger'>" . __('موجودہ پاس ورڈ درست نہیں۔', 'Current password is incorrect.') . "</div>";
        } else {
            $hashed = hash_pass($new_pass);
            if (in_array($role, ['admin', 'nazim', 'teacher'])) {
                $stmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
            } elseif ($role == 'parent') {
                $stmt = $pdo->prepare("UPDATE parents SET password = ? WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
            }
            $stmt->execute([$hashed, $user_id]);
            $msg = "<div class='alert alert-success'>" . __('✅ پاسورڈ کامیابی سے تبدیل ہو گیا!', '✅ Password changed successfully!') . "</div>";
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow">
            <div class="card-header bg-warning text-dark fw-bold">
                <i class="fas fa-key me-2"></i> <?php echo t('change_password'); ?>
            </div>
            <div class="card-body">
                <?php echo $msg; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo __('یوزر نیم / نام', 'Username / Name'); ?></label>
                        <input type="text" class="form-control" value="<?php echo $_SESSION['name']; ?>" readonly disabled>
                    </div>
                    
                    <div class="mb-3">
                        <label class="fw-bold"><?php echo __('موجودہ پاس ورڈ', 'Current Password'); ?></label>
                        <input type="password" name="old_pass" class="form-control" placeholder="<?php echo __('موجودہ پاس ورڈ لکھیں', 'Enter current password'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold"><?php echo t('new_password'); ?></label>
                        <input type="password" name="new_pass" class="form-control" placeholder="<?php echo __('نیا پاس ورڈ لکھیں', 'Enter new password'); ?>" required>
                    </div>

                    <button type="submit" name="change_pass" class="btn btn-dark w-100 fw-bold"><?php echo __('تبدیل کریں', 'Change Password'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
