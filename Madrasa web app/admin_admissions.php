<?php
ob_start();
// admin_admissions.php - Full Admission Applications Management Page
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access control - admin, superadmin, or users with manage_admissions permission
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = strtolower((string)($_SESSION['role'] ?? ''));
$is_admin = in_array($role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
$can_manage_adm = $is_admin || (function_exists('app_has_perm') && (app_has_perm('manage_admissions') || app_has_perm('manage_students'))) || has_permission($role, $_SESSION['permissions'] ?? [], 'manage_admissions') || has_permission($role, $_SESSION['permissions'] ?? [], 'manage_students');
if (!$can_manage_adm) {
    header("Location: dashboard.php");
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id        = intval($_POST['id']);
    $action    = $_POST['action'];
    $sms_fired = false;
    $sms_msg   = '';

    if ($action === 'interview') {
        $test_date  = $_POST['test_date'] ?? null;
        $test_time  = $_POST['test_time'] ?? null;
        $admin_note = trim($_POST['admin_note'] ?? '');

        $stmt = $pdo->prepare("UPDATE admission_applications 
            SET status='interview', test_date=?, test_time=?, admin_note=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$test_date, $test_time, $admin_note, $id, $cur_tenant_id]);

        $appStmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ? AND tenant_id = ? LIMIT 1");
        $appStmt->execute([$id, $cur_tenant_id]);
        $app = $appStmt->fetch(PDO::FETCH_ASSOC);
        $mobile = $app ? trim((string)($app['mobile_number'] ?? ($app['mobile_no'] ?? ''))) : '';

        if ($app && $mobile !== '') {
            require_once __DIR__ . '/send_sms.php';
            $smsMessage = "محترم " . ($app['student_name'] ?? '') . "، آپ کی داخلہ درخواست پر ٹیسٹ و انٹرویو مقرر کیا گیا ہے۔ تاریخ: " . ($test_date ?: '-') . " وقت: " . ($test_time ?: '-') . ($admin_note ? "۔ نوٹ: $admin_note" : "");
            $smsResult = send_sms($mobile, $smsMessage);
            $sms_fired = $smsResult['success'] ?? false;
            $sms_msg   = $smsResult['message'] ?? '';
            try {
                $hasSmsSentColumn = $pdo->query("SHOW COLUMNS FROM admission_applications LIKE 'sms_sent'")->fetch();
                if ($hasSmsSentColumn) {
                    $pdo->prepare("UPDATE admission_applications SET sms_sent = ? WHERE id = ? AND tenant_id = ?")
                        ->execute([$sms_fired ? 1 : 0, $id, $cur_tenant_id]);
                }
            } catch (Throwable $e) {}
        }

        $rawPhone = preg_replace('/[^0-9]/', '', $mobile);
        $cleanPhone = (substr($rawPhone, 0, 1) === '0') ? '92' . substr($rawPhone, 1) : $rawPhone;
        $waMsg = "السلام علیکم ورحمۃ اللہ!\nمحترم " . ($app['student_name'] ?? '') . " ولد " . ($app['father_name'] ?? '') . " کی داخلہ درخواست برائے جامعہ اشرف المدارس پر ٹیسٹ و انٹرویو مقرر کیا گیا ہے۔\n📅 تاریخ: " . ($test_date ?: '-') . "\n⏰ وقت: " . ($test_time ?: '-') . "\n📍 ادارہ: جامعہ اشرف المدارس، فروکہ\nبراہ کرم مقررہ تاریخ اور وقت پر اصل اسناد و شناختی دستاویزات کے ہمراہ تشریف لائیں۔ شکریہ!";
        $waUrl = "https://wa.me/" . $cleanPhone . "?text=" . rawurlencode($waMsg);

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => "درخواست #$id پر ٹیسٹ و انٹرویو شیڈول ہو گیا۔ " . ($sms_fired ? "✅ SMS بھیج دیا گیا!" : "") . " <a href='$waUrl' target='_blank' class='btn btn-success btn-sm ms-2 fw-bold text-white'><i class='fab fa-whatsapp me-1'></i> واٹس ایپ پر نوٹس بھیجیں</a>"
        ];

    } elseif ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE admission_applications SET status='Approved' WHERE id=? AND tenant_id=?");
        $stmt->execute([$id, $cur_tenant_id]);

        $appStmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ? AND tenant_id = ? LIMIT 1");
        $appStmt->execute([$id, $cur_tenant_id]);
        $app = $appStmt->fetch(PDO::FETCH_ASSOC);
        $mobile = $app ? trim((string)($app['mobile_number'] ?? ($app['mobile_no'] ?? ''))) : '';

        // Auto-enroll into students or maktab_students
        if ($app) {
            try {
                $dept = strtolower(trim((string)($app['department'] ?? '')));
                $isMaktab = (strpos($dept, 'مکتب') !== false || strpos($dept, 'maktab') !== false);
                $targetTable = $isMaktab ? 'maktab_students' : 'students';

                $checkAdm = $pdo->prepare("SELECT id FROM $targetTable WHERE name = ? AND father_name = ? AND tenant_id = ? LIMIT 1");
                $checkAdm->execute([$app['student_name'], $app['father_name'], $cur_tenant_id]);
                if (!$checkAdm->fetch()) {
                    $quotaCheck = function_exists('check_quota_limit') ? check_quota_limit($pdo, 'students', $cur_tenant_id) : ['allowed' => true];
                    if (!$quotaCheck['allowed']) {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'msg' => "درخواست منظور ہو گئی لیکن طلبہ کی کوٹہ حد مکمل ہونے کے باعث طالب علم کو رجسٹر میں داخل نہیں کیا جا سکا۔ برائے مہربانی سبسکرپشن پلان اپ گریڈ کریں۔"
                        ];
                    } else {
                        $current_hijri = $app['hijri_year'] ?? '1447';
                        $max_stmt = $pdo->prepare("SELECT MAX(id) FROM $targetTable WHERE tenant_id = ?");
                        $max_stmt->execute([$cur_tenant_id]);
                        $maxId = $max_stmt->fetchColumn() ?: 0;
                        $admNo = $current_hijri . "-" . str_pad($maxId + 1, 3, "0", STR_PAD_LEFT);
                        $today = date('Y-m-d');

                        $insStmt = $pdo->prepare("INSERT INTO $targetTable 
                            (name, father_name, admission_number, class, class_name, contact_number, address, status, admission_date, photo, system_type, residence_status, tenant_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, 'residential_aided', ?)");
                        $insStmt->execute([
                            $app['student_name'],
                            $app['father_name'],
                            $admNo,
                            $app['department'] ?: 'حفظ',
                            $app['department'] ?: 'حفظ',
                            $mobile,
                            $app['address'] ?? '',
                            $today,
                            $app['photo'] ?? '',
                            $isMaktab ? 'Masjid' : 'Madrasa',
                            $cur_tenant_id
                        ]);
                    }
                }
            } catch (Throwable $e) {}
        }

        if ($app && $mobile !== '') {
            require_once __DIR__ . '/send_sms.php';
            $smsMessage = "محترم " . ($app['student_name'] ?? '') . "، آپ کا داخلہ باقاعدہ منظور ہو گیا ہے۔ جامعہ اشرف المدارس میں خوش آمدید۔";
            $smsResult = send_sms($mobile, $smsMessage);
            $sms_fired = $smsResult['success'] ?? false;
            $sms_msg   = $smsResult['message'] ?? '';
            try {
                $hasSmsSentColumn = $pdo->query("SHOW COLUMNS FROM admission_applications LIKE 'sms_sent'")->fetch();
                if ($hasSmsSentColumn) {
                    $pdo->prepare("UPDATE admission_applications SET sms_sent = ? WHERE id = ? AND tenant_id = ?")
                        ->execute([$sms_fired ? 1 : 0, $id, $cur_tenant_id]);
                }
            } catch (Throwable $e) {}
        }

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => "درخواست #$id منظور کر لی گئی اور طالب علم کا باضابطہ اندراج مکمل ہو گیا۔ " . ($sms_fired ? "✅ SMS بھیج دیا گیا!" : "")
        ];

    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE admission_applications SET status='rejected' WHERE id=? AND tenant_id=?");
        $stmt->execute([$id, $cur_tenant_id]);
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
$whereClause = "WHERE tenant_id = $cur_tenant_id";
if ($filter !== 'all') {
    $whereClause .= " AND LOWER(status) = " . $pdo->quote($filter);
}
$apps = $pdo->query("SELECT * FROM admission_applications $whereClause ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Counts
$countMap = ['pending' => 0, 'interview' => 0, 'approved' => 0, 'rejected' => 0];
$counts = $pdo->query("SELECT status, COUNT(*) as c FROM admission_applications WHERE tenant_id = $cur_tenant_id GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach($counts as $c) { $countMap[strtolower($c['status'])] = (int)$c['c']; }
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
        <div class="col-md-3">
            <a href="admin_admissions.php?filter=pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white h-100" style="background:#fd7e14;">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="mb-1">زیرِ التواء</h6><h2 class="fw-bold mb-0"><?php echo $countMap['pending']; ?></h2></div>
                        <i class="fas fa-hourglass-half fs-1 opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="admin_admissions.php?filter=interview" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white h-100" style="background:#6f42c1;">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="mb-1">ٹیسٹ / انٹرویو</h6><h2 class="fw-bold mb-0"><?php echo $countMap['interview']; ?></h2></div>
                        <i class="fas fa-calendar-check fs-1 opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="admin_admissions.php?filter=approved" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white bg-success h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="mb-1">منظور شدہ</h6><h2 class="fw-bold mb-0"><?php echo $countMap['approved']; ?></h2></div>
                        <i class="fas fa-check-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="admin_admissions.php?filter=rejected" class="text-decoration-none">
                <div class="card border-0 shadow-sm text-white bg-danger h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="mb-1">مسترد</h6><h2 class="fw-bold mb-0"><?php echo $countMap['rejected']; ?></h2></div>
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
        <li class="nav-item"><a class="nav-link <?php echo $filter==='interview'?'active':''; ?>" href="?filter=interview">ٹیسٹ / انٹرویو (<?php echo $countMap['interview']; ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $filter==='approved'?'active':''; ?>" href="?filter=approved">منظور شدہ (<?php echo $countMap['approved']; ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $filter==='rejected'?'active':''; ?>" href="?filter=rejected">مسترد (<?php echo $countMap['rejected']; ?>)</a></li>
    </ul>

    <!-- Applications Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead style="background:var(--primary); color:white;">
                    <tr>
                        <th>#</th>
                        <th>تصویر</th>
                        <th class="text-start">طالب علم</th>
                        <th>والد کا نام</th>
                        <th>فون نمبر</th>
                        <th>شعبہ</th>
                        <th>عمر</th>
                        <th>تاریخ درخواست</th>
                        <th>حالت</th>
                        <th>اقدامات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($apps)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">کوئی درخواست موجود نہیں</td></tr>
                    <?php else: foreach($apps as $app): 
                        $currentStatus = strtolower($app['status'] ?? '');
                        if ($currentStatus === 'approved') {
                            $badgeClass = 'bg-success';
                            $statusLabel = 'منظور شدہ';
                        } elseif ($currentStatus === 'rejected') {
                            $badgeClass = 'bg-danger';
                            $statusLabel = 'مسترد';
                        } elseif ($currentStatus === 'interview') {
                            $badgeClass = 'text-white';
                            $statusLabel = 'ٹیسٹ / انٹرویو';
                        } else {
                            $badgeClass = 'bg-warning text-dark';
                            $statusLabel = 'زیرِ التواء';
                        }
                        $photoSrc = !empty($app['photo']) ? htmlspecialchars($app['photo']) : 'assets/img/default-avatar.png';
                        $phoneNum = htmlspecialchars($app['mobile_number'] ?? $app['mobile_no'] ?? '');
                        $rawPhone = preg_replace('/[^0-9]/', '', (string)($app['mobile_number'] ?? $app['mobile_no'] ?? ''));
                        $cleanPhone = (substr($rawPhone, 0, 1) === '0') ? '92' . substr($rawPhone, 1) : $rawPhone;
                        $waMsg = "السلام علیکم ورحمۃ اللہ!\nمحترم " . ($app['student_name'] ?? '') . " ولد " . ($app['father_name'] ?? '') . " کی داخلہ درخواست برائے جامعہ اشرف المدارس پر ٹیسٹ و انٹرویو مقرر کیا گیا ہے۔\n📅 تاریخ: " . ($app['test_date'] ?: '-') . "\n⏰ وقت: " . ($app['test_time'] ?: '-') . "\n📍 ادارہ: جامعہ اشرف المدارس، فروکہ\nبراہ کرم مقررہ تاریخ اور وقت پر اصل اسناد و شناختی دستاویزات کے ہمراہ تشریف لائیں۔ شکریہ!";
                        $waUrl = "https://wa.me/" . $cleanPhone . "?text=" . rawurlencode($waMsg);
                    ?>
                        <tr>
                            <td class="fw-bold"><?php echo $app['id']; ?></td>
                            <td>
                                <img src="<?php echo $photoSrc; ?>" alt="Photo" class="rounded-circle shadow-sm" style="width:45px; height:45px; object-fit:cover;" onerror="this.src='assets/img/default-avatar.png'">
                            </td>
                            <td class="text-start">
                                <strong><?php echo htmlspecialchars($app['student_name'] ?? ''); ?></strong>
                                <?php if(!empty($app['address'])): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($app['address']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($app['father_name'] ?? ''); ?></td>
                            <td dir="ltr">
                                <?php echo $phoneNum; ?>
                                <?php if(!empty($cleanPhone)): ?>
                                    <a href="https://wa.me/<?php echo $cleanPhone; ?>" target="_blank" class="text-success ms-1" title="واٹس ایپ پر رابطہ کریں">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($app['department'] ?? '-'); ?></span></td>
                            <td>
                                <?php 
                                if (!empty($app['date_of_birth']) || !empty($app['dob'])) {
                                    $dobVal = !empty($app['date_of_birth']) ? $app['date_of_birth'] : $app['dob'];
                                    try {
                                        $dob = new DateTime($dobVal);
                                        $now = new DateTime();
                                        $age = $now->diff($dob)->y;
                                        echo $age . " سال";
                                    } catch (Exception $e) { echo "-"; }
                                } else {
                                    echo "-";
                                }
                                ?>
                            </td>
                            <td dir="ltr" class="small"><?php echo !empty($app['created_at']) ? date('d M Y', strtotime($app['created_at'])) : (!empty($app['application_date']) ? date('d M Y', strtotime($app['application_date'])) : '-'); ?></td>
                            <td>
                                <span class="badge <?php echo $badgeClass; ?> rounded-pill" <?php if($currentStatus==='interview') echo 'style="background:#6f42c1;"'; ?>><?php echo $statusLabel; ?></span>
                                <?php if(!empty($app['test_date']) || !empty($app['test_time'])): ?>
                                    <br><small class="text-primary fw-bold">
                                        📅 <?php echo !empty($app['test_date']) ? date('d M Y', strtotime($app['test_date'])) : ''; ?>
                                        <?php if(!empty($app['test_time'])): ?>
                                            ⏰ <?php echo htmlspecialchars($app['test_time']); ?>
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                                <?php if($currentStatus === 'approved'): ?>
                                    <br><small class="text-success">
                                        <?php echo ($app['sms_sent'] ?? 0) ? '✅ SMS بھیجا گیا' : '⚠️ SMS نہیں بھیجا'; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- View Button -->
                                <button class="btn btn-info btn-sm rounded-pill mb-1 text-white" onclick='openViewModal(<?php echo htmlspecialchars(json_encode($app), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye me-1"></i> تفصیل
                                </button>

                                <?php if($currentStatus === 'pending'): ?>
                                    <!-- Schedule Interview -->
                                    <button class="btn btn-sm rounded-pill mb-1 text-white fw-bold" style="background:#6f42c1;"
                                        onclick="openScheduleModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars(addslashes($app['student_name'])); ?>')">
                                        <i class="fas fa-calendar-alt me-1"></i> ٹیسٹ / انٹرویو
                                    </button>
                                    <!-- Direct Approve -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('کیا آپ واقعی یہ داخلہ براہ راست منظور کرنا چاہتے ہیں؟')">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?php echo $app['id']; ?>">
                                        <button class="btn btn-success btn-sm rounded-pill mb-1" type="submit">
                                            <i class="fas fa-check me-1"></i> منظور
                                        </button>
                                    </form>
                                    <!-- Reject Button -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('کیا آپ واقعی یہ درخواست مسترد کرنا چاہتے ہیں؟')">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?php echo $app['id']; ?>">
                                        <button class="btn btn-danger btn-sm rounded-pill mb-1" type="submit">
                                            <i class="fas fa-times me-1"></i> مسترد
                                        </button>
                                    </form>

                                <?php elseif($currentStatus === 'interview'): ?>
                                    <!-- WhatsApp Notice -->
                                    <a href="<?php echo $waUrl; ?>" target="_blank" class="btn btn-success btn-sm rounded-pill mb-1 fw-bold text-white">
                                        <i class="fab fa-whatsapp me-1"></i> واٹس ایپ نوٹس
                                    </a>
                                    <!-- Change Schedule -->
                                    <button class="btn btn-outline-primary btn-sm rounded-pill mb-1"
                                        onclick="openScheduleModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars(addslashes($app['student_name'])); ?>')">
                                        <i class="fas fa-edit me-1"></i> تاریخ تبدیل
                                    </button>
                                    <!-- Approve & Enroll -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('کیا آپ واقعی ٹیسٹ و انٹرویو کے بعد یہ داخلہ باقاعدہ منظور کرنا چاہتے ہیں؟ باضابطہ داخلہ نمبر بن جائے گا۔')">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?php echo $app['id']; ?>">
                                        <button class="btn btn-success btn-sm rounded-pill mb-1 fw-bold" type="submit">
                                            <i class="fas fa-user-check me-1"></i> داخلہ منظور کریں
                                        </button>
                                    </form>
                                    <!-- Reject Button -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('کیا آپ واقعی یہ درخواست مسترد کرنا چاہتے ہیں؟')">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?php echo $app['id']; ?>">
                                        <button class="btn btn-danger btn-sm rounded-pill mb-1" type="submit">
                                            <i class="fas fa-times me-1"></i> مسترد
                                        </button>
                                    </form>

                                <?php elseif($currentStatus === 'approved'): ?>
                                    <?php if(!($app['sms_sent'] ?? 0)): ?>
                                        <button class="btn btn-warning btn-sm rounded-pill" onclick="retrySMS(<?php echo $app['id']; ?>)">
                                            <i class="fas fa-sms me-1"></i> SMS دوبارہ
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Schedule Interview Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:15px;">
            <div class="modal-header" style="background:#6f42c1; color:white;">
                <h5 class="modal-title fw-bold"><i class="fas fa-calendar-check me-2"></i> ٹیسٹ و انٹرویو شیڈول کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="interview">
                <input type="hidden" name="id" id="scheduleAppId">
                <div class="modal-body p-4">
                    <p>امیدوار: <strong id="scheduleStudentName" class="text-primary fs-5"></strong></p>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ٹیسٹ کی تاریخ <span class="text-danger">*</span></label>
                        <input type="date" name="test_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ٹیسٹ کا وقت <span class="text-danger">*</span></label>
                        <input type="time" name="test_time" class="form-control" required value="09:00">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ایڈمن نوٹ (اختیاری)</label>
                        <textarea name="admin_note" class="form-control" rows="2" placeholder="مثلاً: اصل اسناد اور سرپرست کو ساتھ لائیں"></textarea>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        شیڈول محفوظ کرنے کے بعد امیدوار کو <strong>خودکار SMS</strong> جائے گا اور آپ کو <strong>1-کلک واٹس ایپ نوٹس</strong> کا فوری لنک ملے گا۔
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn text-white rounded-pill fw-bold px-4" style="background:#6f42c1;">
                        <i class="fas fa-save me-2"></i> شیڈول محفوظ کریں
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
    let rawPhone = phone.replace(/[^0-9]/g, '');
    let cleanPhone = rawPhone.startsWith('0') ? '92' + rawPhone.substring(1) : rawPhone;
    let waUrl = cleanPhone ? `https://wa.me/${cleanPhone}` : '';

    let body = `
        <div class="row mb-3 align-items-center">
            <div class="col-md-3 text-center">
                <img src="${photo}" alt="Photo" class="img-thumbnail shadow" style="width:120px; height:120px; object-fit:cover; border-radius:50%;" onerror="this.src='assets/img/default-avatar.png'">
            </div>
            <div class="col-md-9">
                <h4 class="text-primary fw-bold">${app.student_name || '-'}</h4>
                <div class="row">
                    <div class="col-md-6 mb-1"><strong>ولدیت:</strong> ${app.father_name || '-'}</div>
                    <div class="col-md-6 mb-1"><strong>تاریخ پیدائش:</strong> ${app.dob || app.date_of_birth || '-'}</div>
                    <div class="col-md-6 mb-1">
                        <strong>فون نمبر:</strong> <span dir="ltr">${phone}</span>
                        ${waUrl ? `<a href="${waUrl}" target="_blank" class="btn btn-sm btn-outline-success ms-2"><i class="fab fa-whatsapp"></i> واٹس ایپ</a>` : ''}
                    </div>
                    <div class="col-md-6 mb-1"><strong>شعبہ:</strong> ${app.department || '-'}</div>
                    ${app.test_date ? `<div class="col-md-6 mb-1 text-primary fw-bold"><strong>ٹیسٹ تاریخ:</strong> ${app.test_date}</div>` : ''}
                    ${app.test_time ? `<div class="col-md-6 mb-1 text-primary fw-bold"><strong>ٹیسٹ وقت:</strong> ${app.test_time}</div>` : ''}
                </div>
            </div>
        </div>
        <hr>
        <div class="row bg-light p-3 rounded mx-0">
            <div class="col-md-6 mb-2"><strong>ای میل:</strong> ${app.email || '-'}</div>
            <div class="col-md-6 mb-2"><strong>موجودہ پتہ:</strong> ${app.address || '-'}</div>
            <div class="col-md-6 mb-2"><strong>مستقل پتہ:</strong> ${app.permanent_address || '-'}</div>
            <div class="col-md-6 mb-2"><strong>سابقہ تعلیم:</strong> ${app.qualification || '-'}</div>
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
            <div class="col-md-6 mb-2"><strong>پتہ:</strong> ${app.guardian_address || '-'}</div>
        </div>
    `;
    document.getElementById('viewModalBody').innerHTML = body;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

function openScheduleModal(id, name) {
    document.getElementById('scheduleAppId').value = id;
    document.getElementById('scheduleStudentName').textContent = name;
    new bootstrap.Modal(document.getElementById('scheduleModal')).show();
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
