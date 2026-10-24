<?php
/**
 * Sync Up Finance & Accounting Module
 * Madrasa Ashraf-ul-Madaris
 */

class SyncUpFinance {
    public static function process(SyncContext $ctx, $input, &$syncedIds, &$serverIds, &$syncErrors) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $tId = (int)$ctx->tenantId;

        // Auto-ensure required columns
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN system_type VARCHAR(50) DEFAULT 'Madrasa'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN deleted_at DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE finance ADD COLUMN original_receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN deleted_at DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE masjid_finance ADD COLUMN original_receipt_no VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN payment_account_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE acc_payroll ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE heads ADD COLUMN tenant_id INT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

        // Finance Entries
        if (!empty($input['finance_entries']) && is_array($input['finance_entries'])) {
            foreach ($input['finance_entries'] as $f) {
                $localId = to_int_or_null($f['id'] ?? null);
                $serverId = to_int_or_null($f['server_id'] ?? null);
                try {
                    if ((int)($f['is_deleted'] ?? 0) === 1) {
                        $deletedServerId = $serverId;
                        if ($serverId) {
                            try {
                                $upd = $pdo->prepare("UPDATE finance SET is_deleted = 1, deleted_at = NOW(), original_receipt_no = COALESCE(original_receipt_no, CAST(computer_receipt_no AS CHAR), manual_receipt_no), computer_receipt_no = NULL WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                                $upd->execute([$serverId, $tId]);
                            } catch (Throwable $eSoft) {
                                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$serverId, $tId]);
                            }
                            finance_accounting_delete_voucher($pdo, $serverId, $tId);
                        } else {
                            $findDeleted = $pdo->prepare("SELECT id FROM finance WHERE date = ? AND type = ? AND amount = ? AND description = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
                            $findDeleted->execute([normalize_date($f['date'] ?? ''), trim($f['type'] ?? ''), (float)($f['amount'] ?? 0), $f['description'] ?? '', $tId]);
                            $deletedServerId = to_int_or_null($findDeleted->fetchColumn());
                            if ($deletedServerId) {
                                try {
                                    $upd = $pdo->prepare("UPDATE finance SET is_deleted = 1, deleted_at = NOW(), original_receipt_no = COALESCE(original_receipt_no, CAST(computer_receipt_no AS CHAR), manual_receipt_no), computer_receipt_no = NULL WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                                    $upd->execute([$deletedServerId, $tId]);
                                } catch (Throwable $eSoft2) {
                                    $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$deletedServerId, $tId]);
                                }
                                finance_accounting_delete_voucher($pdo, $deletedServerId, $tId);
                            }
                        }
                        if ($localId !== null) {
                            $syncedIds['finance_entries'][] = $localId;
                            if ($deletedServerId) $serverIds['finance_entries'][(string)$localId] = (int)$deletedServerId;
                        }
                        continue;
                    }
                    $date = normalize_date($f['date'] ?? '');
                    $type = trim($f['type'] ?? '');
                    $amount = (float)($f['amount'] ?? 0);
                    $desc = $f['description'] ?? '';
                    $uid = to_int_or_null($f['user_id'] ?? null) ?: 1;
                    $headId = to_int_or_null($f['head_id'] ?? null);
                    if (!$headId && !empty($f['head_name'])) {
                        $st = $pdo->prepare("SELECT id FROM heads WHERE name = ? AND tenant_id = ? LIMIT 1");
                        $st->execute([trim($f['head_name']), $tId]);
                        $matchedHead = $st->fetchColumn();
                        if ($matchedHead) {
                            $headId = (int)$matchedHead;
                        }
                    }
                    if (!$headId) {
                        $headId = ensure_head_id($pdo, strtolower($type) === 'expense' ? 'expense' : 'income', $tId);
                    }
                    $accountId = to_int_or_null($f['account_id'] ?? null);
                    $paymentMethod = trim($f['payment_method'] ?? 'Cash');
                    if (empty($paymentMethod)) $paymentMethod = 'Cash';

                    $billImage = trim((string)($f['bill_image'] ?? ''));
                    if (!empty($billImage)) {
                        if (str_contains($billImage, ';base64,') || (strlen($billImage) > 100 && !str_starts_with($billImage, 'uploads/'))) {
                            $cleanBase64 = str_contains($billImage, ';base64,') ? explode(';base64,', $billImage)[1] : $billImage;
                            $decoded = base64_decode($cleanBase64, true);
                            if ($decoded !== false && strlen($decoded) > 50) {
                                $billDir = dirname(__DIR__, 3) . '/uploads/bills/';
                                if (!is_dir($billDir)) @mkdir($billDir, 0777, true);
                                $fn = 'bill_' . time() . '_' . uniqid() . '.jpg';
                                file_put_contents($billDir . $fn, $decoded);
                                $billImage = 'uploads/bills/' . $fn;
                            }
                        }
                    }

                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM finance WHERE id = ? AND tenant_id = ? LIMIT 1");
                        $check->execute([$serverId, $tId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }
                    $userName = trim($f['user_name'] ?? 'Admin');
                    
                    $computer_receipt_no = to_int_or_null($f['computer_receipt_no'] ?? null);
                    $savedId = null;
                    if ($existingId) {
                    $isUserAdmin = in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'superadmin', 'super_admin', 'administrator'], true);
                    if (!$isUserAdmin) {
                        $uid = (int)($user['id'] ?? 1);
                        $userName = !empty($user['name']) ? $user['name'] : (!empty($user['username']) ? $user['username'] : 'User');
                    } else if (!empty($f['user_id'])) {
                        $uid = (int)$f['user_id'];
                        $userName = trim($f['user_name'] ?? 'Admin');
                    }
                        if ($type === 'Income') {
                            $existingNo = $pdo->prepare("SELECT computer_receipt_no FROM finance WHERE id = ? AND tenant_id = ?");
                            $existingNo->execute([$existingId, $tId]);
                            $computer_receipt_no = next_user_receipt_no($pdo, $uid, $computer_receipt_no ?: $existingNo->fetchColumn(), $existingId, 'Madrasa', $tId);
                        } else {
                            $computer_receipt_no = null;
                        }
                        $pdo->prepare("UPDATE finance SET date = ?, type = ?, amount = ?, description = ?, head_id = ?, manual_receipt_no = ?, donor_name = ?, donor_address = ?, computer_receipt_no = ?, user_id = ?, user_name = ?, account_id = ?, payment_method = ?, bill_image = COALESCE(NULLIF(?, ''), bill_image), is_deleted = 0 WHERE id = ? AND tenant_id = ?")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName, $accountId, $paymentMethod, $billImage, $existingId, $tId]);
                        $savedId = (int)$existingId;
                        try {
                            finance_accounting_post($pdo, $savedId);
                        } catch (Throwable $eAcc) {
                            write_log("Sync Up finance_accounting_post update error: " . $eAcc->getMessage());
                        }
                    } else {
                        $computer_receipt_no = ($type === 'Income') ? next_user_receipt_no($pdo, $uid, $computer_receipt_no, null, 'Madrasa', $tId) : null;
                        $pdo->prepare("INSERT INTO finance (date, type, amount, description, head_id, manual_receipt_no, donor_name, donor_address, computer_receipt_no, user_id, user_name, account_id, payment_method, bill_image, system_type, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Madrasa', 0, ?)")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName, $accountId, $paymentMethod, $billImage, $tId]);
                        $savedId = (int)$pdo->lastInsertId();
                        try {
                            finance_accounting_post($pdo, $savedId);
                        } catch (Throwable $eAcc) {
                            write_log("Sync Up finance_accounting_post insert error: " . $eAcc->getMessage());
                        }
                    }
                    if ($localId !== null) {
                        $syncedIds['finance_entries'][] = $localId;
                        if ($savedId) $serverIds['finance_entries'][(string)$localId] = (int)$savedId;
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Sync (Finance): " . $e->getMessage());
                    $syncErrors[] = "finance_entries: " . $e->getMessage();
                }
            }
        }

        // Masjid Finance Entries
        if (!empty($input['masjid_finance_entries']) && is_array($input['masjid_finance_entries'])) {
            foreach ($input['masjid_finance_entries'] as $f) {
                $localId = to_int_or_null($f['id'] ?? null);
                $serverId = to_int_or_null($f['server_id'] ?? null);
                try {
                    if ((int)($f['is_deleted'] ?? 0) === 1) {
                        $deletedServerId = $serverId;
                        if ($serverId) {
                            try {
                                $upd = $pdo->prepare("UPDATE finance SET is_deleted = 1, deleted_at = NOW(), original_receipt_no = COALESCE(original_receipt_no, CAST(computer_receipt_no AS CHAR), manual_receipt_no), computer_receipt_no = NULL WHERE id = ? AND system_type = 'Masjid' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                                $upd->execute([$serverId, $tId]);
                            } catch (Throwable $eSoftM) {
                                $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ? AND system_type = 'Masjid' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$serverId, $tId]);
                            }
                        } else {
                            $findDeleted = $pdo->prepare("SELECT id FROM finance WHERE date = ? AND type = ? AND amount = ? AND description = ? AND system_type = 'Masjid' AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
                            $findDeleted->execute([normalize_date($f['date'] ?? ''), trim($f['type'] ?? ''), (float)($f['amount'] ?? 0), $f['description'] ?? '', $tId]);
                            $deletedServerId = to_int_or_null($findDeleted->fetchColumn());
                            if ($deletedServerId) {
                                try {
                                    $upd = $pdo->prepare("UPDATE finance SET is_deleted = 1, deleted_at = NOW(), original_receipt_no = COALESCE(original_receipt_no, CAST(computer_receipt_no AS CHAR), manual_receipt_no), computer_receipt_no = NULL WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)");
                                    $upd->execute([$deletedServerId, $tId]);
                                } catch (Throwable $eSoftM2) {
                                    $pdo->prepare("UPDATE finance SET is_deleted = 1 WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0)")->execute([$deletedServerId, $tId]);
                                }
                            }
                        }
                        if ($localId !== null) {
                            $syncedIds['masjid_finance_entries'][] = $localId;
                            if ($deletedServerId) $serverIds['finance_entries'][(string)$localId] = (int)$deletedServerId;
                        }
                        continue;
                    }
                    $date = normalize_date($f['date'] ?? '');
                    $type = trim($f['type'] ?? '');
                    $amount = (float)($f['amount'] ?? 0);
                    $desc = $f['description'] ?? '';
                    $uid = to_int_or_null($f['user_id'] ?? null) ?: 1;
                    $headId = to_int_or_null($f['head_id'] ?? null);
                    if (!$headId) {
                        $headId = ensure_head_id($pdo, strtolower($type) === 'expense' ? 'expense' : 'income', $tId);
                    }
                    $computer_receipt_no = to_int_or_null($f['computer_receipt_no'] ?? null);
                    $userName = trim($f['user_name'] ?? 'Admin');
                    $accountId = to_int_or_null($f['account_id'] ?? null);
                    $paymentMethod = trim($f['payment_method'] ?? 'Cash');
                    if (empty($paymentMethod)) $paymentMethod = 'Cash';

                    $billImage = trim((string)($f['bill_image'] ?? ''));
                    if (!empty($billImage)) {
                        if (str_contains($billImage, ';base64,') || (strlen($billImage) > 100 && !str_starts_with($billImage, 'uploads/'))) {
                            $cleanBase64 = str_contains($billImage, ';base64,') ? explode(';base64,', $billImage)[1] : $billImage;
                            $decoded = base64_decode($cleanBase64, true);
                            if ($decoded !== false && strlen($decoded) > 50) {
                                $billDir = dirname(__DIR__, 3) . '/uploads/bills/';
                                if (!is_dir($billDir)) @mkdir($billDir, 0777, true);
                                $fn = 'bill_' . time() . '_' . uniqid() . '.jpg';
                                file_put_contents($billDir . $fn, $decoded);
                                $billImage = 'uploads/bills/' . $fn;
                            }
                        }
                    }

                    $savedId = null;
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM finance WHERE id = ? AND system_type = 'Masjid' AND tenant_id = ? LIMIT 1");
                        $check->execute([$serverId, $tId]);
                        $existingId = $check->fetchColumn();
                    } else {
                        $existingId = null;
                    }
                    if ($existingId) {
                    $isUserAdmin = in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'superadmin', 'super_admin', 'administrator'], true);
                    if (!$isUserAdmin) {
                        $uid = (int)($user['id'] ?? 1);
                        $userName = !empty($user['name']) ? $user['name'] : (!empty($user['username']) ? $user['username'] : 'User');
                    } else if (!empty($f['user_id'])) {
                        $uid = (int)$f['user_id'];
                        $userName = trim($f['user_name'] ?? 'Admin');
                    }
                        if ($type === 'Income') {
                            $existingNo = $pdo->prepare("SELECT computer_receipt_no FROM finance WHERE id = ? AND system_type = 'Masjid' AND tenant_id = ?");
                            $existingNo->execute([$existingId, $tId]);
                            $computer_receipt_no = next_user_receipt_no($pdo, $uid, $computer_receipt_no ?: $existingNo->fetchColumn(), $existingId, 'Masjid', $tId);
                        } else {
                            $computer_receipt_no = null;
                        }
                        $pdo->prepare("UPDATE finance SET date = ?, type = ?, amount = ?, description = ?, head_id = ?, manual_receipt_no = ?, donor_name = ?, donor_address = ?, computer_receipt_no = ?, user_id = ?, user_name = ?, account_id = ?, payment_method = ?, bill_image = COALESCE(NULLIF(?, ''), bill_image), system_type = 'Masjid', is_deleted = 0 WHERE id = ? AND tenant_id = ?")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName, $accountId, $paymentMethod, $billImage, $existingId, $tId]);
                        try { finance_accounting_post($pdo, $savedId); } catch (Throwable $e) {}
                    } else {
                        $computer_receipt_no = ($type === 'Income') ? next_user_receipt_no($pdo, $uid, $computer_receipt_no, null, 'Masjid', $tId) : null;
                        $pdo->prepare("INSERT INTO finance (date, type, amount, description, head_id, manual_receipt_no, donor_name, donor_address, computer_receipt_no, user_id, user_name, account_id, payment_method, bill_image, system_type, is_deleted, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Masjid', 0, ?)")
                            ->execute([$date, $type, $amount, $desc, $headId, $f['manual_receipt_no'] ?? '', $f['donor_name'] ?? '', $f['donor_address'] ?? '', $computer_receipt_no, $uid, $userName, $accountId, $paymentMethod, $billImage, $tId]);
                        $savedId = (int)$pdo->lastInsertId();
                        try { finance_accounting_post($pdo, $savedId); } catch (Throwable $e) {}
                    }
                    if ($localId !== null) {
                        $syncedIds['masjid_finance_entries'][] = $localId;
                        if ($savedId) $serverIds['masjid_finance_entries'][(string)$localId] = (int)$savedId;
                    }
                } catch (Exception $e) {
                    write_log("SQL Error in Sync (Masjid Finance): " . $e->getMessage());
                    $syncErrors[] = "masjid_finance_entries: " . $e->getMessage();
                }
            }
        }

        // Heads
        if (!empty($input['heads']) && is_array($input['heads'])) {
            foreach ($input['heads'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $name = trim($item['name'] ?? '');
                    $type = trim($item['type'] ?? '');
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $isDeleted = (int)($item['is_deleted'] ?? 0);
                    if ($name === '') continue;
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM heads WHERE id = ? LIMIT 1");
                        $check->execute([$serverId]);
                    } else {
                        $check = $pdo->prepare("SELECT id FROM heads WHERE name = ? AND type = ? LIMIT 1");
                        $check->execute([$name, $type]);
                    }
                    $existingId = $check->fetchColumn();
                    if ($isDeleted === 1) {
                        if ($existingId) {
                            try { $pdo->prepare("UPDATE heads SET is_deleted = 1 WHERE id = ?")->execute([$existingId]); }
                            catch (Exception $e) { $pdo->prepare("DELETE FROM heads WHERE id = ?")->execute([$existingId]); }
                        }
                        if ($localId !== null) $syncedIds['heads'][] = $localId;
                        continue;
                    }
                    if ($existingId) {
                        try { $pdo->prepare("UPDATE heads SET name = ?, type = ?, is_deleted = 0 WHERE id = ?")->execute([$name, $type, $existingId]); }
                        catch (Exception $e) { $pdo->prepare("UPDATE heads SET name = ?, type = ? WHERE id = ?")->execute([$name, $type, $existingId]); }
                    } else {
                        $pdo->prepare("INSERT INTO heads (name, type) VALUES (?, ?)")->execute([$name, $type]);
                    }
                    if ($localId !== null) $syncedIds['heads'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "heads: " . $e->getMessage(); }
            }
        }

        // Chart of Accounts
        if (!empty($input['acc_chart_of_accounts']) && is_array($input['acc_chart_of_accounts'])) {
            foreach ($input['acc_chart_of_accounts'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $code = trim($item['code'] ?? '');
                    $name = trim($item['name'] ?? '');
                    $serverId = to_int_or_null($item['id'] ?? null);
                    $isDeleted = (int)($item['is_deleted'] ?? 0);
                    
                    $existing = false;
                    if ($serverId) {
                        $check = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE id = ? LIMIT 1");
                        $check->execute([$serverId]);
                        $existing = $check->fetch();
                    } elseif ($code !== '') {
                        $check = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = ? LIMIT 1");
                        $check->execute([$code]);
                        $existing = $check->fetch();
                    }
                    
                    if ($isDeleted === 1) {
                        if ($existing) {
                            $pdo->prepare("UPDATE acc_chart_of_accounts SET is_deleted = 1 WHERE id = ?")->execute([$existing['id']]);
                        }
                        if ($localId !== null) $syncedIds['acc_chart_of_accounts'][] = $localId;
                        continue;
                    }
                    
                    if ($name === '') continue;
                    
                    if ($existing) {
                        $pdo->prepare("UPDATE acc_chart_of_accounts SET name = ?, type = ?, code = ?, is_deleted = 0 WHERE id = ?")
                            ->execute([$name, $item['type'] ?? '', $code, $existing['id']]);
                    } else {
                        if ($code === '') $code = 'ACC-' . time() . rand(10,99);
                        $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_deleted) VALUES (?, ?, ?, 0)")
                            ->execute([$code, $name, $item['type'] ?? '']);
                    }
                    if ($localId !== null) $syncedIds['acc_chart_of_accounts'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "acc_chart_of_accounts: " . $e->getMessage(); }
            }
        }

        // Vouchers
        if (!empty($input['acc_vouchers']) && is_array($input['acc_vouchers'])) {
            foreach ($input['acc_vouchers'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $voucherNo = trim($item['voucher_no'] ?? '');
                    if ($voucherNo === '') continue;
                    $check = $pdo->prepare("SELECT id FROM acc_vouchers WHERE voucher_no = ? LIMIT 1");
                    $check->execute([$voucherNo]);
                    $item_is_deleted = (int)($item['is_deleted'] ?? 0);
                    if ($item_is_deleted == 1) {
                        if ($check->rowCount() > 0) {
                            $existing = $check->fetch();
                            $pdo->prepare("DELETE FROM acc_vouchers WHERE id = ?")->execute([$existing['id']]);
                        }
                        if ($localId !== null) $syncedIds['acc_vouchers'][] = $localId;
                        continue;
                    }
                    
                    $payload = [
                        normalize_date($item['date'] ?? ''),
                        $item['type'] ?? 'Journal',
                        $item['reference'] ?? '',
                        $item['description'] ?? '',
                        to_int_or_null($item['department_id'] ?? null),
                        $item['status'] ?? 'Pending',
                        to_int_or_null($item['created_by'] ?? null) ?: $user['id'],
                    ];
                    $systemType = !empty($item['system_type']) ? trim($item['system_type']) : ($requested_sys ?: 'Madrasa');
                    if ($check->rowCount() > 0) {
                        $existing = $check->fetch();
                        try {
                            $pdo->prepare("UPDATE acc_vouchers SET date = ?, type = ?, reference = ?, description = ?, department_id = ?, status = ?, created_by = ?, system_type = ? WHERE id = ?")
                                ->execute([...$payload, $systemType, $existing['id']]);
                        } catch (Throwable $e) {
                            $pdo->prepare("UPDATE acc_vouchers SET date = ?, type = ?, reference = ?, description = ?, department_id = ?, status = ?, created_by = ? WHERE id = ?")
                                ->execute([...$payload, $existing['id']]);
                        }
                    } else {
                        try {
                            $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, department_id, status, created_by, system_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$voucherNo, ...$payload, $systemType]);
                        } catch (Throwable $e) {
                            $pdo->prepare("INSERT INTO acc_vouchers (voucher_no, date, type, reference, description, department_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$voucherNo, ...$payload]);
                        }
                    }
                    if ($localId !== null) $syncedIds['acc_vouchers'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "acc_vouchers: " . $e->getMessage(); }
            }
        }

        // Ledger Entries
        if (!empty($input['acc_ledger_entries']) && is_array($input['acc_ledger_entries'])) {
            foreach ($input['acc_ledger_entries'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $voucherId = to_int_or_null($item['voucher_id'] ?? null);
                    $voucherNo = trim($item['voucher_no'] ?? '');
                    if ($voucherNo !== '') {
                        $voucherStmt = $pdo->prepare("SELECT id FROM acc_vouchers WHERE voucher_no = ? LIMIT 1");
                        $voucherStmt->execute([$voucherNo]);
                        $serverVoucherId = $voucherStmt->fetchColumn();
                        if ($serverVoucherId) $voucherId = (int)$serverVoucherId;
                    }
                    $accountId = to_int_or_null($item['account_id'] ?? null);
                    $accountCode = trim($item['account_code'] ?? '');
                    if ($accountCode !== '') {
                        $accountStmt = $pdo->prepare("SELECT id FROM acc_chart_of_accounts WHERE code = ? LIMIT 1");
                        $accountStmt->execute([$accountCode]);
                        $serverAccountId = $accountStmt->fetchColumn();
                        if ($serverAccountId) $accountId = (int)$serverAccountId;
                    }
                    if (!$voucherId || !$accountId) continue;
                    $debit = (float)($item['debit'] ?? 0);
                    $credit = (float)($item['credit'] ?? 0);
                    $check = $pdo->prepare("SELECT id FROM acc_ledger_entries WHERE voucher_id = ? AND account_id = ? AND debit = ? AND credit = ? LIMIT 1");
                    $check->execute([$voucherId, $accountId, $debit, $credit]);
                    if ($check->rowCount() > 0) {
                        $existing = $check->fetch();
                        $pdo->prepare("UPDATE acc_ledger_entries SET department_id = ? WHERE id = ?")
                            ->execute([to_int_or_null($item['department_id'] ?? null), $existing['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO acc_ledger_entries (voucher_id, account_id, debit, credit, department_id) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$voucherId, $accountId, $debit, $credit, to_int_or_null($item['department_id'] ?? null)]);
                    }
                    if ($localId !== null) $syncedIds['acc_ledger_entries'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "acc_ledger_entries: " . $e->getMessage(); }
            }
        }

        // Payroll
        if (!empty($input['acc_payroll']) && is_array($input['acc_payroll'])) {
            foreach ($input['acc_payroll'] as $item) {
                try {
                    $check = $pdo->prepare("SELECT id FROM acc_payroll WHERE teacher_id = ? AND month_year = ?");
                    $check->execute([$item['teacher_id'], $item['month_year']]);
                    $existing = $check->fetch(PDO::FETCH_ASSOC);
                    $bSalary = floatval($item['basic_salary'] ?? 0);
                    $bAllow = floatval($item['allowances'] ?? 0);
                    $bDed = floatval($item['deductions'] ?? 0);
                    $bNet = isset($item['net_salary']) ? floatval($item['net_salary']) : max(0, $bSalary + $bAllow - $bDed);
                    $pSys = $item['system_type'] ?? 'Madrasa';
                    $pStat = $item['status'] ?? 'Unpaid';
                    $pPayDate = !empty($item['payment_date']) ? $item['payment_date'] : null;
                    $pAcctId = to_int_or_null($item['payment_account_id'] ?? null);
                    $pMethod = trim($item['payment_method'] ?? 'Cash');
                    if (empty($pMethod)) $pMethod = 'Cash';
                    $pDel = intval($item['is_deleted'] ?? 0);

                    if ($existing) {
                        $pdo->prepare("UPDATE acc_payroll SET basic_salary = ?, allowances = ?, deductions = ?, net_salary = ?, status = ?, payment_date = ?, payment_account_id = ?, payment_method = ?, system_type = ?, is_deleted = ? WHERE id = ?")
                            ->execute([$bSalary, $bAllow, $bDed, $bNet, $pStat, $pPayDate, $pAcctId, $pMethod, $pSys, $pDel, $existing['id']]);
                        payroll_accounting_post($pdo, (int)$existing['id']);
                        $syncedIds['acc_payroll'][] = $item['id'];
                    } else {
                        $pdo->prepare("INSERT INTO acc_payroll (teacher_id, month_year, basic_salary, allowances, deductions, net_salary, status, payment_date, payment_account_id, payment_method, system_type, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$item['teacher_id'], $item['month_year'], $bSalary, $bAllow, $bDed, $bNet, $pStat, $pPayDate, $pAcctId, $pMethod, $pSys, $pDel]);
                        payroll_accounting_post($pdo, (int)$pdo->lastInsertId());
                        $syncedIds['acc_payroll'][] = $item['id'];
                    }
                } catch (Exception $e) { $syncErrors[] = "acc_payroll: " . $e->getMessage(); }
            }
        }

        // Invoices
        if (!empty($input['acc_invoices']) && is_array($input['acc_invoices'])) {
            foreach ($input['acc_invoices'] as $item) {
                try {
                    $check = $pdo->prepare("SELECT id FROM acc_invoices WHERE student_id = ? AND month_year = ?");
                    $check->execute([$item['student_id'], $item['month_year']]);
                    if ($check->rowCount() > 0) {
                        $existing = $check->fetch();
                        $pdo->prepare("UPDATE acc_invoices SET amount = ?, status = ? WHERE id = ?")->execute([$item['amount'] ?? 0, $item['status'] ?? 'Unpaid', $existing['id']]);
                        $syncedIds['acc_invoices'][] = $item['id'];
                    } else {
                        $pdo->prepare("INSERT INTO acc_invoices (student_id, invoice_no, month_year, amount, status) VALUES (?, ?, ?, ?, ?)")->execute([$item['student_id'], $item['invoice_no'] ?? '', $item['month_year'], $item['amount'] ?? 0, $item['status'] ?? 'Unpaid']);
                        $syncedIds['acc_invoices'][] = $item['id'];
                    }
                } catch (Exception $e) { $syncErrors[] = "acc_invoices: " . $e->getMessage(); }
            }
        }

        // Staff Loans
        if (!empty($input['staff_loans']) && is_array($input['staff_loans'])) {
            foreach ($input['staff_loans'] as $item) {
                try {
                    $loanId = to_int_or_null($item['id'] ?? null);
                    $teacherId = to_int_or_null($item['teacher_id'] ?? null);
                    $bName = $item['borrower_name'] ?? '';
                    $phone = $item['phone'] ?? '';
                    $dir = $item['loan_direction'] ?? 'Given';
                    $lAmt = (float)($item['loan_amount'] ?? 0);
                    $instAmt = (float)($item['installment_amount'] ?? 0);
                    $repAmt = (float)($item['repaid_amount'] ?? 0);
                    $remAmt = (float)($item['remaining_amount'] ?? $lAmt);
                    $dIssued = normalize_date($item['date_issued'] ?? date('Y-m-d'));
                    $dDue = !empty($item['due_date']) ? normalize_date($item['due_date']) : null;
                    $desc = $item['description'] ?? '';
                    $status = $item['status'] ?? 'Active';
                    $sys = $item['system_type'] ?? 'Madrasa';
                    $isDel = (int)($item['is_deleted'] ?? 0);

                    if ($loanId) {
                        $check = $pdo->prepare("SELECT id FROM staff_loans WHERE id = ?");
                        $check->execute([$loanId]);
                        if ($check->fetch()) {
                            $pdo->prepare("UPDATE staff_loans SET teacher_id = ?, borrower_name = ?, phone = ?, loan_direction = ?, loan_amount = ?, installment_amount = ?, repaid_amount = ?, remaining_amount = ?, date_issued = ?, due_date = ?, description = ?, status = ?, system_type = ?, is_deleted = ? WHERE id = ?")
                                ->execute([$teacherId, $bName, $phone, $dir, $lAmt, $instAmt, $repAmt, $remAmt, $dIssued, $dDue, $desc, $status, $sys, $isDel, $loanId]);
                            $syncedIds['staff_loans'][] = $item['id'];
                            continue;
                        }
                    }

                    $pdo->prepare("INSERT INTO staff_loans (teacher_id, borrower_name, phone, loan_direction, loan_amount, installment_amount, repaid_amount, remaining_amount, date_issued, due_date, description, status, system_type, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$teacherId, $bName, $phone, $dir, $lAmt, $instAmt, $repAmt, $remAmt, $dIssued, $dDue, $desc, $status, $sys, $isDel]);
                    $syncedIds['staff_loans'][] = $item['id'];
                } catch (Exception $e) { $syncErrors[] = "staff_loans: " . $e->getMessage(); }
            }
        }

        // Loan Transactions
        if (!empty($input['loan_transactions']) && is_array($input['loan_transactions'])) {
            foreach ($input['loan_transactions'] as $item) {
                try {
                    $txId = to_int_or_null($item['id'] ?? null);
                    $loanId = to_int_or_null($item['loan_id'] ?? null);
                    $type = $item['type'] ?? 'Recovery';
                    $blName = $item['borrower_lender_name'] ?? '';
                    $amount = (float)($item['amount'] ?? 0);
                    $date = normalize_date($item['date'] ?? date('Y-m-d'));
                    $method = $item['payment_method'] ?? 'Cash';
                    $remarks = $item['remarks'] ?? '';
                    $sys = $item['system_type'] ?? 'Madrasa';
                    $createdBy = to_int_or_null($item['created_by'] ?? 1) ?: 1;
                    $isDel = (int)($item['is_deleted'] ?? 0);

                    if ($txId) {
                        $check = $pdo->prepare("SELECT id FROM loan_transactions WHERE id = ?");
                        $check->execute([$txId]);
                        if ($check->fetch()) {
                            $pdo->prepare("UPDATE loan_transactions SET loan_id = ?, type = ?, borrower_lender_name = ?, amount = ?, date = ?, payment_method = ?, remarks = ?, system_type = ?, created_by = ?, is_deleted = ? WHERE id = ?")
                                ->execute([$loanId, $type, $blName, $amount, $date, $method, $remarks, $sys, $createdBy, $isDel, $txId]);
                            $syncedIds['loan_transactions'][] = $item['id'];
                            continue;
                        }
                    }

                    $pdo->prepare("INSERT INTO loan_transactions (loan_id, type, borrower_lender_name, amount, date, payment_method, remarks, system_type, created_by, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$loanId, $type, $blName, $amount, $date, $method, $remarks, $sys, $createdBy, $isDel]);
                    $syncedIds['loan_transactions'][] = $item['id'];
                } catch (Exception $e) { $syncErrors[] = "loan_transactions: " . $e->getMessage(); }
            }
        }

        // In-Kind Donations
        if (!empty($input['in_kind_donations']) && is_array($input['in_kind_donations'])) {
            foreach ($input['in_kind_donations'] as $item) {
                $localId = to_int_or_null($item['id'] ?? null);
                try {
                    $itemName = trim((string)($item['item_name'] ?? ''));
                    if ($itemName !== '') {
                        $donorName = trim((string)($item['donor_name'] ?? ''));
                        $dateVal = normalize_date($item['date'] ?? '') ?: date('Y-m-d');
                        $sysType = trim((string)($item['system_type'] ?? 'Masjid'));
                        if ($sysType === '') $sysType = 'Masjid';

                        $receiptNo = to_int_or_null($item['computer_receipt_no'] ?? null);
                        $isDeleted = (int)($item['is_deleted'] ?? 0);
                        $existingId = null;
                        if ($receiptNo) {
                            $check = $pdo->prepare("SELECT id FROM in_kind_donations WHERE computer_receipt_no = ? LIMIT 1");
                            $check->execute([$receiptNo]);
                            $existingId = $check->fetchColumn();
                        }

                        if ($existingId) {
                            $stmt = $pdo->prepare("UPDATE in_kind_donations SET date=?, donor_name=?, donor_phone=?, donor_address=?, item_name=?, quantity=?, estimated_value=?, description=?, system_type=?, is_deleted=? WHERE id=?");
                            $stmt->execute([$dateVal, $donorName, $item['donor_phone'] ?? '', $item['donor_address'] ?? '', $itemName, (int)($item['quantity'] ?? 1), (float)($item['estimated_value'] ?? 0), $item['description'] ?? '', $sysType, $isDeleted, $existingId]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO in_kind_donations (date, donor_name, donor_phone, donor_address, item_name, quantity, estimated_value, description, computer_receipt_no, system_type, user_id, user_name, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$dateVal, $donorName, $item['donor_phone'] ?? '', $item['donor_address'] ?? '', $itemName, (int)($item['quantity'] ?? 1), (float)($item['estimated_value'] ?? 0), $item['description'] ?? '', $receiptNo, $sysType, $user['id'] ?? 1, $user['name'] ?? 'Admin', $isDeleted]);
                        }
                    }
                    if ($localId !== null) $syncedIds['in_kind_donations'][] = $localId;
                } catch (Exception $e) { $syncErrors[] = "in_kind_donations: " . $e->getMessage(); }
            }
        }
    }
}
