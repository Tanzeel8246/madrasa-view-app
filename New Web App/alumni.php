<?php
// alumni.php - رجسٹر فارغ التحصیل طلباء
require_once 'db.php';
include 'header.php';

// ڈیٹا لائیں
$alumni = $pdo->query("SELECT * FROM students WHERE status = 'Alumni' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY graduation_date DESC")->fetchAll();
?>

<div class="container-fluid py-4 no-print">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-user-graduate me-2 text-primary"></i> رجسٹر فارغ التحصیل طلباء (Alumni Register)</h2>
        <div class="d-flex gap-2 align-items-center">
            <button onclick="window.print()" class="btn btn-dark shadow-sm">
                <i class="fas fa-print me-1"></i> فہرست پرنٹ کریں
            </button>
            <div class="text-muted ms-3">کل تعداد: <span class="badge bg-primary fs-6"><?php echo count($alumni); ?></span></div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">نمبر شمار</th>
                            <th>داخلہ نمبر</th>
                            <th>تصویر</th>
                            <th>نام طالب علم</th>
                            <th>ولدیت</th>
                            <th>داخلہ / فراغت</th>
                            <th>تعلیمی دورانیہ</th>
                            <th>فراغت کی وجہ</th>
                            <th class="text-center no-print">ایکشن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($alumni) > 0): ?>
                            <?php $serialCount = 1; foreach ($alumni as $s): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?php echo $serialCount++; ?></td>
                                    <td class="text-primary fw-bold"><?php echo $s['admission_number']; ?></td>
                                    <td>
                                        <?php if (!empty($s['photo'])): ?>
                                            <img src="<?php echo $s['photo']; ?>" class="rounded shadow-sm" width="40" height="40" style="object-fit: cover;">
                                        <?php else: ?>
                                            <div class="bg-secondary text-white rounded d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="fw-bold fs-6"><?php echo $s['name']; ?></div></td>
                                    <td><?php echo $s['father_name']; ?></td>
                                    <td>
                                        <div class="small">داخلہ: <span class="text-muted"><?php echo $s['admission_date']; ?></span></div>
                                        <div class="small">فراغت: <span class="text-success fw-bold"><?php echo $s['graduation_date']; ?></span></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo $s['duration'] ?: '---'; ?></span></td>
                                    <td>
                                        <div class="small text-muted" style="max-width: 200px; word-wrap: break-word;">
                                            <?php echo $s['leaving_reason'] ?: 'درج نہیں'; ?>
                                        </div>
                                    </td>
                                    <td class="text-center no-print">
                                        <div class="btn-group">
                                            <a href="print_form.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-info" title="فارم دیکھیں" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="students.php?restore=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-success" title="بحال کریں" onclick="return confirm('کیا اسے دوبارہ فعال طلباء میں شامل کرنا ہے؟')">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-folder-open fa-3x mb-3"></i>
                                        <p class="fs-5">کوئی فارغ التحصیل ریکارڈ موجود نہیں ہے۔</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style>
    .print-only { display: none; }
    @media print {
        .no-print { display: none !important; }
        .print-only { display: block !important; }
        body { background: white !important; font-family: 'Noto Nastaliq Urdu', serif !important; }
        .table { width: 100% !important; border: 1px solid #ccc !important; }
        .table th, .table td { border: 1px solid #eee !important; padding: 10px !important; font-size: 14px !important; }
        .printable-register { padding: 20px; }
        .badge { border: 1px solid #ccc; color: black !important; background: transparent !important; }
    }
</style>

<!-- Hidden Header for Printing -->
<div class="print-only printable-register" dir="rtl">
    <div class="text-center mb-4">
        <h1 style="font-size: 30px; font-weight: bold;">مدرسہ اشرف المدارس فروکہ</h1>
        <h3 style="border-bottom: 2px solid #000; display: inline-block; padding-bottom: 5px;">رجسٹر فارغ التحصیل طلباء (الایلومنائی)</h3>
        <p class="mt-2">تاریخ: <?php echo date('d-m-Y'); ?></p>
    </div>
    <table class="table align-middle">
        <thead>
            <tr>
                <th>نمبر شمار</th>
                <th>داخلہ نمبر</th>
                <th>نام طالب علم</th>
                <th>ولدیت</th>
                <th>تاریخ داخلہ</th>
                <th>تاریخ فراغت</th>
                <th>تعلیمی دورانیہ</th>
                <th>فراغت کی وجہ</th>
            </tr>
        </thead>
        <tbody>
            <?php $count = 1; foreach ($alumni as $s): ?>
                <tr>
                    <td><?php echo $count++; ?></td>
                    <td><?php echo $s['admission_number']; ?></td>
                    <td class="fw-bold"><?php echo $s['name']; ?></td>
                    <td><?php echo $s['father_name']; ?></td>
                    <td><?php echo $s['admission_date']; ?></td>
                    <td><?php echo $s['graduation_date']; ?></td>
                    <td><?php echo $s['duration']; ?></td>
                    <td><?php echo $s['leaving_reason']; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'footer.php'; ?>
