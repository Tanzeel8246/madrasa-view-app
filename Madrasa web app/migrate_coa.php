<?php
require_once 'db.php';

echo "<h1>Starting Migration...</h1>";

try {
    // 1. Fetch all existing heads
    $stmt = $pdo->query("SELECT id, name, type FROM heads");
    $heads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $head_to_coa_map = [];

    foreach ($heads as $head) {
        $head_id = $head['id'];
        $name = trim($head['name']);
        if (empty($name)) continue;

        $type_str = strtolower(trim($head['type']));
        $coa_type = ($type_str === 'income') ? 'Revenue' : 'Expense';

        // Check if COA exists
        $check = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE name = ? AND type = ? LIMIT 1");
        $check->execute([$name, $coa_type]);
        $coa_id = $check->fetchColumn();

        if (!$coa_id) {
            // Generate a random code or sequential code
            $code = rand(10000, 99999); 
            $insert = $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted) VALUES (?, ?, ?, 0)");
            $insert->execute([(string)$code, $name, $coa_type]);
            $coa_id = $pdo->lastInsertId();
        }

        $head_to_coa_map[$head_id] = $coa_id;
    }

    echo "<p>Mapped " . count($head_to_coa_map) . " heads to COA.</p>";

    // 2. Update finance table
    $updated_count = 0;
    foreach ($head_to_coa_map as $old_head_id => $new_coa_id) {
        if ($old_head_id != $new_coa_id) {
            $update = $pdo->prepare("UPDATE finance SET head_id = ? WHERE head_id = ?");
            $update->execute([$new_coa_id, $old_head_id]);
            $updated_count += $update->rowCount();
        }
    }

    echo "<p>Updated $updated_count finance records.</p>";
    echo "<h2>Migration Complete!</h2>";
    echo "<p>You can now delete this file.</p>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
