# پروجیکٹ برین (PROJECT_BRAIN.md) — ملٹی ٹیننٹ مدرسہ و مکتب مینجمنٹ سسٹم (MC-MMS SaaS)

**آخری اپڈیٹ:** 2026-11-25  
**فعال ورژن (Tri-Platform Parity):**  
- **Flutter ایپ (`pubspec.yaml`):** `5.7.162+381` (`com.mamfss.madrasa.app`)  
- **PHP ویب پینل (`Madrasa web app/version.php`):** `5.7.162`  
- **لائیو سرور اینکر (Multi-Tenant SaaS):** `https://madrasa.mamfss.com/` (PHP `8.4.26`)  

---

## 1. بنیادی فنِ تعمیر اور ملٹی ٹیننٹ تحفظات
- سنگل ٹیننٹ حوالہ جاتی پروجیکٹ (`D:\CodeHubb\madrasa-app`) کی تمام مصدقہ درستگیاں اور نئے فیچرز ملٹی ٹیننٹ ساس (`e:\madrasa-app`) میں ضم کر دیے گئے ہیں، جبکہ سنگل ٹیننٹ سورس کو مکمل طور پر غیر تبدیل شدہ رکھا گیا ہے۔
- ملٹی ٹیننٹ کے تمام بنیادی اجزاء مکمل محفوظ ہیں: `super_admin/`، `register.php`، `tenants`، `subscriptions`، `subscription_plans`، `allowed_modules`، `tenant_id` آئسولیشن، `SafeTenantPDO.php`، کوٹا گارڈز (`currentTenantMaxStudents` وغیرہ)، `OfflineSyncQueue`، `SyncConflictHandler`، اور `MC-MMS` برانڈنگ۔
- **مدرسہ بمقابلہ مسجد آئسولیشن و پیریٹی:** `system_type` کی مکمل علیحدگی (`Madrasa` بمقابلہ `Masjid`) اور دونوں موڈز میں برابر فیچرز برقرار ہیں۔
- **ڈی ہارڈکوڈنگ:** سنگل ادارے کے جامد نام (`اشرف المدارس` / `فاروقہ، ضلع سرگودھا`) کی جگہ تمام اسکرینز، پی ڈی ایف، رپورٹس اور یاددہانیوں میں ڈائنامک `currentTenantName` نافذ کیا گیا ہے۔

---

## 2. ویب پینل اور فلٹر میں شامل اہم تبدیلیاں
- **ویب پینل (`Madrasa web app/`):** سنگل ٹیننٹ سے مصدقہ مالیاتی، اکیڈمک، رزلٹ کارڈ، پے رول، بیک اپ، اور سنک بہتریوں کو ملٹی ٹیننٹ APIs و سکیورٹی گارڈز کے ساتھ ضم کیا گیا، اور `version.php` کو `5.7.162` پر اپڈیٹ کر کے لائیو سرور کے ساتھ 100% MD5 ہم آہنگی تصدیق کی گئی۔
- **ریپازیٹریز و ڈیش بورڈ سروسز (`lib/db/repositories/`, `lib/utils/`, `lib/services/`):** `DataRefreshBus`، `FinancePdfExportService`، `DatabaseBackupService`، `OmniSearchDialog`، `SalaryStatusPage`، `AdminRepository`، `InventoryRepository`، `LibraryRepository`، اور `HostelRepository` کو ملٹی ٹیننٹ کالمز اور ڈائنامک ٹیننٹ نام کے ساتھ شامل کیا گیا۔
- **SQLite و سنک انجن (`lib/db_helper.dart`, `lib/sync/`):** مطابقتی کالمز، کورس/رزلٹ APIs، `masjid_itikaf` سنک کنفیگریشن، `server_time` اسٹیمپ، محفوظ ID گارڈ، اور ملٹی ٹیننٹ اجازتوں کے مکمل میٹاڈیٹا کی بحالی شامل کی گئی۔
- **فلٹر UI و لاگ ان سکیورٹی (`lib/screens/`):** پے رول سیلیکٹیو موڈل، ریئل GL سالانہ رپورٹ و اِنکم اسٹیٹمنٹ/ٹرائل بیلنس، حاضری `TabBar` فکس، لاگ ان 5-ناکام کوششوں پر 30 سیکنڈ لاک آؤٹ، `home.dart` اسٹیٹ کارڈ اوور فلو فکس، اور `fee_reminders.dart` انٹرپولیشن فکس شامل کیے گئے۔

---

## 3. حتمی تصدیقی خلاصہ (Mechanical Verification)
- **لائیو سرور و PHP پارِٹی (`https://madrasa.mamfss.com/`):**  
  - لائیو PHP `8.4.26` پر `348` فعال فائلوں کا MD5 اور `TOKEN_PARSE` چیک: `0` سنٹیکس خرابیاں؛ تمام فعال فائلیں (بشمول `login.php`، `register.php`، `api.php`، `version.php`) مقامی ویب پینل سے 100% مطابق (صرف `archive/` کی 3 پرانی آرکائیو فائلوں میں فرق پایا گیا)۔
- **Flutter تجزیہ اور یونٹ ٹیسٹس:**  
  - `flutter analyze --no-fatal-infos --no-fatal-warnings lib test`: `0` ایررز (`46` pre-existing info/warning آئٹمز)۔  
  - `flutter test`: کل `54` ٹیسٹ کیسز 100% پاس (`00:18 +54: All tests passed!`)۔
- **Android ایمیولیٹر (`emulator-5554`, `com.mamfss.madrasa.app`):**  
  - `flutter build apk --debug` کامیابی سے مکمل، SQLite مائیگریشن (`v38`, `v42`, `v43`, `v48`) بغیر کسی کریش کے مکمل، ہوم/داخلہ فارم/لاگ ان/انگریزی-اردو سوئچ اور لائیو سرور `https://madrasa.mamfss.com/` سے "سرور آن لائن ہے اور جواب دے رہا ہے" کنکشن ٹیسٹ کامیاب۔
- **Windows ڈیسک ٹاپ (`110% Tri-Platform Parity`):**  
  - `flutter build windows --release` (`build\windows\x64\runner\Release\madrasa_app.exe`) بغیر کسی ایرر کے مکمل اور لانچ ٹیسٹ (`HasWindow=True`) کامیاب۔

---

## 4. معلوم حدود / غیر تصدیق شدہ حصے
- مقامی Windows مشین پر `php.exe` انسٹال نہ ہونے کی وجہ سے حتمی ورژن بمپ کے بعد PHP سنٹیکس کی تصدیق براہِ راست لائیو PHP `8.4.26` (`token_get_all(..., TOKEN_PARSE)`) سے کی گئی۔
- Android ایمیولیٹر اور لائیو ویب پینل پر غیر مستند (unauthenticated) اور عمومی صفحات/سرور کنکشن ٹیسٹ براہِ راست چلائے گئے؛ مستند لائیو یوزر پاسورڈ کے ساتھ فلٹر لاگ ان کے اندر موجود تمام ذیلی اسکرینز کی ایمیولیٹر کلک تھرو جانچ اس سیشن میں لائیو یوزر کریڈینشلز نہ ہونے کے باعث محدود رہی (تاہم SQLite و لاجک کو 54 یونٹ ٹیسٹس سے جانچا گیا ہے)۔
