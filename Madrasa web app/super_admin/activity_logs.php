<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — سسٹم سرگرمی لاگ (Activity & Audit Logs)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'سسٹم سرگرمی لاگ (Audit Logs)';
require_once __DIR__ . '/header.php';

$search = trim($_GET['search'] ?? '');
$tenantFilter = (int)($_GET['tenant_id'] ?? 0);

$sql = "SELECT l.*, t.name as tenant_name, t.code as tenant_code 
        FROM tenant_activity_log l 
        LEFT JOIN tenants t ON l.tenant_id = t.id 
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (l.action LIKE ? OR l.details LIKE ? OR l.ip_address LIKE ? OR t.name LIKE ? OR t.code LIKE ?)";
    $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"]);
}
if ($tenantFilter > 0) {
    $sql .= " AND l.tenant_id = ?";
    $params[] = $tenantFilter;
}

$sql .= " ORDER BY l.id DESC LIMIT 150";

$logs = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Tenants for dropdown
$tenantsList = [];
try {
    $tenantsList = $pdo->query("SELECT id, name, code FROM tenants ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<!-- Filter Bar -->
<div class="card-custom mb-4 py-3">
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="text" name="search" class="form-control" style="max-width: 340px;" placeholder="ایکشن، تفصیل، IP، یا ادارہ سے تلاش کریں..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="tenant_id" class="form-select" style="max-width: 220px;">
            <option value="0">تمام ادارے</option>
            <?php foreach($tenantsList as $t): ?>
                <option value="<?php echo $t['id']; ?>" <?php echo $tenantFilter===$t['id']?'selected':''; ?>><?php echo htmlspecialchars($t['name']); ?> (<?php echo htmlspecialchars($t['code']); ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-primary px-3 fw-bold">تلاش کریں</button>
        <?php if(!empty($search) || $tenantFilter > 0): ?>
            <a href="activity_logs.php" class="btn btn-outline-secondary">ری سیٹ</a>
        <?php endif; ?>
    </form>
</div>

<!-- Logs Table -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>#</th>
                    <th>وقت و تاریخ</th>
                    <th>ادارہ (Tenant)</th>
                    <th>نوعیت / ایکشن</th>
                    <th>تفصیلات</th>
                    <th>IP ایڈریس</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4" style="color: var(--sa-text-muted);">کوئی سرگرمی لاگ موجود نہیں ہے۔</td>
                    </tr>
                <?php else: foreach ($logs as $idx => $l): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td>
                            <div class="font-monospace small"><?php echo date('d M Y, h:i A', strtotime($l['created_at'])); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold" style="color: var(--sa-text-main);"><?php echo htmlspecialchars($l['tenant_name'] ?: 'مرکزی نظام'); ?></div>
                            <?php if (!empty($l['tenant_code'])): ?>
                                <span class="badge bg-secondary bg-opacity-25 text-secondary font-monospace small"><?php echo htmlspecialchars($l['tenant_code']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace">
                                <?php echo htmlspecialchars($l['action']); ?>
                            </span>
                        </td>
                        <td>
                            <span style="color: var(--sa-text-main);"><?php echo htmlspecialchars($l['details'] ?: '-'); ?></span>
                        </td>
                        <td>
                            <span class="font-monospace small" style="color: var(--sa-text-muted);"><?php echo htmlspecialchars($l['ip_address'] ?: '-'); ?></span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
