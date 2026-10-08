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
        teacher_id INT NULL,
        teacher_ids JSON NULL,
        FOREIGN KEY (class_id) REFERENCES maktab_classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// کلاس حذف کرنا 
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM maktab_classes WHERE id = ?")->execute([$id]);
    header("Location: manage_maktab_classes.php");
    exit;
}

include 'header.php';

// نئی کلاس شامل کرنا
if (isset($_POST['add_class'])) {
    $name = trim($_POST['class_name']);
    $section = trim($_POST['section'] ?? '');
    $class_type = trim($_POST['class_type']);
    $main_teacher_ids = isset($_POST['main_teacher_ids']) && is_array($_POST['main_teacher_ids']) ? json_encode($_POST['main_teacher_ids']) : json_encode([]);
    $subjects_list = trim($_POST['subjects_list'] ?? '');
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO maktab_classes (name, section, class_type, main_teacher_ids, subjects_list) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $section, $class_type, $main_teacher_ids, $subjects_list]);
            $class_id = $pdo->lastInsertId();

            // Always save periods for all maktab_classes
            for ($i = 1; $i <= 8; $i++) {
                $books = trim($_POST['period_books'][$i] ?? '');
                $books_list = trim($_POST['period_books_list'][$i] ?? '');
                $teacher_ids = isset($_POST['period_teacher'][$i]) && is_array($_POST['period_teacher'][$i]) ? json_encode($_POST['period_teacher'][$i]) : json_encode([]);
                if ($books !== '' || $teacher_ids !== '[]' || $books_list !== '') {
                    $p_stmt = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, books, books_list, teacher_ids) VALUES (?, ?, ?, ?, ?)");
                    $p_stmt->execute([$class_id, $i, $books, $books_list, $teacher_ids]);
                }
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
        $stmt = $pdo->prepare("UPDATE maktab_classes SET name=?, section=?, class_type=?, main_teacher_ids=?, subjects_list=? WHERE id=?");
        $stmt->execute([$name, $section, $class_type, $main_teacher_ids, $subjects_list, $id]);

        $pdo->prepare("DELETE FROM class_periods WHERE class_id=?")->execute([$id]);
        for ($i = 1; $i <= 8; $i++) {
            $books = trim($_POST['period_books'][$i] ?? '');
            $books_list = trim($_POST['period_books_list'][$i] ?? '');
            $teacher_ids = isset($_POST['period_teacher'][$i]) && is_array($_POST['period_teacher'][$i]) ? json_encode($_POST['period_teacher'][$i]) : json_encode([]);
            if ($books !== '' || $teacher_ids !== '[]' || $books_list !== '') {
                $p_stmt = $pdo->prepare("INSERT INTO class_periods (class_id, period_no, books, books_list, teacher_ids) VALUES (?, ?, ?, ?, ?)");
                $p_stmt->execute([$id, $i, $books, $books_list, $teacher_ids]);
            }
        }
        echo "<div class='container-fluid mt-3'><div class='alert alert-success'>کلاس اپڈیٹ ہو گئی!</div></div>";
    }
}

$maktab_classes = $pdo->query("SELECT * FROM maktab_classes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$periods_by_class = [];
$periods_raw = $pdo->query("SELECT * FROM class_periods ORDER BY class_id, period_no")->fetchAll(PDO::FETCH_ASSOC);
foreach ($periods_raw as $pr) {
    $periods_by_class[$pr['class_id']][$pr['period_no']] = $pr;
}

$maktab_teachers = $pdo->query("SELECT id, name FROM maktab_teachers WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (status = 'Active' OR status = '' OR status IS NULL) ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$teacher_map = [];
foreach($maktab_teachers as $t) {
    $teacher_map[$t['id']] = $t['name'];
}
?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary">کلاسز مینجمنٹ (Manage Maktab Classes)</h2>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="neobox shadow-sm">
                <h5 class="fw-bold mb-3"><?php echo t('add_new_class'); ?></h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo t('class_name'); ?></label>
                        <input type="text" name="class_name" class="form-control" placeholder="مثلاً: درجہ اولیٰ" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">سیکشن (Section)</label>
                        <input type="text" name="section" class="form-control" placeholder="مثلاً: A, B, Boys وغیرہ">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">کلاس کی قسم (Class Type)</label>
                        <select name="class_type" class="form-select class-type-select" required>
                            <optgroup label="قرآن کریم">
                                <option value="Hifz/Gardan/Husn">حفظ، گردان، حسن صوت</option>
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
                            <label class="form-label fw-bold">اساتذہ (حفظ / ناظرہ)</label>
                            <select name="main_teacher_ids[]" class="form-select" multiple size="3">
                                <?php foreach($maktab_teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-1">ایک سے زیادہ اساتذہ منتخب کرنے کے لیے Ctrl/Cmd دبائے رکھیں۔</small>
                            <small class="text-muted d-block mt-1">حفظ/ناظرہ کلاس کا شیڈول (سبق، سبقی، منزل) خودکار طور پر ہوتا ہے۔</small>
                        </div>
                    </div>

                    <div class="darse-nizami-section">
                        <label class="form-label fw-bold mb-2">پیریڈز کی تفصیل (8 Periods)</label>
                        <?php for($i=1; $i<=8; $i++): ?>
                            <div class="border p-2 mb-2 rounded bg-light">
                                <div class="fw-bold text-secondary mb-1" style="font-size: 0.85rem;">Period <?= $i ?>
                                    <?php if($i == 7) echo '<span class="badge bg-secondary ms-1">مطالعہ</span>'; ?>
                                    <?php if($i == 8) echo '<span class="badge bg-secondary ms-1">تکرار</span>'; ?>
                                </div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-6">
                                        <input type="text" name="period_books[<?= $i ?>]" class="form-control form-control-sm" placeholder="کتاب (مثلاً بخاری)<?= ($i==7)?' / مطالعہ':(($i==8)?' / تکرار':'') ?>">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <select name="period_teacher[<?= $i ?>][]" class="form-select form-select-sm" multiple size="3" style="height: 60px;">
                                            <?php foreach($maktab_teachers as $t): ?>
                                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted" style="font-size: 0.7rem;">Ctrl دبا کر مزید منتخب کریں</small>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label fw-bold">دیگر مضامین اور کل نمبر (اختياری)</label>
                        <textarea name="subjects_list" class="form-control" rows="2" placeholder="مثلاً: قرآن:100, ریاضی:50"></textarea>
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
                                <option value="Hifz/Gardan/Husn" <?= in_array(($c['class_type'] ?? ''), ['Hifz/Gardan/Husn', 'Hifz/Nazira']) ? 'selected' : '' ?>>حفظ، گردان، حسن صوت</option>
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
                        <label class="form-label fw-bold">اساتذہ (حفظ / ناظرہ)</label>
                        <select name="main_teacher_ids[]" class="form-select" multiple size="3">
                            <?php 
                            $selected_mt = json_decode($c['main_teacher_ids'] ?? '[]', true) ?: [];
                            foreach($maktab_teachers as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= in_array($t['id'], $selected_mt) ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1">ایک سے زیادہ اساتذہ منتخب کرنے کے لیے Ctrl/Cmd دبائے رکھیں۔</small>
                        <small class="text-muted d-block mt-1">حفظ/ناظرہ کلاس کا شیڈول (سبق، سبقی، منزل) خودکار طور پر ہوتا ہے۔</small>
                    </div>
                </div>

                <div class="darse-nizami-section">
                    <label class="form-label fw-bold mb-2">پیریڈز کی تفصیل (8 Periods)</label>
                    <div class="row g-2">
                    <?php for($i=1; $i<=8; $i++): 
                        $p_book = $periods_by_class[$c['id']][$i]['books'] ?? '';
                        $p_teacher_ids = json_decode($periods_by_class[$c['id']][$i]['teacher_ids'] ?? '[]', true) ?: [];
                    ?>
                        <div class="col-md-6">
                            <div class="border p-2 rounded bg-light h-100">
                                <div class="fw-bold text-secondary mb-1" style="font-size: 0.85rem;">Period <?= $i ?>
                                    <?php if($i == 7) echo '<span class="badge bg-secondary ms-1">مطالعہ</span>'; ?>
                                    <?php if($i == 8) echo '<span class="badge bg-secondary ms-1">تکرار</span>'; ?>
                                </div>
                                <input type="text" name="period_books[<?= $i ?>]" class="form-control form-control-sm mb-1 fw-bold" placeholder="مضمون کا نام (Subject)<?= ($i==7)?' / مطالعہ':(($i==8)?' / تکرار':'') ?>" value="<?= htmlspecialchars($p_book) ?>">
                                <input type="text" name="period_books_list[<?= $i ?>]" class="form-control form-control-sm mb-2" placeholder="کتابیں (کوما کے ساتھ، مثلاً: علم الصرف، ارشاد الصرف)" value="<?= htmlspecialchars($periods_by_class[$c['id']][$i]['books_list'] ?? '') ?>">
                                <select name="period_teacher[<?= $i ?>][]" class="form-select form-select-sm" multiple size="3" style="height: 60px;">
                                    <?php foreach($maktab_teachers as $t): ?>
                                        <option value="<?= $t['id'] ?>" <?= in_array($t['id'], $p_teacher_ids) ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" style="font-size: 0.7rem;">Ctrl دبا کر مزید منتخب کریں</small>
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
document.addEventListener('DOMContentLoaded', function() {
    function toggleSections(selectElement) {
        var val = selectElement.value;
        var form = selectElement.closest('form');
        var hifzSection = form.querySelector('.hifz-section');
        var darseSection = form.querySelector('.darse-nizami-section');
        if(val === 'Hifz/Gardan/Husn' || val === 'Nazira' || val === 'Hifz/Nazira') {
            if(hifzSection) hifzSection.style.display = 'block';
            if(darseSection) darseSection.style.display = 'block'; // Always show periods so they can assign period-specific maktab_teachers
        } else {
            if(hifzSection) hifzSection.style.display = 'none';
            if(darseSection) darseSection.style.display = 'block';
        }
    }

    var selects = document.querySelectorAll('.class-type-select');
    selects.forEach(function(select) {
        select.addEventListener('change', function() {
            toggleSections(select);
        });
        // initial state
        toggleSections(select);
    });
});
</script>

<?php include 'footer.php'; ?>
