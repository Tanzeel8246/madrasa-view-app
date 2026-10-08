<?php
require_once 'db.php';

$sys_type = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys_type === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

$user_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin = in_array($user_role, ['admin', 'superadmin', 'administrator'], true);
$p_needed = ($sys_type === 'Masjid') ? 'manage_masjid_academic_dashboard' : 'manage_academic_dashboard';
$p_alt1 = ($sys_type === 'Masjid') ? 'manage_masjid_academic_entry' : 'manage_academic_entry';
$p_alt2 = ($sys_type === 'Masjid') ? 'manage_masjid_academics' : 'manage_academics';
$p_alt3 = ($sys_type === 'Masjid') ? 'manage_masjid_hifz' : 'manage_hifz';
if (!$is_admin && !app_has_perm($p_needed) && !app_has_perm($p_alt1) && !app_has_perm($p_alt2) && !app_has_perm($p_alt3)) {
    include 'header.php';
    echo "<div class='alert alert-danger m-4 text-center fw-bold'>آپ کو تعلیمی کارکردگی ڈیش بورڈ تک رسائی کی اجازت نہیں ہے۔</div>";
    include 'footer.php';
    exit;
}

// Fetch active classes for the current system_type
$classes = get_allowed_classes($pdo, 'class_type, name ASC', null, null, $sys_type);

$studentColumns = [];
try {
    $studentColumns = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$studentClassExpr = in_array('class_name', $studentColumns, true)
    ? "TRIM(COALESCE(NULLIF(class, ''), NULLIF(class_name, '')))"
    : "TRIM(class)";

$has_std_sys = in_array('system_type', $studentColumns, true);
$std_sys_cond = $has_std_sys ? $sys_cond : "1=1";
$privacy_class_sql = get_student_class_privacy_sql($pdo, $studentClassExpr);

$cur_tenant_id = (int)get_current_tenant_id();
$counts = [];
try {
    $stmt = $pdo->query("
        SELECT LOWER($studentClassExpr) AS class_key, COUNT(*) AS cnt
        FROM students
        WHERE tenant_id = $cur_tenant_id AND $std_sys_cond AND ($privacy_class_sql) AND (is_deleted = 0 OR is_deleted IS NULL)
          AND (status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'حاضر', 'Ø­Ø§Ø¶Ø±'))
          AND $studentClassExpr <> ''
        GROUP BY LOWER($studentClassExpr)
    ");
    while ($row = $stmt->fetch()) {
        $counts[$row['class_key']] = (int)$row['cnt'];
    }
} catch (Exception $e) {
    try {
        $stmt = $pdo->query("SELECT class, COUNT(*) as cnt FROM students WHERE tenant_id = $cur_tenant_id AND $std_sys_cond AND ($privacy_class_sql) AND (status='Active' OR status='حاضر' OR status IS NULL OR status='') AND (is_deleted=0 OR is_deleted IS NULL) GROUP BY class");
        while ($row = $stmt->fetch()) {
            $counts[strtolower(trim($row['class']))] = (int)$row['cnt'];
        }
    } catch (Exception $e2) {}
}

// Fallback ONLY for admin if get_allowed_classes was empty
if (empty($classes) && $is_admin) {
    try {
        $classes = $pdo->query("SELECT * FROM classes WHERE tenant_id = $cur_tenant_id AND $sys_cond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        try {
            $classes = $pdo->query("SELECT * FROM classes WHERE tenant_id = $cur_tenant_id AND $sys_cond ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            $classes = [];
        }
    }
}

// Group classes
$grouped = ['قرآن کریم' => [], 'دیگر تعلیمات' => []];
foreach ($classes as $c) {
    $cType = $c['class_type'] ?? '';
    $cName = $c['name'] ?? '';
    if (in_array($cType, ['Hifz/Gardan/Husn', 'Nazira', 'Tajweed', 'Hifz/Nazira', 'Qaida', 'نورانی قاعدہ', 'نماز حنفی']) 
        || strpos($cName, 'نماز حنفی') !== false 
        || strpos($cName, 'قاعدہ') !== false
        || strpos($cName, 'حفظ') !== false
        || strpos($cName, 'ناظرہ') !== false
        || strpos($cName, 'تجوید') !== false) {
        $grouped['قرآن کریم'][] = $c;
    } else {
        $grouped['دیگر تعلیمات'][] = $c;
    }
}

$page_title = t('manage_academic_dashboard');
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-layer-group text-primary me-2"></i> <?php echo t('manage_academic_dashboard'); ?></h2>
    </div>

    <?php 
    $total_rendered = count($grouped['قرآن کریم']) + count($grouped['دیگر تعلیمات']);
    if ($total_rendered === 0): 
    ?>
        <div class="alert alert-info text-center my-4 py-4 rounded-3 shadow-sm">
            <i class="fas fa-info-circle fa-2x mb-2 text-primary"></i>
            <h5>فی الوقت کوئی کلاس موجود نہیں ہے۔</h5>
            <p class="text-muted mb-0">کلاسز مینجمنٹ میں جا کر نئی کلاسز شامل کریں یا تصدیق کریں کہ کلاسز ایکٹیو ہیں۔</p>
            <a href="manage_classes.php" class="btn btn-primary mt-3 btn-sm"><i class="fas fa-plus me-1"></i> کلاسز مینجمنٹ</a>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $type => $classList): ?>
            <?php if (count($classList) > 0): ?>
                <h4 class="mt-4 mb-3 border-bottom pb-2">
                    <?php if ($type == 'قرآن کریم'): ?>
                        <i class="fas fa-quran text-success me-2"></i> <?php echo $type; ?>
                    <?php else: ?>
                        <i class="fas fa-book text-info me-2"></i> <?php echo $type; ?>
                    <?php endif; ?>
                </h4>
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4 mb-4">
                    <?php foreach ($classList as $c): 
                        $cnt = $counts[strtolower(trim($c['name']))] ?? 0;
                        
                        // Determine link
                        if ($c['class_type'] === 'Nazira' || strpos($c['name'], 'ناظرہ') !== false) {
                            $link = "nazira.php?class=".urlencode($c['name']);
                        } elseif ($c['class_type'] == 'Qaida' || $c['class_type'] == 'نورانی قاعدہ' || strpos($c['name'], 'قاعدہ') !== false || $c['class_type'] == 'نماز حنفی' || strpos($c['name'], 'نماز حنفی') !== false) {
                            $link = "qaida_entry.php?class=".urlencode($c['name']);
                        } elseif (strpos($c['name'], 'حسن') !== false || strpos($c['name'], 'صوت') !== false || stripos($c['name'], 'husn') !== false || stripos($c['name'], 'saut') !== false || $c['class_type'] === 'Husn' || $c['class_type'] === 'Husn Saut' || $c['class_type'] === 'حسن صوت') {
                            $link = "husn_saut.php?class=".urlencode($c['name']);
                        } elseif (in_array($c['class_type'], ['Hifz/Gardan/Husn', 'Hifz/Nazira']) || strpos($c['name'], 'حفظ') !== false) {
                            $link = "hifz.php?class=".urlencode($c['name']);
                        } else {
                            $link = "academic_report.php?class=".urlencode($c['name']);
                        }
                        
                        // Determine icon
                        if ($type == 'قرآن کریم') {
                            $icon = "fa-quran";
                            $color = "success";
                        } else {
                            $icon = "fa-book";
                            $color = "info";
                        }
                        
                        $type_badge = htmlspecialchars($c['class_type'] ?? 'کلاس');
                        if (strpos($c['name'], 'حسن') !== false || strpos($c['name'], 'صوت') !== false || $type_badge === 'Husn Saut' || $type_badge === 'Husn') {
                            $type_badge = 'حسن صوت';
                        } elseif ($type_badge === 'Hifz/Gardan/Husn' || $type_badge === 'Hifz/Nazira') {
                            $type_badge = 'حفظ / گردان';
                        }
                        if ($type_badge === 'Nazira') $type_badge = 'ناظرہ';
                        if ($type_badge === 'Tajweed') $type_badge = 'تجوید';
                        if ($type_badge === 'Asri Taleem') $type_badge = 'عصری تعلیم';
                        if ($type_badge === 'Darse Nizami') $type_badge = 'درس نظامی';
                    ?>
                    <div class="col">
                        <a href="<?php echo $link; ?>" class="text-decoration-none">
                            <div class="card h-100 shadow-sm hover-shadow transition-all border-top border-<?php echo $color; ?> border-3">
                                <div class="card-body text-center">
                                    <div class="display-4 text-<?php echo $color; ?> mb-3">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <h5 class="card-title text-dark fw-bold mb-2"><?php echo htmlspecialchars($c['name']); ?></h5>
                                    <div class="mb-2">
                                        <span class="badge bg-light text-dark border"><?php echo $type_badge; ?></span>
                                    </div>
                                    <span class="badge bg-secondary rounded-pill fs-6 px-3 py-2">
                                        <i class="fas fa-users me-1"></i> <?php echo $cnt; ?> طلبہ
                                    </span>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.hover-shadow:hover {
    transform: translateY(-5px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
}
.transition-all {
    transition: all .3s ease;
}
</style>

<?php require_once 'footer.php'; ?>
