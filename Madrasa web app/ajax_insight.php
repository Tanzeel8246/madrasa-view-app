<?php
// ✅ session_start() کو require سے پہلے چلانا ضروری ہے
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$current_tid = (int)get_current_tenant_id($pdo);
$lang = isset($_GET['lang']) ? trim($_GET['lang']) : ($_SESSION['lang'] ?? 'ur');
$is_en = ($lang === 'en');

$st_q = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
$st_q->execute([$current_tid]);
$settings = $st_q->fetch(PDO::FETCH_ASSOC) ?: [];
$provider = $settings['ai_provider'] ?? 'gemini';
$gemini_key = $settings['gemini_api_key'] ?? '';
$groq_key = $settings['groq_api_key'] ?? '';
$week_start_day = $settings['week_start_day'] ?? 6;

try {
    $stmt_std = $pdo->prepare("SELECT COUNT(*) FROM students WHERE (status='Active' OR status='' OR status IS NULL) AND tenant_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $stmt_std->execute([$current_tid]);
    $total_students = (int)$stmt_std->fetchColumn();
    
    $now = new DateTime();
    $current_day_num = (int)$now->format('w');
    $days_since_start = ($current_day_num - $week_start_day + 7) % 7;
    $start_date = $now->modify("-$days_since_start days")->format('Y-m-d');
    $end_date = date('Y-m-d');

    $stmt_ex = $pdo->prepare("SELECT COUNT(*) FROM daily_hifz WHERE (sabaq_status='Excellent' OR manzil_status='Excellent' OR sabaq_status='ممتاز' OR manzil_status='ممتاز') AND tenant_id = ? AND date BETWEEN ? AND ?");
    $stmt_ex->execute([$current_tid, $start_date, $end_date]);
    $weekly_excellent = (int)$stmt_ex->fetchColumn();

    $stmt_wk = $pdo->prepare("SELECT COUNT(*) FROM daily_hifz WHERE (sabaq_status='Weak' OR manzil_status='Weak' OR sabaq_status='کمزور' OR manzil_status='کمزور') AND tenant_id = ? AND date BETWEEN ? AND ?");
    $stmt_wk->execute([$current_tid, $start_date, $end_date]);
    $weekly_weak = (int)$stmt_wk->fetchColumn();

    $stmt_ent = $pdo->prepare("SELECT COUNT(*) FROM daily_hifz WHERE tenant_id = ? AND date BETWEEN ? AND ?");
    $stmt_ent->execute([$current_tid, $start_date, $end_date]);
    $weekly_entries = (int)$stmt_ent->fetchColumn();

    $stmt_cmp = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE tenant_id = ? AND date BETWEEN ? AND ?");
    $stmt_cmp->execute([$current_tid, $start_date, $end_date]);
    $weekly_complaints = (int)$stmt_cmp->fetchColumn();

    if ($is_en) {
        $complaint_context = ($weekly_complaints > 0) ? "There are $weekly_complaints complaints this week regarding student behavior/discipline. Emphasize that Tarbiyat (discipline and ethics) is as vital as Taleem (academics), and advise teachers accordingly." : "There are no complaints this week, discipline and student conduct are commendable.";
        $prompt = "You are an educational and administrative advisor for an Islamic Quran Madrasa. Week Stats: Students: $total_students, Hifz Entries: $weekly_entries, Excellent: $weekly_excellent, Weak: $weekly_weak. Context: $complaint_context. Provide a 2-line strategic and motivating advice in concise ENGLISH for the ADMIN and TEACHERS. Output pure English text only.";
    } else {
        $complaint_context = ($weekly_complaints > 0) ? "There are $weekly_complaints complaints this week regarding student behavior/discipline. Emphasize that Tarbiyat (upbringing/discipline) is equally important as Taleem (education), and advise teachers to focus on resolving these complaints." : "There are no complaints this week, behavior is good.";
        $prompt = "You are an educational and management advisor for a Quran Madrasa. Week Stats: Students: $total_students, Hifz Entries: $weekly_entries, Excellent: $weekly_excellent, Weak: $weekly_weak. Context: $complaint_context. Provide a 2-line strategic and reformative advice in URDU for the ADMIN and TEACHERS. Just Urdu text.";
    }
    
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
                    $response_text = $is_en ? "AI service is currently unavailable. Please try again later." : "اے آئی سروس فی الوقت دستیاب نہیں ہے۔ برائے مہربانی بعد میں کوشش کریں۔";
                }
            }
        } else {
            if(!empty($selected_gemini_key)) {
                $useGemini = true; $useGroq = false; $response_text = '';
            } else {
                $response_text = $is_en ? "Could not connect to AI service server." : "اے آئی سرور سے رابطہ قائم نہیں ہو سکا۔";
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
                $response_text = $is_en ? "AI service is currently unavailable. Please try again later." : "اے آئی سروس فی الوقت دستیاب نہیں ہے۔ برائے مہربانی بعد میں کوشش کریں۔";
            }
        } else {
            $response_text = $is_en ? "Could not connect to AI service server." : "اے آئی سرور سے رابطہ قائم نہیں ہو سکا۔";
        }
    } else if (!$useGroq && !$useGemini) {
        $response_text = $is_en ? "Please configure AI API key in Settings for automated management insights." : "اے آئی رہنمائی کے لیے ترتیبات میں API کلید درج کریں۔";
    }
    echo trim($response_text);
} catch (Exception $e) {
    error_log("AI Insight Error: " . $e->getMessage());
    echo $is_en ? "Management recommendations are currently unavailable." : "انتظامی تجاویز فی الوقت دستیاب نہیں ہیں۔";
}
