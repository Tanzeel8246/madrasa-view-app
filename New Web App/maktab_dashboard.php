<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id']) || (!app_has_perm('manage_classes') && !app_has_perm('manage_students') && !app_has_perm('manage_teachers') && !app_has_perm('manage_attendance') && !app_has_perm('manage_teacher_attendance') && !app_has_perm('manage_hifz') && !app_has_perm('manage_academic_dashboard'))) {
    header("Location: dashboard.php");
    exit();
}
$isUrdu = isset($_COOKIE['lang']) && $_COOKIE['lang'] == 'ur';
$lang = $isUrdu ? 'ur' : 'en';

$titles = [
    'ur' => [
        'Maktab Management' => 'Ù…Ú©ØªØ¨ Ù…ÛŒÙ†Ø¬Ù…Ù†Ù¹',
        'Classes' => 'Ú©Ù„Ø§Ø³Ø²',
        'Students' => 'Ø·Ù„Ø¨Ø§Ø¡',
        'Teachers' => 'Ø§Ø³Ø§ØªØ°Û',
        'Attendance' => 'Ø·Ù„Ø¨Ø§Ø¡ Ø­Ø§Ø¶Ø±ÛŒ',
        'Teacher Attendance' => 'Ø§Ø³Ø§ØªØ°Û Ø­Ø§Ø¶Ø±ÛŒ',
        'Academics' => 'ØªØ¹Ù„ÛŒÙ…ÛŒ Ú©Ø§Ø±Ú©Ø±Ø¯Ú¯ÛŒ',
        'Reports' => 'Ø±Ù¾ÙˆØ±Ù¹Ø³'
    ],
    'en' => [
        'Maktab Management' => 'Maktab Management',
        'Classes' => 'Classes',
        'Students' => 'Students',
        'Teachers' => 'Teachers',
        'Attendance' => 'Student Attendance',
        'Teacher Attendance' => 'Teacher Attendance',
        'Academics' => 'Academics',
        'Reports' => 'Reports'
    ]
];
function __($key) { global $titles, $lang; return $titles[$lang][$key] ?? $key; }
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $isUrdu ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('Maktab Management') ?> - Madrasa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php include 'theme_styles.php'; ?>
</head>
<body class="<?= $isUrdu ? 'urdu-text' : '' ?>">
    <nav class="navbar navbar-expand-lg navbar-dark theme-bg">
        <div class="container">
            <a class="navbar-brand" href="masjid_dashboard.php"><i class="fas fa-arrow-left me-2"></i><?= __('Maktab Management') ?></a>
        </div>
    </nav>
    <div class="container mt-4">
                <div class="row g-4">
            <?php if(has_perm('manage_classes')): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="manage_classes.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-chalkboard fa-3x mb-3 text-primary"></i>
                            <h5 class="card-title"><?= __('Classes') ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(has_perm('manage_students')): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="students.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-user-graduate fa-3x mb-3 text-success"></i>
                            <h5 class="card-title"><?= __('Students') ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(has_perm('manage_teachers')): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="teachers.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-chalkboard-teacher fa-3x mb-3 text-warning"></i>
                            <h5 class="card-title"><?= __('Teachers') ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(has_perm('manage_hifz') || has_perm('manage_academics')): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="academic_entry_dashboard.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-edit fa-3x mb-3 text-info"></i>
                            <h5 class="card-title"><?= $isUrdu ? 'تعلیمی رپورٹ (اندراج)' : 'Academic Entry' ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="academic_view_dashboard.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-eye fa-3x mb-3 text-primary"></i>
                            <h5 class="card-title"><?= $isUrdu ? 'تعلیمی رپورٹ ویو' : 'Academic View' ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(has_perm('manage_attendance')): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="attendance.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-clipboard-check fa-3x mb-3 text-success"></i>
                            <h5 class="card-title"><?= __('Attendance') ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(has_perm('manage_exams')): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="exams.php" class="text-decoration-none">
                    <div class="card dash-card text-center h-100">
                        <div class="card-body">
                            <i class="fas fa-file-alt fa-3x mb-3 text-secondary"></i>
                            <h5 class="card-title"><?= $isUrdu ? 'امتحانات' : 'Exams' ?></h5>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

