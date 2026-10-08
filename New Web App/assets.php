<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

try {
    $pdo->exec("RENAME TABLE masjid_assets TO assets");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE assets ADD COLUMN system_type VARCHAR(50) DEFAULT 'Masjid' AFTER name");
} catch (Exception $e) {}

$system_type = $_SESSION['system_type'] ?? 'Masjid';
if (!isset($_SESSION['user_id']) || !app_has_perm('manage_finance')) { header("Location: dashboard.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->query("UPDATE assets SET is_deleted = 1, is_synced = 0 WHERE id = $id AND system_type = '$system_type'");
    } else {
        $name = $_POST["name"] ?? "";
        $category = $_POST["category"] ?? "";
        $quantity = (int)($_POST["quantity"] ?? 0);
        $condition_status = $_POST["condition_status"] ?? "";
        $asset_type = $_POST["asset_type"] ?? "Purchased";
        
        $donor_name = "";
        $donor_father = "";
        $donor_address = "";
        $donor_phone = "";
        
        if ($asset_type === 'Waqf') {
            $donor_name = $_POST["donor_name"] ?? "";
            $donor_father = $_POST["donor_father"] ?? "";
            $donor_address = $_POST["donor_address"] ?? "";
            $donor_phone = $_POST["donor_phone"] ?? "";
        }

        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE assets SET name=?, category=?, quantity=?, condition_status=?, asset_type=?, donor_name=?, donor_father=?, donor_address=?, donor_phone=?, is_synced=0 WHERE id=? AND system_type=?");
            $stmt->execute([$name, $category, $quantity, $condition_status, $asset_type, $donor_name, $donor_father, $donor_address, $donor_phone, $id, $system_type]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO assets (name, system_type, category, quantity, condition_status, asset_type, donor_name, donor_father, donor_address, donor_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $system_type, $category, $quantity, $condition_status, $asset_type, $donor_name, $donor_father, $donor_address, $donor_phone]);
        }
    }
    header("Location: assets.php");
    exit;
}

$records = $pdo->query("SELECT * FROM assets WHERE (is_deleted = 0 OR is_deleted IS NULL) AND system_type = '$system_type' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$title = "اثاثہ جات";
include 'header.php';
?>

<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-danger"><i class="fas fa-boxes me-2"></i> اثاثہ جات (Assets)</h3>
        <button class="btn btn-danger fw-bold" onclick="openAddModal()"><i class="fas fa-plus me-1"></i> نیا اثاثہ شامل کریں</button>
    </div>

    <div class="card shadow-sm border-0 neobox">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>نام اثاثہ</th>
                        <th>کیٹیگری</th>
                        <th>تعداد / حالت</th>
                        <th>قسم (Type)</th>
                        <th>تفصیل (اگر وقف ہے)</th>
                        <th class="text-center">ایکشن</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($records as $row): ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars((string)$row["name"]); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)$row["category"]); ?></span></td>
                        <td>
                            تعداد: <?php echo htmlspecialchars((string)$row["quantity"]); ?><br>
                            <small class="text-muted">حالت: <?php echo htmlspecialchars((string)$row["condition_status"]); ?></small>
                        </td>
                        <td>
                            <?php if($row['asset_type'] == 'Waqf'): ?>
                                <span class="badge bg-success">وقف (Waqf)</span>
                            <?php else: ?>
                                <span class="badge bg-primary">خریدا گیا (Purchased)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['asset_type'] == 'Waqf'): ?>
                                <strong>نام:</strong> <?php echo htmlspecialchars((string)$row["donor_name"]); ?> 
                                <?php if($row['donor_father']): ?>بن <?php echo htmlspecialchars((string)$row["donor_father"]); ?><?php endif; ?><br>
                                <small class="text-muted">
                                    <?php if($row['donor_phone']): ?><i class="fas fa-phone ms-1"></i><?php echo htmlspecialchars((string)$row["donor_phone"]); ?><?php endif; ?>
                                    <?php if($row['donor_address']): ?><i class="fas fa-map-marker-alt ms-1"></i><?php echo htmlspecialchars((string)$row["donor_address"]); ?><?php endif; ?>
                                </small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='editRecord(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-edit"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('کیا آپ یہ ریکارڈ حذف کرنا چاہتے ہیں؟');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($records)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">کوئی ریکارڈ نہیں ملا</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="assetModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="assetForm">
        <input type="hidden" name="id" id="asset_id">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalTitle">اثاثہ شامل کریں</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">نام اثاثہ</label>
                    <input type="text" name="name" id="asset_name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">کیٹیگری (مثلاً الیکٹرانکس، فرنیچر)</label>
                    <input type="text" name="category" id="asset_category" class="form-control" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">تعداد</label>
                    <input type="number" name="quantity" id="asset_qty" class="form-control" required min="1" value="1">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">حالت (Condition)</label>
                    <select name="condition_status" id="asset_condition" class="form-select" required>
                        <option value="نیا (New)">نیا (New)</option>
                        <option value="استعمال شدہ (Used - Good)">استعمال شدہ (Used - Good)</option>
                        <option value="مرمت طلب (Needs Repair)">مرمت طلب (Needs Repair)</option>
                        <option value="ناکارہ (Damaged/Broken)">ناکارہ (Damaged/Broken)</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">قسم (Asset Type)</label>
                <div class="d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="asset_type" id="typePurchased" value="Purchased" checked onchange="toggleWaqfFields()">
                        <label class="form-check-label" for="typePurchased">خریدا گیا (Purchased)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="asset_type" id="typeWaqf" value="Waqf" onchange="toggleWaqfFields()">
                        <label class="form-check-label" for="typeWaqf">وقف (Waqf)</label>
                    </div>
                </div>
            </div>

            <div id="waqfFields" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #dee2e6;">
                <h6 class="fw-bold text-success mb-3"><i class="fas fa-hand-holding-heart me-2"></i> وقف کنندہ کی تفصیلات</h6>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">نام</label>
                        <input type="text" name="donor_name" id="donor_name" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ولدیت</label>
                        <input type="text" name="donor_father" id="donor_father" class="form-control">
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-6">
                        <label class="form-label">فون نمبر</label>
                        <input type="text" name="donor_phone" id="donor_phone" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">پتہ</label>
                        <input type="text" name="donor_address" id="donor_address" class="form-control">
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بند کریں</button>
          <button type="submit" class="btn btn-danger">محفوظ کریں</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleWaqfFields() {
    if (document.getElementById('typeWaqf').checked) {
        document.getElementById('waqfFields').style.display = 'block';
        document.getElementById('donor_name').required = true;
    } else {
        document.getElementById('waqfFields').style.display = 'none';
        document.getElementById('donor_name').required = false;
    }
}

function openAddModal() {
    document.getElementById('assetForm').reset();
    document.getElementById('asset_id').value = '';
    document.getElementById('modalTitle').innerText = 'اثاثہ شامل کریں';
    document.getElementById('typePurchased').checked = true;
    toggleWaqfFields();
    new bootstrap.Modal(document.getElementById('assetModal')).show();
}

function editRecord(row) {
    document.getElementById('asset_id').value = row.id || '';
    document.getElementById('asset_name').value = row.name || '';
    document.getElementById('asset_category').value = row.category || '';
    document.getElementById('asset_qty').value = row.quantity || '1';
    
    // Set condition if exists
    let conditionSelect = document.getElementById('asset_condition');
    let conditionVal = row.condition_status || 'نیا (New)';
    // ensure value exists in options, else just pick first
    if(Array.from(conditionSelect.options).some(o => o.value === conditionVal)) {
        conditionSelect.value = conditionVal;
    } else {
        conditionSelect.selectedIndex = 0;
    }

    if (row.asset_type === 'Waqf') {
        document.getElementById('typeWaqf').checked = true;
        document.getElementById('donor_name').value = row.donor_name || '';
        document.getElementById('donor_father').value = row.donor_father || '';
        document.getElementById('donor_phone').value = row.donor_phone || '';
        document.getElementById('donor_address').value = row.donor_address || '';
    } else {
        document.getElementById('typePurchased').checked = true;
    }
    
    toggleWaqfFields();
    document.getElementById('modalTitle').innerText = 'اثاثہ میں ترمیم';
    new bootstrap.Modal(document.getElementById('assetModal')).show();
}
</script>

<?php include 'footer.php'; ?>
