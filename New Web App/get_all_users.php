<?php
require 'db.php';
// Only allow display, DO NOT KEEP THIS FILE ON SERVER AFTER USE
$stmt = $pdo->query("SELECT id, name, username, password, role FROM admins");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Users List</title>
    <style>
        body { font-family: Tahoma, Arial; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h2>ڈیٹابیس میں موجود یوزر اکاؤنٹس (Total: <?php echo count($users); ?>)</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Username</th>
            <th>Password (Hash)</th>
            <th>Role</th>
        </tr>
        <?php foreach ($users as $user): ?>
        <tr>
            <td><?php echo htmlspecialchars($user['id']); ?></td>
            <td><?php echo htmlspecialchars($user['name']); ?></td>
            <td><?php echo htmlspecialchars($user['username']); ?></td>
            <td><?php echo htmlspecialchars($user['password']); ?></td>
            <td><?php echo htmlspecialchars($user['role'] ?? 'admin'); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <br>
    <p style="color: red; font-weight: bold;">نوٹ: سیکیورٹی کے پیش نظر، کام مکمل ہونے کے بعد اس فائل (get_all_users.php) کو سرور سے ڈیلیٹ کر دیں۔ کیونکہ پاسورڈز انکرپٹڈ (Encrypted) ہوتے ہیں، اس لیے وہ ہیش (Hash) کی صورت میں نظر آئیں گے۔</p>
</body>
</html>
