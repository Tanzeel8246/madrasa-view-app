<?php
/**
 * Sync Context & Shared Utilities
 * Madrasa Ashraf-ul-Madaris
 */

class SyncContext {
    public $pdo;
    public $user;
    public $since;
    public $since_date;
    public $since_dt;
    public $userRole;
    public $requestedSys;
    public $allowedClasses;
    public $allowedClassNames;
    public $tenantId;
    public $tenantCode;

    public function __construct($pdo, $user, $since = '') {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->userRole = $user['role'] ?? '';
        $this->requestedSys = $_GET['system_type'] ?? $_POST['system_type'] ?? null;
        $this->since = trim($since);

        $this->tenantId = !empty($user['tenant_id']) ? (int)$user['tenant_id'] : (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1);
        $this->tenantCode = !empty($user['tenant_code']) ? (string)$user['tenant_code'] : (function_exists('get_current_tenant_code') ? get_current_tenant_code() : 'MAIN01');

        if ($this->since && strtotime($this->since)) {
            $since_ts = strtotime($this->since);
            $this->since_dt = date('Y-m-d H:i:s', $since_ts);
            // 1 day buffer to ensure no records missed across midnight/timezone differences
            $this->since_date = date('Y-m-d', strtotime('-1 day', $since_ts));
        } else {
            $this->since_dt = '';
            $this->since_date = '';
        }

        $this->allowedClasses = get_allowed_classes(
            $this->pdo,
            'id ASC',
            $this->userRole,
            $this->user['id'] ?? null,
            $this->requestedSys ?: ($this->user['system_type'] ?? null)
        );
        $this->allowedClassNames = array_column($this->allowedClasses, 'name');
    }

    public function fetchAll($sql, $params = []) {
        try {
            if (empty($params)) {
                return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            if (function_exists('write_log')) {
                write_log("SyncContext fetchAll Error: " . $e->getMessage() . " | SQL: " . $sql);
            } else {
                error_log("SyncContext fetchAll Error: " . $e->getMessage() . " | SQL: " . $sql);
            }
            return [];
        }
    }

    public function uploadImage($base64Data, $prefix) {
        if (empty($base64Data) || strlen($base64Data) < 100) return $base64Data;
        if (strpos($base64Data, 'data:image') === false && strpos($base64Data, ';base64,') === false) return $base64Data;

        try {
            $parts = explode(";base64,", $base64Data);
            if (count($parts) != 2) return $base64Data;
            $ext = 'jpg';
            if (strpos($parts[0], 'png') !== false) $ext = 'png';
            $filename = $prefix . "_" . time() . "_" . rand(1000, 9999) . "." . $ext;

            $folder = "uploads/students";
            if ($prefix === 'fee' || $prefix === 'leave' || $prefix === 'teacher') {
                $folder = "uploads/" . ($prefix === 'fee' ? 'fees' : ($prefix === 'leave' ? 'leaves' : 'teachers'));
            }

            $path = __DIR__ . "/../../" . $folder . "/" . $filename;
            if (!is_dir(__DIR__ . "/../../" . $folder)) {
                mkdir(__DIR__ . "/../../" . $folder, 0777, true);
            }
            file_put_contents($path, base64_decode($parts[1]));
            return $folder . "/" . $filename;
        } catch (Exception $e) {
            return $base64Data;
        }
    }
}
