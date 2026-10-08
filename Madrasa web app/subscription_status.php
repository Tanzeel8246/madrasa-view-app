<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سبسکرپشن کیفیت و ادائیگی پورٹل (Subscription Status & Billing Portal)
 *  Madrasa & Masjid Multi-Tenant SaaS
 * ════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$tenantId = (int)($current_tenant_id ?? 1);
$tenantCode = $_SESSION['tenant_code'] ?? 'MAIN01';
$tenantName = $_SESSION['tenant_name'] ?? 'مدرسہ';

// Fetch current subscription and plan
$sub = null;
$plan = null;
try {
    $stmt = $pdo->prepare("
        SELECT s.*, p.name as plan_name, p.name_en, p.description as plan_desc,
               p.price_monthly, p.price_yearly, p.max_students as p_max_students,
               p.max_classes as p_max_classes, p.max_teachers as p_max_teachers,
               p.has_mobile_app, p.has_desktop_app, p.code as plan_code
        FROM subscriptions s
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE s.tenant_id = ?
        ORDER BY s.id DESC LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fallback if no subscription found in table
if (!$sub) {
    $sub = [
        'plan_name' => 'بنیادی پیکج (Basic)',
        'plan_code' => 'basic',
        'status' => 'trial',
        'billing_cycle' => 'monthly',
        'price_monthly' => 1500,
        'price_yearly' => 15000,
        'starts_at' => date('Y-m-d'),
        'ends_at' => date('Y-m-d', strtotime('+14 days')),
        'p_max_students' => 50,
        'p_max_classes' => 3,
        'p_max_teachers' => 4,
        'has_mobile_app' => 1,
        'has_desktop_app' => 1
    ];
}

$isTrial = ($sub['status'] === 'trial');
$isActive = ($sub['status'] === 'active');
$isExpired = ($sub['status'] === 'expired');

$endsAtTs = !empty($sub['ends_at']) ? strtotime($sub['ends_at']) : time();
$daysLeft = max(0, (int)ceil(($endsAtTs - time()) / 86400));

// Live Usage Metrics
$usedStudents = 0;
$usedClasses = 0;
$usedTeachers = 0;

try {
    $stdStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $stdStmt->execute([$tenantId]);
    $usedStudents = (int)$stdStmt->fetchColumn();

    $clsStmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE tenant_id = ? AND (status = 'Active' OR status = '' OR status IS NULL)");
    $clsStmt->execute([$tenantId]);
    $usedClasses = (int)$clsStmt->fetchColumn();

    $teaStmt = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $teaStmt->execute([$tenantId]);
    $usedTeachers = (int)$teaStmt->fetchColumn();
} catch (Throwable $e) {}

$maxStudents = (int)($sub['p_max_students'] ?? 50);
$maxClasses = (int)($sub['p_max_classes'] ?? 3);
$maxTeachers = (int)($sub['p_max_teachers'] ?? 4);

$stdPercent = ($maxStudents > 0) ? min(100, round(($usedStudents / $maxStudents) * 100)) : 0;
$clsPercent = ($maxClasses > 0) ? min(100, round(($usedClasses / $maxClasses) * 100)) : 0;
$teaPercent = ($maxTeachers > 0) ? min(100, round(($usedTeachers / $maxTeachers) * 100)) : 0;

// Trial Progress calculation (14 days base)
$trialTotalDays = 14;
$trialPercent = max(0, min(100, round(($daysLeft / $trialTotalDays) * 100)));
?>

<div class="row g-4 mb-4">
    <!-- Top Status Banner -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="background: linear-gradient(135deg, #064e3b 0%, #0f172a 100%); color: #ffffff;">
            <div class="card-body p-4 p-md-5">
                <div class="row align-items-center g-3">
                    <div class="col-12 col-md-8">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-success bg-opacity-25 border border-success text-success-light px-3 py-1 rounded-pill">
                                <i class="fas fa-fingerprint me-1"></i> ادارہ کوڈ: <?php echo htmlspecialchars($tenantCode); ?>
                            </span>
                            <?php if ($isTrial): ?>
                                <span class="badge bg-danger bg-opacity-75 px-3 py-1 rounded-pill">
                                    <span class="blinking-dot me-1"></span> 14 روزہ مفت آزمائش (Trial)
                                </span>
                            <?php elseif ($isActive): ?>
                                <span class="badge bg-success px-3 py-1 rounded-pill"><i class="fas fa-check-circle me-1"></i> فعال پلان</span>
                            <?php else: ?>
                                <span class="badge bg-secondary px-3 py-1 rounded-pill"><i class="fas fa-clock me-1"></i> غیر فعال / ختم</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="fw-bold mb-2"><?php echo htmlspecialchars($sub['plan_name'] ?? 'بنیادی پیکج'); ?></h2>
                        <p class="text-white-50 mb-0" style="font-size: 0.95rem;">
                            ادارہ: <strong><?php echo htmlspecialchars($tenantName); ?></strong> — سبسکرپشن چارجز و اکاؤنٹ کی موجودہ کیفیت
                        </p>
                    </div>

                    <div class="col-12 col-md-4 text-md-end">
                        <div class="p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-15 d-inline-block text-center" style="min-width: 170px;">
                            <div class="small text-white-50 mb-1">باقی ایام (Days Left)</div>
                            <div class="display-5 fw-bold text-warning"><?php echo $daysLeft; ?> <small class="fs-6 fw-normal text-white">دن</small></div>
                            <div class="small text-white-50 mt-1">اختتام: <?php echo htmlspecialchars($sub['ends_at'] ?? '14 دن'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($isTrial): ?>
                <div class="mt-4 pt-3 border-top border-white border-opacity-10">
                    <div class="d-flex justify-content-between small text-white-50 mb-1">
                        <span>آزمائشی مدت کی پیش رفت</span>
                        <span><?php echo $daysLeft; ?> دن باقی از 14 دن</span>
                    </div>
                    <div class="progress" style="height: 8px; background: rgba(255,255,255,0.15); border-radius: 10px;">
                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $trialPercent; ?>%;" aria-valuenow="<?php echo $trialPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Left Column: Quota & Usage Cards -->
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4">
                <h5 class="fw-bold text-success mb-3">
                    <i class="fas fa-chart-pie me-2"></i> پلان کی مقررہ حدود و استعمال (Usage & Limits)
                </h5>

                <!-- Students Quota -->
                <div class="mb-4 p-3 bg-light rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold"><i class="fas fa-user-graduate text-success me-2"></i> زیرِ تعلیم طلبہ</span>
                        <span class="fw-bold font-monospace text-primary"><?php echo $usedStudents; ?> / <?php echo ($maxStudents > 9000 ? 'لامحدود' : $maxStudents); ?></span>
                    </div>
                    <div class="progress" style="height: 10px; border-radius: 6px;">
                        <div class="progress-bar <?php echo ($stdPercent >= 90) ? 'bg-danger' : 'bg-success'; ?>" style="width: <?php echo $stdPercent; ?>%;"></div>
                    </div>
                    <small class="text-muted mt-1 d-block">پلان کے تحت زیادہ سے زیادہ <?php echo $maxStudents; ?> طلبہ کا ڈیٹا داخل کیا جا سکتا ہے۔</small>
                </div>

                <!-- Classes Quota -->
                <div class="mb-4 p-3 bg-light rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold"><i class="fas fa-chalkboard text-info me-2"></i> درجات و کلاسز</span>
                        <span class="fw-bold font-monospace text-info"><?php echo $usedClasses; ?> / <?php echo ($maxClasses > 9000 ? 'لامحدود' : $maxClasses); ?></span>
                    </div>
                    <div class="progress" style="height: 10px; border-radius: 6px;">
                        <div class="progress-bar <?php echo ($clsPercent >= 90) ? 'bg-danger' : 'bg-info'; ?>" style="width: <?php echo $clsPercent; ?>%;"></div>
                    </div>
                    <small class="text-muted mt-1 d-block">پلان کے تحت <?php echo $maxClasses; ?> کلاسز فعال کی جا سکتی ہیں۔</small>
                </div>

                <!-- Teachers Quota -->
                <div class="mb-4 p-3 bg-light rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold"><i class="fas fa-chalkboard-teacher text-warning me-2"></i> اساتذہ و عملہ</span>
                        <span class="fw-bold font-monospace text-warning"><?php echo $usedTeachers; ?> / <?php echo ($maxTeachers > 9000 ? 'لامحدود' : $maxTeachers); ?></span>
                    </div>
                    <div class="progress" style="height: 10px; border-radius: 6px;">
                        <div class="progress-bar <?php echo ($teaPercent >= 90) ? 'bg-danger' : 'bg-warning'; ?>" style="width: <?php echo $teaPercent; ?>%;"></div>
                    </div>
                    <small class="text-muted mt-1 d-block">پلان کے تحت <?php echo $maxTeachers; ?> اساتذہ و ملازمین کو اکاؤنٹس دیے جا سکتے ہیں۔</small>
                </div>

                <!-- Tri-Platform Support Status -->
                <h6 class="fw-bold text-dark mt-4 mb-3">پلیٹ فارم رسائی کی سہولت:</h6>
                <div class="row g-2">
                    <div class="col-4">
                        <div class="p-2 border rounded-3 text-center bg-white">
                            <i class="fas fa-globe text-success fs-4 mb-1"></i>
                            <div class="small fw-bold">پی ایچ پی ویب</div>
                            <span class="badge bg-success bg-opacity-10 text-success small">فعال</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 border rounded-3 text-center bg-white">
                            <i class="fas fa-mobile-screen <?php echo !empty($sub['has_mobile_app']) ? 'text-info' : 'text-muted'; ?> fs-4 mb-1"></i>
                            <div class="small fw-bold">اینڈرائڈ ایپ</div>
                            <?php if (!empty($sub['has_mobile_app'])): ?>
                                <span class="badge bg-success bg-opacity-10 text-success small">فعال</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger small">غیر فعال</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 border rounded-3 text-center bg-white">
                            <i class="fas fa-desktop <?php echo !empty($sub['has_desktop_app']) ? 'text-warning' : 'text-muted'; ?> fs-4 mb-1"></i>
                            <div class="small fw-bold">ونڈوز ڈیسک ٹاپ</div>
                            <?php if (!empty($sub['has_desktop_app'])): ?>
                                <span class="badge bg-success bg-opacity-10 text-success small">فعال</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger small">غیر فعال</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Official Pricing & Renewal Bank Accounts -->
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4">
                <h5 class="fw-bold text-success mb-3">
                    <i class="fas fa-wallet me-2"></i> سبسکرپشن چارجز و تجدید
                </h5>

                <!-- Price Box -->
                <div class="p-3 rounded-4 mb-3 border bg-success bg-opacity-10">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">ماہانہ فیس:</span>
                        <strong class="fs-5 text-success">روپے <?php echo number_format((float)($sub['price_monthly'] ?? 1500)); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">سالانہ رعایتی پیکیج:</span>
                        <strong class="fs-5 text-dark">روپے <?php echo number_format((float)($sub['price_yearly'] ?? 15000)); ?></strong>
                    </div>
                    <small class="text-muted d-block text-end" style="font-size: 0.75rem;">(سالانہ ادائیگی پر 2 ماہ کی مکمل بچت)</small>
                </div>

                <div class="alert alert-warning border-0 small rounded-3 p-3 mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong>تجدید کا طریقہ:</strong> سبسکرپشن فیس نیچے دیے گئے بینک یا جاز کیش اکاؤنٹ میں ادا کریں اور ٹرانزیکشن کی رسید واٹس ایپ پر ارسال فرمائیں۔ 10 منٹ کے اندر آپ کا اکاؤنٹ مکمل فعال ہو جائے گا۔
                </div>

                <!-- Payment Accounts -->
                <div class="border rounded-3 p-3 mb-3">
                    <h6 class="fw-bold text-dark mb-2"><i class="fas fa-building-columns text-primary me-2"></i> بینک اکاؤنٹ تفصیلات</h6>
                    <table class="table table-sm table-borderless small mb-0">
                        <tr>
                            <td class="text-muted ps-0">بینک:</td>
                            <td class="fw-bold">Meezan Bank Ltd</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0">اکاؤنٹ ٹائٹل:</td>
                            <td class="fw-bold">Madrasa Management SaaS</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0">اکاؤنٹ نمبر:</td>
                            <td class="fw-bold font-monospace text-primary">02830105820491</td>
                        </tr>
                    </table>
                </div>

                <div class="border rounded-3 p-3 mb-4">
                    <h6 class="fw-bold text-dark mb-2"><i class="fas fa-mobile-retro text-danger me-2"></i> جاز کیش / ایزی پیسہ</h6>
                    <table class="table table-sm table-borderless small mb-0">
                        <tr>
                            <td class="text-muted ps-0">JazzCash / EasyPaisa:</td>
                            <td class="fw-bold font-monospace text-danger">0301-2454595</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0">ٹائٹل:</td>
                            <td class="fw-bold">Muhammad Tanzil</td>
                        </tr>
                    </table>
                </div>

                <!-- Direct WhatsApp Action Button -->
                <a href="https://wa.me/923012454595?text=<?php echo urlencode("السلام علیکم! ہم اپنے ادارے [{$tenantName}] (کوڈ: {$tenantCode}) کا سبسکرپشن پلان [{$sub['plan_name']}] ری نیو / ایکٹیویٹ کروانا چاہتے ہیں۔"); ?>" target="_blank" class="btn btn-success w-100 rounded-pill py-2 fw-bold shadow-sm">
                    <i class="fab fa-whatsapp me-2 fs-5 align-middle"></i> واٹس ایپ پر رسید بھیجیں و ایکٹیویٹ کروائیں
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
