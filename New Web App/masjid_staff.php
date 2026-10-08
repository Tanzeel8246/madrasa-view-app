<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
if (!isset($_SESSION['user_id']) || !app_has_perm('manage_teachers')) { header("Location: dashboard.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->query("UPDATE masjid_staff SET is_deleted = 1, is_synced = 0 WHERE id = $id");
    } else {
        $name = $_POST["name"] ?? "";
        $role = $_POST["role"] ?? "";
        $phone = $_POST["phone"] ?? "";
        $salary = $_POST["salary"] ?? "";

        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE masjid_staff SET name=?, role=?, phone=?, salary=?, is_synced=0 WHERE id=?");
            $stmt->execute([$name, $role, $phone, $salary, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO masjid_staff (name, role, phone, salary) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $role, $phone, $salary]);
        }
    }
    header("Location: masjid_staff.php");
    exit;
}

$records = $pdo->query("SELECT * FROM masjid_staff WHERE is_deleted = 0 OR is_deleted IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$title = "مسجد کا عملہ";
include 'header.php';
?>

<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-success"><i class="fas fa-list me-2"></i> مسجد کا عملہ</h3>
        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus me-1"></i> نیا ریکارڈ</button>
    </div>

    <div class="card shadow-sm border-0 neobox h-100">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>نام</th><th>عہدہ</th><th>فون نمبر</th><th>تنخواہ</th>
                        <th>ایکشن</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($records as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row["name"]); ?></td><td><?php echo htmlspecialchars((string)$row["role"]); ?></td><td><?php echo htmlspecialchars((string)$row["phone"]); ?></td><td><?php echo htmlspecialchars((string)$row["salary"]); ?></td>
                        <td>
                            <form method="POST" class="d-inline" onsubmit="return confirm('کیا آپ یہ ریکارڈ حذف کرنا چاہتے ہیں؟');">
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
          <h5 class="modal-title">Add Masjid Staff</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

            <div class="mb-3">
                <label>نام</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>عہدہ</label>
                <input type="text" name="role" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>فون نمبر</label>
                <input type="text" name="phone" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>تنخواہ</label>
                <input type="number" name="salary" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بند کریں</button>
          <button type="submit" class="btn btn-success">محفوظ کریں</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
