<?php
session_start();
session_unset();
session_destroy();

// تبدیلی: اب یہ سیدھا ہوم پیج (لینڈنگ پیج) پر جائے گا
header("Location: index.php");
exit;
?>