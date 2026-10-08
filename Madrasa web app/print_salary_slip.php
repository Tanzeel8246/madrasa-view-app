<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    die("Invalid request");
}

$id = (int)$_GET['id'];

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond_p = ($sys === 'Masjid') 
    ? "p.system_type = 'Masjid'" 
    : "(p.system_type = 'Madrasa' OR p.system_type IS NULL OR p.system_type = '')";

$cur_tenant_id = (int)get_current_tenant_id();
$query = $pdo->prepare("
    SELECT p.*, t.name as teacher_name, t.phone 
    FROM acc_payroll p 
    JOIN teachers t ON p.teacher_id = t.id 
    WHERE p.id = ? AND p.tenant_id = ? AND t.tenant_id = ? AND $sys_cond_p
");
$query->execute([$id, $cur_tenant_id, $cur_tenant_id]);
$slip = $query->fetch(PDO::FETCH_ASSOC);

if (!$slip) {
    die("Slip not found");
}

$settings = get_tenant_settings($pdo, $cur_tenant_id);
$app_name = !empty($settings['madrasa_name']) ? $settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : 'Madrasa Management System');

function numberToUrdu($num) {
    return number_format($num) . " روپے صرف";
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Salary Slip - <?php echo htmlspecialchars($slip['teacher_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: url('fonts/JameelNooriNastaleeq.ttf') format('truetype');
        }
        body {
            font-family: 'Jameel Noori Nastaleeq', Arial, sans-serif;
            background: #f4f6f9;
        }
        .slip-container {
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 2px solid #284D3D;
        }
        .header {
            text-align: center;
            border-bottom: 3px double #284D3D;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #284D3D;
            font-weight: bold;
            font-size: 36px;
        }
        .info-table, .salary-table {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-table td {
            padding: 8px;
            font-size: 18px;
        }
        .salary-table th, .salary-table td {
            padding: 12px;
            border: 1px solid #ddd;
            font-size: 18px;
        }
        .salary-table th {
            background-color: #f8f9fa;
            color: #284D3D;
        }
        .net-salary {
            font-size: 24px;
            font-weight: bold;
            color: #d9534f;
            text-align: left;
        }
        .signature-section {
            margin-top: 60px;
            display: flex;
            justify-content: space-between;
        }
        .sig-line {
            border-top: 1px solid #000;
            width: 200px;
            text-align: center;
            padding-top: 5px;
            font-weight: bold;
        }
        @media print {
            body { background: #fff; }
            .slip-container { margin: 0; box-shadow: none; border: none; }
            .d-print-none { display: none; }
        }
    </style>
</head>
<body>

<div class="text-center mt-3 d-print-none">
    <button onclick="window.print()" class="btn btn-primary fw-bold"><i class="fas fa-print"></i> پرنٹ کریں</button>
    <button onclick="window.close()" class="btn btn-secondary fw-bold">بند کریں</button>
</div>

<div class="slip-container">
    <div class="header">
        <h1><?php echo htmlspecialchars($app_name); ?></h1>
        <h4 class="text-muted mt-2">پے رول / سیلری سلپ (Salary Slip)</h4>
        <h5>مہینہ: <span><?php echo function_exists('format_urdu_month_year') ? format_urdu_month_year($slip['month_year']) : date('F Y', strtotime($slip['month_year'] . '-01')); ?></span></h5>
    </div>

    <table class="info-table">
        <tr>
            <td width="20%"><b>نام استاذ:</b></td>
            <td width="30%"><b><?php echo htmlspecialchars($slip['teacher_name']); ?></b></td>
            <td width="20%"><b>سٹیٹس:</b></td>
            <td width="30%">
                <?php if($slip['status'] == 'Paid'): ?>
                    <span style="color: green; font-weight: bold;">ادا شدہ (Paid)</span>
                <?php else: ?>
                    <span style="color: red; font-weight: bold;">غیر ادا شدہ (Unpaid)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><b>فون نمبر:</b></td>
            <td dir="ltr" class="text-end"><?php echo htmlspecialchars($slip['phone']); ?></td>
            <td><b>تاریخ ادائیگی:</b></td>
            <td><?php echo $slip['payment_date'] ? date('d-M-Y', strtotime($slip['payment_date'])) : '-'; ?></td>
        </tr>
    </table>

    <table class="salary-table mt-4">
        <thead>
            <tr>
                <th width="70%">تفصیل (Description)</th>
                <th width="30%" class="text-center">رقم (Amount)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>بنیادی تنخواہ (Basic Salary)</td>
                <td class="text-center">Rs. <?php echo number_format($slip['basic_salary'], 2); ?></td>
            </tr>
            <?php if($slip['allowances'] > 0): ?>
            <tr>
                <td>دیگر الاؤنسز (Allowances)</td>
                <td class="text-center text-success">+ Rs. <?php echo number_format($slip['allowances'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if($slip['deductions'] > 0): ?>
            <tr>
                <td>کٹوتی / غیر حاضری (Deductions)</td>
                <td class="text-center text-danger">- Rs. <?php echo number_format($slip['deductions'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr style="background-color: #f8f9fa;">
                <td class="text-end fw-bold" style="font-size: 22px;">خالص تنخواہ (Net Payable):</td>
                <td class="text-center net-salary">Rs. <?php echo number_format($slip['net_salary'], 2); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="mt-2 text-center" style="font-size: 18px;">
        مبلغ <b><?php echo numberToUrdu($slip['net_salary']); ?></b> کی ادائیگی کی گئی۔
    </div>

    <div class="signature-section">
        <div class="sig-line">دستخط وصول کنندہ</div>
        <div class="sig-line">دستخط مہتمم / ناظم</div>
    </div>
</div>

</body>
</html>
