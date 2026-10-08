<?php
// manage_users.php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
        $permissions = isset($_POST['permissions']) ? json_encode(array_values($_POST['permissions'])) : '[]';
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
                $msg = "یوزر کامیابی سے اپڈیٹ ہو گیا۔";
            } else { // insert
                $hashed = hash_pass($password);
                $stmt = $pdo->prepare("INSERT INTO admins (name, username, password, role, permissions, allowed_classes, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $username, $hashed, $role, $permissions, $allowed_classes, $cur_tenant_id]);
                $msg = "نیا یوزر شامل کر دیا گیا۔";
            }
        } catch (PDOException $e) {
            $err = "ڈیٹا بیس ایرر: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = $_POST['delete_user'];
        if ($id != $_SESSION['user_id']) { // Don't delete self
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
    'manage_classes' => 'کلاسز کا انتظام',
    'manage_students' => 'طلباء کا ریکارڈ',
    'manage_teachers' => 'اساتذہ کا ریکارڈ',
    'manage_attendance' => 'طالب علموں کی حاضری',
    'manage_teacher_attendance' => 'اساتذہ کی حاضری',
    'manage_attendance_report' => 'حاضری تفصیلی رپورٹ',
    'manage_hifz' => 'حفظ (روزانہ اندراج و رپورٹ)',
    'manage_hifz_daily' => 'حفظ (روزانہ اندراج)',
    'manage_hifz_report' => 'حفظ (تعلیمی رپورٹ)',
    'manage_academic_dashboard' => 'تعلیمی کارکردگی (اندراج ڈیش بورڈ)',
    'manage_academic_entry' => 'تعلیمی رپورٹ (اندراج)',
    'manage_academic_view' => 'تعلیمی کارکردگی (رپورٹ دیکھیں)',
    'manage_academics' => 'تعلیمی رپورٹ (جنرل)',
    'manage_exams' => 'امتحانات و نتائج',
    'manage_finance' => 'آمدن و خرچ (سادہ فنانس - تمام)',
    'manage_finance_entry' => 'آمدن و خرچ اندراج',
    'manage_finance_report' => 'مالیاتی رپورٹ',
    'manage_financial_reports' => 'مالیاتی تفصیلی گوشوارے و سالانہ رپورٹ',
    'manage_fee_defaulters' => 'فیس و واجبات / نادہندگان',
    'manage_loans' => 'ایڈوانس و قرض مینیجمنٹ',
    'manage_stipends' => 'وظائف مینیجمنٹ',
    'manage_assets' => 'اثاثہ جات (Assets - مدرسہ)',
    'manage_in_kind_donations' => 'اشیاء و عطیات (In-Kind Donations - مدرسہ)',
    'manage_petty_cash' => 'پیٹی کیش رجسٹر',
    'manage_erp' => 'ای آر پی اکاؤنٹس (ERP Accounting)',
    'manage_admissions' => 'داخلہ درخواستیں',
    'manage_notices' => 'نوٹس بورڈ / اعلانات',
    'manage_requests' => 'درخواستیں و شکایات',
    'add_complaint' => 'شکایت اندراج',
    'manage_complaints' => 'شکایات ریکارڈ',
    'manage_users' => 'یوزرز کا انتظام (User Management)',
];

$masjid_perms = [
    'manage_masjid_classes' => 'مکتب: کلاسز کا انتظام',
    'manage_masjid_students' => 'مکتب: طلباء کا ریکارڈ',
    'manage_masjid_teachers' => 'مکتب: اساتذہ کا ریکارڈ',
    'manage_masjid_attendance' => 'مکتب: طالب علموں کی حاضری',
    'manage_masjid_teacher_attendance' => 'مکتب: اساتذہ کی حاضری',
    'manage_masjid_attendance_report' => 'مکتب: حاضری تفصیلی رپورٹ',
    'manage_masjid_hifz' => 'مکتب: حفظ (روزانہ اندراج و رپورٹ)',
    'manage_masjid_hifz_daily' => 'مکتب: حفظ (روزانہ اندراج)',
    'manage_masjid_hifz_report' => 'مکتب: حفظ (تعلیمی رپورٹ)',
    'manage_masjid_academic_dashboard' => 'مکتب: تعلیمی کارکردگی (اندراج ڈیش بورڈ)',
    'manage_masjid_academic_entry' => 'مکتب: تعلیمی رپورٹ (اندراج)',
    'manage_masjid_academic_view' => 'مکتب: تعلیمی کارکردگی (رپورٹ دیکھیں)',
    'manage_masjid_academics' => 'مکتب: تعلیمی رپورٹ (جنرل)',
    'manage_masjid_exams' => 'مکتب: امتحانات و نتائج',
    'manage_masjid_finance' => 'مسجد: آمدن و خرچ (سادہ فنانس - تمام)',
    'manage_masjid_finance_entry' => 'مسجد: آمدن و خرچ اندراج',
    'manage_masjid_finance_report' => 'مسجد: مالیاتی رپورٹ',
    'manage_masjid_financial_reports' => 'مسجد: مالیاتی تفصیلی گوشوارے',
    'manage_masjid_fee_defaulters' => 'مسجد: فیس و واجبات',
    'manage_masjid_loans' => 'مسجد: ایڈوانس و قرض مینیجمنٹ',
    'manage_masjid_stipends' => 'مسجد: وظائف مینیجمنٹ',
    'manage_masjid_assets' => 'مسجد: اثاثہ جات',
    'manage_masjid_in_kind_donations' => 'مسجد: اشیاء و عطیات',
    'manage_masjid_petty_cash' => 'مسجد: پیٹی کیش رجسٹر',
    'manage_masjid_itikaf' => 'مسجد: اعتکاف مینیجمنٹ',
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
        <span><i class="fas fa-user-plus me-2"></i> <?php echo t('add_new_user'); ?></span>
        <button type="button" class="btn btn-sm btn-outline-light d-none" id="cancel_edit_btn" onclick="resetForm()">منسوخ کریں</button>
    </div>
    <div class="card-body">
        <form method="POST" id="user_form">
            <input type="hidden" name="user_id" id="form_user_id" value="">
            <div id="edit_alert" class="alert alert-warning d-none py-2 mb-3">
                <i class="fas fa-pen me-1"></i> <strong>ترمیم موڈ</strong> — یوزر کی معلومات تبدیل کریں
            </div>

            <!-- Top Row: User Basic Info in 4 Columns -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label fw-bold"><?php echo t('name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="form_name" class="form-control" placeholder="نام درج کریں" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold"><?php echo t('username'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="username" id="form_username" class="form-control" placeholder="یوزر نیم درج کریں" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">پاس ورڈ <small class="text-muted">(تبدیل نہ کرنے پر خالی رکھیں)</small></label>
                    <input type="password" name="password" class="form-control" placeholder="پاس ورڈ">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">رول (Role)</label>
                    <select name="role" id="form_role" class="form-select" onchange="handleRoleChange(this.value)">
                        <option value="admin">ایڈمن (Admin - Full Access)</option>
                        <option value="nazim">ناظم (Nazim)</option>
                        <option value="teacher">استاد (Teacher)</option>
                    </select>
                </div>
            </div>

            <!-- Middle Row: 3 Side-by-Side Sections (مدرسہ پرمیشنز ، متعلقہ کلاسز ، مسجد پرمیشنز) -->
            <div class="row g-3 mb-4">
                <!-- 1. مدرسہ پرمیشنز -->
                <div class="col-lg-4 col-md-4">
                    <div class="perm-col-box">
                        <div class="perm-col-header">
                            <h6 class="box-title text-success"><i class="fas fa-university me-1"></i> مدرسہ پرمیشنز</h6>
                            <div class="box-actions">
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-success" onclick="togglePermGroup('madrasa', true)">تمام منتخب</button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="togglePermGroup('madrasa', false)">تمام ختم</button>
                            </div>
                        </div>
                        <div class="perm-search-box">
                            <input type="text" class="perm-search-input" placeholder="مدرسہ پرمیشن تلاش کریں..." onkeyup="filterPermList(this, 'madrasa_list')">
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
                            <span>کل اختیارات: <?php echo count($madrasa_perms); ?></span>
                            <span class="badge bg-success" id="madrasa_count_badge">0 منتخب</span>
                        </div>
                    </div>
                </div>

                <!-- 2. متعلقہ کلاسز (Allowed Classes) -->
                <div class="col-lg-4 col-md-4">
                    <div class="perm-col-box">
                        <div class="perm-col-header">
                            <h6 class="box-title text-primary"><i class="fas fa-chalkboard me-1"></i> متعلقہ کلاسز (Classes)</h6>
                            <div class="box-actions">
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-primary" onclick="toggleAllClasses(true)">تمام منتخب</button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="toggleAllClasses(false)">تمام ختم</button>
                            </div>
                        </div>
                        <div class="perm-search-box">
                            <input type="text" class="perm-search-input" placeholder="کلاس تلاش کریں..." onkeyup="filterPermList(this, 'classes_list')">
                        </div>
                        <div class="perm-col-body" id="classes_list">
                            <?php if (empty($all_classes)): ?>
                                <p class="text-muted small p-3 text-center">کوئی کلاس موجود نہیں۔</p>
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
                                            <span class="badge bg-warning text-dark me-1" style="font-size: 10px;">مکتب</span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="perm-col-footer">
                            <span>کل کلاسز: <?php echo count($all_classes); ?></span>
                            <span class="badge bg-primary" id="classes_count_badge">0 منتخب</span>
                        </div>
                    </div>
                </div>

                <!-- 3. مسجد پرمیشنز -->
                <div class="col-lg-4 col-md-4">
                    <div class="perm-col-box">
                        <div class="perm-col-header">
                            <h6 class="box-title text-warning"><i class="fas fa-mosque me-1"></i> مسجد پرمیشنز</h6>
                            <div class="box-actions">
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-warning" onclick="togglePermGroup('masjid', true)">تمام منتخب</button>
                                <span class="text-muted">|</span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-danger" onclick="togglePermGroup('masjid', false)">تمام ختم</button>
                            </div>
                        </div>
                        <div class="perm-search-box">
                            <input type="text" class="perm-search-input" placeholder="مسجد پرمیشن تلاش کریں..." onkeyup="filterPermList(this, 'masjid_list')">
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
                            <span>کل اختیارات: <?php echo count($masjid_perms); ?></span>
                            <span class="badge bg-warning text-dark" id="masjid_count_badge">0 منتخب</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Row: Buttons & Admin Note -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-3 border-top">
                <div class="text-muted small">
                    <i class="fas fa-info-circle me-1 text-primary"></i> <?php echo t('admin_perm_notice'); ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="resetForm()">
                        <i class="fas fa-times me-1"></i> <?php echo t('clear_form'); ?>
                    </button>
                    <button type="submit" name="save_user" class="btn btn-success px-4 fw-bold">
                        <i class="fas fa-save me-1"></i> <?php echo t('btn_save_ur'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Current Users & Staff Table (Full Width) -->
<div class="card shadow-sm mb-5">
    <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="fas fa-users-cog me-2"></i> <?php echo t('current_users_staff'); ?> (کل: <?php echo count($users); ?>)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th><?php echo t('name'); ?></th>
                        <th><?php echo t('username'); ?></th>
                        <th><?php echo t('role'); ?></th>
                        <th><?php echo t('permissions'); ?></th>
                        <th>متعلقہ کلاسز</th>
                        <th style="width: 120px;"><?php echo t('action_col'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" class="py-4 text-muted">کوئی یوزر موجود نہیں۔</td></tr>
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
                                if($u['role']=='admin') echo '<span class="badge bg-danger">' . t('role_admin') . '</span>';
                                elseif($u['role']=='nazim') echo '<span class="badge bg-primary">' . t('role_nazim') . '</span>';
                                else echo '<span class="badge bg-info text-dark">' . t('role_teacher') . '</span>';
                                ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge bg-success">تمام اختیارات (All)</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo count($uPerms); ?> پرمیشنز</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge bg-success">تمام کلاسز (All)</span>
                                <?php elseif (empty($uClasses)): ?>
                                    <small class="text-muted">کوئی مخصوص کلاس نہیں</small>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><?php echo count($uClasses); ?> کلاسز الاٹ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1" title="ترمیم" onclick='editUser(<?php echo json_encode($u); ?>)'><i class="fas fa-edit"></i></button>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo t('confirm_del_user'); ?>');">
                                    <input type="hidden" name="delete_user" value="<?php echo $u['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="حذف کریں"><i class="fas fa-trash"></i></button>
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

    if (elM) elM.textContent = madrasaSel + ' منتخب';
    if (elC) elC.textContent = classesSel + ' منتخب';
    if (elJ) elJ.textContent = masjidSel + ' منتخب';
}

function handleRoleChange(role) {
    // Optional helper when role changes
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
    document.getElementById('form_card_title').innerHTML = '<span><i class="fas fa-user-plus me-2"></i> <?php echo t("add_new_user"); ?></span>';
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
    document.getElementById('form_card_title').innerHTML = '<span><i class="fas fa-user-edit me-2"></i> یوزر اپڈیٹ کریں: ' + u.name + '</span><button type="button" class="btn btn-sm btn-outline-dark" onclick="resetForm()">منسوخ کریں</button>';
    document.getElementById('form_card_title').className = 'card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center';
    document.getElementById('edit_alert').classList.remove('d-none');
    
    document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
    try {
        let perms = JSON.parse(u.permissions);
        if (Array.isArray(perms)) {
            perms.forEach(p => {
                let cb = document.getElementById('perm_' + p);
                if (cb) cb.checked = true;
            });
        }
    } catch(e) {}

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
