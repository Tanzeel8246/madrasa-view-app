<?php
/**
 * AcademicController - Handles Academic & AI Insight API Requests
 * Madrasa Ashraf-ul-Madaris
 */

class AcademicController {

    public static function getAiInsight(PDO $pdo, array $input): array {
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '') {
            $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status='Active' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            $prompt = "You are an AI Management Advisor for a Quran Madrasa. Total Students: $total_students. Provide a 2-line strategic advice in URDU for the ADMIN. Just Urdu text.";
        }
        $ai_response = function_exists('call_ai_provider') ? call_ai_provider($pdo, $prompt) : 'AI service unavailable';
        return ['status' => 'success', 'insight' => trim($ai_response)];
    }
}
