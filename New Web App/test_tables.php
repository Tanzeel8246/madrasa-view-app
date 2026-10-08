<?php
require 'Madrasa web app/api.php';
\ = \->query('SHOW TABLES');
while (\ = \->fetch(PDO::FETCH_NUM)) {
    echo \[0] . "\n";
}
