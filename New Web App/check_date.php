require 'db.php';
\ = \->query("SHOW COLUMNS FROM daily_hifz LIKE 'date'");
print_r(\->fetch(PDO::FETCH_ASSOC));
