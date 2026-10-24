<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

$cur_tenant_id = (int)get_current_tenant_id($pdo);
$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Self-healing table schema
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS masjid_itikaf (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(255) DEFAULT '',
        year VARCHAR(50) DEFAULT '',
        status VARCHAR(100) DEFAULT 'معتکف',
        notes TEXT,
        is_synced INT DEFAULT 0,
        is_deleted INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$title = "اعتکاف ریکارڈ - مسجد انتظام";
include 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE masjid_itikaf SET is_deleted = 1, is_synced = 0 WHERE id = ? AND tenant_id = ?")->execute([$id, $cur_tenant_id]);
    } else {
        $name = trim($_POST["name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $year = trim($_POST["year"] ?? date('Y'));
        $status = trim($_POST["status"] ?? "معتکف");

        if (!empty($name)) {
            if (isset($_POST['id']) && !empty($_POST['id'])) {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE masjid_itikaf SET name=?, phone=?, year=?, status=?, is_synced=0 WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $phone, $year, $status, $id, $cur_tenant_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO masjid_itikaf (name, phone, year, status, tenant_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $phone, $year, $status, $cur_tenant_id]);
            }
        }
    }
    header("Location: masjid_itikaf.php");
    exit;
}

$records = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM masjid_itikaf WHERE (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? ORDER BY id DESC");
    $stmt->execute([$cur_tenant_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $records = [];
}
?>

<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h3 class="fw-bold text-success mb-0"><i class="fas fa-user-clock me-2"></i> اعتکاف ریکارڈ (مسجد)</h3>
        <button class="btn btn-success fw-bold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus me-1"></i> نیا معتکف درج کریں
        </button>
    </div>

    <div class="card shadow-sm border-0 neobox">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="table-success">
                    <tr>
                        <th>نام معتکف</th>
                        <th>فون نمبر</th>
                        <th>سال (ہجری / عیسوی)</th>
                        <th>حیثیت (Status)</th>
                        <th>ایکشن</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="5" class="py-4 text-muted">کوئی اعتکاف ریکارڈ موجود نہیں ہے۔</td></tr>
                    <?php else: ?>
                        <?php foreach($records as $row): ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars((string)$row["name"]); ?></td>
                            <td><?php echo htmlspecialchars((string)$row["phone"]); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)$row["year"]); ?></span></td>
                            <td><span class="badge bg-success"><?php echo htmlspecialchars((string)$row["status"]); ?></span></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('کیا آپ واقعی یہ ریکارڈ حذف کرنا چاہتے ہیں؟');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف کریں"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-end" dir="rtl">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-success"><i class="fas fa-user-plus me-1"></i> معتکف کا اندراج</h5>
          <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label fw-bold">نام معتکف</label>
                <input type="text" name="name" class="form-control" required placeholder="معتکف کا نام درج کریں...">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">فون نمبر / رابطہ</label>
                <input type="text" name="phone" class="form-control" placeholder="0300-0000000">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">سال</label>
                <input type="text" name="year" class="form-control" value="<?php echo date('Y'); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">کیفیت / سٹیٹس</label>
                <select name="status" class="form-select">
                    <option value="معتکف (دس دن)">معتکف (آخری عشرہ - 10 دن)</option>
                    <option value="معتکف (پورا مہینہ)">معتکف (پورا مہینہ)</option>
                    <option value="نفلی اعتکاف">نفلی اعتکاف</option>
                </select>
            </div>
        </div>
        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">منسوخ</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">محفوظ کریں</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
