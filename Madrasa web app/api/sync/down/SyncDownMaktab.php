<?php
/**
 * Sync Down Maktab Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncDownMaktab {
    public static function process(SyncContext $ctx, &$data) {
        if ($ctx->userRole === 'student' || $ctx->userRole === 'parent') {
            return;
        }
        $since_date = $ctx->since_date;
        $since_dt = $ctx->since_dt;
        $tId = (int)$ctx->tenantId;
        $tCond = ($tId === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tId";
        try { $ctx->pdo->exec("ALTER TABLE maktab_students ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $ctx->pdo->exec("ALTER TABLE maktab_students ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $ctx->pdo->exec("ALTER TABLE maktab_teachers ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $ctx->pdo->exec("ALTER TABLE maktab_teachers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

        $maktabDateCond = $since_dt
            ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
            : " AND (is_deleted = 0 OR is_deleted IS NULL)";

        $data['maktab_students'] = array_map('strip_sensitive_row', $ctx->fetchAll("SELECT * FROM maktab_students WHERE $tCond $maktabDateCond ORDER BY id DESC LIMIT 5000"));
        $data['maktab_teachers'] = array_map('strip_sensitive_row', $ctx->fetchAll("SELECT * FROM maktab_teachers WHERE $tCond $maktabDateCond ORDER BY id DESC LIMIT 1000"));
        $data['maktab_classes'] = $ctx->fetchAll("SELECT * FROM maktab_classes WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC LIMIT 500");
        $data['maktab_attendance'] = $ctx->fetchAll("SELECT * FROM period_attendance WHERE $tCond " . ($since_date ? "AND date >= '$since_date'" : "") . " ORDER BY date DESC, id DESC LIMIT 5000");
        $data['maktab_nazira_record'] = $ctx->fetchAll("SELECT * FROM maktab_nazira_record WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC, id DESC LIMIT 5000");
        $data['maktab_hifz_record'] = $ctx->fetchAll("SELECT * FROM maktab_hifz_record WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC, id DESC LIMIT 5000");
        $data['maktab_qaida_records'] = $ctx->fetchAll("SELECT * FROM maktab_qaida_records WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC, id DESC LIMIT 5000");
        $data['maktab_academic_reports'] = $ctx->fetchAll("SELECT * FROM maktab_academic_reports WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC, id DESC LIMIT 5000");

        $userRole = $ctx->userRole;
        $user = $ctx->user;
        $requested_sys = $ctx->requestedSys;
        $hasMaktabExams = ($userRole === 'admin' || has_permission($userRole, $user['permissions'] ?? [], 'manage_exams', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_exams', $requested_sys));
        if ($hasMaktabExams) {
            $data['maktab_exams'] = $ctx->fetchAll("SELECT * FROM maktab_exams WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 500");
            $data['maktab_exam_results'] = $ctx->fetchAll("SELECT * FROM maktab_exam_results WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)" . ($since_dt ? " AND created_at >= '$since_dt'" : "") . " ORDER BY id DESC LIMIT 5000");
        } else {
            $data['maktab_exams'] = [];
            $data['maktab_exam_results'] = [];
        }
    }
}
