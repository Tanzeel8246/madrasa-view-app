<?php
/**
 * Sync Up Students & Academics Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncUpStudents {
    public static function process(SyncContext $ctx, $input, &$syncedIds, &$serverIds, &$syncErrors) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $userRole = $ctx->userRole;
        $allowed_class_names_up = $ctx->allowedClassNames;
        $tId = (int)$ctx->tenantId;

        // Hifz
        if (!empty($input['hifz_entries']) && is_array($input['hifz_entries'])) {
            foreach ($input['hifz_entries'] as $h) {
                $localId = to_int_or_null($h['id'] ?? null);
                try {
                    $date = normalize_date($h['date'] ?? ''); $studentId = $h['student_id'] ?? null;
                    if (!$date || !$studentId) continue;

                    $isAdminOrNazim = in_array(strtolower($userRole), ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'], true);
                    if (!$isAdminOrNazim) {
                        $hClsStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(class), ''), TRIM(class_name), '') FROM students WHERE id = ? LIMIT 1");
                        $hClsStmt->execute([$studentId]);
                        $hCls = trim((string)$hClsStmt->fetchColumn());
                        $normAllowed = array_map(function($c) { return trim(mb_strtolower((string)$c, 'UTF-8')); }, $allowed_class_names_up);
                        $isAllowed = empty($allowed_class_names_up) || in_array(mb_strtolower($hCls, 'UTF-8'), $normAllowed, true);
                        if ($hCls && !$isAllowed) {
                            write_log("Sync Up: hifz_entries - user not allowed for student $studentId in class $hCls");
                            $syncErrors[] = "Sync Up: hifz_entries - user not allowed for student $studentId in class $hCls";
                            continue;
                        }
                    }

                    $sabaq_status = map_grade($h['sabaq_grade'] ?? $h['sabaq_status'] ?? '');
                    $sabqi_status = map_grade($h['sabqi_grade'] ?? $h['sabqi_status'] ?? '');
                    $manzil_status = map_grade($h['manzil_grade'] ?? $h['manzil_status'] ?? '');

                    $sabaq_parah = $h['sabaq_para'] ?? $h['sabaq_parah'] ?? '';
                    $sabaq_lines = $h['sabaq_lines'] ?? '';
                    $sabqi_parah = $h['sabqi_para'] ?? $h['sabqi_parah'] ?? '';
                    $sabqi_amount = $h['sabqi_qty'] ?? $h['sabqi_amount'] ?? '';
                    $sabqi_listener = $h['sabqi_listener'] ?? '';
                    $manzil_parah = $h['manzil_details'] ?? $h['manzil_parah'] ?? '';
                    $manzil_amount = $h['manzil_total_qty'] ?? $h['manzil_amount'] ?? '';
                    $manzil_listener = $h['manzil_listener'] ?? '';
                    $sabaq_time = $h['sabaq_time'] ?? '';
                    $sabqi_time = $h['sabqi_time'] ?? '';
                    $manzil_time = $h['manzil_time'] ?? '';

                    $check = $pdo->prepare("SELECT * FROM daily_hifz WHERE date = ? AND student_id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                    $check->execute([$date, $studentId, $tId]);
                    $existingHifz = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$existingHifz) {
                        $stmt = $pdo->prepare("INSERT INTO daily_hifz (date, student_id, sabaq_parah, sabaq_lines, sabaq_status, sabqi_parah, sabqi_amount, sabqi_status, sabqi_listener, manzil_parah, manzil_amount, manzil_status, manzil_listener, sabaq_time, sabqi_time, manzil_time, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $res = $stmt->execute([$date, $studentId, $sabaq_parah, $sabaq_lines, $sabaq_status, $sabqi_parah, $sabqi_amount, $sabqi_status, $sabqi_listener, $manzil_parah, $manzil_amount, $manzil_status, $manzil_listener, $sabaq_time, $sabqi_time, $manzil_time, $tId]);
                        write_log("Hifz Insert Result: " . ($res ? "Success" : "Fail"));
                    } else {
                        $keepOld = function($newValue, $oldValue) {
                            return trim((string)$newValue) !== '' ? $newValue : $oldValue;
                        };
                        $stmt = $pdo->prepare("UPDATE daily_hifz SET sabaq_parah=?, sabaq_lines=?, sabaq_status=?, sabqi_parah=?, sabqi_amount=?, sabqi_status=?, sabqi_listener=?, manzil_parah=?, manzil_amount=?, manzil_status=?, manzil_listener=?, sabaq_time=?, sabqi_time=?, manzil_time=?, tenant_id=? WHERE id=?");
                        $res = $stmt->execute([
                            $keepOld($sabaq_parah, $existingHifz['sabaq_parah'] ?? ''),
                            $keepOld($sabaq_lines, $existingHifz['sabaq_lines'] ?? ''),
                            $keepOld($sabaq_status, $existingHifz['sabaq_status'] ?? ''),
                            $keepOld($sabqi_parah, $existingHifz['sabqi_parah'] ?? ''),
                            $keepOld($sabqi_amount, $existingHifz['sabqi_amount'] ?? ''),
                            $keepOld($sabqi_status, $existingHifz['sabqi_status'] ?? ''),
                            $keepOld($sabqi_listener, $existingHifz['sabqi_listener'] ?? ''),
                            $keepOld($manzil_parah, $existingHifz['manzil_parah'] ?? ''),
                            $keepOld($manzil_amount, $existingHifz['manzil_amount'] ?? ''),
                            $keepOld($manzil_status, $existingHifz['manzil_status'] ?? ''),
                            $keepOld($manzil_listener, $existingHifz['manzil_listener'] ?? ''),
                            $keepOld($sabaq_time, $existingHifz['sabaq_time'] ?? ''),
                            $keepOld($sabqi_time, $existingHifz['sabqi_time'] ?? ''),
                            $keepOld($manzil_time, $existingHifz['manzil_time'] ?? ''),
                            $tId,
                            $existingHifz['id'],
                        ]);
                        write_log("Hifz Update Result: " . ($res ? "Success" : "Fail"));
                    }
                    if ($localId !== null) $syncedIds['hifz_entries'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Sync: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }

        // Nazira
        if (!empty($input['nazira_record'])) {
            foreach ($input['nazira_record'] as $row) {
                $localId = to_int_or_null($row['id'] ?? null);
                $date = normalize_date($row['date'] ?? '');
                $student_id = $row['student_id'] ?? null;
                if (!$date || !$student_id) continue;

                $isAdminOrNazim = in_array(strtolower($userRole), ['admin', 'superadmin', 'super_admin', 'administrator', 'nazim'], true);
                if (!$isAdminOrNazim) {
                    $nClsStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(class), ''), TRIM(class_name), '') FROM students WHERE id = ? LIMIT 1");
                    $nClsStmt->execute([$student_id]);
                    $nCls = trim((string)$nClsStmt->fetchColumn());
                    $normAllowed = array_map(function($c) { return trim(mb_strtolower((string)$c, 'UTF-8')); }, $allowed_class_names_up);
                    $isAllowed = empty($allowed_class_names_up) || in_array(mb_strtolower($nCls, 'UTF-8'), $normAllowed, true);
                    if ($nCls && !$isAllowed) {
                        write_log("Sync Up: nazira_record - user not allowed for student $student_id in class $nCls");
                        $syncErrors[] = "Sync Up: nazira_record - user not allowed for student $student_id in class $nCls";
                        continue;
                    }
                }
                $check = $pdo->prepare("SELECT * FROM daily_nazira WHERE date = ? AND student_id = ?");
                $check->execute([$date, $student_id]);
                $ex = $check->fetch(PDO::FETCH_ASSOC);
                if (!$ex) {
                    $stmt = $pdo->prepare("INSERT INTO daily_nazira (date, student_id, morning_sabaq, morning_qty, morning_time, morning_status, evening_sabaq, evening_qty, evening_time, evening_status, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$date, $student_id, $row['morning_sabaq'], $row['morning_qty'] ?? '', $row['morning_time'] ?? '', $row['morning_status'], $row['evening_sabaq'], $row['evening_qty'] ?? '', $row['evening_time'] ?? '', $row['evening_status'], $tId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE daily_nazira SET morning_sabaq=?, morning_qty=?, morning_time=?, morning_status=?, evening_sabaq=?, evening_qty=?, evening_time=?, evening_status=? WHERE id=?");
                    $stmt->execute([
                        trim($row['morning_sabaq'] ?? '') !== '' ? $row['morning_sabaq'] : $ex['morning_sabaq'],
                        isset($row['morning_qty']) ? $row['morning_qty'] : $ex['morning_qty'],
                        isset($row['morning_time']) ? $row['morning_time'] : $ex['morning_time'],
                        trim($row['morning_status'] ?? '') !== '' ? $row['morning_status'] : $ex['morning_status'],
                        trim($row['evening_sabaq'] ?? '') !== '' ? $row['evening_sabaq'] : $ex['evening_sabaq'],
                        isset($row['evening_qty']) ? $row['evening_qty'] : $ex['evening_qty'],
                        isset($row['evening_time']) ? $row['evening_time'] : $ex['evening_time'],
                        trim($row['evening_status'] ?? '') !== '' ? $row['evening_status'] : $ex['evening_status'],
                        $ex['id']
                    ]);
                }
                if ($localId !== null) $syncedIds['nazira_record'][] = $localId;
            }
        }

        // Academic Reports
        if (!empty($input['academic_reports']) && is_array($input['academic_reports'])) {
            foreach ($input['academic_reports'] as $ar) {
                $localId = to_int_or_null($ar['id'] ?? null);
                try {
                    $date = normalize_date($ar['date'] ?? '');
                    $studentId = $ar['student_id'] ?? null;
                    if (!$date || !$studentId) continue;

                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $arCls = $ar['class_name'] ?? '';
                        if ($arCls === '') {
                            $arClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                            $arClsStmt->execute([$studentId]);
                            $arCls = $arClsStmt->fetchColumn() ?: '';
                        }
                        if ($arCls && !in_array($arCls, $allowed_class_names_up)) {
                            write_log("Sync Up: academic_reports - user not allowed for class $arCls");
                            if ($localId !== null) $syncedIds['academic_reports'][] = $localId;
                            continue;
                        }
                    }
                    
                    $check = $pdo->prepare("SELECT id FROM academic_reports WHERE student_id = ? AND date = ? AND subject = ?");
                    $check->execute([$studentId, $date, $ar['subject'] ?? '']);
                    $existingAr = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$existingAr) {
                        $stmt = $pdo->prepare("INSERT INTO academic_reports (student_id, date, class_name, subject, lesson_details, grade, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$studentId, $date, $ar['class_name'] ?? '', $ar['subject'] ?? '', $ar['lesson_details'] ?? '', $ar['grade'] ?? '', $ar['remarks'] ?? '', $tId]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE academic_reports SET class_name=?, lesson_details=?, grade=?, remarks=? WHERE id=?");
                        $stmt->execute([$ar['class_name'] ?? '', $ar['lesson_details'] ?? '', $ar['grade'] ?? '', $ar['remarks'] ?? '', $existingAr['id']]);
                    }
                    if ($localId !== null) $syncedIds['academic_reports'][] = $localId;
                } catch (Exception $e) {
                    write_log("SQL Error in Sync Academic Reports: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }

        // Students
        if (!empty($input['students']) && is_array($input['students'])) {
            foreach ($input['students'] as $s) {
                $localId = to_int_or_null($s['id'] ?? null);
                try {
                    $name = trim((string)($s['name'] ?? '')); if ($name === '') continue;
                    $sClass = $s['class_name'] ?? $s['class'] ?? '';
                    if ($userRole !== 'admin' && !in_array($sClass, $allowed_class_names_up)) {
                        write_log("Sync Up: User not allowed to update/insert student for class $sClass.");
                        if ($localId !== null) $syncedIds['students'][] = $localId;
                        continue;
                    }
                    $admissionNo = trim((string)($s['admission_number'] ?? ($s['roll_number'] ?? '')));
                    $isDel = (int)($s['is_deleted'] ?? 0);
                    $newStatus = trim((string)($s['status'] ?? 'Active'));
                    if ($isDel === 1 || strtolower($newStatus) === 'deleted') {
                        $delStmt = $pdo->prepare("UPDATE students SET is_deleted=1, status='Deleted' WHERE admission_number=? OR id=?");
                        $delStmt->execute([$admissionNo, $localId]);
                        write_log("Sync Up: Student deleted on server ($admissionNo / Local ID: $localId)");
                        if ($localId !== null) $syncedIds['students'][] = $localId;
                        continue;
                    }

                    $stmtCheck = $pdo->prepare("SELECT id, is_deleted FROM students WHERE admission_number = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                    $stmtCheck->execute([$admissionNo, $tId]);
                    $row = $admissionNo ? $stmtCheck->fetch() : null;
                    $savedStudentId = null;
                    if ($row) {
                        if ($row['is_deleted'] == 1) {
                            write_log("Sync Up: Student $admissionNo is deleted on server. Skipping.");
                            if ($localId !== null) $syncedIds['students'][] = $localId;
                            continue;
                        }
                        $existingId = (int)$row['id'];
                        $savedStudentId = $existingId;
                        $rawPass = $s['password'] ?? '';
                        $finalPass = (strlen($rawPass) > 0 && strlen($rawPass) < 20) ? hash_pass($rawPass) : $rawPass;
                        $photoPath = $ctx->uploadImage($s['photo'] ?? '', 'std');
                        $residence_status = $s['residence_status'] ?? '';
                        $sysType = trim((string)($s['system_type'] ?? 'Madrasa'));
                        if ($sysType === '') $sysType = 'Madrasa';
                        $alumni_category = !empty($s['alumni_category']) ? $s['alumni_category'] : ($newStatus === 'Alumni' ? 'completed' : null);
                        $wifaq_exam = !empty($s['wifaq_exam']) ? 1 : 0;
                        $wifaq_marks = $s['wifaq_marks'] ?? null;
                        $mehrab = $s['mehrab_recitation'] ?? null;
                        $hifz_section = !empty($s['hifz_section']) ? $s['hifz_section'] : 'regular';
                        
                        $stmt = $pdo->prepare("UPDATE students SET name=?, father_name=?, admission_number=?, class=?, contact_number=?, address=?, password=COALESCE(NULLIF(?, ''), password), status=?, admission_date=?, graduation_date=?, duration=?, leaving_reason=?, photo=COALESCE(NULLIF(?, ''), photo), residence_status=?, system_type=?, alumni_category=?, wifaq_exam=?, wifaq_marks=?, mehrab_recitation=?, hifz_section=?, is_deleted=0 WHERE id=?");
                        $res = $stmt->execute([$name, $s['father_name'] ?? '', $admissionNo, $s['class_name'] ?? $s['class'] ?? '', $s['contact_number'] ?? $s['mobile_no'] ?? '', $s['address'] ?? '', $finalPass, $newStatus, normalize_date($s['admission_date'] ?? ''), normalize_date($s['graduation_date'] ?? ''), $s['duration'] ?? '', $s['leaving_reason'] ?? '', $photoPath, $residence_status, $sysType, $alumni_category, $wifaq_exam, $wifaq_marks, $mehrab, $hifz_section, $existingId]);
                        write_log("Student Update Result ($admissionNo / Status: $newStatus / System: $sysType): " . ($res ? "Success" : "Fail"));
                    } else {
                        $rawPass = $s['password'] ?? '';
                        $finalPass = (strlen($rawPass) > 0 && strlen($rawPass) < 20) ? hash_pass($rawPass) : $rawPass;
                        $photoPath = $ctx->uploadImage($s['photo'] ?? '', 'std');
                        $residence_status = $s['residence_status'] ?? '';
                        $sysType = trim((string)($s['system_type'] ?? 'Madrasa'));
                        if ($sysType === '') $sysType = 'Madrasa';
                        $alumni_category = !empty($s['alumni_category']) ? $s['alumni_category'] : null;
                        $wifaq_exam = !empty($s['wifaq_exam']) ? 1 : 0;
                        $wifaq_marks = $s['wifaq_marks'] ?? null;
                        $mehrab = $s['mehrab_recitation'] ?? null;
                        $hifz_section = !empty($s['hifz_section']) ? $s['hifz_section'] : 'regular';
                        
                        $stmt = $pdo->prepare("INSERT INTO students (name, father_name, admission_number, class, contact_number, address, password, status, admission_date, graduation_date, duration, photo, residence_status, system_type, alumni_category, wifaq_exam, wifaq_marks, mehrab_recitation, hifz_section, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                        $res = $stmt->execute([$name, $s['father_name'] ?? '', $admissionNo, $s['class_name'] ?? $s['class'] ?? '', $s['contact_number'] ?? $s['mobile_no'] ?? '', $s['address'] ?? '', $finalPass, normalize_date($s['admission_date'] ?? ''), normalize_date($s['graduation_date'] ?? ''), $s['duration'] ?? '', $photoPath, $residence_status, $sysType, $alumni_category, $wifaq_exam, $wifaq_marks, $mehrab, $hifz_section, $tId]);
                        $savedStudentId = (int)$pdo->lastInsertId();
                        write_log("Student Insert Result ($admissionNo / System: $sysType): " . ($res ? "Success (New ID: $savedStudentId)" : "Fail"));
                    }
                    if ($localId !== null) {
                        $syncedIds['students'][] = $localId;
                        if ($savedStudentId) {
                            $serverIds['students'][(string)$localId] = $savedStudentId;
                        }
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Student Sync: " . $e->getMessage());
                    $syncErrors[] = $e->getMessage();
                }
            }
        }

        // Complaints
        if (!empty($input['complaints']) && is_array($input['complaints'])) {
            foreach ($input['complaints'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $check = $pdo->prepare("SELECT id FROM complaints WHERE student_id = ? AND date = ? AND complaint = ?");
                    $check->execute([$item['student_id'], normalize_date($item['date']), $item['complaint']]);
                    if ($check->rowCount() == 0) {
                        $pdo->prepare("INSERT INTO complaints (student_id, date, complaint, tenant_id) VALUES (?, ?, ?, ?)")->execute([$item['student_id'], normalize_date($item['date']), $item['complaint'], $tId]);
                    }
                    if ($localId !== null) $syncedIds['complaints'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "complaints: " . $e->getMessage(); }
            }
        }

        // Parent Complaints
        if (!empty($input['parent_complaints']) && is_array($input['parent_complaints'])) {
            foreach ($input['parent_complaints'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $ev = $ctx->uploadImage($item['evidence'] ?? '', 'complaint');
                    if ((int)($item['is_deleted'] ?? 0) === 1) {
                        $serverId = to_int_or_null($item['id'] ?? null);
                        if ($serverId) {
                            $pdo->prepare("DELETE FROM parent_complaints WHERE id = ?")->execute([$serverId]);
                        }
                        if ($localId !== null) $syncedIds['parent_complaints'][] = $localId;
                        continue;
                    }
                    $check = $pdo->prepare("SELECT id FROM parent_complaints WHERE student_id = ? AND date = ? AND description = ?");
                    $check->execute([$item['student_id'], normalize_date($item['date']), $item['description']]);
                    $existing = $check->fetch();
                    if (!$existing) {
                        $pdo->prepare("INSERT INTO parent_complaints (student_id, date, category, description, evidence, status, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$item['student_id'], normalize_date($item['date']), $item['category'] ?? '', $item['description'], $ev, $item['status'] ?? 'Pending', $tId]);
                    } else {
                        $pdo->prepare("UPDATE parent_complaints SET status = ? WHERE id = ?")->execute([$item['status'] ?? 'Pending', $existing['id']]);
                    }
                    if ($localId !== null) $syncedIds['parent_complaints'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "parent_complaints: " . $e->getMessage(); }
            }
        }

        // Leave Applications
        if (!empty($input['leave_applications']) && is_array($input['leave_applications'])) {
            foreach ($input['leave_applications'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $photo = $ctx->uploadImage($item['application_photo'] ?? '', 'leave');
                    if ((int)($item['is_deleted'] ?? 0) === 1) {
                        $serverId = to_int_or_null($item['id'] ?? null);
                        if ($serverId) {
                            $pdo->prepare("DELETE FROM leave_applications WHERE id = ?")->execute([$serverId]);
                        }
                        if ($localId !== null) $syncedIds['leave_applications'][] = $localId;
                        continue;
                    }
                    $serverId = to_int_or_null($item['id'] ?? null);
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM leave_applications WHERE id = ? LIMIT 1");
                        $check->execute([$serverId]);
                    } else {
                        $check = $pdo->prepare("SELECT id FROM leave_applications WHERE student_id = ? AND date_from = ? AND date_to = ? AND reason = ? LIMIT 1");
                        $check->execute([$item['student_id'], $item['date_from'], $item['date_to'], $item['reason'] ?? '']);
                    }
                    $existingId = $check->fetchColumn();
                    if ($existingId) {
                        $pdo->prepare("UPDATE leave_applications SET date_from = ?, date_to = ?, reason = ?, application_photo = COALESCE(NULLIF(?, ''), application_photo), status = ? WHERE id = ?")
                            ->execute([$item['date_from'], $item['date_to'], $item['reason'], $photo, $item['status'] ?? 'Pending', $existingId]);
                    } else {
                        $pdo->prepare("INSERT INTO leave_applications (student_id, date_from, date_to, reason, application_photo, status, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$item['student_id'], $item['date_from'], $item['date_to'], $item['reason'], $photo, $item['status'] ?? 'Pending', $tId]);
                    }
                    if ($localId !== null) $syncedIds['leave_applications'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "leave_applications: " . $e->getMessage(); }
            }
        }

        // Fee Submissions
        if (!empty($input['fee_submissions']) && is_array($input['fee_submissions'])) {
            foreach ($input['fee_submissions'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $shot = $ctx->uploadImage($item['screenshot'] ?? '', 'fee');
                    if ((int)($item['is_deleted'] ?? 0) === 1) {
                        $serverId = to_int_or_null($item['id'] ?? null);
                        if ($serverId) {
                            $pdo->prepare("DELETE FROM fee_submissions WHERE id = ?")->execute([$serverId]);
                        }
                        if ($localId !== null) $syncedIds['fee_submissions'][] = $localId;
                        continue;
                    }
                    $serverId = to_int_or_null($item['id'] ?? null);
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id, finance_id FROM fee_submissions WHERE id = ? LIMIT 1");
                        $check->execute([$serverId]);
                    } else {
                        $check = $pdo->prepare("SELECT id, finance_id FROM fee_submissions WHERE student_id = ? AND amount = ? AND submission_date = ? LIMIT 1");
                        $check->execute([$item['student_id'], $item['amount'], $item['submission_date']]);
                    }
                    $existing = $check->fetch(PDO::FETCH_ASSOC);
                    $financeId = to_int_or_null($existing['finance_id'] ?? null);
                    $status = $item['status'] ?? 'Pending';

                    if ($status === 'Approved' && !$financeId) {
                        $studentStmt = $pdo->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
                        $studentStmt->execute([$item['student_id']]);
                        $studentName = $studentStmt->fetchColumn() ?: '';
                        $desc = !empty($item['description']) ? $item['description'] . " (تعاؤن ID: " . ($existing['id'] ?? 'mobile') . ")" : "Online Cooperation/Fee";
                        
                        $adminName = 'Admin';
                        $adminId = 1;
                        if (!empty($user['id'])) {
                            $adminId = (int)$user['id'];
                            $adminStmt = $pdo->prepare("SELECT name FROM admins WHERE id = ? LIMIT 1");
                            $adminStmt->execute([$adminId]);
                            $adminName = $adminStmt->fetchColumn() ?: 'Admin';
                        }
                        $compNo = next_user_receipt_no($pdo, $adminId);

                        $stmt = $pdo->prepare("INSERT INTO finance (date, type, amount, description, donor_name, head_id, computer_receipt_no, user_id, user_name, tenant_id) VALUES (?, 'Income', ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([date('Y-m-d'), $item['amount'], $desc, $studentName, to_int_or_null($item['head_id'] ?? null), $compNo, $adminId, $adminName, $tId]);
                        $financeId = (int)$pdo->lastInsertId();
                        
                        require_once __DIR__ . '/../../finance_accounting_bridge.php';
                        finance_accounting_post($pdo, $financeId);
                    }

                    if ($existing) {
                        $pdo->prepare("UPDATE fee_submissions SET amount = ?, submission_date = ?, screenshot = COALESCE(NULLIF(?, ''), screenshot), status = ?, head_id = ?, description = ?, finance_id = ? WHERE id = ?")
                            ->execute([$item['amount'], $item['submission_date'], $shot, $status, to_int_or_null($item['head_id'] ?? null), $item['description'] ?? '', $financeId, $existing['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO fee_submissions (student_id, amount, submission_date, screenshot, status, head_id, description, finance_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$item['student_id'], $item['amount'], $item['submission_date'], $shot, $status, to_int_or_null($item['head_id'] ?? null), $item['description'] ?? '', $financeId, $tId]);
                    }
                    if ($localId !== null) $syncedIds['fee_submissions'][] = $localId;
                    
                    // Auto SMS Hook for Fee Received
                    $st = $pdo->prepare("SELECT name, contact_number FROM students WHERE id = ?");
                    $st->execute([$item['student_id']]);
                    $s_data = $st->fetch();
                    if ($status === 'Approved' && $s_data && !empty($s_data['contact_number'])) {
                        $msg = "اطلاع: محترم والدین، آپ کے بچے " . $s_data['name'] . " کی فیس مبلغ " . $item['amount'] . " روپے موصول ہو گئی ہے۔ شکریہ۔";
                        try {
                            require_once __DIR__ . '/../../send_sms.php';
                            send_sms($s_data['contact_number'], $msg);
                        } catch (Throwable $e) {
                            write_log("Fee SMS Error: " . $e->getMessage());
                        }
                    }
                } catch (Exception $e) { $syncErrors[] = "fee_submissions: " . $e->getMessage(); }
            }
        }

        // Exams
        if (!empty($input['exams']) && is_array($input['exams'])) {
            foreach ($input['exams'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $sys = $item['system_type'] ?? 'Madrasa';
                    $check = $pdo->prepare("SELECT id FROM exams WHERE exam_name = ? AND exam_date = ? AND (system_type = ? OR (system_type IS NULL AND ? = 'Madrasa'))");
                    $check->execute([$item['exam_name'], normalize_date($item['exam_date']), $sys, $sys]);
                    if ($check->rowCount() > 0) {
                        $existingId = $check->fetchColumn();
                        $pdo->prepare("UPDATE exams SET total_marks = ?, passing_marks = ?, system_type = ? WHERE id = ?")
                            ->execute([(int)($item['total_marks'] ?? 100), (int)($item['passing_marks'] ?? 33), $sys, $existingId]);
                    } else {
                        $pdo->prepare("INSERT INTO exams (exam_name, exam_date, total_marks, passing_marks, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$item['exam_name'], normalize_date($item['exam_date']), (int)($item['total_marks'] ?? 100), (int)($item['passing_marks'] ?? 33), $sys, $tId]);
                    }
                    if ($localId !== null) $syncedIds['exams'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "exams: " . $e->getMessage(); }
            }
        }

        // Exam Results
        if (!empty($input['exam_results']) && is_array($input['exam_results'])) {
            foreach ($input['exam_results'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $erStudentId = $item['student_id'] ?? null;
                        if ($erStudentId) {
                            $erClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                            $erClsStmt->execute([$erStudentId]);
                            $erCls = $erClsStmt->fetchColumn();
                            if ($erCls && !in_array($erCls, $allowed_class_names_up)) {
                                write_log("Sync Up: exam_results - user not allowed for class $erCls");
                                continue;
                            }
                        }
                    }
                    $check = $pdo->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ? AND subject = ?");
                    $check->execute([$item['exam_id'], $item['student_id'], $item['subject'] ?? 'Quran']);
                    if ($check->rowCount() > 0) {
                        $pdo->prepare("UPDATE exam_results SET marks_obtained=?, total_marks=?, grade=?, status=?, remarks=?, pukhtagi=?, tajweed=?, lahja=?, masail=?, miqdar_khwandgi=?, p1=?, p2=?, p3=?, p4=?, p5=?, p6=?, subjects_marks=? WHERE id=?")
                            ->execute([$item['marks_obtained'] ?? 0, $item['total_marks'] ?? 100, $item['grade'] ?? '', $item['status'] ?? 'Pass', $item['remarks'] ?? '', $item['pukhtagi'] ?? 0, $item['tajweed'] ?? 0, $item['lahja'] ?? 0, $item['masail'] ?? 0, $item['miqdar_khwandgi'] ?? '', $item['p1'] ?? 0, $item['p2'] ?? 0, $item['p3'] ?? 0, $item['p4'] ?? 0, $item['p5'] ?? 0, $item['p6'] ?? 0, $item['subjects_marks'] ?? null, $check->fetchColumn()]);
                    } else {
                        $pdo->prepare("INSERT INTO exam_results (exam_id, student_id, subject, marks_obtained, total_marks, grade, status, remarks, pukhtagi, tajweed, lahja, masail, miqdar_khwandgi, p1, p2, p3, p4, p5, p6, subjects_marks, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$item['exam_id'], $item['student_id'], $item['subject'] ?? 'Quran', $item['marks_obtained'] ?? 0, $item['total_marks'] ?? 100, $item['grade'] ?? '', $item['status'] ?? 'Pass', $item['remarks'] ?? '', $item['pukhtagi'] ?? 0, $item['tajweed'] ?? 0, $item['lahja'] ?? 0, $item['masail'] ?? 0, $item['miqdar_khwandgi'] ?? '', $item['p1'] ?? 0, $item['p2'] ?? 0, $item['p3'] ?? 0, $item['p4'] ?? 0, $item['p5'] ?? 0, $item['p6'] ?? 0, $item['subjects_marks'] ?? null, $tId]);
                    }
                    if ($localId !== null) $syncedIds['exam_results'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "exam_results: " . $e->getMessage(); }
            }
        }

        // Qaida Records
        if (!empty($input['qaida_records']) && is_array($input['qaida_records'])) {
            foreach ($input['qaida_records'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $isDeleted = (int)($item['is_deleted'] ?? 0);
                    $dateVal = normalize_date($item['date'] ?? '');
                    $studentId = $item['student_id'] ?? null;
                    if (!$dateVal || !$studentId) continue;

                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $stdClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                        $stdClsStmt->execute([$studentId]);
                        $stdCls = $stdClsStmt->fetchColumn();
                        if ($stdCls && !in_array($stdCls, $allowed_class_names_up)) {
                            write_log("Sync Up: qaida_records - user not allowed for class $stdCls");
                            if ($localId !== null) $syncedIds['qaida_records'][] = $localId;
                            continue;
                        }
                    }
                    
                    $check = $pdo->prepare("SELECT id FROM qaida_records WHERE student_id = ? AND date = ? LIMIT 1");
                    $check->execute([$studentId, $dateVal]);
                    $existingId = $check->fetchColumn();
                    
                    if ($isDeleted === 1) {
                        if ($existingId) {
                            $pdo->prepare("UPDATE qaida_records SET is_deleted = 1 WHERE id = ?")->execute([$existingId]);
                        }
                    } else {
                        $dataArgs = [
                            $item['sabaq_1'] ?? '', $item['grade_1'] ?? '', $item['qty_1'] ?? '',
                            $item['sabaq_2'] ?? '', $item['grade_2'] ?? '', $item['qty_2'] ?? '',
                            $item['sabaq_3'] ?? '', $item['grade_3'] ?? '', $item['qty_3'] ?? '',
                            $item['sabaq_4'] ?? '', $item['grade_4'] ?? '', $item['qty_4'] ?? '',
                            $item['lesson_type'] ?? 'نورانی قاعدہ', $item['hearing_time'] ?? ''
                        ];
                        if ($existingId) {
                            $pdo->prepare("UPDATE qaida_records SET sabaq_1=?, grade_1=?, qty_1=?, sabaq_2=?, grade_2=?, qty_2=?, sabaq_3=?, grade_3=?, qty_3=?, sabaq_4=?, grade_4=?, qty_4=?, lesson_type=?, hearing_time=?, is_deleted=0 WHERE id=?")
                                ->execute([...$dataArgs, $existingId]);
                        } else {
                            $pdo->prepare("INSERT INTO qaida_records (student_id, date, sabaq_1, grade_1, qty_1, sabaq_2, grade_2, qty_2, sabaq_3, grade_3, qty_3, sabaq_4, grade_4, qty_4, lesson_type, hearing_time, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)")
                                ->execute([$studentId, $dateVal, ...$dataArgs, $tId]);
                        }
                    }
                    if ($localId !== null) $syncedIds['qaida_records'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "qaida_records: " . $e->getMessage(); }
            }
        }

        // Husn-e-Saut Records
        if (!empty($input['husn_saut_record']) && is_array($input['husn_saut_record'])) {
            foreach ($input['husn_saut_record'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $isDeleted = (int)($item['is_deleted'] ?? 0);
                    $dateVal = normalize_date($item['date'] ?? '');
                    $studentId = $item['student_id'] ?? null;
                    if (!$dateVal || !$studentId) continue;

                    if ($userRole !== 'admin' && $userRole !== 'nazim') {
                        $stdClsStmt = $pdo->prepare("SELECT class FROM students WHERE id = ? LIMIT 1");
                        $stdClsStmt->execute([$studentId]);
                        $stdCls = $stdClsStmt->fetchColumn();
                        if ($stdCls && !in_array($stdCls, $allowed_class_names_up)) {
                            write_log("Sync Up: husn_saut_record - user not allowed for class $stdCls");
                            if ($localId !== null) $syncedIds['husn_saut_record'][] = $localId;
                            continue;
                        }
                    }
                    
                    $check = $pdo->prepare("SELECT id FROM husn_saut_record WHERE student_id = ? AND date = ? LIMIT 1");
                    $check->execute([$studentId, $dateVal]);
                    $existingId = $check->fetchColumn();
                    
                    if ($isDeleted === 1) {
                        if ($existingId) {
                            $pdo->prepare("UPDATE husn_saut_record SET is_deleted = 1 WHERE id = ?")->execute([$existingId]);
                        }
                    } else {
                        $dataArgs = [
                            $item['manzil_sabaq'] ?? '', $item['manzil_listener'] ?? '', $item['manzil_grade'] ?? '',
                            $item['mashq_1_sabaq'] ?? '', $item['mashq_1_time'] ?? '',
                            $item['mashq_2_sabaq'] ?? '', $item['mashq_2_time'] ?? '',
                            $item['mashq_3_sabaq'] ?? '', $item['mashq_3_time'] ?? '',
                            $item['mashq_4_sabaq'] ?? '', $item['mashq_4_time'] ?? '',
                            $item['tilawat_qty'] ?? '', $item['tilawat_parahs'] ?? ''
                        ];
                        if ($existingId) {
                            $pdo->prepare("UPDATE husn_saut_record SET manzil_sabaq=?, manzil_listener=?, manzil_grade=?, mashq_1_sabaq=?, mashq_1_time=?, mashq_2_sabaq=?, mashq_2_time=?, mashq_3_sabaq=?, mashq_3_time=?, mashq_4_sabaq=?, mashq_4_time=?, tilawat_qty=?, tilawat_parahs=?, is_deleted=0 WHERE id=?")
                                ->execute([...$dataArgs, $existingId]);
                        } else {
                            $pdo->prepare("INSERT INTO husn_saut_record (student_id, date, manzil_sabaq, manzil_listener, manzil_grade, mashq_1_sabaq, mashq_1_time, mashq_2_sabaq, mashq_2_time, mashq_3_sabaq, mashq_3_time, mashq_4_sabaq, mashq_4_time, tilawat_qty, tilawat_parahs, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)")
                                ->execute([$studentId, $dateVal, ...$dataArgs, $tId]);
                        }
                    }
                    if ($localId !== null) $syncedIds['husn_saut_record'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "husn_saut_record: " . $e->getMessage(); }
            }
        }
    }
}
