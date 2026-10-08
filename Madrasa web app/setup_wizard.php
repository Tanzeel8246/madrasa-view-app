<?php
require_once 'db.php';
include 'header.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

$msg = '';
$msgType = 'success';

// فارم جمع کروانے کا عمل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_wizard'])) {
    try {
        $pdo->beginTransaction();

        // 1. مدرسہ کی معلومات اپڈیٹ کریں
        $name = trim($_POST['madrasa_name'] ?? '');
        $tagline = trim($_POST['tagline'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $hijri = trim($_POST['hijri_year'] ?? '');

        $chk = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        if ($chk > 0) {
            $stmt = $pdo->prepare("UPDATE settings SET madrasa_name = ?, tagline = ?, phone = ?, address = ?, hijri_year = ?, is_synced = 0 WHERE id = 1");
            $stmt->execute([$name, $tagline, $phone, $address, $hijri]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO settings (id, madrasa_name, tagline, phone, address, hijri_year, is_synced) VALUES (1, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$name, $tagline, $phone, $address, $hijri]);
        }

        // 2. منتخب کلاسز کا اندراج
        if (!empty($_POST['classes']) && is_array($_POST['classes'])) {
            $stmtClass = $pdo->prepare("INSERT INTO classes (name, department, system_type, is_synced) VALUES (?, ?, 'Madrasa', 0)");
            foreach ($_POST['classes'] as $cls) {
                $clsName = trim($cls);
                $dept = 'شعبہ تدریس';
                if (strpos($clsName, 'حفظ') !== false) $dept = 'شعبہ حفظ';
                elseif (strpos($clsName, 'ناظرہ') !== false) $dept = 'شعبہ ناظرہ';
                elseif (strpos($clsName, 'قاعدہ') !== false) $dept = 'شعبہ ابتدائیہ';

                // چیک کریں اگر پہلے سے موجود نہ ہو
                $cChk = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE name = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
                $cChk->execute([$clsName]);
                if ($cChk->fetchColumn() == 0) {
                    $stmtClass->execute([$clsName, $dept]);
                }
            }
        }

        // 3. پہلے استاد کا اندراج
        $tName = trim($_POST['teacher_name'] ?? '');
        $tPhone = trim($_POST['teacher_phone'] ?? '');
        if (!empty($tName)) {
            $tChk = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE name = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
            $tChk->execute([$tName]);
            if ($tChk->fetchColumn() == 0) {
                $stmtTeacher = $pdo->prepare("INSERT INTO teachers (name, phone, role, system_type, is_synced, joining_date) VALUES (?, ?, 'استاد', 'Madrasa', 0, CURDATE())");
                $stmtTeacher->execute([$tName, $tPhone]);
            }
        }

        // 4. پہلے طالب علم کا اندراج
        $sName = trim($_POST['student_name'] ?? '');
        $sFather = trim($_POST['student_father'] ?? '');
        $sClass = trim($_POST['student_class'] ?? 'حفظ القرآن الکریم');
        if (!empty($sName)) {
            $sChk = $pdo->prepare("SELECT COUNT(*) FROM students WHERE name = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
            $sChk->execute([$sName]);
            if ($sChk->fetchColumn() == 0) {
                $stmtStudent = $pdo->prepare("INSERT INTO students (name, father_name, class, admission_date, system_type, is_synced) VALUES (?, ?, ?, CURDATE(), 'Madrasa', 0)");
                $stmtStudent->execute([$sName, $sFather, $sClass]);
            }
        }

        $pdo->commit();
        $msg = "✅ نیا مدرسہ سیٹ اپ کامیابی سے مکمل ہو گیا ہے! سسٹم کے تمام بنیادی شعبہ جات فعال ہیں۔";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "خرابی: " . $e->getMessage();
        $msgType = 'danger';
    }
}

// موجودہ سیٹنگز لوڈ کریں
$settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-header bg-success text-white p-4 rounded-top-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="fw-bold mb-1"><i class="fas fa-magic me-2"></i> ابتدائی سیٹ اپ وزرڈ (Madrasa Setup Wizard)</h3>
                            <p class="mb-0 text-white-50">نئے مدرسہ یا ادارے کا 4 مراحل پر مشتمل فوری اور جامع کنفیگریشن سسٹم</p>
                        </div>
                        <a href="dashboard.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> ڈیش بورڈ</a>
                    </div>
                </div>

                <div class="card-body p-4">
                    <?php if (!empty($msg)): ?>
                        <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show fw-bold" role="alert">
                            <?php echo $msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="wizardForm">
                        <!-- Step Navigation Tabs -->
                        <ul class="nav nav-pills nav-fill mb-4 p-2 bg-light rounded-3" id="wizardTabs" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active fw-bold" id="step1-tab" data-bs-toggle="pill" data-bs-target="#step1" type="button">
                                    <i class="fas fa-university me-1"></i> 1. ادارہ معلومات
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="step2-tab" data-bs-toggle="pill" data-bs-target="#step2" type="button">
                                    <i class="fas fa-calendar-alt me-1"></i> 2. تعلیمی و ہجری سال
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="step3-tab" data-bs-toggle="pill" data-bs-target="#step3" type="button">
                                    <i class="fas fa-chalkboard me-1"></i> 3. کلاسز و شعبہ جات
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link fw-bold" id="step4-tab" data-bs-toggle="pill" data-bs-target="#step4" type="button">
                                    <i class="fas fa-user-graduate me-1"></i> 4. استاد و طالب علم
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content border p-4 rounded-3 bg-white" id="wizardContent">
                            <!-- Step 1 -->
                            <div class="tab-pane fade show active" id="step1" role="tabpanel">
                                <h5 class="fw-bold text-success border-bottom pb-2 mb-3">مرحلہ 1: ادارہ یا جامعہ کی بنیادی معلومات</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">مدرسہ کا نام</label>
                                        <input type="text" name="madrasa_name" class="form-control" value="<?php echo htmlspecialchars($settings['madrasa_name'] ?? 'جامعہ اشرف المدارس'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">سلوگن / ذیلی عنوان</label>
                                        <input type="text" name="tagline" class="form-control" value="<?php echo htmlspecialchars($settings['tagline'] ?? 'مرکز علوم دینیہ و عصریہ'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">مرکزی فون / رابطہ نمبر</label>
                                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($settings['phone'] ?? '03001234567'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">پتہ / شہر</label>
                                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($settings['address'] ?? 'مین جی ٹی روڈ'); ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2 -->
                            <div class="tab-pane fade" id="step2" role="tabpanel">
                                <h5 class="fw-bold text-success border-bottom pb-2 mb-3">مرحلہ 2: سالانہ تعلیمی سال اور ہجری کیلنڈر</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">تعلیمی سال (عیسوی)</label>
                                        <input type="text" name="academic_year" class="form-control" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">ہجری سال</label>
                                        <input type="text" name="hijri_year" class="form-control" value="<?php echo htmlspecialchars($settings['hijri_year'] ?? '1447 ھ'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">ہفتہ وار تعطیل</label>
                                        <select name="weekly_holiday" class="form-select">
                                            <option value="Friday" selected>جمعۃ المبارک (Friday)</option>
                                            <option value="Sunday">اتوار (Sunday)</option>
                                            <option value="Thursday_Friday">جمعرات و جمعہ</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3 -->
                            <div class="tab-pane fade" id="step3" role="tabpanel">
                                <h5 class="fw-bold text-success border-bottom pb-2 mb-3">مرحلہ 3: ابتدائی کلاسز و شعبہ جات کا انتخاب</h5>
                                <p class="text-muted">جن کلاسز کو آپ فوری طور پر فعال کرنا چاہتے ہیں ان پر ٹک لگا رہنے دیں:</p>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-check p-3 border rounded-3 bg-light">
                                            <input class="form-check-input" type="checkbox" name="classes[]" value="حفظ القرآن الکریم" id="cls1" checked>
                                            <label class="form-check-label fw-bold ms-2" for="cls1">
                                                حفظ القرآن الکریم <span class="badge bg-success ms-2">شعبہ حفظ</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check p-3 border rounded-3 bg-light">
                                            <input class="form-check-input" type="checkbox" name="classes[]" value="ناظرہ قرآن و تجوید" id="cls2" checked>
                                            <label class="form-check-label fw-bold ms-2" for="cls2">
                                                ناظرہ قرآن و تجوید <span class="badge bg-primary ms-2">شعبہ ناظرہ</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check p-3 border rounded-3 bg-light">
                                            <input class="form-check-input" type="checkbox" name="classes[]" value="نورانی قاعدہ" id="cls3" checked>
                                            <label class="form-check-label fw-bold ms-2" for="cls3">
                                                نورانی قاعدہ <span class="badge bg-warning text-dark ms-2">ابتدائیہ</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check p-3 border rounded-3 bg-light">
                                            <input class="form-check-input" type="checkbox" name="classes[]" value="درس نظامی - سال اول" id="cls4">
                                            <label class="form-check-label fw-bold ms-2" for="cls4">
                                                درس نظامی - سال اول <span class="badge bg-secondary ms-2">شعبہ کتب</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 4 -->
                            <div class="tab-pane fade" id="step4" role="tabpanel">
                                <h5 class="fw-bold text-success border-bottom pb-2 mb-3">مرحلہ 4: پہلے استاد اور طالب علم کا اندراج</h5>
                                <div class="row g-4">
                                    <div class="col-md-6 border-end">
                                        <h6 class="fw-bold text-dark"><i class="fas fa-chalkboard-teacher me-1"></i> پہلے استاد کی معلومات:</h6>
                                        <div class="mb-2">
                                            <label class="form-label">استاد کا نام</label>
                                            <input type="text" name="teacher_name" class="form-control" value="قاری عبد الرحمن">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">موبائل نمبر</label>
                                            <input type="text" name="teacher_phone" class="form-control" value="03120000000">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-dark"><i class="fas fa-user-graduate me-1"></i> پہلے طالب علم کی معلومات:</h6>
                                        <div class="mb-2">
                                            <label class="form-label">طالب علم کا نام</label>
                                            <input type="text" name="student_name" class="form-control" value="محمد احمد">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">والد کا نام</label>
                                            <input type="text" name="student_father" class="form-control" value="محمد علی">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">کلاس</label>
                                            <input type="text" name="student_class" class="form-control" value="حفظ القرآن الکریم">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <button type="button" class="btn btn-outline-secondary px-4 fw-bold" id="prevBtn" onclick="prevStep()">
                                <i class="fas fa-chevron-right me-1"></i> پچھلا مرحلہ
                            </button>
                            <div>
                                <button type="button" class="btn btn-success px-4 fw-bold me-2" id="nextBtn" onclick="nextStep()">
                                    اگلا مرحلہ <i class="fas fa-chevron-left ms-1"></i>
                                </button>
                                <button type="submit" name="complete_wizard" class="btn btn-primary px-4 fw-bold d-none" id="submitBtn">
                                    <i class="fas fa-check-circle me-1"></i> سیٹ اپ مکمل کریں
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentStepIndex = 0;
const stepTabIds = ['#step1-tab', '#step2-tab', '#step3-tab', '#step4-tab'];

function updateButtons() {
    document.getElementById('prevBtn').disabled = (currentStepIndex === 0);
    if (currentStepIndex === stepTabIds.length - 1) {
        document.getElementById('nextBtn').classList.add('d-none');
        document.getElementById('submitBtn').classList.remove('d-none');
    } else {
        document.getElementById('nextBtn').classList.remove('d-none');
        document.getElementById('submitBtn').classList.add('d-none');
    }
}

function nextStep() {
    if (currentStepIndex < stepTabIds.length - 1) {
        currentStepIndex++;
        const tabEl = document.querySelector(stepTabIds[currentStepIndex]);
        const tab = new bootstrap.Tab(tabEl);
        tab.show();
        updateButtons();
    }
}

function prevStep() {
    if (currentStepIndex > 0) {
        currentStepIndex--;
        const tabEl = document.querySelector(stepTabIds[currentStepIndex]);
        const tab = new bootstrap.Tab(tabEl);
        tab.show();
        updateButtons();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateButtons();
    stepTabIds.forEach((id, idx) => {
        const btn = document.querySelector(id);
        if (btn) {
            btn.addEventListener('shown.bs.tab', () => {
                currentStepIndex = idx;
                updateButtons();
            });
        }
    });
});
</script>

<?php include 'footer.php'; ?>
