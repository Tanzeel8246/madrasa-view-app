<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
if (!isset($_SESSION['user_id']) || !app_has_perm('manage_hifz')) { header("Location: dashboard.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->query("UPDATE masjid_nazira_students SET is_deleted = 1, is_synced = 0 WHERE id = $id");
    } else {
        $name = $_POST["name"] ?? "";
        $father_name = $_POST["father_name"] ?? "";
        $phone = $_POST["phone"] ?? "";
        $admission_date = $_POST["admission_date"] ?? "";
        $status = $_POST["status"] ?? "";

        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE masjid_nazira_students SET name=?, father_name=?, phone=?, admission_date=?, status=?, is_synced=0 WHERE id=?");
            $stmt->execute([$name, $father_name, $phone, $admission_date, $status, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO masjid_nazira_students (name, father_name, phone, admission_date, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $father_name, $phone, $admission_date, $status]);
        }
    }
    header("Location: masjid_nazira_students.php");
    exit;
}

$records = $pdo->query("SELECT * FROM masjid_nazira_students WHERE is_deleted = 0 OR is_deleted IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$title = "  ()";
include 'header.php';
?>

<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-success"><i class="fas fa-list me-2"></i>   ()</h3>
        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus me-1"></i>  </button>
    </div>

    <div class="card shadow-sm border-0 neobox h-100">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th></th><th>  </th><th> </th><th> </th><th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($records as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row["name"]); ?></td><td><?php echo htmlspecialchars((string)$row["father_name"]); ?></td><td><?php echo htmlspecialchars((string)$row["phone"]); ?></td><td><?php echo htmlspecialchars((string)$row["admission_date"]); ?></td><td><?php echo htmlspecialchars((string)$row["status"]); ?></td>
                        <td>
                            <form method="POST" class="d-inline" onsubmit="return confirm('       ');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add Masjid Nazira</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

            <div class="mb-3">
                <label></label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>  </label>
                <input type="text" name="father_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label> </label>
                <input type="text" name="phone" class="form-control" required>
            </div>
            <div class="mb-3">
                <label> </label>
                <input type="date" name="admission_date" class="form-control" required>
            </div>
            <div class="mb-3">
                <label></label>
                <input type="text" name="status" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"> </button>
          <button type="submit" class="btn btn-success"> </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
