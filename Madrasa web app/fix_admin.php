<?php
/**
 * ایڈمن ریسکیو اور ڈائیگنوسٹک اسکرپٹ (Admin Rescue & Diagnostic Tool)
 * استعمال کے بعد اس فائل کو سرور سے لازمی ڈیلیٹ کر دیں۔
 */
require_once __DIR__ . '/db.php';

$message = '';
$error = '';

// ڈیٹابیس کی معلومات حاصل کریں
$db_name = '';
$table_exists = false;
$admins_list = [];

try {
    $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
    
    $check_table = $pdo->query("SHOW TABLES LIKE 'admins'");
    $table_exists = ($check_table && $check_table->rowCount() > 0);

    if ($table_exists) {
        $admins_list = $pdo->query("SELECT id, name, username, role, system_type FROM admins")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $error = "ڈیٹابیس ایرر: " . $e->getMessage();
}

// ایڈمن بنانے یا پاسورڈ ری سیٹ کرنے کا ایکشن
if (isset($_POST['create_or_reset'])) {
    $new_user = trim($_POST['username'] ?? 'admin');
    $new_pass = trim($_POST['password'] ?? 'admin123');
    $new_name = trim($_POST['name'] ?? 'Super Admin');

    if (empty($new_user) || empty($new_pass)) {
        $error = "برائے مہربانی یوزر نیم اور پاسورڈ درج کریں۔";
    } else {
        try {
            if (!$table_exists) {
                // ٹیبل بنائیں اگر موجود نہ ہو
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `admins` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `name` varchar(100) NOT NULL,
                      `username` varchar(50) NOT NULL,
                      `password` varchar(255) NOT NULL,
                      `role` varchar(50) DEFAULT 'admin',
                      `permissions` text DEFAULT NULL,
                      `allowed_classes` text DEFAULT NULL,
                      `system_type` varchar(50) DEFAULT 'Madrasa',
                      `phone` varchar(50) DEFAULT NULL,
                      `fcm_token` text DEFAULT NULL,
                      `created_at` timestamp NULL DEFAULT current_timestamp(),
                      `is_deleted` tinyint(1) DEFAULT 0,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `username` (`username`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $table_exists = true;
            }

            $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
            
            // چیک کریں کہ کیا یہ یوزر پہلے سے ہے؟
            $chk = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
            $chk->execute([$new_user]);
            $existing_id = $chk->fetchColumn();

            if ($existing_id) {
                // پاسورڈ ری سیٹ کریں
                $upd = $pdo->prepare("UPDATE admins SET password = ?, role = 'admin', name = ?, system_type = 'Madrasa' WHERE id = ?");
                $upd->execute([$hashed, $new_name, $existing_id]);
                $message = "یوزر <strong>{$new_user}</strong> کا پاسورڈ کامیابی سے تبدیل کر دیا گیا ہے!<br>نیا پاسورڈ: <strong>{$new_pass}</strong>";
            } else {
                // نیا یوزر داخل کریں
                $ins = $pdo->prepare("INSERT INTO admins (name, username, password, role, permissions, system_type) VALUES (?, ?, ?, 'admin', '[\"all\"]', 'Madrasa')");
                $ins->execute([$new_name, $new_user, $hashed]);
                $message = "نیا ایڈمن یوزر <strong>{$new_user}</strong> کامیابی سے بن گیا ہے!<br>پاسورڈ: <strong>{$new_pass}</strong>";
            }

            // لسٹ دوبارہ ریفریش کریں
            $admins_list = $pdo->query("SELECT id, name, username, role, system_type FROM admins")->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            $error = "عمل ناکام: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ایڈمن ریسکیو و پاسورڈ فکس ٹول</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f4f6f9; }
        .card { border-radius: 12px; }
    </style>
</head>
<body class="p-3 p-md-5">
<div class="container" style="max-width: 750px;">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white py-3">
            <h4 class="mb-0 fw-bold">🛠️ ایڈمن لاگ ان تشخیصی و ری سیٹ ٹول</h4>
            <small>یہ ٹول نئے سرور پر ڈیٹابیس اور ایڈمن یوزرز کی جانچ کے لیے بنایا گیا ہے</small>
        </div>
        <div class="card-body p-4">

            <?php if($message): ?>
                <div class="alert alert-success fw-bold shadow-sm"><?= $message ?></div>
            <?php endif; ?>

            <?php if($error): ?>
                <div class="alert alert-danger fw-bold shadow-sm"><?= $error ?></div>
            <?php endif; ?>

            <!-- سسٹم اور ڈیٹابیس کا اسٹیٹس -->
            <div class="card bg-light border p-3 mb-4">
                <h6 class="fw-bold text-primary mb-2">📊 موجودہ ڈیٹابیس کنکشن معلومات:</h6>
                <ul class="list-unstyled mb-0">
                    <li><strong>کنیکٹڈ ڈیٹابیس کا نام:</strong> <span class="badge bg-primary fs-6"><?= htmlspecialchars($db_name ?: 'نامعلوم') ?></span></li>
                    <li><strong>ایڈمن ٹیبل کی موجودگی:</strong> 
                        <?php if($table_exists): ?>
                            <span class="badge bg-success">موجود ہے (Table Exists)</span>
                        <?php else: ?>
                            <span class="badge bg-danger">موجود نہیں ہے (Table Missing)</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <!-- موجودہ ایڈمنز کی لسٹ -->
            <div class="mb-4">
                <h6 class="fw-bold text-dark">👥 اس ڈیٹابیس میں موجود ایڈمنز (Admins in Database):</h6>
                <?php if(empty($admins_list)): ?>
                    <div class="alert alert-warning py-2 mb-0">
                        ⚠️ اس ڈیٹابیس میں <strong>کوئی بھی ایڈمن یوزر موجود نہیں ہے!</strong> اسی وجہ سے لاگ ان پر "غلط یوزر نیم یا پاسورڈ" کا ایرر آ رہا تھا۔ نیچے دیے گئے فارم سے فوری ایڈمن بنائیں۔
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>نام</th>
                                    <th>یوزر نیم (Username)</th>
                                    <th>رول</th>
                                    <th>سسٹم ٹائپ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($admins_list as $a): ?>
                                <tr>
                                    <td><?= $a['id'] ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($a['name']) ?></td>
                                    <td><code class="fs-6"><?= htmlspecialchars($a['username']) ?></code></td>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($a['role']) ?></span></td>
                                    <td><?= htmlspecialchars($a['system_type'] ?? 'Madrasa') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <hr>

            <!-- نیا ایڈمن بنانے یا پاسورڈ ری سیٹ کرنے کا فارم -->
            <h6 class="fw-bold text-success mb-3">🔑 نیا ایڈمن بنائیں یا پاسورڈ تبدیل کریں:</h6>
            <form method="POST" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">نام (Display Name):</label>
                    <input type="text" name="name" class="form-control" value="Super Admin" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">یوزر نیم (Username):</label>
                    <input type="text" name="username" class="form-control" value="admin" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">نیا پاسورڈ (Password):</label>
                    <input type="text" name="password" class="form-control" value="admin123" required>
                </div>
                <div class="col-12">
                    <button type="submit" name="create_or_reset" class="btn btn-success fw-bold w-100 py-2">
                        <i class="fas fa-key me-1"></i> ایڈمن بنائیں / پاسورڈ اپڈیٹ کریں
                    </button>
                </div>
            </form>

            <div class="mt-4 p-3 bg-danger bg-opacity-10 border border-danger rounded">
                <span class="text-danger fw-bold">⚠️ اہم سیکیورٹی ہدایت:</span>
                <p class="small text-danger mb-0">کامیابی سے لاگ ان ہونے کے بعد اپنے سرور کے فائل مینیجر سے اس فائل (<code>fix_admin.php</code>) کو لازمی ڈیلیٹ کر دیں تاکہ کوئی دوسرا شخص اس پیج تک نہ پہنچ سکے۔</p>
            </div>

            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-outline-primary fw-bold">👈 لاگ ان پیج پر واپس جائیں</a>
            </div>

        </div>
    </div>

</div>
</body>
</html>
