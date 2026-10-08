<?php
require_once 'db.php';

if (!isset($_GET['id'])) {
    die("طالب علم کی آئی ڈی درکار ہے۔");
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$_GET['id']]);
$s = $stmt->fetch();

if (!$s) {
    die("ریکارڈ نہیں ملا۔");
}

// Student's Urdu Signature Generation (Stylized)
$student_signature = $s['name']; 
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>داخلہ فارم - <?php echo $s['name']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu&family=Mrs+Saint+Delafield&display=swap" rel="stylesheet">
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
            padding: 40px;
            position: relative;
        }
        .header-section {
            margin-bottom: 40px;
            position: relative;
            min-height: 180px;
        }
        .header-title {
            font-size: 30px;
            font-weight: bold;
            color: #000;
            margin-bottom: 5px;
        }
        .madrasa-name {
            font-size: 48px;
            color: #000;
            font-weight: bold;
            margin-top: 0;
            line-height: 1.1;
        }
        .madrasa-address {
            font-size: 15px;
            margin-top: 5px;
            font-weight: bold;
            line-height: 1.5;
        }
        .photo-box {
            width: 130px;
            height: 160px;
            border: 2px solid #000;
            position: absolute;
            top: 0;
            left: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #fff;
            z-index: 10;
        }
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .form-content {
            margin-top: 20px;
        }
        .form-row {
            margin-bottom: 18px;
            border-bottom: 1px dotted #555;
            padding-bottom: 5px;
            font-size: 19px;
            display: flex;
            justify-content: space-between;
        }
        .label {
            font-weight: bold;
            color: #000;
            min-width: 150px;
        }
        .value {
            flex-grow: 1;
            text-align: right;
            padding-right: 10px;
        }
        .box-title {
            background: #222;
            color: white;
            text-align: center;
            padding: 6px;
            margin: 25px 0 10px 0;
            font-size: 20px;
            border-radius: 4px;
            font-weight: bold;
        }
        .declaration-text {
            font-size: 15px;
            line-height: 2;
            text-align: justify;
            padding: 10px;
            border: 1px solid #eee;
            background: #fafafa;
        }
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .sig-box {
            text-align: center;
            width: 30%;
            border-top: 1px solid #000;
            padding-top: 10px;
            position: relative;
        }
        .digital-sig {
            font-family: 'Mrs Saint Delafield', cursive;
            font-size: 35px;
            color: #00008b;
            position: absolute;
            top: -45px;
            left: 0;
            right: 0;
            text-align: center;
            opacity: 0.8;
            transform: rotate(-3deg);
        }
        .digital-sig-urdu {
            font-family: 'Noto Nastaliq Urdu', serif;
            font-size: 18px;
            color: #00008b;
            position: absolute;
            top: -35px;
            left: 0;
            right: 0;
            text-align: center;
            opacity: 0.7;
            font-weight: normal;
        }
        @media print {
            body { background: none; padding: 0; }
            .printable-area { border: 15px double #000; margin: 0; width: 100%; box-shadow: none; }
            .btn-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="text-center mb-3 btn-print">
        <button onclick="window.print()" class="btn btn-dark px-5 fw-bold shadow">پرنٹ کریں (Print / PDF)</button>
        <a href="students.php" class="btn btn-outline-secondary px-3 ms-2">واپس جائیں</a>
    </div>

    <div class="printable-area">
        <div class="header-section text-center">
            <div class="photo-box shadow-sm">
                <?php if(!empty($s['photo'])): ?>
                    <img src="<?php echo $s['photo']; ?>" alt="Photo">
                <?php else: ?>
                    <span class="text-muted small">تصویر کے لیے جگہ</span>
                <?php endif; ?>
            </div>

            <h1 class="header-title">داخلہ فارم</h1>
            <h2 class="madrasa-name">مدرسہ اشرف المدارس</h2><br>
            <p class="madrasa-address mt-1">منسلک جامع مسجد حنفیہ (محلہ راؤ رشید والا/بالمقابل دفتر زراعت ساہیوال روڈ<br> فروکہ ضلع سرگودھا</p>
        </div>

        <div class="form-content">
            <div class="row">
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">داخلہ نمبر:</span>
                        <span class="value"><?php echo $s['admission_number']; ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">تاریخِ داخلہ:</span>
                        <span class="value"><?php echo $s['admission_date']; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <span class="label">طالب علم کا نام:</span>
                <span class="value fw-bold text-primary"><?php echo $s['name']; ?></span>
            </div>
            
            <div class="form-row">
                <span class="label">والد کا نام:</span>
                <span class="value"><?php echo $s['father_name']; ?></span>
            </div>

            <div class="row">
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">تاریخِ پیدائش:</span>
                        <span class="value"><?php echo $s['dob'] ?? '---'; ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">شعبہ / درجہ:</span>
                        <span class="value"><?php echo $s['class']; ?></span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <span class="label">موجودہ پتہ:</span>
                <span class="value"><?php echo $s['address']; ?></span>
            </div>

            <div class="form-row">
                <span class="label">مستقل پتہ:</span>
                <span class="value"><?php echo $s['permanent_address'] ?? $s['address']; ?></span>
            </div>

            <div class="row">
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">موبائل نمبر:</span>
                        <span class="value" dir="ltr"><?php echo $s['contact_number']; ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-row">
                        <span class="label">ای میل:</span>
                        <span class="value" dir="ltr"><?php echo $s['email'] ?? '---'; ?></span>
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

            <div class="signature-section">
                <div class="sig-box">
                    <div class="digital-sig-urdu"> <?php echo $student_signature; ?></div>
                    <strong>دستخط طالب علم</strong>
                </div>
                <div class="sig-box">
                    <strong>دستخط سرپرست</strong>
                </div>
                <div class="sig-box">
                    <strong>دستخط ناظمِ مدرسہ</strong>
                </div>
            </div>
        </div>

        <div class="position-absolute bottom-0 start-0 p-4 text-muted small">
            سسٹم جنریٹڈ فارم - <?php echo date('Y-m-d H:i'); ?>
        </div>
    </div>

</body>
</html>
