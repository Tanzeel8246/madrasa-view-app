<?php
/**
 * NotificationBadgeService
 * 
 * Provides centralized, cached retrieval of pending counts and smart alerts
 * across Madrasa and Masjid systems, avoiding multiple duplicate queries per request.
 */

class NotificationBadgeService {
    private static $cachedCounts = null;

    public static function getCounts($pdo, $tenantId, $systemType = 'Madrasa') {
        if (self::$cachedCounts !== null) {
            return self::$cachedCounts;
        }

        $tenantId = (int)$tenantId;
        $isMasjid = ($systemType === 'Masjid');
        $tCond = " AND tenant_id = $tenantId";
        $sysCond = $isMasjid ? "system_type = 'Masjid'" : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

        $counts = [
            'pending_fee' => 0,
            'pending_leave' => 0,
            'pending_complaints' => 0,
            'pending_admissions' => 0,
            'pending_teacher_leaves' => 0,
            'total_pending' => 0,
            'daily_naaghah' => 0,
        ];

        try {
            $counts['pending_fee'] = (int)$pdo->query("SELECT COUNT(*) FROM fee_submissions WHERE status = 'Pending' AND (is_deleted = 0 OR is_deleted IS NULL) $tCond")->fetchColumn();
            $counts['pending_leave'] = (int)$pdo->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'Pending' AND (is_deleted = 0 OR is_deleted IS NULL) $tCond")->fetchColumn();
            $counts['pending_complaints'] = (int)$pdo->query("SELECT COUNT(*) FROM parent_complaints WHERE status = 'Pending' AND (is_deleted = 0 OR is_deleted IS NULL) $tCond")->fetchColumn();
            
            try {
                $counts['pending_admissions'] = (int)$pdo->query("SELECT COUNT(*) FROM admission_applications WHERE LOWER(status) = 'pending' $tCond")->fetchColumn();
            } catch (Throwable $e) {}

            try {
                $counts['pending_teacher_leaves'] = (int)$pdo->query("SELECT COUNT(*) FROM teacher_leaves WHERE status = 'Pending' AND (is_deleted = 0 OR is_deleted IS NULL) AND $sysCond $tCond")->fetchColumn();
            } catch (Throwable $e) {}

            $counts['total_pending'] = $counts['pending_fee'] + $counts['pending_leave'] + $counts['pending_complaints'] + $counts['pending_admissions'] + $counts['pending_teacher_leaves'];

            $todayStr = date('Y-m-d');
            $hTable = $isMasjid ? 'maktab_hifz_record' : 'hifz_record';
            try {
                $counts['daily_naaghah'] = (int)$pdo->query("SELECT COUNT(*) FROM $hTable WHERE date = '$todayStr' AND (sabaq_grade IN ('نہیں سنایا', 'ناغہ') OR sabqi_grade IN ('نہیں سنائی', 'ناغہ') OR manzil_grade IN ('نہیں سنائی', 'ناغہ')) AND (is_deleted = 0 OR is_deleted IS NULL) $tCond")->fetchColumn();
            } catch (Throwable $e) {}

        } catch (Throwable $e) {
            error_log("[NotificationBadgeService Error] " . $e->getMessage());
        }

        self::$cachedCounts = $counts;
        return $counts;
    }
}
