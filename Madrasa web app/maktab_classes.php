<?php 
require_once 'db.php';

// Migrations to support Phase 4 and Phase 5
try { $pdo->exec("ALTER TABLE maktab_classes ADD COLUMN class_type VARCHAR(50) DEFAULT 'Darse Nizami / Other'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE maktab_classes ADD COLUMN main_teacher_id INT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE maktab_classes ADD COLUMN main_teacher_ids JSON NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE maktab_classes ADD COLUMN section VARCHAR(50) DEFAULT NULL"); } catch (PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        period_no INT NOT NULL,
        books VARCHAR(255),
        books_list VARCHAR(255),
        teacher_id INT NULL,
        teacher_ids JSON NULL,
        FOREIGN KEY (class_id) REFERENCES maktab_classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// کلاس حذف کرنا
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM maktab_classes WHERE id = ?")->execute([$id]);
    header("Location: maktab_classes.php");
    exit;
}

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
    return 8;
}

include 'header.php';
$cur_tenant_id = (int)get_current_tenant_id();

// کلاس حذف کرنا
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM maktab_classes WHERE id = ? AND tenant_id = ?")->execute([$id, $cur_tenant_id]);
    header("Location: maktab_classes.php");
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
            $stmt = $pdo->prepare("INSERT INTO maktab_classes (name, section, class_type, main_teacher_ids, subjects_list, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $section, $class_type, $main_teacher_ids, $subjects_list, $cur_tenant_id]);
            $class_id = $pdo->lastInsertId();

            $max_p = get_class_max_periods($class_type, $name);
            $all_tids = [];
            if (!empty($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids'])) {
                $all_tids = array_map('intval', $_POST['main_teacher_ids']);
            }

            for ($i = 1; $i <= $max_p; $i++) {
                $books = trim($_POST['period_books'][$i] ?? '');
                $books_list = trim($_POST['period_books_list'][$i] ?? '');
                $t_ids = isset($_POST['period_teacher'][$i]) && is_array($_POST['period_teacher'][$i]) ? array_map('intval', $_POST['period_teacher'][$i]) : [];
                $teacher_ids = json_encode($t_ids);
                foreach ($t_ids as $tid) {
                    if ($tid > 0 && !in_array($tid, $all_tids)) {
                        $all_tids[] = $tid;
                    }
                }
                if ($books !== '' || $teacher_ids !== '[]' || $books_list !== '') {
                    $p_stmt = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, books, books_list, teacher_ids, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $p_stmt->execute([$class_id, $i, $books, $books_list, $teacher_ids, $cur_tenant_id]);
                }
            }

            if (!empty($all_tids)) {
                $final_main_tids = json_encode(array_values(array_unique($all_tids)));
                $pdo->prepare("UPDATE maktab_classes SET main_teacher_ids = ? WHERE id = ? AND tenant_id = ?")->execute([$final_main_tids, $class_id, $cur_tenant_id]);
            }

            echo "<div class='container-fluid mt-3'><div class='alert alert-success'>کلاس کامیابی سے شامل کر دی گئی!</div></div>";
        } catch (Exception $e) {
            echo "<div class='container-fluid mt-3'><div class='alert alert-danger'>ایرر: یہ کلاس پہلے سے موجود ہو سکتی ہے۔</div></div>";
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
        $stmt = $pdo->prepare("UPDATE maktab_classes SET name=?, section=?, class_type=?, main_teacher_ids=?, subjects_list=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$name, $section, $class_type, $main_teacher_ids, $subjects_list, $id, $cur_tenant_id]);

        $max_p = get_class_max_periods($class_type, $name);
        $all_tids = [];
        if (!empty($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids'])) {
            $all_tids = array_map('intval', $_POST['main_teacher_ids']);
        }

        $pdo->prepare("DELETE FROM class_periods WHERE class_id=?")->execute([$id]);
        for ($i = 1; $i <= $max_p; $i++) {
            $books = trim($_POST['period_books'][$i] ?? '');
            $books_list = trim($_POST['period_books_list'][$i] ?? '');
            $t_ids = isset($_POST['period_teacher'][$i]) && is_array($_POST['period_teacher'][$i]) ? array_map('intval', $_POST['period_teacher'][$i]) : [];
            $teacher_ids = json_encode($t_ids);
            foreach ($t_ids as $tid) {
                if ($tid > 0 && !in_array($tid, $all_tids)) {
                    $all_tids[] = $tid;
                }
            }
            if ($books !== '' || $teacher_ids !== '[]' || $books_list !== '') {
                $p_stmt = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, books, books_list, teacher_ids, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
                $p_stmt->execute([$id, $i, $books, $books_list, $teacher_ids, $cur_tenant_id]);
            }
        }

        if (!empty($all_tids)) {
            $final_main_tids = json_encode(array_values(array_unique($all_tids)));
            $pdo->prepare("UPDATE maktab_classes SET main_teacher_ids = ? WHERE id = ? AND tenant_id = ?")->execute([$final_main_tids, $id, $cur_tenant_id]);
        }

        echo "<div class='container-fluid mt-3'><div class='alert alert-success'>کلاس اپڈیٹ ہو گئی!</div></div>";
    }
}

$maktab_classes = $pdo->query("SELECT * FROM maktab_classes WHERE tenant_id = $cur_tenant_id ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$periods_by_class = [];
$periods_raw = $pdo->query("SELECT * FROM class_periods WHERE (tenant_id = $cur_tenant_id OR class_id IN (SELECT id FROM maktab_classes WHERE tenant_id = $cur_tenant_id)) ORDER BY class_id, period_no")->fetchAll(PDO::FETCH_ASSOC);
foreach ($periods_raw as $pr) {
    $periods_by_class[$pr['class_id']][$pr['period_no']] = $pr;
}

$maktab_teachers = $pdo->query("SELECT id, name FROM maktab_teachers WHERE tenant_id = $cur_tenant_id AND (is_deleted = 0 OR is_deleted IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$teacher_map = [];
foreach($maktab_teachers as $t) {
    $teacher_map[$t['id']] = $t['name'];
}
?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary">مکتب کلاسز مینجمنٹ (Manage Maktab Classes)</h2>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="neobox shadow-sm">
                <h5 class="fw-bold mb-3"><?php echo t('add_new_class'); ?></h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo t('class_name'); ?></label>
                        <input type="text" name="class_name" class="form-control" placeholder="مثلاً: ناظرہ، حفظ، حسنِ صوت" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">سیکشن (Section)</label>
                        <input type="text" name="section" class="form-control" placeholder="مثلاً: A, B, Boys وغیرہ">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">کلاس کی قسم (Class Type)</label>
                        <select name="class_type" class="form-select class-type-select" required>
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
                        </select>
                    </div>

                    <div class="hifz-section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-bold">مرکزی اساتذہ (مکتب اساتذہ)</label>
                            <div class="border rounded p-2 bg-white" style="max-height: 100px; overflow-y: auto;">
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach($maktab_teachers as $t): ?>
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
                        <label class="form-label fw-bold mb-2 periods-header-label">پیریڈز اور اساتذہ کی تفصیل</label>
                        <?php for($i=1; $i<=8; $i++): ?>
                            <div class="border p-2 mb-2 rounded bg-light period-block" data-period="<?= $i ?>">
                                <div class="fw-bold text-secondary mb-1 period-label" style="font-size: 0.85rem;">Period <?= $i ?></div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-5">
                                        <input type="text" name="period_books[<?= $i ?>]" class="form-control form-control-sm mb-1" placeholder="کتاب / سبق / مضمون">
                                        <input type="text" name="period_books_list[<?= $i ?>]" class="form-control form-control-sm" placeholder="تفصیل کتب (اختیاری)">
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <div class="border rounded p-1 bg-white" style="max-height: 80px; overflow-y: auto;">
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach($maktab_teachers as $t): ?>
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
                        <label class="form-label fw-bold">دیگر مضامین اور کل نمبر (اختیاری)</label>
                        <textarea name="subjects_list" class="form-control" rows="2" placeholder="مثلاً: قرآن:100, دینیات:50"></textarea>
                    </div>
                    <button type="submit" name="add_class" class="btn btn-primary w-100 fw-bold">شامل کریں</button>
                </form>
            </div>
        </div>
        <div class="col-md-8">
            <div class="neobox shadow-sm p-0 overflow-hidden">
                <div class="bg-light py-3 px-4 border-bottom fw-bold">مکتب کلاسز کی فہرست</div>
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
                            <?php foreach ($maktab_classes as $c): ?>
                            <tr>
                                <td class="px-4 text-muted">#<?= $c['id']; ?></td>
                                <td class="fw-bold">
                                    <a href="maktab_students.php?class_filter=<?= urlencode($c['name']); ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($c['name']); ?>
                                        <?= !empty($c['section']) ? ' (Section ' . htmlspecialchars($c['section']) . ')' : ''; ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-info text-dark mb-1"><?= htmlspecialchars($c['class_type'] ?? 'Darse Nizami'); ?></span>
                                    <br>
                                    <?php 
                                        $mt_ids = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                                        $mt_names = [];
                                        foreach($mt_ids as $tid) {
                                            if(isset($teacher_map[$tid])) $mt_names[] = $teacher_map[$tid];
                                        }
                                        $teacher_str = empty($mt_names) ? 'تعین نہیں' : implode(', ', $mt_names);
                                    ?>
                                    <small class="text-muted"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($teacher_str) ?></small>
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
                            <?php if (empty($maktab_classes)): ?>
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
<?php foreach($maktab_classes as $c): ?>
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
                        </select>
                    </div>
                </div>

                <div class="hifz-section" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label fw-bold">مرکزی اساتذہ (مکتب اساتذہ)</label>
                        <div class="border rounded p-2 bg-white" style="max-height: 100px; overflow-y: auto;">
                            <div class="d-flex flex-wrap gap-1">
                                <?php 
                                $selected_mt = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                                foreach($maktab_teachers as $t): 
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
                    <label class="form-label fw-bold mb-2 periods-header-label">پیریڈز اور اساتذہ کی تفصیل</label>
                    <div class="row g-2">
                    <?php for($i=1; $i<=8; $i++): 
                        $p_book = $periods_by_class[$c['id']][$i]['books'] ?? '';
                        $p_books_list = $periods_by_class[$c['id']][$i]['books_list'] ?? '';
                        $p_teacher_ids = json_decode($periods_by_class[$c['id']][$i]['teacher_ids'] ?? '[]', true) ?: [];
                    ?>
                        <div class="col-md-6 period-block" data-period="<?= $i ?>">
                            <div class="border p-2 rounded bg-light h-100">
                                <div class="fw-bold text-secondary mb-1 period-label" style="font-size: 0.85rem;">Period <?= $i ?></div>
                                <input type="text" name="period_books[<?= $i ?>]" class="form-control form-control-sm mb-1" placeholder="کتاب / سبق / مضمون" value="<?= htmlspecialchars($p_book) ?>">
                                <input type="text" name="period_books_list[<?= $i ?>]" class="form-control form-control-sm mb-2" placeholder="تفصیل کتب (اختیاری)" value="<?= htmlspecialchars($p_books_list) ?>">
                                <div class="border rounded p-1 bg-white" style="max-height: 80px; overflow-y: auto;">
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach($maktab_teachers as $t): 
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
                    <label class="form-label fw-bold">دیگر مضامین اور کل نمبر (اختیاری)</label>
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

    document.querySelectorAll('.modal').forEach(function(modalEl) {
        modalEl.addEventListener('shown.bs.modal', function() {
            var form = modalEl.querySelector('form');
            if (form) toggleSections(form);
        });
    });
});
</script>

<?php include 'footer.php'; ?>
