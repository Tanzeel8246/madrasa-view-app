<?php 
require_once 'db.php';
include 'header.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("<div class='container mt-5 alert alert-danger'>صرف ایڈمن کو اس صفحے تک رسائی کی اجازت ہے۔</div>");
}
?>
<?php
if (isset($_POST['save_result'])) {
    $stmt = $pdo->prepare("INSERT INTO results (student_id, exam_id, marks_obtained, total_marks) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_POST['std_id'], $_POST['exam_id'], $_POST['obt'], $_POST['total']]);
    echo "<div class='alert alert-success'>نتیجہ محفوظ ہو گیا!</div>";
}
$students = $pdo->query("SELECT * FROM students")->fetchAll();
$exams = $pdo->query("SELECT * FROM exams")->fetchAll();
?>
<h2 class="mb-4">تعلیمی کارکردگی (Academic Performance)</h2>
<div class="card p-4 mb-4">
    <form method="POST" class="row g-3">
        <div class="col-md-4">
            <label>طالب علم</label>
            <select name="std_id" class="form-select" required>
                <?php foreach($students as $s) echo "<option value='{$s['id']}'>{$s['name']}</option>"; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label>امتحان</label>
            <select name="exam_id" class="form-select" required>
                <?php foreach($exams as $e) echo "<option value='{$e['id']}'>{$e['exam_name']}</option>"; ?>
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
                JOIN exams e ON r.exam_id = e.id ORDER BY r.id DESC";
        foreach($pdo->query($sql) as $row) {
            echo "<tr><td>{$row['name']}</td><td>{$row['exam_name']}</td><td>{$row['marks_obtained']}</td><td>{$row['total_marks']}</td></tr>";
        }
        ?>
    </tbody>
</table>
<?php include 'footer.php'; ?>