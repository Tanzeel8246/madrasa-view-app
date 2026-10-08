<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Only admins can run this migration.");
}

echo "<h2>Finance Heads Migration to Chart of Accounts</h2>";

try {
    // 1. Check if finance_heads exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'finance_heads'");
    if ($stmt->rowCount() == 0) {
        echo "<p>Table 'finance_heads' does not exist. Adding default heads...</p>";
        $heads = []; // Defaults will be added later
    } else {
        $heads = $pdo->query("SELECT * FROM finance_heads")->fetchAll(PDO::FETCH_ASSOC);
    }

    $inserted = 0;
    
    // Add default heads if empty
    if (empty($heads)) {
        $default_heads = [
            ['name' => ' / Donations', 'type' => 'Income'],
            ['name' => ' / Fees', 'type' => 'Income'],
            ['name' => ' / Salary', 'type' => 'Expense'],
            ['name' => '  / Electricity', 'type' => 'Expense'],
            ['name' => ' / Misc', 'type' => 'Expense']
        ];
        
        $system_types = ['Madrasa', 'Masjid'];
        foreach ($system_types as $st) {
            foreach ($default_heads as $head) {
                // Check if exists
                $chk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE name = ? AND system_type = ?");
                $chk->execute([$head['name'], $st]);
                if ($chk->rowCount() == 0) {
                    $code = strtoupper(substr($head['type'], 0, 3)) . '-' . rand(1000, 9999);
                    $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, system_type) VALUES (?, ?, ?, ?)");
                    $ins->execute([$code, $head['name'], $head['type'], $st]);
                    $inserted++;
                }
            }
        }
    } else {
        foreach ($heads as $head) {
            $name = $head['head_name'] ?? $head['name'];
            $type = $head['head_type'] ?? $head['type'];
            
            if (empty($name) || empty($type)) continue;

            $system_types = ['Madrasa', 'Masjid'];
            foreach ($system_types as $st) {
                // Check if exists
                $chk = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE name = ? AND system_type = ?");
                $chk->execute([$name, $st]);
                if ($chk->rowCount() == 0) {
                    $code = strtoupper(substr($type, 0, 3)) . '-' . rand(1000, 9999);
                    $ins = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, system_type) VALUES (?, ?, ?, ?)");
                    $ins->execute([$code, $name, $type, $st]);
                    $inserted++;
                }
            }
        }
    }

    echo "<p>Migration complete. Inserted $inserted heads into acc_chart_of_accounts.</p>";
    echo "<a href='simple_finance.php'>Go to Finance</a>";

} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
