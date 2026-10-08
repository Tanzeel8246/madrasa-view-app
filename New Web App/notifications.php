<?php
// ای میل بھیجنے کا فنکشن
function sendEmail($to, $subject, $message) {
    // اپنی مدرسہ کی ای میل یہاں لکھیں
    $headers = "From: info@madrasa.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    if(mail($to, $subject, $message, $headers)) {
        return true;
    }
    return false;
}

// ایس ایم ایس بھیجنے کا فنکشن (یہاں API استعمال ہوگی)
function sendSMS($number, $message) {
    // مثال: اگر آپ Branding SMS یا Twilio استعمال کریں گے تو ان کا کوڈ یہاں آئے گا
    // فی الحال ہم صرف فرضی ریٹرن کر رہے ہیں
    /*
    $api_key = "YOUR_API_KEY";
    $url = "https://sms-gateway-url.com/send?key=$api_key&to=$number&msg=" . urlencode($message);
    file_get_contents($url);
    */
    return true; 
}

// واٹس ایپ بھیجنے کا فنکشن (API درکار ہے)
function sendWhatsApp($number, $message) {
    // مثال: UltraMsg یا Twilio API
    /*
    $data = [
        'token' => 'YOUR_TOKEN',
        'to' => $number,
        'body' => $message
    ];
    // cURL code here...
    */
    return true;
}
?>