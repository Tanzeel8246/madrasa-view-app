<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !has_perm('manage_complaints') && !app_has_perm('manage_complaints')) {
    header("Location: dashboard.php");
    exit;
}

// Handle Delete
$cur_tenant_id = (int)get_current_tenant_id();

if (isset($_GET['del_id']) && isset($_GET['type'])) {
    $id = (int)$_GET['del_id'];
    $type = $_GET['type'];
    if ($type == 'admin') {
        $pdo->prepare("DELETE FROM complaints WHERE id = ? AND tenant_id = ?")->execute([$id, $cur_tenant_id]);
    } else {
        $pdo->prepare("DELETE FROM parent_complaints WHERE id = ? AND tenant_id = ?")->execute([$id, $cur_tenant_id]);
    }
    header("Location: manage_complaints.php?msg=deleted");
    exit;
}

// Handle Edit
if (isset($_POST['edit_complaint'])) {
    $id = (int)$_POST['id'];
    $type = $_POST['type'];
    $date = $_POST['date'];
    
    if ($type == 'admin') {
        $cat = $_POST['category'];
        $desc = $_POST['description'];
        $final = "[$cat] $desc";
        $pdo->prepare("UPDATE complaints SET date = ?, complaint = ? WHERE id = ? AND tenant_id = ?")->execute([$date, $final, $id, $cur_tenant_id]);
    } else {
        $cat = $_POST['category'];
        $desc = $_POST['description'];
        $pdo->prepare("UPDATE parent_complaints SET date = ?, category = ?, description = ? WHERE id = ? AND tenant_id = ?")->execute([$date, $cat, $desc, $id, $cur_tenant_id]);
    }
    header("Location: manage_complaints.php?msg=updated");
    exit;
}

// Fetch all with System Type Isolation
$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "s.system_type = 'Masjid'" : "(s.system_type = 'Madrasa' OR s.system_type IS NULL OR s.system_type = '')";

$admin_comp = $pdo->query("SELECT c.id, c.date, c.complaint, s.name as student_name, s.father_name, 'admin' as type FROM complaints c JOIN students s ON c.student_id = s.id WHERE (c.is_deleted = 0 OR c.is_deleted IS NULL) AND $sys_cond AND c.tenant_id = $cur_tenant_id AND s.tenant_id = $cur_tenant_id ORDER BY c.date DESC")->fetchAll(PDO::FETCH_ASSOC);
$parent_comp = $pdo->query("SELECT p.id, p.date, p.category, p.description, s.name as student_name, s.father_name, 'parent' as type FROM parent_complaints p JOIN students s ON p.student_id = s.id WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL) AND $sys_cond AND p.tenant_id = $cur_tenant_id AND s.tenant_id = $cur_tenant_id ORDER BY p.date DESC")->fetchAll(PDO::FETCH_ASSOC);

$all_complaints = [];
foreach ($admin_comp as $c) {
    // Parse category from "[Cat] Desc"
    $cat = "General";
    $desc = $c['complaint'];
    if (preg_match('/^\[(.*?)\]\s*(.*)$/s', $c['complaint'], $matches)) {
        $cat = $matches[1];
        $desc = $matches[2];
    }
    $fullName = $c['student_name'] . (!empty($c['father_name']) ? ' ولد ' . $c['father_name'] : '');
    $all_complaints[] = [
        'id' => $c['id'],
        'date' => $c['date'],
        'student_name' => $fullName,
        'category' => $cat,
        'description' => $desc,
        'type' => 'admin',
        'raw' => $c['complaint']
    ];
}
foreach ($parent_comp as $p) {
    $fullName = $p['student_name'] . (!empty($p['father_name']) ? ' ولد ' . $p['father_name'] : '');
    $all_complaints[] = [
        'id' => $p['id'],
        'date' => $p['date'],
        'student_name' => $fullName,
        'category' => $p['category'],
        'description' => $p['description'],
        'type' => 'parent',
        'raw' => $p['description']
    ];
}

// Sort by date DESC
usort($all_complaints, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$title = "Manage Complaints";
include 'header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i> <?php echo __('شکایات کا ریکارڈ', 'Complaints Record'); ?></h3>
        <a href="add_complaint.php" class="btn btn-danger fw-bold shadow"><i class="fas fa-plus me-1"></i> <?php echo __('نئی شکایت درج کریں', 'Enter New Complaint'); ?></a>
    </div>

    <?php if(isset($_GET['msg'])): ?>
    <div class="alert alert-success">✅ تبدیلی کامیابی سے محفوظ ہو گئی۔</div>
    <?php endif; ?>

    <div class="card shadow-sm neobox border-0">
        <div class="card-body">
            
        <?php
        // Group by Date -> Category
        $grouped = [];
        foreach ($all_complaints as $c) {
            $d = $c['date'];
            $cat = $c['category'];
            if (!isset($grouped[$d])) {
                $grouped[$d] = [];
            }
            if (!isset($grouped[$d][$cat])) {
                $grouped[$d][$cat] = [];
            }
            $grouped[$d][$cat][] = $c;
        }
        
        krsort($grouped); // Sort dates descending
        ?>
        
        <div class="accordion" id="complaintsAccordion">
            <?php $d_idx = 0; foreach ($grouped as $date => $categories): $d_idx++; ?>
            <div class="accordion-item mb-3 border border-danger rounded shadow-sm">
                <h2 class="accordion-header" id="heading<?php echo $d_idx; ?>">
                    <button class="accordion-button <?php echo $d_idx > 1 ? 'collapsed' : ''; ?> bg-danger text-white fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $d_idx; ?>">
                        <i class="fas fa-calendar-day me-2"></i> <?php echo __('تاریخ:', 'Date:'); ?> <?php echo date('d-m-Y', strtotime($date)); ?>
                    </button>
                </h2>
                <div id="collapse<?php echo $d_idx; ?>" class="accordion-collapse collapse <?php echo $d_idx == 1 ? 'show' : ''; ?>" data-bs-parent="#complaintsAccordion">
                    <div class="accordion-body bg-light">
                        
                        <?php foreach ($categories as $cat_name => $comps): ?>
                        <div class="mb-4">
                            <h5 class="fw-bold text-dark border-bottom border-secondary pb-2 mb-3"><i class="fas fa-tag me-2 text-warning"></i> <?php echo htmlspecialchars($cat_name); ?></h5>
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered align-middle text-center bg-white">
                                    <thead class="table-dark">
                                        <tr>
                                            <th><?php echo __('طالب علم', 'Student'); ?></th>
                                            <th><?php echo __('تفصیل', 'Description'); ?></th>
                                            <th><?php echo __('قسم', 'Type'); ?></th>
                                            <th><?php echo __('ایکشن', 'Action'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($comps as $c): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($c['student_name']); ?></td>
                                            <td class="text-start"><?php echo htmlspecialchars($c['description']); ?></td>
                                            <td>
                                                <?php if($c['type']=='parent'): ?>
                                                    <span class="badge bg-info text-dark"><i class="fas fa-user-tie"></i> <?php echo __('والدین', 'Parent'); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><i class="fas fa-user-shield"></i> <?php echo __('ایڈمن', 'Admin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="editComplaint(<?php echo $c['id']; ?>, '<?php echo $c['type']; ?>', '<?php echo $c['date']; ?>', '<?php echo htmlspecialchars(addslashes($c['category'])); ?>', '<?php echo htmlspecialchars(addslashes($c['description'])); ?>')"><i class="fas fa-edit"></i></button>
                                                <a href="?del_id=<?php echo $c['id']; ?>&type=<?php echo $c['type']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('&#1705;&#1740;&#1575; &#1570;&#1662; &#1608;&#1575;&#1602;&#1593;&#1740; &#1581;&#1584;&#1601; &#1705;&#1585;&#1606;&#1575; &#1670;&#1575;&#1729;&#1578;&#1746; &#1729;&#1740;&#1722;&#1567;')"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if(empty($grouped)): ?>
                <div class="alert alert-info text-center">&#1705;&#1608;&#1574;&#1740; &#1588;&#1705;&#1575;&#1740;&#1578; &#1605;&#1608;&#1580;&#1608;&#1583; &#1606;&#1729;&#1740;&#1722;&#1748;</div>
            <?php endif; ?>
        </div>
</div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> شکایت ایڈٹ کریں</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="type" id="edit_type">
                    
                    <div class="mb-3">
                        <label>تاریخ</label>
                        <input type="date" name="date" id="edit_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>کیٹیگری</label>
                        <input type="text" name="category" id="edit_category" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>تفصیل</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                    <button type="submit" name="edit_complaint" class="btn btn-primary">محفوظ کریں</button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#complaintsTable').DataTable({
        "order": [[ 0, "desc" ]],
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/ur.json" }
    });
});

function editComplaint(id, type, date, category, desc) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_type').value = type;
    document.getElementById('edit_date').value = date;
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_description').value = desc;
    var modal = new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
}
</script>

<?php include 'footer.php'; ?>


