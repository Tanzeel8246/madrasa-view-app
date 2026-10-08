<?php 
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
require_once 'db.php'; 
$selected_class = $_GET['class'] ?? '';
if (empty($selected_class)) {
    header('Location: academic_entry_dashboard.php');
    exit;
}

$allowed_classes = get_allowed_classes($pdo);
$allowed_class_names = array_column($allowed_classes, 'name');
if (!in_array($selected_class, $allowed_class_names) && $_SESSION['role'] !== 'admin') {
    die("<div style='text-align:center;margin-top:50px;font-family:sans-serif;'><h2>Access Denied</h2><p>You don't have permission to access this class.</p></div>");
}

include 'header.php'; 

// --- 1. ڈیٹا محفوظ کرنے کا عمل ---
if (isset($_POST['save_hifz'])) {
    try {
        $date = $_POST['date'];
        $std_id = $_POST['std_id'];
        
        // Sabaq
        $s_parah = $_POST['sabaq_p'] ?? '';
        $s_lines = $_POST['sabaq_l'] ?? '';
        $s_status = $_POST['sabaq_s'] ?? '';
        $s_time = $_POST['sabaq_time'] ?? '';
        
        // Sabqi
        $sq_parah = $_POST['sabqi_p'] ?? '';
        $sq_amt = $_POST['sabqi_amt'] ?? '';
        $sq_status = $_POST['sabqi_s'] ?? '';
        $sq_list = $_POST['sabqi_listener'] ?? ''; 
        $sq_time = $_POST['sabqi_time'] ?? '';
        if (isset($_POST['sabqi_listener_jaiza'])) $sq_list .= " (جائزہ)";

        if ($sq_amt === 'جائزہ' && isset($_POST['sabqi_review_parah']) && is_array($_POST['sabqi_review_parah'])) {
            $review_details = [];
            $review_parahs = $_POST['sabqi_review_parah'];
            $review_types = $_POST['sabqi_review_type'] ?? [];
            for($i=0; $i<count($review_parahs); $i++) {
                if(!empty($review_parahs[$i])) {
                    $type_text = $review_types[$i] ?? 'مکمل';
                    $review_details[] = $review_parahs[$i] . " (" . $type_text . ")";
                }
            }
            if (!empty($review_details)) {
                $sq_parah = implode(", ", $review_details);
                $sq_amt = "صرف جائزہ (" . count($review_details) . " پارے)";
            }
        }
        
        // Manzil
        $m_total_amt = $_POST['manzil_total_amt'] ?? ''; 
        $m_status = $_POST['manzil_s'] ?? '';
        $m_list = $_POST['manzil_listener'] ?? ''; 
        $m_time = $_POST['manzil_time'] ?? '';
        if (isset($_POST['manzil_listener_jaiza'])) $m_list .= " (جائزہ)";

        $m_details_arr = [];
        if(isset($_POST['m_parah_num']) && is_array($_POST['m_parah_num'])) {
            $p_nums = $_POST['m_parah_num'];
            $p_types = $_POST['m_parah_type']; 
            for($i=0; $i<count($p_nums); $i++) {
                if(!empty($p_nums[$i])) {
                    $type_text = $p_types[$i] ?? 'مکمل';
                    $m_details_arr[] = $p_nums[$i] . " (" . $type_text . ")";
                }
            }
        }
        $m_parah_final = implode(", ", $m_details_arr);
        if ($m_total_amt === 'جائزہ' && !empty($m_details_arr)) {
            $m_total_amt = "صرف جائزہ (" . count($m_details_arr) . " پارے)";
        }

        if ($sq_amt === 'جائزہ') {
            throw new Exception(t('sabaq') . 'ی جائزہ کے لیے کم از کم ایک پارہ شامل کریں۔');
        }
        if ($m_total_amt === 'جائزہ') {
            throw new Exception(t('manzil') . ' جائزہ کے لیے کم از کم ایک پارہ شامل کریں۔');
        }

        $exist = $pdo->prepare("SELECT * FROM daily_hifz WHERE student_id = ? AND date = ?");
        $exist->execute([$std_id, $date]);
        $row = $exist->fetch();

        if ($row) {
            $keepOld = function($newValue, $oldValue) {
                return trim((string)$newValue) !== '' ? $newValue : $oldValue;
            };
            $s_parah = $keepOld($s_parah, $row['sabaq_parah'] ?? '');
            $s_lines = $keepOld($s_lines, $row['sabaq_lines'] ?? '');
            $s_status = $keepOld($s_status, $row['sabaq_status'] ?? '');
            $sq_parah = $keepOld($sq_parah, $row['sabqi_parah'] ?? '');
            $sq_amt = $keepOld($sq_amt, $row['sabqi_amount'] ?? '');
            $sq_status = $keepOld($sq_status, $row['sabqi_status'] ?? '');
            $sq_list = $keepOld($sq_list, $row['sabqi_listener'] ?? '');
            $m_parah_final = $keepOld($m_parah_final, $row['manzil_parah'] ?? '');
            $m_total_amt = $keepOld($m_total_amt, $row['manzil_amount'] ?? '');
            $m_status = $keepOld($m_status, $row['manzil_status'] ?? '');
            $s_time = $keepOld($s_time, $row['sabaq_time'] ?? '');
            $sq_time = $keepOld($sq_time, $row['sabqi_time'] ?? '');
            $m_time = $keepOld($m_time, $row['manzil_time'] ?? '');
            $sql = "UPDATE daily_hifz SET sabaq_parah=?, sabaq_lines=?, sabaq_status=?, sabqi_parah=?, sabqi_amount=?, sabqi_status=?, sabqi_listener=?, manzil_parah=?, manzil_amount=?, manzil_status=?, manzil_listener=?, sabaq_time=?, sabqi_time=?, manzil_time=? WHERE id=?";
            $pdo->prepare($sql)->execute([$s_parah, $s_lines, $s_status, $sq_parah, $sq_amt, $sq_status, $sq_list, $m_parah_final, $m_total_amt, $m_status, $m_list, $s_time, $sq_time, $m_time, $row['id']]);
        } else {
            $sql = "INSERT INTO daily_hifz (student_id, date, sabaq_parah, sabaq_lines, sabaq_status, sabqi_parah, sabqi_amount, sabqi_status, sabqi_listener, manzil_parah, manzil_amount, manzil_status, manzil_listener, sabaq_time, sabqi_time, manzil_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$std_id, $date, $s_parah, $s_lines, $s_status, $sq_parah, $sq_amt, $sq_status, $sq_list, $m_parah_final, $m_total_amt, $m_status, $m_list, $s_time, $sq_time, $m_time]);
        }
        
        echo "<div class='alert alert-success text-center fw-bold shadow mt-2' id='alert_msg'>✅ حفظ کا ریکارڈ محفوظ / اپڈیٹ ہو گیا!</div>";
        echo "<script>setTimeout(()=>{ document.getElementById('alert_msg').style.display='none'; }, 2000);</script>";

    } catch (Exception $e) { echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>"; }
}


// طلباء اور اساتذہ کا ڈیٹا لانا (صرف حفظ کے طلباء)
$stmt = $pdo->prepare("
    SELECT s.* FROM students s
    LEFT JOIN classes c ON s.class = c.name
    WHERE (LOWER(s.status) = 'active' OR s.status = 'حاضر' OR s.status = 'Present') 
      AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
      AND s.class = ?
    ORDER BY s.admission_number ASC
");
$stmt->execute([$selected_class]);
$students_data = $stmt->fetchAll();
$teachers = $pdo->query("SELECT name FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL)")->fetchAll(PDO::FETCH_COLUMN); 
if(empty($teachers)) $teachers = ['قاری صاحب'];

$manzil_total_opts = [
    'ایک پاؤ' => 'ایک پاؤ', 'آدھا پارہ' => 'آدھا پارہ', 'پونا پارہ' => 'پونا پارہ',
    '1 پارہ' => '1 پارہ', '1.25 پارے' => '1.25 پارے', '1.5 پارے' => '1.5 پارے',
    '2 پارے' => '2 پارے', '3 پارے' => '3 پارے', 'جائزہ' => '✅ صرف جائزہ'
];
$sabqi_opts = ['پاؤ'=>'پاؤ', 'نصف اول'=>'نصف اول', 'نصف آخر'=>'نصف آخر', 'آدھا'=>'آدھا', 'پونا'=>'پونا', 'مکمل'=>'مکمل', 'جائزہ'=>'✅ صرف جائزہ'];

function parahDropdown($name, $id) {
    $h = "<select name='$name' id='$id' class='form-select fw-bold'><option value=''>--</option>";
    $h .= "<option value='نورانی قاعدہ'>نورانی قاعدہ</option><option value='نماز حنفی'>نماز حنفی</option>";
    for($i=1; $i<=30; $i++) $h .= "<option value='$i'>$i</option>";
    $h .= "</select>"; return $h;
}
?>

<div class="container py-3 text-end" dir="rtl">
    <a href="academic_entry_dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold float-start mt-2"><i class="fas fa-arrow-right me-2"></i> واپس ڈیش بورڈ</a>
    <h2 class="fw-bold text-success mb-4 border-bottom pb-2"><i class="fas fa-quran me-2"></i> حفظ کا روزانہ اندراج</h2>
    
    <div class="sticky-top pt-2" style="z-index: 100;">
        <div class="card mb-3 bg-dark text-white shadow-lg border-0" style="background: linear-gradient(135deg, #134E5E 0%, #71B280 100%);">
            <div class="card-body text-center py-2">
                <div class="d-flex justify-content-center align-items-center gap-3">
                    <button type="button" id="mic_btn" class="btn btn-warning fw-bold rounded-pill px-4 shadow" onclick="toggleVoice()">
                        <i class="fas fa-microphone me-2"></i> <span>بولیں (Start)</span>
                    </button>
                    <div id="status_text" class="text-white small text-start border-start ps-3" style="min-width: 250px;">
                        <span class="opacity-75">مائیک بند ہے۔</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="hifz_form" class="card p-4 shadow-sm border-0 border-top border-5 border-success">
        
        <div class="row mb-4 bg-light p-3 rounded mx-0 border">
            <div class="col-md-6">
                <label class="fw-bold"><?php echo t('select_student'); ?>:</label>
                <select name="std_id" id="std_id" class="form-select fw-bold select2" required>
                    <option value="">-- تلاش کریں --</option>
                    <?php foreach($students_data as $s) {
                        $label = trim(($s['name'] ?? '') . ' - ' . ($s['father_name'] ?? '') . ' | داخلہ: ' . ($s['admission_number'] ?? '') . ' | کلاس: ' . ($s['class'] ?? ''));
                        echo "<option value='{$s['id']}'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</option>";
                    } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="fw-bold">تاریخ:</label>
                <input type="date" name="date" class="form-control fw-bold" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>

        <div id="hifz_section">
            <div class="row g-0 border rounded overflow-hidden mb-3">
                <!-- <?php echo t('sabaq'); ?> -->
                <div class="col-md-4 p-3 border-start section-box" id="box_sabaq">
                    <h5 class="text-white text-center bg-success p-2 rounded mb-3 shadow-sm"><?php echo t('sabaq'); ?></h5>
                    <div class="mb-3"><label class="small fw-bold">پارہ نمبر</label><?php echo parahDropdown('sabaq_p', 'sabaq_p'); ?></div>
                    <div class="mb-3"><label class="small fw-bold">لائنیں</label><input type="number" name="sabaq_l" id="sabaq_l" class="form-control fw-bold text-center"></div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="small fw-bold"><?php echo t('status_ur'); ?></label>
                            <select name="sabaq_s" id="sabaq_s" class="form-select status-select" onchange="colorStatus(this)">
                                <option value="">--</option>
                                <option value="بہترین">بہترین</option>
                                <option value="بہتر">بہتر</option>
                                <option value="کمزور">کمزور</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">ٹائم</label>
                            <input type="time" name="sabaq_time" id="sabaq_time" class="form-control text-center" onchange="updateSabaqTime()">
                        </div>
                    </div>
                </div>

                <!-- <?php echo t('sabaq'); ?>ی -->
                <div class="col-md-4 p-3 border-start section-box" id="box_sabqi">
                    <h5 class="text-white text-center bg-primary p-2 rounded mb-3 shadow-sm"><?php echo t('sabaq'); ?>ی</h5>
                    <div class="mb-3"><label class="small fw-bold">پارہ نمبر</label><?php echo parahDropdown('sabqi_p', 'sabqi_p'); ?></div>
                    <div class="mb-3"><label class="small fw-bold">مقدار</label>
                        <select name="sabqi_amt" id="sabqi_amt" class="form-select fw-bold text-primary" onchange="checkSabqiJaiza()">
                            <option value="">--</option>
                            <?php foreach($sabqi_opts as $k=>$v) echo "<option value='$k'>$v</option>"; ?>
                        </select>
                    </div>
                    <div id="sabqi_review_rows" class="mb-3" style="display:none;">
                        <label class="small fw-bold text-primary">جائزہ کی تفصیل (کون سے پارے):</label>
                        <div id="sabqi_review_rows_container"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary w-100 fw-bold mt-1" onclick="addSabqiReviewRow();">
                            <i class="fas fa-plus"></i> جائزہ پارہ شامل کریں
                        </button>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="small fw-bold"><?php echo t('status_ur'); ?></label>
                            <select name="sabqi_s" id="sabqi_s" class="form-select status-select" onchange="colorStatus(this)">
                                <option value="">--</option>
                                <option value="بہترین">بہترین</option>
                                <option value="بہتر">بہتر</option>
                                <option value="کمزور">کمزور</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">ٹائم</label>
                            <input type="time" name="sabqi_time" id="sabqi_time" class="form-control text-center" onchange="updateSabqiTime()">
                        </div>
                    </div>
                    <div class="mb-3" id="sabqi_listener_div">
                        <label class="small fw-bold text-muted">سننے والا</label>
                        <div class="input-group input-group-sm">
                            <input list="teachers_list" name="sabqi_listener" id="sabqi_listener" class="form-control">
                            <div class="input-group-text bg-white border-start-0">
                                <input class="form-check-input mt-0 me-1" type="checkbox" name="sabqi_listener_jaiza" id="sabqi_listener_jaiza">
                                <label for="sabqi_listener_jaiza" class="small mb-0 ms-1 fw-bold text-primary">جائزہ</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- <?php echo t('manzil'); ?> -->
                <div class="col-md-4 p-3 bg-light section-box" id="box_manzil">
                    <h5 class="text-dark text-center bg-warning p-2 rounded mb-3 shadow-sm"><?php echo t('manzil'); ?></h5>
                    
                    <div class="mb-3">
                        <label class="small fw-bold text-danger">کل مقدار / جائزہ</label>
                        <select name="manzil_total_amt" id="manzil_total_amt" class="form-select fw-bold border-danger text-danger" onchange="autoGenerateRows(); updateRealTimeCalc(); checkManzilJaiza();">
                            <option value="">-- منتخب کریں --</option>
                            <?php foreach($manzil_total_opts as $k=>$v) echo "<option value='$k'>$v</option>"; ?>
                        </select>
                    </div>

                    <div id="calc_display" class="alert alert-secondary py-1 px-2 mb-2 text-center" style="display:none; font-size: 13px;">
                        <span id="calc_status" class="fw-bold"></span>
                    </div>

                    <div class="mb-2">
                        <label class="small fw-bold">تفصیل (پارہ وار):</label>
                        <div id="manzil_rows_container" onchange="updateRealTimeCalc()"></div>
                        <button type="button" class="btn btn-sm btn-outline-success w-100 fw-bold mt-1" onclick="addManzilRow(); updateRealTimeCalc();">
                            <i class="fas fa-plus"></i> مزید لائن شامل کریں
                        </button>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="small fw-bold"><?php echo t('status_ur'); ?></label>
                            <select name="manzil_s" id="manzil_s" class="form-select status-select" onchange="colorStatus(this)">
                                <option value="">--</option>
                                <option value="بہترین">بہترین</option>
                                <option value="بہتر">بہتر</option>
                                <option value="کمزور">کمزور</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">ٹائم</label>
                            <input type="time" name="manzil_time" id="manzil_time" class="form-control text-center" onchange="updateManzilTime()">
                        </div>
                    </div>
                    <div class="mb-3" id="manzil_listener_div">
                        <label class="small fw-bold text-muted">سننے والا</label>
                        <div class="input-group input-group-sm">
                            <input list="teachers_list" name="manzil_listener" id="manzil_listener" class="form-control">
                            <div class="input-group-text bg-white border-start-0">
                                <input class="form-check-input mt-0 me-1" type="checkbox" name="manzil_listener_jaiza" id="manzil_listener_jaiza">
                                <label for="manzil_listener_jaiza" class="small mb-0 ms-1 fw-bold text-warning text-darken">جائزہ</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <button type="submit" name="save_hifz" id="save_hifz_btn" class="btn btn-success w-100 py-3 fw-bold shadow fs-5">محفوظ / اپڈیٹ کریں</button>
                </div>
            </div>
        </div>
    </form>
</div>

<datalist id="teachers_list"><?php foreach($teachers as $t) echo "<option value='$t'>"; ?></datalist>

<script>
// مقدار کا نمبر ڈیٹا
const qtyMap = {
    'ایک پاؤ': 0.25, 'آدھا پارہ': 0.5, 'پونا پارہ': 0.75,
    '1 پارہ': 1.0, '1.25 پارے': 1.25, '1.5 پارے': 1.5,
    '2 پارے': 2.0, '3 پارے': 3.0, 'جائزہ': 0
};

const rowValueMap = { 'مکمل': 1.0, 'نصف اول': 0.5, 'نصف آخر': 0.5, 'پاؤ': 0.25 };

function colorStatus(sel) {
    if(sel.value == 'بہترین') sel.style.backgroundColor = '#d1e7dd';
    else if(sel.value == 'کمزور') sel.style.backgroundColor = '#f8d7da';
    else if(sel.value == '') sel.style.backgroundColor = '#fff';
    else sel.style.backgroundColor = '#fff3cd';

    // Auto set time if not already set
    let timeFieldId = '';
    if(sel.id === 'sabaq_s') timeFieldId = 'sabaq_time';
    else if(sel.id === 'sabqi_s') timeFieldId = 'sabqi_time';
    else if(sel.id === 'manzil_s') timeFieldId = 'manzil_time';

    if(timeFieldId && sel.value !== '') {
        let timeField = document.getElementById(timeFieldId);
        if(timeField && !timeField.value) {
            let now = new Date();
            let hours = String(now.getHours()).padStart(2, '0');
            let minutes = String(now.getMinutes()).padStart(2, '0');
            timeField.value = hours + ':' + minutes;
        }
    }
}

function checkSabqiJaiza() {
    let amt = document.getElementById('sabqi_amt').value;
    let listenerDiv = document.getElementById('sabqi_listener_div');
    let reviewRows = document.getElementById('sabqi_review_rows');
    if (amt === 'جائزہ') {
        reviewRows.style.display = 'block';
        if (document.getElementById('sabqi_review_rows_container').children.length === 0) {
            addSabqiReviewRow();
        }
    } else {
        reviewRows.style.display = 'none';
    }
}

function checkManzilJaiza() {
    let amt = document.getElementById('manzil_total_amt').value;
    let listenerDiv = document.getElementById('manzil_listener_div');
    if (amt === 'جائزہ') {
        listenerDiv.style.display = 'none';
        document.getElementById('manzil_listener').value = '';
    } else {
        listenerDiv.style.display = 'block';
    }
}

function parahOptions(selected='') {
    return `<option value="">--</option>
        <option value="نورانی قاعدہ" ${selected=='نورانی قاعدہ'?'selected':''}>نورانی قاعدہ</option><option value="نماز حنفی" ${selected=='نماز حنفی'?'selected':''}>نماز حنفی</option>
        ${Array.from({length:30}, (_,i)=>`<option value="${i+1}" ${selected==i+1?'selected':''}>${i+1}</option>`).join('')}`;
}

function addSabqiReviewRow(parah='', type='مکمل') {
    const container = document.getElementById('sabqi_review_rows_container');
    const div = document.createElement('div');
    div.className = 'd-flex gap-1 mb-1';
    div.innerHTML = `
        <select name="sabqi_review_parah[]" class="form-select form-select-sm fw-bold">
            ${parahOptions(parah)}
        </select>
        <select name="sabqi_review_type[]" class="form-select form-select-sm" style="width:110px;">
            <option value="مکمل" ${type=='مکمل'?'selected':''}>مکمل</option>
            <option value="نصف اول" ${type=='نصف اول'?'selected':''}>نصف اول</option>
            <option value="نصف آخر" ${type=='نصف آخر'?'selected':''}>نصف آخر</option>
            <option value="پاؤ" ${type=='پاؤ'?'selected':''}>پاؤ</option>
        </select>
        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove();">×</button>
    `;
    container.appendChild(div);
}

function addManzilRow(parah='', type='مکمل') {
    const container = document.getElementById('manzil_rows_container');
    const div = document.createElement('div');
    div.className = 'd-flex gap-1 mb-1';
    div.innerHTML = `
        <select name="m_parah_num[]" class="form-select form-select-sm fw-bold">
            ${parahOptions(parah)}
        </select>
        <select name="m_parah_type[]" class="form-select form-select-sm" style="width:100px;">
            <option value="مکمل" ${type=='مکمل'?'selected':''}>مکمل</option>
            <option value="نصف اول" ${type=='نصف اول'?'selected':''}>نصف اول</option>
            <option value="نصف آخر" ${type=='نصف آخر'?'selected':''}>نصف آخر</option>
            <option value="پاؤ" ${type=='پاؤ'?'selected':''}>پاؤ</option>
        </select>
        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove(); updateRealTimeCalc();">×</button>
    `;
    container.appendChild(div);
}

function autoGenerateRows() {
    const totalQty = document.getElementById('manzil_total_amt').value;
    const container = document.getElementById('manzil_rows_container');
    container.innerHTML = '';
    if(totalQty == 'ایک پاؤ') addManzilRow('', 'پاؤ');
    else if(totalQty == 'آدھا پارہ') addManzilRow('', 'نصف اول');
    else if(totalQty == 'پونا پارہ') { addManzilRow('', 'نصف اول'); addManzilRow('', 'پاؤ'); }
    else if(totalQty == '1 پارہ') addManzilRow('', 'مکمل');
    else if(totalQty == '1.5 پارے') { addManzilRow('', 'مکمل'); addManzilRow('', 'نصف اول'); }
    else if(totalQty == '2 پارے') { addManzilRow('', 'مکمل'); addManzilRow('', 'مکمل'); }
    else if(totalQty == '3 پارے') { addManzilRow('', 'مکمل'); addManzilRow('', 'مکمل'); addManzilRow('', 'مکمل'); }
}

function updateRealTimeCalc() {
    const targetVal = qtyMap[document.getElementById('manzil_total_amt').value] || 0;
    if(targetVal == 0) { document.getElementById('calc_display').style.display='none'; return; }
    
    let currentTotal = 0;
    const types = document.getElementsByName('m_parah_type[]');
    for(let t of types) currentTotal += rowValueMap[t.value] || 0;
    
    const display = document.getElementById('calc_display');
    const status = document.getElementById('calc_status');
    display.style.display = 'block';
    
    if(currentTotal === targetVal) {
        display.className = 'alert alert-success py-1 px-2 mb-2 text-center';
        status.innerText = "مقدار مکمل ہے! ✅";
    } else if(currentTotal < targetVal) {
        display.className = 'alert alert-warning py-1 px-2 mb-2 text-center';
        status.innerText = "مقدار کم ہے (باقی: " + (targetVal - currentTotal).toFixed(2) + ")";
    } else {
        display.className = 'alert alert-danger py-1 px-2 mb-2 text-center';
        status.innerText = "مقدار زیادہ ہے! ⚠️";
    }
}

// --- Voice Recognition ---
let recognition;
if ('webkitSpeechRecognition' in window) {
    recognition = new webkitSpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.lang = 'ur-PK';

    recognition.onresult = function(event) {
        let final_transcript = '';
        for (let i = event.resultIndex; i < event.results.length; ++i) {
            if (event.results[i].isConfirmed) final_transcript += event.results[i][0].transcript;
        }
        if(final_transcript) processVoiceCommand(final_transcript);
    };
}

function toggleVoice() {
    const btn = document.getElementById('mic_btn');
    const status = document.getElementById('status_text');
    if(btn.classList.contains('btn-warning')) {
        recognition.start();
        btn.classList.replace('btn-warning', 'btn-danger');
        btn.innerHTML = '<i class="fas fa-stop me-2"></i> <span>بند کریں (Stop)</span>';
        status.innerHTML = '<span class="text-danger fw-bold pulse">سن رہا ہوں...</span>';
    } else {
        recognition.stop();
        btn.classList.replace('btn-danger', 'btn-warning');
        btn.innerHTML = '<i class="fas fa-microphone me-2"></i> <span>بولیں (Start)</span>';
        status.innerHTML = '<span class="opacity-75">مائیک بند ہے۔</span>';
    }
}

function processVoiceCommand(text) {
    text = text.toLowerCase();
    document.getElementById('status_text').innerText = "آپ نے کہا: " + text;
    
    // Commands implementation...
    if(text.includes('بہترین') || text.includes('بہتر') || text.includes('کمزور')) {
        let val = text.includes('بہترین') ? 'بہترین' : (text.includes('کمزور') ? 'کمزور' : 'بہتر');
        if(text.includes('<?php echo t('sabaq'); ?>')) { document.getElementById('sabaq_s').value = val; colorStatus(document.getElementById('sabaq_s')); }
        if(text.includes('<?php echo t('sabaq'); ?>ی')) { document.getElementById('sabqi_s').value = val; colorStatus(document.getElementById('sabqi_s')); }
        if(text.includes('<?php echo t('manzil'); ?>')) { document.getElementById('manzil_s').value = val; colorStatus(document.getElementById('manzil_s')); }
    }
}

// Auto-load record
document.addEventListener('DOMContentLoaded', () => {
    $('#std_id').on('change', fetchHifzRecord);
    document.querySelector('[name="date"]').addEventListener('change', fetchHifzRecord);
});

function fetchHifzRecord() {
    let std_id = document.getElementById('std_id').value;
    let date = document.querySelector('[name="date"]').value;
    if(!std_id || !date) return;
    
    fetch(`get_student_record.php?type=hifz&std_id=${std_id}&date=${date}`)
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') {
            let data = res.data;
            document.getElementById('sabaq_p').value = data.sabaq_parah || '';
            document.getElementById('sabaq_l').value = data.sabaq_lines || '';
            document.getElementById('sabaq_s').value = data.sabaq_status || '';
            document.getElementById('sabaq_time').value = data.sabaq_time || '';
            document.getElementById('sabqi_p').value = data.sabqi_parah || '';
            document.getElementById('sabqi_amt').value = data.sabqi_amount || '';
            document.getElementById('sabqi_s').value = data.sabqi_status || '';
            document.getElementById('sabqi_time').value = data.sabqi_time || '';
            
            let sqL = data.sabqi_listener || '';
            document.getElementById('sabqi_listener').value = sqL.replace(' (جائزہ)','').trim();
            document.getElementById('sabqi_listener_jaiza').checked = sqL.includes('(جائزہ)');
            
            document.getElementById('manzil_total_amt').value = data.manzil_amount || '';
            document.getElementById('manzil_s').value = data.manzil_status || '';
            document.getElementById('manzil_time').value = data.manzil_time || '';
            
            let mL = data.manzil_listener || '';
            document.getElementById('manzil_listener').value = mL.replace(' (جائزہ)','').trim();
            document.getElementById('manzil_listener_jaiza').checked = mL.includes('(جائزہ)');
            
            ['sabaq_s', 'sabqi_s', 'manzil_s'].forEach(id => colorStatus(document.getElementById(id)));
            checkSabqiJaiza();
            checkManzilJaiza();
            
            let mDetails = data.manzil_parah || '';
            document.getElementById('manzil_rows_container').innerHTML = '';
            if(mDetails) {
                mDetails.split(',').forEach(row => {
                    let parts = row.split('(');
                    if(parts.length > 1) {
                        let parah = parts[0].replace('پارہ','').trim();
                        let type = parts[1].replace(')','').trim();
                        addManzilRow(parah, type);
                    }
                });
                updateRealTimeCalc();
            } else {
                autoGenerateRows();
            }
        } else {
            // Reset fields
            document.getElementById('sabaq_p').value = '';
            document.getElementById('sabaq_l').value = '';
            document.getElementById('sabaq_s').value = '';
            document.getElementById('sabaq_time').value = '';
            document.getElementById('sabqi_p').value = '';
            document.getElementById('sabqi_amt').value = '';
            document.getElementById('sabqi_s').value = '';
            document.getElementById('sabqi_time').value = '';
            document.getElementById('sabqi_listener').value = '';
            document.getElementById('sabqi_listener_jaiza').checked = false;
            document.getElementById('manzil_total_amt').value = '';
            document.getElementById('manzil_s').value = '';
            document.getElementById('manzil_time').value = '';
            document.getElementById('manzil_listener').value = '';
            document.getElementById('manzil_listener_jaiza').checked = false;
            ['sabaq_s', 'sabqi_s', 'manzil_s'].forEach(id => colorStatus(document.getElementById(id)));
            document.getElementById('manzil_rows_container').innerHTML = '';
            document.getElementById('sabqi_review_rows_container').innerHTML = '';
            document.getElementById('calc_display').style.display = 'none';
        }
    });
}
</script>

<style>
.section-box { transition: all 0.3s ease; }
.section-box:hover { background: rgba(0,0,0,0.02); }
.status-select { font-weight: bold; cursor: pointer; }
.pulse { animation: pulse-red 1.5s infinite; }
@keyframes pulse-red { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
</style>

<?php include 'footer.php'; ?>
