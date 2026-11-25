<?php 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($u_role, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
if (!$is_admin && !app_has_perm('manage_exams') && !app_has_perm('manage_masjid_exams')) {
    $sys = $_SESSION['system_type'] ?? 'Madrasa';
    header("Location: " . ($sys === 'Masjid' ? "masjid_dashboard.php" : "dashboard.php"));
    exit;
}
include 'header.php'; 

$cur_tenant_id = (int)get_current_tenant_id();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS maktab_exams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_name VARCHAR(255) NOT NULL,
        exam_date DATE NULL,
        total_marks INT DEFAULT 100,
        passing_marks INT DEFAULT 33,
        status VARCHAR(50) DEFAULT 'Active',
        tenant_id INT NOT NULL DEFAULT 1,
        is_deleted TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS maktab_exam_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        student_id INT NOT NULL,
        class_name VARCHAR(100) NULL,
        teacher_id INT NULL,
        subject VARCHAR(100) NULL,
        total_marks INT DEFAULT 100,
        obtained_marks INT DEFAULT 0,
        remarks TEXT NULL,
        tenant_id INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $exCols = $pdo->query("SHOW COLUMNS FROM maktab_exams")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tenant_id', $exCols)) { try { $pdo->exec("ALTER TABLE maktab_exams ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {} }
    if (!in_array('is_deleted', $exCols)) { try { $pdo->exec("ALTER TABLE maktab_exams ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {} }
    if (!in_array('total_marks', $exCols)) { try { $pdo->exec("ALTER TABLE maktab_exams ADD COLUMN total_marks INT DEFAULT 100"); } catch (Throwable $e) {} }
    if (!in_array('passing_marks', $exCols)) { try { $pdo->exec("ALTER TABLE maktab_exams ADD COLUMN passing_marks INT DEFAULT 33"); } catch (Throwable $e) {} }
    $erCols = $pdo->query("SHOW COLUMNS FROM maktab_exam_results")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tenant_id', $erCols)) { try { $pdo->exec("ALTER TABLE maktab_exam_results ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {} }
} catch (Throwable $eTbl) {}

if (isset($_POST['add_exam'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO maktab_exams (exam_name, exam_date, total_marks, passing_marks, tenant_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['date'], $_POST['total_marks'], $_POST['passing_marks'], $cur_tenant_id]);
        echo "<div class='alert alert-info shadow-sm fw-bold'>امتحان شامل کر دیا گیا!</div>";
    } catch (Exception $e) {
        echo "<div class='alert alert-danger shadow-sm fw-bold'>غلطی: <br><small>Error: {$e->getMessage()}</small></div>";
    }
}
?>
<h2 class="mb-4">امتحانات کی فہرست (Exams List)</h2>
<div class="card p-4 mb-4">
    <form method="POST" class="row g-3">
        <div class="col-md-4">
            <label class="form-label fw-bold">امتحان کا نام</label>
            <input type="text" name="name" class="form-control" placeholder="مثال: ششماہی امتحان 2024" required>
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
            <button type="submit" name="add_exam" class="btn btn-primary w-100 fw-bold">محفوظ کریں</button>
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
                    (SELECT COUNT(*) FROM maktab_exam_results WHERE exam_id = e.id AND tenant_id = $cur_tenant_id) as result_count,
                    (SELECT COUNT(*) FROM maktab_students WHERE status='Active' AND is_deleted=0 AND tenant_id = $cur_tenant_id) as total_maktab_students
                    FROM maktab_exams e WHERE is_deleted = 0 AND e.tenant_id = $cur_tenant_id ORDER BY id DESC";
            foreach($pdo->query($sql) as $row) {
                $perc = $row['total_maktab_students'] > 0 ? round(($row['result_count'] / $row['total_maktab_students']) * 100) : 0;
                $status_color = ($perc == 100) ? 'success' : 'warning';
                
                echo "<tr>
                        <td class='px-4'>{$row['id']}</td>
                        <td>
                            <div class='fw-bold text-dark'>" . htmlspecialchars($row['exam_name']) . "</div>
                            <div class='progress mt-1' style='height: 5px; width: 100px;'>
                                <div class='progress-bar bg-$status_color' style='width: $perc%'></div>
                            </div>
                            <small class='text-muted' style='font-size: 10px;'>پیشرفت: $perc% ($row[result_count]/$row[total_maktab_students])</small>
                        </td>
                        <td>
                            <div class='fw-bold'>{$row['exam_date']}</div>
                            <small class='text-muted' style='font-size: 10px;'>کل نمبر: {$row['total_marks']} | پاسنگ مارکس: {$row['passing_marks']}</small>
                        </td>
                        <td class='text-center'>
                            <div class='btn-group shadow-sm'>
                                <a href='maktab_exam_results_entry.php?exam_id={$row['id']}' class='btn btn-sm btn-primary' title='رزلٹ انٹری'><i class='fas fa-edit'></i></a>
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