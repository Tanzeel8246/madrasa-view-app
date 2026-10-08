<?php
require_once __DIR__ . '/env_loader.php';
require_once 'db.php';
header("Content-Type: application/json; charset=UTF-8");
session_start();

$allowedOrigins = [
    'https://madrasa.mamfss.com',
    'http://localhost',
    'http://localhost:8080',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$apiSecret = getenv('MADRASA_API_SECRET') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$xApiKey = $headers['X-API-KEY'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
$hasApiAuth = $apiSecret !== '' && (hash_equals('Bearer ' . $apiSecret, $authHeader) || hash_equals($apiSecret, $xApiKey));
if (!isset($_SESSION['user_id']) && !$hasApiAuth) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'insight' => 'Unauthorized']);
    exit;
}

// Auto-upgrade DB schema for AI provider if not exists
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN ai_provider VARCHAR(50) DEFAULT 'gemini'");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE settings ADD COLUMN groq_api_key VARCHAR(255)");
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';
$json_data = file_get_contents("php://input");
$input = json_decode($json_data, true) ?: $_POST;

$prompt = $input['prompt'] ?? '';
if (empty($prompt)) {
    echo json_encode(['status' => 'error', 'insight' => 'Prompt is empty']);
    exit;
}

$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
$provider = $settings['ai_provider'] ?? 'gemini';
$gemini_key = $settings['gemini_api_key'] ?? '';
$groq_key = $settings['groq_api_key'] ?? '';

function ai_key_list($value) {
    $parts = preg_split('/[\r\n,]+/', (string)$value);
    $keys = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') $keys[] = $part;
    }
    return array_values(array_unique($keys));
}

function ai_call_groq($apiKey, $prompt) {
    if (trim((string)$apiKey) === '') return null;
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $data = [
        "model" => "llama-3.3-70b-versatile",
        "messages" => [["role" => "user", "content" => $prompt]],
        "temperature" => 0.7,
        "max_tokens" => 256
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return null;
    $decoded = json_decode($res, true);
    $text = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    return $text !== '' ? $text : null;
}

function ai_call_gemini($apiKey, $prompt) {
    if (trim((string)$apiKey) === '') return null;
    $models = ['gemini-2.5-flash'];
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
        $data = ["contents" => [["parts" => [["text" => $prompt]]]]];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        curl_close($ch);
        if (!$res) continue;
        $decoded = json_decode($res, true);
        $text = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text !== '') return $text;
    }
    return null;
}

$provider = strtolower(trim((string)$provider));
$attempts = ($provider === 'groq') ? ['groq', 'gemini'] : ['gemini', 'groq'];
$gemini_keys = ai_key_list($gemini_key);
$groq_keys = ai_key_list($groq_key);
$response_text = null;
foreach ($attempts as $attempt) {
    if ($attempt === 'groq') {
        foreach ($groq_keys as $key) {
            $response_text = ai_call_groq($key, $prompt);
            if ($response_text !== null && trim($response_text) !== '') break;
        }
    } else {
        foreach ($gemini_keys as $key) {
            $response_text = ai_call_gemini($key, $prompt);
            if ($response_text !== null && trim($response_text) !== '') break;
        }
    }
    if ($response_text !== null && trim($response_text) !== '') break;
}
if ($response_text === null || trim($response_text) === '') {
    echo json_encode(['status' => 'error', 'insight' => 'AI provider failed or API key is missing.']);
    exit;
}

echo json_encode(['status' => 'success', 'insight' => trim($response_text)], JSON_UNESCAPED_UNICODE);
?>
