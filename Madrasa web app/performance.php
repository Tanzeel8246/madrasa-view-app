<?php 
require_once 'db.php';
include 'header.php';
$cur_tenant_id = function_exists('get_current_tenant_id') ? (int)get_current_tenant_id($pdo) : 1;
$tCond = ($cur_tenant_id <= 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $cur_tenant_id";
$rTenantCond = ($cur_tenant_id <= 1) ? "(r.tenant_id = 1 OR r.tenant_id IS NULL OR r.tenant_id = 0)" : "r.tenant_id = $cur_tenant_id";
$roleLower = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!in_array($roleLower, ['admin', 'superadmin', 'super_admin', 'administrator'], true)) {
    http_response_code(403);
    die("<div class='container mt-5 alert alert-danger'>صرف ایڈمن کو اس صفحے تک رسائی کی اجازت ہے۔</div>");
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        marks_obtained DECIMAL(10,2) DEFAULT 0,
        total_marks DECIMAL(10,2) DEFAULT 100,
        tenant_id INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $resCols = $pdo->query("SHOW COLUMNS FROM results LIKE 'tenant_id'")->fetch();
    if (!$resCols) { try { $pdo->exec("ALTER TABLE results ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {} }
} catch (Throwable $eResTbl) {}
?>
<?php
if (isset($_POST['save_result'])) {
    $stmt = $pdo->prepare("INSERT INTO results (student_id, exam_id, marks_obtained, total_marks, tenant_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['std_id'], $_POST['exam_id'], $_POST['obt'], $_POST['total'], $cur_tenant_id]);
    echo "<div class='alert alert-success'>نتیجہ محفوظ ہو گیا!</div>";
}
$students = $pdo->query("SELECT * FROM students WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll();
$exams = $pdo->query("SELECT * FROM exams WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll();
?>
<h2 class="mb-4">تعلیمی کارکردگی (Academic Performance)</h2>
<div class="card p-4 mb-4">
    <form method="POST" class="row g-3">
        <div class="col-md-4">
            <label>طالب علم</label>
            <select name="std_id" class="form-select" required>
                <?php foreach($students as $s) echo "<option value='{$s['id']}'>" . htmlspecialchars($s['name']) . "</option>"; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label>امتحان</label>
            <select name="exam_id" class="form-select" required>
                <?php foreach($exams as $e) echo "<option value='{$e['id']}'>" . htmlspecialchars($e['exam_name']) . "</option>"; ?>
            </select>
        </div>
        <div class="col-md-2"><label>حاصل کردہ نمبر</label><input type="number" name="obt" class="form-control" required></div>
        <div class="col-md-2"><label>کل نمبر</label><input type="number" name="total" class="form-control" required></div>
        <div class="col-12"><button type="submit" name="save_result" class="btn btn-primary">محفوظ کریں</button></div>
    </form>
</div>
<table class="table bg-white">
    <thead class="table-dark"><tr><th>طالب علم</th><th>امتحان</th><th>حاصل کردہ</th><th>کل نمبر</th></tr></thead>
    <tbody>
        <?php
        $sql = "SELECT r.*, s.name, e.exam_name FROM results r 
                JOIN students s ON r.student_id = s.id 
                JOIN exams e ON r.exam_id = e.id WHERE $rTenantCond ORDER BY r.id DESC";
        foreach($pdo->query($sql) as $row) {
            echo "<tr><td>" . htmlspecialchars($row['name']) . "</td><td>" . htmlspecialchars($row['exam_name']) . "</td><td>{$row['marks_obtained']}</td><td>{$row['total_marks']}</td></tr>";
        }
        ?>
    </tbody>
</table>
<?php include 'footer.php'; ?>