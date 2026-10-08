<?php
// hostel.php - ہاسٹل و رہائش مینیجمنٹ سسٹم مع فیس انضمام
ob_start();
require_once 'db.php';
include 'header.php';

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage = in_array($role, ['admin', 'nazim', 'superadmin'], true) || has_perm('manage_hostel');
if (!$can_manage) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

ensure_phase3_4_tables($pdo);

$cur_tenant_id = (int)get_current_tenant_id();

$active_sys = $_SESSION['active_system'] ?? ($_SESSION['system_type'] ?? 'Madrasa');
$is_masjid = ($active_sys === 'Masjid');

$sys_cond = $is_masjid 
    ? "system_type = 'Masjid'" 
    : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$r_sys_cond = $is_masjid 
    ? "r.system_type = 'Masjid'" 
    : "(r.system_type = 'Madrasa' OR r.system_type IS NULL OR r.system_type = '')";

$success_msg = '';
$error_msg = '';

// --- ACTIONS ---

// 1. نیا کمرہ شامل کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    try {
        $roomNo = trim($_POST['room_number'] ?? '');
        $roomName = trim($_POST['room_name'] ?? '');
        $beds = max(1, (int)($_POST['capacity_beds'] ?? 4));
        $fee = (float)($_POST['monthly_fee'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        $sys = trim($_POST['system_type'] ?? $active_sys);

        if (empty($roomNo)) throw new Exception('کمرہ نمبر درج کرنا لازمی ہے!');

        $stmt = $pdo->prepare("INSERT INTO hostel_rooms (room_number, room_name, capacity_beds, monthly_fee, status, remarks, system_type, tenant_id, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, 'Active', ?, ?, ?, 0, NOW(), NOW())");
        $stmt->execute([$roomNo, $roomName, $beds, $fee, $remarks, $sys, $cur_tenant_id]);
        log_activity($pdo, 'hostel', "نیا ہاسٹل روم شامل کیا گیا: $roomNo ($roomName)");
        $success_msg = "کمرہ نمبر '$roomNo' کامیابی سے شامل کر دیا گیا!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 2. طالب علم کو بیڈ الاٹ کرنا
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_student'])) {
    try {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $stdId = (int)($_POST['student_id'] ?? 0);
        $bedNo = trim($_POST['bed_number'] ?? '');
        $allocDate = $_POST['allocation_date'] ?? date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($roomId <= 0 || $stdId <= 0) throw new Exception('کمرہ اور طالب علم کا انتخاب لازمی ہے!');

        // Check if student already has active allocation
        $checkStd = $pdo->prepare("SELECT id FROM hostel_allocations WHERE student_id = ? AND status = 'Occupied' AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
        $checkStd->execute([$stdId, $cur_tenant_id]);
        if ($checkStd->fetchColumn()) {
            throw new Exception('یہ طالب علم پہلے ہی ہاسٹل کے کسی کمرے میں مقیم ہے!');
        }

        // Get student name
        $st = $pdo->prepare("SELECT name FROM students WHERE id = ? AND tenant_id = ?");
        $st->execute([$stdId, $cur_tenant_id]);
        $sName = $st->fetchColumn() ?: '';

        // Get room details
        $rm = $pdo->prepare("SELECT room_number, capacity_beds FROM hostel_rooms WHERE id = ? AND tenant_id = ?");
        $rm->execute([$roomId, $cur_tenant_id]);
        $room = $rm->fetch(PDO::FETCH_ASSOC);
        if (!$room) throw new Exception('کمرہ نہیں ملا!');

        // Check capacity
        $occCount = (int)$pdo->query("SELECT COUNT(*) FROM hostel_allocations WHERE room_id = $roomId AND status = 'Occupied' AND tenant_id = $cur_tenant_id AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        if ($occCount >= (int)$room['capacity_beds']) {
            throw new Exception('اس کمرے میں تمام بیڈز پہلے سے پر ہیں!');
        }

        if (empty($bedNo)) {
            $bedNo = 'Bed-' . ($occCount + 1);
        }

        $stmt = $pdo->prepare("INSERT INTO hostel_allocations (room_id, student_id, student_name, bed_number, allocation_date, status, remarks, system_type, tenant_id, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Occupied', ?, ?, ?, 0, NOW(), NOW())");
        $stmt->execute([$roomId, $stdId, $sName, $bedNo, $allocDate, $remarks, $active_sys, $cur_tenant_id]);

        // Update student residence_status to مقیم
        $pdo->prepare("UPDATE students SET residence_status = 'مقیم' WHERE id = ? AND tenant_id = ?")->execute([$stdId, $cur_tenant_id]);

        log_activity($pdo, 'hostel', "طالب علم {$sName} کو کمرہ {$room['room_number']} میں بیڈ {$bedNo} الاٹ کیا گیا");
        $success_msg = "طالب علم '$sName' کو کامیابی سے ہاسٹل الاٹ کر دیا گیا!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 3. کمرہ خالی کرنا (Vacate Bed)
if (isset($_GET['vacate_id'])) {
    $vId = (int)$_GET['vacate_id'];
    $alloc = $pdo->prepare("SELECT a.*, r.room_number FROM hostel_allocations a JOIN hostel_rooms r ON a.room_id = r.id WHERE a.id = ? AND a.tenant_id = ? AND r.tenant_id = ?");
    $alloc->execute([$vId, $cur_tenant_id, $cur_tenant_id]);
    $row = $alloc->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pdo->prepare("UPDATE hostel_allocations SET status = 'Vacated', vacating_date = CURDATE(), updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$vId, $cur_tenant_id]);
        // Update student residence status to مقامی
        $pdo->prepare("UPDATE students SET residence_status = 'مقامی' WHERE id = ? AND tenant_id = ?")->execute([$row['student_id'], $cur_tenant_id]);
        log_activity($pdo, 'hostel', "طالب علم {$row['student_name']} نے کمرہ {$row['room_number']} خالی کیا");
        $success_msg = "بیڈ کامیابی سے خالی قرار دے دیا گیا!";
    }
}

// 4. ڈیلیٹ کمرہ
if (isset($_GET['delete_room'])) {
    $delId = (int)$_GET['delete_room'];
    $pdo->prepare("UPDATE hostel_rooms SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$delId, $cur_tenant_id]);
    log_activity($pdo, 'hostel', "کمرہ حذف کیا گیا ID: $delId");
    $success_msg = "کمرہ کامیابی سے حذف کر دیا گیا!";
}

// Fetch Rooms with Occupancy
$rooms = $pdo->query("SELECT * FROM hostel_rooms WHERE $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) AND tenant_id = $cur_tenant_id ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Allocations
$allocStmt = $pdo->query("
    SELECT a.*, r.room_number, r.room_name, r.monthly_fee, s.admission_number, s.class
    FROM hostel_allocations a
    JOIN hostel_rooms r ON a.room_id = r.id
    JOIN students s ON a.student_id = s.id
    WHERE a.status = 'Occupied' AND $r_sys_cond AND (a.is_deleted = 0 OR a.is_deleted IS NULL)
    AND a.tenant_id = $cur_tenant_id AND r.tenant_id = $cur_tenant_id AND s.tenant_id = $cur_tenant_id
    ORDER BY r.room_number ASC, a.bed_number ASC
");
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

// Group allocations by room_id
$roomAllocMap = [];
foreach ($allocations as $al) {
    $roomAllocMap[$al['room_id']][] = $al;
}

// Statistics
$totalRooms = count($rooms);
$totalBeds = array_sum(array_column($rooms, 'capacity_beds'));
$occupiedBeds = count($allocations);
$availableBeds = max(0, $totalBeds - $occupiedBeds);

// Students for Allocation Dropdown
$students = $pdo->query("
    SELECT s.id, s.name, s.admission_number, s.class 
    FROM students s 
    WHERE s.status = 'Active' AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
    AND s.tenant_id = $cur_tenant_id
    AND s.id NOT IN (SELECT student_id FROM hostel_allocations WHERE status = 'Occupied' AND tenant_id = $cur_tenant_id AND (is_deleted = 0 OR is_deleted IS NULL))
    ORDER BY s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4" dir="rtl">
    <!-- Header Title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h3 class="fw-bold mb-1" style="color: #284D3D;">
                <i class="fas fa-hotel me-2 text-warning"></i>
                <?php echo $is_masjid ? 'ہاسٹل و معتکفین رہائش (مسجد)' : 'ہاسٹل مینیجمنٹ سسٹم'; ?>
            </h3>
            <span class="text-muted">کمرہ جات، بیڈز کی الاٹمنٹ اور ماہانہ فیس انوائسز میں خودکار ہاسٹل چارجز</span>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-warning fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#allocModal">
                <i class="fas fa-user-plus me-1"></i> طالب علم کو بیڈ الاٹ کریں
            </button>
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                <i class="fas fa-plus-circle me-1"></i> نیا کمرہ بنائیں
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
                        <div class="text-muted small">کل کمرے</div>
                        <h3 class="fw-bold text-primary mb-0"><?php echo $totalRooms; ?></h3>
                    </div>
                    <i class="fas fa-door-open fa-2x text-primary opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">کل بیڈز کی گنجائش</div>
                        <h3 class="fw-bold text-info mb-0"><?php echo $totalBeds; ?></h3>
                    </div>
                    <i class="fas fa-bed fa-2x text-info opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">مقیم طلبہ (پر بیڈز)</div>
                        <h3 class="fw-bold text-danger mb-0"><?php echo $occupiedBeds; ?></h3>
                    </div>
                    <i class="fas fa-user-check fa-2x text-danger opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">خالی بیڈز دستیاب</div>
                        <h3 class="fw-bold text-success mb-0"><?php echo $availableBeds; ?></h3>
                    </div>
                    <i class="fas fa-check-double fa-2x text-success opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-3 gap-2" id="hostelTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold px-4" id="rooms-tab" data-bs-toggle="pill" data-bs-target="#rooms-pane">
                <i class="fas fa-th-large me-1"></i> کمروں کا جائزہ (Visual Rooms)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4" id="residents-tab" data-bs-toggle="pill" data-bs-target="#residents-pane">
                <i class="fas fa-users me-1"></i> مقیم طلبہ کا ریکارڈ (Residents List)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="hostelTabsContent">
        <!-- TAB 1: Visual Rooms -->
        <div class="tab-pane fade show active" id="rooms-pane">
            <?php if (empty($rooms)): ?>
                <div class="card border-0 shadow-sm p-5 text-center text-muted">
                    <i class="fas fa-hotel fa-3x mb-3 text-secondary opacity-50"></i>
                    <h5>ابھی تک کوئی ہاسٹل روم نہیں بنایا گیا۔</h5>
                    <p>اوپر دیے گئے بٹن 'نیا کمرہ بنائیں' سے پہلا کمرہ شامل کریں۔</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($rooms as $rm): 
                        $rId = $rm['id'];
                        $rAllocs = $roomAllocMap[$rId] ?? [];
                        $occ = count($rAllocs);
                        $cap = (int)$rm['capacity_beds'];
                        $isFull = $occ >= $cap;
                    ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="card border-0 shadow-sm rounded-3 h-100 <?php echo $isFull ? 'border-top border-4 border-danger' : 'border-top border-4 border-success'; ?>">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h5 class="fw-bold mb-0 text-dark">
                                            کمرہ نمبر: <?php echo htmlspecialchars($rm['room_number']); ?>
                                            <?php if (!empty($rm['room_name'])): ?>
                                                <small class="text-muted fs-6">(<?php echo htmlspecialchars($rm['room_name']); ?>)</small>
                                            <?php endif; ?>
                                        </h5>
                                        <span class="badge <?php echo $isFull ? 'bg-danger' : 'bg-success'; ?>">
                                            <?php echo $occ . ' / ' . $cap; ?> بیڈز
                                        </span>
                                    </div>
                                    <div class="small text-muted mb-3">
                                        ماہانہ فیس: <strong>Rs. <?php echo number_format($rm['monthly_fee'], 0); ?></strong>
                                    </div>

                                    <!-- Visual Bed Badges -->
                                    <div class="border rounded-2 p-2 bg-light mb-3">
                                        <div class="fw-bold small text-secondary mb-2"><i class="fas fa-bed me-1"></i> بیڈز کی صورتحال:</div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php for ($b = 1; $b <= $cap; $b++): 
                                                $assigned = $rAllocs[$b - 1] ?? null;
                                            ?>
                                                <?php if ($assigned): ?>
                                                    <span class="badge bg-danger p-2 text-start" title="<?php echo htmlspecialchars($assigned['student_name']); ?>">
                                                        <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($assigned['bed_number'] ?: "بیڈ $b"); ?>: <?php echo htmlspecialchars(mb_substr($assigned['student_name'], 0, 10)); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-outline-success border border-success text-success p-2">
                                                        <i class="fas fa-check me-1"></i> بیڈ <?php echo $b; ?> (خالی)
                                                    </span>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <!-- Card Actions -->
                                    <div class="d-flex justify-content-between align-items-center mt-auto">
                                        <button class="btn btn-sm btn-outline-warning fw-bold" onclick="openAllocForRoom('<?php echo $rId; ?>')" <?php echo $isFull ? 'disabled' : ''; ?>>
                                            <i class="fas fa-user-plus me-1"></i> طالب علم الاٹ کریں
                                        </button>
                                        <a href="hostel.php?delete_room=<?php echo $rId; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ کمرہ حذف کرنا چاہتے ہیں؟');">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 2: Residents List -->
        <div class="tab-pane fade" id="residents-pane">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-3">
                    <h5 class="fw-bold mb-3"><i class="fas fa-user-check me-2 text-primary"></i> مقیم طلبہ کی جامع رپورٹ</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>داخلہ نمبر</th>
                                    <th>نامِ طالب علم</th>
                                    <th>کلاس</th>
                                    <th>کمرہ نمبر</th>
                                    <th>بیڈ نمبر</th>
                                    <th>ماہانہ ہاسٹل فیس</th>
                                    <th>الاٹمنٹ تاریخ</th>
                                    <th>ایکشن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allocations)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">فی الوقت کوئی طالب علم ہاسٹل میں الاٹ نہیں ہے۔</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allocations as $al): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($al['admission_number']); ?></span></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($al['student_name']); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($al['class'] ?: '-'); ?></span></td>
                                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($al['room_number']); ?></td>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($al['bed_number']); ?></span></td>
                                            <td class="fw-bold text-success">Rs. <?php echo number_format($al['monthly_fee'], 0); ?></td>
                                            <td><?php echo htmlspecialchars($al['allocation_date']); ?></td>
                                            <td>
                                                <a href="hostel.php?vacate_id=<?php echo $al['id']; ?>" class="btn btn-sm btn-outline-danger fw-bold" onclick="return confirm('کیا آپ واقعی اس طالب علم کو کمرے سے فارغ / خارج کرنا چاہتے ہیں؟');">
                                                    <i class="fas fa-sign-out-alt me-1"></i> بیڈ خالی کریں
                                                </a>
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

<!-- Modal: Add New Room -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-1"></i> نیا کمرہ بنائیں</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">کمرہ نمبر <span class="text-danger">*</span></label>
                        <input type="text" name="room_number" class="form-control" required placeholder="مثلاً 101، A-2">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">کمرے کا نام (اختیاری)</label>
                        <input type="text" name="room_name" class="form-control" placeholder="مثلاً دار الحفظ، ابو حنیفہ ہال">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">بیڈز کی گنجائش <span class="text-danger">*</span></label>
                        <input type="number" name="capacity_beds" class="form-control" value="4" min="1" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">ماہانہ ہاسٹل فیس (روپے)</label>
                        <input type="number" step="0.01" name="monthly_fee" class="form-control" value="2000" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">تفصیل / ہدایات</label>
                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="add_room" class="btn btn-success fw-bold">کمرہ محفوظ کریں</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Allocate Student -->
<div class="modal fade" id="allocModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-1"></i> طالب علم کو ہاسٹل الاٹ کریں</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">کمرہ منتخب کریں <span class="text-danger">*</span></label>
                    <select name="room_id" id="alloc_room_id" class="form-select" required>
                        <option value="">-- کمرہ منتخب کریں --</option>
                        <?php foreach ($rooms as $rm): ?>
                            <option value="<?php echo $rm['id']; ?>">
                                کمرہ نمبر: <?php echo htmlspecialchars($rm['room_number']) . " (گنجائش: {$rm['capacity_beds']} بیڈز, فیس: Rs. {$rm['monthly_fee']})"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">طالب علم منتخب کریں <span class="text-danger">*</span></label>
                    <select name="student_id" class="form-select" required>
                        <option value="">-- طالب علم منتخب کریں --</option>
                        <?php foreach ($students as $st): ?>
                            <option value="<?php echo $st['id']; ?>">
                                <?php echo htmlspecialchars($st['name']) . " ({$st['admission_number']} - {$st['class']})"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">بیڈ نمبر (اختیاری)</label>
                        <input type="text" name="bed_number" class="form-control" placeholder="آٹو جنریٹ">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">الاٹمنٹ تاریخ</label>
                        <input type="date" name="allocation_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">نوٹس / سامان کی تفصیل</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="الماری، بستر وغیرہ"></textarea>
                </div>
                <div class="alert alert-info py-2 small mb-0">
                    <i class="fas fa-info-circle me-1"></i> الاٹمنٹ کے بعد طالب علم کا اسٹیٹس خودکار طور پر 'مقیم' ہو جائے گا اور ماہانہ انوائس بننے پر ہاسٹل فیس شامل ہوگی۔
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">منسوخ</button>
                <button type="submit" name="allocate_student" class="btn btn-warning fw-bold text-dark">الاٹمنٹ محفوظ کریں</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAllocForRoom(roomId) {
    document.getElementById('alloc_room_id').value = roomId;
    const modal = new bootstrap.Modal(document.getElementById('allocModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>
