<?php
/**
 * StudentController - Handles Student Domain API Requests
 * Madrasa Ashraf-ul-Madaris
 */

class StudentController {

    public static function getStats(PDO $pdo): array {
        $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE LOWER(status) = 'active' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        $total_teachers = $pdo->query("SELECT COUNT(*) FROM teachers WHERE LOWER(status) = 'active' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        $total_hifz = $pdo->query("SELECT COUNT(*) FROM students WHERE LOWER(status) = 'active' AND (class LIKE '%حفظ%' OR class LIKE '%Hifz%') AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        return [
            'status' => 'success',
            'data' => [
                'total_students' => (int)$total_students,
                'total_teachers' => (int)$total_teachers,
                'total_hifz' => (int)$total_hifz,
                'total_depts' => 8
            ]
        ];
    }

    public static function getAdmissionApplications(PDO $pdo, string $filterStatus): array {
        $allowedFilters = ['all', 'pending', 'approved', 'rejected'];
        if (!in_array(strtolower($filterStatus), $allowedFilters)) $filterStatus = 'all';

        if ($filterStatus === 'all') {
            $apps = $pdo->query("SELECT * FROM admission_applications ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM admission_applications WHERE LOWER(status) = ? ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([strtolower($filterStatus)]);
            $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $countStmt = $pdo->query("SELECT status, COUNT(*) as c FROM admission_applications GROUP BY status");
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $counts[strtolower($c['status'])] = (int)$c['c'];
        }
        return ['status' => 'success', 'applications' => $apps, 'counts' => $counts];
    }

    public static function updateAdmissionStatus(PDO $pdo, array $input): array {
        $app_id = intval($input['id'] ?? 0);
        $new_status = trim((string)($input['status'] ?? ''));
        $test_date = trim((string)($input['test_date'] ?? ''));

        if (!$app_id || !$new_status) {
            return ['status' => 'error', 'message' => 'Missing ID or status'];
        }
        $stmt = $pdo->prepare("UPDATE admission_applications SET status = ?, test_date = ? WHERE id = ?");
        $stmt->execute([$new_status, !empty($test_date) ? $test_date : null, $app_id]);
        return ['status' => 'success', 'message' => 'Status updated successfully'];
    }

    public static function deleteAccount(PDO $pdo, array $user, array $input): array {
        $user_id = intval($input['user_id'] ?? $user['id']);
        $role = trim((string)($input['role'] ?? $user['role']));
        if ($user['role'] !== 'admin') {
            $user_id = $user['id'];
            $role = $user['role'];
        }
        if (!$user_id) {
            return ['status' => 'error', 'message' => 'User ID required'];
        } elseif ($role === 'student' || $role === 'parent') {
            $pdo->prepare("UPDATE students SET is_deleted = 1, status = 'Deleted' WHERE id = ?")->execute([$user_id]);
            return ['status' => 'success', 'message' => 'Account deleted successfully'];
        } else {
            return ['status' => 'error', 'message' => 'Admin account cannot be deleted via API'];
        }
    }
}
