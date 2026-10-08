<?php
require_once 'db.php';

if (!isset($_GET['id'])) {
    die("طالب علم کی آئی ڈی درکار ہے۔");
}

$student_id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$s = $stmt->fetch();

if (!$s) {
    // اگر طلبہ ٹیبل میں نہ ملے تو مکتب طلبہ میں تلاش کریں
    $stmt = $pdo->prepare("SELECT * FROM maktab_students WHERE id = ?");
    $stmt->execute([$student_id]);
    $s = $stmt->fetch();
}

if (!$s) {
    die("طالب علم کا ریکارڈ نہیں ملا۔");
}

$student_name = $s['name'];

$cur_tenant_id = (int)($s['tenant_id'] ?? get_current_tenant_id());
$settings = get_tenant_settings($pdo, $cur_tenant_id);
$app_name = !empty($settings['madrasa_name']) ? $settings['madrasa_name'] : "مدرسہ اشرف المدارس";
$app_address = !empty($settings['address']) ? $settings['address'] : "منسلک جامع مسجد حنفیہ (محلہ راؤ رشید والا/بالمقابل دفتر زراعت ساہیوال روڈ<br> فروکہ ضلع سرگودھا)";
$sig_stamp = (!empty($settings['signature_stamp_path']) && file_exists(__DIR__ . '/' . $settings['signature_stamp_path'])) ? $settings['signature_stamp_path'] : '';

// Current page URL for QR code
$form_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . '/print_form.php?id=' . $student_id;
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=4&data=' . urlencode($form_url);

// لائف ٹائم یونیک سسٹم جنریٹڈ کوڈ (تاریخ مہینہ داخلہ نمبر سال مثلاً: 23 09 90047 26)
$ref_timestamp = !empty($s['admission_date']) ? strtotime($s['admission_date']) : (!empty($s['created_at']) ? strtotime($s['created_at']) : time());
$day_part   = date('d', $ref_timestamp);
$month_part = date('m', $ref_timestamp);
$year_part  = date('y', $ref_timestamp);

$raw_adm = trim((string)($s['admission_number'] ?? ''));
if ($raw_adm !== '') {
    $adm_part = preg_replace('/[^a-zA-Z0-9]/', '', $raw_adm);
} else {
    $adm_part = sprintf('%05d', (int)$s['id']);
}
$lifetime_code = $day_part . ' ' . $month_part . ' ' . $adm_part . ' ' . $year_part;
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>داخلہ فارم - <?php echo htmlspecialchars($student_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f0f0f0;
            font-family: 'Noto Nastaliq Urdu', serif;
            padding: 20px;
        }
        .printable-area {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: auto;
            border: 15px double #333;
            padding: 35px 40px;
            position: relative;
            box-sizing: border-box;
        }

        /* ===== HEADER ===== */
        .header-section {
            margin-bottom: 25px;
            position: relative;
            min-height: 155px;
        }

        /* تصویر باکس (بائیں جانب اوپر) */
        .photo-box {
            width: 125px;
            height: 150px;
            border: 2px solid #284D3D;
            position: absolute;
            top: 0;
            left: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #fff;
            z-index: 10;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* QR کوڈ باکس (دائیں جانب تصویر والی رو کے بالکل برابر ایڈجسٹ) */
        .qr-box {
            position: absolute;
            top: 0;
            right: 0;
            width: 125px;
            height: 150px;
            border: 2px solid #284D3D;
            border-radius: 4px;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10;
            box-sizing: border-box;
            padding: 6px;
        }
        .qr-box img {
            width: 105px;
            height: 105px;
            object-fit: contain;
            display: block;
        }
        .qr-lifetime-code {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            font-weight: 700;
            color: #111;
            letter-spacing: 1px;
            margin-top: 5px;
            text-align: center;
            white-space: nowrap;
            line-height: 1.2;
        }

        /* درمیان میں مدرسہ نام اور پتہ */
        .header-center-info {
            margin: 0 135px;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .header-title {
            font-size: 28px;
            font-weight: bold;
            color: #000;
            margin-bottom: 2px;
        }
        .madrasa-name {
            font-size: 42px;
            color: #284D3D;
            font-weight: bold;
            margin-top: 0;
            margin-bottom: 4px;
            line-height: 1.15;
        }
        .madrasa-address {
            font-size: 13.5px;
            margin-top: 2px;
            font-weight: bold;
            line-height: 1.5;
            color: #333;
        }

        /* ===== FORM ROWS ===== */
        .form-content {
            margin-top: 15px;
        }
        .form-row {
            margin-bottom: 16px;
            border-bottom: 1px dotted #555;
            padding-bottom: 4px;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
        }
        .label {
            font-weight: bold;
            color: #000;
            min-width: 140px;
        }
        .value {
            flex-grow: 1;
            text-align: right;
            padding-right: 10px;
        }
        .box-title {
            background: #284D3D;
            color: white;
            text-align: center;
            padding: 5px;
            margin: 20px 0 8px 0;
            font-size: 19px;
            border-radius: 4px;
            font-weight: bold;
        }
        .declaration-text {
            font-size: 14.5px;
            line-height: 1.9;
            text-align: justify;
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: #fafafa;
            border-radius: 4px;
        }

        /* ===== دستخط سیکشن (دستخط اپلوڈ + ڈریگ اینڈ ڈراپ + سائز) ===== */
        .signature-section {
            margin-top: 35px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
        }
        .sig-box {
            text-align: center;
            width: 31%;
            min-height: 130px;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .sig-drop-zone {
            width: 100%;
            height: 85px;
            border: 2px dashed #94a3b8;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: #f8fafc;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .sig-drop-zone.drag-over {
            border-color: #284D3D;
            background: #e8f5e9;
        }
        .sig-drop-zone .drop-hint {
            font-size: 10px;
            color: #64748b;
            pointer-events: none;
            text-align: center;
            padding: 4px;
            line-height: 1.3;
        }

        /* ڈریگ ایبل دستخط امیج */
        .sig-draggable-wrapper {
            position: absolute;
            cursor: grab;
            user-select: none;
            touch-action: none;
            display: none;
            z-index: 5;
        }
        .sig-draggable-wrapper.dragging {
            cursor: grabbing;
            opacity: 0.85;
        }
        .sig-draggable-wrapper img {
            max-width: 100%;
            height: auto;
            max-height: 75px;
            object-fit: contain;
            pointer-events: none;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.1));
        }

        .sig-controls {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-top: 4px;
            width: 100%;
            background: #f1f5f9;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 10px;
        }
        .sig-controls input[type="range"] {
            flex: 1;
            height: 4px;
            cursor: pointer;
        }
        .btn-sig-clear {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 3px;
            padding: 1px 6px;
            font-size: 9px;
            cursor: pointer;
        }

        .sig-label {
            font-weight: bold;
            font-size: 15px;
            border-top: 1.5px solid #000;
            width: 100%;
            padding-top: 5px;
            margin-top: auto;
            color: #000;
        }

        /* ===== پرنٹ میڈیا ===== */
        @media print {
            body { background: none; padding: 0; }
            .printable-area { border: 15px double #000; margin: 0; width: 100%; box-shadow: none; padding: 25px 30px; }
            .btn-print-bar, .sig-drop-zone, .sig-controls, .drop-hint { display: none !important; }
            .sig-box { min-height: 80px; justify-content: flex-end; }
            .sig-draggable-wrapper {
                position: relative !important;
                display: block !important;
                cursor: default !important;
                margin-bottom: 2px !important;
                top: auto !important;
                left: auto !important;
            }
            .sig-draggable-wrapper img {
                display: block !important;
                margin: 0 auto !important;
            }
            .photo-box { display: flex !important; }
            .qr-box { display: flex !important; }
        }
    </style>
</head>
<body>

    <div class="text-center mb-3 btn-print-bar">
        <button onclick="window.print()" class="btn btn-dark px-5 fw-bold shadow">
            🖨️ پرنٹ کریں (Print / PDF)
        </button>
        <a href="students.php" class="btn btn-outline-secondary px-3 ms-2">واپس جائیں</a>
        <div class="small text-muted mt-1">
            💡 رہنمائی: کسی بھی دستخط باکس پر تصویر اپلوڈ کر کے یا ڈریگ کر کے ماؤس سے اپنی مرضی کے مقام پر ایڈجسٹ کریں اور سائز تبدیل کریں۔
        </div>
    </div>

    <div class="printable-area">
        <!-- ہیڈر -->
        <div class="header-section text-center">

            <!-- تصویر باکس (بائیں جانب) -->
            <div class="photo-box shadow-sm">
                <?php if(!empty($s['photo'])): ?>
                    <img src="<?php echo htmlspecialchars($s['photo']); ?>" alt="Photo">
                <?php else: ?>
                    <span class="text-muted small">تصویر کے لیے جگہ</span>
                <?php endif; ?>
            </div>

            <!-- QR کوڈ باکس (دائیں جانب تصویر والی رو میں برابر) -->
            <div class="qr-box shadow-sm">
                <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Code">
                <div class="qr-lifetime-code" dir="ltr"><?php echo htmlspecialchars($lifetime_code); ?></div>
            </div>

            <!-- مرکزی معلومات -->
            <div class="header-center-info">
                <h1 class="header-title">داخلہ فارم</h1>
                <h2 class="madrasa-name"><?php echo htmlspecialchars($app_name); ?></h2>
                <p class="madrasa-address"><?php echo $app_address; ?></p>
            </div>
        </div>

        <!-- فارم مواد -->
        <div class="form-content">
            <div class="row">
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">داخلہ نمبر:</span>
                        <span class="value"><?php echo htmlspecialchars($s['admission_number']); ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">تاریخِ داخلہ:</span>
                        <span class="value"><?php echo htmlspecialchars($s['admission_date'] ?? ''); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <span class="label">طالب علم کا نام:</span>
                <span class="value fw-bold text-primary"><?php echo htmlspecialchars($s['name']); ?></span>
            </div>
            
            <div class="form-row">
                <span class="label">والد کا نام:</span>
                <span class="value"><?php echo htmlspecialchars($s['father_name']); ?></span>
            </div>

            <div class="row">
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">تاریخِ پیدائش:</span>
                        <span class="value"><?php echo htmlspecialchars($s['dob'] ?? '---'); ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">شعبہ / درجہ:</span>
                        <span class="value"><?php echo htmlspecialchars($s['class'] ?? ''); ?></span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <span class="label">موجودہ پتہ:</span>
                <span class="value"><?php echo htmlspecialchars($s['address'] ?? ''); ?></span>
            </div>

            <div class="form-row">
                <span class="label">مستقل پتہ:</span>
                <span class="value"><?php echo htmlspecialchars($s['permanent_address'] ?? $s['address'] ?? ''); ?></span>
            </div>

            <div class="row">
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">موبائل نمبر:</span>
                        <span class="value" dir="ltr"><?php echo htmlspecialchars($s['contact_number'] ?? ''); ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">ای میل:</span>
                        <span class="value" dir="ltr"><?php echo htmlspecialchars($s['email'] ?? '---'); ?></span>
                    </div>
                </div>
            </div>

            <div class="box-title">عہدنامہ از طالب علم</div>
            <div class="declaration-text">
                میں نے تمام قواعد و ضوابط کا بغور مطالعہ کیا ہے اور میں یہ عہد کرتا ہوں اور اپنے اساتذہ کو یقین دلاتا ہوں کہ ان تمام قواعد و ضوابط اور ان کے علاوہ بھی وقتاً فوقتاً اساتذہ کی طرف سے ملنے والی ہدایات پر عمل کروں گا اور قطعاً شکایت کا موقع نہیں دوں گا۔
            </div>

            <div class="box-title">عہدنامہ از سرپرست / والد</div>
            <div class="declaration-text">
                میں حلفاً بیان کرتا ہوں کہ مندرجہ بالا معلومات 100% درست ہیں۔ میں بحیثیت سرپرست وعدہ کرتا ہوں کہ اپنے عزیز کی تمام تر تعلیمی ذمہ داری پوری کروں گا۔ چھٹیوں کے دوران اور جمعہ کے دن گھر میں تعلیم کی نگرانی کروں گا۔ کسی بھی غیر قانونی یا سیاسی غیر شرعی کام میں ملوث پائے جانے کی صورت میں ادارہ کی تادیبی کارروائی قبول کروں گا۔
            </div>

            <!-- دستخط سیکشن (طالب علم، سرپرست، ناظمِ مدرسہ) -->
            <div class="signature-section">

                <!-- 1. دستخط طالب علم -->
                <div class="sig-box" id="sig-box-std">
                    <div class="sig-drop-zone" id="zone-std" onclick="triggerSigUpload('input-std')" ondragover="sigDragOver(event, 'zone-std')" ondragleave="sigDragLeave(event, 'zone-std')" ondrop="sigDrop(event, 'std')">
                        <span class="drop-hint">✍️ طالب علم کے دستخط<br>کلک یا فائل ڈراپ کریں</span>
                        <input type="file" id="input-std" accept="image/*" style="display:none;" onchange="sigFileChosen(this, 'std')">
                    </div>
                    <div class="sig-draggable-wrapper" id="wrap-std" title="ماؤس سے پکڑ کر مطلوبہ جگہ سیٹ کریں">
                        <img id="img-std" src="" alt="دستخط طالب علم">
                    </div>
                    <div class="sig-controls" id="ctrl-std">
                        <span>سائز:</span>
                        <input type="range" min="30" max="140" value="70" oninput="resizeSigImg('img-std', this.value)">
                        <button type="button" class="btn-sig-clear" onclick="clearSig('std')">✕</button>
                    </div>
                    <div class="sig-label">دستخط طالب علم</div>
                </div>

                <!-- 2. دستخط سرپرست -->
                <div class="sig-box" id="sig-box-guard">
                    <div class="sig-drop-zone" id="zone-guard" onclick="triggerSigUpload('input-guard')" ondragover="sigDragOver(event, 'zone-guard')" ondragleave="sigDragLeave(event, 'zone-guard')" ondrop="sigDrop(event, 'guard')">
                        <span class="drop-hint">✍️ سرپرست کے دستخط<br>کلک یا فائل ڈراپ کریں</span>
                        <input type="file" id="input-guard" accept="image/*" style="display:none;" onchange="sigFileChosen(this, 'guard')">
                    </div>
                    <div class="sig-draggable-wrapper" id="wrap-guard" title="ماؤس سے پکڑ کر مطلوبہ جگہ سیٹ کریں">
                        <img id="img-guard" src="" alt="دستخط سرپرست">
                    </div>
                    <div class="sig-controls" id="ctrl-guard">
                        <span>سائز:</span>
                        <input type="range" min="30" max="140" value="70" oninput="resizeSigImg('img-guard', this.value)">
                        <button type="button" class="btn-sig-clear" onclick="clearSig('guard')">✕</button>
                    </div>
                    <div class="sig-label">دستخط سرپرست</div>
                </div>

                <!-- 3. دستخط ناظمِ مدرسہ -->
                <div class="sig-box" id="sig-box-nazim">
                    <div class="sig-drop-zone" id="zone-nazim" onclick="triggerSigUpload('input-nazim')" ondragover="sigDragOver(event, 'zone-nazim')" ondragleave="sigDragLeave(event, 'zone-nazim')" ondrop="sigDrop(event, 'nazim')">
                        <span class="drop-hint">✍️ ناظم کے دستخط/مہر<br>کلک یا فائل ڈراپ کریں</span>
                        <input type="file" id="input-nazim" accept="image/*" style="display:none;" onchange="sigFileChosen(this, 'nazim')">
                    </div>
                    <div class="sig-draggable-wrapper" id="wrap-nazim" title="ماؤس سے پکڑ کر مطلوبہ جگہ سیٹ کریں">
                        <img id="img-nazim" src="<?php echo htmlspecialchars($sig_stamp); ?>" alt="دستخط ناظم">
                    </div>
                    <div class="sig-controls" id="ctrl-nazim">
                        <span>سائز:</span>
                        <input type="range" min="30" max="140" value="70" oninput="resizeSigImg('img-nazim', this.value)">
                        <button type="button" class="btn-sig-clear" onclick="clearSig('nazim')">✕</button>
                    </div>
                    <div class="sig-label">دستخط ناظمِ مدرسہ</div>
                </div>

            </div><!-- /signature-section -->
        </div><!-- /form-content -->

    </div><!-- /printable-area -->

    <script>
    /* ===== دستخط اپلوڈ + ڈریگ اینڈ ڈراپ ری پوزیشننگ + سائزنگ ===== */

    function triggerSigUpload(inputId) {
        document.getElementById(inputId).click();
    }

    function sigFileChosen(input, key) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                applySigSrc(key, e.target.result);
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function applySigSrc(key, src) {
        const wrap = document.getElementById('wrap-' + key);
        const img  = document.getElementById('img-' + key);
        const ctrl = document.getElementById('ctrl-' + key);
        const zone = document.getElementById('zone-' + key);

        img.src = src;
        wrap.style.display = 'block';
        wrap.style.top = '10px';
        wrap.style.left = '20px';
        ctrl.style.display = 'flex';
        zone.style.opacity = '0.35';
    }

    function resizeSigImg(imgId, val) {
        document.getElementById(imgId).style.maxWidth = val + 'px';
    }

    function clearSig(key) {
        const wrap = document.getElementById('wrap-' + key);
        const img  = document.getElementById('img-' + key);
        const ctrl = document.getElementById('ctrl-' + key);
        const zone = document.getElementById('zone-' + key);

        img.src = '';
        wrap.style.display = 'none';
        ctrl.style.display = 'none';
        zone.style.opacity = '1';
    }

    function sigDragOver(e, zoneId) {
        e.preventDefault();
        document.getElementById(zoneId).classList.add('drag-over');
    }

    function sigDragLeave(e, zoneId) {
        document.getElementById(zoneId).classList.remove('drag-over');
    }

    function sigDrop(e, key) {
        e.preventDefault();
        document.getElementById('zone-' + key).classList.remove('drag-over');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            const file = e.dataTransfer.files[0];
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    applySigSrc(key, evt.target.result);
                };
                reader.readAsDataURL(file);
            }
        }
    }

    // ڈریگنگ فیچر (ماؤس اور ٹچ سپورٹ ہر دستخط کے لیے)
    ['std', 'guard', 'nazim'].forEach(function(key) {
        const wrap = document.getElementById('wrap-' + key);
        const box  = document.getElementById('sig-box-' + key);

        let isDragging = false;
        let startX, startY, initL, initT;

        wrap.addEventListener('mousedown', function(e) {
            startMove(e.clientX, e.clientY);
            e.preventDefault();
        });

        wrap.addEventListener('touchstart', function(e) {
            const t = e.touches[0];
            startMove(t.clientX, t.clientY);
            e.preventDefault();
        }, { passive: false });

        function startMove(clientX, clientY) {
            isDragging = true;
            wrap.classList.add('dragging');
            const boxRect  = box.getBoundingClientRect();
            const wrapRect = wrap.getBoundingClientRect();

            initL = wrapRect.left - boxRect.left;
            initT = wrapRect.top - boxRect.top;
            startX = clientX;
            startY = clientY;

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', endMove);
            document.addEventListener('touchmove', onTouchMove, { passive: false });
            document.addEventListener('touchend', endMove);
        }

        function onMove(e) {
            if (!isDragging) return;
            doMove(e.clientX, e.clientY);
        }

        function onTouchMove(e) {
            if (!isDragging) return;
            const t = e.touches[0];
            doMove(t.clientX, t.clientY);
            e.preventDefault();
        }

        function doMove(clientX, clientY) {
            const dx = clientX - startX;
            const dy = clientY - startY;

            let nL = initL + dx;
            let nT = initT + dy;

            wrap.style.left = nL + 'px';
            wrap.style.top  = nT + 'px';
        }

        function endMove() {
            isDragging = false;
            wrap.classList.remove('dragging');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', endMove);
            document.removeEventListener('touchmove', onTouchMove);
            document.removeEventListener('touchend', endMove);
        }
    });

    // اگر پہلے سے ناظم کی دستخط/مہر موجود ہو تو شو کریں
    <?php if (!empty($sig_stamp)): ?>
    applySigSrc('nazim', '<?php echo htmlspecialchars($sig_stamp); ?>');
    <?php endif; ?>
    </script>

</body>
</html>
