<?php
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "=== 1. CHART OF ACCOUNTS (ADVANCE ACCOUNTS) ===\n";
$stmt = $pdo->query("SELECT id, code, name, type, is_active, is_deleted FROM acc_chart_of_accounts WHERE name LIKE '%ایڈوانس%' OR name LIKE '%Advance%' OR code LIKE '15%'");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($accounts);

echo "\n=== 2. VOUCHERS MATCHING THE SCREENSHOT ===\n";
$vouchers = $pdo->query("SELECT id, voucher_no, date, amount, type, reference, narration FROM acc_vouchers WHERE voucher_no IN ('CV-00005', 'CV-260916-2300', 'CV-260913-3585', 'CV-260909-4147', 'CV-00001') OR narration LIKE '%saleem%' OR narration LIKE '%سلیم%' ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
print_r($vouchers);

echo "\n=== 3. LEDGER ENTRIES FOR THESE VOUCHERS ===\n";
if (!empty($vouchers)) {
    $vids = implode(',', array_column($vouchers, 'id'));
    $entries = $pdo->query("SELECT e.id, e.voucher_id, v.voucher_no, e.account_id, a.name as account_name, e.debit, e.credit, e.description 
                            FROM acc_ledger_entries e 
                            LEFT JOIN acc_chart_of_accounts a ON e.account_id = a.id 
                            LEFT JOIN acc_vouchers v ON e.voucher_id = v.id 
                            WHERE e.voucher_id IN ($vids)")->fetchAll(PDO::FETCH_ASSOC);
    print_r($entries);
}

echo "\n=== 4. FINANCE ENTRIES FOR SALEEM (USER ID 3) ===\n";
$finSaleem = $pdo->query("SELECT account_id, COUNT(*) as cnt, SUM(amount) as total_amt FROM finance WHERE user_id = 3 AND (is_deleted = 0 OR is_deleted IS NULL) GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC);
print_r($finSaleem);

echo "\n=== 5. FINANCE ENTRIES FOR TANZEEL (USER ID 1) ===\n";
$finTanzeel = $pdo->query("SELECT account_id, COUNT(*) as cnt, SUM(amount) as total_amt FROM finance WHERE user_id = 1 AND (is_deleted = 0 OR is_deleted IS NULL) GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC);
print_r($finTanzeel);
