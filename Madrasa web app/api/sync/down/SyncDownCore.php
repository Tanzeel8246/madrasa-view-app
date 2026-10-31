<?php
/**
 * Sync Down Core Module (Settings, Classes, Departments, Notices, Assets)
 * Madrasa Ashraf-ul-Madaris
 */

class SyncDownCore {
    public static function process(SyncContext $ctx, &$data) {
        $pdo = $ctx->pdo;
        $user = $ctx->user;
        $since_date = $ctx->since_date;
        $requested_sys = $ctx->requestedSys;
        $tId = (int)$ctx->tenantId;
        $tCond = ($tId === 1) ? "(tenant_id = 1 OR tenant_id IS NULL OR tenant_id = 0)" : "tenant_id = $tId";

        // Settings (Scoped strictly to tenant)
        try {
            $settings = get_tenant_settings($pdo, $tId);
            if ($settings) {
                $data['settings'] = [
                    'madrasa_name' => $settings['madrasa_name'] ?? '',
                    'phone' => $settings['phone'] ?? '',
                    'address' => $settings['address'] ?? '',
                    'tagline' => $settings['tagline'] ?? '',
                    'current_hijri_year' => $settings['current_hijri_year'] ?? '1447',
                    'week_start_day' => (int)($settings['week_start_day'] ?? 6),
                    'ai_provider' => $settings['ai_provider'] ?? 'gemini',
                    'auto_notify_day' => (int)($settings['auto_notify_day'] ?? 4),
                    'auto_notify_time' => $settings['auto_notify_time'] ?? '18:00',
                    'logo_path' => $settings['logo_path'] ?? 'logo.png',
                    'receipt_bg' => $settings['receipt_bg'] ?? 'uploads/receipt_bg.webp',
                    'masjid_receipt_bg' => $settings['masjid_receipt_bg'] ?? 'uploads/MAMFSS.webp',
                    'id_card_bg' => $settings['id_card_bg'] ?? null,
                    'sanad_bg' => $settings['sanad_bg'] ?? null,
                    'signature_stamp_path' => $settings['signature_stamp_path'] ?? null,
                    'sanad_layout_json' => $settings['sanad_layout_json'] ?? null,
                    'masjid_name' => $settings['masjid_name'] ?? 'مسجد اشرف المدارس',
                    'masjid_logo_path' => $settings['masjid_logo_path'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            $data['settings'] = ['madrasa_name' => 'جامعہ دارالعلوم', 'current_hijri_year' => '1447', 'week_start_day' => 6];
        }

        // Classes
        try { $pdo->exec("ALTER TABLE classes ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE classes ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

        $clsDeltaCond = $ctx->since_dt
            ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')"
            : " AND (is_deleted = 0 OR is_deleted IS NULL)";

        if ($user['role'] === 'admin' || $user['role'] === 'nazim' || has_permission($user['role'], $user['permissions'] ?? [], 'manage_classes', $requested_sys)) {
            $data['classes'] = $ctx->fetchAll("SELECT * FROM classes WHERE $tCond $clsDeltaCond ORDER BY id ASC");
        } else {
            $allowed_classes = $ctx->allowedClasses;
            $active_classes = array_values(array_filter($allowed_classes, function($c) { return ($c['status'] ?? 'Active') == 'Active'; }));
            if ($ctx->since_dt) {
                $data['classes'] = array_values(array_filter($active_classes, function($c) use ($ctx) {
                    $u = $c['updated_at'] ?? $c['created_at'] ?? '';
                    return $u && $u >= $ctx->since_dt;
                }));
            } else {
                $data['classes'] = $active_classes;
            }
        }

        // Notices
        $data['notices'] = $ctx->fetchAll("SELECT * FROM notices WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)" . ($ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : ($since_date ? " AND date >= '$since_date'" : "")) . " ORDER BY date DESC LIMIT 300");

        // Assets
        $userRole = $user['role'] ?? 'guest';
        $hasAssets = ($userRole === 'admin' || has_permission($userRole, $user['permissions'] ?? [], 'manage_assets', $requested_sys) || has_permission($userRole, $user['permissions'] ?? [], 'manage_masjid_assets', $requested_sys));
        if ($hasAssets) {
            try { $pdo->exec("ALTER TABLE assets ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE assets ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}

            $assetDeltaCond = $ctx->since_dt
                ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')"
                : " AND (is_deleted = 0 OR is_deleted IS NULL)";

            $fetch_assets = function() use ($pdo, $tCond, $assetDeltaCond) {
                try {
                    return $pdo->query("SELECT * FROM assets WHERE $tCond $assetDeltaCond ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    try {
                        return $pdo->query("SELECT * FROM masjid_assets WHERE $tCond $assetDeltaCond ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e2) {
                        return [];
                    }
                }
            };
            $data['masjid_assets'] = $fetch_assets();
        } else {
            $data['masjid_assets'] = [];
        }

        // Admission Inquiries (Phase 2)
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['admission_inquiries'] = $ctx->fetchAll("SELECT * FROM admission_inquiries WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 1000");
        } catch (Throwable $e) {
            try {
                $data['admission_inquiries'] = $ctx->fetchAll("SELECT * FROM admission_inquiries WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 1000");
            } catch (Throwable $e2) { $data['admission_inquiries'] = []; }
        }

        // Library Books (Phase 2)
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['lib_books'] = $ctx->fetchAll("SELECT * FROM lib_books WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 5000");
        } catch (Throwable $e) {
            try {
                $data['lib_books'] = $ctx->fetchAll("SELECT * FROM lib_books WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 5000");
            } catch (Throwable $e2) { $data['lib_books'] = []; }
        }

        // Library Issues (Phase 2)
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['lib_issues'] = $ctx->fetchAll("SELECT * FROM lib_issues WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 5000");
        } catch (Throwable $e) {
            try {
                $data['lib_issues'] = $ctx->fetchAll("SELECT * FROM lib_issues WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 5000");
            } catch (Throwable $e2) { $data['lib_issues'] = []; }
        }

        // Phase 3: School Shop & Inventory
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['inventory_items'] = $ctx->fetchAll("SELECT * FROM inventory_items WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 5000");
        } catch (Throwable $e) {
            try {
                $data['inventory_items'] = $ctx->fetchAll("SELECT * FROM inventory_items WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 5000");
            } catch (Throwable $e2) { $data['inventory_items'] = []; }
        }

        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['inventory_sales'] = $ctx->fetchAll("SELECT * FROM inventory_sales WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 5000");
        } catch (Throwable $e) {
            try {
                $data['inventory_sales'] = $ctx->fetchAll("SELECT * FROM inventory_sales WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 5000");
            } catch (Throwable $e2) { $data['inventory_sales'] = []; }
        }

        // Phase 3: Hostel Management
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['hostel_rooms'] = $ctx->fetchAll("SELECT * FROM hostel_rooms WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 1000");
        } catch (Throwable $e) {
            try {
                $data['hostel_rooms'] = $ctx->fetchAll("SELECT * FROM hostel_rooms WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 1000");
            } catch (Throwable $e2) { $data['hostel_rooms'] = []; }
        }

        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['hostel_allocations'] = $ctx->fetchAll("SELECT * FROM hostel_allocations WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 2000");
        } catch (Throwable $e) {
            try {
                $data['hostel_allocations'] = $ctx->fetchAll("SELECT * FROM hostel_allocations WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 2000");
            } catch (Throwable $e2) { $data['hostel_allocations'] = []; }
        }

        // Phase 4: Jobs & Recruitment
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['job_postings'] = $ctx->fetchAll("SELECT * FROM job_postings WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 1000");
        } catch (Throwable $e) {
            try {
                $data['job_postings'] = $ctx->fetchAll("SELECT * FROM job_postings WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 1000");
            } catch (Throwable $e2) { $data['job_postings'] = []; }
        }

        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['job_applicants'] = $ctx->fetchAll("SELECT * FROM job_applicants WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 2000");
        } catch (Throwable $e) {
            try {
                $data['job_applicants'] = $ctx->fetchAll("SELECT * FROM job_applicants WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 2000");
            } catch (Throwable $e2) { $data['job_applicants'] = []; }
        }

        // Phase 4: Website CMS & Announcements
        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['web_pages'] = $ctx->fetchAll("SELECT * FROM web_pages WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 500");
        } catch (Throwable $e) {
            try {
                $data['web_pages'] = $ctx->fetchAll("SELECT * FROM web_pages WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 500");
            } catch (Throwable $e2) { $data['web_pages'] = []; }
        }

        try {
            $delta = $ctx->since_dt ? " AND (updated_at >= '{$ctx->since_dt}' OR created_at >= '{$ctx->since_dt}')" : "";
            $data['announcements'] = $ctx->fetchAll("SELECT * FROM announcements WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL)$delta ORDER BY id DESC LIMIT 1000");
        } catch (Throwable $e) {
            try {
                $data['announcements'] = $ctx->fetchAll("SELECT * FROM announcements WHERE $tCond AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY id DESC LIMIT 1000");
            } catch (Throwable $e2) { $data['announcements'] = []; }
        }
    }
}
