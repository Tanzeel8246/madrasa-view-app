<?php
// website_cms.php - ویب سائٹ و سوشل CMS اور نیوز فیڈ مینیجمنٹ
ob_start();
require_once 'db.php';
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'superadmin'], true) || has_perm('manage_website') || has_perm('manage_notices');
if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

ensure_phase3_4_tables($pdo);

$active_sys = $_SESSION['active_system'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
$is_masjid = ($active_sys === 'Masjid');
$cur_tenant_id = get_current_tenant_id($pdo);

$sys_cond = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$success_msg = '';
$error_msg = '';

// --- ACTIONS ---

// 1. نیا اعلان / خبر شامل کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_announcement'])) {
    try {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $cat = trim($_POST['category'] ?? 'خبر');
        $img = trim($_POST['image_url'] ?? '');
        $pDate = $_POST['publish_date'] ?? date('Y-m-d');
        $audience = trim($_POST['target_audience'] ?? 'All');
        $isPub = isset($_POST['is_public']) ? 1 : 0;
        $sys = trim($_POST['system_type'] ?? $active_sys);

        if (empty($title)) throw new Exception('عنوان لکھنا لازمی ہے!');

        $stmt = $pdo->prepare("INSERT INTO announcements (title, body, image_url, category, is_public, target_audience, publish_date, system_type, is_deleted, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW(), ?)");
        $stmt->execute([$title, $body, $img, $cat, $isPub, $audience, $pDate, $sys, $cur_tenant_id]);
        log_activity($pdo, 'cms', "نیا اعلان شامل کیا گیا: $title ($cat)");
        $success_msg = "اعلان / خبر '$title' کامیابی سے شائع ہو گئی!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 2. اعلان حذف کرنا
if (isset($_GET['delete_announcement'])) {
    $delId = (int)$_GET['delete_announcement'];
    $pdo->prepare("UPDATE announcements SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$delId, $cur_tenant_id]);
    log_activity($pdo, 'cms', "اعلان حذف کیا گیا ID: $delId");
    $success_msg = "اعلان کامیابی سے حذف کر دیا گیا!";
}

// 3. نیا ویب پیج بنانا یا اپڈیٹ کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_web_page'])) {
    try {
        $slug = trim($_POST['page_slug'] ?? '');
        $pTitle = trim($_POST['title'] ?? '');
        $content = trim($_POST['content_html'] ?? '');
        $meta = trim($_POST['meta_description'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        $status = trim($_POST['status'] ?? 'Published');

        if (empty($slug) || empty($pTitle)) throw new Exception('صفحے کا عنوان اور سلگ (URL) لازمی ہیں!');

        // Check if slug exists for this tenant
        $check = $pdo->prepare("SELECT id FROM web_pages WHERE page_slug = ? AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ?");
        $check->execute([$slug, $cur_tenant_id]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            $stmt = $pdo->prepare("UPDATE web_pages SET title = ?, content_html = ?, meta_description = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$pTitle, $content, $meta, $sort, $status, $existingId, $cur_tenant_id]);
            $success_msg = "ویب صفحہ '$pTitle' کامیابی سے اپڈیٹ ہو گیا!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO web_pages (page_slug, title, content_html, meta_description, sort_order, status, system_type, is_deleted, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW(), ?)");
            $stmt->execute([$slug, $pTitle, $content, $meta, $sort, $status, $active_sys, $cur_tenant_id]);
            $success_msg = "نیا ویب صفحہ '$pTitle' کامیابی سے بن گیا!";
        }
        log_activity($pdo, 'cms', "ویب صفحہ محفوظ کیا گیا: $slug ($pTitle)");
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Fetch Announcements (Tenant scoped)
$an_stmt = $pdo->prepare("SELECT * FROM announcements WHERE $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? ORDER BY publish_date DESC, id DESC LIMIT 50");
$an_stmt->execute([$cur_tenant_id]);
$announcements = $an_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Web Pages (Tenant scoped)
$pg_stmt = $pdo->prepare("SELECT * FROM web_pages WHERE $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = ? ORDER BY sort_order ASC, id DESC");
$pg_stmt->execute([$cur_tenant_id]);
$pages = $pg_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4" dir="rtl">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold mb-1" style="color: #284D3D;">
                <i class="fas fa-globe me-2 text-warning"></i>
                <?php echo $is_masjid ? 'ویب سائٹ و اعلانات CMS (مسجد)' : 'ویب سائٹ و سوشل CMS (Website & News Feed)'; ?>
            </h3>
            <span class="text-muted">پبلک پورٹل، موبائل ایپ نیوز فیڈ اور اعلانات کا مرکزی انتظام</span>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" target="_blank" class="btn btn-outline-primary fw-bold">
                <i class="fas fa-external-link-alt me-1"></i> پبلک ویب سائٹ دیکھیں
            </a>
            <button class="btn btn-warning fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#addPageModal">
                <i class="fas fa-file-alt me-1"></i> نیا ویب صفحہ
            </button>
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
                <i class="fas fa-bullhorn me-1"></i> نیا اعلان شائع کریں
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

    <!-- Tabs -->
    <ul class="nav nav-pills mb-4 gap-2" id="cmsTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold px-4" id="feed-tab" data-bs-toggle="pill" data-bs-target="#feed-pane">
                <i class="fas fa-bullhorn me-1"></i> اعلانات و خبریں (News Feed)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4" id="pages-tab" data-bs-toggle="pill" data-bs-target="#pages-pane">
                <i class="fas fa-file-code me-1"></i> پبلک ویب پیجز (Custom Pages)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="cmsTabsContent">
        <!-- TAB 1: Announcements Feed -->
        <div class="tab-pane fade show active" id="feed-pane">
            <div class="row g-3">
                <?php if (empty($announcements)): ?>
                    <div class="col-12 text-center py-5 text-muted">کوئی اعلان یا خبر شائع نہیں کی گئی۔</div>
                <?php else: ?>
                    <?php foreach ($announcements as $an): ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="card border-0 shadow-sm rounded-3 h-100 bg-white">
                                <?php if (!empty($an['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($an['image_url']); ?>" class="card-img-top rounded-top-3" style="height: 180px; object-fit: cover;" onerror="this.style.display='none';">
                                <?php endif; ?>
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-success"><?php echo htmlspecialchars($an['category']); ?></span>
                                        <small class="text-muted"><i class="fas fa-calendar-alt me-1"></i> <?php echo htmlspecialchars($an['publish_date']); ?></small>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($an['title']); ?></h5>
                                    <p class="text-muted small mb-3"><?php echo nl2br(htmlspecialchars(mb_substr($an['body'] ?? '', 0, 180))); ?>...</p>

                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                        <span class="badge <?php echo $an['is_public'] ? 'bg-info text-dark' : 'bg-secondary'; ?>">
                                            <i class="fas <?php echo $an['is_public'] ? 'fa-eye' : 'fa-lock'; ?> me-1"></i> <?php echo $an['is_public'] ? 'پبلک' : 'صرف ایپ'; ?>
                                        </span>
                                        <a href="website_cms.php?delete_announcement=<?php echo $an['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ اعلان حذف کرنا چاہتے ہیں؟');">
                                            <i class="fas fa-trash-alt me-1"></i> حذف
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 2: Web Pages -->
        <div class="tab-pane fade" id="pages-pane">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <h5 class="fw-bold mb-3"><i class="fas fa-file-alt me-2 text-primary"></i> پبلک ویب سائٹس کے صفحات</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ترتیب</th>
                                    <th>صفحے کا عنوان</th>
                                    <th>سلگ (URL)</th>
                                    <th>مختصر تفصیل</th>
                                    <th>اسٹیٹس</th>
                                    <th>ایکشن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pages)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">ابھی تک کوئی کسٹم پیج نہیں بنایا گیا۔</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pages as $p): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?php echo $p['sort_order']; ?></span></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($p['title']); ?></td>
                                            <td><code>/<?php echo htmlspecialchars($p['page_slug']); ?></code></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars(mb_substr($p['meta_description'] ?: '-', 0, 50)); ?></td>
                                            <td>
                                                <span class="badge <?php echo $p['status']=='Published'?'bg-success':'bg-warning text-dark'; ?>">
                                                    <?php echo $p['status']=='Published'?'شائع شدہ':'ڈرافٹ'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="editPage(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                                    <i class="fas fa-edit me-1"></i> ترمیم
                                                </button>
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

<!-- Modal: Add Announcement -->
<div class="modal fade" id="addAnnouncementModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-bullhorn me-1"></i> نیا اعلان / خبر شائع کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">عنوان <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required placeholder="مثلاً سالانہ جلسہ دستار فضیلت، چھٹیوں کا اعلان">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">کیٹیگری</label>
                        <select name="category" class="form-select">
                            <option value="خبر">اہم خبر</option>
                            <option value="تقریب">تقریب / جلسہ</option>
                            <option value="سرکلر">سرکلر و ہدایات</option>
                            <option value="سنگِ میل">سنگِ میل و کامیابی</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">اشاعت کی تاریخ</label>
                        <input type="date" name="publish_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">تصویر کا لنک (Image URL)</label>
                    <input type="url" name="image_url" class="form-control" placeholder="https://example.com/photo.jpg">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">تفصیل / مواد</label>
                    <textarea name="body" class="form-control" rows="4" placeholder="مکمل متن یہاں لکھیں..."></textarea>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="is_public" id="is_public_check" checked>
                    <label class="form-check-label fw-bold" for="is_public_check">پبلک ویب سائٹ پر بھی دکھائیں</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="add_announcement" class="btn btn-success fw-bold">شائع کریں</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add / Edit Web Page -->
<div class="modal fade" id="addPageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-code me-1"></i> کسٹم ویب صفحہ (CMS Page)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">صفحے کا عنوان <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="page_title_input" class="form-control" required placeholder="تعارف و تاریخ، شعبہ جات، قواعد و ضوابط">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">سلگ / URL Identifier <span class="text-danger">*</span></label>
                        <input type="text" name="page_slug" id="page_slug_input" class="form-control" required placeholder="about-us, rules, facilities">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">ترتیب نمبر (Sort Order)</label>
                        <input type="number" name="sort_order" id="page_sort_input" class="form-control" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">اسٹیٹس</label>
                        <select name="status" id="page_status_select" class="form-select">
                            <option value="Published">شائع شدہ (Published)</option>
                            <option value="Draft">ڈرافٹ (Draft)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">مختصر میٹا تفصیل</label>
                    <input type="text" name="meta_description" id="page_meta_input" class="form-control" placeholder="گوگل اور سرچ کے لیے مختصر خلاصہ">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">صفحے کا ایچ ٹی ایم ایل مواد (HTML Content)</label>
                    <textarea name="content_html" id="page_content_input" class="form-control font-monospace" rows="8" placeholder="<p>صفحے کا پیراگراف...</p>"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="save_web_page" class="btn btn-warning fw-bold text-dark">صفحہ محفوظ کریں</button>
            </div>
        </form>
    </div>
</div>

<script>
function editPage(page) {
    document.getElementById('page_title_input').value = page.title;
    document.getElementById('page_slug_input').value = page.page_slug;
    document.getElementById('page_sort_input').value = page.sort_order || 0;
    document.getElementById('page_status_select').value = page.status || 'Published';
    document.getElementById('page_meta_input').value = page.meta_description || '';
    document.getElementById('page_content_input').value = page.content_html || '';
    const modal = new bootstrap.Modal(document.getElementById('addPageModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>
