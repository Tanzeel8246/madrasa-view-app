<?php
/**
 * Sync Down Students & Academics Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncDownStudents {
    public static function process(SyncContext $ctx, &$data, &$sharedContext) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $userRole = $ctx->userRole;
        $since_date = $ctx->since_date;
        $since_dt = $ctx->since_dt;
        $requested_sys = $ctx->requestedSys;
        $allowed_classes = $ctx->allowedClasses;
        $tId = (int)$ctx->tenantId;
        $tCond = ($tId === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tId";

        if ($userRole === 'student' || $userRole === 'parent') {
            $studentIds = [];
            if ($userRole === 'student') {
                $studentIds[] = (int)$user['id'];
            } else {
                $pid = (int)$user['id'];
                $stStmt = $pdo->prepare("SELECT student_id FROM student_parents WHERE parent_id = ?");
                $stStmt->execute([$pid]);
                $studentIds = $stStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($studentIds)) {
                $data['students'] = [];
                $data['hifz_history'] = [];
                $data['nazira_history'] = [];
                $data['attendance_history'] = [];
                $data['academic_reports'] = [];
                $data['qaida_records'] = [];
                $data['complaints'] = [];
                $data['parent_complaints'] = [];
                $data['leave_applications'] = [];
                $data['fee_submissions'] = [];
                $data['exam_results'] = [];
                $data['husn_saut_record'] = [];
            } else {
                $inClause = implode(',', array_map('intval', $studentIds));
                $data['students'] = array_map('strip_sensitive_row', $ctx->fetchAll("SELECT * FROM students WHERE id IN ($inClause) AND $tCond"));
                $data['hifz_history'] = $ctx->fetchAll("SELECT * FROM daily_hifz WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 5000");
                $data['nazira_history'] = $ctx->fetchAll("SELECT * FROM daily_nazira WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 5000");
                $data['qaida_records'] = $ctx->fetchAll("SELECT * FROM qaida_records WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 500");
                $data['husn_saut_record'] = $ctx->fetchAll("SELECT * FROM husn_saut_record WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 500");
                $data['academic_reports'] = $ctx->fetchAll("SELECT * FROM academic_reports WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 500");
                $data['complaints'] = $ctx->fetchAll("SELECT * FROM complaints WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 500");
                $data['parent_complaints'] = $ctx->fetchAll("SELECT * FROM parent_complaints WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 500");
                $data['leave_applications'] = $ctx->fetchAll("SELECT * FROM leave_applications WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND (created_at >= '$since_dt' OR date >= '$since_date')" : "") . " ORDER BY id DESC LIMIT 500");
                $data['fee_submissions'] = $ctx->fetchAll("SELECT * FROM fee_submissions WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 500");
                $data['exam_results'] = $ctx->fetchAll("SELECT * FROM exam_results WHERE student_id IN ($inClause) AND $tCond" . ($since_date ? " AND created_at >= '$since_dt'" : "") . " ORDER BY id DESC LIMIT 500");
            }
            return;
        }

        // Admin / Nazim / Teacher / Permission based
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        } catch (Throwable $e) {}

        $stdDateCond = $since_dt
            ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
            : " AND (is_deleted = 0 OR is_deleted IS NULL)";

        $allowedStudentIds = [];
        if ($userRole === 'admin' || $userRole === 'nazim' || in_array('manage_students', $user['permissions'] ?? [], true)) {
            $data['students'] = array_map('strip_sensitive_row', $ctx->fetchAll("SELECT * FROM students WHERE $tCond $stdDateCond ORDER BY id DESC LIMIT 10000"));
            $data['complaints'] = $ctx->fetchAll("SELECT * FROM complaints WHERE $tCond " . ($since_date ? "AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 1000");
        } else {
            $allowed_class_names = array_column($data['classes'] ?? [], 'name');
            if (empty($allowed_class_names)) {
                $data['students'] = array_map('strip_sensitive_row', $ctx->fetchAll("SELECT * FROM students WHERE $tCond $stdDateCond ORDER BY id DESC LIMIT 10000"));
                $data['complaints'] = $ctx->fetchAll("SELECT * FROM complaints WHERE $tCond " . ($since_date ? "AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 1000");
            } else {
                $inClasses = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names));
                $data['students'] = array_map('strip_sensitive_row', $ctx->fetchAll("SELECT * FROM students WHERE $tCond AND (class IN ($inClasses) OR class_name IN ($inClasses)) $stdDateCond ORDER BY id DESC LIMIT 10000"));
                $sIds = array_column($data['students'], 'id');
                if (!empty($sIds)) {
                    $inStudentIds = implode(',', array_map('intval', $sIds));
                    $data['complaints'] = $ctx->fetchAll("SELECT * FROM complaints WHERE student_id IN ($inStudentIds) AND $tCond" . ($since_date ? " AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 1000");
                    $allowedStudentIds = $sIds;
                } else {
                    $data['complaints'] = [];
                }
            }
        }

        // Exams and Results
        $hasExams = ($userRole === 'admin' || has_permission($userRole, $user['permissions'] ?? [], 'manage_exams', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_exams', $requested_sys));
        if ($hasExams) {
            $data['exams'] = $ctx->fetchAll("SELECT * FROM exams WHERE $tCond " . ($since_date ? "AND exam_date >= '$since_date'" : "") . " ORDER BY exam_date DESC, id DESC LIMIT 1000");
            if ($userRole === 'admin' || $userRole === 'nazim') {
                $data['exam_results'] = $ctx->fetchAll("SELECT * FROM exam_results WHERE $tCond " . ($since_dt ? "AND created_at >= '$since_dt'" : "") . " ORDER BY id DESC LIMIT 5000");
            } else {
                $inAllowedStdExams = !empty($allowedStudentIds) ? implode(',', array_map('intval', $allowedStudentIds)) : null;
                if (!$inAllowedStdExams) {
                    $allowed_class_names_ex = array_column($allowed_classes ?? [], 'name');
                    if (!empty($allowed_class_names_ex)) {
                        $inCEx = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $allowed_class_names_ex));
                        $exStdIds = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inCEx) AND $tCond AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                        $inAllowedStdExams = !empty($exStdIds) ? implode(',', array_map('intval', $exStdIds)) : null;
                    }
                }
                if ($inAllowedStdExams) {
                    $data['exam_results'] = $ctx->fetchAll("SELECT * FROM exam_results WHERE student_id IN ($inAllowedStdExams) AND $tCond" . ($since_dt ? " AND created_at >= '$since_dt'" : "") . " ORDER BY id DESC LIMIT 5000");
                } else {
                    $data['exam_results'] = [];
                }
            }
        } else {
            $data['exams'] = [];
            $data['exam_results'] = [];
        }

        // Requests & Complaints
        if ($userRole === 'admin' || $userRole === 'nazim' || has_permission($userRole, $user['permissions'] ?? [], 'manage_requests', $requested_sys)) {
            $data['parent_complaints'] = $ctx->fetchAll("SELECT * FROM parent_complaints WHERE $tCond " . ($since_date ? "AND date >= '$since_date'" : "") . " ORDER BY date DESC LIMIT 300");
            $data['leave_applications'] = $ctx->fetchAll("SELECT * FROM leave_applications WHERE $tCond " . ($since_date ? "AND (created_at >= '$since_dt' OR date >= '$since_date')" : "") . " ORDER BY id DESC LIMIT 300");
        }

        // Hifz & Nazira History
        try { $pdo->exec("ALTER TABLE daily_hifz ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE daily_hifz ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE daily_nazira ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE daily_nazira ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE academic_reports ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE academic_reports ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE qaida_records ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE husn_saut_record ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE husn_saut_record ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

        $hifzDeltaCond = $since_dt
            ? " AND (updated_at >= '$since_dt' OR created_at >= '$since_dt')"
            : ($since_date ? " AND date >= '$since_date'" : "");

        if ($userRole === 'admin' || $userRole === 'nazim' || $userRole === 'teacher' || has_permission($userRole, $user['permissions'] ?? [], 'manage_hifz', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_academics', $requested_sys)) {
            if ($userRole === 'admin' || $userRole === 'nazim') {
                $data['hifz_history'] = $ctx->fetchAll("SELECT * FROM daily_hifz WHERE $tCond $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
                $data['nazira_history'] = $ctx->fetchAll("SELECT * FROM daily_nazira WHERE $tCond $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
            } else {
                $sync_class_names = array_column($allowed_classes ?? [], 'name');
                if (!empty($sync_class_names)) {
                    $inSyncClasses = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names));
                    $allowedStudentIdsHifz = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClasses) AND $tCond AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                } else {
                    $allowedStudentIdsHifz = [];
                }
                if (!empty($allowedStudentIdsHifz)) {
                    $inAllowedStd = implode(',', array_map('intval', $allowedStudentIdsHifz));
                    $data['hifz_history'] = $ctx->fetchAll("SELECT * FROM daily_hifz WHERE student_id IN ($inAllowedStd) AND $tCond $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
                    $data['nazira_history'] = $ctx->fetchAll("SELECT * FROM daily_nazira WHERE student_id IN ($inAllowedStd) AND $tCond $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
                    $sharedContext['inAllowedStd'] = $inAllowedStd;
                } else {
                    $data['hifz_history'] = [];
                    $data['nazira_history'] = [];
                }
            }
        }

        // Academic Reports
        if ($userRole === 'admin' || $userRole === 'nazim' || $userRole === 'teacher' || has_permission($userRole, $user['permissions'] ?? [], 'manage_hifz', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_academics', $requested_sys)) {
            if ($userRole === 'admin' || $userRole === 'nazim') {
                $data['academic_reports'] = $ctx->fetchAll("SELECT * FROM academic_reports WHERE $tCond $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
            } else {
                $inAllowedStdFinal = $sharedContext['inAllowedStd'] ?? null;
                if ($inAllowedStdFinal) {
                    $data['academic_reports'] = $ctx->fetchAll("SELECT * FROM academic_reports WHERE student_id IN ($inAllowedStdFinal) AND $tCond $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
                } else {
                    $data['academic_reports'] = [];
                }
            }
        }

        // Qaida Records
        if ($userRole === 'admin' || $userRole === 'nazim' || $userRole === 'teacher' || has_permission($userRole, $user['permissions'] ?? [], 'manage_hifz', $requested_sys)) {
            if ($userRole === 'admin' || $userRole === 'nazim') {
                $data['qaida_records'] = $ctx->fetchAll("SELECT * FROM qaida_records WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
            } else {
                $inAllowedStdQaida = $sharedContext['inAllowedStd'] ?? null;
                if (!$inAllowedStdQaida) {
                    $sync_class_names_q = array_column($allowed_classes ?? [], 'name');
                    if (!empty($sync_class_names_q)) {
                        $inSyncClassesQ = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names_q));
                        $allowedStudentIdsQ = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClassesQ) AND $tCond AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                        $inAllowedStdQaida = !empty($allowedStudentIdsQ) ? implode(',', array_map('intval', $allowedStudentIdsQ)) : null;
                    }
                }
                if ($inAllowedStdQaida) {
                    $data['qaida_records'] = $ctx->fetchAll("SELECT * FROM qaida_records WHERE student_id IN ($inAllowedStdQaida) AND $tCond AND (is_deleted = 0 OR is_deleted IS NULL) $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
                } else {
                    $data['qaida_records'] = [];
                }
            }
        }

        // Husn-e-Saut Records
        if ($userRole === 'admin' || $userRole === 'nazim' || $userRole === 'teacher' || has_permission($userRole, $user['permissions'] ?? [], 'manage_hifz', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_academics', $requested_sys)) {
            if ($userRole === 'admin' || $userRole === 'nazim') {
                $data['husn_saut_record'] = $ctx->fetchAll("SELECT * FROM husn_saut_record WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
            } else {
                $inAllowedStdHusn = $sharedContext['inAllowedStd'] ?? null;
                if (!$inAllowedStdHusn) {
                    $sync_class_names_h = array_column($allowed_classes ?? [], 'name');
                    if (!empty($sync_class_names_h)) {
                        $inSyncClassesH = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names_h));
                        $allowedStudentIdsH = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClassesH) AND $tCond AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                        $inAllowedStdHusn = !empty($allowedStudentIdsH) ? implode(',', array_map('intval', $allowedStudentIdsH)) : null;
                    }
                }
                if ($inAllowedStdHusn) {
                    $data['husn_saut_record'] = $ctx->fetchAll("SELECT * FROM husn_saut_record WHERE student_id IN ($inAllowedStdHusn) AND $tCond AND (is_deleted = 0 OR is_deleted IS NULL) $hifzDeltaCond ORDER BY date DESC LIMIT 5000");
                } else {
                    $data['husn_saut_record'] = [];
                }
            }
        }
    }
}
