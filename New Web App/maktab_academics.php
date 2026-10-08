<?php
require_once 'db.php';

// Fetch active classes
$classes = get_allowed_classes($pdo, 'class_type, name ASC');

// Count active students per class
$stmt = $pdo->query("SELECT class, COUNT(*) as cnt FROM students WHERE (status='Active' OR status='حاضر') AND (is_deleted=0 OR is_deleted IS NULL) GROUP BY class");
$counts = [];
while ($row = $stmt->fetch()) {
    $counts[$row['class']] = $row['cnt'];
}

$studentColumns = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
$studentClassExpr = in_array('class_name', $studentColumns, true)
    ? "TRIM(COALESCE(NULLIF(class, ''), NULLIF(class_name, '')))"
    : "TRIM(class)";
$stmt = $pdo->query("
    SELECT LOWER($studentClassExpr) AS class_key, COUNT(*) AS cnt
    FROM students
    WHERE (is_deleted = 0 OR is_deleted IS NULL)
      AND (status IS NULL OR status = '' OR LOWER(TRIM(status)) IN ('active', 'present', 'حاضر', 'Ø­Ø§Ø¶Ø±'))
      AND $studentClassExpr <> ''
    GROUP BY LOWER($studentClassExpr)
");
$counts = [];
while ($row = $stmt->fetch()) {
    $counts[$row['class_key']] = (int)$row['cnt'];
}

// Group classes
$grouped = ['قرآن کریم' => [], 'دیگر تعلیمات' => []];
foreach ($classes as $c) {
    if (in_array($c['class_type'], ['Hifz/Gardan/Husn', 'Nazira', 'Tajweed', 'Hifz/Nazira', 'Qaida', 'نورانی قاعدہ', 'نماز حنفی']) || strpos($c['name'], 'نماز حنفی') !== false || strpos($c['name'], 'قاعدہ') !== false) {
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
                    if ($c['class_type'] === 'Nazira') {
                        $link = "maktab_nazira.php?class=".urlencode($c['name']);
                    } elseif ($c['class_type'] == 'Qaida' || $c['class_type'] == 'نورانی قاعدہ' || strpos($c['name'], 'قاعدہ') !== false || $c['class_type'] == 'نماز حنفی' || strpos($c['name'], 'نماز حنفی') !== false) {
                        $link = "maktab_qaida.php?class=".urlencode($c['name']);
                    } elseif (in_array($c['class_type'], ['Hifz/Gardan/Husn', 'Hifz/Nazira'])) {
                        $link = "maktab_hifz.php?class=".urlencode($c['name']);
                    } else {
                        // Tajweed, Asri Taleem, Darse Nizami all go to academic_report.php
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
                    
                    // You can also add a sub-badge showing the exact class type
                    $type_badge = htmlspecialchars($c['class_type']);
                    if ($type_badge === 'Hifz/Gardan/Husn' || $type_badge === 'Hifz/Nazira') $type_badge = 'حفظ، گردان، حسن صوت';
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
