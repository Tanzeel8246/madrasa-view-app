<?php 
require_once 'db.php';

// Migrations to support Phase 4 and Phase 5
try { $pdo->exec("ALTER TABLE classes ADD COLUMN class_type VARCHAR(50) DEFAULT 'Darse Nizami / Other'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE classes ADD COLUMN main_teacher_id INT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE classes ADD COLUMN main_teacher_ids JSON NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE classes ADD COLUMN section VARCHAR(50) DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE classes ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE classes ADD COLUMN subjects_list VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        period_no INT NOT NULL,
        books VARCHAR(255),
        teacher_id INT NULL,
        teacher_ids JSON NULL,
        tenant_id INT NOT NULL DEFAULT 1,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE class_periods ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (PDOException $e) {}

// Drop legacy single-column unique index on classes(name) that prevents multi-tenant class creation
try {
    $indexes = $pdo->query("SHOW INDEX FROM `classes`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $idx) {
        if ($idx['Non_unique'] == 0 && $idx['Key_name'] !== 'PRIMARY' && $idx['Column_name'] === 'name') {
            $kName = $idx['Key_name'];
            $hasTenant = false;
            foreach ($indexes as $idx2) {
                if ($idx2['Key_name'] === $kName && $idx2['Column_name'] === 'tenant_id') {
                    $hasTenant = true;
                }
            }
            if (!$hasTenant) {
                try { $pdo->exec("ALTER TABLE `classes` DROP INDEX `$kName`"); } catch (Throwable $eDrop) {}
            }
        }
    }
    try {
        $pdo->exec("ALTER TABLE `classes` ADD UNIQUE KEY `idx_tenant_class` (`tenant_id`, `name`, `section`, `system_type`)");
    } catch (Throwable $eAddIdx) {}
} catch (Throwable $e) {}

// Auto-seed default classes for Madrasa if empty for this tenant
try {
    $cur_tenant_id = (int)get_current_tenant_id();
    $madrasa_classes_count = $pdo->query("SELECT COUNT(*) FROM classes WHERE (system_type = 'Madrasa' OR system_type IS NULL OR system_type = '') AND tenant_id = $cur_tenant_id")->fetchColumn();
    if ($madrasa_classes_count == 0) {
        $default_classes = [
            ['name' => 'حفظ', 'class_type' => 'Hifz/Gardan/Husn'],
            ['name' => 'ناظرہ', 'class_type' => 'Nazira'],
            ['name' => 'قاعدہ', 'class_type' => 'Qaida'],
            ['name' => 'درجہ اولیٰ', 'class_type' => 'Darse Nizami / Other']
        ];
        $stmt_seed_m = $pdo->prepare("INSERT INTO classes (name, class_type, system_type, tenant_id) VALUES (?, ?, 'Madrasa', ?)");
        foreach ($default_classes as $dc) {
            $stmt_seed_m->execute([$dc['name'], $dc['class_type'], $cur_tenant_id]);
        }
    }
} catch (Exception $e) {}

// Auto-seed 4 Quranic classes for Masjid if empty for this tenant
try {
    $cur_tenant_id = (int)get_current_tenant_id();
    $masjid_classes_count = $pdo->query("SELECT COUNT(*) FROM classes WHERE system_type = 'Masjid' AND tenant_id = $cur_tenant_id")->fetchColumn();
    if ($masjid_classes_count == 0) {
        $quran_classes = [
            ['name' => 'حفظ', 'class_type' => 'Hifz/Gardan/Husn'],
            ['name' => 'ناظرہ', 'class_type' => 'Nazira'],
            ['name' => 'قاعدہ', 'class_type' => 'Qaida'],
            ['name' => 'حسنِ صوت', 'class_type' => 'Hifz/Gardan/Husn']
        ];
        $stmt_seed = $pdo->prepare("INSERT INTO classes (name, class_type, system_type, tenant_id) VALUES (?, ?, 'Masjid', ?)");
        foreach ($quran_classes as $qc) {
            $stmt_seed->execute([$qc['name'], $qc['class_type'], $cur_tenant_id]);
        }
    }
} catch (Exception $e) {}

function get_class_max_periods($class_type, $name = '') {
    $haystack = mb_strtolower($class_type . ' ' . $name, 'UTF-8');
    if (strpos($haystack, 'nazira') !== false || strpos($haystack, 'ناظرہ') !== false) {
        return 2;
    }
    if (strpos($haystack, 'qaida') !== false || strpos($haystack, 'قاعدہ') !== false) {
        return 2;
    }
    if (strpos($haystack, 'husn') !== false || strpos($haystack, 'حسن') !== false || strpos($haystack, 'صوت') !== false) {
        return 4;
    }
    if (strpos($haystack, 'hifz') !== false || strpos($haystack, 'حفظ') !== false || strpos($haystack, 'gardan') !== false || strpos($haystack, 'گردان') !== false) {
        return 4;
    }
    return 8; // Academic / Darse Nizami / Asri Taleem
}

include 'header.php';
$cur_tenant_id = (int)get_current_tenant_id();

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$can_manage_classes = in_array($role, ['admin', 'superadmin', 'nazim'], true) || has_perm('manage_classes');
if (!$can_manage_classes) {
    echo "<script>window.location='dashboard.php';</script>";
    exit;
}

// کلاس حذف کرنا 
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM classes WHERE id = ? AND tenant_id = ?")->execute([$id, $cur_tenant_id]);
    header("Location: manage_classes.php");
    exit;
}

// نئی کلاس شامل کرنا
if (isset($_POST['add_class'])) {
    $name = trim($_POST['class_name']);
    $section = trim($_POST['section'] ?? '');
    $class_type = trim($_POST['class_type']);
    $main_teacher_ids = isset($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids']) ? json_encode($_POST['main_teacher_ids']) : json_encode([]);
    $subjects_list = trim($_POST['subjects_list'] ?? '');
    
    if (!empty($name)) {
        try {
            $sys = $_SESSION['system_type'] ?? 'Madrasa';
            $stmt = $pdo->prepare("INSERT INTO classes (name, section, class_type, main_teacher_ids, subjects_list, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $section, $class_type, $main_teacher_ids, $subjects_list, $sys, $cur_tenant_id]);
            $class_id = $pdo->lastInsertId();

            $max_p = get_class_max_periods($class_type, $name);
            $all_tids = [];
            if (!empty($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids'])) {
                $all_tids = array_map('intval', $_POST['main_teacher_ids']);
            }

            for ($i = 1; $i <= $max_p; $i++) {
                $books = trim($_POST['period_books'][$i] ?? '');
                $t_ids = isset($_POST['period_teacher'][$i]) && is_array($_POST['period_teacher'][$i]) ? array_map('intval', $_POST['period_teacher'][$i]) : [];
                $teacher_ids = json_encode($t_ids);
                foreach ($t_ids as $tid) {
                    if ($tid > 0 && !in_array($tid, $all_tids)) {
                        $all_tids[] = $tid;
                    }
                }
                if ($books !== '' || $teacher_ids !== '[]') {
                    $p_stmt = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, books, teacher_ids, tenant_id) VALUES (?, ?, ?, ?, ?)");
                    $p_stmt->execute([$class_id, $i, $books, $teacher_ids, $cur_tenant_id]);
                }
            }

            if (!empty($all_tids)) {
                $final_main_tids = json_encode(array_values(array_unique($all_tids)));
                $pdo->prepare("UPDATE classes SET main_teacher_ids = ? WHERE id = ? AND tenant_id = ?")->execute([$final_main_tids, $class_id, $cur_tenant_id]);
            }

            echo "<div class='container-fluid mt-3'><div class='alert alert-success'>کلاس کامیابی سے شامل کر دی گئی!</div></div>";
        } catch (Exception $e) {
            $errMsg = htmlspecialchars($e->getMessage());
            echo "<div class='container-fluid mt-3'><div class='alert alert-danger'>ایرر: {$errMsg}</div></div>";
        }
    }
}

// کلاس اپڈیٹ کرنا
if (isset($_POST['update_class'])) {
    $id = (int)$_POST['class_id'];
    $name = trim($_POST['class_name']);
    $section = trim($_POST['section'] ?? '');
    $class_type = trim($_POST['class_type']);
    $main_teacher_ids = isset($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids']) ? json_encode($_POST['main_teacher_ids']) : json_encode([]);
    $subjects_list = trim($_POST['subjects_list'] ?? '');
    
    if (!empty($name)) {
        $stmt = $pdo->prepare("UPDATE classes SET name=?, section=?, class_type=?, main_teacher_ids=?, subjects_list=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$name, $section, $class_type, $main_teacher_ids, $subjects_list, $id, $cur_tenant_id]);

        $max_p = get_class_max_periods($class_type, $name);
        $all_tids = [];
        if (!empty($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids'])) {
            $all_tids = array_map('intval', $_POST['main_teacher_ids']);
        }

        $pdo->prepare("DELETE FROM class_periods WHERE class_id=?")->execute([$id]);
        for ($i = 1; $i <= $max_p; $i++) {
            $books = trim($_POST['period_books'][$i] ?? '');
            $t_ids = isset($_POST['period_teacher'][$i]) && is_array($_POST['period_teacher'][$i]) ? array_map('intval', $_POST['period_teacher'][$i]) : [];
            $teacher_ids = json_encode($t_ids);
            foreach ($t_ids as $tid) {
                if ($tid > 0 && !in_array($tid, $all_tids)) {
                    $all_tids[] = $tid;
                }
            }
            if ($books !== '' || $teacher_ids !== '[]') {
                $p_stmt = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, books, teacher_ids, tenant_id) VALUES (?, ?, ?, ?, ?)");
                $p_stmt->execute([$id, $i, $books, $teacher_ids, $cur_tenant_id]);
            }
        }

        if (!empty($all_tids)) {
            $final_main_tids = json_encode(array_values(array_unique($all_tids)));
            $pdo->prepare("UPDATE classes SET main_teacher_ids = ? WHERE id = ? AND tenant_id = ?")->execute([$final_main_tids, $id, $cur_tenant_id]);
        }

        echo "<div class='container-fluid mt-3'><div class='alert alert-success'>کلاس اپڈیٹ ہو گئی!</div></div>";
    }
}

$sys = $_SESSION['system_type'] ?? 'Madrasa';
$sys_cond = ($sys === 'Masjid') ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";
$classes = $pdo->query("SELECT * FROM classes WHERE $sys_cond AND tenant_id = $cur_tenant_id ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$periods_by_class = [];
$periods_raw = $pdo->query("SELECT * FROM class_periods WHERE (tenant_id = $cur_tenant_id OR class_id IN (SELECT id FROM classes WHERE tenant_id = $cur_tenant_id)) ORDER BY class_id, period_no")->fetchAll(PDO::FETCH_ASSOC);
foreach ($periods_raw as $pr) {
    $periods_by_class[$pr['class_id']][$pr['period_no']] = $pr;
}

$teachers = $pdo->query("SELECT id, name FROM teachers WHERE $sys_cond AND tenant_id = $cur_tenant_id AND (is_deleted = 0 OR is_deleted IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$teacher_map = [];
foreach($teachers as $t) {
    $teacher_map[$t['id']] = $t['name'];
}
?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary">
            <?php echo ($sys === 'Masjid') ? 'مکتب کلاسز مینجمنٹ (مسجد)' : 'کلاسز مینجمنٹ (مدرسہ)'; ?>
        </h2>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="neobox shadow-sm">
                <h5 class="fw-bold mb-3"><?php echo ($sys === 'Masjid') ? 'نئی مکتب کلاس شامل کریں' : t('add_new_class'); ?></h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo t('class_name'); ?></label>
                        <input type="text" name="class_name" class="form-control" placeholder="<?php echo ($sys === 'Masjid') ? 'مثلاً: حفظ، ناظرہ، قاعدہ، حسنِ صوت' : 'مثلاً: درجہ اولیٰ'; ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">سیکشن (Section)</label>
                        <input type="text" name="section" class="form-control" placeholder="مثلاً: A, B, Boys وغیرہ">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">کلاس کی قسم (Class Type)</label>
                        <select name="class_type" class="form-select class-type-select" required>
                            <?php if ($sys === 'Masjid'): ?>
                                <option value="Hifz/Gardan/Husn">حفظ و گردان</option>
                                <option value="Husn-e-Saut">حسنِ صوت</option>
                                <option value="Nazira">ناظرہ</option>
                                <option value="Qaida">نورانی قاعدہ</option>
                                <option value="Tajweed">تجوید</option>
                            <?php else: ?>
                                <optgroup label="قرآن کریم">
                                    <option value="Hifz/Gardan/Husn">حفظ و گردان</option>
                                    <option value="Husn-e-Saut">حسنِ صوت</option>
                                    <option value="Nazira">ناظرہ</option>
                                    <option value="Qaida">نورانی قاعدہ / نماز حنفی</option>
                                    <option value="Tajweed">تجوید</option>
                                </optgroup>
                                <optgroup label="دیگر">
                                    <option value="Asri Taleem">عصری تعلیم</option>
                                    <option value="Darse Nizami">درس نظامی</option>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="hifz-section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-bold">مرکزی اساتذہ (<?php echo ($sys === 'Masjid') ? 'مکتب / مسجد اساتذہ' : 'حفظ / ناظرہ'; ?>)</label>
                            <div class="border rounded p-2 bg-white" style="max-height: 100px; overflow-y: auto;">
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach($teachers as $t): ?>
                                        <label class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-pill teacher-chip" style="font-size: 0.8rem; cursor: pointer;">
                                            <input type="checkbox" name="main_teacher_ids[]" value="<?= $t['id'] ?>" class="d-none" onchange="toggleTeacherChip(this)">
                                            <i class="fas fa-check-circle me-1 d-none"></i>
                                            <?= htmlspecialchars($t['name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-1">ایک یا زائد اساتذہ منتخب کرنے کے لیے نام پر کلک کریں۔</small>
                        </div>
                    </div>

                    <div class="darse-nizami-section">
                        <label class="form-label fw-bold mb-2 periods-header-label">
                            پیریڈز اور اساتذہ کی تفصیل
                        </label>
                        <?php 
                        for($i=1; $i<=8; $i++): ?>
                            <div class="border p-2 mb-2 rounded bg-light period-block" data-period="<?= $i ?>">
                                <div class="fw-bold text-secondary mb-1 period-label" style="font-size: 0.85rem;">
                                    Period <?= $i ?>
                                </div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-5">
                                        <input type="text" name="period_books[<?= $i ?>]" class="form-control form-control-sm" placeholder="کتاب / سبق / مضمون">
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <div class="border rounded p-1 bg-white" style="max-height: 80px; overflow-y: auto;">
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach($teachers as $t): ?>
                                                    <label class="btn btn-outline-secondary btn-sm py-0 px-2 rounded-pill teacher-chip" style="font-size: 0.76rem; cursor: pointer;">
                                                        <input type="checkbox" name="period_teacher[<?= $i ?>][]" value="<?= $t['id'] ?>" class="d-none" onchange="toggleTeacherChip(this)">
                                                        <i class="fas fa-check-circle me-1 d-none"></i>
                                                        <?= htmlspecialchars($t['name']) ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label fw-bold">دیگر مضامین اور کل نمبر (اختياری)</label>
                        <textarea name="subjects_list" class="form-control" rows="2" placeholder="مثلاً: قرآن:100, دینیات:50"></textarea>
                    </div>
                    <button type="submit" name="add_class" class="btn btn-primary w-100 fw-bold">شامل کریں</button>
                </form>
            </div>
        </div>
        <div class="col-md-8">
            <div class="neobox shadow-sm p-0 overflow-hidden">
                <div class="bg-light py-3 px-4 border-bottom fw-bold">کلاسز کی فہرست</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4">ID</th>
                                <th><?php echo t('class_name'); ?></th>
                                <th>قسم / استاذ</th>
                                <th>سٹیٹس</th>
                                <th class="text-center"><?php echo t('action_col'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $c): ?>
                            <tr>
                                <td class="px-4 text-muted">#<?= $c['id']; ?></td>
                                <td class="fw-bold">
                                    <a href="students.php?class_filter=<?= urlencode($c['name']); ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($c['name']); ?>
                                        <?= !empty($c['section']) ? ' (Section ' . htmlspecialchars($c['section']) . ')' : ''; ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-info text-dark mb-1"><?= htmlspecialchars($c['class_type'] ?? 'Darse Nizami'); ?></span>
                                    <br>
                                    <?php if(in_array(($c['class_type'] ?? ''), ['Hifz/Gardan/Husn', 'Nazira', 'Hifz/Nazira'])): ?>
                                        <?php
                                            $mt_ids = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                                            $mt_names = [];
                                            foreach($mt_ids as $tid) {
                                                if(isset($teacher_map[$tid])) $mt_names[] = $teacher_map[$tid];
                                            }
                                            $teacher_str = empty($mt_names) ? 'تعین نہیں' : implode(', ', $mt_names);
                                        ?>
                                        <small class="text-muted"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($teacher_str) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted"><?= count($periods_by_class[$c['id']] ?? []) ?> Periods Configured</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-success small"><?= htmlspecialchars($c['status'] ?? 'Active'); ?></span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal<?= $c['id']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete=<?= $c['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('کیا آپ واقعی یہ کلاس حذف کرنا چاہتے ہیں؟')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($classes)): ?>
                                <tr><td colspan="5" class="text-center py-4">کوئی ریکارڈ نہیں ملا</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals placed entirely outside the table to prevent blinking -->
<?php foreach($classes as $c): ?>
<div class="modal fade" id="editModal<?= $c['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">کلاس اپڈیٹ کریں</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-start">
                <input type="hidden" name="class_id" value="<?= $c['id']; ?>">
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">کلاس کا نام</label>
                        <input type="text" name="class_name" class="form-control" value="<?= htmlspecialchars($c['name']); ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">سیکشن (Section)</label>
                        <input type="text" name="section" class="form-control" value="<?= htmlspecialchars($c['section'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">کلاس کی قسم</label>
                        <select name="class_type" class="form-select class-type-select" required>
                            <?php if ($sys === 'Masjid'): ?>
                                <option value="Hifz/Gardan/Husn" <?= in_array(($c['class_type'] ?? ''), ['Hifz/Gardan/Husn', 'Hifz/Nazira']) ? 'selected' : '' ?>>حفظ و گردان</option>
                                <option value="Husn-e-Saut" <?= ($c['class_type'] ?? '') === 'Husn-e-Saut' ? 'selected' : '' ?>>حسنِ صوت</option>
                                <option value="Nazira" <?= ($c['class_type'] ?? '') === 'Nazira' ? 'selected' : '' ?>>ناظرہ</option>
                                <option value="Qaida" <?= ($c['class_type'] ?? '') === 'Qaida' ? 'selected' : '' ?>>نورانی قاعدہ</option>
                                <option value="Tajweed" <?= ($c['class_type'] ?? '') === 'Tajweed' ? 'selected' : '' ?>>تجوید</option>
                            <?php else: ?>
                                <optgroup label="قرآن کریم">
                                    <option value="Hifz/Gardan/Husn" <?= in_array(($c['class_type'] ?? ''), ['Hifz/Gardan/Husn', 'Hifz/Nazira']) ? 'selected' : '' ?>>حفظ و گردان</option>
                                    <option value="Husn-e-Saut" <?= ($c['class_type'] ?? '') === 'Husn-e-Saut' ? 'selected' : '' ?>>حسنِ صوت</option>
                                    <option value="Nazira" <?= ($c['class_type'] ?? '') === 'Nazira' ? 'selected' : '' ?>>ناظرہ</option>
                                    <option value="Qaida" <?= ($c['class_type'] ?? '') === 'Qaida' ? 'selected' : '' ?>>نورانی قاعدہ / نماز حنفی</option>
                                    <option value="Tajweed" <?= ($c['class_type'] ?? '') === 'Tajweed' ? 'selected' : '' ?>>تجوید</option>
                                </optgroup>
                                <optgroup label="دیگر">
                                    <option value="Asri Taleem" <?= ($c['class_type'] ?? '') === 'Asri Taleem' ? 'selected' : '' ?>>عصری تعلیم</option>
                                    <option value="Darse Nizami" <?= in_array(($c['class_type'] ?? ''), ['Darse Nizami', 'Darse Nizami / Other']) ? 'selected' : '' ?>>درس نظامی</option>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="hifz-section" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label fw-bold">مرکزی اساتذہ (<?php echo ($sys === 'Masjid') ? 'مکتب / مسجد اساتذہ' : 'حفظ / ناظرہ'; ?>)</label>
                        <div class="border rounded p-2 bg-white" style="max-height: 100px; overflow-y: auto;">
                            <div class="d-flex flex-wrap gap-1">
                                <?php 
                                $selected_mt = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                                foreach($teachers as $t): 
                                    $is_checked = in_array($t['id'], $selected_mt);
                                    $btn_class = $is_checked ? 'btn-success text-white' : 'btn-outline-secondary';
                                ?>
                                    <label class="btn <?= $btn_class ?> btn-sm py-0 px-2 rounded-pill teacher-chip" style="font-size: 0.8rem; cursor: pointer;">
                                        <input type="checkbox" name="main_teacher_ids[]" value="<?= $t['id'] ?>" class="d-none" onchange="toggleTeacherChip(this)" <?= $is_checked ? 'checked' : '' ?>>
                                        <i class="fas fa-check-circle me-1 <?= $is_checked ? '' : 'd-none' ?>"></i>
                                        <?= htmlspecialchars($t['name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-1">ایک یا زائد اساتذہ منتخب کرنے کے لیے نام پر کلک کریں۔</small>
                    </div>
                </div>

                <div class="darse-nizami-section">
                    <label class="form-label fw-bold mb-2 periods-header-label">
                        پیریڈز اور اساتذہ کی تفصیل
                    </label>
                    <div class="row g-2">
                    <?php 
                    for($i=1; $i<=8; $i++): 
                        $p_book = $periods_by_class[$c['id']][$i]['books'] ?? '';
                        $p_teacher_ids = json_decode($periods_by_class[$c['id']][$i]['teacher_ids'] ?? '[]', true) ?: [];
                    ?>
                        <div class="col-md-6 period-block" data-period="<?= $i ?>">
                            <div class="border p-2 rounded bg-light h-100">
                                <div class="fw-bold text-secondary mb-1 period-label" style="font-size: 0.85rem;">
                                    Period <?= $i ?>
                                </div>
                                <input type="text" name="period_books[<?= $i ?>]" class="form-control form-control-sm mb-2" placeholder="کتاب / سبق / مضمون" value="<?= htmlspecialchars($p_book) ?>">
                                <div class="border rounded p-1 bg-white" style="max-height: 80px; overflow-y: auto;">
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach($teachers as $t): 
                                            $is_p_checked = in_array($t['id'], $p_teacher_ids);
                                            $p_btn_class = $is_p_checked ? 'btn-success text-white' : 'btn-outline-secondary';
                                        ?>
                                            <label class="btn <?= $p_btn_class ?> btn-sm py-0 px-2 rounded-pill teacher-chip" style="font-size: 0.76rem; cursor: pointer;">
                                                <input type="checkbox" name="period_teacher[<?= $i ?>][]" value="<?= $t['id'] ?>" class="d-none" onchange="toggleTeacherChip(this)" <?= $is_p_checked ? 'checked' : '' ?>>
                                                <i class="fas fa-check-circle me-1 <?= $is_p_checked ? '' : 'd-none' ?>"></i>
                                                <?= htmlspecialchars($t['name']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                    </div>
                </div>

                <div class="mb-3 mt-3">
                    <label class="form-label fw-bold">دیگر مضامین اور کل نمبر (اختياری)</label>
                    <textarea name="subjects_list" class="form-control" rows="2"><?= htmlspecialchars($c['subjects_list'] ?? ''); ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">کینسل</button>
                <button type="submit" name="update_class" class="btn btn-primary fw-bold">محفوظ کریں</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
function toggleTeacherChip(input) {
    var label = input.closest('.teacher-chip');
    if (!label) return;
    var icon = label.querySelector('.fa-check-circle');
    if (input.checked) {
        label.classList.remove('btn-outline-secondary');
        label.classList.add('btn-success', 'text-white');
        if (icon) icon.classList.remove('d-none');
    } else {
        label.classList.remove('btn-success', 'text-white');
        label.classList.add('btn-outline-secondary');
        if (icon) icon.classList.add('d-none');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    function toggleSections(form) {
        if (!form) return;
        var selectElement = form.querySelector('.class-type-select');
        var val = selectElement ? selectElement.value : '';
        var nameInput = form.querySelector('input[name="class_name"]');
        var nameVal = nameInput ? nameInput.value.trim().toLowerCase() : '';
        var hifzSection = form.querySelector('.hifz-section');
        var darseSection = form.querySelector('.darse-nizami-section');
        var periodsHeader = form.querySelector('.periods-header-label');

        // Identify category
        var isNazira = (val === 'Nazira' || nameVal.includes('ناظرہ') || nameVal.includes('nazira'));
        var isQaida = (val === 'Qaida' || nameVal.includes('قاعدہ') || nameVal.includes('qaida'));
        var isHusn = (val === 'Husn-e-Saut' || nameVal.includes('حسن') || nameVal.includes('husn') || nameVal.includes('صوت'));
        var isHifz = (!isHusn && (val === 'Hifz/Gardan/Husn' || val === 'Hifz' || nameVal.includes('حفظ') || nameVal.includes('hifz') || nameVal.includes('گردان')));

        var maxPeriods = 8;
        if (isNazira || isQaida) {
            maxPeriods = 2;
        } else if (isHifz || isHusn) {
            maxPeriods = 4;
        } else {
            maxPeriods = 8;
        }

        if (hifzSection) {
            hifzSection.style.display = (isHifz || isHusn || isNazira || isQaida) ? 'block' : 'none';
        }
        if (darseSection) {
            darseSection.style.display = 'block';
        }

        if (periodsHeader) {
            if (isNazira) {
                periodsHeader.textContent = 'ناظرہ پیریڈز / سیشنز کنفیگریشن (2 پیریڈز)';
            } else if (isQaida) {
                periodsHeader.textContent = 'قاعدہ پیریڈز / سیشنز کنفیگریشن (2 پیریڈز)';
            } else if (isHusn) {
                periodsHeader.textContent = 'حسنِ صوت پیریڈز / سیشنز کنفیگریشن (4 پیریڈز)';
            } else if (isHifz) {
                periodsHeader.textContent = 'حفظ و گردان پیریڈز / سیشنز کنفیگریشن (4 پیریڈز)';
            } else {
                periodsHeader.textContent = 'پیریڈز اور اساتذہ کی تفصیل (8 Periods)';
            }
        }

        var periodBlocks = form.querySelectorAll('.period-block');
        periodBlocks.forEach(function(block) {
            var pNum = parseInt(block.getAttribute('data-period'));
            var labelEl = block.querySelector('.period-label');
            if (pNum <= maxPeriods) {
                block.style.display = '';
                if (labelEl) {
                    if (isNazira || isQaida) {
                        if (pNum === 1) labelEl.textContent = 'پیریڈ 1: صبح کا سیشن (Morning Shift)';
                        if (pNum === 2) labelEl.textContent = 'پیریڈ 2: شام کا سیشن (Evening Shift)';
                    } else if (isHifz) {
                        if (pNum === 1) labelEl.textContent = 'پیریڈ 1: صبح کا سیشن (سبق / Morning Shift)';
                        if (pNum === 2) labelEl.textContent = 'پیریڈ 2: ناشتہ / دوپہر کا سیشن (سبقی / Noon Shift)';
                        if (pNum === 3) labelEl.textContent = 'پیریڈ 3: عصر / شام کا سیشن (منزل / Evening Shift)';
                        if (pNum === 4) labelEl.textContent = 'پیریڈ 4: مغرب / عشاء کا سیشن (تکرار / Night Shift)';
                    } else if (isHusn) {
                        if (pNum === 1) labelEl.textContent = 'پیریڈ 1: صبح کا سیشن (Morning Shift)';
                        if (pNum === 2) labelEl.textContent = 'پیریڈ 2: دوپہر کا سیشن (Noon Shift)';
                        if (pNum === 3) labelEl.textContent = 'پیریڈ 3: عصر / شام کا سیشن (Evening Shift)';
                        if (pNum === 4) labelEl.textContent = 'پیریڈ 4: مغرب / عشاء کا سیشن (Night Shift)';
                    } else {
                        var extraBadge = '';
                        if (pNum === 7) extraBadge = ' <span class="badge bg-secondary ms-1">مطالعہ</span>';
                        if (pNum === 8) extraBadge = ' <span class="badge bg-secondary ms-1">تکرار</span>';
                        labelEl.innerHTML = 'Period ' + pNum + extraBadge;
                    }
                }
            } else {
                block.style.display = 'none';
            }
        });
    }

    var forms = document.querySelectorAll('form');
    forms.forEach(function(form) {
        var select = form.querySelector('.class-type-select');
        var nameInput = form.querySelector('input[name="class_name"]');
        if (select) {
            select.addEventListener('change', function() {
                toggleSections(form);
            });
        }
        if (nameInput) {
            nameInput.addEventListener('input', function() {
                toggleSections(form);
            });
        }
        if (select || nameInput) {
            toggleSections(form);
        }
    });

    // Also listen for modal shown to ensure correct initial rendering
    document.querySelectorAll('.modal').forEach(function(modalEl) {
        modalEl.addEventListener('shown.bs.modal', function() {
            var form = modalEl.querySelector('form');
            if (form) toggleSections(form);
        });
    });
});
</script>

<?php include 'footer.php'; ?>
