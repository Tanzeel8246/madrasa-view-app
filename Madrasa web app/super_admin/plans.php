<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — سبسکرپشن پلانز (Subscription Plans)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'سبسکرپشن پلانز و قیمتیں (Pricing & Packages)';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';

// Handle Plan Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. نیا پلان شامل کرنا
    if ($action === 'create_plan') {
        $name = trim($_POST['name'] ?? '');
        $code = strtolower(trim($_POST['code'] ?? ''));
        $price = (float)($_POST['price'] ?? 0);
        $max_students = (int)($_POST['max_students'] ?? 100);
        $max_classes = (int)($_POST['max_classes'] ?? 5);
        $max_teachers = (int)($_POST['max_teachers'] ?? 10);
        $has_sms = isset($_POST['has_sms']) ? 1 : 0;
        $has_custom_domain = isset($_POST['has_custom_domain']) ? 1 : 0;
        $has_mobile_app = isset($_POST['has_mobile_app']) ? 1 : 0;
        $has_desktop_app = isset($_POST['has_desktop_app']) ? 1 : 0;

        if (empty($name) || empty($code)) {
            $error = "پلان کا نام اور کوڈ درج کرنا لازمی ہے۔";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO subscription_plans (name, code, price, price_monthly, max_students, max_classes, max_teachers, has_sms, has_custom_domain, has_mobile_app, has_desktop_app, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([$name, $code, $price, $price, $max_students, $max_classes, $max_teachers, $has_sms, $has_custom_domain, $has_mobile_app, $has_desktop_app]);
                $msg = "نیا سبسکرپشن پلان '{$name}' کامیابی سے شامل کر دیا گیا۔";
            } catch (Throwable $e) {
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    // 2. پلان اپڈیٹ کرنا
    if ($action === 'update_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $max_students = (int)($_POST['max_students'] ?? 100);
        $max_classes = (int)($_POST['max_classes'] ?? 5);
        $max_teachers = (int)($_POST['max_teachers'] ?? 10);
        $has_sms = isset($_POST['has_sms']) ? 1 : 0;
        $has_custom_domain = isset($_POST['has_custom_domain']) ? 1 : 0;
        $has_mobile_app = isset($_POST['has_mobile_app']) ? 1 : 0;
        $has_desktop_app = isset($_POST['has_desktop_app']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("UPDATE subscription_plans SET name = ?, price = ?, price_monthly = ?, max_students = ?, max_classes = ?, max_teachers = ?, has_sms = ?, has_custom_domain = ?, has_mobile_app = ?, has_desktop_app = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $price, $price, $max_students, $max_classes, $max_teachers, $has_sms, $has_custom_domain, $has_mobile_app, $has_desktop_app, $is_active, $plan_id]);
            $msg = "پلان '{$name}' کامیابی سے اپڈیٹ کر دیا گیا۔";
        } catch (Throwable $e) {
            $error = "خرابی: " . $e->getMessage();
        }
    }
}

// Fetch all plans with subscriber count
$plans = [];
try {
    $plans = $pdo->query("SELECT p.*, 
                                 (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status IN ('active', 'trial')) as active_subscribers
                          FROM subscription_plans p 
                          ORDER BY p.sort_order ASC, p.price ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<?php if (!empty($msg)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-1" style="color: var(--sa-text-main);">پیکجز، قیمتیں اور صلاحیت</h5>
        <small style="color: var(--sa-text-muted);">مختلف حجم کے اداروں کے لیے ماہانہ چارجز اور طلبہ و اساتذہ کی حد مقرر کریں</small>
    </div>
    <button class="btn btn-primary rounded-pill fw-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#createPlanModal">
        <i class="fas fa-plus-circle me-1"></i> نیا پلان شامل کریں
    </button>
</div>

<!-- Plans Grid -->
<div class="row g-4 mb-4">
    <?php foreach ($plans as $p): ?>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card-custom h-100 d-flex flex-column justify-content-between position-relative">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0" style="color: var(--sa-text-main);"><?php echo htmlspecialchars($p['name']); ?></h5>
                        <?php if ($p['is_active']): ?>
                            <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">فعال</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">غیر فعال</span>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <?php if ($p['price'] <= 0 && empty($p['is_free_trial'])): ?>
                            <h3 class="fw-bold text-info mb-0">قیمت رابطہ پر</h3>
                            <small class="fs-6 fw-normal" style="color: var(--sa-text-muted);">کسٹم ضروریات کے مطابق</small>
                        <?php elseif (!empty($p['is_free_trial'])): ?>
                            <h2 class="fw-bold text-success mb-0">مفت
                                <small class="fs-6 fw-normal" style="color: var(--sa-text-muted);">/ 14 روزہ ٹرائل</small>
                            </h2>
                        <?php else: ?>
                            <h2 class="fw-bold text-primary mb-0">
                                روپے <?php echo number_format($p['price']); ?>
                                <small class="fs-6 fw-normal" style="color: var(--sa-text-muted);">/ ماہ</small>
                            </h2>
                        <?php endif; ?>
                    </div>

                    <ul class="list-unstyled mb-4 small">
                        <li class="mb-2 d-flex align-items-center justify-content-between">
                            <span style="color: var(--sa-text-sub);"><i class="fas fa-users text-primary me-2"></i> طلبہ حد:</span>
                            <strong style="color: var(--sa-text-main);"><?php echo $p['max_students'] > 9000 ? 'لامحدود' : $p['max_students']; ?></strong>
                        </li>
                        <li class="mb-2 d-flex align-items-center justify-content-between">
                            <span style="color: var(--sa-text-sub);"><i class="fas fa-door-open text-primary me-2"></i> کلاسز حد:</span>
                            <strong style="color: var(--sa-text-main);"><?php echo ($p['max_classes'] ?? 5) > 9000 ? 'لامحدود' : ($p['max_classes'] ?? 5); ?></strong>
                        </li>
                        <li class="mb-2 d-flex align-items-center justify-content-between">
                            <span style="color: var(--sa-text-sub);"><i class="fas fa-chalkboard-teacher text-primary me-2"></i> اساتذہ و عملہ:</span>
                            <strong style="color: var(--sa-text-main);"><?php echo $p['max_teachers'] > 9000 ? 'لامحدود' : $p['max_teachers']; ?></strong>
                        </li>
                        <li class="mb-2 d-flex align-items-center justify-content-between">
                            <span style="color: var(--sa-text-sub);"><i class="fas fa-mobile-alt <?php echo !empty($p['has_mobile_app']) ? 'text-success' : 'text-muted'; ?> me-2"></i> موبائل ایپ رسائی:</span>
                            <strong style="color: var(--sa-text-main);"><?php echo !empty($p['has_mobile_app']) ? 'شامل ہے' : 'نہیں (صرف ویب)'; ?></strong>
                        </li>
                        <li class="mb-2 d-flex align-items-center justify-content-between">
                            <span style="color: var(--sa-text-sub);"><i class="fas fa-desktop <?php echo !empty($p['has_desktop_app']) ? 'text-success' : 'text-muted'; ?> me-2"></i> ونڈوز ایپ رسائی:</span>
                            <strong style="color: var(--sa-text-main);"><?php echo !empty($p['has_desktop_app']) ? 'شامل ہے' : 'نہیں (صرف ویب)'; ?></strong>
                        </li>
                        <li class="mb-2 d-flex align-items-center justify-content-between">
                            <span style="color: var(--sa-text-sub);"><i class="fas fa-comment-dots <?php echo !empty($p['has_sms']) ? 'text-success' : 'text-muted'; ?> me-2"></i> SMS الرٹس گیٹ وے:</span>
                            <strong style="color: var(--sa-text-main);"><?php echo !empty($p['has_sms']) ? 'فعال' : 'غیر فعال'; ?></strong>
                        </li>
                    </ul>
                </div>

                <div class="border-top pt-3 mt-2 d-flex justify-content-between align-items-center" style="border-color: var(--sa-border) !important;">
                    <small style="color: var(--sa-text-muted);">
                        <i class="fas fa-building me-1 text-info"></i> <?php echo $p['active_subscribers']; ?> ادارے
                    </small>
                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" 
                            onclick="openEditPlan(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                        ترمیم کریں
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Create Plan -->
<div class="modal fade" id="createPlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_plan">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);"><i class="fas fa-tag me-2 text-primary"></i> نیا سبسکرپشن پلان</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label stat-label">پلان کا نام *</label>
                        <input type="text" name="name" class="form-control" required placeholder="مثال: پرو پیکج (Pro Package)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">پلان کوڈ *</label>
                        <input type="text" name="code" class="form-control font-monospace" required placeholder="pro">
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">ماہانہ قیمت (روپے) *</label>
                        <input type="number" name="price" class="form-control" required placeholder="3000">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label stat-label">طلبہ حد</label>
                            <input type="number" name="max_students" class="form-control" value="100">
                        </div>
                        <div class="col-4">
                            <label class="form-label stat-label">کلاسز حد</label>
                            <input type="number" name="max_classes" class="form-control" value="6">
                        </div>
                        <div class="col-4">
                            <label class="form-label stat-label">اساتذہ حد</label>
                            <input type="number" name="max_teachers" class="form-control" value="10">
                        </div>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="has_mobile_app" id="mobileAppCheck" checked>
                        <label class="form-check-label" for="mobileAppCheck" style="color: var(--sa-text-main);">موبائل ایپ رسائی شامل ہے</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="has_desktop_app" id="desktopAppCheck" checked>
                        <label class="form-check-label" for="desktopAppCheck" style="color: var(--sa-text-main);">ونڈوز ایپ رسائی شامل ہے</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="has_sms" id="smsCheck">
                        <label class="form-check-label" for="smsCheck" style="color: var(--sa-text-main);">SMS گیٹ وے سپورٹ</label>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">پلان محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Plan -->
<div class="modal fade" id="editPlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_plan">
                <input type="hidden" name="plan_id" id="editPlanId">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);"><i class="fas fa-edit me-2 text-primary"></i> پلان میں ترمیم</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label stat-label">پلان کا نام *</label>
                        <input type="text" name="name" id="editPlanName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">ماہانہ قیمت (روپے) *</label>
                        <input type="number" name="price" id="editPlanPrice" class="form-control" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label stat-label">طلبہ حد</label>
                            <input type="number" name="max_students" id="editPlanMaxStudents" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label stat-label">کلاسز حد</label>
                            <input type="number" name="max_classes" id="editPlanMaxClasses" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label stat-label">اساتذہ حد</label>
                            <input type="number" name="max_teachers" id="editPlanMaxTeachers" class="form-control">
                        </div>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="has_mobile_app" id="editMobileApp">
                        <label class="form-check-label" for="editMobileApp" style="color: var(--sa-text-main);">موبائل ایپ رسائی شامل ہے</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="has_desktop_app" id="editDesktopApp">
                        <label class="form-check-label" for="editDesktopApp" style="color: var(--sa-text-main);">ونڈوز ایپ رسائی شامل ہے</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="has_sms" id="editSms">
                        <label class="form-check-label" for="editSms" style="color: var(--sa-text-main);">SMS گیٹ وے سپورٹ</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive" checked>
                        <label class="form-check-label" for="editIsActive" style="color: var(--sa-text-main);">پلان فعال رکھیں (Active)</label>
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">تبدیلیاں محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditPlan(plan) {
    document.getElementById('editPlanId').value = plan.id;
    document.getElementById('editPlanName').value = plan.name;
    document.getElementById('editPlanPrice').value = plan.price;
    document.getElementById('editPlanMaxStudents').value = plan.max_students;
    document.getElementById('editPlanMaxClasses').value = plan.max_classes || 5;
    document.getElementById('editPlanMaxTeachers').value = plan.max_teachers;
    document.getElementById('editMobileApp').checked = plan.has_mobile_app == 1;
    document.getElementById('editDesktopApp').checked = plan.has_desktop_app == 1;
    document.getElementById('editSms').checked = plan.has_sms == 1;
    document.getElementById('editIsActive').checked = plan.is_active == 1;
    new bootstrap.Modal(document.getElementById('editPlanModal')).show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
