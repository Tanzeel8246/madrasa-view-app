<?php 
require_once 'db.php'; 
include 'header.php'; 

if (($_SESSION['role'] ?? '') !== 'admin') { 
    echo "<script>window.location='dashboard.php';</script>"; 
    exit; 
}

function next_user_receipt_no(PDO $pdo, $userId) {
    $userId = max(1, (int)$userId);
    $minNo = $userId * 100000;
    $maxNo = ($userId + 1) * 100000;
    $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ?");
    $stmt->execute([$minNo, $maxNo]);
    $currentMax = (int)$stmt->fetchColumn();
    $nextNo = ($currentMax > $minNo) ? ($currentMax + 1) : ($minNo + 1);
    if ($nextNo >= $maxNo) {
        throw new Exception("Receipt number quota exhausted for this user.");
    }
    return $nextNo;
}

$msg = '';
$msgType = 'success';

// --- Deletion Actions ---
if (isset($_GET['del_fee'])) {
    $delId = (int)$_GET['del_fee'];
    $pdo->prepare("DELETE FROM fee_submissions WHERE id = ?")->execute([$delId]);
    $msg = "آن لائن فیس/تعاون انٹری کامیابی سے حذف کر دی گئی!";
}

if (isset($_GET['del_leave'])) {
    $delId = (int)$_GET['del_leave'];
    $pdo->prepare("DELETE FROM leave_applications WHERE id = ?")->execute([$delId]);
    $msg = "چھٹی کی درخواست کامیابی سے حذف کر دی گئی!";
}

if (isset($_GET['del_complaint'])) {
    $delId = (int)$_GET['del_complaint'];
    $pdo->prepare("DELETE FROM parent_complaints WHERE id = ?")->execute([$delId]);
    $msg = "والدین کی شکایت کامیابی سے حذف کر دی گئی!";
}

// --- 1. فیس / تعاون ایکشن ---
if (isset($_POST['update_fee'])) {
    $id = (int)$_POST['id']; 
    $status = $_POST['status'] ?? 'Pending';
    
    if ($status == 'Approved') {
        $f = $pdo->query("SELECT f.*, s.name FROM fee_submissions f JOIN students s ON f.student_id = s.id WHERE f.id = $id")->fetch();
        if ($f) {
            $adminId = $_SESSION['user_id'] ?? 1;
            $adminName = $_SESSION['name'] ?? 'Admin';
            $compNo = next_user_receipt_no($pdo, $adminId);
            
            $desc = $f['description'] ? $f['description'] . " (تعاون ID: $id)" : "Online Cooperation/Fee (ID: $id)";
            $head_id = $f['head_id'] ? $f['head_id'] : null;
            
            $stmt = $pdo->prepare("INSERT INTO finance (date, type, amount, description, donor_name, head_id, computer_receipt_no, user_id, user_name) VALUES (?, 'Income', ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([date('Y-m-d'), $f['amount'], $desc, $f['name'], $head_id, $compNo, $adminId, $adminName]);
            $finance_id = $pdo->lastInsertId();
            
            require_once 'finance_accounting_bridge.php';
            finance_accounting_post($pdo, (int)$finance_id);
            
            $pdo->prepare("UPDATE fee_submissions SET status = ?, finance_id = ? WHERE id = ?")->execute([$status, $finance_id, $id]);
            $msg = "آن لائن فیس منظور کر کے آمدن فنانس میں شامل کر دی گئی اور اکاؤنٹنگ جرنل میں درج ہو گئی!";
        }
    } else {
        $pdo->prepare("UPDATE fee_submissions SET status = ? WHERE id = ?")->execute([$status, $id]);
        $msg = "فیس کا سٹیٹس اپڈیٹ ہو گیا!";
    }
}

// --- 2. چھٹی ایکشن ---
if (isset($_POST['update_leave'])) {
    $id = (int)$_POST['id'];
    $status = $_POST['status'] ?? 'Pending';
    $action = $_POST['action_type'] ?? '';

    $pdo->prepare("UPDATE leave_applications SET status = ? WHERE id = ?")->execute([$status, $id]);

    if ($action == 'AddToReport' && $status == 'Approved') {
        $l = $pdo->query("SELECT * FROM leave_applications WHERE id = $id")->fetch();
        if ($l) {
            $final_msg = "[چھٹی کی درخواست]: " . $l['reason'] . " (" . $l['date_from'] . " تا " . $l['date_to'] . ")";
            $pdo->prepare("INSERT INTO complaints (student_id, date, complaint) VALUES (?, ?, ?)")->execute([$l['student_id'], $l['date_from'], $final_msg]);
            $msg = "چھٹی منظور کر لی گئی اور طالب علم کی رپورٹ میں شامل کر دی گئی!";
            $msgType = 'info';
        }
    } else {
        $msg = "چھٹی کا سٹیٹس اپڈیٹ ہو گیا!";
    }
}

// --- 3. والدین کی شکایت ایکشن ---
if (isset($_POST['action_complaint'])) {
    $id = (int)$_POST['id'];
    $action = $_POST['action_type'] ?? 'Viewed';
    
    $pdo->prepare("UPDATE parent_complaints SET status = 'Viewed' WHERE id = ?")->execute([$id]);

    if ($action == 'AddToReport') {
        $pc = $pdo->query("SELECT * FROM parent_complaints WHERE id = $id")->fetch();
        if ($pc) {
            $final_msg = "[والدین کی طرف سے]: " . $pc['category'] . " - " . $pc['description'];
            $pdo->prepare("INSERT INTO complaints (student_id, date, complaint) VALUES (?, ?, ?)")->execute([$pc['student_id'], $pc['date'], $final_msg]);
            $msg = "شکایت پورٹل پر دیکھ لی گئی اور طالب علم کی آفیشل رپورٹ میں ڈال دی گئی!";
        }
    } else {
        $msg = "شکایت دیکھ لی گئی!";
    }
}

// Fetch Data
$fees = $pdo->query("SELECT f.*, s.name as student_name, s.father_name, s.class, h.name as head_name FROM fee_submissions f JOIN students s ON f.student_id = s.id LEFT JOIN heads h ON f.head_id = h.id ORDER BY f.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$leaves = $pdo->query("SELECT l.*, s.name as student_name, s.father_name, s.class FROM leave_applications l JOIN students s ON l.student_id = s.id ORDER BY l.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$complaints = $pdo->query("SELECT pc.*, s.name as student_name, s.father_name, s.class FROM parent_complaints pc JOIN students s ON pc.student_id = s.id ORDER BY pc.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary mb-0"><i class="fas fa-tasks me-2"></i> درخواستیں و شکایات پورٹل</h3>
        <span class="badge bg-primary fs-6">والدین و طلبہ کی ریکوئسٹس</span>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4 gap-2" id="requestTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold px-4 py-2" id="fees-tab" data-bs-toggle="tab" data-bs-target="#fees" type="button">
                <i class="fas fa-hand-holding-usd me-2"></i> آن لائن تعاون / فیس
                <?php 
                $pendingFeeCount = count(array_filter($fees, fn($x) => $x['status'] === 'Pending'));
                if ($pendingFeeCount > 0): 
                ?>
                    <span class="badge bg-danger rounded-pill ms-2"><?php echo $pendingFeeCount; ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4 py-2" id="leaves-tab" data-bs-toggle="tab" data-bs-target="#leaves" type="button">
                <i class="fas fa-envelope-open-text me-2"></i> چھٹی کی درخواستیں
                <?php 
                $pendingLeaveCount = count(array_filter($leaves, fn($x) => $x['status'] === 'Pending'));
                if ($pendingLeaveCount > 0): 
                ?>
                    <span class="badge bg-danger rounded-pill ms-2"><?php echo $pendingLeaveCount; ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold px-4 py-2" id="complaints-tab" data-bs-toggle="tab" data-bs-target="#complaints" type="button">
                <i class="fas fa-exclamation-triangle me-2"></i> والدین کی شکایات
                <?php 
                $pendingCompCount = count(array_filter($complaints, fn($x) => $x['status'] === 'Pending'));
                if ($pendingCompCount > 0): 
                ?>
                    <span class="badge bg-danger rounded-pill ms-2"><?php echo $pendingCompCount; ?></span>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="requestTabsContent">
        <!-- Tab 1: Fee Submissions -->
        <div class="tab-pane fade show active" id="fees" role="tabpanel">
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title fw-bold mb-0 text-primary"><i class="fas fa-money-check-alt me-2"></i> موصول شدہ آن لائن تعاون / فیس کی فہرست</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>طالب علم</th>
                                <th>کلاس</th>
                                <th>تاریخ</th>
                                <th>رقم</th>
                                <th>مد (حساب)</th>
                                <th>تفصیل</th>
                                <th>رسید (ثبوت)</th>
                                <th>سٹیٹس</th>
                                <th class="text-center">کارروائی</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fees)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">کوئی فیس انٹری موصول نہیں ہوئی۔</td></tr>
                            <?php else: ?>
                                <?php foreach($fees as $f): ?>
                                <tr>
                                    <td class="fw-bold">#<?php echo $f['id']; ?></td>
                                    <td class="fw-bold text-dark"><?php echo htmlspecialchars($f['student_name']); ?> <small class="text-muted">(والد: <?php echo htmlspecialchars($f['father_name']); ?>)</small></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($f['class']); ?></span></td>
                                    <td><?php echo date('d-M-Y', strtotime($f['submission_date'])); ?></td>
                                    <td class="fw-bold text-success">Rs. <?php echo number_format($f['amount']); ?>/-</td>
                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($f['head_name'] ?? 'تعاون'); ?></span></td>
                                    <td><small><?php echo htmlspecialchars($f['description'] ?? '---'); ?></small></td>
                                    <td>
                                        <?php if (!empty($f['screenshot'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showImgModal('<?php echo htmlspecialchars($f['screenshot']); ?>', 'رسید کا ثبوت')"><i class="fas fa-image me-1"></i> ثبوت دیکھیں</button>
                                        <?php else: ?>
                                            <span class="text-muted">نہیں ہے</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($f['status'] == 'Pending'): ?>
                                            <span class="badge bg-warning text-dark">پینڈنگ</span>
                                        <?php elseif($f['status'] == 'Approved'): ?>
                                            <span class="badge bg-success">منظور شدہ</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">مسترد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <?php if($f['status'] == 'Pending'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                                    <input type="hidden" name="status" value="Approved">
                                                    <button type="submit" name="update_fee" class="btn btn-sm btn-success" onclick="return confirm('کیا آپ یہ فیس منظور کر کے فنانس آمدن میں شامل کرنا چاہتے ہیں؟')"><i class="fas fa-check me-1"></i> منظور</button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                                    <input type="hidden" name="status" value="Rejected">
                                                    <button type="submit" name="update_fee" class="btn btn-sm btn-danger"><i class="fas fa-times me-1"></i> مسترد</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (!empty($f['finance_id'])): ?>
                                                <a href="print_receipt.php?id=<?php echo $f['finance_id']; ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-print me-1"></i> رسید</a>
                                            <?php endif; ?>
                                            <a href="?del_fee=<?php echo $f['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ انٹری ڈیلیٹ کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
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

        <!-- Tab 2: Leave Applications -->
        <div class="tab-pane fade" id="leaves" role="tabpanel">
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title fw-bold mb-0 text-primary"><i class="fas fa-user-clock me-2"></i> موصول شدہ چھٹی کی درخواستیں</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>طالب علم</th>
                                <th>کلاس</th>
                                <th>تاریخ (مدت)</th>
                                <th>وجہ</th>
                                <th>درخواست (فائل)</th>
                                <th>سٹیٹس</th>
                                <th class="text-center">کارروائی</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leaves)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">کوئی چھٹی کی درخواست موصول نہیں ہوئی۔</td></tr>
                            <?php else: ?>
                                <?php foreach($leaves as $l): ?>
                                <tr>
                                    <td class="fw-bold">#<?php echo $l['id']; ?></td>
                                    <td class="fw-bold text-dark"><?php echo htmlspecialchars($l['student_name']); ?> <small class="text-muted">(والد: <?php echo htmlspecialchars($l['father_name']); ?>)</small></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($l['class']); ?></span></td>
                                    <td><?php echo date('d-M-Y', strtotime($l['date_from']))." تا ".date('d-M-Y', strtotime($l['date_to'])); ?></td>
                                    <td><small><?php echo htmlspecialchars($l['reason']); ?></small></td>
                                    <td>
                                        <?php if(!empty($l['application_photo'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showImgModal('<?php echo htmlspecialchars($l['application_photo']); ?>', 'درخواست کی تصویر')"><i class="fas fa-file-image me-1"></i> دیکھیں</button>
                                        <?php else: ?>
                                            <span class="text-muted">نہیں ہے</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($l['status']=='Pending'): ?>
                                            <span class="badge bg-warning text-dark">پینڈنگ</span>
                                        <?php elseif($l['status']=='Approved'): ?>
                                            <span class="badge bg-success">منظور</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">مسترد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
                                                <input type="hidden" name="action_type" id="l_act_<?php echo $l['id']; ?>" value="">
                                                <input type="hidden" name="status" id="l_stat_<?php echo $l['id']; ?>" value="">
                                                
                                                <?php if($l['status'] == 'Pending'): ?>
                                                    <button type="submit" name="update_leave" class="btn btn-sm btn-success" onclick="document.getElementById('l_act_<?php echo $l['id']; ?>').value=''; document.getElementById('l_stat_<?php echo $l['id']; ?>').value='Approved';">منظور</button>
                                                    <button type="submit" name="update_leave" class="btn btn-sm btn-danger" onclick="document.getElementById('l_act_<?php echo $l['id']; ?>').value=''; document.getElementById('l_stat_<?php echo $l['id']; ?>').value='Rejected';">مسترد</button>
                                                <?php endif; ?>

                                                <button type="submit" name="update_leave" class="btn btn-sm btn-dark" onclick="document.getElementById('l_act_<?php echo $l['id']; ?>').value='AddToReport'; document.getElementById('l_stat_<?php echo $l['id']; ?>').value='Approved';">
                                                    رپورٹ میں ڈالیں
                                                </button>
                                            </form>
                                            <a href="?del_leave=<?php echo $l['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ انٹری ڈیلیٹ کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
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

        <!-- Tab 3: Complaints -->
        <div class="tab-pane fade" id="complaints" role="tabpanel">
            <div class="card shadow-sm border-0 neobox">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title fw-bold mb-0 text-primary"><i class="fas fa-exclamation-circle me-2"></i> موصول شدہ شکایات</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>تاریخ</th>
                                <th>طالب علم</th>
                                <th>کلاس</th>
                                <th>نوعیت</th>
                                <th>تفصیل</th>
                                <th>ثبوت (فائل)</th>
                                <th>سٹیٹس</th>
                                <th class="text-center">کارروائی</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($complaints)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">کوئی شکایت موصول نہیں ہوئی۔</td></tr>
                            <?php else: ?>
                                <?php foreach($complaints as $pc): ?>
                                <tr>
                                    <td class="fw-bold">#<?php echo $pc['id']; ?></td>
                                    <td><?php echo date('d-M-Y', strtotime($pc['date'])); ?></td>
                                    <td class="fw-bold text-dark"><?php echo htmlspecialchars($pc['student_name']); ?> <small class="text-muted">(والد: <?php echo htmlspecialchars($pc['father_name']); ?>)</small></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($pc['class']); ?></span></td>
                                    <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($pc['category']); ?></span></td>
                                    <td><small><?php echo htmlspecialchars($pc['description']); ?></small></td>
                                    <td>
                                        <?php if (!empty($pc['evidence'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showImgModal('<?php echo htmlspecialchars($pc['evidence']); ?>', 'شکایت کا ثبوت')"><i class="fas fa-image me-1"></i> ثبوت دیکھیں</button>
                                        <?php else: ?>
                                            <span class="text-muted">نہیں ہے</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($pc['status'] == 'Pending'): ?>
                                            <span class="badge bg-danger">نیا میسج</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">دیکھ لیا</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="id" value="<?php echo $pc['id']; ?>">
                                                <input type="hidden" name="action_type" id="act_type_<?php echo $pc['id']; ?>" value="Viewed">
                                                
                                                <button type="submit" name="action_complaint" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('act_type_<?php echo $pc['id']; ?>').value='Viewed'">
                                                    صرف دیکھیں
                                                </button>
                                                
                                                <button type="submit" name="action_complaint" class="btn btn-sm btn-danger" onclick="document.getElementById('act_type_<?php echo $pc['id']; ?>').value='AddToReport'">
                                                    <i class="fas fa-file-alt me-1"></i> رپورٹ میں ڈالیں
                                                </button>
                                            </form>
                                            <a href="?del_complaint=<?php echo $pc['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ انٹری ڈیلیٹ کرنا چاہتے ہیں؟')"><i class="fas fa-trash"></i></a>
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
</div>

<!-- Image Preview Modal -->
<div class="modal fade" id="imgPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius:15px;">
            <div class="modal-header text-white fw-bold" style="background:#284D3D;">
                <h5 class="modal-title text-white" id="imgModalTitle"><i class="fas fa-image me-2"></i></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-3">
                <img id="imgModalSrc" src="" alt="preview" class="img-fluid rounded shadow" style="max-height:75vh;">
            </div>
            <div class="modal-footer">
                <a id="imgModalDownload" href="#" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt me-1"></i> نئے ٹیب میں کھولیں</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بند کریں</button>
            </div>
        </div>
    </div>
</div>

<script>
function showImgModal(src, title) {
    document.getElementById('imgModalSrc').src = src;
    document.getElementById('imgModalTitle').innerHTML = '<i class="fas fa-image me-2"></i> ' + title;
    document.getElementById('imgModalDownload').href = src;
    var modal = new bootstrap.Modal(document.getElementById('imgPreviewModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>