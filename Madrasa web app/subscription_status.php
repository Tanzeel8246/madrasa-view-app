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

// 1. ٹیننٹ کے کوائف اور سسٹم ٹائپ حاصل کریں
$tenantRow = null;
try {
    $tStmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $tStmt->execute([$tenantId]);
    $tenantRow = $tStmt->fetch(PDO::FETCH_ASSOC);
    if ($tenantRow && !empty($tenantRow['name'])) {
        $tenantName = $tenantRow['name'];
        $tenantCode = $tenantRow['code'];
    }
} catch (Throwable $e) {}

// 2. ٹیننٹ کی تازہ ترین فعال سبسکرپشن اور پلان کو درست طریقے سے حاصل کریں
$sub = null;
try {
    // ترجیح 1: فعال یا ٹرائل سبسکرپشن
    $stmt = $pdo->prepare("
        SELECT s.*, p.name as plan_name, p.name_en, p.description as plan_desc,
               p.price_monthly, p.price_yearly, p.max_students as p_max_students,
               p.max_classes as p_max_classes, p.max_teachers as p_max_teachers,
               p.has_mobile_app, p.has_desktop_app, p.code as plan_code
        FROM subscriptions s
        JOIN subscription_plans p ON s.plan_id = p.id
        WHERE s.tenant_id = ? AND s.status IN ('active', 'trial')
        ORDER BY s.id DESC LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    // ترجیح 2: اگر فعال نہ ملے تو آخری سبسکرپشن
    if (!$sub) {
        $stmt2 = $pdo->prepare("
            SELECT s.*, p.name as plan_name, p.name_en, p.description as plan_desc,
                   p.price_monthly, p.price_yearly, p.max_students as p_max_students,
                   p.max_classes as p_max_classes, p.max_teachers as p_max_teachers,
                   p.has_mobile_app, p.has_desktop_app, p.code as plan_code
            FROM subscriptions s
            JOIN subscription_plans p ON s.plan_id = p.id
            WHERE s.tenant_id = ?
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt2->execute([$tenantId]);
        $sub = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

// Fallback if no subscription found in table
if (!$sub) {
    $sub = [
        'plan_name' => 'مفت آزمائش (Free Trial)',
        'plan_code' => 'free',
        'status' => 'trial',
        'billing_cycle' => 'monthly',
        'price_monthly' => 1000,
        'price_yearly' => 10000,
        'starts_at' => date('Y-m-d'),
        'ends_at' => date('Y-m-d', strtotime('+30 days')),
        'p_max_students' => 20,
        'p_max_classes' => 1,
        'p_max_teachers' => 2,
        'has_mobile_app' => 1,
        'has_desktop_app' => 1
    ];
}

$isTrial = ($sub['status'] === 'trial');
$isActive = ($sub['status'] === 'active');
$isExpired = ($sub['status'] === 'expired');

$endsAtTs = !empty($sub['ends_at']) ? strtotime($sub['ends_at']) : time();
$daysLeft = max(0, (int)ceil(($endsAtTs - time()) / 86400));

// 3. لائیو استعمال اور مقررہ حدود کا واحد باضابطہ ذریعہ (Single Source of Truth)
$usage = function_exists('get_tenant_live_usage') ? get_tenant_live_usage($pdo, $tenantId) : ['students' => 0, 'classes' => 0, 'teachers' => 0];
$usedStudents = $usage['students'];
$usedClasses = $usage['classes'];
$usedTeachers = $usage['teachers'];

$planLimits = function_exists('get_tenant_plan_limits') ? get_tenant_plan_limits($pdo, $tenantId) : [];
$maxStudents = (int)($planLimits['max_students'] ?? $sub['p_max_students'] ?? 20);
$maxClasses = (int)($planLimits['max_classes'] ?? $sub['p_max_classes'] ?? 1);
$maxTeachers = (int)($planLimits['max_teachers'] ?? $sub['p_max_teachers'] ?? 2);

$stdPercent = ($maxStudents > 0) ? min(100, round(($usedStudents / $maxStudents) * 100)) : 0;
$clsPercent = ($maxClasses > 0) ? min(100, round(($usedClasses / $maxClasses) * 100)) : 0;
$teaPercent = ($maxTeachers > 0) ? min(100, round(($usedTeachers / $maxTeachers) * 100)) : 0;

// Trial Progress calculation (30 days base)
$trialTotalDays = 30;
$trialPercent = max(0, min(100, round(($daysLeft / $trialTotalDays) * 100)));
$isEn = ($lang === 'en');
?>

<style>
/* 🌟 فول پروف ہائی کنٹراسٹ اسٹائلز برائے سبسکرپشن پورٹل */
.sub-portal-hero {
    background: linear-gradient(135deg, #064e3b 0%, #065f46 50%, #0f172a 100%) !important;
    color: #ffffff !important;
    border-radius: 1.25rem !important;
    box-shadow: 0 10px 25px -5px rgba(6, 78, 59, 0.3) !important;
    padding: 2.25rem 2rem !important;
    position: relative;
    overflow: hidden;
}
.sub-portal-hero h1, 
.sub-portal-hero h2, 
.sub-portal-hero h3, 
.sub-portal-hero h4, 
.sub-portal-hero h5, 
.sub-portal-hero p, 
.sub-portal-hero span:not(.badge), 
.sub-portal-hero strong {
    color: #ffffff !important;
}
.sub-portal-hero .text-dim {
    color: #e2e8f0 !important;
}
.sub-portal-hero .hero-box {
    background: rgba(255, 255, 255, 0.15) !important;
    border: 1.5px solid rgba(255, 255, 255, 0.3) !important;
    backdrop-filter: blur(8px);
    border-radius: 1rem;
    padding: 1.25rem 1.5rem;
}
.sub-portal-hero .hero-days-num {
    color: #fde047 !important; /* روشن سنہری پیلا */
    font-size: 2.75rem;
    font-weight: 800;
    line-height: 1;
}

.sub-portal-card {
    background: #ffffff !important;
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 1.25rem !important;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04) !important;
}
.sub-metric-row {
    background: #f8fafc !important;
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 0.85rem !important;
    padding: 1.1rem 1.25rem !important;
    margin-bottom: 1.25rem !important;
}
.sub-metric-title {
    color: #0f172a !important;
    font-weight: 700 !important;
    font-size: 1.05rem !important;
}
.sub-metric-val {
    font-weight: 800 !important;
    font-size: 1.15rem !important;
}
.sub-metric-desc {
    color: #475569 !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
}

.sub-price-highlight {
    background: #f0fdf4 !important;
    border: 2px solid #86efac !important;
    border-radius: 1rem !important;
    padding: 1.25rem !important;
}
.sub-price-title {
    color: #1e293b !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
}
.sub-price-val {
    color: #15803d !important;
    font-weight: 800 !important;
    font-size: 1.35rem !important;
}
.sub-price-val-dark {
    color: #0f172a !important;
    font-weight: 800 !important;
    font-size: 1.35rem !important;
}

.sub-bank-box {
    background: #f8fafc !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 0.85rem !important;
    padding: 1.1rem 1.25rem !important;
}
.sub-bank-box h6 {
    color: #0f172a !important;
    font-weight: 800 !important;
    font-size: 1.05rem !important;
}
.sub-bank-label {
    color: #475569 !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    width: 35%;
}
.sub-bank-val {
    color: #0f172a !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
}
</style>

<div class="row g-4 mb-4">
    <!-- Top Status Banner (High Contrast & Theme-Proof) -->
    <div class="col-12">
        <div class="sub-portal-hero">
            <div class="row align-items-center g-3">
                <div class="col-12 col-md-8">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge px-3 py-2 rounded-pill font-monospace fw-bold" style="background: rgba(16, 185, 129, 0.3) !important; color: #a7f3d0 !important; border: 1.5px solid #34d399 !important; font-size: 0.85rem;">
                            <i class="fas fa-fingerprint me-1"></i> <?php echo $isEn ? 'Institution Code:' : 'ادارہ کوڈ:'; ?> <?php echo htmlspecialchars($tenantCode); ?>
                        </span>
                        <?php if ($isTrial): ?>
                            <span class="badge px-3 py-2 rounded-pill fw-bold" style="background: #ef4444 !important; color: #ffffff !important; border: 1px solid #f87171 !important;">
                                <span class="blinking-dot me-1"></span> <?php echo $isEn ? '30-Day Free Trial' : '30 روزہ مفت آزمائش (Trial)'; ?>
                            </span>
                        <?php elseif ($isActive): ?>
                            <span class="badge px-3 py-2 rounded-pill fw-bold" style="background: #10b981 !important; color: #ffffff !important; border: 1px solid #6ee7b7 !important;">
                                <i class="fas fa-check-circle me-1"></i> <?php echo $isEn ? 'Active Subscription' : 'فعال سبسکرپشن (Active)'; ?>
                            </span>
                        <?php else: ?>
                            <span class="badge px-3 py-2 rounded-pill fw-bold" style="background: #64748b !important; color: #ffffff !important;">
                                <i class="fas fa-clock me-1"></i> <?php echo $isEn ? 'Expired / Inactive' : 'غیر فعال / ختم شدہ'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1 class="fw-bold mb-2 fs-2"><?php echo htmlspecialchars(($isEn && !empty($sub['name_en'])) ? $sub['name_en'] : ($sub['plan_name'] ?? 'بنیادی پیکج (Basic)')); ?></h1>
                    <p class="text-dim mb-0 fs-6">
                        <?php echo $isEn ? 'Institution:' : 'ادارہ:'; ?> <strong class="text-white"><?php echo htmlspecialchars($tenantName); ?></strong> — <?php echo $isEn ? 'Subscription charges, active features, and quota metrics' : 'سبسکرپشن چارجز، فعال فیچرز اور کوٹہ کیفیت'; ?>
                    </p>
                </div>

                <div class="col-12 col-md-4 text-md-end">
                    <div class="hero-box d-inline-block text-center" style="min-width: 180px;">
                        <div class="small text-dim mb-1 fw-bold"><?php echo $isEn ? 'Days Left' : 'باقی ایام (Days Left)'; ?></div>
                        <div class="hero-days-num"><?php echo $daysLeft; ?> <small class="fs-6 fw-normal text-white"><?php echo $isEn ? 'days' : 'دن'; ?></small></div>
                        <div class="small text-dim mt-2 fw-semibold"><?php echo $isEn ? 'Expiry Date:' : 'تاریخِ اختتام:'; ?> <strong class="text-white font-monospace"><?php echo htmlspecialchars($sub['ends_at'] ?? '30 days'); ?></strong></div>
                    </div>
                </div>
            </div>

            <?php if ($isTrial): ?>
            <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.2) !important;">
                <div class="d-flex justify-content-between small text-dim mb-2 fw-bold">
                    <span><?php echo $isEn ? 'Trial Period Progress' : 'آزمائشی مدت کی پیش رفت'; ?></span>
                    <span><?php echo $isEn ? "$daysLeft days left of 30 days" : "$daysLeft دن باقی از 30 دن"; ?></span>
                </div>
                <div class="progress" style="height: 10px; background: rgba(255,255,255,0.2) !important; border-radius: 10px;">
                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $trialPercent; ?>%;" aria-valuenow="<?php echo $trialPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Left Column: Quota & Usage Cards -->
    <div class="col-12 col-lg-7">
        <div class="sub-portal-card p-4 h-100">
            <h5 class="fw-bold mb-3" style="color: #065f46;">
                <i class="fas fa-chart-pie me-2 text-success"></i> <?php echo $isEn ? 'Quota & Current Usage Limits' : 'پلان کی مقررہ حدود و موجودہ استعمال (Quota & Limits)'; ?>
            </h5>

            <!-- Classes Quota -->
            <div class="sub-metric-row">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="sub-metric-title"><i class="fas fa-chalkboard text-info me-2"></i> <?php echo $isEn ? 'Classes & Grades' : 'درجات و کلاسز'; ?></span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($usedClasses >= $maxClasses && $maxClasses <= 9000): ?>
                            <span class="badge bg-danger rounded-pill px-2 py-1"><i class="fas fa-lock me-1"></i> <?php echo $isEn ? 'Limit Reached' : 'حد مکمل'; ?></span>
                        <?php endif; ?>
                        <span class="sub-metric-val font-monospace <?php echo ($usedClasses >= $maxClasses && $maxClasses <= 9000) ? 'text-danger' : 'text-info'; ?>">
                            <?php echo $usedClasses; ?> / <?php echo ($maxClasses > 9000 ? ($isEn ? 'Unlimited' : 'لامحدود') : $maxClasses); ?>
                        </span>
                    </div>
                </div>
                <div class="progress" style="height: 10px; border-radius: 6px; background: #e2e8f0;">
                    <div class="progress-bar <?php echo ($clsPercent >= 100) ? 'bg-danger' : (($clsPercent >= 80) ? 'bg-warning' : 'bg-info'); ?>" style="width: <?php echo $clsPercent; ?>%;"></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="sub-metric-desc"><?php echo $isEn ? "Up to $maxClasses classes can be created under this plan." : "پلان کے تحت زیادہ سے زیادہ $maxClasses کلاسز فعال کی جا سکتی ہیں۔"; ?></small>
                    <?php if ($usedClasses >= $maxClasses && $maxClasses <= 9000): ?>
                        <small class="text-danger fw-bold"><?php echo $isEn ? 'New class creation is locked' : 'نئی کلاس کا اندراج بلاک ہے'; ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Teachers Quota -->
            <div class="sub-metric-row">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="sub-metric-title"><i class="fas fa-chalkboard-teacher text-warning me-2"></i> <?php echo $isEn ? 'Teachers & Staff' : 'اساتذہ و عملہ'; ?></span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($usedTeachers >= $maxTeachers && $maxTeachers <= 9000): ?>
                            <span class="badge bg-danger rounded-pill px-2 py-1"><i class="fas fa-lock me-1"></i> <?php echo $isEn ? 'Limit Reached' : 'حد مکمل'; ?></span>
                        <?php endif; ?>
                        <span class="sub-metric-val font-monospace <?php echo ($usedTeachers >= $maxTeachers && $maxTeachers <= 9000) ? 'text-danger' : 'text-warning'; ?>">
                            <?php echo $usedTeachers; ?> / <?php echo ($maxTeachers > 9000 ? ($isEn ? 'Unlimited' : 'لامحدود') : $maxTeachers); ?>
                        </span>
                    </div>
                </div>
                <div class="progress" style="height: 10px; border-radius: 6px; background: #e2e8f0;">
                    <div class="progress-bar <?php echo ($teaPercent >= 100) ? 'bg-danger' : (($teaPercent >= 80) ? 'bg-warning' : 'bg-warning'); ?>" style="width: <?php echo $teaPercent; ?>%;"></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="sub-metric-desc"><?php echo $isEn ? "Up to $maxTeachers teachers can be registered under this plan." : "پلان کے تحت $maxTeachers اساتذہ و ملازمین کے اکاؤنٹس بن سکتے ہیں۔"; ?></small>
                    <?php if ($usedTeachers >= $maxTeachers && $maxTeachers <= 9000): ?>
                        <small class="text-danger fw-bold"><?php echo $isEn ? 'New teacher addition is locked' : 'نئے استاد کا اندراج بلاک ہے'; ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Students Quota -->
            <div class="sub-metric-row">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="sub-metric-title"><i class="fas fa-user-graduate text-success me-2"></i> <?php echo $isEn ? 'Enrolled Students' : 'زیرِ تعلیم طلبہ'; ?></span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($usedStudents >= $maxStudents && $maxStudents <= 9000): ?>
                            <span class="badge bg-danger rounded-pill px-2 py-1"><i class="fas fa-lock me-1"></i> <?php echo $isEn ? 'Limit Reached' : 'حد مکمل'; ?></span>
                        <?php endif; ?>
                        <span class="sub-metric-val font-monospace <?php echo ($usedStudents >= $maxStudents && $maxStudents <= 9000) ? 'text-danger' : 'text-success'; ?>">
                            <?php echo $usedStudents; ?> / <?php echo ($maxStudents > 9000 ? ($isEn ? 'Unlimited' : 'لامحدود') : $maxStudents); ?>
                        </span>
                    </div>
                </div>
                <div class="progress" style="height: 10px; border-radius: 6px; background: #e2e8f0;">
                    <div class="progress-bar <?php echo ($stdPercent >= 100) ? 'bg-danger' : (($stdPercent >= 80) ? 'bg-warning' : 'bg-success'); ?>" style="width: <?php echo $stdPercent; ?>%;"></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="sub-metric-desc"><?php echo $isEn ? "Up to $maxStudents students can be enrolled under this plan." : "پلان کے تحت زیادہ سے زیادہ $maxStudents طلبہ کا داخلہ ہو سکتا ہے۔"; ?></small>
                    <?php if ($usedStudents >= $maxStudents && $maxStudents <= 9000): ?>
                        <small class="text-danger fw-bold"><?php echo $isEn ? 'New student admission is locked' : 'نیا داخلہ بلاک ہے'; ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tri-Platform Support Status -->
            <h6 class="fw-bold mt-4 mb-3" style="color: #0f172a;"><?php echo $isEn ? 'Platform Access Status:' : 'پلیٹ فارم رسائی کی سہولت:'; ?></h6>
            <div class="row g-2">
                <div class="col-4">
                    <div class="p-3 border rounded-3 text-center bg-white shadow-sm">
                        <i class="fas fa-globe text-success fs-3 mb-2"></i>
                        <div class="small fw-bold" style="color: #0f172a;"><?php echo $isEn ? 'PHP Web Panel' : 'پی ایچ پی ویب'; ?></div>
                        <span class="badge bg-success px-2 py-1 small"><?php echo $isEn ? 'Active' : 'فعال'; ?></span>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-3 border rounded-3 text-center bg-white shadow-sm">
                        <i class="fas fa-mobile-screen <?php echo !empty($sub['has_mobile_app']) ? 'text-info' : 'text-muted'; ?> fs-3 mb-2"></i>
                        <div class="small fw-bold" style="color: #0f172a;"><?php echo $isEn ? 'Android App' : 'اینڈرائڈ ایپ'; ?></div>
                        <?php if (!empty($sub['has_mobile_app']) || $sub['plan_code'] !== 'free' || (isset($isTrial) && $isTrial)): ?>
                            <span class="badge bg-success px-2 py-1 small"><?php echo $isEn ? 'Active' : 'فعال'; ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger px-2 py-1 small"><?php echo $isEn ? 'Inactive' : 'غیر فعال'; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-3 border rounded-3 text-center bg-white shadow-sm">
                        <i class="fas fa-desktop <?php echo !empty($sub['has_desktop_app']) ? 'text-warning' : 'text-muted'; ?> fs-3 mb-2"></i>
                        <div class="small fw-bold" style="color: #0f172a;"><?php echo $isEn ? 'Windows Desktop' : 'ونڈوز ڈیسک ٹاپ'; ?></div>
                        <?php if (!empty($sub['has_desktop_app']) || $sub['plan_code'] !== 'free' || (isset($isTrial) && $isTrial)): ?>
                            <span class="badge bg-success px-2 py-1 small"><?php echo $isEn ? 'Active' : 'فعال'; ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger px-2 py-1 small"><?php echo $isEn ? 'Inactive' : 'غیر فعال'; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Official Pricing & Renewal Bank Accounts -->
    <div class="col-12 col-lg-5">
        <div class="sub-portal-card p-4 h-100">
            <h5 class="fw-bold mb-3" style="color: #065f46;">
                <i class="fas fa-wallet me-2 text-success"></i> <?php echo $isEn ? 'Subscription Charges & Renewal' : 'سبسکرپشن چارجز و تجدید'; ?>
            </h5>

            <!-- Price Box -->
            <div class="sub-price-highlight mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="sub-price-title"><?php echo $isEn ? 'Monthly Fee:' : 'ماہانہ فیس:'; ?></span>
                    <strong class="sub-price-val"><?php echo $isEn ? 'PKR' : 'روپے'; ?> <?php echo number_format((float)($sub['price_monthly'] ?? 1000)); ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="sub-price-title"><?php echo $isEn ? 'Annual Discounted Package:' : 'سالانہ رعایتی پیکیج:'; ?></span>
                    <strong class="sub-price-val-dark"><?php echo $isEn ? 'PKR' : 'روپے'; ?> <?php echo number_format((float)($sub['price_yearly'] ?? 10000)); ?></strong>
                </div>
                <small class="text-success d-block text-end fw-bold" style="font-size: 0.8rem;"><?php echo $isEn ? '(Save 2 full months on annual payment)' : '(سالانہ ادائیگی پر 2 ماہ کی مکمل بچت)'; ?></small>
            </div>

            <div class="alert alert-warning border-0 small rounded-3 p-3 mb-3" style="color: #78350f !important; background: #fef3c7 !important;">
                <i class="fas fa-info-circle me-1"></i>
                <strong><?php echo $isEn ? 'Renewal Instructions:' : 'تجدید کا طریقہ:'; ?></strong> <?php echo $isEn ? 'Pay the fee to the bank or JazzCash account below and send transaction receipt on WhatsApp. Your account will be activated within 10 minutes.' : 'سبسکرپشن فیس نیچے دیے گئے بینک یا جاز کیش اکاؤنٹ میں ادا کریں اور ٹرانزیکشن کی رسید واٹس ایپ پر ارسال فرمائیں۔ 10 منٹ کے اندر آپ کا اکاؤنٹ مکمل فعال ہو جائے گا۔'; ?>
            </div>

            <!-- Payment Accounts (High Contrast & Clear Typography) -->
            <div class="sub-bank-box mb-3">
                <h6 class="mb-2"><i class="fas fa-building-columns text-primary me-2"></i> <?php echo $isEn ? 'Bank Account Details' : 'بینک اکاؤنٹ تفصیلات'; ?></h6>
                <table class="table table-sm table-borderless small mb-0">
                    <tr>
                        <td class="sub-bank-label ps-0"><?php echo $isEn ? 'Bank:' : 'بینک:'; ?></td>
                        <td class="sub-bank-val">Meezan Bank Ltd</td>
                    </tr>
                    <tr>
                        <td class="sub-bank-label ps-0"><?php echo $isEn ? 'Account Title:' : 'اکاؤنٹ ٹائٹل:'; ?></td>
                        <td class="sub-bank-val">Madrasa Management SaaS</td>
                    </tr>
                    <tr>
                        <td class="sub-bank-label ps-0"><?php echo $isEn ? 'Account No:' : 'اکاؤنٹ نمبر:'; ?></td>
                        <td class="sub-bank-val font-monospace text-primary fs-6">02830105820491</td>
                    </tr>
                </table>
            </div>

            <div class="sub-bank-box mb-4">
                <h6 class="mb-2"><i class="fas fa-mobile-retro text-danger me-2"></i> <?php echo $isEn ? 'JazzCash / EasyPaisa' : 'جاز کیش / ایزی پیسہ'; ?></h6>
                <table class="table table-sm table-borderless small mb-0">
                    <tr>
                        <td class="sub-bank-label ps-0">JazzCash / EasyPaisa:</td>
                        <td class="sub-bank-val font-monospace text-danger fs-6">0301-2454595</td>
                    </tr>
                    <tr>
                        <td class="sub-bank-label ps-0"><?php echo $isEn ? 'Title:' : 'ٹائٹل:'; ?></td>
                        <td class="sub-bank-val">Muhammad Tanzil</td>
                    </tr>
                </table>
            </div>

            <!-- Direct WhatsApp Action Button -->
            <a href="https://wa.me/923012454595?text=<?php echo urlencode("السلام علیکم! ہم اپنے ادارے [{$tenantName}] (کوڈ: {$tenantCode}) کا سبسکرپشن پلان [{$sub['plan_name']}] ری نیو / ایکٹیویٹ کروانا چاہتے ہیں۔"); ?>" target="_blank" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow-sm fs-6" style="background: #16a34a !important; border: none !important;">
                <i class="fab fa-whatsapp me-2 fs-5 align-middle"></i> <?php echo $isEn ? 'Send Receipt on WhatsApp to Activate' : 'واٹس ایپ پر رسید بھیجیں و ایکٹیویٹ کروائیں'; ?>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
