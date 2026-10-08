<?php
// recruitment.php - بھرتیاں، نوکریاں اور امیدواروں کا پائپ لائن سسٹم
ob_start();
require_once 'db.php';
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'superadmin'], true) || has_perm('manage_recruitment');
if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

ensure_phase3_4_tables($pdo);

$active_sys = $_SESSION['active_system'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
$is_masjid = ($active_sys === 'Masjid');

$sys_cond = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$success_msg = '';
$error_msg = '';

// --- ACTIONS ---

// 1. نئی نوکری / آسامی شامل کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_job'])) {
    try {
        $title = trim($_POST['title'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $jType = trim($_POST['job_type'] ?? 'Full-time');
        $reqs = trim($_POST['requirements'] ?? '');
        $salary = trim($_POST['salary_range'] ?? '');
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $sys = trim($_POST['system_type'] ?? $active_sys);

        if (empty($title)) throw new Exception('آسامی کا عنوان لازمی ہے!');

        $stmt = $pdo->prepare("INSERT INTO job_postings (title, department, job_type, requirements, salary_range, deadline, status, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'Open', ?, 0, NOW(), NOW())");
        $stmt->execute([$title, $dept, $jType, $reqs, $salary, $deadline, $sys]);
        log_activity($pdo, 'recruitment', "نئی آسامی شامل کی گئی: $title ($dept)");
        $success_msg = "آسامی '$title' کامیابی سے شامل کر دی گئی!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 2. نیا امیدوار درج کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_applicant'])) {
    try {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $name = trim($_POST['applicant_name'] ?? '');
        $fName = trim($_POST['father_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $qual = trim($_POST['qualification'] ?? '');
        $exp = trim($_POST['experience'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($jobId <= 0 || empty($name) || empty($phone)) {
            throw new Exception('آسامی، امیدوار کا نام اور فون نمبر لازمی ہیں!');
        }

        $stmt = $pdo->prepare("INSERT INTO job_applicants (job_id, applicant_name, father_name, phone, email, qualification, experience, status, notes, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, 0, NOW(), NOW())");
        $stmt->execute([$jobId, $name, $fName, $phone, $email, $qual, $exp, $notes, $active_sys]);
        log_activity($pdo, 'recruitment', "نئے امیدوار کی درخواست موصول ہوئی: $name برائے جاب ID: $jobId");
        $success_msg = "امیدوار '$name' کی درخواست کامیابی سے درج ہو گئی!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 3. امیدوار کا مرحلہ / انٹرویو اپڈیٹ کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $appId = (int)($_POST['applicant_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Pending');
        $iDate = !empty($_POST['interview_date']) ? $_POST['interview_date'] : null;
        $iNotes = trim($_POST['interview_notes'] ?? '');

        if ($appId <= 0) throw new Exception('غلط درخواست!');

        $stmt = $pdo->prepare("UPDATE job_applicants SET status = ?, interview_date = ?, interview_notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $iDate, $iNotes, $appId]);
        log_activity($pdo, 'recruitment', "امیدوار ID: $appId کا اسٹیٹس تبدیل کیا گیا: $newStatus");
        $success_msg = "امیدوار کا مرحلہ کامیابی سے اپڈیٹ ہو گیا!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 4. امیدوار کو استاد / ملازم میں تبدیل کرنا (Hire to Teacher)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hire_teacher'])) {
    try {
        $appId = (int)($_POST['applicant_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM job_applicants WHERE id = ?");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) throw new Exception('امیدوار نہیں ملا!');

        $targetTable = $is_masjid ? 'maktab_teachers' : 'teachers';
        $tStmt = $pdo->prepare("INSERT INTO $targetTable (name, father_name, phone, qualification, status, is_deleted, created_at) VALUES (?, ?, ?, ?, 'Active', 0, NOW())");
        $tStmt->execute([$app['applicant_name'], $app['father_name'], $app['phone'], $app['qualification']]);
        $newTId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE job_applicants SET status = 'Selected', converted_teacher_id = ?, updated_at = NOW() WHERE id = ?")->execute([$newTId, $appId]);
        log_activity($pdo, 'recruitment', "امیدوار {$app['applicant_name']} کو استاد میں شامل کیا گیا (ID: $newTId)");
        $success_msg = "مبارک ہو! امیدوار '{$app['applicant_name']}' کو اساتذہ لسٹ میں شامل کر دیا گیا ہے!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Fetch Job Postings
$jobs = $pdo->query("SELECT * FROM job_postings WHERE $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Applicants with Job Title
$applicants = $pdo->query("
    SELECT a.*, j.title as job_title, j.department
    FROM job_applicants a
    JOIN job_postings j ON a.job_id = j.id
    WHERE $sys_cond AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
    ORDER BY a.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Stages Pipeline
$stageDefs = [
    'Pending' => ['title' => 'موصولہ درخواستیں', 'color' => 'primary', 'icon' => 'fa-inbox'],
    'Interviewed' => ['title' => 'انٹرویو مرحلہ', 'color' => 'warning', 'icon' => 'fa-calendar-check'],
    'Selected' => ['title' => 'منتخب شدہ (بھرتی)', 'color' => 'success', 'icon' => 'fa-check-circle'],
    'Rejected' => ['title' => 'مسترد شدہ', 'color' => 'danger', 'icon' => 'fa-times-circle'],
];
?>

<div class="container-fluid py-4" dir="rtl">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold mb-1" style="color: #284D3D;">
                <i class="fas fa-user-tie me-2 text-warning"></i>
                <?php echo $is_masjid ? 'بھرتی و نوکریاں (مسجد / مکتب عملہ)' : 'بھرتی و اساتذہ نوکریاں (Recruitment)'; ?>
            </h3>
            <span class="text-muted">خالی آسامیوں، امیدواروں کی سی وی اور انٹرویو پائپ لائن کا انتظام</span>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-warning fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#addApplicantModal">
                <i class="fas fa-user-plus me-1"></i> امیدوار شامل کریں
            </button>
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addJobModal">
                <i class="fas fa-briefcase me-1"></i> نئی آسامی شامل کریں
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4 gap-2" id="recTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold px-4" id="pipeline-tab" data-bs-toggle="pill" data-bs-target="#pipeline-pane">
                <i class="fas fa-stream me-1"></i> پائپ لائن بورڈ (Kanban / Stages)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4" id="jobs-tab" data-bs-toggle="pill" data-bs-target="#jobs-pane">
                <i class="fas fa-briefcase me-1"></i> خالی آسامیاں (Job Openings)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="recTabsContent">
        <!-- TAB 1: Pipeline Board -->
        <div class="tab-pane fade show active" id="pipeline-pane">
            <div class="row g-3">
                <?php foreach ($stageDefs as $stKey => $stMeta): 
                    $stageApps = array_filter($applicants, function($a) use ($stKey) {
                        return ($a['status'] ?? 'Pending') === $stKey;
                    });
                ?>
                    <div class="col-lg-3 col-md-6">
                        <div class="card border-0 shadow-sm rounded-3 bg-light h-100">
                            <div class="card-header bg-white border-bottom-0 py-3 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-<?php echo $stMeta['color']; ?>">
                                    <i class="fas <?php echo $stMeta['icon']; ?> me-1"></i> <?php echo $stMeta['title']; ?>
                                </h6>
                                <span class="badge bg-<?php echo $stMeta['color']; ?> rounded-pill"><?php echo count($stageApps); ?></span>
                            </div>
                            <div class="card-body p-2" style="min-height: 400px; max-height: 650px; overflow-y: auto;">
                                <?php if (empty($stageApps)): ?>
                                    <div class="text-center py-5 text-muted small">کوئی درخواست نہیں</div>
                                <?php else: ?>
                                    <?php foreach ($stageApps as $app): ?>
                                        <div class="card border-0 shadow-sm mb-2 rounded-2 bg-white">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <h6 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($app['applicant_name']); ?></h6>
                                                    <span class="badge bg-light text-dark border small"><?php echo htmlspecialchars($app['job_title']); ?></span>
                                                </div>
                                                <div class="small text-muted mb-2">
                                                    <i class="fas fa-phone-alt me-1 text-success"></i> <?php echo htmlspecialchars($app['phone']); ?><br>
                                                    <i class="fas fa-graduation-cap me-1 text-primary"></i> <?php echo htmlspecialchars($app['qualification'] ?: 'تعلیم درج نہیں'); ?>
                                                </div>
                                                <?php if (!empty($app['interview_date'])): ?>
                                                    <div class="badge bg-warning text-dark mb-2">
                                                        <i class="fas fa-calendar-alt me-1"></i> انٹرویو: <?php echo htmlspecialchars($app['interview_date']); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="openStageModal(<?php echo htmlspecialchars(json_encode($app)); ?>)">
                                                        <i class="fas fa-exchange-alt me-1"></i> مرحلہ بدلیں
                                                    </button>
                                                    <?php if ($stKey === 'Selected' && empty($app['converted_teacher_id'])): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('کیا آپ اس امیدوار کو اساتذہ لسٹ میں شامل کرنا چاہتے ہیں؟');">
                                                            <input type="hidden" name="applicant_id" value="<?php echo $app['id']; ?>">
                                                            <button type="submit" name="hire_teacher" class="btn btn-sm btn-success fw-bold">
                                                                <i class="fas fa-user-check me-1"></i> ملازم بنائیں
                                                            </button>
                                                        </form>
                                                    <?php elseif (!empty($app['converted_teacher_id'])): ?>
                                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i> ملازم درج شدہ</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TAB 2: Job Openings -->
        <div class="tab-pane fade" id="jobs-pane">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <h5 class="fw-bold mb-3"><i class="fas fa-briefcase me-2 text-primary"></i> آسامیوں کی تفصیلات</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>عنوانِ ملازمت</th>
                                    <th>شعبہ</th>
                                    <th>قسم</th>
                                    <th>تنخواہ</th>
                                    <th>آخری تاریخ</th>
                                    <th>اہلیت / تقاضے</th>
                                    <th>اسٹیٹس</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jobs)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">کوئی آسامی موجود نہیں ہے۔</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $j): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($j['title']); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($j['department'] ?: 'عام'); ?></span></td>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($j['job_type']); ?></span></td>
                                            <td><?php echo htmlspecialchars($j['salary_range'] ?: 'حسبِ لیاقت'); ?></td>
                                            <td><?php echo htmlspecialchars($j['deadline'] ?: 'اوپن'); ?></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars($j['requirements'] ?: '-'); ?></td>
                                            <td>
                                                <span class="badge <?php echo $j['status']=='Open'?'bg-success':'bg-secondary'; ?>">
                                                    <?php echo $j['status']=='Open'?'کھلی ہے':'بند'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add Job -->
<div class="modal fade" id="addJobModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-briefcase me-1"></i> نئی آسامی کا اندراج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">آسامی کا عنوان <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required placeholder="مثلاً مدرس حفظ، قاری ناظرہ، نگران ہاسٹل، اکاؤنٹنٹ">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">شعبہ</label>
                        <input type="text" name="department" class="form-control" placeholder="حفظ، کتب، ایڈمن">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">ملازمت کی نوعیت</label>
                        <select name="job_type" class="form-select">
                            <option value="Full-time">مکمل وقتی (Full-time)</option>
                            <option value="Part-time">جز وقتی (Part-time)</option>
                            <option value="Visiting">عارضی / وزٹنگ</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">تنخواہ کا تخمینہ</label>
                        <input type="text" name="salary_range" class="form-control" placeholder="25,000 - 35,000">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">آخری تاریخ برائے درخواست</label>
                        <input type="date" name="deadline" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">اہلیت و تقاضے</label>
                    <textarea name="requirements" class="form-control" rows="2" placeholder="وفاق المدارس سند، تجوید، 2 سالہ تجربہ"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="add_job" class="btn btn-success fw-bold">آسامی شائع کریں</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Applicant -->
<div class="modal fade" id="addApplicantModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-1"></i> امیدوار کی درخواست درج کریں</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">آسامی منتخب کریں <span class="text-danger">*</span></label>
                    <select name="job_id" class="form-select" required>
                        <option value="">-- منتخب کریں --</option>
                        <?php foreach ($jobs as $j): ?>
                            <option value="<?php echo $j['id']; ?>"><?php echo htmlspecialchars($j['title']) . " ({$j['department']})"; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">امیدوار کا نام <span class="text-danger">*</span></label>
                        <input type="text" name="applicant_name" class="form-control" required placeholder="نام">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">والد کا نام</label>
                        <input type="text" name="father_name" class="form-control" placeholder="ولدیت">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">رابطہ نمبر <span class="text-danger">*</span></label>
                        <input type="text" name="phone" class="form-control" required placeholder="0300-1234567">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">ای میل (اختیاری)</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">تعلیم / ڈگری</label>
                        <input type="text" name="qualification" class="form-control" placeholder="شہادت العالمیہ / بی اے">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">تجربہ</label>
                        <input type="text" name="experience" class="form-control" placeholder="مثلاً 3 سالہ تدریس">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">نوٹس / ابتدائی رائے</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="add_applicant" class="btn btn-warning fw-bold text-dark">درخواست محفوظ کریں</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Update Stage / Interview -->
<div class="modal fade" id="stageModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="applicant_id" id="stage_app_id">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-exchange-alt me-1"></i> امیدوار کا مرحلہ تبدیل کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">موجودہ امیدوار</label>
                    <input type="text" id="stage_app_name" class="form-control bg-light" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">نیا مرحلہ منتخب کریں</label>
                    <select name="status" id="stage_status" class="form-select" required>
                        <option value="Pending">موصولہ (Pending)</option>
                        <option value="Interviewed">انٹرویو شیڈول (Interviewed)</option>
                        <option value="Selected">منتخب شدہ (Selected / Hired)</option>
                        <option value="Rejected">مسترد شدہ (Rejected)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">انٹرویو کی تاریخ (اگر شیڈول کرنا ہو)</label>
                    <input type="date" name="interview_date" id="stage_interview_date" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">انٹرویو نوٹس / ممتحن کی رائے</label>
                    <textarea name="interview_notes" id="stage_interview_notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="update_status" class="btn btn-primary fw-bold">اسٹیٹس اپڈیٹ کریں</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStageModal(app) {
    document.getElementById('stage_app_id').value = app.id;
    document.getElementById('stage_app_name').value = app.applicant_name + ' (' + app.job_title + ')';
    document.getElementById('stage_status').value = app.status || 'Pending';
    document.getElementById('stage_interview_date').value = app.interview_date || '';
    document.getElementById('stage_interview_notes').value = app.interview_notes || '';
    const modal = new bootstrap.Modal(document.getElementById('stageModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>
