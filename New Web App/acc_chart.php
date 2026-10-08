<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !app_has_perm('manage_erp')) {
    header("Location: dashboard.php");
    exit;
}
$role = $_SESSION['role'] ?? '';
$user = ['id' => $_SESSION['user_id'], 'role' => $role];
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
