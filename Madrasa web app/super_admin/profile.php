<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن پروفائل و سیکیورٹی (Super Admin Profile & Security)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'پروفائل و سیکیورٹی ترتیبات';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';
$adminId = (int)($_SESSION['super_admin_id'] ?? 1);

// Fetch current admin profile
$stmt = $pdo->prepare("SELECT * FROM super_admins WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    // If not found, fetch the first super admin
    $admin = $pdo->query("SELECT * FROM super_admins LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        $adminId = (int)$admin['id'];
        $_SESSION['super_admin_id'] = $adminId;
    }
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. بنیادی معلومات اپڈیٹ کرنا (Update Profile Info)
    if ($action === 'update_info') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($username)) {
            $error = "نام اور یوزر نیم درج کرنا لازمی ہے۔";
        } else {
            try {
                // Check if username is taken by another admin
                $chk = $pdo->prepare("SELECT id FROM super_admins WHERE username = ? AND id != ?");
                $chk->execute([$username, $adminId]);
                if ($chk->fetch()) {
                    $error = "یہ یوزر نیم پہلے سے زیر استعمال ہے۔ براہ کرم کوئی دوسرا یوزر نیم منتخب کریں۔";
                } else {
                    $up = $pdo->prepare("UPDATE super_admins SET name = ?, username = ?, email = ?, phone = ? WHERE id = ?");
                    $up->execute([$name, $username, $email, $phone, $adminId]);

                    $_SESSION['super_admin_name'] = $name;
                    $_SESSION['super_admin_user'] = $username;
                    $admin['name'] = $name;
                    $admin['username'] = $username;
                    $admin['email'] = $email;
                    $admin['phone'] = $phone;

                    $msg = "پروفائل کی معلومات کامیابی سے اپڈیٹ ہو گئیں!";
                }
            } catch (Throwable $e) {
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    // 2. پاس ورڈ تبدیل کرنا (Change Password)
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password)) {
            $error = "موجودہ اور نیا پاس ورڈ دونوں درج کرنا لازمی ہے۔";
        } elseif ($new_password !== $confirm_password) {
            $error = "نیا پاس ورڈ اور تصدیقی پاس ورڈ آپس میں مماثلت نہیں رکھتے۔";
        } elseif (strlen($new_password) < 6) {
            $error = "نیا پاس ورڈ کم از کم 6 حروف پر مشتمل ہونا چاہیے۔";
        } else {
            // Verify current password (allow master bypass if current equals default)
            $validCurrent = false;
            if (password_verify($current_password, $admin['password'])) {
                $validCurrent = true;
            } elseif ($current_password === 'superadmin123' || $current_password === 'superadmin@2026') {
                $validCurrent = true;
            }

            if (!$validCurrent) {
                $error = "موجودہ پاس ورڈ درست نہیں ہے۔";
            } else {
                try {
                    $newHash = password_hash($new_password, PASSWORD_DEFAULT);
                    $up = $pdo->prepare("UPDATE super_admins SET password = ? WHERE id = ?");
                    $up->execute([$newHash, $adminId]);
                    $admin['password'] = $newHash;
                    $msg = "پاس ورڈ کامیابی سے تبدیل کر دیا گیا ہے! آئندہ لاگ ان پر نیا پاس ورڈ استعمال کریں۔";
                } catch (Throwable $e) {
                    $error = "خرابی: " . $e->getMessage();
                }
            }
        }
    }
}
?>

<div class="row g-4">
    <?php if (!empty($msg)): ?>
        <div class="col-12">
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="col-12">
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Profile Info Form -->
    <div class="col-12 col-lg-6">
        <div class="card-custom h-100">
            <div class="d-flex align-items-center mb-4 pb-2 border-bottom" style="border-color: var(--sa-border) !important;">
                <span class="p-2 rounded-3 bg-primary bg-opacity-10 text-primary me-3 fs-4">
                    <i class="fas fa-id-card"></i>
                </span>
                <div>
                    <h5 class="fw-bold mb-0" style="color: var(--sa-text-main);">بنیادی معلومات</h5>
                    <small style="color: var(--sa-text-muted);">سوپر ایڈمن نام، یوزر نیم اور رابطہ تبدیل کریں</small>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_info">
                <div class="mb-3">
                    <label class="form-label stat-label">پورا نام *</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($admin['name'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label stat-label">یوزر نیم (لاگ ان کے لیے) *</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background-color: var(--sa-table-header-bg); border-color: var(--sa-input-border); color: var(--sa-text-sub);"><i class="fas fa-at"></i></span>
                        <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label stat-label">ای میل ایڈریس</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" placeholder="admin@domain.com">
                </div>

                <div class="mb-4">
                    <label class="form-label stat-label">موبائل / واٹس ایپ نمبر</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>" placeholder="03001234567">
                </div>

                <button type="submit" class="btn btn-primary px-4 fw-bold rounded-pill shadow-sm">
                    <i class="fas fa-save me-1"></i> معلومات محفوظ کریں
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password Form -->
    <div class="col-12 col-lg-6">
        <div class="card-custom h-100">
            <div class="d-flex align-items-center mb-4 pb-2 border-bottom" style="border-color: var(--sa-border) !important;">
                <span class="p-2 rounded-3 bg-danger bg-opacity-10 text-danger me-3 fs-4">
                    <i class="fas fa-key"></i>
                </span>
                <div>
                    <h5 class="fw-bold mb-0" style="color: var(--sa-text-main);">پاس ورڈ تبدیل کریں</h5>
                    <small style="color: var(--sa-text-muted);">اپنے ماسٹر اکاؤنٹ کا پاس ورڈ محفوظ بنائیں</small>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="mb-3">
                    <label class="form-label stat-label">موجودہ پاس ورڈ *</label>
                    <div class="input-group">
                        <input type="password" name="current_password" id="curPass" class="form-control" required placeholder="موجودہ پاس ورڈ درج کریں">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassView('curPass', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label stat-label">نیا پاس ورڈ *</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="newPass" class="form-control" required placeholder="کم از کم 6 حروف">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassView('newPass', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label stat-label">نئے پاس ورڈ کی تصدیق *</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confPass" class="form-control" required placeholder="نیا پاس ورڈ دوبارہ لکھیں">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassView('confPass', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger px-4 fw-bold rounded-pill shadow-sm">
                    <i class="fas fa-shield-alt me-1"></i> پاس ورڈ اپڈیٹ کریں
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function togglePassView(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
