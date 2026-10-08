<?php 
require_once 'db.php';

if (!isset($_GET['id'])) { die('Invalid Request'); }

$id = (int)$_GET['id'];
$row = $pdo->prepare("SELECT f.*, h.name AS head_name FROM finance f LEFT JOIN heads h ON h.id = f.head_id WHERE f.id = ? LIMIT 1");
$row->execute([$id]);
$data = $row->fetch(PDO::FETCH_ASSOC);

if (!$data || strtolower((string)$data['type']) !== 'income') { die('Receipt not found or not an income entry.'); }

function numberToUrduWordsLess100($n) {
    $units = [
        0 => "", 1 => "ایک", 2 => "دو", 3 => "تین", 4 => "چار", 5 => "پانچ", 6 => "چھ", 7 => "سات", 8 => "آٹھ", 9 => "نو", 10 => "دس",
        11 => "گیارہ", 12 => "بارہ", 13 => "تیرہ", 14 => "چودہ", 15 => "پندرہ", 16 => "سولہ", 17 => "سترہ", 18 => "اٹھارہ", 19 => "انیس", 20 => "بیس",
        21 => "اکیس", 22 => "بائیس", 23 => "تیئیس", 24 => "چوبیس", 25 => "پچیس", 26 => "چھبیس", 27 => "ستائیس", 28 => "اٹہائییس", 29 => "انتیس", 30 => "تیس",
        31 => "اکتیس", 32 => "بتیس", 33 => "تینتیس", 34 => "چونتیس", 35 => "پینتیس", 36 => "چھتیس", 37 => "سینتیس", 38 => "اڑتیس", 39 => "انتالیس", 40 => "چالیس",
        41 => "اکتالیس", 42 => "بیالیس", 43 => "تینتالیس", 44 => "چوالیس", 45 => "پینتالیس", 46 => "چھیالیس", 47 => "سینتالیس", 48 => "اڑتالیس", 49 => "انچاس", 50 => "پچاس",
        51 => "اکیاون", 52 => "باون", 53 => "ترپن", 54 => "چوون", 55 => "پچپن", 56 => "چھپن", 57 => "ستاون", 58 => "اٹھاون", 59 => "انسیٹھ", 60 => "ساٹھ",
        61 => "اکسٹھ", 62 => "باسٹھ", 63 => "تریسٹھ", 64 => "چونسٹھ", 65 => "پینسٹھ", 66 => "چھیاسٹھ", 67 => "سرستھ", 68 => "اڑسٹھ", 69 => "انتر", 70 => "ستر",
        71 => "اکہتر", 72 => "بہتر", 73 => "تہتر", 74 => "چوہتر", 75 => "پچھتر", 76 => "چھہتر", 77 => "ستتر", 78 => "اٹھہتر", 79 => "اناسی", 80 => "اسی",
        81 => "اکیاسی", 82 => "بیاسی", 83 => "تراسی", 84 => "چوراسی", 85 => "پچاسی", 86 => "چھیاسی", 87 => "ستاسی", 88 => "اٹیاسی", 89 => "نواسی", 90 => "نوے",
        91 => "اکانوے", 92 => "بانوے", 93 => "ترانوے", 94 => "چورانوے", 95 => "پچانوے", 96 => "چھیاانوے", 97 => "ستانوے", 98 => "اٹھانوے", 99 => "نناوے"
    ];
    return $units[(int)$n] ?? (string)$n;
}

function numberToUrdu($num) {
    $num = (int)round((float)$num);
    if ($num <= 0) return "صفر روپے صرف";
    
    $parts = [];
    
    $crores = (int)floor($num / 10000000);
    $num %= 10000000;
    if ($crores > 0) {
        $parts[] = numberToUrduWordsLess100($crores) . " کروڑ";
    }
    
    $lakhs = (int)floor($num / 100000);
    $num %= 100000;
    if ($lakhs > 0) {
        $parts[] = numberToUrduWordsLess100($lakhs) . " لاکھ";
    }
    
    $thousands = (int)floor($num / 1000);
    $num %= 1000;
    if ($thousands > 0) {
        $parts[] = numberToUrduWordsLess100($thousands) . " ہزار";
    }
    
    $hundreds = (int)floor($num / 100);
    $num %= 100;
    if ($hundreds > 0) {
        $parts[] = numberToUrduWordsLess100($hundreds) . " سو";
    }
    
    if ($num > 0) {
        $parts[] = numberToUrduWordsLess100($num);
    }
    
    return implode(" ", $parts) . " روپے صرف";
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?php echo htmlspecialchars((string)$data['computer_receipt_no']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Jameel Noori Nastaleeq';
            src: url('fonts/JameelNooriNastaleeq.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        body {
            margin: 0;
            padding: 0;
            background: #f0f2f5;
            font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', 'Urdu Typesetting', serif !important;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Action Bar */
        .action-bar {
            background: #fff;
            padding: 15px 20px;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-family: sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: 0.2s;
            font-size: 14px;
        }

        .btn-whatsapp { background: #25D366; color: white; }
        .btn-whatsapp:hover { background: #1ebe57; }
        .btn-close { background: #6c757d; color: white; }
        .btn-close:hover { background: #5a6268; }
        .btn-print { background: #007bff; color: white; }
        .btn-print:hover { background: #0069d9; }
        .btn-sig { background: #f39c12; color: white; position: relative; overflow: hidden; }
        .btn-sig input { position: absolute; left: 0; top: 0; opacity: 0; cursor: pointer; height: 100%; width: 100%; }

        .receipt-wrapper {
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .receipt-container {
            width: 250mm; 
            height: 160mm; 
            background-image: url('uploads/receipt_bg.webp');
            background-size: 100% 100%;
            background-repeat: no-repeat;
            background-position: center;
            position: relative;
            box-shadow: 0 15px 50px rgba(0,0,0,0.2);
            background-color: white;
            border-radius: 5px;
            overflow: hidden;
        }

        @media print {
            body { background: white; padding: 0; }
            .action-bar { display: none !important; }
            .receipt-wrapper { padding: 0; margin: 0; }
            .receipt-container { 
                box-shadow: none; 
                margin: 0;
                width: 100%;
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                border-radius: 0;
            }
            @page { size: landscape; margin: 0; }
        }

        .field {
            position: absolute;
            color: #000;
            white-space: nowrap;
            line-height: 1.2;
        }

        /* Positions and explicit !important sizes with line decoration */
        .f-comp-no { top: 34%; left: 13%; font-size: 26px !important; color: #000; font-family: Arial, sans-serif !important; font-weight: 900 !important; }
        .f-date { top: 46.5%; left: 12.5%; font-family: Arial, sans-serif !important; font-size: 22px !important; font-weight: 900 !important; }
        
        .f-name { top: 41.5%; right: 8%; font-size: 52px !important; font-weight: bold !important; width: 65%; text-align: right; border-bottom: 2px dotted #444; padding-bottom: 2px; }
        .f-address { top: 54.5%; right: 8%; font-size: 38px !important; font-weight: bold !important; width: 78%; overflow: hidden; text-align: right; border-bottom: 2px dotted #444; padding-bottom: 2px; }
        .f-head { top: 67.5%; right: 8%; font-size: 38px !important; font-weight: bold !important; width: 50%; text-align: right; border-bottom: 2px dotted #444; padding-bottom: 2px; }
        
        .f-amount-box { top: 68.5%; left: 24%; font-size: 26px !important; font-family: Arial, sans-serif !important; font-weight: 900 !important; }
        
        .f-sig-img { 
            position: absolute;
            bottom: 11%;
            right: 20%;
            max-width: 180px;
            max-height: 80px;
            display: none;
        }
        .f-sig-label { bottom: 9%; right: 8%; font-size: 32px !important; color: #000; font-weight: bold !important; }

        /* Modal & Overlay Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }

        .loading-box {
            background: white;
            padding: 30px 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: flex; flex-direction: column; align-items: center; gap: 15px;
            font-size: 18px; font-weight: bold; color: #284d3d;
            font-family: sans-serif;
        }

        .spinner {
            width: 40px; height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #284d3d;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .modal-box {
            background: white;
            width: 90%; max-width: 500px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalPop 0.3s ease-out;
            font-family: sans-serif;
        }

        @keyframes modalPop { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }

        .modal-header {
            background: #284d3d;
            color: white;
            padding: 15px 20px;
            display: flex; justify-content: space-between; align-items: center;
        }

        .modal-header h3 { margin: 0; display: flex; align-items: center; gap: 10px; font-size: 20px; font-family: 'Jameel Noori Nastaleeq', serif; }
        .close-btn { background: none; border: none; color: white; font-size: 28px; cursor: pointer; transition: 0.2s; line-height: 1; }
        .close-btn:hover { color: #ff6b6b; }

        .modal-body { padding: 20px; display: flex; flex-direction: column; gap: 15px; align-items: center; }
        .modal-instruction { font-size: 18px; color: #555; margin: 0; text-align: center; font-family: 'Jameel Noori Nastaleeq', serif; }

        .preview-container {
            width: 100%; max-height: 300px;
            overflow: auto;
            border: 2px solid #eee;
            border-radius: 10px;
            padding: 10px;
            background: #fafafa;
            display: flex; justify-content: center; align-items: center;
        }

        .preview-container img { width: 100%; height: auto; border-radius: 5px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }

        .modal-actions { display: flex; flex-direction: column; gap: 12px; width: 100%; margin-top: 5px; }
        .modal-btn { width: 100%; justify-content: center; padding: 14px; font-size: 16px; border-radius: 12px; font-family: 'Jameel Noori Nastaleeq', serif; font-size: 20px; }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="modal-overlay" style="display: none;">
        <div class="loading-box">
            <div class="spinner"></div>
            <p style="font-family: 'Jameel Noori Nastaleeq', serif; font-size: 24px; margin: 0;">رسید کا امیج تیار کیا جا رہا ہے...</p>
        </div>
    </div>

    <!-- Share Modal -->
    <div id="share-modal" class="modal-overlay" style="display: none;">
        <div class="modal-box">
            <div class="modal-header">
                <h3><img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" width="24" alt=""> رسید واٹس ایپ شیئر کریں</h3>
                <button onclick="closeModal()" class="close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <p class="modal-instruction">رسید کا پروفیشنل امیج تیار ہو چکا ہے۔ براہ کرم نیچے دیے گئے آپشنز میں سے انتخاب کریں:</p>
                <div class="preview-container">
                    <img id="modal-receipt-img" src="" alt="Receipt Preview">
                </div>
                <div class="modal-actions">
                    <button onclick="shareDirectly()" class="btn btn-whatsapp modal-btn">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" width="24" alt=""> ڈائریکٹ شیئر (موبائل / ونڈوز ایپ)
                    </button>
                    <button onclick="downloadAndOpenWA()" class="btn btn-print modal-btn">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> امیج ڈاؤن لوڈ + واٹس ایپ کھولیں
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="action-bar">
        <a href="javascript:void(0)" onclick="shareWhatsApp()" class="btn btn-whatsapp">
             <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" width="20" alt=""> شیئر کریں
        </a>
        <button onclick="window.print()" class="btn btn-print">پرنٹ کریں</button>
        <div class="btn btn-sig">
            دستخط اپلوڈ کریں
            <input type="file" accept="image/*" onchange="previewSignature(this)">
        </div>
        <a href="simple_finance.php" class="btn btn-close">بند کریں</a>
    </div>

    <div class="receipt-wrapper">
        <div class="receipt-container">
            <!-- Receipt Numbers in white box -->
            <div class="field f-comp-no"><?php echo htmlspecialchars((string)$data['computer_receipt_no']); ?></div>
            
            <div class="field f-date">تاریخ: <?php echo date('d/m/Y', strtotime($data['date'])); ?></div>
            
            <div class="field f-name">محترم جناب: <?php echo htmlspecialchars((string)$data['donor_name']); ?></div>
            <div class="field f-address">پتہ: <?php echo htmlspecialchars((string)$data['donor_address']); ?></div>
            <div class="field f-head">مد: <?php echo htmlspecialchars((string)($data['head_name'] ?? '')); ?></div>
            <div class="field f-amount-box">
                <div>Rs: <?php echo number_format((float)$data['amount']); ?>/-</div>
                <div style="font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif !important; font-size: 18px !important; font-weight: bold !important; color: #111; line-height: 1.1; margin-top: 2px;">
                    (<?php echo numberToUrdu($data['amount']); ?>)
                </div>
            </div>
            
            <img id="sig-preview" class="f-sig-img" src="" alt="Signature">
            <div class="field f-sig-label">دستخط وصول کنندہ:</div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        function previewSignature(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    var img = document.getElementById('sig-preview');
                    img.src = e.target.result;
                    img.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function closeModal() {
            document.getElementById('share-modal').style.display = 'none';
        }

        function shareWhatsApp() {
            document.getElementById('loading-overlay').style.display = 'flex';
            
            const receiptElem = document.querySelector('.receipt-container');
            
            html2canvas(receiptElem, {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                backgroundColor: null
            }).then(canvas => {
                document.getElementById('loading-overlay').style.display = 'none';
                
                const imgData = canvas.toDataURL('image/png');
                document.getElementById('modal-receipt-img').src = imgData;
                document.getElementById('share-modal').style.display = 'flex';
                
                window.currentReceiptCanvas = canvas;
            }).catch(err => {
                document.getElementById('loading-overlay').style.display = 'none';
                alert('امیج بنانے میں خرابی پیش آئی: ' + err);
            });
        }

        function getShareText() {
            const donor = "<?php echo addslashes((string)$data['donor_name']); ?>";
            const amount = "<?php echo number_format((float)$data['amount']); ?>";
            const head = "<?php echo addslashes((string)($data['head_name'] ?: $data['description'])); ?>";
            const receiptNo = "<?php echo htmlspecialchars((string)$data['computer_receipt_no']); ?>";
            
            return `*مدرسہ اشرف المدارس — رسید* \n` +
                   `نام: ${donor}\n` +
                   `رقم: ${amount} روپے\n` +
                   `مد: ${head}\n` +
                   `رسید نمبر: ${receiptNo}\n` +
                   `جزاک اللہ خیرا!`;
        }

        function shareDirectly() {
            if (!window.currentReceiptCanvas) return;
            const text = getShareText();
            const receiptNo = "<?php echo htmlspecialchars((string)$data['computer_receipt_no']); ?>";
                         
            window.currentReceiptCanvas.toBlob(blob => {
                const file = new File([blob], `Receipt_${receiptNo}.png`, { type: 'image/png' });
                
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({
                        files: [file],
                        title: 'مدرسہ اشرف المدارس - رسید',
                        text: text
                    }).then(() => {
                        closeModal();
                    }).catch(err => {
                        console.log('Share error:', err);
                    });
                } else {
                    alert('آپ کا موجودہ براؤزر ڈائریکٹ فائل شیئرنگ کو سپورٹ نہیں کرتا۔ براہ کرم نیچے دیا گیا "امیج ڈاؤن لوڈ + واٹس ایپ کھولیں" والا آپشن استعمال کریں۔');
                }
            }, 'image/png');
        }

        function downloadAndOpenWA() {
            if (!window.currentReceiptCanvas) return;
            const text = getShareText();
            const receiptNo = "<?php echo htmlspecialchars((string)$data['computer_receipt_no']); ?>";

            // 1. Download Image
            const a = document.createElement('a');
            a.href = window.currentReceiptCanvas.toDataURL('image/png');
            a.download = `Receipt_${receiptNo}.png`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            // 2. Open WhatsApp
            const waUrl = "https://wa.me/?text=" + encodeURIComponent(text);
            setTimeout(() => {
                window.open(waUrl, '_blank');
                closeModal();
            }, 500);
        }
    </script>
</body>
</html>



