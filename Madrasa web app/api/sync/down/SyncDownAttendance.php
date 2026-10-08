<?php
/**
 * Sync Down Attendance & Staff Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncDownAttendance {
    public static function process(SyncContext $ctx, &$data, &$sharedContext) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $userRole = $ctx->userRole;
        $since_date = $ctx->since_date;
        $requested_sys = $ctx->requestedSys;
        $allowed_classes = $ctx->allowedClasses;
        $tId = (int)$ctx->tenantId;
        $tCond = ($tId === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tId";

        try { $pdo->exec("ALTER TABLE attendance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE attendance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE attendance_teachers ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE attendance_teachers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE teacher_period_attendance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE teacher_period_attendance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE teachers ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE teachers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

        $attDeltaCond = $ctx->since_dt
            ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')"
            : ($since_date ? " AND date >= '$since_date'" : "");
        $tpaDeltaCond = $ctx->since_dt
            ? " AND (tpa.updated_at >= '{$ctx->since_dt}' OR tpa.created_at >= '{$ctx->since_dt}')"
            : ($since_date ? " AND tpa.date >= '$since_date'" : "");
        $teacherDeltaCond = $ctx->since_dt
            ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')"
            : " AND (is_deleted = 0 OR is_deleted IS NULL)";

        // Student Attendance
        if ($userRole === 'admin' || $userRole === 'nazim' || $userRole === 'teacher' || has_permission($userRole, $user['permissions'] ?? [], 'manage_attendance', $requested_sys)) {
            if ($userRole === 'admin' || $userRole === 'nazim') {
                $data['attendance_history'] = $ctx->fetchAll("SELECT * FROM attendance WHERE $tCond $attDeltaCond ORDER BY date DESC LIMIT 5000");
            } else {
                $inAllowedStd2 = $sharedContext['inAllowedStd'] ?? null;
                if (!$inAllowedStd2) {
                    $sync_class_names2 = array_column($allowed_classes ?? [], 'name');
                    if (!empty($sync_class_names2)) {
                        $inSyncClasses2 = implode(',', array_map(function($c) use ($pdo) { return $pdo->quote($c); }, $sync_class_names2));
                        $allowedStudentIds2 = array_column($pdo->query("SELECT id FROM students WHERE class IN ($inSyncClasses2) AND $tCond AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC), 'id');
                    } else {
                        $allowedStudentIds2 = [];
                    }
                    $inAllowedStd2 = !empty($allowedStudentIds2) ? implode(',', array_map('intval', $allowedStudentIds2)) : null;
                }
                if ($inAllowedStd2) {
                    $data['attendance_history'] = $ctx->fetchAll("SELECT * FROM attendance WHERE student_id IN ($inAllowedStd2) AND $tCond $attDeltaCond ORDER BY date DESC LIMIT 5000");
                } else {
                    $data['attendance_history'] = [];
                }
            }
        }

        // Staff & Teacher Attendance
        if ($userRole === 'admin' || $userRole === 'nazim' || has_permission($userRole, $user['permissions'] ?? [], 'manage_teachers', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_attendance', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_teacher_attendance', $requested_sys)) {
            if ($userRole === 'admin' || $userRole === 'nazim' || has_permission($userRole, $user['permissions'] ?? [], 'manage_attendance', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_teacher_attendance', $requested_sys)) {
                $data['attendance_teachers'] = $ctx->fetchAll("SELECT * FROM attendance_teachers WHERE $tCond $attDeltaCond ORDER BY date DESC LIMIT 5000");
                $data['teacher_period_attendance'] = $ctx->fetchAll("SELECT tpa.*, c.name AS class_name FROM teacher_period_attendance tpa LEFT JOIN classes c ON c.id = tpa.class_id WHERE (tpa.tenant_id = $tId OR tpa.tenant_id IS NULL) $tpaDeltaCond ORDER BY tpa.date DESC, tpa.id DESC LIMIT 5000");
            }

            // Fetch teachers and add assigned_periods for mobile
            $teachers = [];
            $all_cp = [];
            try {
                $teachers = $pdo->query("SELECT * FROM teachers WHERE $tCond $teacherDeltaCond")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            try {
                $all_cp = $pdo->query("SELECT cp.class_id, cp.period_no, cp.teacher_id, cp.teacher_ids, c.name AS class_name, c.main_teacher_ids FROM class_periods cp JOIN classes c ON c.id = cp.class_id WHERE (c.tenant_id = $tId OR c.tenant_id IS NULL) AND (c.status = 'Active' OR c.status IS NULL OR c.status = '')")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            
            $explicit_map = [];
            foreach ($all_cp as $row) {
                $cid = $row['class_id'];
                $pno = $row['period_no'];
                $tids = [];
                if (!empty($row['teacher_ids'])) {
                    $decoded = json_decode($row['teacher_ids'], true);
                    if (is_array($decoded)) $tids = array_merge($tids, $decoded);
                }
                if (!empty($row['teacher_id'])) $tids[] = $row['teacher_id'];
                foreach ($tids as $tid) {
                    $explicit_map[$cid][$tid][] = $pno;
                }
            }
            
            $teacher_assigned_periods = [];
            foreach ($all_cp as $row) {
                $cid = $row['class_id'];
                $className = $row['class_name'] ?? '';
                $pno = (string)$row['period_no'];
                $tids_for_this_period = [];
                if (!empty($row['teacher_ids'])) {
                    $decoded = json_decode($row['teacher_ids'], true);
                    if (is_array($decoded)) $tids_for_this_period = array_merge($tids_for_this_period, $decoded);
                }
                if (!empty($row['teacher_id'])) $tids_for_this_period[] = $row['teacher_id'];
                if (!empty($row['main_teacher_ids'])) {
                    $m_ids = json_decode($row['main_teacher_ids'], true);
                    if (!is_array($m_ids)) $m_ids = array_filter(explode(',', $row['main_teacher_ids']));
                    foreach ($m_ids as $tid) {
                        $has_explicit = isset($explicit_map[$cid][$tid]) && count($explicit_map[$cid][$tid]) > 0;
                        if (!$has_explicit) {
                            $tids_for_this_period[] = $tid;
                        }
                    }
                }
                foreach (array_unique(array_filter($tids_for_this_period)) as $tid) {
                    if ($className !== '') $teacher_assigned_periods[$tid][$className][] = $pno;
                }
            }
            
            foreach ($teachers as &$t) {
                $tid = $t['id'];
                $profileClasses = sync_list_values($t['assigned_classes'] ?? '');
                $periodMap = sync_decode_teacher_periods($t['assigned_periods'] ?? '');
                foreach (($teacher_assigned_periods[$tid] ?? []) as $className => $periods) {
                    if (!isset($periodMap[$className]) || empty($periodMap[$className])) {
                        $periodMap[$className] = $periods;
                    }
                }
                foreach ($periodMap as $className => $periods) {
                    $periodMap[$className] = array_values(array_unique(array_map('strval', $periods)));
                }
                $classList = array_values(array_unique(array_filter(array_merge($profileClasses, array_keys($periodMap)))));
                $t['assigned_classes'] = implode(',', $classList);
                $t['assigned_periods'] = json_encode($periodMap, JSON_UNESCAPED_UNICODE);
            }
            $data['teachers'] = $teachers;
        }

        // Phase 1: Teacher Leaves & Activity Logs
        try {
            if (function_exists('ensure_phase1_tables')) ensure_phase1_tables($pdo);
            $data['teacher_leaves'] = $ctx->fetchAll("SELECT * FROM teacher_leaves " . ($since_date ? "WHERE updated_at >= '$since_date' OR start_date >= '$since_date'" : "") . " ORDER BY id DESC LIMIT 1000");
        } catch (Throwable $e) {}

        try {
            if (function_exists('ensure_phase1_tables')) ensure_phase1_tables($pdo);
            $data['activity_logs'] = $ctx->fetchAll("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 100");
        } catch (Throwable $e) {}
    }
}
