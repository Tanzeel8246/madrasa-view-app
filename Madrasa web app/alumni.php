<?php
// alumni.php - رجسٹر فارغ التحصیل طلباء (Categorized Alumni Register)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage_alumni = in_array($role, ['admin', 'superadmin', 'nazim'], true) || has_permission($role, $_SESSION['permissions'] ?? [], 'manage_students');
if (!$can_manage_alumni) {
    header("Location: dashboard.php");
    exit;
}

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$selected_cat = $_GET['cat'] ?? 'all';

// Category update POST handler
if (isset($_POST['update_alumni_category'])) {
    $std_id = (int)$_POST['student_id'];
    $new_cat = $_POST['new_category'] ?? 'completed';
    $reason = trim($_POST['leaving_reason'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE students SET alumni_category = ?, leaving_reason = ? WHERE id = ?");
    $stmt->execute([$new_cat, $reason, $std_id]);
    
    header("Location: alumni.php?cat=" . urlencode($selected_cat));
    exit;
}

include 'header.php';

if (!function_exists('resolve_alumni_category')) {
    function resolve_alumni_category($s) {
        $cat = strtolower(trim((string)($s['alumni_category'] ?? '')));
        $reason = trim((string)($s['leaving_reason'] ?? ''));
        $status = strtolower(trim((string)($s['status'] ?? '')));

        // 1. Direct category match
        if ($cat === 'expelled' || strpos($cat, 'خارج') !== false || strpos($cat, 'اخراج') !== false) {
            return 'expelled';
        }
        if ($cat === 'dropped' || strpos($cat, 'چھوڑ') !== false || strpos($cat, 'ترک') !== false || strpos($cat, 'شوقیہ') !== false) {
            return 'dropped';
        }

        // 2. Reason or status indicators
        if (strpos($reason, 'خارج') !== false || strpos($reason, 'اخراج') !== false || strpos($status, 'خارج') !== false || strpos($status, 'struck off') !== false || strpos($status, 'expelled') !== false) {
            return 'expelled';
        }
        if (strpos($reason, 'چھوڑ') !== false || strpos($reason, 'شوقیہ') !== false || strpos($reason, 'ترک') !== false || strpos($status, 'left') !== false || strpos($status, 'dropped') !== false) {
            return 'dropped';
        }

        return 'completed';
    }
}

try {
    // Auto-heal dropped & expelled students' categories in DB
    $pdo->exec("UPDATE students SET alumni_category = 'dropped' 
        WHERE (alumni_category IS NULL OR alumni_category = '' OR alumni_category = 'completed') 
          AND (
            LOWER(TRIM(status)) IN ('left', 'dropped') 
            OR leaving_reason LIKE '%چھوڑ%' 
            OR leaving_reason LIKE '%ترک%' 
            OR leaving_reason LIKE '%شوقیہ%'
            OR leaving_reason LIKE '%left%'
            OR leaving_reason LIKE '%drop%'
          )");
    $pdo->exec("UPDATE students SET alumni_category = 'expelled' 
        WHERE (alumni_category IS NULL OR alumni_category = '' OR alumni_category = 'completed') 
          AND (
            LOWER(TRIM(status)) IN ('expelled', 'struck off', 'خارج') 
            OR leaving_reason LIKE '%خارج%' 
            OR leaving_reason LIKE '%اخراج%' 
            OR leaving_reason LIKE '%expel%'
            OR leaving_reason LIKE '%struck%'
          )");
} catch (Throwable $eAlmSync) {}

// Fetch all alumni / left / expelled students
$all_alumni = $pdo->query("SELECT * FROM students WHERE (status = 'Alumni' OR status = 'Left' OR status = 'Struck Off' OR status = 'خارج' OR status = 'فارغ' OR status = 'فارغ التحصیل' OR (graduation_date IS NOT NULL AND graduation_date != '')) AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond ORDER BY graduation_date DESC, id DESC")->fetchAll();

$count_all = count($all_alumni);
$count_completed = 0;
$count_dropped = 0;
$count_expelled = 0;

foreach ($all_alumni as $s) {
    $c = resolve_alumni_category($s);
    if ($c === 'dropped') {
        $count_dropped++;
    } elseif ($c === 'expelled') {
        $count_expelled++;
    } else {
        $count_completed++;
    }
}

if ($selected_cat === 'all') {
    $alumni = $all_alumni;
} else {
    $alumni = array_values(array_filter($all_alumni, function($s) use ($selected_cat) {
        return resolve_alumni_category($s) === $selected_cat;
    }));
}

function get_category_badge($cat) {
    if ($cat === 'dropped') {
        return '<span class="badge bg-warning text-dark px-2 py-1"><i class="fas fa-pause-circle me-1"></i> ترکِ تعلیم</span>';
    } elseif ($cat === 'expelled') {
        return '<span class="badge bg-danger px-2 py-1"><i class="fas fa-user-slash me-1"></i> خارج کردہ</span>';
    }
    return '<span class="badge bg-success px-2 py-1"><i class="fas fa-graduation-cap me-1"></i> تکمیلِ تعلیم</span>';
}

function get_category_name_ur($cat) {
    if ($cat === 'dropped') return 'ترکِ تعلیم (درمیان میں چھوڑنے والے)';
    if ($cat === 'expelled') return 'خارج کردہ (اخراج)';
    return 'تکمیلِ تعلیم (تعلیم مکمل کرنے والے)';
}
?>


<div class="container-fluid py-4 no-print">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-user-graduate me-2 text-primary"></i> رجسٹر فارغ التحصیل طلباء (Alumni Register)</h2>
        <div class="d-flex gap-2 align-items-center">
            <button onclick="window.print()" class="btn btn-dark shadow-sm">
                <i class="fas fa-print me-1"></i> فہرست پرنٹ کریں
            </button>
            <div class="text-muted ms-3">کل تعداد: <span class="badge bg-primary fs-6"><?php echo count($alumni); ?></span></div>
        </div>
    </div>

    <!-- Category Filter Tabs -->
    <div class="card shadow-sm border-0 mb-3 bg-white">
        <div class="card-body p-2">
            <ul class="nav nav-pills gap-2">
                <li class="nav-item">
                    <a class="nav-link <?php echo $selected_cat === 'all' ? 'active bg-dark' : 'text-dark'; ?>" href="alumni.php?cat=all">
                        <i class="fas fa-list me-1"></i> تمام طلباء 
                        <span class="badge bg-secondary ms-1"><?php echo $count_all; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $selected_cat === 'completed' ? 'active bg-success' : 'text-success'; ?>" href="alumni.php?cat=completed">
                        <i class="fas fa-graduation-cap me-1"></i> تعلیم مکمل کرنے والے 
                        <span class="badge bg-success-subtle text-success ms-1"><?php echo $count_completed; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $selected_cat === 'dropped' ? 'active bg-warning text-dark' : 'text-warning'; ?>" href="alumni.php?cat=dropped">
                        <i class="fas fa-pause-circle me-1"></i> درمیان میں چھوڑنے والے (ترکِ تعلیم) 
                        <span class="badge bg-warning-subtle text-warning ms-1"><?php echo $count_dropped; ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $selected_cat === 'expelled' ? 'active bg-danger' : 'text-danger'; ?>" href="alumni.php?cat=expelled">
                        <i class="fas fa-user-slash me-1"></i> خارج کیے جانے والے 
                        <span class="badge bg-danger-subtle text-danger ms-1"><?php echo $count_expelled; ?></span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">نمبر شمار</th>
                            <th>داخلہ نمبر</th>
                            <th>تصویر</th>
                            <th>نام طالب علم</th>
                            <th>ولدیت</th>
                            <th>نوعیت / کیٹیگری</th>
                            <th>داخلہ / فراغت</th>
                            <th>تعلیمی دورانیہ</th>
                            <th>امتحان وفاق</th>
                            <th>محراب (تراویح)</th>
                            <th>وجہ / تفصیلات</th>
                            <th class="text-center no-print">ایکشن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($alumni) > 0): ?>
                            <?php $serialCount = 1; foreach ($alumni as $s): 
                                $cat = resolve_alumni_category($s);
                            ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?php echo $serialCount++; ?></td>
                                    <td class="text-primary fw-bold"><?php echo $s['admission_number']; ?></td>
                                    <td>
                                        <?php if (!empty($s['photo'])): ?>
                                            <img src="<?php echo $s['photo']; ?>" class="rounded shadow-sm" width="40" height="40" style="object-fit: cover;">
                                        <?php else: ?>
                                            <div class="bg-secondary text-white rounded d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="fw-bold fs-6"><?php echo $s['name']; ?></div></td>
                                    <td><?php echo $s['father_name']; ?></td>
                                    <td><?php echo get_category_badge($cat); ?></td>
                                    <td>
                                        <div class="small">داخلہ: <span class="text-muted"><?php echo $s['admission_date']; ?></span></div>
                                        <div class="small">فراغت: <span class="text-success fw-bold"><?php echo $s['graduation_date']; ?></span></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo $s['duration'] ?: '---'; ?></span></td>
                                    <td>
                                        <?php if (!empty($s['wifaq_exam'])): ?>
                                            <span class="badge bg-info text-dark">ہاں <?php echo !empty($s['wifaq_marks']) ? '(' . $s['wifaq_marks'] . ')' : ''; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">نہیں</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['mehrab_recitation'])): ?>
                                            <span class="badge bg-secondary"><?php echo $s['mehrab_recitation']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted" style="max-width: 180px; word-wrap: break-word;">
                                            <?php echo $s['leaving_reason'] ?: 'درج نہیں'; ?>
                                        </div>
                                    </td>
                                    <td class="text-center no-print">
                                        <div class="btn-group">
                                            <?php if ($cat === 'completed'): ?>
                                                <a href="print_sanad.php?student_id=<?php echo $s['id']; ?>" class="btn btn-sm btn-success fw-bold shadow-sm" title="سند الفراغ جاری و پرنٹ کریں" target="_blank">
                                                    <i class="fas fa-award me-1"></i> سند
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning text-dark" title="نوعیت / کیٹیگری تبدیل کریں" onclick="openCatModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars(addslashes($s['name'])); ?>', '<?php echo $cat; ?>', '<?php echo htmlspecialchars(addslashes($s['leaving_reason'] ?? '')); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="print_form.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-info" title="داخلہ فارم دیکھیں" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="students.php?restore=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-secondary" title="بحال کریں" onclick="return confirm('کیا اسے دوبارہ فعال طلباء میں شامل کرنا ہے؟')">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-folder-open fa-3x mb-3"></i>
                                        <p class="fs-5">اس کیٹیگری میں کوئی ریکارڈ موجود نہیں ہے۔</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style>
    .print-only { display: none; }
    @media print {
        .no-print { display: none !important; }
        .print-only { display: block !important; }
        body { background: white !important; font-family: 'Noto Nastaliq Urdu', serif !important; }
        .table { width: 100% !important; border: 1px solid #ccc !important; }
        .table th, .table td { border: 1px solid #eee !important; padding: 8px !important; font-size: 13px !important; }
        .printable-register { padding: 20px; }
        .badge { border: 1px solid #ccc; color: black !important; background: transparent !important; }
    }
</style>

<!-- Hidden Header for Printing -->
<div class="print-only printable-register" dir="rtl">
    <div class="text-center mb-4">
        <h1 style="font-size: 28px; font-weight: bold;">مدرسہ اشرف المدارس فروکہ</h1>
        <h3 style="border-bottom: 2px solid #000; display: inline-block; padding-bottom: 5px;">
            رجسٹر فارغ التحصیل طلباء (<?php echo ($selected_cat === 'all') ? 'تمام کیٹیگریز' : get_category_name_ur($selected_cat); ?>)
        </h3>
        <p class="mt-2">تاریخ رپورٹ: <?php echo date('d-m-Y'); ?> | کل تعداد: <?php echo count($alumni); ?></p>
    </div>
    <table class="table align-middle">
        <thead>
            <tr>
                <th>نمبر شمار</th>
                <th>داخلہ نمبر</th>
                <th>نام طالب علم</th>
                <th>ولدیت</th>
                <th>نوعیت / کیٹیگری</th>
                <th>تاریخ داخلہ</th>
                <th>تاریخ فراغت</th>
                <th>دورانیہ</th>
                <th>امتحان وفاق</th>
                <th>محراب سنائی</th>
                <th>وجہ</th>
            </tr>
        </thead>
        <tbody>
            <?php $count = 1; foreach ($alumni as $s): 
                $cat = resolve_alumni_category($s);
            ?>
                <tr>
                    <td><?php echo $count++; ?></td>
                    <td><?php echo $s['admission_number']; ?></td>
                    <td class="fw-bold"><?php echo $s['name']; ?></td>
                    <td><?php echo $s['father_name']; ?></td>
                    <td><?php echo ($cat === 'dropped' ? 'ترکِ تعلیم' : ($cat === 'expelled' ? 'خارج کردہ' : 'تکمیلِ تعلیم')); ?></td>
                    <td><?php echo $s['admission_date']; ?></td>
                    <td><?php echo $s['graduation_date']; ?></td>
                    <td><?php echo $s['duration']; ?></td>
                    <td><?php echo !empty($s['wifaq_exam']) ? 'ہاں (' . ($s['wifaq_marks'] ?: '-') . ')' : 'نہیں'; ?></td>
                    <td><?php echo $s['mehrab_recitation'] ?: '-'; ?></td>
                    <td><?php echo $s['leaving_reason']; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Change Alumni Category -->
<div class="modal fade" id="alumniCatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content text-end" dir="rtl">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-graduate me-2"></i> طالب علم کی نوعیت / کیٹیگری تبدیل کریں</h5>
                    <button type="button" class="btn-close btn-close-white ms-0" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-start">
                    <input type="hidden" name="student_id" id="modal_cat_std_id">
                    <div class="mb-3 text-end">
                        <label class="fw-bold text-muted">طالب علم:</label>
                        <h5 id="modal_cat_std_name" class="fw-bold text-primary"></h5>
                    </div>
                    <div class="mb-3 text-end">
                        <label class="fw-bold mb-1">نوعیت / کیٹیگری منتخب کریں:</label>
                        <select name="new_category" id="modal_cat_new" class="form-select fw-bold">
                            <option value="completed">🎓 تعلیم مکمل کرنے والے (تکمیلِ تعلیم)</option>
                            <option value="dropped">⏸️ درمیان میں چھوڑنے والے (ترکِ تعلیم)</option>
                            <option value="expelled">❌ مدرسہ سے خارج کردہ (اخراج)</option>
                        </select>
                    </div>
                    <div class="mb-3 text-end">
                        <label class="fw-bold mb-1">تفصیل / وجہ:</label>
                        <textarea name="leaving_reason" id="modal_cat_reason" class="form-control" rows="3" placeholder="مثلاً: شوقیہ چھوڑ گیا، یا نافرمانی پر اخراج"></textarea>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                    <button type="submit" name="update_alumni_category" class="btn btn-success fw-bold px-4"><i class="fas fa-check me-2"></i> محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openCatModal(id, name, cat, reason) {
    document.getElementById('modal_cat_std_id').value = id;
    document.getElementById('modal_cat_std_name').innerText = name;
    document.getElementById('modal_cat_new').value = cat || 'completed';
    document.getElementById('modal_cat_reason').value = reason || '';
    var modal = new bootstrap.Modal(document.getElementById('alumniCatModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>

