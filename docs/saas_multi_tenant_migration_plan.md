# 🏢 مدرسہ مینجمنٹ سسٹم: ملٹی ٹیننٹ (SaaS) مائیگریشن پلان و آرکیٹیکچر
> **ہدف:** موجودہ سنگل ٹیننٹ سسٹم کو کلاؤڈ SaaS پلیٹ فارم میں تبدیل کرنا تاکہ ایک ہی سرور پر سینکڑوں مدارس اور مساجد کے ڈیٹا کو مکمل رازداری و علیحدگی کے ساتھ چلایا جا سکے۔

---

## 📌 1. آرکیٹیکچرل ماڈل کا انتخاب (Architectural Approach)

کلاؤڈ SaaS کے لیے **Shared Database, Shared Schema with Discriminator Column (`madrasa_id`)** ماڈل منتخب کیا گیا ہے کیونکہ:
1. کم لاگت اور آسان کلاؤڈ ہوسٹنگ (ایک ہی MySQL انسٹینس پر 1,000+ مدارس کی سپورٹ)۔
2. تیز رفتار سنک اور بیک اپ مینجمنٹ۔
3. موجودہ ڈیٹا بیس اور API اسٹرکچر کے ساتھ 100% ہم آہنگ۔

---

## 🗄️ 2. ٹیننٹ ماسٹر ٹیبل (Tenants Master Schema)

```sql
-- 1. ٹیننٹس / مدارس کا مرکزی ٹیبل
CREATE TABLE IF NOT EXISTS `tenants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(64) NOT NULL UNIQUE COMMENT 'سب ڈومین یا منفرد کوڈ مثلا: jamia-ashraf',
    `name` VARCHAR(191) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(30) NOT NULL,
    `email` VARCHAR(100) NULL,
    `subdomain` VARCHAR(100) NULL,
    `plan` ENUM('free_trial', 'basic', 'standard', 'premium') DEFAULT 'free_trial',
    `status` ENUM('active', 'suspended', 'expired') DEFAULT 'active',
    `max_students` INT DEFAULT 200,
    `max_teachers` INT DEFAULT 20,
    `valid_until` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- موجودہ ڈیفالٹ مدرسہ کا اندراج
INSERT INTO `tenants` (`id`, `slug`, `name`, `phone`, `plan`, `status`)
VALUES (1, 'default-jamia', 'جامعہ اشرف المدارس', '03001234567', 'premium', 'active')
ON DUPLICATE KEY UPDATE `id` = `id`;
```

---

## ⚙️ 3. ڈیٹا بیس مائیگریشن اسکرپٹ (`madrasa_id` کا نفاذ)

مندرجہ ذیل SQL اسکرپٹ تمام موجودہ ٹیبلز میں بغیر کسی ڈیٹا لاس کے `madrasa_id` کا اضافہ کرے گا:

```sql
-- کالم کا اضافہ اور ڈیفالٹ 1 پر سیٹ کرنا
SET FOREIGN_KEY_CHECKS = 0;

-- 1. بنیادی تعلیمی و طلبہ ٹیبلز
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `teachers` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `classes` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `departments` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;

-- 2. حاضری و تعلیمی رپورٹس
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `attendance_teachers` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `teacher_leaves` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `hifz_record` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `nazira_record` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `qaida_records` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `exams` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `exam_results` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;

-- 3. فنانس و اکاؤنٹنگ
ALTER TABLE `finance` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `masjid_finance` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `heads` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `fee_submissions` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;

-- 4. نوٹسز، شکایات و ایڈمنز
ALTER TABLE `notices` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `complaints` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `parent_complaints` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `leave_applications` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `madrasa_id` INT NOT NULL DEFAULT 1 AFTER `id`;

-- تیز رفتار سرچ کے لیے کمپوزٹ انڈیکسز (Composite Indexes)
CREATE INDEX idx_students_tenant ON `students` (`madrasa_id`, `id`);
CREATE INDEX idx_attendance_tenant ON `attendance` (`madrasa_id`, `date`);
CREATE INDEX idx_finance_tenant ON `finance` (`madrasa_id`, `date`);
CREATE INDEX idx_admins_tenant ON `admins` (`madrasa_id`, `username`);

SET FOREIGN_KEY_CHECKS = 1;
```

---

## 🔒 4. پی ایچ پی بیک اینڈ ٹیننٹ اسکوپ مڈل ویئر (Tenant Middleware)

`Madrasa web app/tenant_context.php`:
```php
<?php
// ٹیننٹ کی شناخت بذریعہ Subdomain یا HTTP Header یا Session
function get_current_tenant_id(PDO $pdo) {
    if (isset($_SESSION['madrasa_id'])) {
        return (int)$_SESSION['madrasa_id'];
    }

    // 1. موبائل ہیڈر سے شناخت
    $headers = getallheaders();
    if (!empty($headers['X-Madrasa-Tenant-Id'])) {
        return (int)$headers['X-Madrasa-Tenant-Id'];
    }

    // 2. سب ڈومین سے شناخت: e.g. ashraf.madrasaapp.com
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $parts = explode('.', $host);
    if (count($parts) >= 3 && $parts[0] !== 'www') {
        $slug = $parts[0];
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$slug]);
        $tid = $stmt->fetchColumn();
        if ($tid) return (int)$tid;
    }

    // ڈیفالٹ سنگل ٹیننٹ فال بیک
    return 1;
}
?>
```

---

## 📱 5. موبائل ایپ میں ملٹی ٹیننٹ اسمارٹ لاگ ان

1. لاگ ان اسکرین پر "مدرسہ کوڈ (Madrasa Code)" کی فیلڈ یا کیو آر کوڈ اسکین کی سہولت۔
2. `FlutterSecureStorage` میں `tenant_id` محفوظ کرنا۔
3. `NetworkService` کی تمام ریکویسٹس میں `headers['X-Madrasa-Tenant-Id'] = tenantId` خودکار شامل کرنا۔

---

## 🚀 6. مرحلہ وار نفاذ کی حکمت عملی (Phased Rollout)

| مرحلہ | کام | دورانیہ | خطرہ (Risk) |
| :--- | :--- | :--- | :--- |
| **مرحلہ 1** | SQL اسکرپٹ رن کر کے تمام ٹیبلز میں `madrasa_id DEFAULT 1` شامل کرنا | 1 دن | صفر (کوئی موجودہ کیوری متاثر نہیں ہوگی) |
| **مرحلہ 2** | `tenants` ٹیبل کا اضافہ اور بیک اینڈ میں `tenant_context.php` مڈل ویئر | 2 دن | محفوظ |
| **مرحلہ 3** | ویب ایڈمن پینل پر سپر ایڈمن پورٹل برائے ٹیننٹ رجسٹریشن و سبسکرپشن | 3 دن | نیا فیچر |
| **مرحلہ 4** | موبائل ایپ میں ٹیننٹ سوئچر / کیو آر کنفیگریشن | 2 دن | محفوظ |
