<?php
ob_start();
// admin_admissions.php - Full Admission Applications Management Page
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access control - admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Handle approval with test date/time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id        = intval($_POST['id']);
    $action    = $_POST['action'];
    $sms_fired = false;
    $sms_msg   = '';

    if ($action === 'approve') {
        $test_date  = $_POST['test_date'] ?? null;
        $test_time  = $_POST['test_time'] ?? null;
        $admin_note = trim($_POST['admin_note'] ?? '');

        $stmt = $pdo->prepare("UPDATE admission_applications 
            SET status='Approved', test_date=?, test_time=?, admin_note=? WHERE id=?");
        $stmt->execute([$test_date, $test_time, $admin_note, $id]);

        // Auto-trigger SMS
        $ch = curl_init();
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 's':'');
        $sms_url = 'http' . $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/send_sms.php';
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $sms_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['app_id' => $id]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $smsResultRaw = curl_exec($ch);
        $smsResult = json_decode($smsResultRaw, true);
        curl_close($ch);
        $sms_fired = $smsResult['success'] ?? false;
        $sms_msg   = $smsResult['message'] ?? '';

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => "درخواست #$id منظور کر لی گئی۔ " . ($sms_fired ? "✅ SMS بھیج دیا گیا!" : "⚠️ SMS میں خرابی: $sms_msg")
        ];

    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE admission_applications SET status='rejected' WHERE id=?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => "درخواست #$id مسترد کر دی گئی۔"];
    }

    header("Location: admin_admissions.php");
    exit;
}

// اب ہیڈر شامل کریں جب تمام لاجک مکمل ہو جائے
include 'header.php';

// Fetch all applications (newest first)
$filter = $_GET['filter'] ?? 'all';
$filter = strtolower($filter);
$whereClause = ($filter === 'all') ? '' : "WHERE LOWER(status)='$filter'";
$apps = $pdo->query("SELECT * FROM admission_applications $whereClause ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Counts
$countMap = ['pending'=>0,'approved'=>0,'rejected'=>0];
$counts = $pdo->query("SELECT status, COUNT(*) as c FROM admission_applications GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach($counts as $c) { $countMap[strtolower($c['status'])] = $c['c']; }
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold" style="color:var(--primary);">
            <i class="fas fa-graduation-cap me-2"></i> داخلہ درخواستیں
        </h2>
    </div>

    <!-- Flash Message -->
    <?php if(isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash']['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['flash']['msg']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="admin_admissions.php?filter=pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white h-100" style="background:var(--primary);">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h5 class="mb-0">زیرِ التواء</h5><h1 class="display-5 fw-bold mb-0"><?php echo $countMap['pending']; ?></h1></div>
                        <i class="fas fa-hourglass-half fs-1 opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="admin_admissions.php?filter=approved" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white bg-success h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h5 class="mb-0">منظور شدہ</h5><h1 class="display-5 fw-bold mb-0"><?php echo $countMap['approved']; ?></h1></div>
                        <i class="fas fa-check-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="admin_admissions.php?filter=rejected" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white bg-danger h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h5 class="mb-0">مسترد</h5><h1 class="display-5 fw-bold mb-0"><?php echo $countMap['rejected']; ?></h1></div>
                        <i class="fas fa-times-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Filter Tabs -->
    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link <?php echo $filter==='all'?'active':''; ?>" href="?filter=all">تمام (<?php echo array_sum($countMap); ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $filter==='pending'?'active':''; ?>" href="?filter=pending">زیرِ التواء (<?php echo $countMap['pending']; ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $filter==='approved'?'active':''; ?>" href="?filter=approved">منظور شدہ</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $filter==='rejected'?'active':''; ?>" href="?filter=rejected">مسترد</a></li>
    </ul>

    <!-- Applications Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background:var(--primary); color:white;">
                    <tr>
                        <th>#</th>
                        <th>طالب علم</th>
                        <th>والد</th>
                        <th>فون</th>
                        <th>ای میل</th>
                        <th>شعبہ</th>
                        <th><?php echo t('age'); ?></th>
                        <th>تاریخ درخواست</th>
                        <th><?php echo t('status_ur'); ?></th>
                        <th>اقدامات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($apps)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">کوئی درخواست موجود نہیں</td></tr>
                    <?php else: foreach($apps as $app): 
                        $currentStatus = strtolower($app['status'] ?? '');
                        $badgeClass = match($currentStatus) {
                            'approved' => 'bg-success',
                            'rejected' => 'bg-danger',
                            default    => 'bg-warning text-dark'
                        };
                        $statusLabel = match($currentStatus) {
                            'approved' => 'منظور',
                            'rejected' => 'مسترد',
                            default    => 'زیرِ التواء'
                        };
                    ?>
                        <tr>
                            <td class="fw-bold"><?php echo $app['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($app['student_name'] ?? ''); ?></strong>
                                <?php if(!empty($app['address'])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($app['address']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($app['father_name'] ?? ''); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($app['mobile_number'] ?? $app['mobile_no'] ?? ''); ?></td>
                            <td dir="ltr" class="small"><?php echo ($app['email'] ?? '') ? htmlspecialchars($app['email']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo htmlspecialchars($app['department'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                if (!empty($app['date_of_birth'])) {
                                    $dob = new DateTime($app['date_of_birth']);
                                    $now = new DateTime();
                                    $age = $now->diff($dob)->y;
                                    echo $age . " سال";
                                } else {
                                    echo "-";
                                }
                                ?>
                            </td>
                            <td dir="ltr" class="small"><?php echo date('d M Y', strtotime($app['created_at'])); ?></td>
                            <td>
                                <span class="badge <?php echo $badgeClass; ?> rounded-pill"><?php echo $statusLabel; ?></span>
                                <?php if($currentStatus === 'approved'): ?>
                                    <br><small class="text-success">
                                        <?php if($app['test_date']): ?>
                                            📅 <?php echo date('d M Y', strtotime($app['test_date'])); ?>
                                            ⏰ <?php echo date('h:i A', strtotime($app['test_time'])); ?>
                                        <?php endif; ?>
                                        <?php echo ($app['sms_sent'] ?? 0) ? '<br>✅ SMS بھیجا گیا' : '<br>⚠️ SMS نہیں بھیجا'; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- View Button -->
                                <button class="btn btn-info btn-sm rounded-pill mb-1 text-white" onclick='openViewModal(<?php echo htmlspecialchars(json_encode($app), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye me-1"></i> تفصیل
                                </button>
                                <?php if($currentStatus === 'pending'): ?>
                                    <br>
                                    <!-- Approve Button → Triggers Modal -->
                                    <button class="btn btn-success btn-sm rounded-pill mb-1"
                                        onclick="openApproveModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['student_name']); ?>')">
                                        <i class="fas fa-check me-1"></i> منظور
                                    </button>
                                    <!-- Reject Button -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('کیا آپ واقعی یہ درخواست مسترد کرنا چاہتے ہیں؟')">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?php echo $app['id']; ?>">
                                        <button class="btn btn-danger btn-sm rounded-pill mb-1" type="submit">
                                            <i class="fas fa-times me-1"></i> مسترد
                                        </button>
                                    </form>
                                <?php elseif($currentStatus==='approved' && !($app['sms_sent'] ?? 0)): ?>
                                    <br>
                                    <!-- Retry SMS -->
                                    <button class="btn btn-warning btn-sm rounded-pill" onclick="retrySMS(<?php echo $app['id']; ?>)">
                                        <i class="fas fa-sms me-1"></i> SMS دوبارہ بھیجیں
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Approval Modal with Date/Time Picker -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:15px;">
            <div class="modal-header" style="background:var(--primary);">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-calendar-check me-2"></i> اپوائنٹمنٹ تفصیلات</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" id="approveAppId">
                <div class="modal-body p-4">
                    <p>امیدوار: <strong id="approveStudentName" class="text-success"></strong></p>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ٹیسٹ کی تاریخ <span class="text-danger">*</span></label>
                        <input type="date" name="test_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ٹیسٹ کا وقت <span class="text-danger">*</span></label>
                        <input type="time" name="test_time" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ایڈمن نوٹ (اختیاری)</label>
                        <textarea name="admin_note" class="form-control" rows="2" placeholder="مثلاً: مین گیٹ سے داخل ہوں"></textarea>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        منظوری کے بعد امیدوار کے موبائل نمبر پر <strong>خودکار SMS</strong> بھیجا جائے گا جس میں ٹیسٹ کی تاریخ اور وقت شامل ہوگا۔
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-success rounded-pill fw-bold px-4">
                        <i class="fas fa-check me-2"></i> منظور کریں اور SMS بھیجیں
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:15px;">
            <div class="modal-header" style="background:#0dcaf0; color:white;">
                <h5 class="modal-title fw-bold"><i class="fas fa-eye me-2"></i> درخواست کی تفصیلات</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">بند کریں</button>
            </div>
        </div>
    </div>
</div>

<script>
function openViewModal(app) {
    let photo = app.photo ? app.photo : 'assets/img/default-avatar.png';
    let phone = app.mobile_number || app.mobile_no || '-';
    let body = `
        <div class="row mb-3 align-items-center">
            <div class="col-md-3 text-center">
                <img src="${photo}" alt="Photo" class="img-thumbnail" style="width:120px; height:120px; object-fit:cover; border-radius:50%;">
            </div>
            <div class="col-md-9">
                <h4 class="text-primary fw-bold">${app.student_name || '-'}</h4>
                <div class="row">
                    <div class="col-md-6 mb-1"><strong>ولدیت:</strong> ${app.father_name || '-'}</div>
                    <div class="col-md-6 mb-1"><strong>تاریخ پیدائش:</strong> ${app.dob || '-'}</div>
                    <div class="col-md-6 mb-1"><strong>فون نمبر:</strong> <span dir="ltr">${phone}</span></div>
                    <div class="col-md-6 mb-1"><strong>شعبہ:</strong> ${app.department || '-'}</div>
                </div>
            </div>
        </div>
        <hr>
        <div class="row bg-light p-3 rounded mx-0">
            <div class="col-md-6 mb-2"><strong>ای میل:</strong> ${app.email || '-'}</div>
            <div class="col-md-6 mb-2"><strong>موجودہ <?php echo t('address_admin'); ?>:</strong> ${app.address || '-'}</div>
            <div class="col-md-6 mb-2"><strong>مستقل <?php echo t('address_admin'); ?>:</strong> ${app.permanent_address || '-'}</div>
            <div class="col-md-6 mb-2"><strong><?php echo t('prev_education'); ?>:</strong> ${app.qualification || '-'}</div>
            <div class="col-md-6 mb-2"><strong>گزشتہ ادارہ:</strong> ${app.last_studied || '-'}</div>
            <div class="col-md-6 mb-2"><strong>مقصدِ داخلہ:</strong> ${app.aim || '-'}</div>
            <div class="col-md-6 mb-2"><strong>سابقہ مدارس:</strong> ${app.prev_madaris || '-'}</div>
            <div class="col-md-6 mb-2"><strong>مدرسہ چھوڑنے کی وجہ:</strong> ${app.leave_reason || '-'}</div>
        </div>
        <h5 class="mt-3 text-secondary border-bottom pb-2">سرپرست کی معلومات</h5>
        <div class="row px-3">
            <div class="col-md-6 mb-2"><strong>نام سرپرست:</strong> ${app.guardian_name || '-'}</div>
            <div class="col-md-6 mb-2"><strong>شناختی کارڈ:</strong> <span dir="ltr">${app.guardian_cnic || '-'}</span></div>
            <div class="col-md-6 mb-2"><strong>رشتہ:</strong> ${app.guardian_relation || '-'}</div>
            <div class="col-md-6 mb-2"><strong><?php echo t('address_admin'); ?>:</strong> ${app.guardian_address || '-'}</div>
        </div>
    `;
    document.getElementById('viewModalBody').innerHTML = body;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

function openApproveModal(id, name) {
    document.getElementById('approveAppId').value = id;
    document.getElementById('approveStudentName').textContent = name;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function retrySMS(appId) {
    if(!confirm('SMS دوبارہ بھیجیں؟')) return;
    fetch('send_sms.php', {
        method: 'POST',
        body: new URLSearchParams({ app_id: appId })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if(data.success) location.reload();
    });
}
</script>

<?php include 'footer.php'; ?>
