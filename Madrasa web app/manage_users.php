<?php
// manage_users.php
session_start();
require_once 'db.php';
require_once 'finance_accounting_bridge.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin) {
    header("Location: dashboard.php");
    exit;
}

$cur_tenant_id = (int)get_current_tenant_id();
$msg = '';
$err = '';

// Handle add / edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user'])) {
        $id = $_POST['user_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? 'admin';
        $password = $_POST['password'] ?? '';
        $rawPerms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? array_values($_POST['permissions']) : [];
        if ($role === 'admin') {
            $rawPerms = !empty($rawPerms) ? array_values(array_unique(array_merge(['all'], $rawPerms))) : ['all'];
        } elseif ($role === 'nazim') {
            $nazimBase = [
                'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
                'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
                'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
                'manage_students', 'manage_teachers', 'manage_classes',
                'manage_finance_entry', 'manage_finance_report',
                'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
                'manage_masjid_attendance', 'manage_masjid_teacher_attendance', 'manage_masjid_attendance_report',
                'manage_masjid_hifz', 'manage_masjid_hifz_daily', 'manage_masjid_hifz_report',
                'manage_masjid_academics', 'manage_masjid_academic_dashboard', 'manage_masjid_academic_entry', 'manage_masjid_academic_view',
                'manage_masjid_students', 'manage_masjid_teachers', 'manage_masjid_classes',
                'manage_masjid_finance_entry', 'manage_masjid_finance_report',
                'manage_masjid_requests', 'manage_masjid_notices', 'manage_masjid_admissions', 'manage_masjid_complaints', 'manage_masjid_add_complaint'
            ];
            $rawPerms = array_values(array_unique(array_merge($nazimBase, $rawPerms)));
        }
        $permissions = json_encode($rawPerms);
        $allowed_classes = isset($_POST['allowed_classes']) ? json_encode(array_values($_POST['allowed_classes'])) : '[]';

        try {
            if ($id) { // update
                if (!empty($password)) {
                    $hashed = hash_pass($password);
                    $stmt = $pdo->prepare("UPDATE admins SET name=?, username=?, role=?, permissions=?, allowed_classes=?, password=? WHERE id=? AND tenant_id=?");
                    $stmt->execute([$name, $username, $role, $permissions, $allowed_classes, $hashed, $id, $cur_tenant_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE admins SET name=?, username=?, role=?, permissions=?, allowed_classes=? WHERE id=? AND tenant_id=?");
                    $stmt->execute([$name, $username, $role, $permissions, $allowed_classes, $id, $cur_tenant_id]);
                }
                if (function_exists('finance_ensure_user_canonical_accounts')) {
                    finance_ensure_user_canonical_accounts($pdo, $id, $name, $username, $cur_tenant_id);
                }
                $msg = "یوزر کامیابی سے اپڈیٹ ہو گیا۔";
            } else { // insert
                $hashed = hash_pass($password);
                $stmt = $pdo->prepare("INSERT INTO admins (name, username, password, role, permissions, allowed_classes, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $username, $hashed, $role, $permissions, $allowed_classes, $cur_tenant_id]);
                $newUserId = (int)$pdo->lastInsertId();
                if (function_exists('finance_ensure_user_canonical_accounts')) {
                    finance_ensure_user_canonical_accounts($pdo, $newUserId, $name, $username, $cur_tenant_id);
                }
                $msg = "نیا یوزر شامل کر دیا گیا۔ اس صارف کے لیے خرچ کھاتہ اور نقد وصولی کھاتہ بھی خودکار و محفوظ طریقے سے تیار کر دیے گئے۔";
            }
        } catch (PDOException $e) {
            $err = "ڈیٹا بیس ایرر: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = (int)$_POST['delete_user'];
        if ($id === 1) {
            $err = "مرکزی ایڈمن اکاؤنٹ (تنزیل الرحمن) کو کسی بھی صورت میں حذف نہیں کیا جا سکتا۔";
        } elseif ($id != $_SESSION['user_id']) { // Don't delete self
            $stmt = $pdo->prepare("DELETE FROM admins WHERE id=? AND tenant_id=?");
            $stmt->execute([$id, $cur_tenant_id]);
            $msg = "یوزر حذف کر دیا گیا۔";
        } else {
            $err = "آپ اپنا اکاؤنٹ ڈیلیٹ نہیں کر سکتے۔";
        }
    }
}

// Fetch users and classes isolated by tenant
try {
    $stmtUsers = $pdo->prepare("SELECT * FROM admins WHERE tenant_id = ? ORDER BY id");
    $stmtUsers->execute([$cur_tenant_id]);
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = $pdo->query("SELECT * FROM admins ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $stmtClasses = $pdo->prepare("SELECT * FROM classes WHERE tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY system_type ASC, name ASC");
    $stmtClasses->execute([$cur_tenant_id]);
    $all_classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

include 'header.php';

// Categorized Permissions
$madrasa_perms = [
    'manage_classes' => __('کلاسز کا انتظام', 'Manage Classes'),
    'manage_students' => __('طلباء کا ریکارڈ', 'Student Records'),
    'manage_teachers' => __('اساتذہ کا ریکارڈ', 'Teacher Records'),
    'manage_attendance' => __('طالب علموں کی حاضری', 'Student Attendance'),
    'manage_teacher_attendance' => __('اساتذہ کی حاضری', 'Teacher Attendance'),
    'manage_attendance_report' => __('حاضری تفصیلی رپورٹ', 'Detailed Attendance Report'),
    'manage_hifz' => __('حفظ (روزانہ اندراج و رپورٹ)', 'Hifz (Daily Entry & Report)'),
    'manage_hifz_daily' => __('حفظ (روزانہ اندراج)', 'Hifz (Daily Entry)'),
    'manage_hifz_report' => __('حفظ (تعلیمی رپورٹ)', 'Hifz (Academic Report)'),
    'manage_academic_dashboard' => __('تعلیمی کارکردگی (اندراج ڈیش بورڈ)', 'Academic Performance (Entry Dashboard)'),
    'manage_academic_entry' => __('تعلیمی رپورٹ (اندراج)', 'Academic Report (Entry)'),
    'manage_academic_view' => __('تعلیمی کارکردگی (رپورٹ دیکھیں)', 'Academic Performance (View Report)'),
    'manage_academics' => __('تعلیمی رپورٹ (جنرل)', 'Academic Report (General)'),
    'manage_exams' => __('امتحانات و نتائج', 'Exams & Results'),
    'manage_finance' => __('آمدن و خرچ (سادہ فنانس - تمام)', 'Income & Expense (Simple Finance - All)'),
    'manage_finance_entry' => __('آمدن و خرچ اندراج', 'Income & Expense Entry'),
    'manage_finance_report' => __('مالیاتی رپورٹ', 'Financial Report'),
    'manage_financial_reports' => __('مالیاتی تفصیلی گوشوارے و سالانہ رپورٹ', 'Detailed Financial Statements & Annual Report'),
    'manage_fee_defaulters' => __('فیس و واجبات / نادہندگان', 'Fees & Dues / Defaulters'),
    'manage_loans' => __('ایڈوانس و قرض مینیجمنٹ', 'Advance & Loan Management'),
    'manage_stipends' => __('وظائف مینیجمنٹ', 'Stipends Management'),
    'manage_assets' => __('اثاثہ جات (Assets - مدرسہ)', 'Assets (Madrasa Assets)'),
    'manage_in_kind_donations' => __('اشیاء و عطیات (In-Kind Donations - مدرسہ)', 'In-Kind Donations (Madrasa)'),
    'manage_petty_cash' => __('پیٹی کیش رجسٹر', 'Petty Cash Register'),
    'manage_erp' => __('ای آر پی اکاؤنٹس (ERP Accounting)', 'ERP Accounting'),
    'manage_admissions' => __('داخلہ درخواستیں', 'Admission Applications'),
    'manage_notices' => __('نوٹس بورڈ / اعلانات', 'Notice Board / Announcements'),
    'manage_requests' => __('درخواستیں و شکایات', 'Requests & Complaints'),
    'add_complaint' => __('شکایت اندراج', 'Submit Complaint'),
    'manage_complaints' => __('شکایات ریکارڈ', 'Complaints Record'),
    'manage_library' => __('لائبریری مینیجمنٹ (Library Management)', 'Library Management'),
    'manage_inventory' => __('سکول شاپ و انونٹری (School Shop & Inventory)', 'School Shop & Inventory'),
    'manage_hostel' => __('ہاسٹل مینیجمنٹ (Hostel Management)', 'Hostel Management'),
    'manage_recruitment' => __('بھرتی و تقرری (HR & Recruitment)', 'HR & Recruitment'),
    'manage_website' => __('ویب سائٹ و سوشل CMS (Website CMS)', 'Website CMS'),
    'manage_saas' => __('سپر ایڈمن پورٹل (Super Admin Portal SaaS)', 'Super Admin Portal (SaaS)'),
    'manage_users' => __('یوزرز کا انتظام (User Management)', 'User Management'),
];

$masjid_perms = [
    'manage_masjid_classes' => __('مکتب: کلاسز کا انتظام', 'Maktab: Manage Classes'),
    'manage_masjid_students' => __('مکتب: طلباء کا ریکارڈ', 'Maktab: Student Records'),
    'manage_masjid_teachers' => __('مکتب: اساتذہ کا ریکارڈ', 'Maktab: Teacher Records'),
    'manage_masjid_attendance' => __('مکتب: طالب علموں کی حاضری', 'Maktab: Student Attendance'),
    'manage_masjid_teacher_attendance' => __('مکتب: اساتذہ کی حاضری', 'Maktab: Teacher Attendance'),
    'manage_masjid_attendance_report' => __('مکتب: حاضری تفصیلی رپورٹ', 'Maktab: Detailed Attendance Report'),
    'manage_masjid_hifz' => __('مکتب: حفظ (روزانہ اندراج و رپورٹ)', 'Maktab: Hifz (Daily Entry & Report)'),
    'manage_masjid_hifz_daily' => __('مکتب: حفظ (روزانہ اندراج)', 'Maktab: Hifz (Daily Entry)'),
    'manage_masjid_hifz_report' => __('مکتب: حفظ (تعلیمی رپورٹ)', 'Maktab: Hifz (Academic Report)'),
    'manage_masjid_academic_dashboard' => __('مکتب: تعلیمی کارکردگی (اندراج ڈیش بورڈ)', 'Maktab: Academic Performance (Entry Dashboard)'),
    'manage_masjid_academic_entry' => __('مکتب: تعلیمی رپورٹ (اندراج)', 'Maktab: Academic Report (Entry)'),
    'manage_masjid_academic_view' => __('مکتب: تعلیمی کارکردگی (رپورٹ دیکھیں)', 'Maktab: Academic Performance (View Report)'),
    'manage_masjid_academics' => __('مکتب: تعلیمی رپورٹ (جنرل)', 'Maktab: Academic Report (General)'),
    'manage_masjid_exams' => __('مکتب: امتحانات و نتائج', 'Maktab: Exams & Results'),
    'manage_masjid_finance' => __('مسجد: آمدن و خرچ (سادہ فنانس - تمام)', 'Masjid: Income & Expense (Simple Finance - All)'),
    'manage_masjid_finance_entry' => __('مسجد: آمدن و خرچ اندراج', 'Masjid: Income & Expense Entry'),
    'manage_masjid_finance_report' => __('مسجد: مالیاتی رپورٹ', 'Masjid: Financial Report'),
    'manage_masjid_financial_reports' => __('مسجد: مالیاتی تفصیلی گوشوارے', 'Masjid: Detailed Financial Statements'),
    'manage_masjid_fee_defaulters' => __('مسجد: فیس و واجبات', 'Masjid: Fees & Dues'),
    'manage_masjid_loans' => __('مسجد: ایڈوانس و قرض مینیجمنٹ', 'Masjid: Advance & Loan Management'),
    'manage_masjid_stipends' => __('مسجد: وظائف مینیجمنٹ', 'Masjid: Stipends Management'),
    'manage_masjid_assets' => __('مسجد: اثاثہ جات', 'Masjid: Assets Management'),
    'manage_masjid_in_kind_donations' => __('مسجد: اشیاء و عطیات', 'Masjid: In-Kind Donations'),
    'manage_masjid_petty_cash' => __('مسجد: پیٹی کیش رجسٹر', 'Masjid: Petty Cash Register'),
    'manage_masjid_itikaf' => __('مسجد: اعتکاف مینیجمنٹ', 'Masjid: Itikaf Management'),
];

$all_perms = array_merge($madrasa_perms, $masjid_perms);
?>

<style>
.perm-col-box {
    background: var(--card-bg, #ffffff);
    border: 1px solid var(--card-border, #e2e8f0);
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
    display: flex;
    flex-direction: column;
    height: 390px;
    overflow: hidden;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.perm-col-box:focus-within, .perm-col-box:hover {
    border-color: var(--primary, #1B4332);
}
.perm-col-header {
    padding: 10px 14px;
    background: var(--table-header-bg, #f8f9fa);
    border-bottom: 1px solid var(--card-border, #e2e8f0);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.perm-col-header .box-title {
    font-weight: 700;
    font-size: 14px;
    margin: 0;
}
.perm-col-header .box-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
}
.perm-search-box {
    padding: 6px 12px;
    border-bottom: 1px solid var(--card-border, #e2e8f0);
    background: var(--card-bg, #ffffff);
}
.perm-search-input {
    background: var(--input-bg, #ffffff);
    color: var(--text-main, #212529);
    border: 1px solid var(--input-border, #dee2e6);
    border-radius: 6px;
    font-size: 12px;
    padding: 5px 10px;
    width: 100%;
}
.perm-search-input:focus {
    outline: none;
    border-color: var(--primary, #1B4332);
}
.perm-col-body {
    padding: 8px 10px;
    overflow-y: auto;
    flex: 1;
    background: var(--input-bg, var(--card-bg, #ffffff));
}
.perm-item {
    padding: 5px 8px;
    border-radius: 6px;
    transition: background 0.15s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 2px;
}
.perm-item:hover {
    background: var(--table-hover-bg, rgba(0, 0, 0, 0.05));
}
.perm-item .form-check-label {
    color: var(--text-main, #212529) !important;
    font-size: 13px;
    cursor: pointer;
    flex: 1;
    margin: 0;
    line-height: 1.4;
    user-select: none;
}
.perm-item .form-check-input {
    cursor: pointer;
    margin-top: 0;
    flex-shrink: 0;
}
.perm-col-footer {
    padding: 6px 14px;
    background: var(--table-header-bg, #f8f9fa);
    border-top: 1px solid var(--card-border, #e2e8f0);
    font-size: 11px;
    color: var(--text-muted, #6c757d);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
</style>

<?php if ($msg) echo "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>$msg<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; ?>
<?php if ($err) echo "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>$err<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; ?>

<!-- Add / Edit User Form Card (Full Width) -->
<div class="card shadow-sm mb-4" id="user_form_card">
    <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center" id="form_card_title">
        <span><i class="fas fa-user-plus me-2"></i> <?php echo __('نیا یوزر شامل کریں', 'Add New User'); ?></span>
        <button type="button" class="btn btn-sm btn-outline-light d-none" id="cancel_edit_btn" onclick="resetForm()"><?php echo __('منسوخ کریں', 'Cancel'); ?></button>
    </div>
    <div class="card-body">
        <form method="POST" id="user_form">
            <input type="hidden" name="user_id" id="form_user_id" value="">
            <div id="edit_alert" class="alert alert-warning d-none py-2 mb-3">
                <i class="fas fa-pen me-1"></i> <strong><?php echo __('ترمیم موڈ', 'Edit Mode'); ?></strong> — <?php echo __('یوزر کی معلومات تبدیل کریں', 'Update user information'); ?>
            </div>

            <!-- Top Row: User Basic Info in 4 Columns -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label fw-bold"><?php echo __('نام', 'Name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="form_name" class="form-control" placeholder="<?php echo __('نام درج کریں', 'Enter name'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold"><?php echo __('یوزر نیم', 'Username'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="username" id="form_username" class="form-control" placeholder="<?php echo __('یوزر نیم درج کریں', 'Enter username'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold"><?php echo __('پاس ورڈ', 'Password'); ?> <small class="text-muted">(<?php echo __('تبدیل نہ کرنے پر خالی رکھیں', 'Leave blank to keep unchanged'); ?>)</small></label>
                    <input type="password" name="password" class="form-control" placeholder="<?php echo __('پاس ورڈ', 'Password'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold"><?php echo __('رول (Role)', 'Role'); ?></label>
                    <select name="role" id="form_role" class="form-select" onchange="handleRoleChange(this.value)">
                        <option value="admin"><?php echo __('ایڈمن (مکمل رسائی)', 'Admin (Full Access)'); ?></option>
                        <option value="nazim"><?php echo __('ناظم', 'Nazim'); ?></option>
                        <option value="teacher"><?php echo __('استاد', 'Teacher'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Middle Row: 3 Side-by-Side Sections (مدرسہ پرمیشنز ، متعلقہ کلاسز ، مسجد پرمیشنز) -->
            <div class="row g-3 mb-4">
                <!-- 1. مدرسہ پرمیشنز -->
                <div class="col-lg-4 col-md-4">
                    <div class="perm-col-box">
                        <div class="perm-col-header">
                            <h6 class="box-title text-success"><i class="fas fa-university me-1"></i> <?php echo __('مدرسہ پرمیشنز', 'Madrasa Permissions'); ?></h6>
                            <div class="box-actions">
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-success" onclick="togglePermGroup('madrasa', true)"><?php echo __('تمام منتخب', 'Select All'); ?></button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="togglePermGroup('madrasa', false)"><?php echo __('تمام ختم', 'Deselect All'); ?></button>
                            </div>
                        </div>
                        <div class="perm-search-box">
                            <input type="text" class="perm-search-input" placeholder="<?php echo __('مدرسہ پرمیشن تلاش کریں...', 'Search madrasa permissions...'); ?>" onkeyup="filterPermList(this, 'madrasa_list')">
                        </div>
                        <div class="perm-col-body" id="madrasa_list">
                            <?php foreach ($madrasa_perms as $key => $val): ?>
                            <div class="perm-item" data-title="<?php echo htmlspecialchars(mb_strtolower($val, 'UTF-8')); ?>">
                                <input class="form-check-input perm-cb madrasa-perm-cb" type="checkbox" name="permissions[]" value="<?php echo $key; ?>" id="perm_<?php echo $key; ?>" onchange="updateSelectedCounts()">
                                <label class="form-check-label" for="perm_<?php echo $key; ?>"><?php echo $val; ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="perm-col-footer">
                            <span><?php echo __('کل اختیارات:', 'Total Permissions:'); ?> <?php echo count($madrasa_perms); ?></span>
                            <span class="badge bg-success" id="madrasa_count_badge">0 <?php echo __('منتخب', 'Selected'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. متعلقہ کلاسز (Allowed Classes) -->
                <div class="col-lg-4 col-md-4">
                    <div class="perm-col-box">
                        <div class="perm-col-header">
                            <h6 class="box-title text-primary"><i class="fas fa-chalkboard me-1"></i> <?php echo __('متعلقہ کلاسز (Classes)', 'Assigned Classes'); ?></h6>
                            <div class="box-actions">
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-primary" onclick="toggleAllClasses(true)"><?php echo __('تمام منتخب', 'Select All'); ?></button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="toggleAllClasses(false)"><?php echo __('تمام ختم', 'Deselect All'); ?></button>
                            </div>
                        </div>
                        <div class="perm-search-box">
                            <input type="text" class="perm-search-input" placeholder="<?php echo __('کلاس تلاش کریں...', 'Search class...'); ?>" onkeyup="filterPermList(this, 'classes_list')">
                        </div>
                        <div class="perm-col-body" id="classes_list">
                            <?php if (empty($all_classes)): ?>
                                <p class="text-muted small p-3 text-center"><?php echo __('کوئی کلاس موجود نہیں۔', 'No classes available.'); ?></p>
                            <?php else: ?>
                                <?php foreach ($all_classes as $cls): 
                                    $is_masjid_cls = (($cls['system_type'] ?? '') === 'Masjid');
                                ?>
                                <div class="perm-item" data-title="<?php echo htmlspecialchars(mb_strtolower($cls['name'] . ' ' . ($cls['section'] ?? ''), 'UTF-8')); ?>">
                                    <input class="form-check-input class-cb" type="checkbox" name="allowed_classes[]" value="<?php echo $cls['id']; ?>" id="class_<?php echo $cls['id']; ?>" onchange="updateSelectedCounts()">
                                    <label class="form-check-label" for="class_<?php echo $cls['id']; ?>">
                                        <?php echo e($cls['name']); ?> 
                                        <?php echo !empty($cls['section']) ? ' - ' . e($cls['section']) : ''; ?>
                                        <?php if ($is_masjid_cls): ?>
                                            <span class="badge bg-warning text-dark me-1" style="font-size: 10px;"><?php echo __('مکتب', 'Maktab'); ?></span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="perm-col-footer">
                            <span><?php echo __('کل کلاسز:', 'Total Classes:'); ?> <?php echo count($all_classes); ?></span>
                            <span class="badge bg-primary" id="classes_count_badge">0 <?php echo __('منتخب', 'Selected'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- 3. مسجد پرمیشنز -->
                <div class="col-lg-4 col-md-4">
                    <div class="perm-col-box">
                        <div class="perm-col-header">
                            <h6 class="box-title text-warning"><i class="fas fa-mosque me-1"></i> <?php echo __('مسجد پرمیشنز', 'Masjid Permissions'); ?></h6>
                            <div class="box-actions">
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-warning" onclick="togglePermGroup('masjid', true)"><?php echo __('تمام منتخب', 'Select All'); ?></button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="togglePermGroup('masjid', false)"><?php echo __('تمام ختم', 'Deselect All'); ?></button>
                            </div>
                        </div>
                        <div class="perm-search-box">
                            <input type="text" class="perm-search-input" placeholder="<?php echo __('مسجد پرمیشن تلاش کریں...', 'Search masjid permissions...'); ?>" onkeyup="filterPermList(this, 'masjid_list')">
                        </div>
                        <div class="perm-col-body" id="masjid_list">
                            <?php foreach ($masjid_perms as $key => $val): ?>
                            <div class="perm-item" data-title="<?php echo htmlspecialchars(mb_strtolower($val, 'UTF-8')); ?>">
                                <input class="form-check-input perm-cb masjid-perm-cb" type="checkbox" name="permissions[]" value="<?php echo $key; ?>" id="perm_<?php echo $key; ?>" onchange="updateSelectedCounts()">
                                <label class="form-check-label" for="perm_<?php echo $key; ?>"><?php echo $val; ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="perm-col-footer">
                            <span><?php echo __('کل اختیارات:', 'Total Permissions:'); ?> <?php echo count($masjid_perms); ?></span>
                            <span class="badge bg-warning text-dark" id="masjid_count_badge">0 <?php echo __('منتخب', 'Selected'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Row: Buttons & Admin Note -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-3 border-top">
                <div class="text-muted small">
                    <i class="fas fa-info-circle me-1 text-primary"></i> <?php echo __('ایڈمن کو خودکار طور پر تمام اختیارات حاصل ہوتے ہیں۔ ناظم اور اساتذہ کے لیے مخصوص اختیارات منتخب کریں۔', 'Admin automatically has full access. For Nazim and Teachers, select specific permissions.'); ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="resetForm()">
                        <i class="fas fa-times me-1"></i> <?php echo __('فارم خالی کریں', 'Clear Form'); ?>
                    </button>
                    <button type="submit" name="save_user" class="btn btn-success px-4 fw-bold">
                        <i class="fas fa-save me-1"></i> <?php echo __('محفوظ کریں', 'Save'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Current Users & Staff Table (Full Width) -->
<div class="card shadow-sm mb-5">
    <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="fas fa-users-cog me-2"></i> <?php echo __('موجودہ یوزرز و عملہ', 'Current Users & Staff'); ?> (<?php echo __('کل:', 'Total:'); ?> <?php echo count($users); ?>)</span>
        <a href="cleanup_and_reorder_users.php" class="btn btn-sm btn-warning text-dark fw-bold shadow-sm" title="<?php echo __('فالتو اکاؤنٹس ختم کریں اور آئی ڈیز 1 تا 4 ری آرڈر کریں', 'Remove extra accounts and reorder IDs 1 to 4'); ?>">
            <i class="fas fa-broom me-1"></i> <?php echo __('اکاؤنٹس صفائی و ترتیبِ نو (1 تا 4)', 'Cleanup & Reorder Accounts (1 to 4)'); ?>
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th><?php echo __('نام', 'Name'); ?></th>
                        <th><?php echo __('یوزر نیم', 'Username'); ?></th>
                        <th><?php echo __('رول', 'Role'); ?></th>
                        <th><?php echo __('پرمیشنز', 'Permissions'); ?></th>
                        <th><?php echo __('متعلقہ کلاسز', 'Assigned Classes'); ?></th>
                        <th style="width: 120px;"><?php echo __('کارروائی', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" class="py-4 text-muted"><?php echo __('کوئی یوزر موجود نہیں۔', 'No users found.'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $index => $u): 
                            $uPerms = json_decode($u['permissions'] ?? '[]', true) ?: [];
                            $uClasses = json_decode($u['allowed_classes'] ?? '[]', true) ?: [];
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td class="fw-bold"><?php echo e($u['name']); ?></td>
                            <td><code><?php echo e($u['username']); ?></code></td>
                            <td>
                                <?php 
                                if($u['role']=='admin') echo '<span class="badge bg-danger">' . __('ایڈمن', 'Admin') . '</span>';
                                elseif($u['role']=='nazim') echo '<span class="badge bg-primary">' . __('ناظم', 'Nazim') . '</span>';
                                else echo '<span class="badge bg-info text-dark">' . __('استاد', 'Teacher') . '</span>';
                                ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge bg-success"><?php echo __('تمام اختیارات (All)', 'All Permissions (All)'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo count($uPerms); ?> <?php echo __('پرمیشنز', 'Permissions'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge bg-success"><?php echo __('تمام کلاسز (All)', 'All Classes (All)'); ?></span>
                                <?php elseif (empty($uClasses)): ?>
                                    <small class="text-muted"><?php echo __('کوئی مخصوص کلاس نہیں', 'No specific class'); ?></small>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><?php echo count($uClasses); ?> <?php echo __('کلاسز الاٹ', 'Classes Assigned'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1" title="<?php echo __('ترمیم', 'Edit'); ?>" onclick='editUser(<?php echo json_encode($u); ?>)'><i class="fas fa-edit"></i></button>
                                <?php if ((int)$u['id'] === 1): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 small" title="<?php echo __('مرکزی ایڈمن اکاؤنٹ (نان ڈیلیٹ ایبل)', 'Main Admin Account (Non-deletable)'); ?>">
                                        <i class="fas fa-shield-alt me-1"></i> <?php echo __('مین اکاؤنٹ', 'Main Account'); ?>
                                    </span>
                                <?php elseif ($u['id'] == $_SESSION['user_id']): ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle px-2 py-1 small" title="<?php echo __('آپ اس وقت اس اکاؤنٹ سے لاگ ان ہیں', 'You are currently logged in with this account'); ?>">
                                        <i class="fas fa-user-check me-1"></i> <?php echo __('خود لاگ ان', 'Self Logged In'); ?>
                                    </span>
                                <?php else: ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo __('کیا آپ واقعی اس یوزر کو ڈیلیٹ کرنا چاہتے ہیں؟', 'Are you sure you want to delete this user?'); ?>');">
                                        <input type="hidden" name="delete_user" value="<?php echo $u['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="<?php echo __('حذف کریں', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                    </form>
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

<script>
function togglePermGroup(group, checked) {
    let selector = group === 'madrasa' ? '.madrasa-perm-cb' : '.masjid-perm-cb';
    document.querySelectorAll(selector).forEach(cb => {
        let parentItem = cb.closest('.perm-item');
        if (parentItem && parentItem.style.display !== 'none') {
            cb.checked = checked;
        }
    });
    updateSelectedCounts();
}

function toggleAllClasses(checked) {
    document.querySelectorAll('.class-cb').forEach(cb => {
        let parentItem = cb.closest('.perm-item');
        if (parentItem && parentItem.style.display !== 'none') {
            cb.checked = checked;
        }
    });
    updateSelectedCounts();
}

function filterPermList(input, containerId) {
    let filter = input.value.trim().toLowerCase();
    let container = document.getElementById(containerId);
    let items = container.querySelectorAll('.perm-item');
    items.forEach(item => {
        let title = item.getAttribute('data-title') || '';
        if (title.includes(filter)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function updateSelectedCounts() {
    let madrasaSel = document.querySelectorAll('.madrasa-perm-cb:checked').length;
    let classesSel = document.querySelectorAll('.class-cb:checked').length;
    let masjidSel = document.querySelectorAll('.masjid-perm-cb:checked').length;

    let elM = document.getElementById('madrasa_count_badge');
    let elC = document.getElementById('classes_count_badge');
    let elJ = document.getElementById('masjid_count_badge');
    let selSuffix = ' <?php echo __('منتخب', 'Selected'); ?>';

    if (elM) elM.textContent = madrasaSel + selSuffix;
    if (elC) elC.textContent = classesSel + selSuffix;
    if (elJ) elJ.textContent = masjidSel + selSuffix;
}

function handleRoleChange(role) {
    if (role === 'admin') {
        document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = true);
    } else if (role === 'nazim') {
        let checkedCount = document.querySelectorAll('.perm-cb:checked').length;
        if (checkedCount === 0) {
            const nazimDefaults = [
                'manage_attendance', 'manage_teacher_attendance', 'manage_attendance_report',
                'manage_hifz', 'manage_hifz_daily', 'manage_hifz_report',
                'manage_academics', 'manage_academic_dashboard', 'manage_academic_entry', 'manage_academic_view',
                'manage_students', 'manage_teachers', 'manage_classes', 'manage_exams',
                'manage_finance_entry', 'manage_finance_report',
                'manage_requests', 'manage_notices', 'manage_admissions', 'manage_complaints', 'add_complaint',
                'manage_masjid_attendance', 'manage_masjid_teacher_attendance', 'manage_masjid_attendance_report',
                'manage_masjid_hifz', 'manage_masjid_hifz_daily', 'manage_masjid_hifz_report',
                'manage_masjid_academics', 'manage_masjid_academic_dashboard', 'manage_masjid_academic_entry', 'manage_masjid_academic_view',
                'manage_masjid_students', 'manage_masjid_teachers', 'manage_masjid_classes', 'manage_masjid_exams',
                'manage_masjid_finance_entry', 'manage_masjid_finance_report'
            ];
            nazimDefaults.forEach(p => {
                let cb = document.getElementById('perm_' + p);
                if (cb) cb.checked = true;
            });
        }
    }
    updateSelectedCounts();
}

function resetForm() {
    document.getElementById('form_user_id').value = '';
    document.getElementById('form_name').value = '';
    document.getElementById('form_username').value = '';
    document.getElementById('form_role').value = 'teacher';
    document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
    document.querySelectorAll('.class-cb').forEach(cb => cb.checked = false);
    document.querySelectorAll('.perm-search-input').forEach(inp => {
        inp.value = '';
        let body = inp.closest('.perm-col-box').querySelector('.perm-col-body');
        if (body) filterPermList(inp, body.id);
    });
    document.getElementById('form_card_title').innerHTML = '<span><i class="fas fa-user-plus me-2"></i> <?php echo __('نیا یوزر شامل کریں', 'Add New User'); ?></span>';
    document.getElementById('form_card_title').className = 'card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center';
    document.getElementById('edit_alert').classList.add('d-none');
    document.getElementById('cancel_edit_btn').classList.add('d-none');
    updateSelectedCounts();
}

function editUser(u) {
    document.getElementById('form_user_id').value = u.id;
    document.getElementById('form_name').value = u.name;
    document.getElementById('form_username').value = u.username;
    document.getElementById('form_role').value = u.role || 'teacher';
    
    // Update card header to show edit mode
    document.getElementById('form_card_title').innerHTML = '<span><i class="fas fa-user-edit me-2"></i> <?php echo __('یوزر اپڈیٹ کریں:', 'Update User:'); ?> ' + u.name + '</span><button type="button" class="btn btn-sm btn-outline-dark" onclick="resetForm()"><?php echo __('منسوخ کریں', 'Cancel'); ?></button>';
    document.getElementById('form_card_title').className = 'card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center';
    document.getElementById('edit_alert').classList.remove('d-none');
    
    document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
    try {
        let perms = JSON.parse(u.permissions);
        if (Array.isArray(perms)) {
            if (perms.includes('all') || u.role === 'admin') {
                document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = true);
            } else {
                perms.forEach(p => {
                    let cb = document.getElementById('perm_' + p);
                    if (cb) cb.checked = true;
                });
            }
        } else if (u.role === 'admin') {
            document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = true);
        }
    } catch(e) {
        if (u.role === 'admin') {
            document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = true);
        }
    }

    document.querySelectorAll('.class-cb').forEach(cb => cb.checked = false);
    try {
        let classes = JSON.parse(u.allowed_classes);
        if (Array.isArray(classes)) {
            classes.forEach(c => {
                let cb = document.getElementById('class_' + c);
                if (cb) cb.checked = true;
            });
        }
    } catch(e) {}
    
    updateSelectedCounts();
    
    // Scroll smoothly to form
    document.getElementById('user_form_card').scrollIntoView({behavior: 'smooth', block: 'start'});
}

document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCounts();
});
</script>

<?php include 'footer.php'; ?>
