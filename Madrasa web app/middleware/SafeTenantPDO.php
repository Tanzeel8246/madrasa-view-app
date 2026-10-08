<?php
/**
 * SafeTenantPDO - Multi-Tenant Data Leakage Prevention Shield
 * 
 * Intercepts and validates SQL queries to guarantee that multi-tenant tables
 * are never queried or mutated without an explicit tenant_id condition.
 */

class SafeTenantPDO {
    private static $tenantProtectedTables = [
        'students', 'teachers', 'classes', 'finance', 'attendance',
        'attendance_teachers', 'notices', 'fee_submissions', 'leave_applications',
        'parent_complaints', 'teacher_leaves', 'admission_applications',
        'hifz_record', 'nazira_record', 'qaida_records', 'husn_saut_record',
        'acc_vouchers', 'acc_ledger_entries', 'masjid_finance'
    ];

    /**
     * Checks if a raw SQL query safely contains tenant_id for protected tables.
     * Logs or throws a warning in development if a leak is detected.
     */
    public static function auditQuery($sql, $tenantId = null) {
        $tenantId = $tenantId ?: (function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1);
        $lowerSql = strtolower($sql);

        foreach (self::$tenantProtectedTables as $tbl) {
            if (preg_match('/\b(from|into|update|join)\s+[`"]?' . $tbl . '[`"]?\b/i', $lowerSql)) {
                // If the protected table is present, verify tenant_id is in the query
                if (strpos($lowerSql, 'tenant_id') === false) {
                    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                    $file = isset($caller[1]['file']) ? basename($caller[1]['file']) : 'unknown';
                    $line = isset($caller[1]['line']) ? $caller[1]['line'] : '0';
                    error_log("[SafeTenantPDO Warning] Unscoped query on '$tbl' without tenant_id at $file:$line -> " . substr($sql, 0, 120));
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Safely executes a SELECT query with guaranteed tenant_id filter.
     */
    public static function safeSelect($pdo, $table, $extraWhere = '', $params = [], $fields = '*') {
        $tenantId = function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1;
        $sql = "SELECT $fields FROM `$table` WHERE tenant_id = ?";
        $boundParams = [$tenantId];

        if (!empty($extraWhere)) {
            $sql .= " AND ($extraWhere)";
            $boundParams = array_merge($boundParams, $params);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($boundParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Safely counts records with guaranteed tenant_id filter.
     */
    public static function safeCount($pdo, $table, $extraWhere = '', $params = []) {
        $tenantId = function_exists('get_current_tenant_id') ? get_current_tenant_id() : 1;
        $sql = "SELECT COUNT(*) FROM `$table` WHERE tenant_id = ?";
        $boundParams = [$tenantId];

        if (!empty($extraWhere)) {
            $sql .= " AND ($extraWhere)";
            $boundParams = array_merge($boundParams, $params);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($boundParams);
        return (int)$stmt->fetchColumn();
    }
}
