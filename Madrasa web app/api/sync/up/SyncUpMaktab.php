<?php
/**
 * Sync Up Maktab Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncUpMaktab {
    public static function process(SyncContext $ctx, $input, &$syncedIds, &$serverIds, &$syncErrors) {
        $pdo = $ctx->pdo;
        $tId = (int)$ctx->tenantId;

        // Maktab Students
        if (!empty($input['maktab_students']) && is_array($input['maktab_students'])) {
            foreach ($input['maktab_students'] as $s) {
                $localId = to_int_or_null($s['id'] ?? null);
                try {
                    $name = trim((string)($s['name'] ?? '')); if ($name === '') continue;
                    $admissionNo = trim((string)($s['admission_number'] ?? ($s['roll_number'] ?? '')));
                    $isDelM = (int)($s['is_deleted'] ?? 0);
                    $newStatus = trim((string)($s['status'] ?? 'Active'));
                    if ($isDelM === 1 || strtolower($newStatus) === 'deleted') {
                        $delStmtM = $pdo->prepare("UPDATE maktab_students SET is_deleted=1, status='Deleted' WHERE admission_number=? OR id=?");
                        $delStmtM->execute([$admissionNo, $localId]);
                        write_log("Sync Up: Maktab Student deleted on server ($admissionNo / Local ID: $localId)");
                        if ($localId !== null) $syncedIds['maktab_students'][] = $localId;
                        continue;
                    }

                    $stmtCheck = $pdo->prepare("SELECT id, is_deleted FROM maktab_students WHERE admission_number = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1");
                    $stmtCheck->execute([$admissionNo, $tId]);
                    $row = $admissionNo ? $stmtCheck->fetch() : null;
                    $photoPath = $ctx->uploadImage($s['photo'] ?? '', 'maktab_std');
                    $residence_status = $s['residence_status'] ?? '';
                    $alumni_category = !empty($s['alumni_category']) ? $s['alumni_category'] : ($newStatus === 'Alumni' ? 'completed' : null);
                    $wifaq_exam = !empty($s['wifaq_exam']) ? 1 : 0;
                    $wifaq_marks = $s['wifaq_marks'] ?? null;
                    $mehrab = $s['mehrab_recitation'] ?? null;
                    $savedMaktabStudentId = null;

                    if ($row) {
                        if ($row['is_deleted'] == 1) {
                            if ($localId !== null) $syncedIds['maktab_students'][] = $localId;
                            continue;
                        }
                        $existingId = (int)$row['id'];
                        $savedMaktabStudentId = $existingId;
                        $hifz_section = !empty($s['hifz_section']) ? $s['hifz_section'] : 'regular';
                        $stmt = $pdo->prepare("UPDATE maktab_students SET name=?, father_name=?, admission_number=?, class=?, contact_number=?, address=?, status=?, admission_date=?, graduation_date=?, duration=?, leaving_reason=?, photo=COALESCE(NULLIF(?, ''), photo), residence_status=?, alumni_category=?, wifaq_exam=?, wifaq_marks=?, mehrab_recitation=?, hifz_section=?, is_deleted=0 WHERE id=?");
                        $stmt->execute([$name, $s['father_name'] ?? '', $admissionNo, $s['class'] ?? $s['class_name'] ?? '', $s['contact_number'] ?? $s['mobile_no'] ?? '', $s['address'] ?? '', $newStatus, normalize_date($s['admission_date'] ?? ''), normalize_date($s['graduation_date'] ?? ''), $s['duration'] ?? '', $s['leaving_reason'] ?? '', $photoPath, $residence_status, $alumni_category, $wifaq_exam, $wifaq_marks, $mehrab, $hifz_section, $existingId]);
                    } else {
                        $hifz_section = !empty($s['hifz_section']) ? $s['hifz_section'] : 'regular';
                        $stmt = $pdo->prepare("INSERT INTO maktab_students (name, father_name, admission_number, class, contact_number, address, status, admission_date, graduation_date, duration, photo, residence_status, alumni_category, wifaq_exam, wifaq_marks, mehrab_recitation, hifz_section, is_deleted, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Masjid', ?)");
                        $stmt->execute([$name, $s['father_name'] ?? '', $admissionNo, $s['class'] ?? $s['class_name'] ?? '', $s['contact_number'] ?? $s['mobile_no'] ?? '', $s['address'] ?? '', normalize_date($s['admission_date'] ?? ''), normalize_date($s['graduation_date'] ?? ''), $s['duration'] ?? '', $photoPath, $residence_status, $alumni_category, $wifaq_exam, $wifaq_marks, $mehrab, $hifz_section, $tId]);
                        $savedMaktabStudentId = (int)$pdo->lastInsertId();
                    }
                    if ($localId !== null) {
                        $syncedIds['maktab_students'][] = $localId;
                        if ($savedMaktabStudentId) {
                            $serverIds['maktab_students'][(string)$localId] = $savedMaktabStudentId;
                        }
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Maktab Student Sync: " . $e->getMessage());
                    $syncErrors[] = "maktab_students: " . $e->getMessage();
                }
            }
        }

        // Maktab Classes
        if (!empty($input['maktab_classes']) && is_array($input['maktab_classes'])) {
            foreach ($input['maktab_classes'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $name = trim((string)($item['name'] ?? ''));
                    if ($name !== '') {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_classes WHERE name = ? LIMIT 1");
                        $check->execute([$name]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_classes SET class_type=?, teacher_id=?, is_deleted=? WHERE id=?")
                                ->execute([$item['class_type'] ?? 'Nazira', to_int_or_null($item['teacher_id'] ?? null), $isDeleted, $existingId]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_classes (name, class_type, teacher_id, is_deleted, system_type, tenant_id) VALUES (?, ?, ?, ?, 'Masjid', ?)")
                                ->execute([$name, $item['class_type'] ?? 'Nazira', to_int_or_null($item['teacher_id'] ?? null), $isDeleted, $tId]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_classes'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_classes: " . $e->getMessage(); }
            }
        }

        // Maktab Teachers
        if (!empty($input['maktab_teachers']) && is_array($input['maktab_teachers'])) {
            foreach ($input['maktab_teachers'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $name = trim((string)($item['name'] ?? ''));
                    if ($name !== '') {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_teachers WHERE name = ? AND father_name = ? LIMIT 1");
                        $check->execute([$name, $item['father_name'] ?? '']);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_teachers SET phone=?, role=?, assigned_class=?, is_deleted=? WHERE id=?")
                                ->execute([$item['phone'] ?? '', $item['role'] ?? 'Teacher', $item['assigned_class'] ?? '', $isDeleted, $existingId]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_teachers (name, father_name, phone, role, assigned_class, is_deleted, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, 'Masjid', ?)")
                                ->execute([$name, $item['father_name'] ?? '', $item['phone'] ?? '', $item['role'] ?? 'Teacher', $item['assigned_class'] ?? '', $isDeleted, $tId]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_teachers'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_teachers: " . $e->getMessage(); }
            }
        }

        // Maktab Exams
        if (!empty($input['maktab_exams']) && is_array($input['maktab_exams'])) {
            foreach ($input['maktab_exams'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $title = trim((string)($item['title'] ?? ($item['exam_name'] ?? '')));
                    if ($title !== '') {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $dateVal = normalize_date($item['date'] ?? ($item['exam_date'] ?? ''));
                        $check = $pdo->prepare("SELECT id FROM maktab_exams WHERE title = ? AND date = ? LIMIT 1");
                        $check->execute([$title, $dateVal]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_exams SET total_marks=?, is_deleted=? WHERE id=?")
                                ->execute([(int)($item['total_marks'] ?? 100), $isDeleted, $existingId]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_exams (title, date, total_marks, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?)")
                                ->execute([$title, $dateVal, (int)($item['total_marks'] ?? 100), $isDeleted, $tId]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_exams'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_exams: " . $e->getMessage(); }
            }
        }

        // Maktab Attendance
        if (!empty($input['maktab_attendance']) && is_array($input['maktab_attendance'])) {
            foreach ($input['maktab_attendance'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $studentId = to_int_or_null($item['student_id'] ?? null);
                    $dateVal = normalize_date($item['date'] ?? '');
                    $periodNum = (int)($item['period_num'] ?? 1);
                    if ($studentId && $dateVal) {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM period_attendance WHERE student_id = ? AND date = ? AND period_num = ? LIMIT 1");
                        $check->execute([$studentId, $dateVal, $periodNum]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE period_attendance SET status=?, remarks=? WHERE id=?")
                                ->execute([$item['status'] ?? 'Present', $item['remarks'] ?? '', $existingId]);
                        } else {
                            $pdo->prepare("INSERT INTO period_attendance (student_id, date, period_num, status, remarks, tenant_id) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$studentId, $dateVal, $periodNum, $item['status'] ?? 'Present', $item['remarks'] ?? '', $tId]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_attendance'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_attendance: " . $e->getMessage(); }
            }
        }

        // Maktab Nazira Record
        if (!empty($input['maktab_nazira_record']) && is_array($input['maktab_nazira_record'])) {
            foreach ($input['maktab_nazira_record'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $studentId = to_int_or_null($item['student_id'] ?? null);
                    $dateVal = normalize_date($item['date'] ?? '');
                    if ($studentId && $dateVal) {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_nazira_record WHERE student_id = ? AND date = ? LIMIT 1");
                        $check->execute([$studentId, $dateVal]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_nazira_record SET class_name=?, teacher_id=?, morning_sabaq=?, morning_qty=?, morning_time=?, morning_status=?, evening_sabaq=?, evening_qty=?, evening_time=?, evening_status=?, sabaq_p=?, sabaq_r=?, sabaq_s=?, sabaq_time=?, sabqi_p=?, sabqi_r=?, sabqi_s=?, sabqi_listener=?, sabqi_time=?, manzil_p=?, manzil_r=?, manzil_s=?, manzil_listener=?, manzil_time=?, status=?, is_deleted=? WHERE id=?")
                                ->execute([
                                    $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null),
                                    $item['morning_sabaq'] ?? '', $item['morning_qty'] ?? '', $item['morning_time'] ?? '', $item['morning_status'] ?? '',
                                    $item['evening_sabaq'] ?? '', $item['evening_qty'] ?? '', $item['evening_time'] ?? '', $item['evening_status'] ?? '',
                                    $item['sabaq_p'] ?? '', $item['sabaq_r'] ?? '', $item['sabaq_s'] ?? '', $item['sabaq_time'] ?? '',
                                    $item['sabqi_p'] ?? '', $item['sabqi_r'] ?? '', $item['sabqi_s'] ?? '', $item['sabqi_listener'] ?? '', $item['sabqi_time'] ?? '',
                                    $item['manzil_p'] ?? '', $item['manzil_r'] ?? '', $item['manzil_s'] ?? '', $item['manzil_listener'] ?? '', $item['manzil_time'] ?? '',
                                    $item['status'] ?? 'Active', $isDeleted, $existingId
                                ]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_nazira_record (student_id, class_name, teacher_id, date, morning_sabaq, morning_qty, morning_time, morning_status, evening_sabaq, evening_qty, evening_time, evening_status, sabaq_p, sabaq_r, sabaq_s, sabaq_time, sabqi_p, sabqi_r, sabqi_s, sabqi_listener, sabqi_time, manzil_p, manzil_r, manzil_s, manzil_listener, manzil_time, status, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([
                                    $studentId, $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null), $dateVal,
                                    $item['morning_sabaq'] ?? '', $item['morning_qty'] ?? '', $item['morning_time'] ?? '', $item['morning_status'] ?? '',
                                    $item['evening_sabaq'] ?? '', $item['evening_qty'] ?? '', $item['evening_time'] ?? '', $item['evening_status'] ?? '',
                                    $item['sabaq_p'] ?? '', $item['sabaq_r'] ?? '', $item['sabaq_s'] ?? '', $item['sabaq_time'] ?? '',
                                    $item['sabqi_p'] ?? '', $item['sabqi_r'] ?? '', $item['sabqi_s'] ?? '', $item['sabqi_listener'] ?? '', $item['sabqi_time'] ?? '',
                                    $item['manzil_p'] ?? '', $item['manzil_r'] ?? '', $item['manzil_s'] ?? '', $item['manzil_listener'] ?? '', $item['manzil_time'] ?? '',
                                    $item['status'] ?? 'Active', $isDeleted, $tId
                                ]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_nazira_record'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_nazira_record: " . $e->getMessage(); }
            }
        }

        // Maktab Hifz Record
        if (!empty($input['maktab_hifz_record']) && is_array($input['maktab_hifz_record'])) {
            foreach ($input['maktab_hifz_record'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $studentId = to_int_or_null($item['student_id'] ?? null);
                    $dateVal = normalize_date($item['date'] ?? '');
                    if ($studentId && $dateVal) {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_hifz_record WHERE student_id = ? AND date = ? LIMIT 1");
                        $check->execute([$studentId, $dateVal]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_hifz_record SET class_name=?, teacher_id=?, sabaq_para=?, sabaq_lines=?, sabaq_grade=?, sabaq_time=?, sabqi_para=?, sabqi_qty=?, sabqi_grade=?, sabqi_listener=?, sabqi_time=?, sabqi_review_details=?, manzil_total_qty=?, manzil_details=?, manzil_grade=?, manzil_listener=?, manzil_time=?, sabaq_p=?, sabaq_l=?, sabaq_s=?, sabqi_p=?, sabqi_amt=?, sabqi_s=?, manzil_p=?, manzil_s=?, status=?, is_deleted=? WHERE id=?")
                                ->execute([
                                    $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null),
                                    $item['sabaq_para'] ?? '', $item['sabaq_lines'] ?? '', $item['sabaq_grade'] ?? '', $item['sabaq_time'] ?? '',
                                    $item['sabqi_para'] ?? '', $item['sabqi_qty'] ?? '', $item['sabqi_grade'] ?? '', $item['sabqi_listener'] ?? '', $item['sabqi_time'] ?? '', $item['sabqi_review_details'] ?? '',
                                    $item['manzil_total_qty'] ?? '', $item['manzil_details'] ?? '', $item['manzil_grade'] ?? '', $item['manzil_listener'] ?? '', $item['manzil_time'] ?? '',
                                    $item['sabaq_p'] ?? '', $item['sabaq_l'] ?? '', $item['sabaq_s'] ?? '',
                                    $item['sabqi_p'] ?? '', $item['sabqi_amt'] ?? '', $item['sabqi_s'] ?? '',
                                    $item['manzil_p'] ?? '', $item['manzil_s'] ?? '',
                                    $item['status'] ?? 'Active', $isDeleted, $existingId
                                ]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_hifz_record (student_id, class_name, teacher_id, date, sabaq_para, sabaq_lines, sabaq_grade, sabaq_time, sabqi_para, sabqi_qty, sabqi_grade, sabqi_listener, sabqi_time, sabqi_review_details, manzil_total_qty, manzil_details, manzil_grade, manzil_listener, manzil_time, sabaq_p, sabaq_l, sabaq_s, sabqi_p, sabqi_amt, sabqi_s, manzil_p, manzil_s, status, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([
                                    $studentId, $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null), $dateVal,
                                    $item['sabaq_para'] ?? '', $item['sabaq_lines'] ?? '', $item['sabaq_grade'] ?? '', $item['sabaq_time'] ?? '',
                                    $item['sabqi_para'] ?? '', $item['sabqi_qty'] ?? '', $item['sabqi_grade'] ?? '', $item['sabqi_listener'] ?? '', $item['sabqi_time'] ?? '', $item['sabqi_review_details'] ?? '',
                                    $item['manzil_total_qty'] ?? '', $item['manzil_details'] ?? '', $item['manzil_grade'] ?? '', $item['manzil_listener'] ?? '', $item['manzil_time'] ?? '',
                                    $item['sabaq_p'] ?? '', $item['sabaq_l'] ?? '', $item['sabaq_s'] ?? '',
                                    $item['sabqi_p'] ?? '', $item['sabqi_amt'] ?? '', $item['sabqi_s'] ?? '',
                                    $item['manzil_p'] ?? '', $item['manzil_s'] ?? '',
                                    $item['status'] ?? 'Active', $isDeleted, $tId
                                ]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_hifz_record'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_hifz_record: " . $e->getMessage(); }
            }
        }

        // Maktab Qaida Records
        if (!empty($input['maktab_qaida_records']) && is_array($input['maktab_qaida_records'])) {
            foreach ($input['maktab_qaida_records'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $studentId = to_int_or_null($item['student_id'] ?? null);
                    $dateVal = normalize_date($item['date'] ?? '');
                    if ($studentId && $dateVal) {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_qaida_records WHERE student_id = ? AND date = ? LIMIT 1");
                        $check->execute([$studentId, $dateVal]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_qaida_records SET class_name=?, teacher_id=?, lesson_type=?, hearing_time=?, sabaq_1=?, qty_1=?, grade_1=?, sabaq_2=?, qty_2=?, grade_2=?, sabaq_3=?, qty_3=?, grade_3=?, sabaq_4=?, qty_4=?, grade_4=?, sabaq_book=?, sabaq_page=?, sabaq_status=?, sabaq_time=?, status=?, is_deleted=? WHERE id=?")
                                ->execute([
                                    $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null),
                                    $item['lesson_type'] ?? 'نورانی قاعدہ', $item['hearing_time'] ?? '',
                                    $item['sabaq_1'] ?? '', $item['qty_1'] ?? '', $item['grade_1'] ?? '',
                                    $item['sabaq_2'] ?? '', $item['qty_2'] ?? '', $item['grade_2'] ?? '',
                                    $item['sabaq_3'] ?? '', $item['qty_3'] ?? '', $item['grade_3'] ?? '',
                                    $item['sabaq_4'] ?? '', $item['qty_4'] ?? '', $item['grade_4'] ?? '',
                                    $item['sabaq_book'] ?? '', $item['sabaq_page'] ?? '', $item['sabaq_status'] ?? '', $item['sabaq_time'] ?? '',
                                    $item['status'] ?? 'Active', $isDeleted, $existingId
                                ]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_qaida_records (student_id, class_name, teacher_id, date, lesson_type, hearing_time, sabaq_1, qty_1, grade_1, sabaq_2, qty_2, grade_2, sabaq_3, qty_3, grade_3, sabaq_4, qty_4, grade_4, sabaq_book, sabaq_page, sabaq_status, sabaq_time, status, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([
                                    $studentId, $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null), $dateVal,
                                    $item['lesson_type'] ?? 'نورانی قاعدہ', $item['hearing_time'] ?? '',
                                    $item['sabaq_1'] ?? '', $item['qty_1'] ?? '', $item['grade_1'] ?? '',
                                    $item['sabaq_2'] ?? '', $item['qty_2'] ?? '', $item['grade_2'] ?? '',
                                    $item['sabaq_3'] ?? '', $item['qty_3'] ?? '', $item['grade_3'] ?? '',
                                    $item['sabaq_4'] ?? '', $item['qty_4'] ?? '', $item['grade_4'] ?? '',
                                    $item['sabaq_book'] ?? '', $item['sabaq_page'] ?? '', $item['sabaq_status'] ?? '', $item['sabaq_time'] ?? '',
                                    $item['status'] ?? 'Active', $isDeleted, $tId
                                ]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_qaida_records'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_qaida_records: " . $e->getMessage(); }
            }
        }

        // Maktab Academic Reports
        if (!empty($input['maktab_academic_reports']) && is_array($input['maktab_academic_reports'])) {
            foreach ($input['maktab_academic_reports'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $studentId = to_int_or_null($item['student_id'] ?? null);
                    $dateVal = normalize_date($item['date'] ?? '');
                    $subject = trim((string)($item['subject'] ?? ''));
                    if ($studentId) {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_academic_reports WHERE student_id = ? AND (date = ? OR (month = ? AND year = ?)) AND subject = ? LIMIT 1");
                        $check->execute([$studentId, $dateVal, $item['month'] ?? '', $item['year'] ?? '', $subject]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_academic_reports SET class_name=?, date=?, month=?, year=?, type=?, lesson_details=?, grade=?, attendance_days=?, total_working_days=?, sabaq_grade=?, sabqi_grade=?, manzil_grade=?, remarks=?, is_deleted=? WHERE id=?")
                                ->execute([
                                    $item['class_name'] ?? '', $dateVal, $item['month'] ?? '', $item['year'] ?? '',
                                    $item['type'] ?? '', $item['lesson_details'] ?? '', $item['grade'] ?? '',
                                    to_int_or_null($item['attendance_days'] ?? null), to_int_or_null($item['total_working_days'] ?? null),
                                    $item['sabaq_grade'] ?? '', $item['sabqi_grade'] ?? '', $item['manzil_grade'] ?? '',
                                    $item['remarks'] ?? '', $isDeleted, $existingId
                                ]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_academic_reports (student_id, class_name, date, month, year, type, subject, lesson_details, grade, attendance_days, total_working_days, sabaq_grade, sabqi_grade, manzil_grade, remarks, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([
                                    $studentId, $item['class_name'] ?? '', $dateVal, $item['month'] ?? '', $item['year'] ?? '',
                                    $item['type'] ?? '', $subject, $item['lesson_details'] ?? '', $item['grade'] ?? '',
                                    to_int_or_null($item['attendance_days'] ?? null), to_int_or_null($item['total_working_days'] ?? null),
                                    $item['sabaq_grade'] ?? '', $item['sabqi_grade'] ?? '', $item['manzil_grade'] ?? '',
                                    $item['remarks'] ?? '', $isDeleted, $tId
                                ]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_academic_reports'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_academic_reports: " . $e->getMessage(); }
            }
        }

        // Maktab Exam Results
        if (!empty($input['maktab_exam_results']) && is_array($input['maktab_exam_results'])) {
            foreach ($input['maktab_exam_results'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $examId = to_int_or_null($item['exam_id'] ?? null);
                    $studentId = to_int_or_null($item['student_id'] ?? null);
                    $subject = trim((string)($item['subject'] ?? ''));
                    if ($examId && $studentId) {
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $check = $pdo->prepare("SELECT id FROM maktab_exam_results WHERE exam_id = ? AND student_id = ? AND subject = ? LIMIT 1");
                        $check->execute([$examId, $studentId, $subject]);
                        $existingId = $check->fetchColumn();
                        if ($existingId) {
                            $pdo->prepare("UPDATE maktab_exam_results SET class_name=?, teacher_id=?, total_marks=?, obtained_marks=?, percentage=?, grade=?, status=?, remarks=?, is_deleted=? WHERE id=?")
                                ->execute([
                                    $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null),
                                    (float)($item['total_marks'] ?? 100), (float)($item['obtained_marks'] ?? 0),
                                    (float)($item['percentage'] ?? 0), $item['grade'] ?? '',
                                    $item['status'] ?? 'Pass', $item['remarks'] ?? '', $isDeleted, $existingId
                                ]);
                        } else {
                            $pdo->prepare("INSERT INTO maktab_exam_results (exam_id, student_id, class_name, teacher_id, subject, total_marks, obtained_marks, percentage, grade, status, remarks, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([
                                    $examId, $studentId, $item['class_name'] ?? '', to_int_or_null($item['teacher_id'] ?? null),
                                    $subject, (float)($item['total_marks'] ?? 100), (float)($item['obtained_marks'] ?? 0),
                                    (float)($item['percentage'] ?? 0), $item['grade'] ?? '',
                                    $item['status'] ?? 'Pass', $item['remarks'] ?? '', $isDeleted, $tId
                                ]);
                        }
                    }
                    if ($localId !== null) $syncedIds['maktab_exam_results'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "maktab_exam_results: " . $e->getMessage(); }
            }
        }
    }
}
