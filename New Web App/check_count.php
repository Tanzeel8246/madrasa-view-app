require 'db.php';
echo \->query('SELECT COUNT(*) FROM daily_hifz')->fetchColumn();
