<?php
require 'db.php';
try {
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN name VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN asset_type VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_name VARCHAR(150) NULL");
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_father VARCHAR(150) NULL");
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_address VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN donor_phone VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE masjid_assets ADD COLUMN condition VARCHAR(50) NULL");
    echo "Columns added successfully";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
