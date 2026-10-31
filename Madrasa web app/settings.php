<?php include 'header.php'; ?>
<?php
$cur_tenant_id = (int)get_current_tenant_id();

// Ensure database columns exist (cached with flag)
if (!file_exists(__DIR__ . '/.settings_schema_done')) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('address', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN address TEXT DEFAULT NULL");
        if (!in_array('tagline', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN tagline VARCHAR(255) DEFAULT 'العلم نور - تعلیم و تربیت کا گہوارہ'");
        if (!in_array('logo_path', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN logo_path VARCHAR(255) DEFAULT 'logo.png'");
        if (!in_array('receipt_bg', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN receipt_bg VARCHAR(255) DEFAULT 'uploads/receipt_bg.webp'");
        if (!in_array('masjid_receipt_bg', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN masjid_receipt_bg VARCHAR(255) DEFAULT 'uploads/MAMFSS.webp'");
        if (!in_array('id_card_bg', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN id_card_bg VARCHAR(255) DEFAULT NULL");
        if (!in_array('sanad_bg', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN sanad_bg VARCHAR(255) DEFAULT NULL");
        if (!in_array('signature_stamp_path', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN signature_stamp_path VARCHAR(255) DEFAULT NULL");
        if (!in_array('masjid_name', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN masjid_name VARCHAR(255) DEFAULT 'مسجد اشرف المدارس'");
        if (!in_array('masjid_logo_path', $cols)) $pdo->exec("ALTER TABLE settings ADD COLUMN masjid_logo_path VARCHAR(255) DEFAULT NULL");
        @touch(__DIR__ . '/.settings_schema_done');
    } catch (Exception $e) {}
}

$uploadDir = __DIR__ . '/uploads/branding/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Function to handle safe file uploads (Strict image extensions only, NO SVG)
function handleSettingsFileUpload($fileKey, $targetPrefix, $currentVal) {
    global $uploadDir, $cur_tenant_id;
    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$fileKey];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];
        if (in_array($ext, $allowed, true)) {
            $newFileName = 't' . $cur_tenant_id . '_' . $targetPrefix . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . $newFileName;
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                return 'uploads/branding/' . $newFileName;
            }
        }
    }
    return $currentVal;
}

$set = get_tenant_settings($pdo, $cur_tenant_id);

if (isset($_POST['update'])) {
    $logo_path = handleSettingsFileUpload('logo_file', 'logo', $set['logo_path'] ?? 'logo.png');
    $receipt_bg = handleSettingsFileUpload('receipt_bg_file', 'receipt_bg', $set['receipt_bg'] ?? 'uploads/receipt_bg.webp');
    $masjid_receipt_bg = handleSettingsFileUpload('masjid_receipt_bg_file', 'masjid_receipt_bg', $set['masjid_receipt_bg'] ?? 'uploads/MAMFSS.webp');
    $id_card_bg = handleSettingsFileUpload('id_card_bg_file', 'id_card_bg', $set['id_card_bg'] ?? null);
    $sanad_bg = handleSettingsFileUpload('sanad_bg_file', 'sanad_bg', $set['sanad_bg'] ?? null);
    $signature_stamp_path = handleSettingsFileUpload('signature_stamp_file', 'stamp', $set['signature_stamp_path'] ?? null);
    $masjid_logo_path = handleSettingsFileUpload('masjid_logo_file', 'masjid_logo', $set['masjid_logo_path'] ?? null);
    $masjid_name = !empty($_POST['masjid_name']) ? trim($_POST['masjid_name']) : ($set['masjid_name'] ?? 'مسجد اشرف المدارس');

    // Reset handlers if user requested reset
    if (isset($_POST['reset_logo'])) $logo_path = 'logo.png';
    if (isset($_POST['reset_receipt_bg'])) $receipt_bg = 'uploads/receipt_bg.webp';
    if (isset($_POST['reset_masjid_receipt_bg'])) $masjid_receipt_bg = 'uploads/MAMFSS.webp';
    if (isset($_POST['reset_id_card_bg'])) $id_card_bg = null;
    if (isset($_POST['reset_sanad_bg'])) $sanad_bg = null;
    if (isset($_POST['reset_signature_stamp'])) $signature_stamp_path = null;
    if (isset($_POST['reset_masjid_logo'])) $masjid_logo_path = null;

    // Ensure row exists for this tenant
    $chk_id = $pdo->prepare("SELECT id FROM settings WHERE tenant_id = ? LIMIT 1");
    $chk_id->execute([$cur_tenant_id]);
    $setting_row_id = (int)$chk_id->fetchColumn();

    if ($setting_row_id <= 0) {
        $ins = $pdo->prepare("INSERT INTO settings (tenant_id, madrasa_name) VALUES (?, ?)");
        $ins->execute([$cur_tenant_id, $_POST['m_name']]);
        $setting_row_id = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("UPDATE settings SET 
        madrasa_name=?, phone=?, address=?, tagline=?, current_hijri_year=?, week_start_day=?, 
        ai_provider=?, gemini_api_key=?, groq_api_key=?, whatsapp_api_key=?, sms_api_key=?, 
        auto_notify_day=?, auto_notify_time=?,
        logo_path=?, receipt_bg=?, masjid_receipt_bg=?, id_card_bg=?, sanad_bg=?, signature_stamp_path=?,
        masjid_name=?, masjid_logo_path=?
        WHERE id = ? AND tenant_id = ?");
    
    $stmt->execute([
        $_POST['m_name'], $_POST['phone'], $_POST['address'], $_POST['tagline'], $_POST['h_year'], $_POST['w_start'],
        $_POST['ai_provider'], $_POST['g_key'], $_POST['groq_key'], $_POST['w_key'], $_POST['s_key'],
        $_POST['a_day'], $_POST['a_time'],
        $logo_path, $receipt_bg, $masjid_receipt_bg, $id_card_bg, $sanad_bg, $signature_stamp_path,
        $masjid_name, $masjid_logo_path,
        $setting_row_id, $cur_tenant_id
    ]);

    // Also update tenants table name and session tenant_name!
    try {
        $pdo->prepare("UPDATE tenants SET name = ? WHERE id = ?")->execute([$_POST['m_name'], $cur_tenant_id]);
        $_SESSION['tenant_name'] = $_POST['m_name'];
    } catch (Throwable $te) {}
    
    echo "<div class='container mt-3'><div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle me-2'></i> " . __('سیٹنگ اور تمام کسٹم دستاویزات کامیابی سے محفوظ ہو گئیں!', 'Settings and all custom documents saved successfully!') . "</div></div>";
    // Refresh settings
    $set = get_tenant_settings($pdo, $cur_tenant_id);
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-sliders-h me-2 text-primary"></i> <?php echo __('سافٹ ویئر سیٹنگز و ملٹی ٹیننٹ کسٹم برانڈنگ', 'Software Settings & Multi-Tenant Custom Branding'); ?></h2>
        <div class="d-flex gap-2">
            <a href="setup_wizard.php" class="btn btn-success fw-bold shadow-sm"><i class="fas fa-magic me-1"></i> <?php echo __('نیا سیٹ اپ وزرڈ کھولیں', 'Open Setup Wizard'); ?></a>
            <span class="badge bg-primary px-3 py-2 fs-6 d-flex align-items-center"><i class="fas fa-building me-1"></i> <?php echo __('ٹیننٹ پروفائل مینجمنٹ', 'Tenant Profile Management'); ?></span>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="row g-4">
            
            <!-- کالم 1: بنیادی پروفائل اور برانڈنگ -->
            <div class="col-lg-6">
                <!-- کارڈ 1: مدرسہ بنیادی معلومات -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="text-primary fw-bold"><i class="fas fa-university me-2"></i> <?php echo __('مدرسہ / ادارے کی بنیادی معلومات', 'Madrasa / Institute Basic Information'); ?></h5>
                        <p class="text-muted small"><?php echo __('یہ معلومات تمام رسیدوں، سٹوڈنٹ کارڈز اور اسناد پر خودکار طور پر نمایاں ہوں گی۔', 'This information will automatically appear on all receipts, student cards, and certificates.'); ?></p>
                    </div>
                    <div class="card-body p-4 pt-2">
                        <div class="mb-3">
                            <label class="fw-bold mb-1"><?php echo __('مدرسہ کا آفیشل نام', 'Official Madrasa Name'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="m_name" class="form-control form-control-lg fw-bold" value="<?php echo htmlspecialchars($set['madrasa_name'] ?? ''); ?>" required placeholder="<?php echo __('مثلاً: جامعہ اشرف المدارس', 'e.g. Jamia Ashraf-ul-Madaris'); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold mb-1"><?php echo __('سلوگن یا ذیلی عنوان (Tagline)', 'Tagline or Subtitle'); ?></label>
                            <input type="text" name="tagline" class="form-control" value="<?php echo htmlspecialchars($set['tagline'] ?? 'العلم نور - تعلیم و تربیت کا گہوارہ'); ?>" placeholder="<?php echo __('مثلاً: شعبہ حفظ و ناظرہ و تجوید القرآن', 'e.g. Department of Hifz & Nazra'); ?>">
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="fw-bold mb-1"><?php echo __('رابطہ فون نمبر / واٹس ایپ', 'Contact Phone / WhatsApp'); ?></label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($set['phone'] ?? ''); ?>" placeholder="0300-1234567">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold mb-1 text-danger"><?php echo __('موجودہ ہجری سال (Current Hijri Year)', 'Current Hijri Year'); ?></label>
                                <input type="text" name="h_year" class="form-control fw-bold fs-5 text-center" value="<?php echo htmlspecialchars($set['current_hijri_year'] ?? '1447'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold mb-1"><?php echo __('مدرسہ کا مکمل پتہ', 'Full Madrasa Address'); ?></label>
                            <textarea name="address" class="form-control" rows="2" placeholder="<?php echo __('شہر، علاقہ، گلی یا قریبی معروف مقام', 'City, Area, Street or landmark'); ?>"><?php echo htmlspecialchars($set['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3 p-3 bg-light rounded border border-success border-opacity-25">
                            <label class="fw-bold mb-1 text-success"><i class="fas fa-mosque me-1"></i> <?php echo __('مسجد کا آفیشل نام (Masjid Name)', 'Official Masjid Name'); ?></label>
                            <input type="text" name="masjid_name" class="form-control fw-bold" value="<?php echo htmlspecialchars($set['masjid_name'] ?? 'مسجد اشرف المدارس'); ?>" placeholder="<?php echo __('مثلاً: جامع مسجد اشرف المدارس', 'e.g. Jamia Masjid Ashraf-ul-Madaris'); ?>">
                            <small class="text-muted d-block mt-1"><?php echo __('مسجد موڈ اور مسجد عطیات/رسیدوں پر یہ نام استعمال ہوگا۔', 'This name will be used in Masjid Mode and for Masjid receipts.'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- کارڈ 2: لوگو اور مہر اپلوڈ -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="text-success fw-bold"><i class="fas fa-certificate me-2"></i> <?php echo __('کسٹم لوگو اور دستخط / مہر', 'Custom Logo & Stamp / Signature'); ?></h5>
                        <p class="text-muted small"><?php echo __('مدرسہ کا آفیشل لوگو اور مجاز دستخط کنندہ کے ڈیجیٹل دستخط و مہر۔', 'Official Madrasa logo and digital signature/stamp of authorized signatory.'); ?></p>
                    </div>
                    <div class="card-body p-4 pt-2">
                        <!-- لوگو اپلوڈ -->
                        <div class="p-3 mb-3 bg-light rounded border">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="fw-bold m-0"><i class="fas fa-image me-1 text-primary"></i> <?php echo __('مدرسہ کا آفیشل لوگو (Logo)', 'Official Madrasa Logo'); ?></label>
                                <?php if (!empty($set['logo_path']) && file_exists(__DIR__ . '/' . $set['logo_path'])): ?>
                                    <span class="badge bg-success"><?php echo __('فعال لوگو موجود ہے', 'Active logo uploaded'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo __('ڈیفالٹ لوگو', 'Default Logo'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-2 text-center" style="width: 80px; height: 80px;">
                                    <img src="<?php echo htmlspecialchars(!empty($set['logo_path']) ? $set['logo_path'] : 'logo.png'); ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="logo_file" class="form-control mb-2" accept="image/*">
                                    <small class="text-muted d-block"><?php echo __('سفارش کردہ فارمیٹ: شفاف PNG یا WebP (سائز 500x500 پکسل)', 'Recommended format: Transparent PNG or WebP (Size 500x500 px)'); ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- مسجد کا آفیشل لوگو اپلوڈ -->
                        <div class="p-3 mb-3 bg-light rounded border border-success border-opacity-25">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="fw-bold m-0 text-success"><i class="fas fa-mosque me-1"></i> <?php echo __('مسجد کا آفیشل لوگو (Masjid Logo)', 'Official Masjid Logo'); ?></label>
                                <?php if (!empty($set['masjid_logo_path']) && file_exists(__DIR__ . '/' . $set['masjid_logo_path'])): ?>
                                    <span class="badge bg-success"><?php echo __('لوگو موجود ہے', 'Logo uploaded'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo __('ڈیفالٹ لوگو', 'Default Logo'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-2 text-center" style="width: 80px; height: 80px;">
                                    <?php if (!empty($set['masjid_logo_path']) && file_exists(__DIR__ . '/' . $set['masjid_logo_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($set['masjid_logo_path']); ?>" alt="Masjid Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <i class="fas fa-mosque fa-2x text-muted mt-3"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="masjid_logo_file" class="form-control mb-2" accept="image/*">
                                    <small class="text-muted d-block"><?php echo __('مسجد کا مخصوص لوگو (شفاف PNG یا WebP)', 'Specific Masjid Logo (Transparent PNG or WebP)'); ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- دستخط و مہر اپلوڈ -->
                        <div class="p-3 bg-light rounded border">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="fw-bold m-0"><i class="fas fa-stamp me-1 text-warning"></i> <?php echo __('پرنسپل / مہتمم کے ڈیجیٹل دستخط و مہر', 'Digital Signature & Stamp of Principal/Admin'); ?></label>
                                <?php if (!empty($set['signature_stamp_path']) && file_exists(__DIR__ . '/' . $set['signature_stamp_path'])): ?>
                                    <span class="badge bg-success"><?php echo __('مہر موجود ہے', 'Stamp uploaded'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo __('سیٹ نہیں ہے', 'Not configured'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-2 text-center" style="width: 80px; height: 80px;">
                                    <?php if (!empty($set['signature_stamp_path']) && file_exists(__DIR__ . '/' . $set['signature_stamp_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($set['signature_stamp_path']); ?>" alt="Stamp" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <i class="fas fa-signature fa-2x text-muted mt-3"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="signature_stamp_file" class="form-control mb-2" accept="image/*">
                                    <small class="text-muted d-block"><?php echo __('شفاف پس منظر (Transparent PNG) دستخط یا مہر کی تصویر، جو رسید اور سند پر پرنٹ ہوگی۔', 'Transparent PNG image of signature or stamp, printed on receipts and certificates.'); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- کالم 2: کسٹم ڈاکومنٹس اور بیک گراؤنڈز -->
            <div class="col-lg-6">
                <!-- کارڈ 3: کسٹم بیک گراؤنڈز (رسید، کارڈ، سند) -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="text-warning fw-bold"><i class="fas fa-file-invoice me-2"></i> <?php echo __('کسٹم رسید، سٹوڈنٹ کارڈ و سند بیک گراؤنڈز', 'Custom Receipt, Student ID Card & Certificate Backgrounds'); ?></h5>
                        <p class="text-muted small"><?php echo __('مدرسہ کی اپنی آفیشل پرنٹ شدہ رسیدیں، کارڈز یا اسناد کے خالی بیک گراؤنڈز یہاں اپلوڈ کریں۔', 'Upload blank scanned templates of your official receipts, cards, and certificates here.'); ?></p>
                    </div>
                    <div class="card-body p-4 pt-2">
                        
                        <!-- 1. مدرسہ رسید بیک گراؤنڈ -->
                        <div class="p-3 mb-3 bg-light rounded border">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold m-0"><i class="fas fa-receipt me-1 text-success"></i> <?php echo __('مدرسہ رسید کا کسٹم بیک گراؤنڈ', 'Madrasa Receipt Custom Background'); ?></label>
                                <a href="print_receipt.php?id=1" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 11px;"><?php echo __('پریویو دیکھیں', 'View Preview'); ?></a>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-1 text-center" style="width: 100px; height: 65px;">
                                    <img src="<?php echo htmlspecialchars(!empty($set['receipt_bg']) ? $set['receipt_bg'] : 'uploads/receipt_bg.webp'); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="receipt_bg_file" class="form-control mb-1" accept="image/*">
                                    <small class="text-muted"><?php echo __('مدرسہ کی آفیشل خالی رسید کا اسکین شدہ پس منظر (Landscape)', 'Scanned background of official blank receipt (Landscape)'); ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- 2. مسجد رسید بیک گراؤنڈ -->
                        <div class="p-3 mb-3 bg-light rounded border">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold m-0"><i class="fas fa-mosque me-1 text-info"></i> <?php echo __('مسجد رسید کا کسٹم بیک گراؤنڈ', 'Masjid Receipt Custom Background'); ?></label>
                                <span class="badge bg-secondary"><?php echo __('مسجد موڈ رسید', 'Masjid Mode Receipt'); ?></span>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-1 text-center" style="width: 100px; height: 65px;">
                                    <img src="<?php echo htmlspecialchars(!empty($set['masjid_receipt_bg']) ? $set['masjid_receipt_bg'] : 'uploads/MAMFSS.webp'); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="masjid_receipt_bg_file" class="form-control mb-1" accept="image/*">
                                    <small class="text-muted"><?php echo __('مسجد فنڈز کے لیے مخصوص رسید کا پس منظر', 'Background specifically for Masjid donations receipt'); ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- 3. سٹوڈنٹ کارڈ کسٹم بیک گراؤنڈ -->
                        <div class="p-3 mb-3 bg-light rounded border">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold m-0"><i class="fas fa-id-card me-1 text-primary"></i> <?php echo __('سٹوڈنٹ آئی ڈی کارڈ کسٹم بیک گراؤنڈ', 'Student ID Card Custom Background'); ?></label>
                                <?php if (!empty($set['id_card_bg']) && file_exists(__DIR__ . '/' . $set['id_card_bg'])): ?>
                                    <span class="badge bg-success"><?php echo __('کسٹم کارڈ سیٹ ہے', 'Custom Card Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo __('سسٹم ڈیفالٹ کارڈ', 'System Default Card'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-1 text-center" style="width: 100px; height: 65px;">
                                    <?php if (!empty($set['id_card_bg']) && file_exists(__DIR__ . '/' . $set['id_card_bg'])): ?>
                                        <img src="<?php echo htmlspecialchars($set['id_card_bg']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="text-muted small d-flex align-items-center justify-content-center h-100"><i class="fas fa-id-badge fa-2x"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="id_card_bg_file" class="form-control mb-1" accept="image/*">
                                    <small class="text-muted"><?php echo __('سٹینڈرڈ کارڈ سائز (CR80 یا 3.375 x 2.125 انچ)', 'Standard card size (CR80 or 3.375 x 2.125 in)'); ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- 4. فارغ التحصیل طلبہ کی سند کا بیک گراؤنڈ -->
                        <div class="p-3 bg-light rounded border border-2 border-warning">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold m-0 text-dark"><i class="fas fa-award me-1 text-warning"></i> <?php echo __('فارغ التحصیل طلبہ کی سند (Sanad Background Template)', 'Graduation Certificate (Sanad Background Template)'); ?></label>
                                <?php if (!empty($set['sanad_bg']) && file_exists(__DIR__ . '/' . $set['sanad_bg'])): ?>
                                    <span class="badge bg-success"><?php echo __('آفیشل سند سیٹ ہے', 'Official Certificate Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><?php echo __('شاہکار اسلامی بارڈر موڈ', 'Master Islamic Border Mode'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 align-items-center">
                                <div class="border rounded bg-white p-1 text-center" style="width: 100px; height: 65px;">
                                    <?php if (!empty($set['sanad_bg']) && file_exists(__DIR__ . '/' . $set['sanad_bg'])): ?>
                                        <img src="<?php echo htmlspecialchars($set['sanad_bg']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="text-muted small d-flex align-items-center justify-content-center h-100"><i class="fas fa-scroll fa-2x text-warning"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <input type="file" name="sanad_bg_file" class="form-control mb-1" accept="image/*">
                                    <small class="text-muted"><?php echo __('مدرسہ کی آفیشل سند کا خالی فریم (A4 Landscape - 297x210mm)۔ اگر اپلوڈ نہ ہو تو سسٹم کا شاہکار اسلامی بارڈر استعمال ہوگا۔', 'Blank frame of official certificate (A4 Landscape - 297x210mm). If not uploaded, the system Islamic border is used.'); ?></small>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- کارڈ 4: AI، نوٹیفیکیشن و سسٹم سیٹنگز -->
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="text-secondary fw-bold"><i class="fas fa-robot me-2"></i> <?php echo __('خودکار پیغامات و AI کنفیگریشن', 'Automated Notifications & AI Configuration'); ?></h5>
                    </div>
                    <div class="card-body p-4 pt-2">
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="fw-bold mb-1"><?php echo __('ہفتہ وار رپورٹ کا آغاز', 'Week Start Day'); ?></label>
                                <select name="w_start" class="form-select">
                                    <?php
                                    $days = [
                                        0 => __('اتوار (Sunday)', 'Sunday'),
                                        1 => __('پیر (Monday)', 'Monday'),
                                        2 => __('منگل (Tuesday)', 'Tuesday'),
                                        3 => __('بدھ (Wednesday)', 'Wednesday'),
                                        4 => __('جمعرات (Thursday)', 'Thursday'),
                                        5 => __('جمعہ (Friday)', 'Friday'),
                                        6 => __('ہفتہ (Saturday)', 'Saturday')
                                    ];
                                    $currentWStart = $set['week_start_day'] ?? 6;
                                    foreach ($days as $val => $label) {
                                        $sel = ($currentWStart == $val) ? 'selected' : '';
                                        echo "<option value='$val' $sel>$label</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold mb-1"><?php echo __('AI فراہم کنندہ (AI Provider)', 'AI Provider'); ?></label>
                                <select name="ai_provider" class="form-select">
                                    <option value="gemini" <?php echo (($set['ai_provider'] ?? 'gemini') === 'gemini') ? 'selected' : ''; ?>>Google Gemini</option>
                                    <option value="groq" <?php echo (($set['ai_provider'] ?? '') === 'groq') ? 'selected' : ''; ?>>Groq (Llama)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="fw-bold mb-1"><?php echo __('خودکار رپورٹ کا دن', 'Auto Notification Day'); ?></label>
                                <select name="a_day" class="form-select">
                                    <?php foreach ($days as $val => $label) {
                                        $sel = (($set['auto_notify_day']??4) == $val) ? 'selected' : '';
                                        echo "<option value='$val' $sel>$label</option>";
                                    } ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold mb-1"><?php echo __('وقت', 'Time'); ?></label>
                                <input type="time" name="a_time" class="form-control" value="<?php echo $set['auto_notify_time'] ?? '18:00'; ?>">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="fw-bold mb-1">Gemini API Keys</label>
                            <textarea name="g_key" class="form-control" rows="2" placeholder="One key per line"><?php echo htmlspecialchars($set['gemini_api_key'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="fw-bold mb-1">Groq API Keys</label>
                            <textarea name="groq_key" class="form-control" rows="2" placeholder="One key per line"><?php echo htmlspecialchars($set['groq_api_key'] ?? ''); ?></textarea>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="fw-bold mb-1">WhatsApp API Key</label>
                                <input type="password" name="w_key" class="form-control" value="<?php echo htmlspecialchars($set['whatsapp_api_key'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold mb-1">SMS API Key</label>
                                <input type="password" name="s_key" class="form-control" value="<?php echo htmlspecialchars($set['sms_api_key'] ?? ''); ?>">
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>

        <!-- فلوٹنگ سیو بٹن بار -->
        <div class="card shadow-lg border-0 sticky-bottom p-3 bg-white" style="border-radius: 12px; z-index: 1020;">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    <i class="fas fa-info-circle text-primary me-1"></i> <?php echo __('تمام تبدیلیاں فورا محفوظ ہو کر رسیدوں، کارڈز اور اسناد پر نافذ ہو جائیں گی۔', 'All changes will be saved instantly and applied to receipts, cards, and certificates.'); ?>
                </div>
                <button type="submit" name="update" class="btn btn-primary btn-lg px-5 fw-bold shadow">
                    <i class="fas fa-save me-2"></i> <?php echo __('تمام سیٹنگز و کسٹم برانڈنگ محفوظ کریں', 'Save All Settings & Custom Branding'); ?>
                </button>
            </div>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>
