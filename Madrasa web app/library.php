<?php
// library.php - لائبریری مینیجمنٹ سسٹم (Library Management)
ob_start();
require_once 'db.php';
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'], true) || has_perm('manage_library') || (function_exists('app_has_perm') && app_has_perm('manage_library'));
if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

ensure_phase2_tables($pdo);

$cur_tenant_id = (int)get_current_tenant_id();

$active_sys = $_SESSION['active_system'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
$is_masjid = ($active_sys === 'Masjid');

// فلٹر اسکوپ برائے کتب
$scope = trim($_GET['scope'] ?? 'mode');
if ($scope === 'all') {
    $sys_cond = "1=1";
    $i_sys_cond = "1=1";
} elseif ($scope === 'shared') {
    $sys_cond = "system_type = 'Shared'";
    $i_sys_cond = "i.system_type = 'Shared'";
} elseif ($scope === 'madrasa') {
    $sys_cond = "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
    $i_sys_cond = "(i.system_type = 'Madrasa' OR i.system_type IS NULL OR i.system_type = '')";
} elseif ($scope === 'masjid') {
    $sys_cond = "system_type = 'Masjid'";
    $i_sys_cond = "i.system_type = 'Masjid'";
} else {
    // Mode default: include mode specific + Shared books
    $sys_cond = $is_masjid 
        ? "(system_type = 'Masjid' OR system_type = 'Shared')" 
        : "(system_type = 'Madrasa' OR system_type = 'Shared' OR system_type IS NULL OR system_type = '')";
    $i_sys_cond = $is_masjid 
        ? "(i.system_type = 'Masjid' OR i.system_type = 'Shared')" 
        : "(i.system_type = 'Madrasa' OR i.system_type = 'Shared' OR i.system_type IS NULL OR i.system_type = '')";
}

$success_msg = '';
$error_msg = '';

// --- ایکشنز (Actions) ---

// 1. نئی کتاب کا اندراج
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book'])) {
    try {
        $title = trim($_POST['book_title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $category = trim($_POST['category'] ?? 'عام');
        $isbn = trim($_POST['isbn'] ?? '');
        $rack = trim($_POST['rack_number'] ?? '');
        $copies = max(1, (int)($_POST['total_copies'] ?? 1));
        $book_sys = trim($_POST['system_type'] ?? $active_sys);
        if (!in_array($book_sys, ['Madrasa', 'Masjid', 'Shared'], true)) {
            $book_sys = $active_sys;
        }

        if (empty($title)) throw new Exception("کتاب کا نام لکھنا لازمی ہے!");

        $stmt = $pdo->prepare("INSERT INTO lib_books 
            (book_title, author, category, isbn, rack_number, total_copies, available_copies, system_type, tenant_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$title, $author, $category, $isbn, $rack, $copies, $copies, $book_sys, $cur_tenant_id]);

        log_activity($pdo, 'library', "نئی کتاب شامل کی گئی: $title ($book_sys)");
        $success_msg = "کتاب کامیابی سے لائبریری میں شامل کر دی گئی!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 2. کتاب کی ترمیم
if (isset($_POST['edit_book'])) {
    try {
        $id = (int)$_POST['book_id'];
        $title = trim($_POST['book_title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $category = trim($_POST['category'] ?? 'عام');
        $isbn = trim($_POST['isbn'] ?? '');
        $rack = trim($_POST['rack_number'] ?? '');
        $copies = max(1, (int)($_POST['total_copies'] ?? 1));
        $book_sys = trim($_POST['system_type'] ?? $active_sys);
        if (!in_array($book_sys, ['Madrasa', 'Masjid', 'Shared'], true)) {
            $book_sys = $active_sys;
        }

        if (empty($title)) throw new Exception("کتاب کا نام لکھنا لازمی ہے!");

        // موجودہ ریکارڈ چیک کریں
        $cur = $pdo->prepare("SELECT total_copies, available_copies FROM lib_books WHERE id = ? AND tenant_id = ?");
        $cur->execute([$id, $cur_tenant_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $diff = $copies - (int)$row['total_copies'];
            $new_avail = max(0, (int)$row['available_copies'] + $diff);

            $stmt = $pdo->prepare("UPDATE lib_books SET book_title=?, author=?, category=?, isbn=?, rack_number=?, total_copies=?, available_copies=?, system_type=?, updated_at=NOW() WHERE id=? AND tenant_id=?");
            $stmt->execute([$title, $author, $category, $isbn, $rack, $copies, $new_avail, $book_sys, $id, $cur_tenant_id]);
            log_activity($pdo, 'library', "کتاب کی تفصیلات اپڈیٹ ہوئیں: $title ($book_sys)");
            $success_msg = "کتاب کی تفصیلات کامیابی سے اپڈیٹ ہو گئیں!";
        }
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 3. کتاب جاری کرنا (Issue Book)
if (isset($_POST['issue_book'])) {
    try {
        $book_id = (int)$_POST['book_id'];
        $borrower_type = $_POST['borrower_type'] ?? 'student';
        $borrower_id = (int)($_POST['borrower_id'] ?? 0);
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
        $remarks = trim($_POST['remarks'] ?? '');

        // قرض دار کا نام معلوم کریں
        $borrower_name = '';
        $borrower_contact = '';
        if ($borrower_type === 'student') {
            $stdTable = $is_masjid ? 'maktab_students' : 'students';
            $sStmt = $pdo->prepare("SELECT name, contact_number FROM $stdTable WHERE id = ? AND tenant_id = ?");
            $sStmt->execute([$borrower_id, $cur_tenant_id]);
            $s = $sStmt->fetch(PDO::FETCH_ASSOC);
            if ($s) {
                $borrower_name = $s['name'];
                $borrower_contact = $s['contact_number'];
            }
        } elseif ($borrower_type === 'teacher') {
            $tTable = $is_masjid ? 'maktab_teachers' : 'teachers';
            $tStmt = $pdo->prepare("SELECT name, phone FROM $tTable WHERE id = ? AND tenant_id = ?");
            $tStmt->execute([$borrower_id, $cur_tenant_id]);
            $t = $tStmt->fetch(PDO::FETCH_ASSOC);
            if ($t) {
                $borrower_name = $t['name'];
                $borrower_contact = $t['phone'];
            }
        }

        if (empty($borrower_name)) {
            $borrower_name = trim($_POST['borrower_custom_name'] ?? '');
            if (empty($borrower_name)) throw new Exception("طالب علم یا استاذ کا انتخاب لازمی ہے!");
        }

        $res = issue_library_book($pdo, $book_id, $borrower_type, $borrower_id, $borrower_name, $borrower_contact, $due_date, $remarks, $active_sys, $cur_tenant_id);
        if (!$res['success']) {
            throw new Exception($res['message']);
        }
        $success_msg = "کتاب کامیابی سے جاری کر دی گئی!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 4. کتاب کی واپسی درج کرنا (Return Book)
if (isset($_POST['return_book'])) {
    try {
        $issue_id = (int)$_POST['issue_id'];
        $fine = floatval($_POST['fine_amount'] ?? 0);
        $remarks = trim($_POST['return_remarks'] ?? '');

        $res = return_library_book($pdo, $issue_id, $fine, $remarks, $cur_tenant_id);
        if (!$res['success']) {
            throw new Exception($res['message']);
        }
        $success_msg = "کتاب کی واپسی کامیابی سے رجسٹر ہو گئی!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// 5. کتاب حذف کرنا
if (isset($_GET['del_book_id']) && $role === 'admin') {
    try {
        $del_id = (int)$_GET['del_book_id'];
        $pdo->prepare("UPDATE lib_books SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$del_id, $cur_tenant_id]);
        log_activity($pdo, 'library', "کتاب #$del_id لائبریری سے حذف کی گئی");
        $success_msg = "کتاب لائبریری سے حذف کر دی گئی!";
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }
}

// شمار برائے سمری کارڈز
$stat_total_titles = 0;
$stat_total_copies = 0;
$stat_avail_copies = 0;
$stat_issued_books = 0;
$stat_overdue_books = 0;
try {
    $stat_total_titles = (int)$pdo->query("SELECT COUNT(*) FROM lib_books WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id")->fetchColumn();
    $stat_total_copies = (int)$pdo->query("SELECT IFNULL(SUM(total_copies), 0) FROM lib_books WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id")->fetchColumn();
    $stat_avail_copies = (int)$pdo->query("SELECT IFNULL(SUM(available_copies), 0) FROM lib_books WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id")->fetchColumn();
    $stat_issued_books = (int)$pdo->query("SELECT COUNT(*) FROM lib_issues WHERE status = 'Issued' AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id")->fetchColumn();
    $stat_overdue_books = (int)$pdo->query("SELECT COUNT(*) FROM lib_issues WHERE status = 'Issued' AND due_date < CURDATE() AND (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id")->fetchColumn();
} catch (Throwable $e) {}

// سرچ و فلٹرز برائے کتب کیٹلاگ
$cat_filter = trim($_GET['cat_filter'] ?? '');
$book_search = trim($_GET['book_search'] ?? '');

$b_where = "(is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id";
$b_params = [];
if ($cat_filter !== '') {
    $b_where .= " AND category = ?";
    $b_params[] = $cat_filter;
}
if ($book_search !== '') {
    $b_where .= " AND (book_title LIKE ? OR author LIKE ? OR rack_number LIKE ? OR isbn LIKE ?)";
    $b_params[] = "%$book_search%";
    $b_params[] = "%$book_search%";
    $b_params[] = "%$book_search%";
    $b_params[] = "%$book_search%";
}

$books_list = [];
try {
    $bStmt = $pdo->prepare("SELECT * FROM lib_books WHERE $b_where ORDER BY id DESC");
    $bStmt->execute($b_params);
    $books_list = $bStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $books_list = [];
}

// جاری شدہ کتب کی لسٹ
$issues_list = [];
try {
    $issuesStmt = $pdo->prepare("
        SELECT i.*, b.book_title, b.author, b.rack_number 
        FROM lib_issues i 
        LEFT JOIN lib_books b ON i.book_id = b.id AND b.tenant_id = $cur_tenant_id
        WHERE (i.is_deleted = 0 OR i.is_deleted IS NULL) AND $i_sys_cond AND i.tenant_id = $cur_tenant_id
        ORDER BY (i.status = 'Issued') DESC, i.due_date ASC
    ");
    $issuesStmt->execute();
    $issues_list = $issuesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $issues_list = [];
}

// طلبہ اور اساتذہ برائے ایشو ڈراپ ڈاؤن
$stdTable = $is_masjid ? 'maktab_students' : 'students';
$tchTable = $is_masjid ? 'maktab_teachers' : 'teachers';
try {
    $students_list = $pdo->query("SELECT id, name, class, contact_number FROM $stdTable WHERE status='Active' AND (is_deleted=0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $students_list = []; }
try {
    $teachers_list = $pdo->query("SELECT id, name, phone FROM $tchTable WHERE (status='Active' OR status='' OR status IS NULL) AND (is_deleted=0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $teachers_list = []; }

$categories = ['قرآن و تفاسیر', 'کتبِ احادیث', 'فقہ و اصول فقہ', 'عربی زبان و ادب', 'سیرت و تاریخ', 'تجوید و قراءت', 'نصابی و درسی کتب', 'اخلاقی و تربیتی', 'عام'];
?>

<div class="container-fluid py-4" style="background: #f8fafc; min-height: 100vh;">
    <!-- پیج ہیڈر -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h2 class="fw-bold text-dark m-0 d-flex align-items-center">
                <i class="fas fa-book-reader text-primary me-2"></i>
                لائبریری مینیجمنٹ سسٹم (Library Management)
                <span class="badge bg-<?php echo $is_masjid ? 'info' : 'success'; ?> ms-3 fs-6">
                    <?php echo $is_masjid ? 'مسجد / مکتب موڈ' : 'مدرسہ موڈ'; ?>
                </span>
            </h2>
            <p class="text-muted m-0 small mt-1">کتب کی کیٹلاگ، اجراء و واپسی رجسٹر، الماری نمبر اور خودکار ڈیو ڈیٹ الرٹس</p>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newBookModal">
                <i class="fas fa-plus-circle me-1"></i> نئی کتاب شامل کریں
            </button>
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#issueBookModal">
                <i class="fas fa-hand-holding me-1"></i> کتاب جاری کریں
            </button>
        </div>
    </div>

    <!-- پیغامات -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 5 شماریاتی کارڈز -->
    <div class="row g-3 mb-4">
        <div class="col-md">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">کل کتب (Titles)</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $stat_total_titles; ?></div>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                        <i class="fas fa-book fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">کل نسخے (Copies)</div>
                        <div class="fs-4 fw-bold text-dark"><?php echo $stat_total_copies; ?></div>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded-circle text-info">
                        <i class="fas fa-copy fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">دستیاب کتب (Available)</div>
                        <div class="fs-4 fw-bold text-success"><?php echo $stat_avail_copies; ?></div>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                        <i class="fas fa-check-circle fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">جاری شدہ (Issued)</div>
                        <div class="fs-4 fw-bold text-warning"><?php echo $stat_issued_books; ?></div>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                        <i class="fas fa-exchange-alt fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold">تاخیر شدہ کتب (Overdue)</div>
                        <div class="fs-4 fw-bold text-danger"><?php echo $stat_overdue_books; ?></div>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
                        <i class="fas fa-exclamation-circle fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- اہم نیویگیشن ٹیبز (Catalog vs Issued Register) -->
    <ul class="nav nav-pills mb-4 border-bottom pb-3 gap-2" id="libTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold px-4 rounded-pill shadow-sm" id="catalog-tab" data-bs-toggle="pill" data-bs-target="#catalog-pane" type="button">
                <i class="fas fa-list me-1"></i> کتب کی کیٹلاگ و انتظام (Books Catalog)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4 rounded-pill shadow-sm position-relative" id="issues-tab" data-bs-toggle="pill" data-bs-target="#issues-pane" type="button">
                <i class="fas fa-history me-1"></i> جاری شدہ کتب و واپسی رجسٹر (Issued Registry)
                <?php if ($stat_overdue_books > 0): ?>
                    <span class="badge bg-danger ms-2 rounded-pill"><?php echo $stat_overdue_books; ?> تاخیر</span>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="libTabContent">
        <!-- ٹیب 1: کتب کیٹلاگ -->
        <div class="tab-pane fade show active" id="catalog-pane" role="tabpanel">
            <!-- سرچ اور فلٹر بار -->
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" name="book_search" class="form-control border-start-0" placeholder="کتاب کا نام، مصنف، یا الماری نمبر تلاش کریں..." value="<?php echo htmlspecialchars($book_search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select name="cat_filter" class="form-select">
                                <option value="">-- تمام کیٹیگریز --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php if ($cat_filter === $cat) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="scope" class="form-select">
                                <option value="mode" <?php if ($scope === 'mode') echo 'selected'; ?>>موجودہ موڈ + مشترکہ کتب</option>
                                <option value="all" <?php if ($scope === 'all') echo 'selected'; ?>>تمام کتب (مشترکہ سمیت)</option>
                                <option value="shared" <?php if ($scope === 'shared') echo 'selected'; ?>>صرف مشترکہ کتب</option>
                                <option value="madrasa" <?php if ($scope === 'madrasa') echo 'selected'; ?>>صرف مدرسہ کتب</option>
                                <option value="masjid" <?php if ($scope === 'masjid') echo 'selected'; ?>>صرف مسجد و مکتب کتب</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-dark w-100 fw-bold">فلٹر کریں</button>
                            <?php if ($book_search !== '' || $cat_filter !== '' || $scope !== 'mode'): ?>
                                <a href="library.php" class="btn btn-outline-secondary">ری سیٹ</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- کتب کا ٹیبل -->
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle m-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>کتاب کا نام</th>
                                <th>مصنف</th>
                                <th>کیٹیگری</th>
                                <th>دائرہ کار</th>
                                <th>الماری / ریک</th>
                                <th class="text-center">کل کاپیاں</th>
                                <th class="text-center">دستیاب</th>
                                <th class="text-center">ایکشنز</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($books_list)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-book-open fa-3x mb-3 opacity-25"></i>
                                        <p class="m-0">کوئی کتاب موجود نہیں ہے</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($books_list as $b): 
                                    $is_avail = (int)$b['available_copies'] > 0;
                                ?>
                                    <tr>
                                        <td class="text-muted small">#<?php echo $b['id']; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($b['book_title']); ?></div>
                                            <?php if (!empty($b['isbn'])): ?>
                                                <small class="text-muted">ISBN: <?php echo htmlspecialchars($b['isbn']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($b['author'] ?: 'نامعلوم'); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($b['category']); ?></span></td>
                                        <td>
                                            <?php if (($b['system_type'] ?? '') === 'Shared'): ?>
                                                <span class="badge" style="background:#6f42c1; color:white;">مشترکہ</span>
                                            <?php elseif (($b['system_type'] ?? '') === 'Masjid'): ?>
                                                <span class="badge bg-info text-dark">مسجد / مکتب</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">مدرسہ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($b['rack_number'] ?: 'غیر متعین'); ?></span></td>
                                        <td class="text-center fw-bold"><?php echo $b['total_copies']; ?></td>
                                        <td class="text-center">
                                            <span class="badge <?php echo $is_avail ? 'bg-success' : 'bg-danger'; ?> px-2 py-1">
                                                <?php echo $b['available_copies']; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($is_avail): ?>
                                                    <button class="btn btn-outline-success" onclick="openDirectIssueModal(<?php echo htmlspecialchars(json_encode($b)); ?>)">
                                                        <i class="fas fa-hand-holding me-1"></i> جاری کریں
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-primary" onclick="openEditBookModal(<?php echo htmlspecialchars(json_encode($b)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($role === 'admin'): ?>
                                                    <a href="library.php?del_book_id=<?php echo $b['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ کتاب ڈیلیٹ کرنا چاہتے ہیں؟');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ٹیب 2: جاری شدہ کتب و واپسی رجسٹر -->
        <div class="tab-pane fade" id="issues-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle m-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>کتاب کا نام</th>
                                <th>قرض دار / طالب علم / استاذ</th>
                                <th>قسم</th>
                                <th>تاریخِ اجراء</th>
                                <th>مقررہ واپسی</th>
                                <th>اسٹیٹس</th>
                                <th class="text-center">ایکشن</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($issues_list)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-history fa-3x mb-3 opacity-25"></i>
                                        <p class="m-0">کوئی جاری شدہ کتاب نہیں ہے</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($issues_list as $iss): 
                                    $is_overdue = ($iss['status'] === 'Issued' && strtotime($iss['due_date']) < strtotime(date('Y-m-d')));
                                ?>
                                    <tr class="<?php if ($is_overdue) echo 'table-danger'; ?>">
                                        <td class="text-muted small">#<?php echo $iss['id']; ?></td>
                                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($iss['book_title']); ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($iss['borrower_name']); ?></div>
                                            <?php if (!empty($iss['borrower_contact'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($iss['borrower_contact']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info text-dark">
                                                <?php echo $iss['borrower_type'] === 'teacher' ? 'استاذ' : 'طالب علم'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($iss['issue_date']); ?></td>
                                        <td>
                                            <span class="fw-bold <?php echo $is_overdue ? 'text-danger' : 'text-dark'; ?>">
                                                <?php echo htmlspecialchars($iss['due_date']); ?>
                                            </span>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge bg-danger ms-1">تاخیر!</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($iss['status'] === 'Issued'): ?>
                                                <span class="badge bg-warning text-dark px-2 py-1">جاری شدہ</span>
                                            <?php else: ?>
                                                <span class="badge bg-success px-2 py-1">واپس وصول شدہ (<?php echo htmlspecialchars($iss['return_date']); ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($iss['status'] === 'Issued'): ?>
                                                <button class="btn btn-sm btn-success fw-bold" onclick="openReturnModal(<?php echo htmlspecialchars(json_encode($iss)); ?>)">
                                                    <i class="fas fa-undo me-1"></i> واپسی درج کریں
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small"><i class="fas fa-check-double text-success"></i> مکمل</span>
                                            <?php endif; ?>
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

<!-- موڈل 1: نئی کتاب -->
<div class="modal fade" id="newBookModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> نئی کتاب کا اندراج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کتاب کا نام <span class="text-danger">*</span></label>
                        <input type="text" name="book_title" class="form-control" required placeholder="مثلاً: تفسیر ابن کثیر">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مصنف / مؤلف</label>
                        <input type="text" name="author" class="form-control" placeholder="مثلاً: علامہ عماد الدین ابن کثیر">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">کیٹیگری</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الماری / ریک نمبر</label>
                            <input type="text" name="rack_number" class="form-control" placeholder="مثلاً: الماری A-1">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">کل کاپیاں (تعداد)</label>
                            <input type="number" name="total_copies" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ISBN / بارکوڈ</label>
                            <input type="text" name="isbn" class="form-control" placeholder="اختیاری">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کتاب کا دائرہ کار (Scope)</label>
                        <select name="system_type" class="form-select">
                            <option value="<?php echo $active_sys; ?>"><?php echo $is_masjid ? 'صرف مسجد / مکتب' : 'صرف مدرسہ'; ?></option>
                            <option value="Shared">مشترکہ (دونوں سسٹمز کے لیے میسر)</option>
                            <option value="<?php echo $is_masjid ? 'Madrasa' : 'Masjid'; ?>"><?php echo $is_masjid ? 'صرف مدرسہ' : 'صرف مسجد / مکتب'; ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="add_book" class="btn btn-primary fw-bold px-4">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- موڈل 2: کتاب جاری کرنا (Issue Book) -->
<div class="modal fade" id="issueBookModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-hand-holding me-2"></i> کتاب کا اجراء (Issue Book)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کتاب منتخب کریں <span class="text-danger">*</span></label>
                        <select name="book_id" id="issue_book_select" class="form-select" required>
                            <option value="">-- کتاب منتخب کریں --</option>
                            <?php foreach ($books_list as $b): if ((int)$b['available_copies'] <= 0) continue; ?>
                                <option value="<?php echo $b['id']; ?>">
                                    <?php echo htmlspecialchars($b['book_title']); ?> (دستیاب: <?php echo $b['available_copies']; ?>) - ریک: <?php echo htmlspecialchars($b['rack_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">قرض دار کی قسم</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="borrower_type" id="type_std" value="student" checked onchange="toggleBorrowerType('student')">
                                <label class="form-check-label fw-bold" for="type_std">طالب علم</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="borrower_type" id="type_tch" value="teacher" onchange="toggleBorrowerType('teacher')">
                                <label class="form-check-label fw-bold" for="type_tch">استاذ محترم</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3" id="student_select_box">
                        <label class="form-label fw-bold">طالب علم منتخب کریں</label>
                        <select name="borrower_id" id="borrower_std_id" class="form-select">
                            <option value="">-- طالب علم چنیں --</option>
                            <?php foreach ($students_list as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (کلاس: <?php echo htmlspecialchars($s['class']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="teacher_select_box">
                        <label class="form-label fw-bold">استاذ محترم منتخب کریں</label>
                        <select name="borrower_id_teacher" id="borrower_tch_id" class="form-select" disabled>
                            <option value="">-- استاذ چنیں --</option>
                            <?php foreach ($teachers_list as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?> (فون: <?php echo htmlspecialchars($t['phone']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مقررہ واپسی کی تاریخ</label>
                        <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اضافی ریمارکس</label>
                        <input type="text" name="remarks" class="form-control" placeholder="اختیاری">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="issue_book" class="btn btn-success fw-bold px-4">کتاب جاری کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- موڈل 3: کتاب واپسی (Return Modal) -->
<div class="modal fade" id="returnBookModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-undo me-2"></i> کتاب کی واپسی درج کریں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="issue_id" id="return_issue_id">
                <div class="modal-body p-4">
                    <div class="alert alert-light border mb-3">
                        <div class="fw-bold fs-6 text-dark" id="return_book_title"></div>
                        <div class="small text-muted" id="return_borrower_name"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">جرمانہ کی رقم (اگر ہو)</label>
                        <input type="number" step="any" name="fine_amount" class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کیفیت / ریمارکس</label>
                        <textarea name="return_remarks" class="form-control" rows="2" placeholder="کتاب کی حالت یا واپسی ریمارکس..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="return_book" class="btn btn-success fw-bold px-4">واپسی کنفرم کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- موڈل 4: کتاب کی ترمیم (Edit Book Modal) -->
<div class="modal fade" id="editBookModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> کتاب کی تفصیلات میں ترمیم</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="book_id" id="edit_book_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">کتاب کا نام <span class="text-danger">*</span></label>
                        <input type="text" name="book_title" id="edit_book_title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">مصنف / مؤلف</label>
                        <input type="text" name="author" id="edit_author" class="form-control">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">کیٹیگری</label>
                            <select name="category" id="edit_category" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الماری / ریک نمبر</label>
                            <input type="text" name="rack_number" id="edit_rack_number" class="form-control">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">کل کاپیاں (تعداد)</label>
                            <input type="number" name="total_copies" id="edit_total_copies" class="form-control" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ISBN / بارکوڈ</label>
                            <input type="text" name="isbn" id="edit_isbn" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">کتاب کا دائرہ کار (Scope)</label>
                        <select name="system_type" id="edit_system_type" class="form-select">
                            <option value="Madrasa">صرف مدرسہ</option>
                            <option value="Masjid">صرف مسجد / مکتب</option>
                            <option value="Shared">مشترکہ (دونوں سسٹمز کے لیے میسر)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                    <button type="submit" name="edit_book" class="btn btn-primary fw-bold px-4">تبدیلیاں محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleBorrowerType(type) {
    const stdBox = document.getElementById('student_select_box');
    const tchBox = document.getElementById('teacher_select_box');
    const stdSelect = document.getElementById('borrower_std_id');
    const tchSelect = document.getElementById('borrower_tch_id');

    if (type === 'student') {
        stdBox.classList.remove('d-none');
        tchBox.classList.add('d-none');
        stdSelect.disabled = false;
        tchSelect.disabled = true;
        tchSelect.name = 'borrower_id_teacher';
        stdSelect.name = 'borrower_id';
    } else {
        stdBox.classList.add('d-none');
        tchBox.classList.remove('d-none');
        stdSelect.disabled = true;
        tchSelect.disabled = false;
        stdSelect.name = 'borrower_id_student';
        tchSelect.name = 'borrower_id';
    }
}

function openDirectIssueModal(book) {
    document.getElementById('issue_book_select').value = book.id;
    new bootstrap.Modal(document.getElementById('issueBookModal')).show();
}

function openEditBookModal(book) {
    document.getElementById('edit_book_id').value = book.id;
    document.getElementById('edit_book_title').value = book.book_title || '';
    document.getElementById('edit_author').value = book.author || '';
    document.getElementById('edit_category').value = book.category || 'عام';
    document.getElementById('edit_rack_number').value = book.rack_number || '';
    document.getElementById('edit_total_copies').value = book.total_copies || 1;
    document.getElementById('edit_isbn').value = book.isbn || '';
    document.getElementById('edit_system_type').value = book.system_type || 'Madrasa';
    new bootstrap.Modal(document.getElementById('editBookModal')).show();
}

function openReturnModal(issue) {
    document.getElementById('return_issue_id').value = issue.id;
    document.getElementById('return_book_title').textContent = "کتاب: " + issue.book_title;
    document.getElementById('return_borrower_name').textContent = "قرض دار: " + issue.borrower_name + " (مقررہ تاریخ: " + issue.due_date + ")";
    new bootstrap.Modal(document.getElementById('returnBookModal')).show();
}
</script>

<?php include 'footer.php'; ?>
