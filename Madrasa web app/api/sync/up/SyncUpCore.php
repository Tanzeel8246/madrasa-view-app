<?php
/**
 * Sync Up Core Module (Classes, Departments, Notices, Assets)
 * Madrasa Ashraf-ul-Madaris
 */

class SyncUpCore {
    public static function process(SyncContext $ctx, $input, &$syncedIds, &$serverIds, &$syncErrors) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $tId = (int)$ctx->tenantId;

        // Classes
        if (!empty($input['classes']) && is_array($input['classes'])) {
            foreach ($input['classes'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    if ((int)($item['is_deleted'] ?? 0) === 1) {
                        if ($serverId) {
                            $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$serverId]);
                        }
                        if ($localId !== null) $syncedIds['classes'][] = $localId;
                        continue;
                    }
                    $itemSys = $item['system_type'] ?? 'Madrasa';
                    $check = $serverId
                        ? $pdo->prepare("SELECT id FROM classes WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")
                        : $pdo->prepare("SELECT id FROM classes WHERE name = ? AND COALESCE(section, '') = ? AND system_type = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                    $check->execute($serverId ? [$serverId, $tId] : [$item['name'], $item['section'] ?? '', $itemSys, $tId]);
                    if ($check->rowCount() > 0) {
                        $pdo->prepare("UPDATE classes SET name=?, section=?, status=?, class_type=?, main_teacher_ids=?, p1_name=?, p2_name=?, p3_name=?, p4_name=?, p5_name=?, p6_name=?, subjects_list=?, system_type=? WHERE id=?")
                            ->execute([$item['name'], $item['section'] ?? '', $item['status'] ?? 'Active', $item['class_type'] ?? 'Regular', $item['main_teacher_ids'] ?? '[]', $item['p1_name'] ?? 'Period 1', $item['p2_name'] ?? 'Period 2', $item['p3_name'] ?? 'Period 3', $item['p4_name'] ?? 'Period 4', $item['p5_name'] ?? 'Period 5', $item['p6_name'] ?? 'Period 6', $item['subjects_list'] ?? null, $itemSys, $check->fetchColumn()]);
                    } else {
                        $pdo->prepare("INSERT INTO classes (name, section, status, class_type, main_teacher_ids, p1_name, p2_name, p3_name, p4_name, p5_name, p6_name, subjects_list, system_type, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$item['name'], $item['section'] ?? '', $item['status'] ?? 'Active', $item['class_type'] ?? 'Regular', $item['main_teacher_ids'] ?? '[]', $item['p1_name'] ?? 'Period 1', $item['p2_name'] ?? 'Period 2', $item['p3_name'] ?? 'Period 3', $item['p4_name'] ?? 'Period 4', $item['p5_name'] ?? 'Period 5', $item['p6_name'] ?? 'Period 6', $item['subjects_list'] ?? null, $itemSys, $tId]);
                    }
                    if ($localId !== null) $syncedIds['classes'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "classes: " . $e->getMessage(); }
            }
        }

        // Departments
        if (!empty($input['departments']) && is_array($input['departments'])) {
            foreach ($input['departments'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $name = trim($item['name'] ?? '');
                    if ($name === '') continue;
                    $check = $pdo->prepare("SELECT id FROM departments WHERE name = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
                    $check->execute([$name, $tId]);
                    if ($check->rowCount() > 0) {
                        $existing = $check->fetch();
                        $pdo->prepare("UPDATE departments SET description = ?, status = ? WHERE id = ?")
                            ->execute([$item['description'] ?? '', $item['status'] ?? 'Active', $existing['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO departments (name, description, status, tenant_id) VALUES (?, ?, ?, ?)")
                            ->execute([$name, $item['description'] ?? '', $item['status'] ?? 'Active', $tId]);
                    }
                    if ($localId !== null) $syncedIds['departments'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "departments: " . $e->getMessage(); }
            }
        }

        // Notices
        if (!empty($input['notices']) && is_array($input['notices'])) {
            foreach ($input['notices'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['server_id'] ?? null);
                    $clientId = to_int_or_null($item['id'] ?? null);
                    $noticeDate = normalize_date($item['date'] ?? null);
                    if ((int)($item['is_deleted'] ?? 0) === 1) {
                        if (!$serverId && $clientId) {
                            $serverId = $clientId;
                        }
                        if ($serverId) {
                            $pdo->prepare("UPDATE notices SET is_deleted = 1 WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$serverId, $tId]);
                        } else {
                            $pdo->prepare("UPDATE notices SET is_deleted = 1 WHERE title = ? AND date = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")
                                ->execute([$item['title'] ?? '', $noticeDate, $tId]);
                        }
                        if ($localId !== null) $syncedIds['notices'][] = $localId;
                        continue;
                    }
                    if ($serverId) {
                        $pdo->prepare("UPDATE notices SET title = ?, message = ?, date = ?, is_deleted = 0 WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")
                            ->execute([$item['title'] ?? '', $item['message'] ?? '', $noticeDate, $serverId, $tId]);
                        $savedNoticeId = $serverId;
                    } else {
                        $check = $pdo->prepare("SELECT id FROM notices WHERE title = ? AND date = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
                        $check->execute([$item['title'] ?? '', $noticeDate, $tId]);
                        $existingId = to_int_or_null($check->fetchColumn());
                        if ($existingId) {
                            $pdo->prepare("UPDATE notices SET message = ?, is_deleted = 0 WHERE id = ?")
                                ->execute([$item['message'] ?? '', $existingId]);
                            $savedNoticeId = $existingId;
                        } else {
                            $pdo->prepare("INSERT INTO notices (title, message, date, tenant_id) VALUES (?, ?, ?, ?)")
                                ->execute([$item['title'] ?? '', $item['message'] ?? '', $noticeDate, $tId]);
                            $savedNoticeId = (int)$pdo->lastInsertId();
                        }
                    }
                    if ($localId !== null) {
                        $syncedIds['notices'][] = $localId;
                        if (!empty($savedNoticeId)) {
                            $serverIds['notices'][(string)$localId] = (int)$savedNoticeId;
                        }
                    }
                } catch (Exception $e) { $syncErrors[] = "notices: " . $e->getMessage(); }
            }
        }

        // Masjid Assets
        if (!empty($input['masjid_assets']) && is_array($input['masjid_assets'])) {
            foreach ($input['masjid_assets'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $name = trim((string)($item['name'] ?? ''));
                    if ($name !== '') {
                        $tbl = 'assets';
                        try {
                            $pdo->query("SELECT 1 FROM assets LIMIT 1");
                        } catch (Exception $e) {
                            $tbl = 'masjid_assets';
                        }
                        $sysType = trim((string)($item['system_type'] ?? 'Masjid'));
                        if ($sysType === '') $sysType = 'Masjid';
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $serverId = to_int_or_null($item['id'] ?? null);

                        $check = $serverId ? $pdo->prepare("SELECT id FROM `$tbl` WHERE id = ? LIMIT 1") : null;
                        if ($check) {
                            $check->execute([$serverId]);
                            $existingId = $check->fetchColumn();
                        } else {
                            $existingId = null;
                        }

                        if ($existingId) {
                            $stmt = $pdo->prepare("UPDATE `$tbl` SET name=?, category=?, quantity=?, condition_status=?, asset_type=?, donor_name=?, donor_father=?, donor_address=?, donor_phone=?, system_type=?, is_deleted=? WHERE id=?");
                            $stmt->execute([$name, $item['category'] ?? '', (int)($item['quantity'] ?? 1), $item['condition_status'] ?? ($item['condition'] ?? 'نیا (New)'), $item['asset_type'] ?? 'Purchased', $item['donor_name'] ?? '', $item['donor_father'] ?? '', $item['donor_address'] ?? '', $item['donor_phone'] ?? '', $sysType, $isDeleted, $existingId]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO `$tbl` (name, system_type, category, quantity, condition_status, asset_type, donor_name, donor_father, donor_address, donor_phone, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$name, $sysType, $item['category'] ?? '', (int)($item['quantity'] ?? 1), $item['condition_status'] ?? ($item['condition'] ?? 'نیا (New)'), $item['asset_type'] ?? 'Purchased', $item['donor_name'] ?? '', $item['donor_father'] ?? '', $item['donor_address'] ?? '', $item['donor_phone'] ?? '', $isDeleted]);
                        }
                    }
                    if ($localId !== null) $syncedIds['masjid_assets'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "masjid_assets: " . $e->getMessage(); }
            }
        }

        // Admission Inquiries (Phase 2)
        if (!empty($input['admission_inquiries']) && is_array($input['admission_inquiries'])) {
            foreach ($input['admission_inquiries'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $name = trim($item['student_name'] ?? '');
                    if ($name === '') continue;
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM admission_inquiries WHERE id = ? LIMIT 1") : null;
                    if ($check) {
                        $check->execute([$serverId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE admission_inquiries SET student_name=?, father_name=?, phone=?, class_applied=?, gender=?, address=?, status=?, interview_date=?, interview_notes=?, admin_notes=?, system_type=?, converted_student_id=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $name,
                            $item['father_name'] ?? '',
                            $item['phone'] ?? '',
                            $item['class_applied'] ?? '',
                            $item['gender'] ?? 'Male',
                            $item['address'] ?? '',
                            $item['status'] ?? 'Pending',
                            !empty($item['interview_date']) ? $item['interview_date'] : null,
                            $item['interview_notes'] ?? '',
                            $item['admin_notes'] ?? '',
                            $item['system_type'] ?? 'Madrasa',
                            to_int_or_null($item['converted_student_id'] ?? null),
                            (int)($item['is_deleted'] ?? 0),
                            $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO admission_inquiries (student_name, father_name, phone, class_applied, gender, address, status, interview_date, interview_notes, admin_notes, system_type, converted_student_id, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $name,
                            $item['father_name'] ?? '',
                            $item['phone'] ?? '',
                            $item['class_applied'] ?? '',
                            $item['gender'] ?? 'Male',
                            $item['address'] ?? '',
                            $item['status'] ?? 'Pending',
                            !empty($item['interview_date']) ? $item['interview_date'] : null,
                            $item['interview_notes'] ?? '',
                            $item['admin_notes'] ?? '',
                            $item['system_type'] ?? 'Madrasa',
                            to_int_or_null($item['converted_student_id'] ?? null),
                            (int)($item['is_deleted'] ?? 0),
                        ]);
                    }
                    if ($localId !== null) $syncedIds['admission_inquiries'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "admission_inquiries: " . $e->getMessage(); }
            }
        }

        // Library Books (Phase 2)
        if (!empty($input['lib_books']) && is_array($input['lib_books'])) {
            foreach ($input['lib_books'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $title = trim($item['book_title'] ?? '');
                    if ($title === '') continue;
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM lib_books WHERE id = ? LIMIT 1") : null;
                    if ($check) {
                        $check->execute([$serverId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE lib_books SET book_title=?, author=?, category=?, isbn=?, rack_number=?, total_copies=?, available_copies=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $title,
                            $item['author'] ?? '',
                            $item['category'] ?? 'عام',
                            $item['isbn'] ?? '',
                            $item['rack_number'] ?? '',
                            (int)($item['total_copies'] ?? 1),
                            (int)($item['available_copies'] ?? 1),
                            $item['system_type'] ?? 'Madrasa',
                            (int)($item['is_deleted'] ?? 0),
                            $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO lib_books (book_title, author, category, isbn, rack_number, total_copies, available_copies, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $title,
                            $item['author'] ?? '',
                            $item['category'] ?? 'عام',
                            $item['isbn'] ?? '',
                            $item['rack_number'] ?? '',
                            (int)($item['total_copies'] ?? 1),
                            (int)($item['available_copies'] ?? 1),
                            $item['system_type'] ?? 'Madrasa',
                            (int)($item['is_deleted'] ?? 0),
                        ]);
                    }
                    if ($localId !== null) $syncedIds['lib_books'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "lib_books: " . $e->getMessage(); }
            }
        }

        // Library Issues (Phase 2)
        if (!empty($input['lib_issues']) && is_array($input['lib_issues'])) {
            foreach ($input['lib_issues'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $bookId = (int)($item['book_id'] ?? 0);
                    if ($bookId <= 0) continue;
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM lib_issues WHERE id = ? LIMIT 1") : null;
                    if ($check) {
                        $check->execute([$serverId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE lib_issues SET book_id=?, borrower_type=?, borrower_id=?, borrower_name=?, borrower_contact=?, issue_date=?, due_date=?, return_date=?, status=?, fine_amount=?, remarks=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $bookId,
                            $item['borrower_type'] ?? 'student',
                            (int)($item['borrower_id'] ?? 0),
                            $item['borrower_name'] ?? '',
                            $item['borrower_contact'] ?? '',
                            $item['issue_date'] ?? date('Y-m-d'),
                            $item['due_date'] ?? date('Y-m-d', strtotime('+14 days')),
                            !empty($item['return_date']) ? $item['return_date'] : null,
                            $item['status'] ?? 'Issued',
                            floatval($item['fine_amount'] ?? 0),
                            $item['remarks'] ?? '',
                            $item['system_type'] ?? 'Madrasa',
                            (int)($item['is_deleted'] ?? 0),
                            $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO lib_issues (book_id, borrower_type, borrower_id, borrower_name, borrower_contact, issue_date, due_date, return_date, status, fine_amount, remarks, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $bookId,
                            $item['borrower_type'] ?? 'student',
                            (int)($item['borrower_id'] ?? 0),
                            $item['borrower_name'] ?? '',
                            $item['borrower_contact'] ?? '',
                            $item['issue_date'] ?? date('Y-m-d'),
                            $item['due_date'] ?? date('Y-m-d', strtotime('+14 days')),
                            !empty($item['return_date']) ? $item['return_date'] : null,
                            $item['status'] ?? 'Issued',
                            floatval($item['fine_amount'] ?? 0),
                            $item['remarks'] ?? '',
                            $item['system_type'] ?? 'Madrasa',
                            (int)($item['is_deleted'] ?? 0),
                        ]);
                    }
                    if ($localId !== null) $syncedIds['lib_issues'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "lib_issues: " . $e->getMessage(); }
            }
        }

        // Phase 3: Inventory Items
        if (!empty($input['inventory_items']) && is_array($input['inventory_items'])) {
            foreach ($input['inventory_items'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM inventory_items WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE inventory_items SET item_code=?, name=?, category=?, unit_price=?, purchase_price=?, current_stock=?, alert_threshold=?, unit=?, description=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $item['item_code'] ?? '', $item['name'] ?? '', $item['category'] ?? 'عام',
                            floatval($item['unit_price'] ?? 0), floatval($item['purchase_price'] ?? 0),
                            intval($item['current_stock'] ?? 0), intval($item['alert_threshold'] ?? 5),
                            $item['unit'] ?? 'عدد', $item['description'] ?? '',
                            $item['system_type'] ?? 'Madrasa', intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO inventory_items (item_code, name, category, unit_price, purchase_price, current_stock, alert_threshold, unit, description, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $item['item_code'] ?? '', $item['name'] ?? '', $item['category'] ?? 'عام',
                            floatval($item['unit_price'] ?? 0), floatval($item['purchase_price'] ?? 0),
                            intval($item['current_stock'] ?? 0), intval($item['alert_threshold'] ?? 5),
                            $item['unit'] ?? 'عدد', $item['description'] ?? '',
                            $item['system_type'] ?? 'Madrasa', intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['inventory_items'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "inventory_items: " . $e->getMessage(); }
            }
        }

        // Phase 3: Inventory Sales
        if (!empty($input['inventory_sales']) && is_array($input['inventory_sales'])) {
            foreach ($input['inventory_sales'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM inventory_sales WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE inventory_sales SET sale_no=?, item_id=?, item_name=?, student_id=?, customer_name=?, quantity=?, unit_price=?, total_amount=?, payment_mode=?, voucher_id=?, date=?, remarks=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $item['sale_no'] ?? '', intval($item['item_id'] ?? 0), $item['item_name'] ?? '',
                            to_int_or_null($item['student_id'] ?? null), $item['customer_name'] ?? '',
                            intval($item['quantity'] ?? 1), floatval($item['unit_price'] ?? 0),
                            floatval($item['total_amount'] ?? 0), $item['payment_mode'] ?? 'Cash',
                            to_int_or_null($item['voucher_id'] ?? null), $item['date'] ?? date('Y-m-d'),
                            $item['remarks'] ?? '', $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO inventory_sales (sale_no, item_id, item_name, student_id, customer_name, quantity, unit_price, total_amount, payment_mode, voucher_id, date, remarks, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $item['sale_no'] ?? '', intval($item['item_id'] ?? 0), $item['item_name'] ?? '',
                            to_int_or_null($item['student_id'] ?? null), $item['customer_name'] ?? '',
                            intval($item['quantity'] ?? 1), floatval($item['unit_price'] ?? 0),
                            floatval($item['total_amount'] ?? 0), $item['payment_mode'] ?? 'Cash',
                            to_int_or_null($item['voucher_id'] ?? null), $item['date'] ?? date('Y-m-d'),
                            $item['remarks'] ?? '', $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['inventory_sales'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "inventory_sales: " . $e->getMessage(); }
            }
        }

        // Phase 3: Hostel Rooms
        if (!empty($input['hostel_rooms']) && is_array($input['hostel_rooms'])) {
            foreach ($input['hostel_rooms'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM hostel_rooms WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE hostel_rooms SET room_number=?, room_name=?, capacity_beds=?, monthly_fee=?, status=?, remarks=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $item['room_number'] ?? '', $item['room_name'] ?? '', intval($item['capacity_beds'] ?? 4),
                            floatval($item['monthly_fee'] ?? 0), $item['status'] ?? 'Active',
                            $item['remarks'] ?? '', $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO hostel_rooms (room_number, room_name, capacity_beds, monthly_fee, status, remarks, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $item['room_number'] ?? '', $item['room_name'] ?? '', intval($item['capacity_beds'] ?? 4),
                            floatval($item['monthly_fee'] ?? 0), $item['status'] ?? 'Active',
                            $item['remarks'] ?? '', $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['hostel_rooms'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "hostel_rooms: " . $e->getMessage(); }
            }
        }

        // Phase 3: Hostel Allocations
        if (!empty($input['hostel_allocations']) && is_array($input['hostel_allocations'])) {
            foreach ($input['hostel_allocations'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM hostel_allocations WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE hostel_allocations SET room_id=?, student_id=?, student_name=?, bed_number=?, allocation_date=?, vacating_date=?, status=?, remarks=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            intval($item['room_id'] ?? 0), intval($item['student_id'] ?? 0),
                            $item['student_name'] ?? '', $item['bed_number'] ?? '',
                            $item['allocation_date'] ?? date('Y-m-d'),
                            !empty($item['vacating_date']) ? $item['vacating_date'] : null,
                            $item['status'] ?? 'Occupied', $item['remarks'] ?? '',
                            $item['system_type'] ?? 'Madrasa', intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO hostel_allocations (room_id, student_id, student_name, bed_number, allocation_date, vacating_date, status, remarks, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            intval($item['room_id'] ?? 0), intval($item['student_id'] ?? 0),
                            $item['student_name'] ?? '', $item['bed_number'] ?? '',
                            $item['allocation_date'] ?? date('Y-m-d'),
                            !empty($item['vacating_date']) ? $item['vacating_date'] : null,
                            $item['status'] ?? 'Occupied', $item['remarks'] ?? '',
                            $item['system_type'] ?? 'Madrasa', intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['hostel_allocations'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "hostel_allocations: " . $e->getMessage(); }
            }
        }

        // Phase 4: Job Postings
        if (!empty($input['job_postings']) && is_array($input['job_postings'])) {
            foreach ($input['job_postings'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM job_postings WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE job_postings SET title=?, department=?, job_type=?, requirements=?, salary_range=?, deadline=?, status=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $item['title'] ?? '', $item['department'] ?? '', $item['job_type'] ?? 'Full-time',
                            $item['requirements'] ?? '', $item['salary_range'] ?? '',
                            !empty($item['deadline']) ? $item['deadline'] : null,
                            $item['status'] ?? 'Open', $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO job_postings (title, department, job_type, requirements, salary_range, deadline, status, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $item['title'] ?? '', $item['department'] ?? '', $item['job_type'] ?? 'Full-time',
                            $item['requirements'] ?? '', $item['salary_range'] ?? '',
                            !empty($item['deadline']) ? $item['deadline'] : null,
                            $item['status'] ?? 'Open', $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['job_postings'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "job_postings: " . $e->getMessage(); }
            }
        }

        // Phase 4: Job Applicants
        if (!empty($input['job_applicants']) && is_array($input['job_applicants'])) {
            foreach ($input['job_applicants'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM job_applicants WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE job_applicants SET job_id=?, applicant_name=?, father_name=?, phone=?, email=?, qualification=?, experience=?, resume_path=?, status=?, interview_date=?, interview_notes=?, notes=?, system_type=?, converted_teacher_id=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            intval($item['job_id'] ?? 0), $item['applicant_name'] ?? '',
                            $item['father_name'] ?? '', $item['phone'] ?? '',
                            $item['email'] ?? '', $item['qualification'] ?? '',
                            $item['experience'] ?? '', $item['resume_path'] ?? '',
                            $item['status'] ?? 'Pending',
                            !empty($item['interview_date']) ? $item['interview_date'] : null,
                            $item['interview_notes'] ?? '', $item['notes'] ?? '',
                            $item['system_type'] ?? 'Madrasa',
                            to_int_or_null($item['converted_teacher_id'] ?? null),
                            intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO job_applicants (job_id, applicant_name, father_name, phone, email, qualification, experience, resume_path, status, interview_date, interview_notes, notes, system_type, converted_teacher_id, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            intval($item['job_id'] ?? 0), $item['applicant_name'] ?? '',
                            $item['father_name'] ?? '', $item['phone'] ?? '',
                            $item['email'] ?? '', $item['qualification'] ?? '',
                            $item['experience'] ?? '', $item['resume_path'] ?? '',
                            $item['status'] ?? 'Pending',
                            !empty($item['interview_date']) ? $item['interview_date'] : null,
                            $item['interview_notes'] ?? '', $item['notes'] ?? '',
                            $item['system_type'] ?? 'Madrasa',
                            to_int_or_null($item['converted_teacher_id'] ?? null),
                            intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['job_applicants'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "job_applicants: " . $e->getMessage(); }
            }
        }

        // Phase 4: Web Pages
        if (!empty($input['web_pages']) && is_array($input['web_pages'])) {
            foreach ($input['web_pages'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM web_pages WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE web_pages SET page_slug=?, title=?, content_html=?, meta_description=?, status=?, sort_order=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $item['page_slug'] ?? '', $item['title'] ?? '',
                            $item['content_html'] ?? '', $item['meta_description'] ?? '',
                            $item['status'] ?? 'Published', intval($item['sort_order'] ?? 0),
                            $item['system_type'] ?? 'Madrasa', intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO web_pages (page_slug, title, content_html, meta_description, status, sort_order, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $item['page_slug'] ?? '', $item['title'] ?? '',
                            $item['content_html'] ?? '', $item['meta_description'] ?? '',
                            $item['status'] ?? 'Published', intval($item['sort_order'] ?? 0),
                            $item['system_type'] ?? 'Madrasa', intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['web_pages'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "web_pages: " . $e->getMessage(); }
            }
        }

        // Phase 4: Announcements
        if (!empty($input['announcements']) && is_array($input['announcements'])) {
            foreach ($input['announcements'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $check = $serverId ? $pdo->prepare("SELECT id FROM announcements WHERE id = ? LIMIT 1") : null;
                    if ($check) { $check->execute([$serverId]); $existingId = $check->fetchColumn(); } else { $existingId = null; }

                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE announcements SET title=?, body=?, image_url=?, category=?, is_public=?, target_audience=?, publish_date=?, system_type=?, is_deleted=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([
                            $item['title'] ?? '', $item['body'] ?? '',
                            $item['image_url'] ?? '', $item['category'] ?? 'خبر',
                            intval($item['is_public'] ?? 1), $item['target_audience'] ?? 'All',
                            $item['publish_date'] ?? date('Y-m-d'), $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0), $existingId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO announcements (title, body, image_url, category, is_public, target_audience, publish_date, system_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $item['title'] ?? '', $item['body'] ?? '',
                            $item['image_url'] ?? '', $item['category'] ?? 'خبر',
                            intval($item['is_public'] ?? 1), $item['target_audience'] ?? 'All',
                            $item['publish_date'] ?? date('Y-m-d'), $item['system_type'] ?? 'Madrasa',
                            intval($item['is_deleted'] ?? 0)
                        ]);
                    }
                    if ($localId !== null) $syncedIds['announcements'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "announcements: " . $e->getMessage(); }
            }
        }
    }
}
