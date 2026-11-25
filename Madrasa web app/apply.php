<?php
// apply.php - جامع داخلہ فارم ہینڈلر و پبلک پیج (ملٹی ٹیننٹ SaaS + D-04, D-05, B-12)
require_once 'db.php';

$apply_tenant_id = function_exists('get_current_tenant_id') ? (int)get_current_tenant_id() : 1;
if ($apply_tenant_id <= 0) $apply_tenant_id = 1;

// ٹیننٹ کی ترتیبات (ادارے کا نام، لوگو، ہجری سال)
$tenant_settings = function_exists('get_tenant_settings') ? get_tenant_settings($pdo, $apply_tenant_id) : [];
$inst_name = trim((string)($tenant_settings['madrasa_name'] ?? ''));
if ($inst_name === '') {
    $inst_name = function_exists('t') ? t('app_name') : 'مدرسہ مینجمنٹ سسٹم';
}
$hijri_label = trim((string)($tenant_settings['current_hijri_year'] ?? '1447'));
$logo_src = (!empty($tenant_settings['madrasa_logo']) && file_exists($tenant_settings['madrasa_logo']))
    ? $tenant_settings['madrasa_logo']
    : 'app_logo_mcmms.svg';

// POST درخواست کی پراسیسنگ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
               || (!isset($_POST['traditional_submit']) && !isset($_SERVER['HTTP_REFERER']))
               || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false && !isset($_POST['traditional_submit']));

    try {
        // ڈیٹا وصول کریں
        $student_name = trim($_POST['student_name'] ?? '');
        $father_name  = trim($_POST['father_name'] ?? '');
        $dob          = trim($_POST['dob'] ?? '');
        $address      = trim($_POST['address'] ?? '');
        $phone        = trim($_POST['phone'] ?? ''); // Saved as mobile_no
        $email        = trim($_POST['email'] ?? '');
        $perm_addr    = trim($_POST['permanent_address'] ?? '');
        $qual         = trim($_POST['qualification'] ?? '');
        $last_study   = trim($_POST['last_studied'] ?? '');
        $aim          = trim($_POST['aim'] ?? '');
        $prev_madaris = trim($_POST['prev_madaris'] ?? '');
        $leave_reason = trim($_POST['leave_reason'] ?? '');
        $g_name       = trim($_POST['guardian_name'] ?? '');
        $g_cnic       = trim($_POST['guardian_cnic'] ?? '');
        $g_rel        = trim($_POST['guardian_relation'] ?? '');
        $g_addr       = trim($_POST['guardian_address'] ?? '');
        $dept         = trim((string)($_POST['department'] ?? 'حفظ قرآن'));

        if (empty($student_name) || empty($father_name)) {
            throw new Exception("طالب علم کا نام اور والد کا نام درج کرنا لازمی ہے۔");
        }
        if (empty($phone)) {
            throw new Exception("رابطہ نمبر درج کرنا لازمی ہے۔");
        }

        // BUGFIX B-12: request-scoped tenant isolation (GET/POST/Header via get_current_tenant_id)
        if (!empty($_POST['tenant_id']) && (int)$_POST['tenant_id'] > 0) {
            $apply_tenant_id = (int)$_POST['tenant_id'];
        }
        if (function_exists('ensure_column')) {
            try { ensure_column($pdo, 'admission_applications', 'tenant_id', 'INT DEFAULT 1'); } catch (Throwable $e) {}
            try { ensure_column($pdo, 'admission_applications', 'admission_number', "VARCHAR(50) DEFAULT ''"); } catch (Throwable $e) {}
            try { ensure_column($pdo, 'admission_applications', 'status', "VARCHAR(50) DEFAULT 'Pending'"); } catch (Throwable $e) {}
            try { ensure_column($pdo, 'admission_applications', 'rejection_reason', 'TEXT DEFAULT NULL'); } catch (Throwable $e) {}
        }

        // سسٹم ٹائپ تعین (مکتب بمقابلہ مدرسہ)
        $is_masjid = (strpos(strtolower($dept), 'مکتب') !== false || strpos(strtolower($dept), 'maktab') !== false);
        $sys_type = $is_masjid ? 'Masjid' : 'Madrasa';

        // اٹامک و کینونیکل رجسٹریشن نمبر جنریشن (D-04)
        $seq = get_next_admission_number($pdo, $sys_type, $apply_tenant_id, true);
        $adm_no = $seq['admission_number'];
        $next_serial = (int)$seq['serial_no'];
        $current_hijri_year = $seq['hijri_year'];

        // تصویر ہینڈلنگ
        $photo_path = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $dir = 'uploads/students/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed_exts, true)) {
                $photo_name = "app_" . time() . "_" . uniqid() . "." . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $photo_name)) {
                    $photo_path = $dir . $photo_name;
                }
            }
        }

        // 1. داخلہ درخواست محفوظ کریں (admission_applications)
        $sql = "INSERT INTO admission_applications
                (student_name, father_name, dob, address, mobile_no, photo, email, status, application_date,
                 permanent_address, qualification, last_studied, aim, prev_madaris, leave_reason,
                 guardian_name, guardian_cnic, guardian_relation, guardian_address, department, hijri_year, serial_no, admission_number, tenant_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $student_name, $father_name, !empty($dob) ? $dob : null, $address ?: $perm_addr, $phone, $photo_path, $email,
            $perm_addr, $qual, $last_study, $aim, $prev_madaris, $leave_reason,
            $g_name, $g_cnic, $g_rel, $g_addr, $dept, $current_hijri_year, $next_serial, $adm_no, $apply_tenant_id
        ]);
        $app_id = (int)$pdo->lastInsertId();

        // 2. ڈیفیکٹ D-05 حل: پائپ لائن انکوائریز کے ساتھ خودکار ادغام (Single Source of Truth)
        try {
            ensure_phase2_tables($pdo);
            $inqStmt = $pdo->prepare("
                INSERT INTO admission_inquiries (
                    student_name, father_name, phone, class_applied, address, status,
                    admission_number, serial_no, hijri_year,
                    guardian_name, guardian_cnic, guardian_relation, guardian_address,
                    prev_madaris, qualification, last_studied, leave_reason,
                    dob, email, photo, source_application_id, system_type, tenant_id, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $inqStmt->execute([
                $student_name, $father_name, $phone, $dept, $address ?: $perm_addr,
                $adm_no, $next_serial, $current_hijri_year,
                $g_name, $g_cnic, $g_rel, $g_addr,
                $prev_madaris, $qual, $last_study, $leave_reason,
                !empty($dob) ? $dob : null, $email, $photo_path, $app_id, $sys_type, $apply_tenant_id
            ]);
        } catch (Throwable $eInq) {
            error_log("Apply sync to inquiries warning: " . $eInq->getMessage());
        }

        if (function_exists('log_activity')) {
            log_activity($pdo, 'admissions', "آن لائن داخلہ فارم موصول ہوا: $student_name ولد $father_name (داخلہ نمبر: $adm_no, شعبہ: $dept)", $sys_type, $apply_tenant_id);
        }

        $succ_message = "آپ کا آن لائن داخلہ فارم کامیابی کے ساتھ جمع ہو گیا ہے! آپ کا جاری شدہ داخلہ نمبر $adm_no ہے۔";

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "success" => true,
                "message" => $succ_message,
                "admission_number" => $adm_no
            ]);
            exit;
        } else {
            $post_success = true;
            $post_adm_no = $adm_no;
            $post_msg = $succ_message;
        }

    } catch (Exception $e) {
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
            exit;
        } else {
            $post_error = $e->getMessage();
        }
    }
}

// اگر GET وزٹ ہو (یا روایتی POST مکمل ہو)، تو مکمل RTL UI پیج دکھائیں
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>آن لائن داخلہ فارم — <?php echo htmlspecialchars($inst_name); ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Amiri:wght@400;700&family=Playfair+Display:ital,wght@0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --emerald-royal: #0B3B24;
            --emerald-deep: #062315;
            --emerald-light: #165B37;
            --gold-burnished: #C5A059;
            --cream-soft: #FBF9F5;
        }
        body {
            font-family: 'Amiri', serif;
            background-color: var(--cream-soft);
            color: #2D3748;
            margin: 0;
            padding: 0;
        }
        .header-banner {
            background: linear-gradient(135deg, var(--emerald-deep) 0%, var(--emerald-royal) 100%);
            color: white;
            padding: 2.5rem 1rem;
            text-align: center;
            border-bottom: 4px solid var(--gold-burnished);
        }
        .form-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid rgba(197, 160, 89, 0.2);
            padding: 2.5rem;
            margin-top: -30px;
            margin-bottom: 3rem;
        }
        .form-label {
            font-weight: 700;
            color: var(--emerald-royal);
            margin-bottom: 0.4rem;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1.5px solid #E2E8F0;
            padding: 0.65rem 0.9rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--emerald-light);
            box-shadow: 0 0 0 3px rgba(22, 91, 55, 0.15);
        }
        .btn-submit {
            background: linear-gradient(135deg, var(--emerald-royal) 0%, var(--emerald-light) 100%);
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 700;
            border: none;
            padding: 0.8rem 2.5rem;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(11, 59, 36, 0.3);
            transition: all 0.3s ease;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, var(--emerald-light) 0%, var(--emerald-deep) 100%);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(11, 59, 36, 0.4);
        }
        .section-badge {
            background: rgba(197, 160, 89, 0.15);
            color: var(--emerald-deep);
            padding: 0.4rem 1rem;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 1.2rem;
            border: 1px solid var(--gold-burnished);
        }
    </style>
</head>
<body>

    <header class="header-banner">
        <div class="container">
            <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Logo" style="width: 70px; height: 70px; border-radius: 50%; border: 3px solid var(--gold-burnished); background: white; margin-bottom: 15px; object-fit: contain;" onerror="this.src='app_logo_mcmms.svg'; this.onerror=null;">
            <h1 class="fw-bold mb-2"><?php echo htmlspecialchars($inst_name); ?></h1>
            <p class="fs-5 mb-0 opacity-90">آن لائن داخلہ فارم برائے تعلیمی سال <?php echo htmlspecialchars($hijri_label); ?>ھ</p>
        </div>
    </header>

    <main class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="form-card">

                    <?php if (!empty($post_success)): ?>
                        <div class="alert alert-success p-4 rounded-4 shadow-sm mb-4 text-center">
                            <i class="fas fa-check-circle fa-3x text-success mb-3 d-block"></i>
                            <h3 class="fw-bold text-success mb-2">ماشاءاللہ! درخواست کامیابی سے موصول ہو گئی</h3>
                            <p class="fs-5 mb-3"><?php echo htmlspecialchars($post_msg); ?></p>
                            <div class="p-3 bg-white rounded-3 border d-inline-block px-5">
                                <span class="text-muted d-block small">آپ کا داخلہ شناختی نمبر:</span>
                                <strong class="fs-3 text-primary" dir="ltr"><?php echo htmlspecialchars($post_adm_no); ?></strong>
                            </div>
                            <div class="mt-4">
                                <a href="apply.php?tenant_id=<?php echo $apply_tenant_id; ?>" class="btn btn-outline-success me-2">ایک اور درخواست جمع کریں</a>
                                <a href="index.php" class="btn btn-secondary">مرکزی صفحہ پر واپس جائیں</a>
                            </div>
                        </div>
                    <?php else: ?>

                        <?php if (!empty($post_error)): ?>
                            <div class="alert alert-danger p-3 rounded-3 shadow-sm mb-4">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>خرابی: </strong> <?php echo htmlspecialchars($post_error); ?>
                            </div>
                        <?php endif; ?>

                        <form id="publicAdmissionForm" method="POST" action="apply.php?tenant_id=<?php echo $apply_tenant_id; ?>" enctype="multipart/form-data">
                            <input type="hidden" name="traditional_submit" value="1">
                            <input type="hidden" name="tenant_id" value="<?php echo $apply_tenant_id; ?>">

                            <!-- 1. بنیادی معلومات -->
                            <div class="mb-4">
                                <span class="section-badge"><i class="fas fa-user-graduate me-2"></i>حصہ اول: طالب علم کی بنیادی معلومات</span>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">طالب علم کا نام <span class="text-danger">*</span></label>
                                        <input type="text" name="student_name" class="form-control" placeholder="محمد احمد" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">والد کا نام <span class="text-danger">*</span></label>
                                        <input type="text" name="father_name" class="form-control" placeholder="محمد اکرم" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">تاریخ پیدائش</label>
                                        <input type="date" name="dob" class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">موبائل / رابطہ نمبر <span class="text-danger">*</span></label>
                                        <input type="tel" name="phone" class="form-control" placeholder="03001234567" dir="ltr" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">ای میل ایڈریس <small class="text-muted">(اختیاری)</small></label>
                                        <input type="email" name="email" class="form-control" placeholder="example@gmail.com" dir="ltr">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">مطلوبہ شعبہ / کلاس <span class="text-danger">*</span></label>
                                        <select name="department" class="form-select" required>
                                            <option value="حفظ قرآن">شعبہ حفظ قرآن کریم</option>
                                            <option value="ناظرہ قرآن">شعبہ ناظرہ و تجوید</option>
                                            <option value="درس نظامی">شعبہ درس نظامی (عالم کورس)</option>
                                            <option value="قاعدہ">بچوں کا مکتب / قاعدہ</option>
                                            <option value="تجوید و قراءت">تجوید و قراءت</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">پاسپورٹ سائز تصویر</label>
                                        <input type="file" name="photo" id="photo_input" class="form-control" accept="image/*" onchange="previewPhoto(this)">
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- 2. تعلیمی پس منظر -->
                            <div class="mb-4">
                                <span class="section-badge"><i class="fas fa-book-reader me-2"></i>حصہ دوم: تعلیمی پس منظر و مقصد</span>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">موجودہ تعلیمی قابلیت</label>
                                        <input type="text" name="qualification" class="form-control" placeholder="مثلاً پرائمری، مڈل، میٹرک">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">آخری تعلیمی ادارہ</label>
                                        <input type="text" name="last_studied" class="form-control" placeholder="سکول یا مدرسہ کا نام">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">پڑھنے کا بنیادی مقصد (Aim)</label>
                                        <input type="text" name="aim" class="form-control" placeholder="مثلاً حفظ قرآن، عالم بننا">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">سابقہ مدارس (اگر کوئی ہوں)</label>
                                        <input type="text" name="prev_madaris" class="form-control" placeholder="سابقہ مدرسہ کا نام و مقام">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">پچھلا ادارہ چھوڑنے کی وجہ</label>
                                        <input type="text" name="leave_reason" class="form-control" placeholder="وجہ تحریر فرمائیں">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">مستقل رہائشی پتہ <span class="text-danger">*</span></label>
                                        <textarea name="permanent_address" class="form-control" rows="2" placeholder="گاؤں / شہر، ڈاکخانہ، تحصیل و ضلع" required></textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- 3. سرپرست کی تفصیلات -->
                            <div class="mb-4">
                                <span class="section-badge"><i class="fas fa-user-shield me-2"></i>حصہ سوم: سرپرست کی معلومات</span>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">سرپرست کا نام</label>
                                        <input type="text" name="guardian_name" class="form-control" placeholder="سرپرست کا نام">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">سرپرست کا شناختی کارڈ نمبر</label>
                                        <input type="text" name="guardian_cnic" class="form-control" placeholder="xxxxx-xxxxxxx-x" dir="ltr">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">طالب علم سے رشتہ</label>
                                        <input type="text" name="guardian_relation" class="form-control" placeholder="والد، چچا، بھائی، وغیرہ">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">سرپرست کا رابطہ و پتہ</label>
                                        <input type="text" name="guardian_address" class="form-control" placeholder="سرپرست کا مکمل پتہ و رابطہ">
                                    </div>
                                </div>
                            </div>

                            <!-- اقرار نامہ -->
                            <div class="p-3 bg-light rounded-3 border mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="termsCheck" required>
                                    <label class="form-check-label fw-bold text-dark" for="termsCheck">
                                        میں اقرار کرتا ہوں کہ درج بالا تمام معلومات بالکل درست ہیں اور میں <?php echo htmlspecialchars($inst_name); ?> کے جملہ قواعد و ضوابط کی پابندی کرنے کا عہد کرتا ہوں۔
                                    </label>
                                </div>
                            </div>

                            <div class="text-center">
                                <button type="submit" id="submitBtn" class="btn btn-submit">
                                    <i class="fas fa-paper-plane me-2"></i>آن لائن داخلہ فارم جمع کروائیں
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </main>

    <footer class="text-center py-4 text-muted small border-top bg-white">
        کاپی رائٹ © <?php echo date('Y'); ?> <?php echo htmlspecialchars($inst_name); ?>۔ تمام حقوق محفوظ ہیں۔
    </footer>

    <script>
    function previewPhoto(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {};
            reader.readAsDataURL(input.files[0]);
        }
    }

    document.getElementById('publicAdmissionForm')?.addEventListener('submit', function(e) {
        const terms = document.getElementById('termsCheck');
        if (!terms || !terms.checked) {
            e.preventDefault();
            alert('براہ کرم قواعد و ضوابط سے اتفاق کا چیک باکس منتخب کریں۔');
            return;
        }

        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> جمع ہو رہا ہے...';

        e.preventDefault();
        const form = this;
        const formData = new FormData(form);

        fetch('apply.php?tenant_id=<?php echo $apply_tenant_id; ?>', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'ماشاءاللہ!',
                    text: data.message,
                    icon: 'success',
                    confirmButtonText: 'ٹھیک ہے',
                    confirmButtonColor: '#0B3B24'
                }).then(() => {
                    form.reset();
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>آن لائن داخلہ فارم جمع کروائیں';
                    window.location.href = 'apply.php?tenant_id=<?php echo $apply_tenant_id; ?>&done=1';
                });
            } else {
                Swal.fire({
                    title: 'معذرت!',
                    text: data.message || 'فارم جمع کرنے میں خرابی پیش آئی۔',
                    icon: 'error',
                    confirmButtonText: 'دوبارہ کوشش کریں'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>آن لائن داخلہ فارم جمع کروائیں';
            }
        })
        .catch(() => {
            form.submit();
        });
    });
    </script>
</body>
</html>
