<?php
include 'db.php';
include 'api_config.php';

// تمام طلباء کا لوپ
$students = $pdo->query("SELECT * FROM students");
foreach($students as $std) {
    // 1. کارکردگی کیلکولیٹ کریں (حاضری + سبق)
    // 2. میسج تیار کریں: "السلام علیکم! آپ کے بچے کی اس ہفتے کی حاضری 6/6 ہے اور سبق 2 پارے ہے۔"
    
    // 3. سینڈ کریں
    send_sms($std['mobile_no'], $message);
    send_whatsapp($std['whatsapp_no'], $message);
}
?>