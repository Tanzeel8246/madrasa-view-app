<?php
/**
 * Sync Up Attendance & Staff Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncUpAttendance {
    public static function process(SyncContext $ctx, $input, &$syncedIds, &$serverIds, &$syncErrors) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $userRole = $ctx->userRole;
        $allowed_class_names_up = $ctx->allowedClassNames;
        $tId = (int)$ctx->tenantId;

        // Teachers
        if (!empty($input['teachers']) && is_array($input['teachers'])) {
            foreach ($input['teachers'] as $t) {
                $localId = to_int_or_null($t['id'] ?? null);
                try {
                    $name = trim((string)($t['name'] ?? '')); if ($name === '') continue;
                    $cnic = trim((string)($t['cnic'] ?? ''));
                    $savedTeacherId = null;
                    $teacherData = null;
                    
                    if ($localId) {
                        $row = $pdo->prepare("SELECT id, name, father_name, cnic, is_deleted FROM teachers WHERE id = ? AND tenant_id = ? LIMIT 1");
                        $row->execute([$localId, $tId]);
                        $teacherData = $row->fetch();
                        if ($teacherData) {
                            $sameCnic = $cnic !== '' && trim((string)($teacherData['cnic'] ?? '')) === $cnic;
                            $sameName = trim((string)($teacherData['name'] ?? '')) === $name
                                && trim((string)($teacherData['father_name'] ?? '')) === trim((string)($t['father_name'] ?? ''));
                            if (!$sameCnic && !$sameName) {
                                $teacherData = null;
                            }
                        }
                    }
                    
                    if (!$teacherData && !empty($cnic)) {
                        $row = $pdo->prepare("SELECT id, name, father_name, cnic, is_deleted FROM teachers WHERE cnic = ? AND tenant_id = ? LIMIT 1");
                        $row->execute([$cnic, $tId]);
                        $teacherData = $row->fetch();
                    }
                    
                    if (!$teacherData && !empty($name)) {
                        $row = $pdo->prepare("SELECT id, name, father_name, cnic, is_deleted FROM teachers WHERE name = ? AND father_name = ? AND (cnic = '' OR cnic IS NULL) AND tenant_id = ? LIMIT 1");
                        $row->execute([$name, $t['father_name'] ?? '', $tId]);
                        $teacherData = $row->fetch();
                    }

                    if ((int)($t['is_deleted'] ?? 0) === 1) {
                        if ($teacherData) {
                            $pdo->prepare("UPDATE teachers SET is_deleted = 1 WHERE id = ? AND tenant_id = ?")->execute([$teacherData['id'], $tId]);
                            $savedTeacherId = (int)$teacherData['id'];
                        }
                        if ($localId !== null) {
                            $syncedIds['teachers'][] = $localId;
                            if ($savedTeacherId) $serverIds['teachers'][(string)$localId] = $savedTeacherId;
                        }
                        continue;
                    }

                    if ($teacherData) {
                        if ($teacherData['is_deleted'] == 1) {
                            if ($localId !== null) {
                                $syncedIds['teachers'][] = $localId;
                                $serverIds['teachers'][(string)$localId] = (int)$teacherData['id'];
                            }
                            continue;
                        }
                        $existingId = $teacherData['id'];
                        $photoPath = $ctx->uploadImage($t['photo'] ?? '', 'teacher');
                        $stmt = $pdo->prepare("UPDATE teachers SET name=?, father_name=?, phone=?, cnic=?, qualification=?, salary=?, house_rent=?, medical_allowance=?, travel_allowance=?, daily_deduction_rate=?, joining_date=?, status=?, address=?, photo=COALESCE(NULLIF(?, ''), photo), assigned_classes=?, assigned_periods=?, is_deleted=0 WHERE id=? AND tenant_id=?");
                        $stmt->execute([$name, $t['father_name'] ?? '', $t['phone'] ?? '', $cnic, $t['qualification'] ?? '', to_int_or_null($t['salary'] ?? null), to_int_or_null($t['house_rent'] ?? null), to_int_or_null($t['medical_allowance'] ?? null), to_int_or_null($t['travel_allowance'] ?? null), to_int_or_null($t['daily_deduction_rate'] ?? null), normalize_date($t['joining_date'] ?? ''), $t['status'] ?? 'Active', $t['address'] ?? '', $photoPath, $t['assigned_classes'] ?? '', $t['assigned_periods'] ?? '', $existingId, $tId]);
                        $savedTeacherId = (int)$existingId;
                    } else {
                        $photoPath = $ctx->uploadImage($t['photo'] ?? '', 'teacher');
                        $stmt = $pdo->prepare("INSERT INTO teachers (name, father_name, phone, cnic, qualification, salary, house_rent, medical_allowance, travel_allowance, daily_deduction_rate, joining_date, status, address, photo, assigned_classes, assigned_periods, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                        $stmt->execute([$name, $t['father_name'] ?? '', $t['phone'] ?? '', $cnic, $t['qualification'] ?? '', to_int_or_null($t['salary'] ?? null), to_int_or_null($t['house_rent'] ?? null), to_int_or_null($t['medical_allowance'] ?? null), to_int_or_null($t['travel_allowance'] ?? null), to_int_or_null($t['daily_deduction_rate'] ?? null), normalize_date($t['joining_date'] ?? ''), $t['status'] ?? 'Active', $t['address'] ?? '', $photoPath, $t['assigned_classes'] ?? '', $t['assigned_periods'] ?? '', $tId]);
                        $savedTeacherId = (int)$pdo->lastInsertId();
                    }
                    if ($localId !== null) {
                        $syncedIds['teachers'][] = $localId;
                        if ($savedTeacherId) $serverIds['teachers'][(string)$localId] = $savedTeacherId;
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Teacher Sync: " . $e->getMessage());
                    $syncErrors[] = "teachers: " . $e->getMessage();
                }
            }
        }
        
        // Attendance Teachers
        if (!empty($input['attendance_teachers']) && is_array($input['attendance_teachers'])) {
            foreach ($input['attendance_teachers'] as $a) {
                $localId = to_int_or_null($a['id'] ?? null);
                try {
                    $teacher_id = to_int_or_null($a['teacher_id'] ?? null);
                    if (!$teacher_id) continue;
                    
                    $date = normalize_date($a['date'] ?? '');
                    if (empty($date)) continue;

                    $status = $a['status'] ?? 'P';
                    $late = to_int_or_null($a['late_minutes'] ?? null) ?: 0;
                    $remarks = $a['remarks'] ?? '';

                    $stmt = $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), late_minutes = VALUES(late_minutes), remarks = VALUES(remarks)");
                    $stmt->execute([$teacher_id, $date, $status, $late, $remarks, $tId]);
                    if ($localId !== null) $syncedIds['attendance_teachers'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Attendance Teachers Sync: " . $e->getMessage());
                    $syncErrors[] = "attendance_teachers: " . $e->getMessage();
                }
            }
        }
        
        // Student Attendance Entries
        if (!empty($input['attendance_entries']) && is_array($input['attendance_entries'])) {
            foreach ($input['attendance_entries'] as $a) {
                $localId = to_int_or_null($a['id'] ?? null);
                try {
                    $date = normalize_date($a['date'] ?? ''); $studentId = $a['student_id'] ?? null;
                    if (!$date || !$studentId) continue;

                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $aClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                        $aClsStmt->execute([$studentId]);
                        $aCls = $aClsStmt->fetchColumn();
                        if ($aCls && !in_array($aCls, $allowed_class_names_up)) {
                            write_log("Sync Up: attendance_entries - user not allowed for class $aCls");
                            if ($localId !== null) $syncedIds['attendance_entries'][] = $localId;
                            continue;
                        }
                    }

                    $shift = normalize_shift($a['shift'] ?? '1');
                    $statusCol = "status_{$shift}"; $lateCol = "late_{$shift}";
                    $check = $pdo->prepare("SELECT id FROM attendance WHERE date = ? AND student_id = ? AND tenant_id = ?");
                    $check->execute([$date, $studentId, $tId]);
                    if ($check->rowCount() > 0) {
                        $pdo->prepare("UPDATE attendance SET $statusCol = ?, $lateCol = ? WHERE id = ? AND tenant_id = ?")->execute([normalize_status_code($a['status'] ?? 'P'), (int)($a['delay_minutes'] ?? 0), $check->fetchColumn(), $tId]);
                    } else {
                        $pdo->prepare("INSERT INTO attendance (date, student_id, $statusCol, $lateCol, tenant_id) VALUES (?, ?, ?, ?, ?)")->execute([$date, $studentId, normalize_status_code($a['status'] ?? 'P'), (int)($a['delay_minutes'] ?? 0), $tId]);
                    }
                    
                    // Auto SMS Hook for Absent/Leave
                    $statusCode = normalize_status_code($a['status'] ?? 'P');
                    if ($statusCode === 'A' || $statusCode === 'L') {
                        $st = $pdo->prepare("SELECT name, contact_number FROM students WHERE id = ?");
                        $st->execute([$studentId]);
                        $s_data = $st->fetch();
                        if ($s_data && !empty($s_data['contact_number'])) {
                            $statusText = ($statusCode === 'A') ? "غیر حاضر" : "چھٹی پر";
                            $msg = "اطلاع: محترم والدین، آپ کا بچہ " . $s_data['name'] . " آج مدرسہ سے " . $statusText . " ہے۔";
                            try {
                                require_once __DIR__ . '/../../send_sms.php';
                                send_sms($s_data['contact_number'], $msg);
                            } catch (Throwable $e) {
                                write_log("Attendance SMS Error: " . $e->getMessage());
                            }
                        }
                    }

                    if ($localId !== null) $syncedIds['attendance_entries'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Sync (Attendance): " . $e->getMessage());
                    $syncErrors[] = "attendance_entries: " . $e->getMessage();
                }
            }
        }

        // Teacher Period Attendance
        if (!empty($input['teacher_period_attendance']) && is_array($input['teacher_period_attendance'])) {
            foreach ($input['teacher_period_attendance'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $teacherId = to_int_or_null($item['teacher_id'] ?? null);
                    $dateVal = normalize_date($item['date'] ?? '');
                    $periodNo = to_int_or_null($item['period_no'] ?? null);
                    if (!$teacherId || !$dateVal || !$periodNo) continue;
                    $classId = to_int_or_null($item['class_id'] ?? null);
                    $className = trim((string)($item['class_name'] ?? ''));
                    if (!$classId && $className !== '') {
                        $classStmt = $pdo->prepare("SELECT id FROM classes WHERE name = ? AND tenant_id = ? LIMIT 1");
                        $classStmt->execute([$className, $tId]);
                        $classId = to_int_or_null($classStmt->fetchColumn());
                    }
                    if (!$classId) continue;
                    $status = $item['status'] ?? 'P';
                    $late = to_int_or_null($item['late_minutes'] ?? null) ?: 0;
                    $remarks = $item['remarks'] ?? '';
                    $check = $pdo->prepare("SELECT id FROM teacher_period_attendance WHERE teacher_id = ? AND class_id = ? AND date = ? AND period_no = ? AND tenant_id = ? LIMIT 1");
                    $check->execute([$teacherId, $classId, $dateVal, $periodNo, $tId]);
                    $existing = $check->fetchColumn();
                    if ($existing) {
                        $pdo->prepare("UPDATE teacher_period_attendance SET status = ?, late_minutes = ?, remarks = ? WHERE id = ? AND tenant_id = ?")
                            ->execute([$status, $late, $remarks, $existing, $tId]);
                    } else {
                        $pdo->prepare("INSERT INTO teacher_period_attendance (teacher_id, class_id, date, period_no, status, late_minutes, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$teacherId, $classId, $dateVal, $periodNo, $status, $late, $remarks, $tId]);
                    }
                    $daily = $pdo->prepare("SELECT id FROM attendance_teachers WHERE teacher_id = ? AND date = ? AND tenant_id = ? LIMIT 1");
                    $daily->execute([$teacherId, $dateVal, $tId]);
                    $dailyId = $daily->fetchColumn();
                    if ($dailyId) {
                        $pdo->prepare("UPDATE attendance_teachers SET status = ?, late_minutes = GREATEST(COALESCE(late_minutes,0), ?), remarks = ? WHERE id = ? AND tenant_id = ?")
                            ->execute([$status, $late, $remarks, $dailyId, $tId]);
                    } else {
                        $pdo->prepare("INSERT INTO attendance_teachers (teacher_id, date, status, late_minutes, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$teacherId, $dateVal, $status, $late, $remarks, $tId]);
                    }
                    if ($localId !== null) $syncedIds['teacher_period_attendance'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "teacher_period_attendance: " . $e->getMessage(); }
            }
        }

        // Teacher Leaves Sync Up
        if (!empty($input['teacher_leaves']) && is_array($input['teacher_leaves'])) {
            if (function_exists('ensure_phase1_tables')) ensure_phase1_tables($pdo);
            foreach ($input['teacher_leaves'] as $tl) {
                $localId = to_int_or_null($tl['id'] ?? null);
                try {
                    $teacher_id = to_int_or_null($tl['teacher_id'] ?? null);
                    if (!$teacher_id) continue;
                    $leave_type = trim((string)($tl['leave_type'] ?? 'اتفاقی'));
                    $start_date = normalize_date($tl['start_date'] ?? '');
                    $end_date = normalize_date($tl['end_date'] ?? $start_date);
                    $reason = trim((string)($tl['reason'] ?? ''));
                    $status = trim((string)($tl['status'] ?? 'Pending'));
                    $approved_by = $tl['approved_by'] ?? null;
                    $system_type = trim((string)($tl['system_type'] ?? 'Madrasa'));
                    if (empty($system_type)) $system_type = 'Madrasa';
                    $is_deleted = (int)($tl['is_deleted'] ?? 0);

                    if (!$start_date || !$end_date) continue;

                    // Match existing leave by server id or teacher_id + start_date
                    $existingId = null;
                    if (!empty($tl['server_id'])) {
                        $chk = $pdo->prepare("SELECT id FROM teacher_leaves WHERE id = ? AND tenant_id = ? LIMIT 1");
                        $chk->execute([(int)$tl['server_id'], $tId]);
                        $existingId = $chk->fetchColumn();
                    }
                    if (!$existingId && $localId) {
                        $chk = $pdo->prepare("SELECT id FROM teacher_leaves WHERE teacher_id = ? AND start_date = ? AND tenant_id = ? LIMIT 1");
                        $chk->execute([$teacher_id, $start_date, $tId]);
                        $existingId = $chk->fetchColumn();
                    }

                    if ($existingId) {
                        $pdo->prepare("UPDATE teacher_leaves SET leave_type = ?, start_date = ?, end_date = ?, reason = ?, status = ?, approved_by = ?, system_type = ?, is_deleted = ? WHERE id = ? AND tenant_id = ?")
                            ->execute([$leave_type, $start_date, $end_date, $reason, $status, $approved_by, $system_type, $is_deleted, $existingId, $tId]);
                        $savedId = (int)$existingId;
                    } else {
                        $pdo->prepare("INSERT INTO teacher_leaves (teacher_id, leave_type, start_date, end_date, reason, status, approved_by, system_type, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$teacher_id, $leave_type, $start_date, $end_date, $reason, $status, $approved_by, $system_type, $is_deleted, $tId]);
                        $savedId = (int)$pdo->lastInsertId();
                    }

                    if ($status === 'Approved' && function_exists('approve_teacher_leave')) {
                        approve_teacher_leave($pdo, $savedId, 'Approved', $approved_by);
                    }

                    if ($localId !== null) {
                        $syncedIds['teacher_leaves'][] = $localId;
                        if ($savedId) $serverIds['teacher_leaves'][(string)$localId] = $savedId;
                    }
                } catch (Exception $e) {
                    $syncErrors[] = "teacher_leaves: " . $e->getMessage();
                }
            }
        }
    }
}
