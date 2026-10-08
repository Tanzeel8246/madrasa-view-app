<?php
require_once 'db.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    die("Student ID required");
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$s = $stmt->fetch();

if (!$s) {
    die("Student not found");
}

$app_name = "مدرسہ اشرف المدارس";
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سٹوڈنٹ آئی ڈی کارڈ - <?php echo $s['name']; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap');
        
        body {
            font-family: 'Noto Nastaliq Urdu', serif;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .id-card-container {
            width: 3.375in; /* Standard ID Card Width */
            height: 2.125in; /* Standard ID Card Height */
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
            border: 1px solid #ddd;
            background: linear-gradient(135deg, #ffffff 0%, #f9f9f9 100%);
        }

        .header {
            background-color: #284D3D;
            color: white;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 45px;
            box-sizing: border-box;
        }

        .header img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: white;
            object-fit: contain;
        }

        .header h1 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            flex: 1;
        }

        .content {
            display: flex;
            padding: 10px;
            height: calc(100% - 65px);
        }

        .photo-area {
            width: 75px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .photo-area img {
            width: 65px;
            height: 80px;
            border: 2px solid #284D3D;
            border-radius: 5px;
            object-fit: cover;
        }
        
        .issuer-sig {
            margin-top: auto;
            font-size: 8px;
            text-align: center;
            font-weight: bold;
            color: #333;
            width: 100%;
        }

        .sig-line {
            border-bottom: 1px solid #333;
            height: 20px;
            margin-bottom: 2px;
        }

        .details-area {
            flex: 1;
            padding-left: 10px; /* padding on left since photo is on the left now */
            font-size: 10px;
            line-height: 1.6;
        }

        .detail-row {
            margin-bottom: 3px;
        }

        .label {
            font-weight: bold;
            color: #555;
            display: inline-block;
            width: 50px;
        }

        .value {
            color: #284D3D;
            font-weight: bold;
        }

        .footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            background-color: #D4AC0D;
            height: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 8px;
            font-weight: bold;
            color: #284D3D;
        }

        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #284D3D;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        @media print {
            .print-btn { display: none; }
            body { background: white; }
            .id-card-container { box-shadow: none; border: 1px solid #000; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <button class="print-btn" onclick="window.print()">پرنٹ کریں</button>

    <div class="id-card-container">
        <div class="header">
            <img src="logo.png" alt="Logo">
            <h1><?php echo $app_name; ?></h1>
            <img src="logo.png" alt="Logo">
        </div>
        
        <div class="content">
            <!-- Details Area (Appears on right due to RTL) -->
            <div class="details-area">
                <div class="detail-row">
                    <span class="label">نام:</span>
                    <span class="value"><?php echo $s['name']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">ولدیت:</span>
                    <span class="value"><?php echo $s['father_name']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">داخلہ نمبر:</span>
                    <span class="value"><?php echo $s['admission_number']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">کلاس:</span>
                    <span class="value"><?php echo $s['class']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">رابطہ:</span>
                    <span class="value"><?php echo $s['contact_number']; ?></span>
                </div>
                <div class="detail-row" style="margin-top: 5px;">
                    <span class="label">آئی ڈی:</span>
                    <span class="value">#<?php echo $s['id']; ?></span>
                </div>
            </div>

            <!-- Photo Area (Appears on left due to RTL) -->
            <div class="photo-area">
                <?php if($s['photo']): ?>
                    <img src="<?php echo $s['photo']; ?>" alt="Student Photo">
                <?php else: ?>
                    <img src="https://via.placeholder.com/65x80?text=No+Photo" alt="No Photo">
                <?php endif; ?>
                
                <div class="issuer-sig">
                    <div class="sig-line"></div>
                    <div>دستخط جاری کنندہ</div>
                </div>
            </div>
        </div>

        <div class="footer">
            العلم نور - تعلیم و تربیت کا گہوارہ
        </div>
    </div>

</body>
</html>
