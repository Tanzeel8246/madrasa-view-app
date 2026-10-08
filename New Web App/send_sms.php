<?php
require_once 'db.php';

function send_sms($mobile, $message) {
    global $pdo;
    
    // سیکیورٹی: نمبر فارمیٹ درست کریں (92 سے شروع کریں)
    $mobile = preg_replace('/[^0-9]/', '', $mobile);
    if (strpos($mobile, '0') === 0) {
        $mobile = '92' . substr($mobile, 1);
    }

    try {
        // سیٹنگز سے API کی حاصل کریں
        $settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
        $api_key = $settings['sms_api_key'] ?? '';
        
        if (empty($api_key)) {
            return ['success' => false, 'message' => 'SMS API Key not configured.'];
        }

        // یہاں آپ کی مخصوص SMS API کا لنک آئے گا
        // فی الحال میں ایک عام فارمیٹ استعمال کر رہا ہوں
        $url = "https://api.veevotech.com/sendsms?hash=$api_key&receiver=$mobile&msg=" . urlencode($message) . "&sender=Madrasa";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'message' => $error ?: 'SMS gateway error'];
        }
        curl_close($ch);

        return ['success' => true, 'message' => 'SMS Sent Successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// اگر یہ فائل براہ راست کال ہو (جیسے ایڈمیشن سے)
if (isset($_POST['app_id'])) {
    $app_id = intval($_POST['app_id']);
    $stmt = $pdo->prepare("SELECT * FROM admission_applications WHERE id = ?");
    $stmt->execute([$app_id]);
    $app = $stmt->fetch();
    
    if ($app) {
        $msg = "محترم " . $app['student_name'] . "، آپ کی درخواست برائے داخلہ موصول ہو گئی ہے۔ ٹیسٹ کی تاریخ: " . $app['test_date'] . " وقت: " . $app['test_time'];
        $res = send_sms($app['mobile_number'], $msg);
        if (!empty($res['success'])) {
            try {
                $hasSmsSentColumn = $pdo->query("SHOW COLUMNS FROM admission_applications LIKE 'sms_sent'")->fetch();
                if ($hasSmsSentColumn) {
                    $pdo->prepare("UPDATE admission_applications SET sms_sent = 1 WHERE id = ?")->execute([$app_id]);
                }
            } catch (Throwable $e) {
                // Keep resend compatible with older databases that do not have sms_sent yet.
            }
        }
        echo json_encode($res);
    }
}
?>
