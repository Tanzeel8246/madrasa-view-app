<?php
require_once __DIR__ . '/env_loader.php';
// MoceanAPI SMS Function
function send_sms($number, $message) {
    // 1. آپ کا API ٹوکن (یہاں اپنا اصلی ٹوکن پیسٹ کریں)
    $api_token = getenv('MOCEAN_API_TOKEN') ?: '';
    if ($api_token === '') {
        return false;
    }

    // 2. API URL
    $url = "https://rest.moceanapi.com/rest/2/sms";

    // 3. ڈیٹا تیار کریں
    $data = [
        'mocean-from' => 'MOCEAN', // اگر آپ کی کوئی Sender ID ہے تو وہ یہاں لکھیں، ورنہ MOCEAN رہنے دیں
        'mocean-to' => $number,
        'mocean-text' => $message,
        'mocean-resp-format' => 'json'
    ];

    // 4. cURL سیٹنگز
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    // ہیڈر میں ٹوکن بھیجنا ہے (جیسا کہ آپ کے کوڈ میں تھا)
    // لیکن MoceanAPI اکثر API Key/Secret کے ساتھ بھی چلتا ہے۔ 
    // اگر آپ کے پاس Bearer Token ہے تو یہ لائن ٹھیک ہے:
    /* curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_token"
    ]); */
    
    // نوٹ: MoceanAPI کی ڈاکومنٹیشن کے مطابق اکثر وہ API Key اور Secret مانگتے ہیں۔
    // اگر آپ کا کوڈ صرف ٹوکن والا ہے تو اوپر والا طریقہ ٹھیک ہے۔
    // اگر وہ API Key اور Secret مانگیں تو انہیں $data ایرے میں شامل کرنا ہوگا۔
    
    // چونکہ آپ نے Bearer Token والا کوڈ دیا ہے، ہم اسے استعمال کریں گے:
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer " . $api_token
    ));

    // 5. بھیجیں
    $response = curl_exec($ch);
    
    // ایرر چیکنگ (صرف ٹیسٹنگ کے لیے)
    if(curl_errno($ch)){
        // echo 'Curl error: ' . curl_error($ch);
        return false;
    }
    
    curl_close($ch);
    return $response; // جواب واپس کریں
}
?>
