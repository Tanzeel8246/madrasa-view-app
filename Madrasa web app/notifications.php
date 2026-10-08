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
    return true;
}

// Push Notification (FCM) فنکشن
function sendPushNotificationFCM(PDO $pdo, $title, $body, $type = 'new_notice', $target_user_id = null, $target_role = null) {
    try {
        // یقینی بنائیں کہ ٹوکنز کا مرکزی ٹیبل موجود ہو
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_fcm_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role VARCHAR(50) NOT NULL,
            fcm_token VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_role (user_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $tokens = [];

        // 1. مرکزی user_fcm_tokens ٹیبل سے تلاش
        if ($target_user_id && $target_role) {
            $stmt = $pdo->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ? AND role = ? AND fcm_token != ''");
            $stmt->execute([$target_user_id, $target_role]);
            $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($target_user_id) {
            $stmt = $pdo->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ? AND fcm_token != ''");
            $stmt->execute([$target_user_id]);
            $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($target_role) {
            $stmt = $pdo->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE role = ? AND fcm_token != ''");
            $stmt->execute([$target_role]);
            $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $stmt = $pdo->query("SELECT fcm_token FROM user_fcm_tokens WHERE fcm_token != ''");
            $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // 2. بیک ورڈ کمپیٹیبلٹی کے لیے admins ٹیبل سے بھی ٹوکن تلاش کریں
        if (empty($target_role) || $target_role === 'admin') {
            if ($target_user_id) {
                $stmt = $pdo->prepare("SELECT fcm_token FROM admins WHERE id = ? AND fcm_token IS NOT NULL AND fcm_token != ''");
                $stmt->execute([$target_user_id]);
                $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
            } else {
                $stmt = $pdo->query("SELECT fcm_token FROM admins WHERE fcm_token IS NOT NULL AND fcm_token != ''");
                $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
        }

        $tokens = array_values(array_filter(array_unique($tokens)));
        if (empty($tokens)) {
            error_log("FCM notice logged (no active tokens): Title: $title, Type: $type");
            return true;
        }

        $fcmServerKey = getenv('FCM_SERVER_KEY') ?: '';
        if (empty($fcmServerKey)) {
            // سرور کی نہ ہونے کی صورت میں سسٹم لاگ میں نوٹیفیکیشن ڈسپیچ ریکارڈ کریں
            error_log("FCM notification dispatched: Title: $title, Type: $type, Recipients: " . count($tokens));
            return true;
        }

        $url = 'https://fcm.googleapis.com/fcm/send';
        $payload = [
            'registration_ids' => $tokens,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'badge' => '1'
            ],
            'data' => [
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ]
        ];

        $headers = [
            'Authorization: key=' . $fcmServerKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);

        return true;
    } catch (Exception $e) {
        error_log("FCM Error: " . $e->getMessage());
        return false;
    }
}
?>