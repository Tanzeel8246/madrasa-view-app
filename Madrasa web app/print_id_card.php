<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    die("Student ID required");
}

$role = strtolower((string)($_SESSION['role'] ?? 'parent'));
$logged_uid = (int)($_SESSION['user_id'] ?? 0);
$is_staff = in_array($role, ['admin', 'superadmin', 'nazim', 'teacher', 'mudarris'], true) || has_permission($role, $_SESSION['permissions'] ?? [], 'manage_students');
if (!$is_staff) {
    if ($role !== 'student' || $id !== $logged_uid) {
        die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>آپ کو شناختی کارڈ دیکھنے کی اجازت نہیں ہے۔</h2><a href='dashboard.php'>واپس جائیں</a></div>");
    }
}

$cur_tenant_id = (int)get_current_tenant_id();
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, $cur_tenant_id]);
$s = $stmt->fetch();

if (!$s) {
    // چیک کریں اگر مکتب ٹیبل میں ہو
    $stmt = $pdo->prepare("SELECT * FROM maktab_students WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $cur_tenant_id]);
    $s = $stmt->fetch();
}

if (!$s) {
    die("Student not found");
}

$settings = get_tenant_settings($pdo, $cur_tenant_id);
$app_name = !empty($settings['madrasa_name']) ? $settings['madrasa_name'] : (!empty($_SESSION['tenant_name']) ? $_SESSION['tenant_name'] : "مدرسہ اشرف المدارس");
$logo_img = (!empty($settings['logo_path']) && file_exists(__DIR__ . '/' . $settings['logo_path'])) ? $settings['logo_path'] : 'logo.png';
$id_card_bg = (!empty($settings['id_card_bg']) && file_exists(__DIR__ . '/' . $settings['id_card_bg'])) ? $settings['id_card_bg'] : null;
$tagline = !empty($settings['tagline']) ? $settings['tagline'] : "تعلیم و تربیت کا گہوارہ";
$sig_stamp = (!empty($settings['signature_stamp_path']) && file_exists(__DIR__ . '/' . $settings['signature_stamp_path'])) ? $settings['signature_stamp_path'] : '';

$issue_date = date('d-m-Y');
$expiry_date = date('d-m-Y', strtotime('+1 year'));
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سٹوڈنٹ آئی ڈی کارڈ - <?php echo htmlspecialchars($s['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap');
        
        body {
            font-family: 'Noto Nastaliq Urdu', serif;
            background-color: #eef2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            flex-direction: column;
            padding: 20px 0;
            box-sizing: border-box;
        }

        /* ===== TOOLBAR (پرنٹ، دستخط اپلوڈ، سائز) ===== */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: white;
            padding: 10px 18px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            font-family: 'Noto Nastaliq Urdu', serif;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-print {
            background: #284D3D;
            color: white;
        }
        .btn-print:hover { background: #1c362b; }

        .btn-upload {
            background: #0284c7;
            color: white;
        }
        .btn-upload:hover { background: #0369a1; }

        .btn-reset {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-reset:hover { background: #e2e8f0; }

        .btn-remove {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .btn-remove:hover { background: #fecaca; }

        .slider-group {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #334155;
            font-weight: bold;
            background: #f8fafc;
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .slider-group input[type="range"] {
            width: 90px;
            cursor: pointer;
        }

        .hint-badge {
            width: 100%;
            text-align: center;
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

        /* ===== ID CARD CONTAINER ===== */
        .id-card-wrapper {
            position: relative;
        }

        .id-card-container {
            width: 3.375in;
            height: 2.125in;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            position: relative;
            overflow: hidden;
            border: 1.5px solid #284D3D;
            box-sizing: border-box;
            <?php if ($id_card_bg): ?>
            background-image: url('<?= htmlspecialchars($id_card_bg) ?>');
            background-size: 100% 100%;
            background-repeat: no-repeat;
            <?php else: ?>
            background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
            <?php endif; ?>
        }

        /* ===== HEADER ===== */
        .header {
            background-color: rgba(40, 77, 61, 0.98);
            color: white;
            padding: 2px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 48px;
            box-sizing: border-box;
        }

        .header img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: white;
            object-fit: contain;
            flex-shrink: 0;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .header-center {
            flex: 1;
            text-align: center;
            padding: 0 4px;
            overflow: hidden;
        }

        .header h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .header .sub-title {
            font-size: 8.5px;
            color: #D4AC0D;
            font-weight: bold;
            margin: 0;
            line-height: 1.05;
        }

        /* ===== CONTENT ===== */
        .content {
            display: flex;
            padding: 3px 8px 2px 8px;
            height: calc(100% - 74px); /* 48px header + 26px footer = 74px */
            box-sizing: border-box;
            position: relative;
        }

        /* ===== PHOTO AREA ===== */
        .photo-area {
            width: 62px;
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
            position: relative;
            justify-content: flex-start;
        }

        .photo-area .student-photo {
            width: 58px;
            height: 68px;
            border: 1.5px solid #284D3D;
            border-radius: 4px;
            object-fit: cover;
            display: block;
            background: #fff;
        }

        /* دستخط جاری کنندہ کا عنوان — فونٹ 50٪ کم اور ٹرانسفارم سکیل برائے براؤزر کمپینیشن */
        .sig-title-wrapper {
            width: 58px;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 1px;
            height: 12px;
        }

        .sig-title {
            font-size: 8px;
            font-weight: bold;
            color: #222;
            text-align: center;
            white-space: nowrap;
            display: inline-block;
            transform: scale(0.65);
            transform-origin: center;
            line-height: 1;
        }

        .sig-space {
            flex: 1;
            min-height: 12px;
            width: 100%;
        }

        .sig-line {
            border-bottom: 1px solid #333;
            width: 58px;
            margin: 0 auto 1px auto;
        }

        /* ===== DETAILS AREA (ٹیبل لے آؤٹ اور مکمل واضح ٹیکسٹ) ===== */
        .details-area {
            flex: 1;
            padding-right: 6px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            position: relative;
            z-index: 1;
            overflow: visible;
        }

        /* واٹر مارک لوگو (ٹیکسٹ ایریا کے بالکل سنٹر میں 22% اوپیسٹی) */
        .watermark-logo {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80px;
            height: 80px;
            opacity: 0.22;
            pointer-events: none;
            z-index: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .watermark-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: grayscale(15%);
        }

        /* ٹیبل لے آؤٹ — تمام 5 لائنیں (تاریخ سمیت) ایک ہی سٹرکچر میں */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            position: relative;
            z-index: 2;
            table-layout: fixed;
            margin: 0;
            padding: 0;
            overflow: visible;
        }

        .details-table tr {
            height: 22px;
        }

        .details-table td {
            padding: 1px 0;
            vertical-align: middle;
            overflow: visible;
            white-space: nowrap;
        }

        .details-table .lbl {
            width: 48px;
            font-size: 9.5px;
            font-weight: bold;
            color: #444;
            line-height: 1.6;
        }

        .details-table .val {
            font-size: 11px;
            font-weight: bold;
            color: #284D3D;
            line-height: 1.6;
        }

        /* پانچویں قطار: تاریخ اجراء و تنسیخ */
        .dates-tr {
            height: 20px !important;
        }

        .dates-tr td {
            border-top: 1px dashed #b0b0b0;
            padding-top: 4px !important;
            padding-bottom: 0 !important;
            vertical-align: middle !important;
        }

        .dates-content {
            font-size: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            white-space: nowrap;
            line-height: 1.4;
            padding: 0 1px;
        }

        .dates-content strong { color: #333; font-size: 7.5px; }
        .dates-content .date-issue { color: #284D3D; font-weight: bold; font-size: 8px; font-family: Arial, 'Noto Nastaliq Urdu', sans-serif; }
        .dates-content .date-expiry { color: #b91c1c; font-weight: bold; font-size: 8px; font-family: Arial, 'Noto Nastaliq Urdu', sans-serif; }
        .dates-content .date-sep { color: #aaa; font-size: 8px; margin: 0 3px; }

        /* ===== FOOTER ===== */
        .footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            background-color: #D4AC0D;
            height: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            box-sizing: border-box;
            padding: 0 6px;
            overflow: hidden;
            z-index: 5;
        }

        .footer span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            font-size: 9.5px;
            font-weight: bold;
            color: #284D3D;
            line-height: 1;
            display: block;
            text-align: center;
            transform: translateY(-2px); /* نستعلیق ہائی بیس لائن کو بار کے عین سنٹر میں سیٹ کرتا ہے */
        }

        /* ===== DRAGGABLE SIGNATURE (ڈریگ اینڈ ڈراپ دستخط) ===== */
        .draggable-signature {
            position: absolute;
            z-index: 40;
            cursor: grab;
            user-select: none;
            display: <?php echo !empty($sig_stamp) ? 'inline-block' : 'none'; ?>;
            touch-action: none;
        }

        .draggable-signature.dragging {
            cursor: grabbing;
            opacity: 0.9;
        }

        .draggable-signature img {
            display: block;
            width: 50px;
            height: auto;
            max-height: 35px;
            object-fit: contain;
            pointer-events: none;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.15));
        }

        .draggable-signature .drag-handle-outline {
            border: 1px dashed rgba(2, 132, 199, 0.7);
            border-radius: 3px;
            padding: 1px;
        }

        /* ===== PRINT MEDIA STYLES ===== */
        @media print {
            .toolbar, .btn-action, input[type="file"], .hint-badge {
                display: none !important;
            }
            body {
                background: white !important;
                padding: 0 !important;
                min-height: auto !important;
            }
            .id-card-wrapper {
                margin: 0 !important;
            }
            .id-card-container {
                box-shadow: none !important;
                border: 1.5px solid #284D3D !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .watermark-logo {
                opacity: 0.22 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .draggable-signature {
                cursor: default !important;
            }
            .draggable-signature .drag-handle-outline {
                border: none !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>

    <!-- ٹول بار: پرنٹ، دستخط اپلوڈ، سائز، ری سیٹ -->
    <div class="toolbar">
        <button class="btn-action btn-print" onclick="window.print()">
            🖨️ پرنٹ کریں (Print)
        </button>

        <label class="btn-action btn-upload">
            ✍️ دستخط اپلوڈ کریں
            <input type="file" id="sig-file-input" accept="image/*" style="display:none;" onchange="handleSigFileSelect(this)">
        </label>

        <div class="slider-group">
            <span>سائز:</span>
            <input type="range" id="sig-size-slider" min="20" max="100" value="50" oninput="changeSigSize(this.value)">
            <span id="sig-size-val">50px</span>
        </div>

        <button class="btn-action btn-reset" onclick="resetSigPosition()">
            ↺ پوزیشن ری سیٹ
        </button>

        <button class="btn-action btn-remove" id="btn-remove-sig" onclick="removeSignature()">
            ✕ دستخط ہٹائیں
        </button>

        <a href="students.php" class="btn-action btn-reset">
            واپس
        </a>

        <div class="hint-badge">
            💡 رہنمائی: دستخط کی تصویر اپلوڈ کر کے ماؤس یا انگلی سے پکڑ کر کارڈ پر اپنی مرضی سے کہیں بھی ڈریگ کریں، یا امیج فائل کارڈ پر ڈراپ کریں۔
        </div>
    </div>

    <!-- کارڈ ویپر -->
    <div class="id-card-wrapper" id="drop-zone-card" ondragover="handleCardDragOver(event)" ondragleave="handleCardDragLeave(event)" ondrop="handleCardDrop(event)">

        <div class="id-card-container" id="idCard">

            <!-- 1. ہیڈر: بڑا لوگو اور مدرسہ نام -->
            <div class="header">
                <img src="<?php echo htmlspecialchars($logo_img); ?>" alt="Logo">
                <div class="header-center">
                    <h1><?php echo htmlspecialchars($app_name); ?></h1>
                    <p class="sub-title">شناختی کارڈ — ID Card</p>
                </div>
                <img src="<?php echo htmlspecialchars($logo_img); ?>" alt="Logo">
            </div>
            
            <div class="content">
                <!-- تفصیلات — RTL میں دائیں جانب -->
                <div class="details-area">
                    <!-- واٹر مارک لوگو (ٹیکسٹ ایریا کے بالکل سنٹر میں 22% اوپیسٹی) -->
                    <div class="watermark-logo">
                        <img src="<?php echo htmlspecialchars($logo_img); ?>" alt="Watermark">
                    </div>

                    <table class="details-table">
                        <tr>
                            <td class="lbl">نام:</td>
                            <td class="val"><?php echo htmlspecialchars($s['name']); ?></td>
                        </tr>
                        <tr>
                            <td class="lbl">ولدیت:</td>
                            <td class="val"><?php echo htmlspecialchars($s['father_name']); ?></td>
                        </tr>
                        <tr>
                            <td class="lbl">داخلہ نمبر:</td>
                            <td class="val"><?php echo htmlspecialchars($s['admission_number']); ?></td>
                        </tr>
                        <?php if(!empty($s['class'])): ?>
                        <tr>
                            <td class="lbl">کلاس:</td>
                            <td class="val"><?php echo htmlspecialchars($s['class']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <!-- پانچویں قطار: تاریخ اجراء و تنسیخ ٹیبل کے اندر محفوظ -->
                        <tr class="dates-tr">
                            <td colspan="2">
                                <div class="dates-content">
                                    <span><strong>اجراء:</strong> <span class="date-issue"><?php echo $issue_date; ?></span></span>
                                    <span class="date-sep">|</span>
                                    <span><strong>تنسیخ:</strong> <span class="date-expiry"><?php echo $expiry_date; ?></span></span>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- تصویر اور دستخط سیکشن — RTL میں بائیں جانب -->
                <div class="photo-area">
                    <?php if(!empty($s['photo']) && file_exists(__DIR__ . '/' . $s['photo'])): ?>
                        <img class="student-photo" src="<?php echo htmlspecialchars($s['photo']); ?>" alt="Student Photo">
                    <?php elseif(!empty($s['photo'])): ?>
                        <img class="student-photo" src="<?php echo htmlspecialchars($s['photo']); ?>" alt="Student Photo">
                    <?php else: ?>
                        <div class="student-photo" style="background:#eee; display:flex; align-items:center; justify-content:center; font-size:8px; color:#aaa;">تصویر</div>
                    <?php endif; ?>
                    
                    <div class="sig-space"></div>
                    <div class="sig-line"></div>
                    <div class="sig-title-wrapper">
                        <div class="sig-title">دستخط جاری کنندہ</div>
                    </div>
                </div>

            </div><!-- /content -->

            <!-- 2. ڈریگ ایبل دستخط امیج (پورے کارڈ پر کہیں بھی موو کی جا سکتی ہے) -->
            <div id="drag-signature" class="draggable-signature" style="bottom: 30px; left: 10px;" title="ماؤس یا ٹچ سے ڈریگ کر کے مطلوبہ جگہ سیٹ کریں">
                <div class="drag-handle-outline">
                    <img id="sig-image" src="<?php echo htmlspecialchars($sig_stamp); ?>" alt="Signature">
                </div>
            </div>

            <!-- 3. فوٹر بار -->
            <div class="footer">
                <span><?php echo htmlspecialchars($tagline); ?></span>
            </div>

        </div><!-- /id-card-container -->

    </div><!-- /id-card-wrapper -->

    <script>
    /* ===== ڈریگ اینڈ ڈراپ دستخط پوزیشننگ + سائزنگ + فائل اپلوڈ لاجک ===== */

    const card = document.getElementById('idCard');
    const dragSig = document.getElementById('drag-signature');
    const sigImg = document.getElementById('sig-image');
    const sizeSlider = document.getElementById('sig-size-slider');
    const sizeVal = document.getElementById('sig-size-val');

    let isDragging = false;
    let startX, startY;
    let initialLeft, initialTop;

    // ڈیفالٹ پوزیشن سیٹ کرنا
    function resetSigPosition() {
        dragSig.style.bottom = '30px';
        dragSig.style.left = '10px';
        dragSig.style.top = 'auto';
        dragSig.style.right = 'auto';
    }

    // سائز تبدیل کرنا
    function changeSigSize(val) {
        sigImg.style.width = val + 'px';
        sizeVal.textContent = val + 'px';
    }

    // دستخط ہٹانا
    function removeSignature() {
        dragSig.style.display = 'none';
    }

    // دستخط امیج شو کرنا
    function showSignatureSrc(src) {
        sigImg.src = src;
        dragSig.style.display = 'inline-block';
        resetSigPosition();
    }

    // فائل ان پٹ سے تصویر لوڈ
    function handleSigFileSelect(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                showSignatureSrc(e.target.result);
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // کارڈ پر ڈائریکٹ فائل ڈراپ کرنا
    function handleCardDragOver(e) {
        e.preventDefault();
        card.style.outline = '2px dashed #0284c7';
    }

    function handleCardDragLeave(e) {
        card.style.outline = 'none';
    }

    function handleCardDrop(e) {
        e.preventDefault();
        card.style.outline = 'none';
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            const file = e.dataTransfer.files[0];
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    showSignatureSrc(evt.target.result);
                };
                reader.readAsDataURL(file);
            }
        }
    }

    // ===== ماؤس اور ٹچ ڈریگ لاجک =====
    dragSig.addEventListener('mousedown', startDrag);
    dragSig.addEventListener('touchstart', startDrag, { passive: false });

    function startDrag(e) {
        isDragging = true;
        dragSig.classList.add('dragging');

        const clientX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.startsWith('touch') ? e.touches[0].clientY : e.clientY;

        const cardRect = card.getBoundingClientRect();
        const sigRect = dragSig.getBoundingClientRect();

        // کارڈ کے مقابلے میں موجودہ پوزیشن
        initialLeft = sigRect.left - cardRect.left;
        initialTop = sigRect.top - cardRect.top;

        startX = clientX;
        startY = clientY;

        // ٹاپ اور لیفٹ موڈ میں تبدیل کرنا تاکہ ڈریگ سموتھ ہو
        dragSig.style.left = initialLeft + 'px';
        dragSig.style.top = initialTop + 'px';
        dragSig.style.bottom = 'auto';
        dragSig.style.right = 'auto';

        document.addEventListener('mousemove', onDrag);
        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchmove', onDrag, { passive: false });
        document.addEventListener('touchend', endDrag);

        e.preventDefault();
    }

    function onDrag(e) {
        if (!isDragging) return;

        const clientX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.startsWith('touch') ? e.touches[0].clientY : e.clientY;

        const deltaX = clientX - startX;
        const deltaY = clientY - startY;

        let newLeft = initialLeft + deltaX;
        let newTop = initialTop + deltaY;

        const cardWidth = card.clientWidth;
        const cardHeight = card.clientHeight;
        const sigWidth = dragSig.offsetWidth;
        const sigHeight = dragSig.offsetHeight;

        // باؤنڈری چیک
        if (newLeft < 0) newLeft = 0;
        if (newTop < 0) newTop = 0;
        if (newLeft + sigWidth > cardWidth) newLeft = cardWidth - sigWidth;
        if (newTop + sigHeight > cardHeight) newTop = cardHeight - sigHeight;

        dragSig.style.left = newLeft + 'px';
        dragSig.style.top = newTop + 'px';

        e.preventDefault();
    }

    function endDrag() {
        isDragging = false;
        dragSig.classList.remove('dragging');
        document.removeEventListener('mousemove', onDrag);
        document.removeEventListener('mouseup', endDrag);
        document.removeEventListener('touchmove', onDrag);
        document.removeEventListener('touchend', endDrag);
    }
    </script>

</body>
</html>
