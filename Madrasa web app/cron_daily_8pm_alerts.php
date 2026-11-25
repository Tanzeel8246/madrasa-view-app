<?php
/**
 * روزانہ رات 8:00 بجے کا کرون جاب (Daily 8:00 PM Smart Alerts Cron - Multi-Tenant Isolated)
 * 1. حفظ و تعلیم: ہر فعال ادارے کے جس طالب علم نے آج کا سبق، سبقی یا منزل نہیں سنائی، اس کا الرٹ متعلقہ ایڈمن کو بھیجتا ہے۔
 * 2. مالیات: آج کے دن کی کل آمدن و اخراجات کا سمری الرٹ متعلقہ ایڈمن کو بھیجتا ہے۔
 */

define('CLI_MODE', php_sapi_name() === 'cli');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

if (!CLI_MODE) {
    $expectedCronKey = trim(function_exists('get_env_val') ? (string)get_env_val('MADRASA_CRON_SECRET', '') : (string)(getenv('MADRASA_CRON_SECRET') ?: ''));
    if ($expectedCronKey === '') {
        $expectedCronKey = trim(function_exists('get_env_val') ? (string)get_env_val('MADRASA_API_SECRET', '') : (string)(getenv('MADRASA_API_SECRET') ?: ''));
    }
    $providedKey = trim((string)($_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? ''));
    if ($expectedCronKey === '' || $providedKey === '' || !hash_equals($expectedCronKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Valid CRON secret required.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');
$response = [
    'status' => 'success',
    'timestamp' => $now,
    'date' => $today,
    'tenants_processed' => []
];

try {
    $hifz_tbl = 'daily_hifz';
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'daily_hifz'")->fetch()) {
            $hifz_tbl = 'hifz_record';
        }
    } catch (Throwable $eTable) {}

    $tenants = [];
    try {
        $tenants = $pdo->query("SELECT id, name FROM tenants WHERE status IN ('active', 'trial') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eT) {}
    if (empty($tenants)) {
        $tenants = [['id' => 1, 'name' => 'Default Tenant']];
    }

    foreach ($tenants as $tenant) {
        $tId = (int)$tenant['id'];
        $tReport = [
            'tenant_id' => $tId,
            'tenant_name' => $tenant['name'],
            'academic_missed_count' => 0,
            'finance_summary' => [],
            'fcm_dispatched' => []
        ];

        $activeStmt = $pdo->prepare("
            SELECT id, name, class, contact_number, system_type
            FROM students
            WHERE tenant_id = ?
              AND (status = 'Active' OR status IS NULL OR status = '' OR status = 'داخل' OR status = 'فعال')
              AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY class ASC, name ASC
        ");
        $activeStmt->execute([$tId]);
        $activeStudents = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

        $missedStudents = [];
        foreach ($activeStudents as $std) {
            $sid = (int)$std['id'];
            $hStmt = $pdo->prepare("SELECT * FROM $hifz_tbl WHERE student_id = ? AND tenant_id = ? AND date = ? AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
            $hStmt->execute([$sid, $tId, $today]);
            $hRow = $hStmt->fetch(PDO::FETCH_ASSOC);

            if (!$hRow) {
                $missedStudents[] = ['id' => $sid, 'name' => $std['name'], 'class' => $std['class'] ?? '', 'reason' => 'سبق/سبقی کا کوئی اندراج نہیں ہوا'];
            } else {
                $sGrade = trim($hRow['sabaq_status'] ?? $hRow['sabaq_grade'] ?? '');
                $sbGrade = trim($hRow['sabqi_status'] ?? $hRow['sabqi_grade'] ?? '');
                $mGrade = trim($hRow['manzil_status'] ?? $hRow['manzil_grade'] ?? '');

                $isSabaqMissed = ($sGrade === 'نہیں سنایا' || $sGrade === 'ناغہ' || $sGrade === 'غیر حاضر');
                $isSabqiMissed = ($sbGrade === 'نہیں سنائی' || $sbGrade === 'ناغہ' || $sbGrade === 'غیر حاضر');
                $isManzilMissed = ($mGrade === 'نہیں سنائی' || $mGrade === 'ناغہ' || $mGrade === 'غیر حاضر');

                if ($isSabaqMissed || $isSabqiMissed || $isManzilMissed) {
                    $reasons = [];
                    if ($isSabaqMissed) $reasons[] = 'سبق ناغہ';
                    if ($isSabqiMissed) $reasons[] = 'سبقی ناغہ';
                    if ($isManzilMissed) $reasons[] = 'منزل ناغہ';
                    $missedStudents[] = ['id' => $sid, 'name' => $std['name'], 'class' => $std['class'] ?? '', 'reason' => implode('، ', $reasons)];
                }
            }
        }

        $tReport['academic_missed_count'] = count($missedStudents);
        if (!empty($missedStudents)) {
            $count = count($missedStudents);
            $sampleNames = implode('، ', array_column(array_slice($missedStudents, 0, 3), 'name'));
            if ($count > 3) $sampleNames .= ' و دیگر';
            $acadTitle = "حفظ و تعلیم ناغہ الرٹ ($today)";
            $acadBody = "آج کل $count طلبہ نے سبق، سبقی یا منزل نہیں سنائی ($sampleNames)۔ تفصیل کے لیے الرٹس چیک کریں۔";

            sendPushNotificationFCM($pdo, $acadTitle, $acadBody, 'academic_alert', null, 'admin', $tId);
            $tReport['fcm_dispatched'][] = 'academic_alert';
        }

        $finStmt = $pdo->prepare("
            SELECT type, SUM(amount) as total
            FROM finance
            WHERE tenant_id = ? AND date = ? AND (is_deleted = 0 OR is_deleted IS NULL)
            GROUP BY type
        ");
        $finStmt->execute([$tId, $today]);
        $finRows = $finStmt->fetchAll(PDO::FETCH_ASSOC);

        $totInc = 0; $totExp = 0;
        foreach ($finRows as $fr) {
            if (strtolower((string)$fr['type']) === 'income') $totInc += (float)$fr['total'];
            if (strtolower((string)$fr['type']) === 'expense') $totExp += (float)$fr['total'];
        }
        $netBalance = $totInc - $totExp;
        $balText = ($netBalance >= 0) ? ("بچت: Rs. " . number_format($netBalance)) : ("خسارہ: Rs. " . number_format(abs($netBalance)));

        $finTitle = "یومیہ مالیاتی سمری الرٹ ($today)";
        $finBody = "آج کی کل آمدن: Rs. " . number_format($totInc) . " | کل اخراجات: Rs. " . number_format($totExp) . " | $balText";

        $tReport['finance_summary'] = ['income' => $totInc, 'expense' => $totExp, 'net' => $netBalance, 'text' => $finBody];
        sendPushNotificationFCM($pdo, $finTitle, $finBody, 'finance_summary', null, 'admin', $tId);
        $tReport['fcm_dispatched'][] = 'finance_summary';

        $response['tenants_processed'][] = $tReport;
    }
} catch (Throwable $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
