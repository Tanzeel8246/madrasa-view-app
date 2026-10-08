<?php
require_once 'db.php';

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$std_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (!$exam_id || !$std_id) {
    die("Invalid request.");
}

$exam = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$exam->execute([$exam_id]);
$exam_data = $exam->fetch();

$sql = "SELECT er.*, s.name, s.father_name, s.admission_number, s.class, s.photo 
        FROM exam_results er 
        JOIN students s ON er.student_id = s.id 
        WHERE er.exam_id = ? AND er.student_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$exam_id, $std_id]);
$res = $stmt->fetch();

if (!$res) die("Result not found.");

// کلاس کا ڈیٹا اور مضامین نکالیں
$class_data = null;
$dynamic_subjects = [];
$dynamic_subjects_marks_max = [];
if ($res['class']) {
    $stmt_c = $pdo->prepare("SELECT * FROM classes WHERE name = ?");
    $stmt_c->execute([$res['class']]);
    $class_data = $stmt_c->fetch();
    if ($class_data) {
        // 1. Query class_periods for the given class id
        $stmt_cp = $pdo->prepare("SELECT books FROM class_periods WHERE class_id = ? ORDER BY period_no ASC");
        $stmt_cp->execute([$class_data['id']]);
        $periods_books = $stmt_cp->fetchAll(PDO::FETCH_COLUMN);
        
        $books_found = array_filter(array_map('trim', $periods_books));
        
        if (!$is_hifz_class && !empty($books_found)) {
            // Use books as subjects
            foreach ($books_found as $b) {
                $dynamic_subjects[] = $b;
                $dynamic_subjects_marks_max[$b] = 100;
            }
        } elseif (!empty($class_data['subjects_list'])) {
            $parts = array_filter(array_map('trim', explode(',', $class_data['subjects_list'])));
            foreach ($parts as $p) {
                $s_parts = explode(':', $p);
                $n = trim($s_parts[0]);
                $m = isset($s_parts[1]) ? (int)trim($s_parts[1]) : 100;
                if ($n) {
                    $dynamic_subjects[] = $n;
                    $dynamic_subjects_marks_max[$n] = $m;
                }
            }
        } else {
            for ($i=1; $i<=6; $i++) {
                $n = $class_data["p{$i}_name"] ?? "P$i";
                if ($n) {
                    $dynamic_subjects[] = $n;
                    $dynamic_subjects_marks_max[$n] = 100;
                }
            }
        }
    }
}

$avg = $res['total_marks'] > 0 ? ($res['marks_obtained'] / $res['total_marks']) * 100 : 0;

// لوگو کو بیس 64 میں تبدیل کریں تاکہ یہ ہمیشہ شو ہو
$logo_base64 = '';
$logo_path = __DIR__ . '/logo.png';
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
}
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Result Card - <?php echo $res['name']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu&display=swap" rel="stylesheet">
    <style>
        body { background: #f0f0f0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .result-card {
            background: white;
            width: 148mm;
            min-height: 210mm; /* A5 Portrait */
            margin: 20px auto;
            padding: 15mm 10mm;
            border: 15px solid #284D3D;
            outline: 2px solid #D4AC0D;
            outline-offset: -10px;
            position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            background-image: url('<?php echo $logo_base64; ?>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: 300px;
            background-blend-mode: overlay;
            background-color: rgba(255, 255, 255, 0.95);
        }
        
        .header { text-align: center; border-bottom: 4px double #284D3D; padding-bottom: 15px; margin-bottom: 20px; position: relative; }
        .logo-box { position: absolute; left: 0; top: 0; width: 70px; height: 70px; }
        .logo-box img { width: 70px; height: 70px; object-fit: contain; }
        
        .madrasa-name { font-family: 'Noto Nastaliq Urdu', serif; font-size: 30px; color: #284D3D; font-weight: bold; line-height: 1.2; margin-bottom: 5px; }
        .sub-header { font-size: 11px; color: #555; font-weight: bold; }
        
        .title-badge { 
            background: #284D3D; 
            color: #D4AC0D; 
            display: inline-block; 
            padding: 5px 30px; 
            border-radius: 50px; 
            font-weight: bold; 
            margin: 15px 0; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            font-size: 18px;
        }

        .student-section {
            display: flex;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 15px;
            background: rgba(255,255,255,0.8);
        }
        .student-photo { width: 80px; height: 100px; border: 2px solid #284D3D; border-radius: 8px; margin-left: 15px; overflow: hidden; }
        .student-photo img { width: 100%; height: 100%; object-fit: cover; }
        .student-details { flex: 1; font-size: 13px; line-height: 1.8; }
        .detail-line { border-bottom: 1px dashed #ccc; display: flex; justify-content: space-between; }
        
        .marks-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; background: white; }
        .marks-table th { background: #284D3D; color: #D4AC0D; padding: 10px; border: 1px solid #284D3D; }
        .marks-table td { padding: 8px; border: 1px solid #284D3D; text-align: center; font-weight: 500; }
        
        .summary-section { display: flex; margin-top: 20px; gap: 15px; }
        .grade-card { flex: 1; border: 2px solid #284D3D; border-radius: 10px; text-align: center; padding: 10px; background: #f9f9f9; }
        .grade-val { font-size: 35px; font-weight: 900; color: #284D3D; line-height: 1; }
        
        .remarks-card { flex: 3; border: 2px solid #284D3D; border-radius: 10px; padding: 10px; font-size: 12px; position: relative; background: white; min-height: 70px; }
        .remarks-label { position: absolute; top: -10px; right: 15px; background: white; padding: 0 5px; font-size: 10px; font-weight: bold; color: #284D3D; }
        
        .footer-sigs { display: flex; justify-content: space-between; margin-top: 40px; }
        .sig-box { width: 120px; border-top: 2px solid #284D3D; padding-top: 5px; text-align: center; font-size: 11px; font-weight: bold; }
        
        @media print {
            body { background: white; margin: 0; }
            .result-card { margin: 0; box-shadow: none; border-width: 10px; width: 148mm; height: 210mm; -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
            @page { size: A5; margin: 0; }
        }
    </style>
</head>
<body>

<div class="text-center mt-3 no-print">
    <button onclick="window.print()" class="btn btn-primary shadow-lg px-5">پرنٹ رزلٹ کارڈ (Print Card)</button>
</div>

<div class="result-card">
    <div class="header">
        <div class="logo-box">
            <?php if ($logo_base64): ?>
                <img src="<?php echo $logo_base64; ?>" alt="Logo">
            <?php endif; ?>
        </div>
        <div class="madrasa-name">مدرسہ اشرف المدارس</div>
        <div class="sub-header">فاروقہ، ضلع سرگودھا | فون: 0300-1234567</div>
        <div class="title-badge">رزلٹ کارڈ (Result Card)</div>
        <div style="color: #284D3D; font-weight: bold; font-size: 14px;"><?php echo $exam_data['exam_name']; ?> (<?php echo $exam_data['exam_date']; ?>)</div>
    </div>

    <div class="student-section">
        <div class="student-photo">
            <?php if($res['photo']): ?>
                <img src="<?php echo $res['photo']; ?>" alt="Photo">
            <?php else: ?>
                <img src="https://via.placeholder.com/80x100?text=Photo" alt="No Photo">
            <?php endif; ?>
        </div>
        <div class="student-details">
            <div class="detail-line"><b>نام طالب علم:</b> <span><?php echo $res['name']; ?></span></div>
            <div class="detail-line"><b>ولدیت:</b> <span><?php echo $res['father_name']; ?></span></div>
            <div class="detail-line"><b>داخلہ نمبر:</b> <span class="fw-bold"><?php echo $res['admission_number']; ?></span></div>
            <div class="detail-line"><b>کلاس / درجہ:</b> <span><?php echo $res['class']; ?></span></div>
        </div>
    </div>

    <table class="marks-table">
        <thead>
            <tr>
                <th style="text-align: right; padding-right: 20px;">تفصیل (Subjects / Details)</th>
                <th>کل نمبر</th>
                <th>حاصل کردہ</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($res['subject'] == 'Quran'): ?>
                <tr><td style="text-align: right; padding-right: 20px;">پختگی (Memorization / Pukhtagi)</td><td>40</td><td class="fw-bold"><?php echo (int)$res['pukhtagi']; ?></td></tr>
                <tr><td style="text-align: right; padding-right: 20px;">تلفظ و تجوید (Tajweed / Talafuz)</td><td>20</td><td class="fw-bold"><?php echo (int)$res['tajweed']; ?></td></tr>
                <tr><td style="text-align: right; padding-right: 20px;">خوبصورتی و لہجہ (Accent / Lahja)</td><td>20</td><td class="fw-bold"><?php echo (int)$res['lahja']; ?></td></tr>
                <tr><td style="text-align: right; padding-right: 20px;">نماز و مسائل (Namaz / Masail)</td><td>20</td><td class="fw-bold"><?php echo (int)$res['masail']; ?></td></tr>
            <?php else: ?>
                <?php 
                $subj_marks_json = json_decode($res['subjects_marks'] ?? '{}', true) ?: [];
                if (!empty($dynamic_subjects)):
                    foreach($dynamic_subjects as $subj): 
                        $val = isset($subj_marks_json[$subj]) ? (float)$subj_marks_json[$subj] : 0;
                        $max_m = $dynamic_subjects_marks_max[$subj] ?? 100;
                ?>
                <tr>
                    <td style="text-align: right; padding-right: 20px;"><?php echo htmlspecialchars($subj); ?></td>
                    <td><?php echo $max_m; ?></td>
                    <td class="fw-bold"><?php echo $val; ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td style="text-align: right; padding-right: 20px;"><?php echo $res['subject']; ?></td><td><?php echo (int)$res['total_marks']; ?></td><td class="fw-bold"><?php echo (int)$res['marks_obtained']; ?></td></tr>
                <?php endif; ?>
            <?php endif; ?>
            
            <tr style="background: #f0f4f2; font-size: 16px; border-top: 3px double #284D3D;">
                <td style="text-align: right; padding-right: 20px; font-weight: bold;">مجموعی نمبر (Total Marks)</td>
                <td class="fw-bold"><?php echo (int)$res['total_marks']; ?></td>
                <td class="fw-bold" style="color: #284D3D; font-size: 20px;"><?php echo (int)$res['marks_obtained']; ?></td>
            </tr>
        </tbody>
    </table>

    <div class="summary-section">
        <div class="grade-card">
            <div style="font-size: 10px; font-weight: bold; color: #555;">گریڈ (Grade)</div>
            <div class="grade-val"><?php echo $res['grade'] ?: '-'; ?></div>
            <div style="font-size: 11px; font-weight: bold; margin-top: 5px;"><?php echo $res['status'] == 'Pass' ? 'کامیاب' : 'ناکام'; ?></div>
        </div>
        <div class="remarks-card">
            <div class="remarks-label">اساتذہ کے ریمارکس / تاثرات:</div>
            <div style="font-family: 'Noto Nastaliq Urdu', serif; line-height: 1.8;">
                <?php echo $res['remarks'] ?: "ماشاءاللہ! طالب علم کی تعلیمی کارکردگی اطمینان بخش ہے۔ مزید محنت اور توجہ سے بہتر نتائج حاصل کیے جا سکتے ہیں۔"; ?>
            </div>
        </div>
    </div>

    <div class="footer-sigs">
        <div class="sig-box">دستخط کلاس انچارج</div>
        <div class="sig-box">دستخط سرپرست / والد</div>
        <div class="sig-box">مہر و دستخط مہتمم</div>
    </div>

    <div style="position: absolute; bottom: 10mm; left: 10mm; font-size: 8px; color: #777;">
        جاری کردہ: <?php echo date('d-m-Y'); ?> | آئی ڈی: #<?php echo $res['id']; ?>
    </div>
</div>

</body>
</html>
