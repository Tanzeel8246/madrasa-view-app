<?php
/**
 * Sync Down Module (Modular Coordinator)
 * Madrasa Ashraf-ul-Madaris
 */

require_once __DIR__ . '/sync/SyncContext.php';
require_once __DIR__ . '/sync/down/SyncDownCore.php';
require_once __DIR__ . '/sync/down/SyncDownStudents.php';
require_once __DIR__ . '/sync/down/SyncDownMaktab.php';
require_once __DIR__ . '/sync/down/SyncDownFinance.php';
require_once __DIR__ . '/sync/down/SyncDownAttendance.php';

function handle_sync_down($pdo, $user) {
    $fullSync = !empty($_GET['full_sync']) || !empty($_POST['full_sync']);
    $since = $fullSync ? '' : trim($_GET['since'] ?? $_POST['since'] ?? '');
    $ctx = new SyncContext($pdo, $user, $since);

    if ($ctx->since_dt) {
        write_log("Sync Down Started (Delta since: {$ctx->since_dt} / Date: {$ctx->since_date})");
    } else {
        write_log("Sync Down Started (Full)");
    }

    $data = [];
    $sharedContext = [];

    // 1. Core & Metadata (Settings, Classes, Notices, Assets)
    SyncDownCore::process($ctx, $data);

    // 2. Students & Academics (Students, Hifz, Nazira, Qaida, Reports, Complaints, Exams)
    SyncDownStudents::process($ctx, $data, $sharedContext);

    // 3. Maktab & Masjid Domain (Maktab Students, Teachers, Classes, Attendance, Exams)
    SyncDownMaktab::process($ctx, $data);

    // 4. Finance & Accounting (Finance, Heads, Fee Submissions, Vouchers, Ledger, Payroll, Loans, Donations)
    SyncDownFinance::process($ctx, $data);

    // 5. Staff & Attendance (Attendance, Teacher Attendance, Period Attendance, Teachers with mapped periods)
    SyncDownAttendance::process($ctx, $data, $sharedContext);

    // Freshly query user's actual permissions from database so client auto-sync immediately corrects any stale/cached permissions
    try {
        $uRole = strtolower(trim((string)($user['role'] ?? '')));
        $uId = (int)($user['id'] ?? 0);
        $tId = (int)$ctx->tenantId;
        if ($uId > 0 && !in_array($uRole, ['admin', 'superadmin', 'super_admin', 'administrator'], true)) {
            $uStmt = $pdo->prepare("SELECT permissions FROM admins WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL OR tenant_id = 0) LIMIT 1");
            $uStmt->execute([$uId, $tId]);
            $userRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            require_once __DIR__ . '/controllers/AuthController.php';
            $rawPerms = [];
            if ($userRow && !empty($userRow['permissions'])) {
                $decoded = json_decode($userRow['permissions'], true);
                if (is_array($decoded)) {
                    $rawPerms = $decoded;
                }
            }
            $data['current_user_permissions'] = AuthController::resolveUserPermissions($uRole, $rawPerms);
        }
    } catch (Throwable $ePermSync) {}

    write_log("Sync Down Finished");
    return ['status' => 'success', 'server_time' => date('c'), 'data' => $data];
}

