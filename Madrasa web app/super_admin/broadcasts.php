<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  سوپر ایڈمن — سسٹم اعلانات و الرٹس (System Broadcasts)
 * ════════════════════════════════════════════════════════════════════
 */

$page_title = 'سسٹم اعلانات و الرٹس (Broadcasts)';
require_once __DIR__ . '/header.php';

$msg = '';
$error = '';

// Handle Create / Delete Broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_broadcast') {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'info';
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

        if (empty($title) || empty($message)) {
            $error = "عنوان اور پیغام درج کرنا لازمی ہے۔";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO saas_broadcasts (title, message, type, is_active, expires_at, created_at) VALUES (?, ?, ?, 1, ?, NOW())");
                $stmt->execute([$title, $message, $type, $expires_at]);
                $msg = "نیا سسٹم اعلان کامیابی سے شائع کر دیا گیا ہے!";
            } catch (Throwable $e) {
                $error = "خرابی: " . $e->getMessage();
            }
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['broadcast_id'] ?? 0);
        $new_status = (int)($_POST['status'] ?? 0);
        try {
            $pdo->prepare("UPDATE saas_broadcasts SET is_active = ? WHERE id = ?")->execute([$new_status, $id]);
            $msg = "اعلان کا اسٹیٹس تبدیل کر دیا گیا۔";
        } catch (Throwable $e) {
            $error = "خرابی: " . $e->getMessage();
        }
    }

    if ($action === 'delete_broadcast') {
        $id = (int)($_POST['broadcast_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM saas_broadcasts WHERE id = ?")->execute([$id]);
            $msg = "اعلان کامیابی سے ڈیلیٹ کر دیا گیا۔";
        } catch (Throwable $e) {
            $error = "خرابی: " . $e->getMessage();
        }
    }
}

// Fetch Broadcasts
$broadcasts = [];
try {
    $broadcasts = $pdo->query("SELECT * FROM saas_broadcasts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
        <h5 class="fw-bold mb-1" style="color: var(--sa-text-main);">تمام اداروں کے لیے عمومی پیغامات</h5>
        <small style="color: var(--sa-text-muted);">مینٹیننس، نئے فیچرز یا ہنگامی اطلاعات جو تمام ٹیننٹس کے پینل پر ظاہر ہوں گی</small>
    </div>
    <button class="btn btn-primary rounded-pill fw-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#createBroadcastModal">
        <i class="fas fa-plus-circle me-1"></i> نیا اعلان جاری کریں
    </button>
</div>

<!-- Broadcasts Table -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نوعیت</th>
                    <th>عنوان</th>
                    <th>پیغام</th>
                    <th>تاریخ اشاعت</th>
                    <th>میعاد ختم</th>
                    <th>اسٹیٹس</th>
                    <th class="text-center">ایکشنز</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($broadcasts)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4" style="color: var(--sa-text-muted);">کوئی سسٹم اعلان موجود نہیں ہے۔</td>
                    </tr>
                <?php else: foreach ($broadcasts as $idx => $b): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td>
                            <?php if ($b['type'] === 'danger'): ?>
                                <span class="badge bg-danger">ہنگامی / اہم</span>
                            <?php elseif ($b['type'] === 'warning'): ?>
                                <span class="badge bg-warning text-dark">انتباہ (Warning)</span>
                            <?php elseif ($b['type'] === 'success'): ?>
                                <span class="badge bg-success">خوشخبری / اپ ڈیٹ</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark">معلومات (Info)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color: var(--sa-text-main);"><?php echo htmlspecialchars($b['title']); ?></strong>
                        </td>
                        <td>
                            <span style="color: var(--sa-text-sub);"><?php echo htmlspecialchars($b['message']); ?></span>
                        </td>
                        <td>
                            <small class="font-monospace"><?php echo date('d M Y', strtotime($b['created_at'])); ?></small>
                        </td>
                        <td>
                            <small class="font-monospace"><?php echo $b['expires_at'] ? date('d M Y', strtotime($b['expires_at'])) : 'ہمیشہ فعال'; ?></small>
                        </td>
                        <td>
                            <?php if ($b['is_active']): ?>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">لائیو</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">روک دیا گیا</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-2">
                                <form method="POST">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="broadcast_id" value="<?php echo $b['id']; ?>">
                                    <input type="hidden" name="status" value="<?php echo $b['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $b['is_active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>" title="<?php echo $b['is_active'] ? 'روکیں' : 'چالو کریں'; ?>">
                                        <i class="fas <?php echo $b['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('کیا آپ واقعی یہ اعلان ڈیلیٹ کرنا چاہتے ہیں؟');">
                                    <input type="hidden" name="action" value="delete_broadcast">
                                    <input type="hidden" name="broadcast_id" value="<?php echo $b['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="ڈیلیٹ">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create Broadcast -->
<div class="modal fade" id="createBroadcastModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_broadcast">
                <div class="modal-header" style="border-color: var(--sa-border);">
                    <h5 class="modal-title fw-bold" style="color: var(--sa-text-main);">
                        <i class="fas fa-bullhorn text-primary me-2"></i> نیا سسٹم اعلان شائع کریں
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label stat-label">عنوان *</label>
                        <input type="text" name="title" class="form-control" required placeholder="مثال: سسٹم اپ ڈیٹ اور مینٹیننس نوٹس">
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">پیغام کی تفصیل *</label>
                        <textarea name="message" class="form-control" rows="3" required placeholder="تمام اداروں کے پینل پر نمایاں پیغام..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">نوعیت (Alert Type)</label>
                        <select name="type" class="form-select">
                            <option value="info">معلوماتی (Info - نیلا)</option>
                            <option value="warning">انتباہ (Warning - پیلا)</option>
                            <option value="danger">ہنگامی / بندش (Danger - سرخ)</option>
                            <option value="success">خوشخبری / نئی سہولت (Success - سبز)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label stat-label">میعاد ختم ہونے کی تاریخ (اختیاری)</label>
                        <input type="date" name="expires_at" class="form-control">
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--sa-border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">شائع کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
