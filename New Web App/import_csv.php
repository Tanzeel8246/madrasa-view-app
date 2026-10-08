<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'db.php';
require_once 'finance_accounting_bridge.php';

// Ensure only admins can access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nazim'])) {
    die("Access Denied. Please login as admin first.");
}

$message = '';

if (isset($_POST['import']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $type = $_POST['entry_type']; // 'Revenue' or 'Expense'
    $financeType = ($type === 'Revenue') ? 'Income' : 'Expense';

    if (empty($file)) {
        $message = "<div class='alert alert-danger'>براہ کرم فائل منتخب کریں!</div>";
    } else {
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            $header = fgetcsv($handle, 1000, ","); // skip header
            $imported = 0;
            $newHeads = 0;

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 6) continue; // ensure valid row

                $date_raw = trim($data[1]);
                // Convert DD/MM/YYYY to YYYY-MM-DD
                $date_parts = explode('/', $date_raw);
                if (count($date_parts) == 3) {
                    $date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
                } else {
                    $date = date('Y-m-d'); // fallback
                }

                $category = trim($data[2]);
                $description = trim($data[3]);
                $amount_raw = trim($data[4]);
                $amount = floatval(str_replace([' ', ','], ['', ''], $amount_raw));
                $recipient = trim($data[5]);

                if (empty($category) || $amount <= 0) continue;

                // Check if head exists
                $headStmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE name = ?");
                $headStmt->execute([$category]);
                $head = $headStmt->fetch(PDO::FETCH_ASSOC);

                if ($head) {
                    $head_id = $head['id'];
                } else {
                    // Create new head
                    $code = rand(10000, 99999);
                    $insHead = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted) VALUES (?, ?, ?, 0)");
                    $insHead->execute([(string)$code, $category, $type]);
                    $head_id = $pdo->lastInsertId();
                    $newHeads++;
                }

                $selUser = $_POST['selected_user'] ?? '';
                $selParts = explode('|', $selUser);
                $importUserId = $_SESSION['user_id'];
                $importUserName = $_SESSION['username'] ?? 'Admin';
                if (count($selParts) == 2) {
                    $importUserId = $selParts[0];
                    $importUserName = $selParts[1];
                }

                // Insert into finance
                $insFin = $pdo->prepare("INSERT INTO finance (type, amount, description, date, head_id, donor_name, user_id, user_name, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $insFin->execute([
                    $financeType,
                    $amount,
                    $description,
                    $date,
                    $head_id,
                    $recipient,
                    $importUserId,
                    $importUserName
                ]);
                
                $finance_id = $pdo->lastInsertId();
                if (function_exists('finance_accounting_post')) {
                    finance_accounting_post($pdo, $finance_id);
                }

                $imported++;
            }
            fclose($handle);
            $message = "<div class='alert alert-success'>$imported ریکارڈز کامیابی سے امپورٹ ہو گئے۔ $newHeads نئی مدات شامل کی گئیں!</div>";
        } else {
            $message = "<div class='alert alert-danger'>فائل پڑھنے میں مسئلہ!</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>CSV Import</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', Arial, sans-serif; background-color: #f8f9fa; }
        .container { max-width: 600px; margin-top: 50px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="container text-center">
        <h2 class="mb-4 text-success">ایکسل / CSV سے ڈیٹا امپورٹ کریں</h2>
        <?= $message ?>
        <form method="post" enctype="multipart/form-data">
<?php
try {
    $usersStmt = $pdo->query("SELECT id, username FROM admins");
    $allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allUsers = [['id' => $_SESSION['user_id'], 'username' => $_SESSION['username'] ?? 'Admin']];
}
?>
            <div class="mb-3 text-start">
                <label class="form-label fw-bold">یہ ڈیٹا کس اکاؤنٹ کے تحت محفوظ کرنا ہے؟</label>
                <select name="selected_user" class="form-select" required>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>|<?= htmlspecialchars($u['username']) ?>" <?= ($u['id'] == $_SESSION['user_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3 text-start">
                <label class="form-label fw-bold">یہ ڈیٹا آمدنی کا ہے یا خرچ کا؟</label>
                <select name="entry_type" class="form-select" required>
                    <option value="Expense">خرچ (Expense)</option>
                    <option value="Revenue">آمدنی (Income)</option>
                </select>
            </div>
            <div class="mb-4 text-start">
                <label class="form-label fw-bold">CSV فائل منتخب کریں</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
            </div>
            <button type="submit" name="import" class="btn btn-success w-100 fs-5">ڈیٹا اپلوڈ کریں</button>
            <a href="finance.php" class="btn btn-secondary w-100 mt-2 fs-5">واپس جائیں</a>
        </form>
    </div>
</body>
</html>
