<?php
/**
 * FinanceController - Handles Finance and Accounting API Requests
 * Madrasa Ashraf-ul-Madaris
 */

class FinanceController {

    public static function getReceiptQuota(PDO $pdo, array $user, array $input): array {
        $userId = (int)($user['id'] ?? 1);
        $systemType = $input['system_type'] ?? 'Madrasa';
        $minNo = $userId * 100000;
        $maxNo = ($userId + 1) * 100000;
        $sysCond = ($systemType === 'Masjid')
            ? "system_type = 'Masjid'"
            : "(system_type = 'Madrasa' OR system_type IS NULL OR system_type = '')";

        $stmt = $pdo->prepare("SELECT MAX(computer_receipt_no) FROM finance WHERE computer_receipt_no > ? AND computer_receipt_no < ? AND $sysCond AND (is_deleted = 0 OR is_deleted IS NULL)");
        $stmt->execute([$minNo, $maxNo]);
        $maxReceipt = (int)$stmt->fetchColumn();

        $nextReceipt = ($maxReceipt > $minNo) ? ($maxReceipt + 1) : ($minNo + 1);
        return [
            'status' => 'success',
            'user_id' => $userId,
            'next_computer_receipt_no' => $nextReceipt,
            'quota_min' => $minNo + 1,
            'quota_max' => $maxNo - 1
        ];
    }

    public static function requestEditOtp(PDO $pdo, array $user, array $input): array {
        require_once dirname(__DIR__, 2) . '/finance_accounting_bridge.php';
        $financeId = (int)($input['finance_id'] ?? 0);
        if ($financeId <= 0) {
            return ['status' => 'error', 'message' => 'درست ٹرانزیکشن ID درکار ہے۔'];
        }
        $userId = (int)($user['id'] ?? 1);
        $userName = !empty($user['name']) ? $user['name'] : (!empty($user['username']) ? $user['username'] : 'User');
        $systemType = $input['system_type'] ?? 'Madrasa';
        $tenantId = (int)($user['tenant_id'] ?? 1);

        $res = finance_request_edit_otp($pdo, $financeId, $userId, $userName, $systemType, $tenantId);
        return $res;
    }

    public static function verifyEditOtp(PDO $pdo, array $user, array $input): array {
        require_once dirname(__DIR__, 2) . '/finance_accounting_bridge.php';
        $financeId = (int)($input['finance_id'] ?? 0);
        $otpCode = trim((string)($input['otp_code'] ?? $input['otp'] ?? ''));
        if ($financeId <= 0 || $otpCode === '') {
            return ['status' => 'error', 'message' => 'ٹرانزیکشن ID اور 6 ہندسوں کا OTP کوڈ درج کرنا لازمی ہے۔'];
        }
        $userId = (int)($user['id'] ?? 1);
        $tenantId = (int)($user['tenant_id'] ?? 1);

        $res = finance_verify_edit_otp($pdo, $financeId, $otpCode, $userId, $tenantId);
        return $res;
    }

    public static function checkEditPermission(PDO $pdo, array $user, array $input): array {
        require_once dirname(__DIR__, 2) . '/finance_accounting_bridge.php';
        $financeId = (int)($input['finance_id'] ?? 0);
        $userId = (int)($user['id'] ?? 1);
        $userRole = strtolower(trim((string)($user['role'] ?? '')));
        $isAdmin = in_array($userRole, ['admin', 'superadmin', 'super_admin', 'administrator'], true);

        if ($isAdmin) {
            return ['status' => 'success', 'has_permission' => true, 'is_admin' => true];
        }

        $hasPerm = finance_has_valid_edit_permission($pdo, $financeId, $userId);
        return [
            'status' => 'success',
            'has_permission' => $hasPerm,
            'is_admin' => false
        ];
    }

    public static function getPendingOtps(PDO $pdo, array $user, array $input): array {
        require_once dirname(__DIR__, 2) . '/finance_accounting_bridge.php';
        $userRole = strtolower(trim((string)($user['role'] ?? '')));
        $isAdmin = in_array($userRole, ['admin', 'superadmin', 'super_admin', 'administrator'], true);
        if (!$isAdmin) {
            return ['status' => 'error', 'message' => 'یہ معلومات صرف ایڈمن کے لیے مخصوص ہیں۔'];
        }

        $tenantId = (int)($user['tenant_id'] ?? 1);
        $otps = finance_get_pending_otps($pdo, $tenantId);
        return [
            'status' => 'success',
            'pending_otps' => $otps,
            'count' => count($otps)
        ];
    }

    public static function uploadBill(PDO $pdo, array $user, array $input): array {
        finance_ensure_otp_and_bill_schema($pdo);
        $financeId = (int)($input['finance_id'] ?? 0);
        $systemType = $input['system_type'] ?? 'Madrasa';
        $imageData = $input['bill_image_base64'] ?? ($input['image'] ?? '');

        $uploadDir = dirname(__DIR__, 2) . '/uploads/bills/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $filePath = '';
        if (!empty($_FILES['bill_file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['bill_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
                $ext = 'jpg';
            }
            $fileName = 'bill_' . ($financeId ?: time()) . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['bill_file']['tmp_name'], $uploadDir . $fileName)) {
                $filePath = 'uploads/bills/' . $fileName;
            }
        } elseif (!empty($imageData)) {
            if (str_starts_with($imageData, 'uploads/')) {
                $filePath = $imageData;
            } else {
                if (str_contains($imageData, ';base64,')) {
                    $imageData = explode(';base64,', $imageData)[1];
                }
                $decoded = base64_decode($imageData, true);
                if ($decoded !== false && strlen($decoded) > 50) {
                    $fileName = 'bill_' . ($financeId ?: time()) . '_' . uniqid() . '.jpg';
                    file_put_contents($uploadDir . $fileName, $decoded);
                    $filePath = 'uploads/bills/' . $fileName;
                }
            }
        }

        if (empty($filePath)) {
            return ['status' => 'error', 'message' => 'بل کی فائل یا تصویر موصول نہیں ہوئی۔'];
        }

        if ($financeId > 0) {
            $table = ($systemType === 'Masjid') ? 'masjid_finance' : 'finance';
            $upd = $pdo->prepare("UPDATE $table SET bill_image = ? WHERE id = ?");
            $upd->execute([$filePath, $financeId]);
            if ($table === 'masjid_finance') {
                try {
                    $pdo->prepare("UPDATE finance SET bill_image = ? WHERE id = ? AND system_type = 'Masjid'")->execute([$filePath, $financeId]);
                } catch (Throwable $e) {}
            }
        }

        return [
            'status' => 'success',
            'bill_image' => $filePath,
            'message' => 'بل کامیابی سے اپلوڈ ہو گیا۔'
        ];
    }
}
