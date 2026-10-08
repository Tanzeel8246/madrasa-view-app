<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

if (!isset($_SESSION['user_id']) || (!app_has_perm('manage_classes') && !app_has_perm('manage_students') && !app_has_perm('manage_teachers') && !app_has_perm('manage_attendance') && !app_has_perm('manage_teacher_attendance') && !app_has_perm('manage_hifz') && !app_has_perm('manage_academic_dashboard'))) {
    header("Location: dashboard.php");
    exit();
}

$page_title = ($lang == 'ur') ? 'مکتب (نظامِ تعلیم)' : 'Maktab Management';
include 'header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-success"><i class="fas fa-book-quran me-2"></i> مکتب (نظامِ تعلیم) </h2>
        <a href="masjid_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold">
            <i class="fas fa-arrow-right me-1"></i> واپس مسجد ڈیش بورڈ
        </a>
    </div>

    <div class="row g-4">
        <?php if(has_perm('manage_classes')): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="manage_classes.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-chalkboard fa-3x text-success"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'کلاسز' : 'Classes'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'تفصیلات دیکھیں' : 'View Details'; ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <?php if(has_perm('manage_students')): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="students.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e3f2fd; border: 1px solid #bbdefb; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-user-graduate fa-3x text-primary"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'طلبہ ریکارڈ' : 'Students'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'تفصیلات دیکھیں' : 'View Details'; ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <?php if(has_perm('manage_teachers')): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="teachers.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #fff8e1; border: 1px solid #ffecb3; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-chalkboard-teacher fa-3x text-warning"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'اساتذہ ریکارڈ' : 'Teachers'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'تفصیلات دیکھیں' : 'View Details'; ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <?php if(has_perm('manage_hifz') || has_perm('manage_academics')): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="academic_entry_dashboard.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e0f2f1; border: 1px solid #b2dfdb; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-edit fa-3x text-teal" style="color: #009688;"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'تعلیمی رپورٹ (اندراج)' : 'Academic Entry'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'کلاس وار اندراج' : 'Enter Report'; ?></div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <a href="academic_view_dashboard.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #ede7f6; border: 1px solid #d1c4e9; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-chart-line fa-3x text-purple" style="color: #673ab7;"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'تعلیمی رپورٹ (دیکھیں)' : 'Academic View'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'کارکردگی جائزہ' : 'View Reports'; ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <?php if(has_perm('manage_attendance')): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="attendance.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-calendar-check fa-3x text-success"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'حاضری رجسٹر' : 'Attendance'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'روزانہ حاضری' : 'Daily Attendance'; ?></div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <a href="attendance_report.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #fce4ec; border: 1px solid #f8bbd0; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-file-signature fa-3x text-danger"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'حاضری رپورٹ' : 'Attendance Report'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'ماہانہ / پیریڈ وار' : 'Monthly Report'; ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <?php if(has_perm('manage_exams')): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="exams.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #fbe9e7; border: 1px solid #ffccbc; border-radius: 12px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-clipboard-list fa-3x text-danger"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark"><?php echo ($lang=='ur') ? 'امتحانات' : 'Exams'; ?></div>
                    <div class="small text-muted"><?php echo ($lang=='ur') ? 'امتحانی نتائج' : 'Exam Results'; ?></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
