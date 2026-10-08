<?php
session_start();
require_once 'db.php';
require_once 'send_sms.php';

$error = '';
$success = '';
$step = isset($_SESSION['reset_step']) ? $_SESSION['reset_step'] : 1; // 1: Info, 2: OTP, 3: New Password

// مرحلہ 1: داخلہ نمبر اور موبائل نمبر کی تصدیق اور OTP بھیجنا
if (isset($_POST['send_otp'])) {
    $admission_no = trim($_POST['admission_no']);
    $mobile = trim($_POST['mobile']);

    try {
        $stmt = $pdo->prepare("SELECT id, name FROM students WHERE admission_number = ? AND (contact_number = ? OR mobile_no = ?)");
        $stmt->execute([$admission_no, $mobile, $mobile]);
        $user = $stmt->fetch();

        if ($user) {
            $otp = rand(1000, 9999);
            $_SESSION['reset_otp'] = $otp;
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_step'] = 2;
            $_SESSION['reset_mobile'] = $mobile;
            
            $msg = "مدرسہ اشرف المدارس: پاسورڈ تبدیل کرنے کے لیے آپ کا کوڈ (OTP) یہ ہے: $otp";
            send_sms($mobile, $msg);
            
            $success = "آپ کے رجسٹرڈ موبائل نمبر پر 4 ہندسوں کا کوڈ بھیج دیا گیا ہے۔";
            $step = 2;
        } else {
            $error = "داخلہ نمبر یا موبائل نمبر ریکارڈ میں موجود نہیں ہے!";
        }
    } catch (PDOException $e) { $error = "سسٹم ایرر: " . $e->getMessage(); }
}

// مرحلہ 2: OTP کی تصدیق
if (isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp']);
    if ($entered_otp == $_SESSION['reset_otp']) {
        $_SESSION['reset_step'] = 3;
        $step = 3;
    } else {
        $error = "غلط کوڈ درج کیا گیا ہے! دوبارہ کوشش کریں۔";
    }
}

// مرحلہ 3: نیا پاسورڈ محفوظ کرنا
if (isset($_POST['update_pass'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 6) {
        $error = "پاسورڈ کم از کم 6 ہندسوں کا ہونا چاہیے!";
    } elseif ($new_password !== $confirm_password) {
        $error = "پاسورڈ آپس میں نہیں مل رہے!";
    } else {
        try {
            $hashed_password = hash_pass($new_password);
            $update = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
            $update->execute([$hashed_password, $_SESSION['reset_user_id']]);
            
            $success = "مبارک ہو! پاسورڈ کامیابی سے تبدیل ہو گیا ہے۔";
            // سیشن ختم کریں
            session_unset();
            session_destroy();
            $step = 4; // کامیابی کا پیغام دکھانے کے لیے
        } catch (PDOException $e) { $error = "سسٹم ایرر: " . $e->getMessage(); }
    }
}

// کینسل اور ری اسٹارٹ
if (isset($_POST['restart'])) {
    session_unset();
    header("Location: forgot_password.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پاسورڈ دوبارہ ترتیب دیں - مدرسہ اشرف المدارس</title>
    
    <script>
        // Immediate theme load logic to prevent FOUC
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'default';
            if (savedTheme !== 'default') {
                document.documentElement.className = 'theme-' + savedTheme;
            }
        })();
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    
    <?php include __DIR__ . '/theme_styles.php'; ?>

    <style>
        body {
            font-family: 'Noto Nastaliq Urdu', serif;
            background-color: var(--bg) !important;
            color: var(--text-main) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .reset-box {
            background: var(--card-bg) !important;
            color: var(--text-main) !important;
            padding: 40px;
            border-radius: 10px;
            width: 100%;
            max-width: 450px;
            box-shadow: var(--card-shadow) !important;
            border: 1px solid var(--card-border) !important;
        }
        .form-label {
            font-weight: bold;
            color: var(--primary) !important;
        }
        .otp-input {
            letter-spacing: 15px;
            font-size: 24px;
            text-align: center;
            font-weight: bold;
            background-color: var(--input-bg) !important;
            color: var(--input-text) !important;
            border-color: var(--input-border) !important;
        }
    </style>
</head>
<body>
    <div class="reset-box">
        <h3 class="mb-4 text-center text-success fw-bold">پاسورڈ ویریفیکیشن</h3>
        
        <?php if($error): ?>
            <div class="alert alert-danger py-2"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success && $step != 4): ?>
            <div class="alert alert-success py-2 small"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if($step == 1): ?>
            <!-- سٹیپ 1: معلومات لینا -->
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">داخلہ نمبر</label>
                    <input type="text" name="admission_no" class="form-control" required placeholder="داخلہ نمبر لکھیں">
                </div>
                <div class="mb-3">
                    <label class="form-label">رجسٹرڈ موبائل نمبر</label>
                    <input type="text" name="mobile" class="form-control" required placeholder="03XXXXXXXXX">
                </div>
                <button type="submit" name="send_otp" class="btn btn-success w-100 fw-bold">کوڈ (OTP) بھیجیں</button>
            </form>

        <?php elseif($step == 2): ?>
            <!-- سٹیپ 2: OTP درج کرنا -->
            <form method="POST" class="text-center">
                <p class="small text-muted">ہم نے آپ کے نمبر (<?php echo $_SESSION['reset_mobile']; ?>) پر کوڈ بھیج دیا ہے۔</p>
                <div class="mb-3">
                    <label class="form-label d-block">4 ہندسوں کا کوڈ لکھیں</label>
                    <input type="text" name="otp" class="form-control otp-input" required maxlength="4" autocomplete="off">
                </div>
                <button type="submit" name="verify_otp" class="btn btn-success w-100 fw-bold">تصدیق کریں</button>
                <button type="submit" name="restart" class="btn btn-link text-muted mt-2">دوبارہ شروع کریں</button>
            </form>

        <?php elseif($step == 3): ?>
            <!-- سٹیپ 3: نیا پاسورڈ -->
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">نیا پاسورڈ</label>
                    <input type="password" name="new_password" class="form-control" required placeholder="کم از کم 6 ہندسے">
                </div>
                <div class="mb-3">
                    <label class="form-label">پاسورڈ دوبارہ لکھیں</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" name="update_pass" class="btn btn-success w-100 fw-bold">پاسورڈ تبدیل کریں</button>
            </form>

        <?php elseif($step == 4): ?>
            <!-- کامیابی کا پیغام -->
            <div class="text-center">
                <div class="alert alert-success"><?php echo $success; ?></div>
                <a href="login.php" class="btn btn-success w-100 fw-bold">لاگ ان کریں</a>
            </div>
        <?php endif; ?>

        <?php if($step != 4): ?>
            <div class="text-center mt-3">
                <a href="login.php" class="text-muted text-decoration-none small">← واپس لاگ ان پر جائیں</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
