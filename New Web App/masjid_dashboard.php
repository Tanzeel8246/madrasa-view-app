<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
// Include migration script so tables are created automatically
require_once 'migrate_masjid.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$title = "Ù…Ø³Ø¬Ø¯ Ù…ÛŒÙ†Ø¬Ù…Ù†Ù¹ (Masjid Management)";
include 'header.php';
?>

<div class="container py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-success"><i class="fas fa-mosque me-2"></i> Ù…Ø³Ø¬Ø¯ Ù…ÛŒÙ†Ø¬Ù…Ù†Ù¹</h2>
    </div>

    <div class="row g-4">
        <!-- Finance & Income -->
        <?php if (app_has_perm('manage_finance')): ?>
        <div class="col-md-3">
            <a href="simple_finance.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 10px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-wallet fa-3x text-success"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark">ÙÙ†ÚˆØ² Ø§ÙˆØ± Ù…Ø§Ù„ÛŒØ§Øª</div>
                    <div class="small text-muted">Ø¢Ù…Ø¯Ù† Ø§ÙˆØ± Ø§Ø®Ø±Ø§Ø¬Ø§Øª Ú©Ø§ Ø±ÛŒÚ©Ø§Ø±Úˆ</div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Staff -->
        <?php if (app_has_perm('manage_teachers')): ?>
        <div class="col-md-3">
            <a href="teachers.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e3f2fd; border: 1px solid #bbdefb; border-radius: 10px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-users fa-3x text-primary"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark">Ù…Ø³Ø¬Ø¯ Ú©Ø§ Ø¹Ù…Ù„Û</div>
                    <div class="small text-muted">Ø§Ù…Ø§Ù…ØŒ Ù…Ø¤Ø°Ù† ÙˆØºÛŒØ±Û Ú©ÛŒ ØªÙØµÛŒÙ„</div>
                </div>
                <?php endif; ?>
        
        <!-- Nazira -->
        <?php if (app_has_perm('manage_classes') || app_has_perm('manage_students') || app_has_perm('manage_teachers') || app_has_perm('manage_attendance') || app_has_perm('manage_teacher_attendance') || app_has_perm('manage_hifz') || app_has_perm('manage_academic_dashboard')): ?>
        <div class="col-md-3">
            <a href="maktab_dashboard.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #fff8e1; border: 1px solid #ffecb3; border-radius: 10px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-book-open fa-3x text-warning"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark">ناظرہ مکتب</div>
                    <div class="small text-muted">بچوں کے داخلے کا ریکارڈ</div>
                </div>
            </a>
        </div>
        </div>
        <?php endif; ?>

        <!-- Assets -->
        <?php if (app_has_perm('manage_finance')): ?>
        <div class="col-md-3">
            <a href="assets.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #fbe9e7; border: 1px solid #ffccbc; border-radius: 10px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-boxes fa-3x text-danger"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark">Ù…Ø³Ø¬Ø¯ Ú©Û’ Ø§Ø«Ø§Ø«Û Ø¬Ø§Øª</div>
                    <div class="small text-muted">Ø³Ø§Ù…Ø§Ù† Ú©ÛŒ ØªÙØµÛŒÙ„Ø§Øª</div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- Itikaf -->
        <?php if (app_has_perm('manage_admissions') || app_has_perm('manage_requests')): ?>
        <div class="col-md-3">
            <a href="masjid_itikaf.php" class="text-decoration-none">
                <div class="stat-card shadow-sm h-100" style="background: #e0f2f1; border: 1px solid #b2dfdb; border-radius: 10px; padding: 20px; text-align: center;">
                    <div class="stat-icon"><i class="fas fa-praying-hands fa-3x text-teal" style="color: #009688;"></i></div>
                    <div class="stat-label fs-5 fw-bold mt-3 text-dark">Ø§Ø¹ØªÚ©Ø§Ù Ø±ÛŒÚ©Ø§Ø±Úˆ</div>
                    <div class="small text-muted">Ø±Ù…Ø¶Ø§Ù† Ø§Ø¹ØªÚ©Ø§Ù Ø±Ø¬Ø³Ù¹Ø±ÛŒØ´Ù†</div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>

