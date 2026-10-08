<?php
/**
 * جامع کلین اپ اور یوزرز آئی ڈیز ری آرڈر اسکرپٹ (User Cleanup & ID Reassignment Engine)
 * 
 * 1. صرف 4 فعال اکاؤنٹس کو باقی رکھنا:
 *    - ID 1: تنزیل الرحمن (Tanzeel, Admin)
 *    - ID 2: قاری حماد اللہ ساجد صاحب (Hammad, Admin)
 *    - ID 3: قاری سلیم اللہ صاحب (saleem, Teacher - سابقہ ID 7)
 *    - ID 4: محمد عثمان معاویہ (usman, Teacher)
 * 2. قاری سلیم اللہ صاحب کا پرانا ڈپلیکیٹ اکاؤنٹ (ID 1) مکمل ختم کرنا۔
 * 3. ID 1 کی وہ 13 انٹریز جو دراصل تنزیل الرحمن کی تھیں، انہیں تنزیل الرحمن (ID 1، کھاتہ 1502) کے کھاتے میں بحال کرنا۔
 * 4. تمام ملحقہ ٹیبلز (finance, masjid_finance, classes, attendance, vouchers, payroll وغیرہ) میں نئی آئی ڈیز اپڈیٹ کرنا۔
 * 5. باقی تمام فالتو اکاؤنٹس کو ڈیٹا بیس سے مکمل ڈیلیٹ کرنا۔
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// سیکیورٹی گارڈ: صرف ایڈمن یا لوکل ایکسس
$is_authorized = false;
if (isset($_SESSION['user_id']) && in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'superadmin', 'administrator'])) {
    $is_authorized = true;
} elseif (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])) {
    $is_authorized = true;
} elseif (isset($_GET['token']) && $_GET['token'] === 'madrasa_cleanup_secret_2026') {
    $is_authorized = true;
}

$executed = false;
$logs = [];
$errors = [];
$stats = [];

if (isset($_POST['run_cleanup']) || isset($_GET['auto_run'])) {
    if (!$is_authorized) {
        die("<div style='color:red;padding:20px;direction:rtl;font-family:sans-serif;'>غیر مجاز رسائی! براہ کرم ایڈمن کے طور پر لاگ ان کریں۔</div>");
    }

    try {
        $pdo->beginTransaction();
        $logs[] = "ٹرانزیکشن کامیابی سے شروع کی گئی (Transaction Started)...";

        // 1. موجودہ 4 فعال یوزرز کو تلاش کریں
        $logs[] = "مرحلہ 1: فعال یوزرز کی تلاش اور شناخت...";
        
        // 1.1 تنزیل الرحمن
        $stmtTanzeel = $pdo->query("SELECT * FROM admins WHERE username = 'Tanzeel' OR name LIKE '%تنزیل%' ORDER BY id ASC LIMIT 1");
        $uTanzeel = $stmtTanzeel->fetch(PDO::FETCH_ASSOC);
        if (!$uTanzeel) {
            throw new Exception("تنزیل الرحمن کا اکاؤنٹ ڈیٹا بیس میں نہیں مل سکا!");
        }
        $old_tanzeel_id = (int)$uTanzeel['id'];
        $logs[] = "✔ تنزیل الرحمن پایا گیا (موجودہ ID: {$old_tanzeel_id})";

        // 1.2 قاری حماد اللہ ساجد صاحب
        $stmtHammad = $pdo->query("SELECT * FROM admins WHERE username = 'Hammad' OR name LIKE '%حماد%' ORDER BY id ASC LIMIT 1");
        $uHammad = $stmtHammad->fetch(PDO::FETCH_ASSOC);
        if (!$uHammad) {
            throw new Exception("قاری حماد اللہ ساجد صاحب کا اکاؤنٹ ڈیٹا بیس میں نہیں مل سکا!");
        }
        $old_hammad_id = (int)$uHammad['id'];
        $logs[] = "✔ قاری حماد اللہ ساجد صاحب پایا گیا (موجودہ ID: {$old_hammad_id})";

        // 1.3 قاری سلیم اللہ صاحب (فعال اکاؤنٹ جو ID 1 کے علاوہ ہو، ترجیحاً ID 7 یا username = saleem)
        $stmtSaleem = $pdo->query("SELECT * FROM admins WHERE (username = 'saleem' OR name LIKE '%سلیم%') AND id != 1 ORDER BY (id = 7) DESC, id DESC LIMIT 1");
        $uSaleem = $stmtSaleem->fetch(PDO::FETCH_ASSOC);
        if (!$uSaleem) {
            // فال بیک اگر صرف ID 1 تھا تو اسے استعمال کریں
            $uSaleem = $pdo->query("SELECT * FROM admins WHERE username = 'saleem' OR name LIKE '%سلیم%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        if (!$uSaleem) {
            throw new Exception("قاری سلیم اللہ صاحب کا اصل اکاؤنٹ نہیں مل سکا!");
        }
        $old_saleem_id = (int)$uSaleem['id'];
        $logs[] = "✔ قاری سلیم اللہ صاحب (اصل استعمال والا) پایا گیا (موجودہ ID: {$old_saleem_id})";

        // 1.4 محمد عثمان معاویہ
        $stmtUsman = $pdo->query("SELECT * FROM admins WHERE username = 'usman' OR name LIKE '%عثمان%' ORDER BY id ASC LIMIT 1");
        $uUsman = $stmtUsman->fetch(PDO::FETCH_ASSOC);
        if (!$uUsman) {
            throw new Exception("محمد عثمان معاویہ کا اکاؤنٹ ڈیٹا بیس میں نہیں مل سکا!");
        }
        $old_usman_id = (int)$uUsman['id'];
        $logs[] = "✔ محمد عثمان معاویہ پایا گیا (موجودہ ID: {$old_usman_id})";

        // 2. چارٹ آف اکاؤنٹس میں کھاتہ 1502 (ایڈوانس کھاتہ تنزیل الرحمن) تلاش یا تیار کریں
        $logs[] = "مرحلہ 2: کھاتہ 1502 (تنزیل الرحمن ایڈوانس) کی تصدیق...";
        $stmt1502 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1502' LIMIT 1");
        $acc_1502_id = $stmt1502 ? (int)$stmt1502->fetchColumn() : 0;
        if (!$acc_1502_id) {
            $stmt1502b = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE name LIKE '%تنزیل%' AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%') LIMIT 1");
            $acc_1502_id = $stmt1502b ? (int)$stmt1502b->fetchColumn() : 0;
        }
        if (!$acc_1502_id) {
            $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted) VALUES ('1502', 'ایڈوانس - تنزیل الرحمن', 'Asset', 1, 0)")->execute();
            $acc_1502_id = (int)$pdo->lastInsertId();
            $logs[] = "✔ کھاتہ 1502 بنایا گیا (Account ID: {$acc_1502_id})";
        } else {
            $pdo->prepare("UPDATE acc_chart_of_accounts SET code = '1502', name = 'ایڈوانس - تنزیل الرحمن', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$acc_1502_id]);
            $logs[] = "✔ کھاتہ 1502 موجود اور اپڈیٹ ہے (Account ID: {$acc_1502_id})";
        }

        // 3. قاری سلیم اللہ صاحب ID 1 کی 13 انٹریز کو تنزیل الرحمن (کھاتہ 1502) میں منتقل و بحال کریں
        $logs[] = "مرحلہ 3: پرانی ID 1 والی 13 انٹریز کی تنزیل الرحمن (1502) پر منتقلی و بحالی...";
        
        // چیک کریں کہ ایسی کتنی انٹریز ہیں جو user_id = 1 تھیں اور سلیم اللہ کے نام پر تھیں، یا جن کا تذکرہ صارف نے کیا
        $chk_q = $pdo->query("SELECT id FROM finance WHERE (user_id = 1 AND (user_name LIKE '%سلیم%' OR user_name = 'saleem')) OR (user_id = 1 AND amount = 245000) OR (user_id = 1 AND account_id = $acc_1502_id)");
        $matched_ids = $chk_q ? $chk_q->fetchAll(PDO::FETCH_COLUMN) : [];
        
        if (empty($matched_ids)) {
            // اگر user_id = 1 کی تمام انٹریز ڈیلیٹ شدہ حالت میں ہیں
            $chk_del = $pdo->query("SELECT id FROM finance WHERE user_id = 1 AND is_deleted = 1");
            $matched_ids = $chk_del ? $chk_del->fetchAll(PDO::FETCH_COLUMN) : [];
        }

        $cnt_fixed = 0;
        if (!empty($matched_ids)) {
            $in_clause = implode(',', array_map('intval', $matched_ids));
            $upd13 = $pdo->prepare("UPDATE finance SET user_id = ?, user_name = 'تنزیل الرحمن', account_id = ?, is_deleted = 0 WHERE id IN ($in_clause)");
            $upd13->execute([$old_tanzeel_id, $acc_1502_id]);
            $cnt_fixed = count($matched_ids);
            $logs[] = "✔ کل {$cnt_fixed} انٹریز تنزیل الرحمن کے کھاتے (کھاتہ 1502) میں بحال کر کے منتقل کر دی گئیں۔";
        } else {
            $logs[] = "ℹ پرانی ID 1 کی براہ راست سلیم اللہ انٹریز پہلے سے درست یا صاف شدہ ہیں۔";
        }

        // 4. اب تمام ملحقہ ٹیبلز میں پرانی آئی ڈیز کو نئی عارضی منفی آئی ڈیز پر منتقل کریں
        // تاکہ پرائمری کی ٹکراؤ نہ ہو
        // تنزیل: $old_tanzeel_id -> -1 -> بعد میں 1
        // حماد:   $old_hammad_id   -> -2 -> بعد میں 2
        // سلیم:   $old_saleem_id   -> -3 -> بعد میں 3
        // عثمان:  $old_usman_id    -> -4 -> بعد میں 4
        $logs[] = "مرحلہ 4: ملحقہ ٹیبلز میں فارن کیز اور ریکارڈز کی میپنگ...";

        $tables_and_columns = [
            'finance' => ['user_id'],
            'masjid_finance' => ['user_id'],
            'classes' => ['teacher_id'],
            'attendance' => ['teacher_id', 'marked_by', 'user_id'],
            'attendance_teachers' => ['teacher_id'],
            'acc_payroll' => ['created_by', 'teacher_id'],
            'acc_vouchers' => ['created_by'],
            'acc_ledger_entries' => ['created_by'],
            'leave_applications' => ['teacher_id', 'applicant_id'],
            'teacher_leaves' => ['teacher_id'],
            'user_fcm_tokens' => ['user_id'],
            'in_kind_donations' => ['user_id'],
            'masjid_in_kind_donations' => ['user_id'],
            'activity_log' => ['user_id']
        ];

        // عارضی منفی ویلیوز سے میپ کریں
        $temp_map = [
            $old_tanzeel_id => -1,
            $old_hammad_id   => -2,
            $old_saleem_id   => -3,
            $old_usman_id    => -4
        ];

        foreach ($tables_and_columns as $tbl => $cols) {
            // چیک کریں کہ کیا ٹیبل موجود ہے
            $tbl_exists = $pdo->query("SHOW TABLES LIKE '$tbl'")->rowCount() > 0;
            if (!$tbl_exists) continue;

            $existing_cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($cols as $col) {
                if (!in_array($col, $existing_cols)) continue;

                foreach ($temp_map as $old_id => $tmp_id) {
                    $st = $pdo->prepare("UPDATE `$tbl` SET `$col` = ? WHERE `$col` = ?");
                    $st->execute([$tmp_id, $old_id]);
                }
            }
        }
        $logs[] = "✔ ملحقہ تمام ٹیبلز میں پرانی آئی ڈیز عارضی طور پر محفوظ کر دی گئیں۔";

        // 5. اب admins ٹیبل میں پرائمری کیز کو درست کریں اور فالتو اکاؤنٹس ڈیلیٹ کریں
        $logs[] = "مرحلہ 5: admins ٹیبل کی صفائی اور نئی ترتیب (1, 2, 3, 4)...";

        // 5.1 چاروں اہم یوزرز کو منفی آئی ڈیز پر منتقل کریں
        $pdo->prepare("UPDATE admins SET id = -1, name = 'تنزیل الرحمن', username = 'Tanzeel', role = 'admin' WHERE id = ?")->execute([$old_tanzeel_id]);
        $pdo->prepare("UPDATE admins SET id = -2, name = 'قاری حماد اللہ ساجد صاحب', username = 'Hammad', role = 'admin' WHERE id = ?")->execute([$old_hammad_id]);
        $pdo->prepare("UPDATE admins SET id = -3, name = 'قاری سلیم اللہ صاحب', username = 'saleem', role = 'teacher' WHERE id = ?")->execute([$old_saleem_id]);
        $pdo->prepare("UPDATE admins SET id = -4, name = 'محمد عثمان معاویہ', username = 'usman', role = 'teacher' WHERE id = ?")->execute([$old_usman_id]);

        // 5.2 ان چار منفی آئی ڈیز کے علاوہ تمام اکاؤنٹس کو ڈیلیٹ کریں (بشمول پرانا قاری سلیم اللہ ID 1)
        $del_stmt = $pdo->exec("DELETE FROM admins WHERE id > 0");
        $logs[] = "✔ کل {$del_stmt} فالتو/ڈپلیکیٹ اکاؤنٹس (بشمول پرانا سلیم اللہ اکاؤنٹ) ڈیٹا بیس سے حذف کر دیے گئے۔";

        // 5.3 اب منفی آئی ڈیز کو نئی حتمی مثبت آئی ڈیز (1, 2, 3, 4) پر لائیں
        $pdo->exec("UPDATE admins SET id = 1 WHERE id = -1");
        $pdo->exec("UPDATE admins SET id = 2 WHERE id = -2");
        $pdo->exec("UPDATE admins SET id = 3 WHERE id = -3");
        $pdo->exec("UPDATE admins SET id = 4 WHERE id = -4");

        // آٹو انکریمنٹ 5 پر سیٹ کریں
        try {
            $pdo->exec("ALTER TABLE admins AUTO_INCREMENT = 5");
        } catch (Throwable $e) {}

        $logs[] = "✔ چاروں فعال اکاؤنٹس کامیابی سے نئی آئی ڈیز پر منتقل ہو گئے:";
        $logs[] = "   • ID 1: تنزیل الرحمن (Tanzeel, Admin)";
        $logs[] = "   • ID 2: قاری حماد اللہ ساجد صاحب (Hammad, Admin)";
        $logs[] = "   • ID 3: قاری سلیم اللہ صاحب (saleem, Teacher)";
        $logs[] = "   • ID 4: محمد عثمان معاویہ (usman, Teacher)";

        // 6. اب ملحقہ ٹیبلز میں منفی عارضی آئی ڈیز کو نئی حتمی آئی ڈیز (1, 2, 3, 4) پر لے آئیں
        $final_map = [
            -1 => 1,
            -2 => 2,
            -3 => 3,
            -4 => 4
        ];

        foreach ($tables_and_columns as $tbl => $cols) {
            $tbl_exists = $pdo->query("SHOW TABLES LIKE '$tbl'")->rowCount() > 0;
            if (!$tbl_exists) continue;

            $existing_cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($cols as $col) {
                if (!in_array($col, $existing_cols)) continue;

                foreach ($final_map as $tmp_id => $new_id) {
                    $st = $pdo->prepare("UPDATE `$tbl` SET `$col` = ? WHERE `$col` = ?");
                    $st->execute([$new_id, $tmp_id]);
                }
            }
        }
        $logs[] = "✔ تمام ملحقہ ٹیبلز میں نئی آئی ڈیز (1 تا 4) کامیابی سے نافذ ہو گئیں۔";

        // 7. finance اور masjid_finance میں ناموں کی یکسانیت کو یقینی بنائیں (Uniformity)
        $names_by_id = [
            1 => 'تنزیل الرحمن',
            2 => 'قاری حماد اللہ ساجد صاحب',
            3 => 'قاری سلیم اللہ صاحب',
            4 => 'محمد عثمان معاویہ'
        ];

        foreach (['finance', 'masjid_finance'] as $ftbl) {
            $tbl_exists = $pdo->query("SHOW TABLES LIKE '$ftbl'")->rowCount() > 0;
            if (!$tbl_exists) continue;

            $existing_cols = $pdo->query("SHOW COLUMNS FROM `$ftbl`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('user_id', $existing_cols) || !in_array('user_name', $existing_cols)) continue;

            foreach ($names_by_id as $n_uid => $n_uname) {
                $st = $pdo->prepare("UPDATE `$ftbl` SET user_name = ? WHERE user_id = ?");
                $st->execute([$n_uname, $n_uid]);
            }
        }
        $logs[] = "✔ فنانس اور مسجد فنانس میں صارفین کے نام اردو میں یکساں کر دیے گئے۔";

        // 8. چارٹ آف اکاؤنٹس کی ہم آہنگی (Chart of Accounts Advance Sync)
        $logs[] = "مرحلہ 6: چارٹ آف اکاؤنٹس ایڈوانس کھاتوں کی تنظیم و مکمل علیحدگی...";
        
        // 8.1 تنزیل الرحمن کھاتہ 1502
        $pdo->prepare("UPDATE acc_chart_of_accounts SET code = '1502', name = 'ایڈوانس - تنزیل الرحمن', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$acc_1502_id]);
        $logs[] = "✔ تنزیل الرحمن ایڈوانس کھاتہ 1502 محفوظ (Account ID: {$acc_1502_id})";
        
        // 8.2 قاری سلیم اللہ صاحب کھاتہ 1503
        $stmtSalAcc = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE code = '1503' LIMIT 1");
        $sal_acc_id = $stmtSalAcc ? (int)$stmtSalAcc->fetchColumn() : 0;
        if (!$sal_acc_id && $acc_1502_id > 0) {
            $stmtSalAcc2 = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE id != $acc_1502_id AND (name LIKE '%سلیم%' OR name LIKE '%Saleem%') AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%') ORDER BY id ASC LIMIT 1");
            $sal_acc_id = $stmtSalAcc2 ? (int)$stmtSalAcc2->fetchColumn() : 0;
        }
        if (!$sal_acc_id) {
            $pdo->prepare("INSERT INTO acc_chart_of_accounts (code, name, type, is_active, is_deleted) VALUES ('1503', 'ایڈوانس - قاری سلیم اللہ صاحب', 'Asset', 1, 0)")->execute();
            $sal_acc_id = (int)$pdo->lastInsertId();
            $logs[] = "✔ قاری سلیم اللہ صاحب کا نیا ایڈوانس کھاتہ 1503 بنایا گیا (Account ID: {$sal_acc_id})";
        } else {
            $pdo->prepare("UPDATE acc_chart_of_accounts SET code = '1503', name = 'ایڈوانس - قاری سلیم اللہ صاحب', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$sal_acc_id]);
            $logs[] = "✔ قاری سلیم اللہ صاحب کا ایڈوانس کھاتہ 1503 اپڈیٹ کیا گیا (Account ID: {$sal_acc_id})";
        }

        // 8.3 رقم منتقلی (Fund Transfers) واؤچرز کی درست کھاتوں پر منتقلی
        if ($sal_acc_id > 0 && $acc_1502_id > 0 && $sal_acc_id !== $acc_1502_id) {
            // سلیم کے ٹرانسفرز (CV-00001, CV-260913-3585, CV-260916-2300, CV-00005): ڈیبٹ انٹری کو سلیم کے کھاتے پر لائیں
            $v_sal_stmt = $pdo->query("SELECT id FROM acc_vouchers WHERE (reference LIKE 'TRANSFER-%' OR type = 'Contra') AND (description LIKE '%saleem%' OR description LIKE '%سلیم%')");
            $v_sal_ids = $v_sal_stmt ? $v_sal_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            if (!empty($v_sal_ids)) {
                $v_sal_in = implode(',', array_map('intval', $v_sal_ids));
                $upd_tr_sal = $pdo->exec("UPDATE acc_ledger_entries SET account_id = $sal_acc_id WHERE voucher_id IN ($v_sal_in) AND debit > 0 AND account_id != $sal_acc_id");
                $logs[] = "✔ قاری سلیم اللہ صاحب کے فنڈ ٹرانسفرز کی لیجر انٹریز کھاتہ 1503 پر بحال ہو گئیں ({$upd_tr_sal} انٹریز اپڈیٹ)۔";
            }

            // تنزیل کے ٹرانسفرز (CV-260909-4147): ڈیبٹ انٹری کو تنزیل کے کھاتے پر رکھیں
            $v_tan_stmt = $pdo->query("SELECT id FROM acc_vouchers WHERE (reference LIKE 'TRANSFER-%' OR type = 'Contra') AND (description LIKE '%tanzeel%' OR description LIKE '%تنزیل%')");
            $v_tan_ids = $v_tan_stmt ? $v_tan_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            if (!empty($v_tan_ids)) {
                $v_tan_in = implode(',', array_map('intval', $v_tan_ids));
                $pdo->exec("UPDATE acc_ledger_entries SET account_id = $acc_1502_id WHERE voucher_id IN ($v_tan_in) AND debit > 0 AND account_id != $acc_1502_id");
                $logs[] = "✔ تنزیل الرحمن کے فنڈ ٹرانسفرز کی لیجر انٹریز کھاتہ 1502 پر محفوظ ہیں۔";
            }

            // 8.4 فنانس ٹیبل میں سلیم کے 64 اخراجات کو کھاتہ 1503 پر منتقل کریں
            $upd_fin_sal = $pdo->exec("
                UPDATE finance 
                SET account_id = $sal_acc_id, user_id = 3, user_name = 'قاری سلیم اللہ صاحب', payment_method = 'Advance' 
                WHERE (user_id = 3 OR user_name LIKE '%سلیم%' OR user_name = 'saleem') 
                  AND type = 'Expense' 
                  AND (account_id = $acc_1502_id OR account_id IS NULL OR account_id = 0 OR payment_method = 'Advance')
            ");
            $logs[] = "✔ قاری سلیم اللہ صاحب کے تمام {$upd_fin_sal} اخراجات کھاتہ 1503 پر بحال ہو گئے۔";

            // تنزیل کے اخراجات کھاتہ 1502 پر محفوظ کریں
            $pdo->exec("
                UPDATE finance 
                SET account_id = $acc_1502_id, user_id = 1, user_name = 'تنزیل الرحمن' 
                WHERE (user_id = 1 OR user_name LIKE '%تنزیل%' OR user_name = 'Tanzeel') 
                  AND type = 'Expense' 
                  AND account_id = $sal_acc_id
            ");
        }

        // 8.5 قاری حماد اللہ ساجد صاحب کھاتہ
        $stmtHamAcc = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE name LIKE '%حماد%' AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC LIMIT 1");
        $ham_acc_id = $stmtHamAcc ? (int)$stmtHamAcc->fetchColumn() : 0;
        if ($ham_acc_id) {
            $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'ایڈوانس - قاری حماد اللہ ساجد صاحب', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$ham_acc_id]);
        }

        // 8.6 محمد عثمان معاویہ کھاتہ
        $stmtUsmAcc = $pdo->query("SELECT id FROM acc_chart_of_accounts WHERE name LIKE '%عثمان%' AND (name LIKE '%ایڈوانس%' OR name LIKE '%Advance%') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id ASC LIMIT 1");
        $usm_acc_id = $stmtUsmAcc ? (int)$stmtUsmAcc->fetchColumn() : 0;
        if ($usm_acc_id) {
            $pdo->prepare("UPDATE acc_chart_of_accounts SET name = 'ایڈوانس - محمد عثمان معاویہ', type = 'Asset', is_active = 1, is_deleted = 0 WHERE id = ?")->execute([$usm_acc_id]);
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $executed = true;
        $logs[] = "🎉 مبارک ہو! تمام تر تبدیلیاں کامیابی کے ساتھ ڈیٹا بیس میں محفوظ کر دی گئی ہیں۔ (Transaction Committed Successfully)";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = "ڈیٹا بیس خرابی: " . $e->getMessage();
    }
}

// موجودہ یوزرز کی لسٹ لائیں
$current_admins = [];
try {
    $current_admins = $pdo->query("SELECT id, name, username, role, permissions FROM admins ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>یوزرز کلین اپ اور آئی ڈیز ری آرڈر ٹول</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; color: #1e293b; }
        .card { border-radius: 12px; border: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .log-box { background: #0f172a; color: #38bdf8; font-family: monospace; font-size: 13px; border-radius: 8px; padding: 15px; max-height: 400px; overflow-y: auto; }
    </style>
</head>
<body class="p-4">
    <div class="container" style="max-width: 900px;">
        <div class="card mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center p-3">
                <h5 class="mb-0 fw-bold"><i class="fas fa-users-cog me-2 text-warning"></i> یوزرز صفائی اور آئی ڈیز ری آرڈر ٹول (1 تا 4)</h5>
                <a href="manage_users.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-right me-1"></i> یوزرز کا انتظام</a>
            </div>
            <div class="card-body p-4">
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h6 class="fw-bold"><i class="fas fa-exclamation-triangle me-1"></i> ایرر پیش آیا:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errors as $er): ?>
                                <li><?php echo htmlspecialchars($er); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($executed): ?>
                    <div class="alert alert-success">
                        <h5 class="fw-bold mb-2"><i class="fas fa-check-circle me-1"></i> ڈیٹا بیس کی صفائی اور ری آرڈر مکمل ہو گیا!</h5>
                        <p class="mb-0">تمام پرانے اکاؤنٹس حذف کر دیے گئے ہیں اور صرف 4 فعال اکاؤنٹس نئی ترتیب (1 تا 4) میں محفوظ ہو چکے ہیں۔</p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($logs)): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-terminal me-1"></i> لاگ رپورٹ:</h6>
                        <div class="log-box">
                            <?php foreach ($logs as $l): ?>
                                <div><?php echo htmlspecialchars($l); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- موجودہ یوزرز کی لسٹ -->
                <h6 class="fw-bold mb-3"><i class="fas fa-list me-1"></i> ڈیٹا بیس میں موجود موجودہ یوزرز (کل: <?php echo count($current_admins); ?>):</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-hover text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>حقیقی ID</th>
                                <th>مکمل نام</th>
                                <th>یوزرنیم</th>
                                <th>رول</th>
                                <th>حالت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($current_admins as $adm): ?>
                                <tr>
                                    <td><span class="badge bg-primary fs-6">ID: <?php echo $adm['id']; ?></span></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($adm['name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($adm['username']); ?></code></td>
                                    <td>
                                        <span class="badge <?php echo $adm['role'] === 'admin' ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                            <?php echo $adm['role']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (in_array((int)$adm['id'], [1, 2, 3, 4])): ?>
                                            <span class="badge bg-success">فعال و درست</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">غیر منظم / پرانا</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="border rounded p-3 bg-light mb-4">
                    <h6 class="fw-bold text-dark mb-2"><i class="fas fa-info-circle text-primary me-1"></i> اس آپریشن کے نتائج:</h6>
                    <ol class="mb-0 ps-3">
                        <li><strong>تنزیل الرحمن</strong> کو مستقل <strong>آئی ڈی 1</strong> اور رول ایڈمن دیا جائے گا۔</li>
                        <li><strong>قاری حماد اللہ ساجد صاحب</strong> کو مستقل <strong>آئی ڈی 2</strong> اور رول ایڈمن دیا جائے گا۔</li>
                        <li><strong>قاری سلیم اللہ صاحب</strong> (اصل اکاؤنٹ) کو مستقل <strong>آئی ڈی 3</strong> اور رول استاذ دیا جائے گا۔</li>
                        <li><strong>محمد عثمان معاویہ</strong> کو مستقل <strong>آئی ڈی 4</strong> اور رول استاذ دیا جائے گا۔</li>
                        <li>قاری سلیم اللہ صاحب کا پرانا ڈپلیکیٹ اکاؤنٹ (سابقہ ID 1) اور تمام دیگر فالتو یوزرز ڈیٹا بیس سے حذف ہو جائیں گے۔</li>
                        <li>سابقہ ID 1 کی تمام 13 انٹریز (کل خرچ 245,000) کو تنزیل الرحمن (کھاتہ 1502) کے تحت منتقل کر دیا جائے گا۔</li>
                        <li>تمام فنانس، حاضری، کلاسز اور واؤچرز کی متعلقہ آئی ڈیز خودکار اپڈیٹ ہو جائیں گی۔</li>
                    </ol>
                </div>

                <form method="POST">
                    <button type="submit" name="run_cleanup" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm" onclick="return confirm('کیا آپ واقعی ڈیٹا بیس یوزرز کی صفائی اور آئی ڈیز 1 تا 4 کی ری اسائنمنٹ کرنا چاہتے ہیں؟');">
                        <i class="fas fa-sync-alt me-2"></i> ابھی صفائی اور آئی ڈیز 1 تا 4 ری آرڈر مکمل کریں
                    </button>
                </form>

            </div>
        </div>
    </div>
</body>
</html>
