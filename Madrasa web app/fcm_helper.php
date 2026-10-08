<?php
/**
 * FCM Push Notification Helper
 * Firebase Cloud Messaging v1 API & Legacy Fallback
 */

if (!function_exists('b64url_encode')) {
    function b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('write_log')) {
    function write_log($msg) {
        error_log($msg);
    }
}

/**
 * Send FCM push notification to a single device token using FCM HTTP v1 API.
 * Falls back to legacy endpoint if legacy server key is configured.
 */
function send_fcm_notification_to_token(string $fcmToken, string $title, string $body, array $data = []): bool {
    if (empty($fcmToken) || empty($title)) return false;
    
    $projectId = trim(getenv('FIREBASE_PROJECT_ID') ?: '');
    $serviceAccountJson = trim(getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: '');
    
    if (empty($projectId) || empty($serviceAccountJson)) {
        $serverKey = trim(getenv('FIREBASE_SERVER_KEY') ?: (getenv('FCM_SERVER_KEY') ?: ''));
        if (!empty($serverKey) && $serverKey !== 'your_fcm_v1_service_account_json_or_legacy_key') {
            return send_fcm_legacy($fcmToken, $title, $body, $data, $serverKey);
        }
        write_log("FCM: Firebase credentials not configured");
        return false;
    }
    
    // FCM v1 API endpoint
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    
    $payload = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
            'data' => array_map('strval', $data),
        ]
    ];
    
    // OAuth2 Bearer Token (Service Account)
    $accessToken = get_firebase_access_token($serviceAccountJson);
    if (!$accessToken) return false;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        write_log("FCM send failed: HTTP {$httpCode} | Response: " . substr((string)$res, 0, 200));
        return false;
    }
    return true;
}

/**
 * Legacy FCM fallback method
 */
function send_fcm_legacy(string $fcmToken, string $title, string $body, array $data, string $serverKey): bool {
    $url = 'https://fcm.googleapis.com/fcm/send';
    $payload = [
        'to' => $fcmToken,
        'notification' => [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
        ],
        'data' => array_merge(['click_action' => 'FLUTTER_NOTIFICATION_CLICK'], array_map('strval', $data)),
        'priority' => 'high',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: key=' . $serverKey,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        write_log("FCM legacy send failed: HTTP {$httpCode} | Response: " . substr((string)$res, 0, 200));
        return false;
    }
    return true;
}

/**
 * Send FCM notification to all active devices belonging to a specific role.
 * Queries both admins and user_fcm_tokens tables.
 */
function send_fcm_to_role(PDO $pdo, string $role, string $title, string $body, array $data = []): int {
    $sentCount = 0;
    try {
        $tokens = [];
        
        // 1. Check admins table
        try {
            $stmt = $pdo->prepare("SELECT fcm_token FROM admins WHERE role = ? AND fcm_token IS NOT NULL AND fcm_token != '' AND (is_deleted = 0 OR is_deleted IS NULL)");
            $stmt->execute([$role]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['fcm_token'])) {
                    $tokens[] = $row['fcm_token'];
                }
            }
        } catch (Exception $e) {}

        // 2. Check user_fcm_tokens table
        try {
            $stmt2 = $pdo->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE role = ? AND fcm_token IS NOT NULL AND fcm_token != ''");
            $stmt2->execute([$role]);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['fcm_token'])) {
                    $tokens[] = $row['fcm_token'];
                }
            }
        } catch (Exception $e) {}

        $tokens = array_values(array_filter(array_unique($tokens)));
        foreach ($tokens as $token) {
            if (send_fcm_notification_to_token($token, $title, $body, $data)) {
                $sentCount++;
            }
        }
    } catch (Exception $e) {
        write_log("FCM batch send error: " . $e->getMessage());
    }
    return $sentCount;
}

/**
 * Generate Google OAuth2 Access Token from Service Account JSON.
 */
function get_firebase_access_token(string $serviceAccountJson): ?string {
    $sa = json_decode($serviceAccountJson, true);
    if (!$sa && file_exists($serviceAccountJson)) {
        $sa = json_decode(file_get_contents($serviceAccountJson), true);
    }
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) return null;
    
    $now = time();
    $header = b64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = b64url_encode(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    
    $input = $header . '.' . $claim;
    $signature = '';
    $signed = @openssl_sign($input, $signature, $sa['private_key'], 'SHA256');
    if (!$signed) {
        write_log("FCM: openssl_sign failed for JWT");
        return null;
    }
    $jwt = $input . '.' . b64url_encode($signature);
    
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        write_log("FCM token exchange failed: HTTP {$httpCode} | Response: " . substr((string)$res, 0, 200));
        return null;
    }
    
    $data = json_decode((string)$res, true);
    return $data['access_token'] ?? null;
}
