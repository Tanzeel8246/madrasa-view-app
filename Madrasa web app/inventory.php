<?php
// inventory.php - سکول شاپ و انونٹری مینیجمنٹ سسٹم مع فنانس انضمام
ob_start();
require_once 'db.php';
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'], true) || has_perm('manage_inventory') || has_perm('manage_shop') || (function_exists('app_has_perm') && (app_has_perm('manage_inventory') || app_has_perm('manage_shop')));
if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

ensure_phase3_4_tables($pdo);

$cur_tenant_id = (int)get_current_tenant_id();

$active_sys = $_SESSION['active_system'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
$is_masjid = ($active_sys === 'Masjid');

$sys_cond = $is_masjid 
    ? "(system_type = 'Masjid' OR system_type = 'Shared')" 
    : "(system_type = 'Madrasa' OR system_type = 'Shared' OR system_type IS NULL OR system_type = '')";

$success_msg = '';
$error_msg = '';

// --- ACTIONS ---

// 1. نیا آئٹم شامل کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    try {
        $code = trim($_POST['item_code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $cat = trim($_POST['category'] ?? 'عام');
        $uPrice = (float)($_POST['unit_price'] ?? 0);
        $pPrice = (float)($_POST['purchase_price'] ?? 0);
        $stock = (int)($_POST['current_stock'] ?? 0);
        $threshold = (int)($_POST['alert_threshold'] ?? 5);
        $unit = trim($_POST['unit'] ?? 'عدد');
        $desc = trim($_POST['description'] ?? '');
        $sys = trim($_POST['system_type'] ?? $active_sys);

        if (empty($code)) {
            $code = 'ITM-' . rand(1000, 9999);
        }

        if (empty($name) || $uPrice <= 0) {
            throw new Exception('آئٹم کا نام اور فروخت قیمت درج کرنا لازمی ہے!');
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventory_items (item_code, name, category, unit_price, purchase_price, current_stock, alert_threshold, unit, description, system_type, tenant_id, is_deleted, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
        ");
        $stmt->execute([$code, $name, $cat, $uPrice, $pPrice, $stock, $threshold, $unit, $desc, $sys, $cur_tenant_id]);
        log_activity($pdo, 'shop', "نئی شاپ آئٹم شامل کی گئی: $name ($code)");
        $success_msg = "نیا آئٹم '$name' کامیابی سے شامل کر دیا گیا!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 2. آئٹم اپڈیٹ / اسٹاک اپڈیٹ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    try {
        $id = (int)($_POST['item_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cat = trim($_POST['category'] ?? 'عام');
        $uPrice = (float)($_POST['unit_price'] ?? 0);
        $pPrice = (float)($_POST['purchase_price'] ?? 0);
        $addStock = (int)($_POST['add_stock'] ?? 0);
        $threshold = (int)($_POST['alert_threshold'] ?? 5);
        $desc = trim($_POST['description'] ?? '');

        if ($id <= 0 || empty($name)) throw new Exception('درخواست نامکمل ہے!');

        $stmt = $pdo->prepare("
            UPDATE inventory_items 
            SET name = ?, category = ?, unit_price = ?, purchase_price = ?, 
                current_stock = current_stock + ?, alert_threshold = ?, description = ?, updated_at = NOW() 
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$name, $cat, $uPrice, $pPrice, $addStock, $threshold, $desc, $id, $cur_tenant_id]);
        log_activity($pdo, 'shop', "شاپ آئٹم اپڈیٹ کی گئی: $name (شامل شدہ اسٹاک: $addStock)");
        $success_msg = "آئٹم کی تفصیلات کامیابی سے محفوظ ہو گئیں!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 3. اشیاء فروخت کرنا (POS Sale with Double-Entry Voucher)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_sale'])) {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $stdId = !empty($_POST['student_id']) ? (int)$_POST['student_id'] : null;
    $cName = trim($_POST['customer_name'] ?? '');
    $mode = trim($_POST['payment_mode'] ?? 'Cash');
    $remarks = trim($_POST['remarks'] ?? '');
    $uid = $_SESSION['user_id'] ?? null;

    if ($stdId && empty($cName)) {
        $std_table = $is_masjid ? 'maktab_students' : 'students';
        $st = $pdo->prepare("SELECT name FROM $std_table WHERE id = ? AND tenant_id = ?");
        $st->execute([$stdId, $cur_tenant_id]);
        $cName = $st->fetchColumn() ?: '';
    }

    $res = record_inventory_sale_with_voucher($pdo, $itemId, $qty, $stdId, $cName, $mode, $remarks, $active_sys, $uid, $cur_tenant_id);
    if ($res['success']) {
        $success_msg = "فروخت کامیابی سے درج ہو گئی! رسید نمبر: " . $res['sale_no'] . " (خودکار فنانس واؤچر شامل کر دیا گیا)";
    } else {
        $error_msg = $res['message'];
    }
}

// 4. ڈیلیٹ آئٹم
if (isset($_GET['delete_item'])) {
    $delId = (int)$_GET['delete_item'];
    $pdo->prepare("UPDATE inventory_items SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$delId, $cur_tenant_id]);
    log_activity($pdo, 'shop', "آئٹم حذف کی گئی ID: $delId");
    $success_msg = "آئٹم کامیابی سے حذف کر دی گئی!";
}

// Data Fetching
$search = trim($_GET['search'] ?? '');
$catFilter = trim($_GET['category'] ?? '');

$where = "$sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id";
$params = [];

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR item_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($catFilter) && $catFilter !== 'all') {
    $where .= " AND category = ?";
    $params[] = $catFilter;
}

$stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE $where ORDER BY name ASC");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$totalItems = count($items);
$totalStock = array_sum(array_column($items, 'current_stock'));
$lowStockCount = 0;
foreach ($items as $it) {
    if ((int)$it['current_stock'] <= (int)$it['alert_threshold']) {
        $lowStockCount++;
    }
}

// Today's Sales
$today = date('Y-m-d');
$todaySales = $pdo->query("SELECT SUM(total_amount) as total, COUNT(*) as count FROM inventory_sales WHERE date = '$today' AND $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id")->fetch(PDO::FETCH_ASSOC);
$todayTotal = (float)($todaySales['total'] ?? 0);
$todayCount = (int)($todaySales['count'] ?? 0);

// Recent Sales
$salesStmt = $pdo->query("SELECT * FROM inventory_sales WHERE $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY id DESC LIMIT 50");
$recentSales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

// Students for Sale Dropdown
$std_table = $is_masjid ? 'maktab_students' : 'students';
$students = $pdo->query("SELECT id, name, admission_number FROM $std_table WHERE status = 'Active' AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4" dir="<?php echo ($lang === 'ur') ? 'rtl' : 'ltr'; ?>">
    <!-- Header Title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold mb-1" style="color: #284D3D;">
                <i class="fas fa-store me-2 text-warning"></i>
                <?php echo $is_masjid ? __('شاپ و کتب خانہ (مسجد / مکتب)', 'Shop & Bookshop (Masjid / Maktab)') : __('سکول شاپ و انونٹری مینیجمنٹ', 'School Shop & Inventory Management'); ?>
            </h3>
            <span class="text-muted"><?php echo __('یونیفارم، درسی کتب، بیجز اور سٹیشنری کا ریکارڈ مع خودکار فنانس ڈبل انٹری', 'Uniform, textbooks, badges & stationery with automated finance double-entry'); ?></span>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-warning fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#posModal">
                <i class="fas fa-cash-register me-1"></i> <?php echo __('فوری فروخت (POS Sale)', 'Quick Sale (POS Sale)'); ?>
            </button>
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fas fa-plus-circle me-1"></i> <?php echo __('نیا آئٹم شامل کریں', 'Add New Item'); ?>
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

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small"><?php echo __('کل آئٹمز کی اقسام', 'Total Item Types'); ?></div>
                        <h3 class="fw-bold text-primary mb-0"><?php echo $totalItems; ?></h3>
                    </div>
                    <i class="fas fa-boxes fa-2x text-primary opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small"><?php echo __('موجودہ کل اسٹاک', 'Total Current Stock'); ?></div>
                        <h3 class="fw-bold text-success mb-0"><?php echo $totalStock; ?></h3>
                    </div>
                    <i class="fas fa-cubes fa-2x text-success opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small"><?php echo __('کم اسٹاک الرٹس', 'Low Stock Alerts'); ?></div>
                        <h3 class="fw-bold text-danger mb-0"><?php echo $lowStockCount; ?></h3>
                    </div>
                    <i class="fas fa-exclamation-circle fa-2x text-danger opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small"><?php echo __('آج کی فروخت (روپے)', 'Today\'s Sales (PKR)'); ?></div>
                        <h3 class="fw-bold text-warning mb-0">Rs. <?php echo number_format($todayTotal, 0); ?></h3>
                    </div>
                    <i class="fas fa-receipt fa-2x text-warning opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-3 gap-2" id="shopTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold px-4" id="items-tab" data-bs-toggle="pill" data-bs-target="#items-pane">
                <i class="fas fa-list me-1"></i> <?php echo __('اشیاء کی فہرست (Items Catalog)', 'Items Catalog'); ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4" id="sales-tab" data-bs-toggle="pill" data-bs-target="#sales-pane">
                <i class="fas fa-history me-1"></i> <?php echo __('فروخت کی ہسٹری (Sales History)', 'Sales History'); ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="shopTabsContent">
        <!-- TAB 1: Items Catalog -->
        <div class="tab-pane fade show active" id="items-pane">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <!-- Filters -->
                    <form method="GET" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" class="form-control" placeholder="<?php echo __('آئٹم کا نام یا کوڈ تلاش کریں...', 'Search item name or code...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-select">
                                <option value="all"><?php echo __('تمام کیٹیگریز', 'All Categories'); ?></option>
                                <option value="کتب" <?php echo $catFilter=='کتب'?'selected':''; ?>><?php echo __('درسی کتب', 'Textbooks'); ?></option>
                                <option value="یونیفارم" <?php echo $catFilter=='یونیفارم'?'selected':''; ?>><?php echo __('یونیفارم / لباس', 'Uniform / Clothing'); ?></option>
                                <option value="بیج" <?php echo $catFilter=='بیج'?'selected':''; ?>><?php echo __('بیجز و ٹوپیاں', 'Badges & Caps'); ?></option>
                                <option value="اسٹیشنری" <?php echo $catFilter=='اسٹیشنری'?'selected':''; ?>><?php echo __('اسٹیشنری و کاپیاں', 'Stationery & Notebooks'); ?></option>
                                <option value="عام" <?php echo $catFilter=='عام'?'selected':''; ?>><?php echo __('دیگر اشیاء', 'Other Items'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> <?php echo __('تلاش کریں', 'Search'); ?></button>
                            <a href="inventory.php" class="btn btn-outline-secondary"><?php echo __('ری سیٹ', 'Reset'); ?></a>
                        </div>
                    </form>

                    <!-- Items Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('کوڈ', 'Code'); ?></th>
                                    <th><?php echo __('نامِ آئٹم', 'Item Name'); ?></th>
                                    <th><?php echo __('کیٹیگری', 'Category'); ?></th>
                                    <th><?php echo __('خرید قیمت', 'Purchase Price'); ?></th>
                                    <th><?php echo __('فروخت قیمت', 'Sale Price'); ?></th>
                                    <th><?php echo __('موجودہ اسٹاک', 'Current Stock'); ?></th>
                                    <th><?php echo __('اسٹیٹس', 'Status'); ?></th>
                                    <th><?php echo __('ایکشن', 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted"><?php echo __('کوئی آئٹم موجود نہیں ہے۔', 'No items found.'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $it): 
                                        $isLow = (int)$it['current_stock'] <= (int)$it['alert_threshold'];
                                    ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($it['item_code']); ?></span></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($it['name']); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($it['category']); ?></span></td>
                                            <td>Rs. <?php echo number_format($it['purchase_price'], 0); ?></td>
                                            <td class="fw-bold text-success">Rs. <?php echo number_format($it['unit_price'], 0); ?></td>
                                            <td>
                                                <span class="fw-bold fs-6 <?php echo $isLow ? 'text-danger' : 'text-dark'; ?>">
                                                    <?php echo $it['current_stock'] . ' ' . $it['unit']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($it['current_stock'] <= 0): ?>
                                                    <span class="badge bg-danger"><?php echo __('ختم ہو چکا', 'Out of Stock'); ?></span>
                                                <?php elseif ($isLow): ?>
                                                    <span class="badge bg-warning text-dark"><?php echo __('اسٹاک کم ہے', 'Low Stock'); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?php echo __('دستیاب', 'In Stock'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-success" onclick="openSaleFor('<?php echo $it['id']; ?>', '<?php echo htmlspecialchars($it['name']); ?>', '<?php echo $it['unit_price']; ?>', '<?php echo $it['current_stock']; ?>')" title="<?php echo __('فروخت کریں', 'Sell'); ?>">
                                                        <i class="fas fa-shopping-cart"></i>
                                                    </button>
                                                    <button class="btn btn-outline-primary" onclick="openEditItem(<?php echo htmlspecialchars(json_encode($it)); ?>)" title="<?php echo __('ترمیم', 'Edit'); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="inventory.php?delete_item=<?php echo $it['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('<?php echo __('کیا آپ واقعی یہ آئٹم حذف کرنا چاہتے ہیں؟', 'Are you sure you want to delete this item?'); ?>');" title="<?php echo __('حذف', 'Delete'); ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
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

        <!-- TAB 2: Sales History -->
        <div class="tab-pane fade" id="sales-pane">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <h5 class="fw-bold mb-3"><i class="fas fa-receipt me-2 text-primary"></i> <?php echo __('حالیہ فروخت کا ریکارڈ', 'Recent Sales Record'); ?></h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('رسید نمبر', 'Receipt #'); ?></th>
                                    <th><?php echo __('تاریخ', 'Date'); ?></th>
                                    <th><?php echo __('نامِ آئٹم', 'Item Name'); ?></th>
                                    <th><?php echo __('خریدار', 'Customer'); ?></th>
                                    <th><?php echo __('تعداد', 'Qty'); ?></th>
                                    <th><?php echo __('قیمت فی عدد', 'Unit Price'); ?></th>
                                    <th><?php echo __('کل رقم', 'Total Amount'); ?></th>
                                    <th><?php echo __('طریقہ ادائیگی', 'Payment Mode'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentSales)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted"><?php echo __('ابھی تک کوئی فروخت درج نہیں ہوئی۔', 'No sales recorded yet.'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentSales as $s): ?>
                                        <tr>
                                            <td><span class="badge bg-dark"><?php echo htmlspecialchars($s['sale_no']); ?></span></td>
                                            <td><?php echo htmlspecialchars($s['date']); ?></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($s['item_name']); ?></td>
                                            <td><?php echo htmlspecialchars($s['customer_name'] ?: __('عام گاہک', 'Walk-in Customer')); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo $s['quantity']; ?></span></td>
                                            <td>Rs. <?php echo number_format($s['unit_price'], 0); ?></td>
                                            <td class="fw-bold text-success">Rs. <?php echo number_format($s['total_amount'], 0); ?></td>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($s['payment_mode']); ?></span></td>
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

<!-- Modal: Add New Item -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-1"></i> <?php echo __('نیا آئٹم شامل کریں', 'Add New Item'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('نامِ آئٹم', 'Item Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="<?php echo __('مثلاً نورانی قاعدہ، قمیص شلوار، اسکول بیگ', 'e.g. Noorani Qaida, Uniform, School Bag'); ?>">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('کیٹیگری', 'Category'); ?></label>
                        <select name="category" class="form-select">
                            <option value="کتب"><?php echo __('درسی کتب', 'Textbooks'); ?></option>
                            <option value="یونیفارم"><?php echo __('یونیفارم / لباس', 'Uniform / Clothing'); ?></option>
                            <option value="بیج"><?php echo __('بیجز و ٹوپیاں', 'Badges & Caps'); ?></option>
                            <option value="اسٹیشنری"><?php echo __('اسٹیشنری و کاپیاں', 'Stationery & Notebooks'); ?></option>
                            <option value="عام"><?php echo __('عام اشیاء', 'General Items'); ?></option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('آئٹم کوڈ (اختیاری)', 'Item Code (Optional)'); ?></label>
                        <input type="text" name="item_code" class="form-control" placeholder="<?php echo __('آٹو جنریٹ', 'Auto Generated'); ?>">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('خرید قیمت', 'Purchase Price'); ?></label>
                        <input type="number" step="0.01" name="purchase_price" class="form-control" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('فروخت قیمت', 'Sale Price'); ?> <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="unit_price" class="form-control" required placeholder="<?php echo __('روپے', 'PKR'); ?>">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('ابتدائی اسٹاک', 'Initial Stock'); ?></label>
                        <input type="number" name="current_stock" class="form-control" value="10">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('الرٹ حد (Low Stock)', 'Low Stock Alert Limit'); ?></label>
                        <input type="number" name="alert_threshold" class="form-control" value="5">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('تفصیل / نوٹس', 'Description / Notes'); ?></label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
                <button type="submit" name="add_item" class="btn btn-success fw-bold"><?php echo __('محفوظ کریں', 'Save Item'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Quick POS Sale -->
<div class="modal fade" id="posModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-cash-register me-1"></i> <?php echo __('فوری فروخت (Point of Sale)', 'Quick POS Sale'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('آئٹم منتخب کریں', 'Select Item'); ?> <span class="text-danger">*</span></label>
                    <select name="item_id" id="pos_item_id" class="form-select" required onchange="updatePosPrice()">
                        <option value=""><?php echo __('-- منتخب کریں --', '-- Select Item --'); ?></option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?php echo $it['id']; ?>" data-price="<?php echo $it['unit_price']; ?>" data-stock="<?php echo $it['current_stock']; ?>">
                                <?php echo htmlspecialchars($it['name']) . ' (' . __('اسٹاک: ', 'Stock: ') . $it['current_stock'] . ', ' . __('قیمت: Rs. ', 'Price: Rs. ') . $it['unit_price'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('تعداد', 'Quantity'); ?> <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="pos_quantity" class="form-control" value="1" min="1" required oninput="calcPosTotal()">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('کل رقم (روپے)', 'Total Amount (PKR)'); ?></label>
                        <input type="text" id="pos_total_display" class="form-control fw-bold text-success" readonly value="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('طالب علم (اختیاری)', 'Student (Optional)'); ?></label>
                    <select name="student_id" class="form-select">
                        <option value=""><?php echo __('-- عام گاہک / کیش کاؤنٹر --', '-- Walk-in Customer / Cash --'); ?></option>
                        <?php foreach ($students as $st): ?>
                            <option value="<?php echo $st['id']; ?>">
                                <?php echo htmlspecialchars($st['name']) . " ({$st['admission_number']})"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('خریدار کا نام (اگر طالب علم نہ ہو)', 'Customer Name (if not student)'); ?></label>
                        <input type="text" name="customer_name" class="form-control" placeholder="<?php echo __('گاہک کا نام', 'Customer Name'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('ادائیگی کا طریقہ', 'Payment Mode'); ?></label>
                        <select name="payment_mode" class="form-select">
                            <option value="Cash"><?php echo __('نقد (Cash)', 'Cash'); ?></option>
                            <option value="Online"><?php echo __('آن لائن / بینک', 'Online / Bank'); ?></option>
                            <option value="Fee Account"><?php echo __('فیس کھاتے میں شامل', 'Add to Fee Account'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="alert alert-info py-2 small mb-0">
                    <i class="fas fa-info-circle me-1"></i> <?php echo __('فروخت درج ہوتے ہی خودکار طور پر ERP فنانس میں کیش ڈیبٹ اور شاپ سیلز کریڈٹ کا واؤچر بن جائے گا۔', 'Recording a sale will automatically create ERP financial voucher (Cash Debit & Shop Sales Credit).'); ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
                <button type="submit" name="record_sale" class="btn btn-warning fw-bold text-dark"><?php echo __('فروخت درج کریں', 'Record Sale'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Item -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="item_id" id="edit_item_id">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-1"></i> <?php echo __('آئٹم میں ترمیم و اسٹاک اضافہ', 'Edit Item & Stock Update'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('نامِ آئٹم', 'Item Name'); ?></label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('کیٹیگری', 'Category'); ?></label>
                        <select name="category" id="edit_category" class="form-select">
                            <option value="کتب"><?php echo __('درسی کتب', 'Textbooks'); ?></option>
                            <option value="یونیفارم"><?php echo __('یونیفارم / لباس', 'Uniform / Clothing'); ?></option>
                            <option value="بیج"><?php echo __('بیجز و ٹوپیاں', 'Badges & Caps'); ?></option>
                            <option value="اسٹیشنری"><?php echo __('اسٹیشنری و کاپیاں', 'Stationery & Notebooks'); ?></option>
                            <option value="عام"><?php echo __('عام اشیاء', 'General Items'); ?></option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('فروخت قیمت', 'Sale Price'); ?></label>
                        <input type="number" step="0.01" name="unit_price" id="edit_unit_price" class="form-control" required>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold"><?php echo __('خرید قیمت', 'Purchase Price'); ?></label>
                        <input type="number" step="0.01" name="purchase_price" id="edit_purchase_price" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold text-success"><?php echo __('+ نیا اسٹاک شامل کریں', '+ Add New Stock'); ?></label>
                        <input type="number" name="add_stock" class="form-control" value="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('الرٹ حد (Low Stock)', 'Low Stock Alert Limit'); ?></label>
                    <input type="number" name="alert_threshold" id="edit_alert_threshold" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo __('تفصیل', 'Description'); ?></label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
                <button type="submit" name="edit_item" class="btn btn-primary fw-bold"><?php echo __('تبدیلیاں محفوظ کریں', 'Save Changes'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function updatePosPrice() {
    calcPosTotal();
}

function calcPosTotal() {
    const sel = document.getElementById('pos_item_id');
    const opt = sel.options[sel.selectedIndex];
    const price = opt ? parseFloat(opt.getAttribute('data-price') || 0) : 0;
    const qty = parseInt(document.getElementById('pos_quantity').value || 1);
    document.getElementById('pos_total_display').value = 'Rs. ' + (price * qty).toLocaleString();
}

function openSaleFor(id, name, price, stock) {
    const sel = document.getElementById('pos_item_id');
    sel.value = id;
    calcPosTotal();
    const modal = new bootstrap.Modal(document.getElementById('posModal'));
    modal.show();
}

function openEditItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_name').value = item.name;
    document.getElementById('edit_category').value = item.category;
    document.getElementById('edit_unit_price').value = item.unit_price;
    document.getElementById('edit_purchase_price').value = item.purchase_price;
    document.getElementById('edit_alert_threshold').value = item.alert_threshold;
    document.getElementById('edit_description').value = item.description || '';
    const modal = new bootstrap.Modal(document.getElementById('editItemModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>
