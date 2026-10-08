<?php include 'header.php'; ?>
<?php
if (isset($_POST['add_exam'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO exams (exam_name, exam_date, total_marks, passing_marks) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['date'], $_POST['total_marks'], $_POST['passing_marks']]);
        echo "<div class='alert alert-info shadow-sm fw-bold'>✅ نیا امتحان شامل کر دیا گیا!</div>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger shadow-sm fw-bold'>❌ غلطی: ڈیٹا بیس اپڈیٹ نہیں ہو سکا۔ برائے مہربانی <a href='fix_schema.php' class='alert-link'>یہاں کلک کر کے</a> ڈیٹا بیس فکس کریں۔<br><small>Error: {$e->getMessage()}</small></div>";
    }
}
?>
<h2 class="mb-4">امتحانات کی فہرست (Exams List)</h2>
<div class="card p-4 mb-4">
    <form method="POST" class="row g-3">
        <div class="col-md-4">
            <label class="form-label fw-bold">امتحان کا نام</label>
            <input type="text" name="name" class="form-control" placeholder="مثلاً: سالانہ امتحان 2024" required>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">تاریخ</label>
            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">کل نمبر</label>
            <input type="number" name="total_marks" class="form-control" value="100" required>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">پاسنگ مارکس</label>
            <input type="number" name="passing_marks" class="form-control" value="33" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" name="add_exam" class="btn btn-primary w-100 fw-bold">شامل کریں</button>
        </div>
    </form>
</div>
<div class="neobox p-0 overflow-hidden shadow-sm">
    <table class="table table-hover mb-0 align-middle">
        <thead class="table-dark">
            <tr>
                <th class="px-4">ID</th>
                <th>امتحان کا نام</th>
                <th>تاریخ</th>
                <th class="text-center">ایکشن</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sql = "SELECT e.*, 
                    (SELECT COUNT(*) FROM exam_results WHERE exam_id = e.id) as result_count,
                    (SELECT COUNT(*) FROM students WHERE status='Active' AND is_deleted=0) as total_students
                    FROM exams e WHERE is_deleted = 0 ORDER BY id DESC";
            foreach($pdo->query($sql) as $row) {
                $perc = $row['total_students'] > 0 ? round(($row['result_count'] / $row['total_students']) * 100) : 0;
                $status_color = ($perc == 100) ? 'success' : 'warning';
                $status_text = ($perc == 100) ? 'مکمل' : 'جاری';
                
                echo "<tr>
                        <td class='px-4'>{$row['id']}</td>
                        <td>
                            <div class='fw-bold text-dark'>{$row['exam_name']}</div>
                            <div class='progress mt-1' style='height: 5px; width: 100px;'>
                                <div class='progress-bar bg-$status_color' style='width: $perc%'></div>
                            </div>
                            <small class='text-muted' style='font-size: 10px;'>رزلٹ: $perc% ($row[result_count]/$row[total_students])</small>
                        </td>
                        <td>
                            <div class='fw-bold'>{$row['exam_date']}</div>
                            <small class='text-muted' style='font-size: 10px;'>کل نمبر: {$row['total_marks']} | پاس: {$row['passing_marks']}</small>
                        </td>
                        <td class='text-center'>
                            <div class='btn-group shadow-sm'>
                                <a href='exam_results_entry.php?exam_id={$row['id']}' class='btn btn-sm btn-primary' title='رزلٹ درج کریں'><i class='fas fa-edit'></i></a>
                                <a href='result_register.php?exam_id={$row['id']}' class='btn btn-sm btn-success' title='رزلٹ رجسٹر'><i class='fas fa-list-alt'></i></a>
                            </div>
                        </td>
                      </tr>";
            }
            ?>
        </tbody>
    </table>
</div>
<?php include 'footer.php'; ?>