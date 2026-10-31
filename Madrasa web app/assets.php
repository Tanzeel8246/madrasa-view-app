<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

try {
    $pdo->exec("RENAME TABLE masjid_assets TO assets");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE assets ADD COLUMN system_type VARCHAR(50) DEFAULT 'Masjid' AFTER name");
} catch (Exception $e) {}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$system_type = $_SESSION['system_type'] ?? 'Madrasa';
$cur_tenant_id = (int)get_current_tenant_id();
$is_admin = in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'superadmin', 'super_admin', 'administrator'], true);
$perm_key = ($system_type === 'Masjid') ? 'manage_masjid_assets' : 'manage_assets';
if (!$is_admin && !app_has_perm($perm_key) && !app_has_perm('manage_assets') && !app_has_perm('manage_masjid_assets')) {
    header("Location: " . ($system_type === 'Masjid' ? "masjid_dashboard.php" : "dashboard.php"));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE assets SET is_deleted = 1, is_synced = 0 WHERE id = ? AND system_type = ? AND tenant_id = ?")->execute([$id, $system_type, $cur_tenant_id]);
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
            $stmt = $pdo->prepare("UPDATE assets SET name=?, category=?, quantity=?, condition_status=?, asset_type=?, donor_name=?, donor_father=?, donor_address=?, donor_phone=?, is_synced=0 WHERE id=? AND system_type=? AND tenant_id=?");
            $stmt->execute([$name, $category, $quantity, $condition_status, $asset_type, $donor_name, $donor_father, $donor_address, $donor_phone, $id, $system_type, $cur_tenant_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO assets (name, system_type, category, quantity, condition_status, asset_type, donor_name, donor_father, donor_address, donor_phone, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $system_type, $category, $quantity, $condition_status, $asset_type, $donor_name, $donor_father, $donor_address, $donor_phone, $cur_tenant_id]);
        }
    }
    header("Location: assets.php");
    exit;
}

$sys_cond = ($system_type === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$records = $pdo->query("SELECT * FROM assets WHERE (is_deleted = 0 OR is_deleted IS NULL) AND $sys_cond AND tenant_id = $cur_tenant_id ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$title = ($system_type === 'Masjid') ? "اثاثہ جات (مسجد)" : "اثاثہ جات (مدرسہ)";
include 'header.php';
?>

<div class="container py-4" dir="<?php echo ($lang === 'ur') ? 'rtl' : 'ltr'; ?>">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-danger"><i class="fas fa-boxes me-2"></i> <?php echo __('اثاثہ جات (Assets)', 'Assets Management'); ?></h3>
        <button class="btn btn-danger fw-bold" onclick="openAddModal()"><i class="fas fa-plus me-1"></i> <?php echo __('نیا اثاثہ شامل کریں', 'Add Asset'); ?></button>
    </div>

    <div class="card shadow-sm border-0 neobox">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php echo __('اثاثہ کا نام', 'Asset Name'); ?></th>
                        <th><?php echo __('شعبہ / کیٹیگری', 'Category'); ?></th>
                        <th><?php echo __('تعداد / حالت', 'Quantity / Condition'); ?></th>
                        <th><?php echo __('قسم (Type)', 'Asset Type'); ?></th>
                        <th><?php echo __('واقف / ذریعہ', 'Donor / Source'); ?></th>
                        <th class="text-center"><?php echo __('کارروائی', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($records as $row): ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars((string)$row["name"]); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)$row["category"]); ?></span></td>
                        <td>
                            : <?php echo htmlspecialchars((string)$row["quantity"]); ?><br>
                            <small class="text-muted">: <?php echo htmlspecialchars((string)$row["condition_status"]); ?></small>
                        </td>
                        <td>
                            <?php if($row['asset_type'] == 'Waqf'): ?>
                                <span class="badge bg-success"><?php echo __('وقف (Waqf)', 'Waqf'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary"><?php echo __('خریداری (Purchased)', 'Purchased'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['asset_type'] == 'Waqf'): ?>
                                <strong>:</strong> <?php echo htmlspecialchars((string)$row["donor_name"]); ?> 
                                <?php if($row['donor_father']): ?> <?php echo htmlspecialchars((string)$row["donor_father"]); ?><?php endif; ?><br>
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
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo __('کیا آپ واقعی یہ اثاثہ حذف کرنا چاہتے ہیں؟', 'Are you sure you want to delete this asset?'); ?>');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($records)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?php echo __('کوئی اثاثہ درج نہیں ہے۔', 'No assets recorded.'); ?></td></tr>
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
          <h5 class="modal-title" id="modalTitle"><?php echo __('نیا اثاثہ شامل کریں', 'Add Asset'); ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold"><?php echo __('اثاثہ کا نام', 'Asset Name'); ?></label>
                    <input type="text" name="name" id="asset_name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold"><?php echo __('کیٹیگری / شعبہ', 'Category / Department'); ?></label>
                    <input type="text" name="category" id="asset_category" class="form-control" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold"><?php echo __('تعداد', 'Quantity'); ?></label>
                    <input type="number" name="quantity" id="asset_qty" class="form-control" required min="1" value="1">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold"><?php echo __('حالت (Condition)', 'Condition'); ?></label>
                    <select name="condition_status" id="asset_condition" class="form-select" required>
                        <option value=" (New)"> (New)</option>
                        <option value="  (Used - Good)">  (Used - Good)</option>
                        <option value="  (Needs Repair)">  (Needs Repair)</option>
                        <option value=" (Damaged/Broken)"> (Damaged/Broken)</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold"><?php echo __('اثاثہ کی قسم', 'Asset Type'); ?></label>
                <div class="d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="asset_type" id="typePurchased" value="Purchased" checked onchange="toggleWaqfFields()">
                        <label class="form-check-label" for="typePurchased"><?php echo __('خریداری (Purchased)', 'Purchased'); ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="asset_type" id="typeWaqf" value="Waqf" onchange="toggleWaqfFields()">
                        <label class="form-check-label" for="typeWaqf"><?php echo __('وقف (Waqf)', 'Waqf'); ?></label>
                    </div>
                </div>
            </div>

            <div id="waqfFields" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #dee2e6;">
                <h6 class="fw-bold text-success mb-3"><i class="fas fa-hand-holding-heart me-2"></i> <?php echo __('واقف / عطیہ دہندہ کی تفصیلات', 'Waqf / Donor Details'); ?></h6>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label"></label>
                        <input type="text" name="donor_name" id="donor_name" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"></label>
                        <input type="text" name="donor_father" id="donor_father" class="form-control">
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-6">
                        <label class="form-label"> </label>
                        <input type="text" name="donor_phone" id="donor_phone" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"></label>
                        <input type="text" name="donor_address" id="donor_address" class="form-control">
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('منسوخ', 'Cancel'); ?></button>
          <button type="submit" class="btn btn-danger"><?php echo __('محفوظ کریں', 'Save Asset'); ?></button>
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
    document.getElementById('modalTitle').innerText = '<?php echo __('نیا اثاثہ شامل کریں', 'Add Asset'); ?>';
    document.getElementById('typePurchased').checked = true;
    toggleWaqfFields(); // edit_modal
    new bootstrap.Modal(document.getElementById('assetModal')).show();
}

function editRecord(row) {
    document.getElementById('asset_id').value = row.id || '';
    document.getElementById('asset_name').value = row.name || '';
    document.getElementById('asset_category').value = row.category || '';
    document.getElementById('asset_qty').value = row.quantity || '1';
    
    // Set condition if exists
    let conditionSelect = document.getElementById('asset_condition');
    let conditionVal = row.condition_status || ' (New)';
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
    
    toggleWaqfFields(); // edit_modal
    document.getElementById('modalTitle').innerText = '<?php echo __('نیا اثاثہ شامل کریں', 'Add Asset'); ?>';
    new bootstrap.Modal(document.getElementById('assetModal')).show();
}
</script>

<?php include 'footer.php'; ?>
