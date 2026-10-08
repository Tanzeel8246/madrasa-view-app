<?php
// ✅ session_start() کو require سے پہلے چلانا ضروری ہے
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
$provider = $settings['ai_provider'] ?? 'gemini';
$gemini_key = $settings['gemini_api_key'] ?? '';
$groq_key = $settings['groq_api_key'] ?? '';
$week_start_day = $settings['week_start_day'] ?? 6;

try {
    $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status='Active'")->fetchColumn();
    
    $now = new DateTime();
    $current_day_num = (int)$now->format('w');
    $days_since_start = ($current_day_num - $week_start_day + 7) % 7;
    $start_date = $now->modify("-$days_since_start days")->format('Y-m-d');
    $end_date = date('Y-m-d');

    $weekly_excellent = $pdo->query("SELECT COUNT(*) FROM daily_hifz WHERE (sabaq_status='Excellent' OR manzil_status='Excellent' OR sabaq_status='ممتاز' OR manzil_status='ممتاز') AND date BETWEEN '$start_date' AND '$end_date'")->fetchColumn();
    $weekly_weak = $pdo->query("SELECT COUNT(*) FROM daily_hifz WHERE (sabaq_status='Weak' OR manzil_status='Weak' OR sabaq_status='کمزور' OR manzil_status='کمزور') AND date BETWEEN '$start_date' AND '$end_date'")->fetchColumn();
    $weekly_entries = $pdo->query("SELECT COUNT(*) FROM daily_hifz WHERE date BETWEEN '$start_date' AND '$end_date'")->fetchColumn();
    $weekly_complaints = $pdo->query("SELECT COUNT(*) FROM complaints WHERE date BETWEEN '$start_date' AND '$end_date'")->fetchColumn();

    $complaint_context = ($weekly_complaints > 0) ? "There are $weekly_complaints complaints this week regarding student behavior/discipline. Emphasize that Tarbiyat (upbringing/discipline) is equally important as Taleem (education), and advise teachers to focus on resolving these complaints." : "There are no complaints this week, behavior is good.";

    $prompt = "You are an educational and management advisor for a Quran Madrasa. Week Stats: Students: $total_students, Hifz Entries: $weekly_entries, Excellent: $weekly_excellent, Weak: $weekly_weak. Context: $complaint_context. Provide a 2-line strategic and reformative advice in URDU for the ADMIN and TEACHERS. Just Urdu text.";
    
    $response_text = "";
    // Multiple keys support: Explode by comma and pick a random key
    $gemini_keys_arr = array_filter(array_map('trim', explode(',', $gemini_key)));
    $groq_keys_arr = array_filter(array_map('trim', explode(',', $groq_key)));
    
    $selected_gemini_key = !empty($gemini_keys_arr) ? $gemini_keys_arr[array_rand($gemini_keys_arr)] : '';
    $selected_groq_key = !empty($groq_keys_arr) ? $groq_keys_arr[array_rand($groq_keys_arr)] : '';

    $useGroq = ($provider === 'groq' && !empty($selected_groq_key)) || (empty($selected_gemini_key) && !empty($selected_groq_key));
    $useGemini = ($provider === 'gemini' && !empty($selected_gemini_key)) || (empty($selected_groq_key) && !empty($selected_gemini_key));

    if ($useGroq) {
        $url = "https://api.groq.com/openai/v1/chat/completions";
        $data = ["model" => "llama-3.3-70b-versatile", "messages" => [["role" => "user", "content" => $prompt]], "temperature" => 0.7, "max_tokens" => 256];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $selected_groq_key]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response) {
            $res = json_decode($response, true);
            if (isset($res['choices'][0]['message']['content'])) {
                $response_text = $res['choices'][0]['message']['content'];
            } else {
                // Groq failed, fallback to Gemini if available
                if(!empty($selected_gemini_key)) {
                    $useGemini = true; $useGroq = false; $response_text = '';
                } else {
                    $response_text = "Groq Error: " . ($res['error']['message'] ?? "ڈیٹا مسنگ ہے۔");
                }
            }
        } else {
            if(!empty($selected_gemini_key)) {
                $useGemini = true; $useGroq = false; $response_text = '';
            } else {
                $response_text = "Groq رابطہ فیل: " . $err;
            }
        }
    } 
    
    if ($useGemini && empty($response_text)) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=$selected_gemini_key";
        $data = ["contents" => [["parts" => [["text" => $prompt]]]]];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($response) {
            $res = json_decode($response, true);
            if (isset($res['candidates'][0]['content']['parts'][0]['text'])) {
                $response_text = $res['candidates'][0]['content']['parts'][0]['text'];
            } else {
                $response_text = "Gemini Error: " . ($res['error']['message'] ?? "ڈیٹا مسنگ ہے۔");
            }
        } else {
            $response_text = "Gemini رابطہ فیل: " . $err;
        }
    } else if (!$useGroq && !$useGemini) {
        $response_text = "اے آئی کی سیٹنگز نامکمل ہیں۔ (API Key غائب ہے)";
    }
    echo trim($response_text);
} catch (Exception $e) {
    echo "رپورٹ تیار کرنے میں خرابی: " . $e->getMessage();
}
?>
