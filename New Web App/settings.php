<?php include 'header.php'; ?>
<?php
if (isset($_POST['update'])) {
    $stmt = $pdo->prepare("UPDATE settings SET 
        madrasa_name=?, phone=?, current_hijri_year=?, week_start_day=?, 
        ai_provider=?, gemini_api_key=?, groq_api_key=?, whatsapp_api_key=?, sms_api_key=?, 
        auto_notify_day=?, auto_notify_time=? WHERE id=1");
    $stmt->execute([
        $_POST['m_name'], $_POST['phone'], $_POST['h_year'], $_POST['w_start'],
        $_POST['ai_provider'], $_POST['g_key'], $_POST['groq_key'], $_POST['w_key'], $_POST['s_key'],
        $_POST['a_day'], $_POST['a_time']
    ]);
    echo "<div class='alert alert-success fw-bold'>✅ سیٹنگ اپڈیٹ ہو گئی!</div>";
}
$set = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <h2 class="mb-4 text-primary fw-bold"><i class="fas fa-cog me-2"></i> سافٹ ویئر سیٹنگ (Settings)</h2>
            <div class="card shadow-sm border-0" style="border-radius: 15px;">
                <div class="card-body p-4">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="fw-bold mb-1">مدرسہ کا نام</label>
                            <input type="text" name="m_name" class="form-control" value="<?php echo htmlspecialchars($set['madrasa_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold mb-1"><?php echo t('phone'); ?></label>
                            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($set['phone'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label class="fw-bold mb-1 text-danger">موجودہ ہجری سال (Current Hijri Year)</label>
                            <input type="text" name="h_year" class="form-control fw-bold fs-5" value="<?php echo htmlspecialchars($set['current_hijri_year'] ?? '1447'); ?>" required>
                            <small class="text-muted">یہ سال نئے داخلہ نمبروں کے لیے استعمال ہوگا۔ (مثلاً 1447)</small>
                        </div>
                        <div class="mb-4">
                            <label class="fw-bold mb-1">ہفتہ وار رپورٹ کا آغاز (Week Start Day)</label>
                            <select name="w_start" class="form-select">
                                <?php
                                $days = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
                                $currentWStart = $set['week_start_day'] ?? 6;
                                foreach ($days as $val => $label) {
                                    $sel = ($currentWStart == $val) ? 'selected' : '';
                                    echo "<option value='$val' $sel>$label</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <hr>
                        <h5 class="text-secondary fw-bold mb-3"><i class="fas fa-key me-2"></i> API و آٹومیشن سیٹنگز</h5>
                        
                        <div class="mb-3">
                            <label class="fw-bold mb-1">AI Provider</label>
                            <select name="ai_provider" class="form-select">
                                <option value="gemini" <?php echo (($set['ai_provider'] ?? 'gemini') === 'gemini') ? 'selected' : ''; ?>>Gemini</option>
                                <option value="groq" <?php echo (($set['ai_provider'] ?? '') === 'groq') ? 'selected' : ''; ?>>Groq (Llama)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold mb-1">Gemini API Keys</label>
                            <textarea name="g_key" class="form-control" rows="3" placeholder="One key per line, or comma separated"><?php echo htmlspecialchars($set['gemini_api_key'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold mb-1">Groq API Keys</label>
                            <textarea name="groq_key" class="form-control" rows="3" placeholder="One key per line, or comma separated"><?php echo htmlspecialchars($set['groq_api_key'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold mb-1">WhatsApp API Key</label>
                            <input type="password" name="w_key" class="form-control" value="<?php echo htmlspecialchars($set['whatsapp_api_key'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold mb-1">SMS API Key</label>
                            <input type="password" name="s_key" class="form-control" value="<?php echo htmlspecialchars($set['sms_api_key'] ?? ''); ?>">
                        </div>

                        <div class="row g-2 mb-4">
                            <div class="col-md-7">
                                <label class="fw-bold mb-1">خودکار رپورٹ کا دن</label>
                                <select name="a_day" class="form-select">
                                    <?php foreach ($days as $val => $label) {
                                        $sel = (($set['auto_notify_day']??4) == $val) ? 'selected' : '';
                                        echo "<option value='$val' $sel>$label</option>";
                                    } ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="fw-bold mb-1">وقت</label>
                                <input type="time" name="a_time" class="form-control" value="<?php echo $set['auto_notify_time'] ?? '18:00'; ?>">
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" name="update" class="btn btn-primary fw-bold py-2">
                                <i class="fas fa-save me-2"></i> سیٹنگ محفوظ کریں
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mt-4 alert alert-info small">
                <i class="fas fa-info-circle me-2"></i> 
                ہجری سال تبدیل کرنے سے پہلے یقینی بنائیں کہ آپ نئے تعلیمی سال کا آغاز کر رہے ہیں۔
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
