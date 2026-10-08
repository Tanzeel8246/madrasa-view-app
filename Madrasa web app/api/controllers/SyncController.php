<?php
/**
 * SyncController - Coordinator for Mobile Offline Sync Endpoints
 * Madrasa Ashraf-ul-Madaris
 */

class SyncController {
    
    public static function syncDown(PDO $pdo, array $user): array {
        require_once dirname(__DIR__) . '/sync_down.php';
        return handle_sync_down($pdo, $user);
    }

    public static function syncUp(PDO $pdo, array $user): array {
        require_once dirname(__DIR__) . '/sync_up.php';
        return handle_sync_up($pdo, $user);
    }
}
