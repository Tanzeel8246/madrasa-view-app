<?php
/**
 * Sync Up Module (Modular Coordinator)
 * Madrasa Ashraf-ul-Madaris
 */

require_once __DIR__ . '/sync/SyncContext.php';
require_once __DIR__ . '/sync/up/SyncUpCore.php';
require_once __DIR__ . '/sync/up/SyncUpStudents.php';
require_once __DIR__ . '/sync/up/SyncUpMaktab.php';
require_once __DIR__ . '/sync/up/SyncUpFinance.php';
require_once __DIR__ . '/sync/up/SyncUpAttendance.php';

function handle_sync_up($pdo, $user, $input = null) {
    if ($input === null) {
        $json_data = file_get_contents("php://input");
        write_log("Sync Up Data Received: " . strlen($json_data) . " bytes");
        $input = json_decode($json_data, true) ?: [];
    }

    if ($user['role'] === 'student' || $user['role'] === 'parent') {
        $allowedStudentSync = ['parent_complaints', 'leave_applications', 'fee_submissions'];
        $parentStudentIds = [];
        if ($user['role'] === 'parent') {
            $stStmt = $pdo->prepare("SELECT student_id FROM student_parents WHERE parent_id = ?");
            $stStmt->execute([$user['id']]);
            $parentStudentIds = array_map('intval', $stStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $input = array_intersect_key($input, array_flip($allowedStudentSync));
        foreach ($allowedStudentSync as $key) {
            if (!empty($input[$key]) && is_array($input[$key])) {
                foreach ($input[$key] as &$item) {
                    if (is_array($item) && $user['role'] === 'student') {
                        $item['student_id'] = $user['id'];
                    } elseif (is_array($item) && $user['role'] === 'parent') {
                        $requestedStudentId = (int)($item['student_id'] ?? 0);
                        if (!in_array($requestedStudentId, $parentStudentIds, true)) {
                            $item = null;
                        }
                    }
                }
                unset($item);
                $input[$key] = array_values(array_filter($input[$key], 'is_array'));
            }
        }
    } elseif (!in_array($user['role'], ['admin', 'nazim', 'teacher'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    } else {
        $input = filter_sync_payload_for_user($input, $user);
    }
    if (empty($input)) {
        write_log("Sync Up: Decoded input is empty or invalid JSON.");
    } else {
        write_log("Sync Up: Keys present: " . implode(', ', array_keys($input)));
    }

    $syncedIds = [
        'finance_entries' => [], 'masjid_finance_entries' => [], 'heads' => [],
        'hifz_entries' => [], 'nazira_record' => [], 'students' => [], 'teachers' => [],
        'attendance_entries' => [], 'attendance_teachers' => [], 'teacher_period_attendance' => [],
        'complaints' => [], 'parent_complaints' => [], 'notices' => [], 'exams' => [],
        'leave_applications' => [], 'fee_submissions' => [], 'academic_reports' => [],
        'qaida_records' => [], 'exam_results' => [], 'classes' => [], 'departments' => [],
        'acc_chart_of_accounts' => [], 'acc_vouchers' => [], 'acc_ledger_entries' => [],
        'acc_payroll' => [], 'acc_invoices' => [], 'staff_loans' => [], 'loan_transactions' => [],
        'in_kind_donations' => [], 'masjid_assets' => [], 'maktab_students' => [],
        'maktab_teachers' => [], 'maktab_classes' => [], 'maktab_exams' => [],
        'maktab_attendance' => [], 'maktab_nazira_record' => [], 'maktab_hifz_record' => [],
        'maktab_qaida_records' => [], 'maktab_academic_reports' => [], 'maktab_exam_results' => [],
        'husn_saut_record' => [], 'admission_inquiries' => [], 'lib_books' => [], 'lib_issues' => [],
        'inventory_items' => [], 'inventory_sales' => [], 'hostel_rooms' => [], 'hostel_allocations' => [],
        'job_postings' => [], 'job_applicants' => [], 'web_pages' => [], 'announcements' => [],
        'teacher_leaves' => []
    ];
    $serverIds = [
        'finance_entries' => [],
        'masjid_finance_entries' => [],
        'teachers' => [],
        'notices' => [],
        'students' => [],
        'maktab_students' => [],
        'teacher_leaves' => []
    ];
    $syncErrors = [];

    $ctx = new SyncContext($pdo, $user);

    // Multi-tenant security: stamp authenticated tenant_id on ALL incoming rows
    $currentTenantId = (int)$ctx->tenantId;
    foreach ($input as $tblKey => &$rows) {
        if (is_array($rows)) {
            foreach ($rows as &$r) {
                if (is_array($r)) {
                    $r['tenant_id'] = $currentTenantId;
                }
            }
            unset($r);
        }
    }
    unset($rows);

    // 1. Students & Academics Module
    SyncUpStudents::process($ctx, $input, $syncedIds, $serverIds, $syncErrors);

    // 2. Maktab & Masjid Education Module
    SyncUpMaktab::process($ctx, $input, $syncedIds, $serverIds, $syncErrors);

    // 3. Attendance & Staff Module
    SyncUpAttendance::process($ctx, $input, $syncedIds, $serverIds, $syncErrors);

    // 4. Finance & Accounting Module
    SyncUpFinance::process($ctx, $input, $syncedIds, $serverIds, $syncErrors);

    // 5. Core & Metadata Module
    SyncUpCore::process($ctx, $input, $syncedIds, $serverIds, $syncErrors);

    return [
        'status' => empty($syncErrors) ? 'success' : 'partial_success',
        'synced_ids' => $syncedIds,
        'server_ids' => $serverIds,
        'errors' => $syncErrors
    ];
}

