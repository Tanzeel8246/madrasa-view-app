<?php
/**
 * ════════════════════════════════════════════════════════════════════
 *  MAMFSS Cloud Madrasa & Masjid SaaS Management System
 *  جدید ترین کلاؤڈ مینیجمنٹ سسٹم — آفیشل لینڈنگ پیج
 * ════════════════════════════════════════════════════════════════════
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

try {
    require_once __DIR__ . '/db.php';
} catch (Throwable $e) {
    die("<div style='font-family:sans-serif;padding:40px;text-align:center;direction:rtl;'><h2>ڈیٹابیس رابطہ کی خرابی</h2><p style='color:#c00;font-size:16px;'>" . htmlspecialchars($e->getMessage()) . "</p></div>");
}

// Language initialization and persistence
if (isset($_GET['lang'])) { $_SESSION['lang'] = $_GET['lang']; }
if (isset($_GET['lang'])) { @setcookie('app_language', $_GET['lang'], time() + (86400 * 365), '/'); }
$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : ($_COOKIE['app_language'] ?? 'ur');

// 1. Fetch Global Live Stats for Hero Metrics
$stat_tenants = 50;
$stat_students = 15000;
$stat_classes = 450;
$stat_teachers = 1200;

try {
    $tCount = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status IN ('active', 'trial')")->fetchColumn();
    if ($tCount > 0) $stat_tenants = max($stat_tenants, $tCount);

    $sCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
    if ($sCount > 0) $stat_students = max($stat_students, $sCount);

    $cCount = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE (status = 'Active' OR status = '' OR status IS NULL)")->fetchColumn();
    if ($cCount > 0) $stat_classes = max($stat_classes, $cCount);

    $teaCount = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
    if ($teaCount > 0) $stat_teachers = max($stat_teachers, $teaCount);
} catch (Throwable $e) {}

// 2. Self-heal and synchronize official subscription plan prices in database
try {
    $pdo->exec("UPDATE subscription_plans SET price = 1000, price_monthly = 1000, price_yearly = 10000, max_students = 50, max_classes = 3, max_teachers = 4, is_free_trial = 0 WHERE LOWER(code) = 'basic'");
    $pdo->exec("UPDATE subscription_plans SET price = 2000, price_monthly = 2000, price_yearly = 20000, max_students = 100, max_classes = 6, max_teachers = 8, is_free_trial = 0 WHERE LOWER(code) = 'standard'");
    $pdo->exec("UPDATE subscription_plans SET price = 3000, price_monthly = 3000, price_yearly = 30000, max_students = 200, max_classes = 12, max_teachers = 16, is_free_trial = 0 WHERE LOWER(code) IN ('premium_200', 'advanced', 'premium')");
    $pdo->exec("UPDATE subscription_plans SET price = 0, price_monthly = 0, price_yearly = 0, max_students = 20, max_classes = 1, max_teachers = 2, is_free_trial = 1 WHERE LOWER(code) = 'free'");
    $pdo->exec("UPDATE subscription_plans SET price = 0, price_monthly = 0, price_yearly = 0, max_students = 99999, max_classes = 99999, max_teachers = 99999, is_free_trial = 0 WHERE LOWER(code) = 'enterprise'");
} catch (Throwable $eSyncPlans) {}

// Fetch Subscription Plans from Database (Deduplicated & Ordered)
$plans = [];
try {
    $rawPlans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC, price_monthly ASC, price ASC")->fetchAll(PDO::FETCH_ASSOC);
    $seenCodes = [];
    foreach ($rawPlans as $rp) {
        $cCode = strtolower(trim($rp['code'] ?? ''));
        if (!isset($seenCodes[$cCode])) {
            $seenCodes[$cCode] = true;
            $plans[] = $rp;
        }
    }
} catch (Throwable $e) {}

// Enforce official natural package ordering
$planSortWeights = [
    'free' => 1,
    'basic' => 2,
    'standard' => 3,
    'premium_200' => 4,
    'advanced' => 4,
    'enterprise' => 5
];
usort($plans, function($a, $b) use ($planSortWeights) {
    $wa = $planSortWeights[strtolower($a['code'] ?? '')] ?? 99;
    $wb = $planSortWeights[strtolower($b['code'] ?? '')] ?? 99;
    return $wa <=> $wb;
});

// Fallback to official 5 packages if table not populated yet
if (empty($plans)) {
    $plans = [
        [
            'code' => 'free',
            'name' => 'مفت ٹرائل (Free Trial)',
            'description' => '20 طلبہ، 1 کلاس، 2 اساتذہ و عملہ — صرف ویب پینل رسائی',
            'price' => 0,
            'max_students' => 20,
            'max_classes' => 1,
            'max_teachers' => 2,
            'has_mobile_app' => 0,
            'has_desktop_app' => 0,
            'is_free_trial' => 1,
            'sort_order' => 1
        ],
        [
            'code' => 'basic',
            'name' => 'بنیادی پیکج (Basic)',
            'description' => '50 طلبہ، 3 کلاسز، 4 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + موبائل + ونڈوز)',
            'price' => 1000,
            'price_monthly' => 1000,
            'price_yearly' => 10000,
            'max_students' => 50,
            'max_classes' => 3,
            'max_teachers' => 4,
            'has_mobile_app' => 1,
            'has_desktop_app' => 1,
            'is_free_trial' => 0,
            'sort_order' => 2
        ],
        [
            'code' => 'standard',
            'name' => 'معیاری پیکج (Standard)',
            'description' => '100 طلبہ، 6 کلاسز، 8 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + موبائل + ونڈوز)',
            'price' => 2000,
            'price_monthly' => 2000,
            'price_yearly' => 20000,
            'max_students' => 100,
            'max_classes' => 6,
            'max_teachers' => 8,
            'has_mobile_app' => 1,
            'has_desktop_app' => 1,
            'is_free_trial' => 0,
            'sort_order' => 3
        ],
        [
            'code' => 'premium_200',
            'name' => 'ایڈوانس پیکج (Advanced)',
            'description' => '200 طلبہ، 12 کلاسز، 16 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + موبائل + ونڈوز)',
            'price' => 3000,
            'price_monthly' => 3000,
            'price_yearly' => 30000,
            'max_students' => 200,
            'max_classes' => 12,
            'max_teachers' => 16,
            'has_mobile_app' => 1,
            'has_desktop_app' => 1,
            'is_free_trial' => 0,
            'sort_order' => 4
        ],
        [
            'code' => 'enterprise',
            'name' => 'لامحدود انٹرپرائز (Unlimited)',
            'description' => 'لامحدود طلبہ، کلاسز، اساتذہ و عملہ — تمام ماڈیولز مع کسٹم ڈیڈیکیٹڈ سپورٹ',
            'price' => 0,
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_students' => 99999,
            'max_classes' => 99999,
            'max_teachers' => 99999,
            'has_mobile_app' => 1,
            'has_desktop_app' => 1,
            'is_free_trial' => 0,
            'sort_order' => 5
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo ($lang == 'ur') ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Primary SEO Meta Tags -->
    <title><?php echo ($lang == 'ur') ? 'MC-MMS — مائنڈکرافٹ کلاؤڈ مدرسہ و مسجد مینیجمنٹ سسٹم | جدید دینی تعلیمی ERP سافٹ ویئر' : 'MC-MMS — MindCrafts Cloud Madrasa & Masjid Management System | Islamic ERP'; ?></title>
    <meta name="title" content="<?php echo ($lang == 'ur') ? 'MC-MMS — مائنڈکرافٹ کلاؤڈ مدرسہ و مسجد مینیجمنٹ سسٹم | جدید دینی تعلیمی ERP سافٹ ویئر' : 'MC-MMS — MindCrafts Cloud Madrasa & Masjid Management System | Islamic ERP'; ?>">
    <meta name="description" content="پاکستان کا جدید ترین کلاؤڈ مدرسہ و مسجد مینیجمنٹ سسٹم (MC-MMS)۔ طلبہ داخلہ، روزانہ حاضری، آن لائن حفظ و ناظرہ ٹریکر، شرعی ڈبل انٹری اکاؤنٹنگ، فیس وصولی، دارالاقامہ، کتب خانہ، امتحانات اور AI خودکار رپورٹنگ۔ ویب، اینڈرائڈ اور ونڈوز ہم آہنگ۔ مفت ٹرائل حاصل کریں۔">
    <meta name="keywords" content="مدرسہ سافٹ ویئر, مسجد مینیجمنٹ سسٹم, حفظ قرآن ٹریکر, مدرسہ اکاؤنٹنگ سافٹ ویئر, مدرسہ ERP پاکستان, کلاؤڈ مدرسہ سافٹ ویئر, جامعہ مینیجمنٹ سسٹم, دارالعلوم سافٹ ویئر, دینی مدارس ریکارڈ سسٹم, Islamic School Management Software, Madrasa ERP, Masjid Management System, Maktab App, Hifz Quran Tracker, Urdu Madrasa Software, MC-MMS">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <meta name="googlebot" content="index, follow">
    <meta name="author" content="MindCrafts AI (مائنڈکرافٹ)">
    <meta name="application-name" content="MC-MMS">
    <link rel="canonical" href="https://madrasa.mamfss.com/">

    <!-- Open Graph / Facebook / WhatsApp / LinkedIn -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://madrasa.mamfss.com/">
    <meta property="og:site_name" content="MC-MMS Mindcrafts">
    <meta property="og:locale" content="ur_PK">
    <meta property="og:title" content="MC-MMS — مائنڈکرافٹ کلاؤڈ مدرسہ و مسجد مینیجمنٹ سسٹم | پاکستان کا نمبر 1 تعلیمی ERP">
    <meta property="og:description" content="دینی مدارس، جامعات اور مساجد کے تمام انتظامی، تعلیمی اور مالیاتی امور کا یکجا کلاؤڈ حل۔ ویب، موبائل ایپ اور ونڈوز ڈیسک ٹاپ کے ساتھ۔ 20 طلبہ کے لیے بالکل مفت۔">
    <meta property="og:image" content="https://madrasa.mamfss.com/logo_mindcrafts.svg">
    <meta property="og:image:alt" content="MC-MMS Madrasa Management System Logo">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://madrasa.mamfss.com/">
    <meta name="twitter:title" content="MC-MMS — جدید کلاؤڈ مدرسہ و مسجد مینیجمنٹ سسٹم">
    <meta name="twitter:description" content="حفظ و ناظرہ ٹریکنگ، بائیو میٹرک حاضری، ڈبل انٹری اکاؤنٹنگ اور AI فیچرز کے ساتھ جدید ترین سافٹ ویئر۔">
    <meta name="twitter:image" content="https://madrasa.mamfss.com/logo_mindcrafts.svg">

    <!-- Schema.org Structured Data (JSON-LD) for Google Rich Snippets -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "SoftwareApplication",
          "@id": "https://madrasa.mamfss.com/#software",
          "name": "MC-MMS (مائنڈکرافٹ مدرسہ و مسجد مینیجمنٹ سسٹم)",
          "operatingSystem": "Web Browser, Android, Windows Desktop",
          "applicationCategory": "EducationalApplication, BusinessApplication",
          "description": "پاکستان و دنیا بھر کے دینی مدارس، جامعات اور مساجد کے لیے کلاؤڈ بیسڈ مکمل تعلیمی، مالیاتی اور انتظامی ERP حل۔",
          "offers": [
            {
              "@type": "Offer",
              "name": "Free Trial (مفت ٹرائل)",
              "price": "0",
              "priceCurrency": "PKR",
              "description": "20 طلبہ، 1 کلاس، 2 اساتذہ — لائف ٹائم فری"
            },
            {
              "@type": "Offer",
              "name": "Basic (بنیادی پیکج)",
              "price": "1500",
              "priceCurrency": "PKR",
              "description": "50 طلبہ، 3 کلاسز، 4 اساتذہ"
            },
            {
              "@type": "Offer",
              "name": "Standard (معیاری پیکج)",
              "price": "2500",
              "priceCurrency": "PKR",
              "description": "100 طلبہ، 6 کلاسز، 8 اساتذہ"
            },
            {
              "@type": "Offer",
              "name": "Premium (پریمیم پیکج)",
              "price": "4000",
              "priceCurrency": "PKR",
              "description": "200 طلبہ، 12 کلاسز، 16 اساتذہ"
            }
          ],
          "aggregateRating": {
            "@type": "AggregateRating",
            "ratingValue": "4.9",
            "reviewCount": "180",
            "bestRating": "5",
            "worstRating": "1"
          }
        },
        {
          "@type": "Organization",
          "@id": "https://madrasa.mamfss.com/#organization",
          "name": "MindCrafts AI",
          "url": "https://madrasa.mamfss.com",
          "logo": "https://madrasa.mamfss.com/logo_mindcrafts.svg",
          "contactPoint": {
            "@type": "ContactPoint",
            "telephone": "+923000000000",
            "contactType": "customer service",
            "availableLanguage": ["Urdu", "English", "Arabic"]
          }
        },
        {
          "@type": "WebSite",
          "@id": "https://madrasa.mamfss.com/#website",
          "url": "https://madrasa.mamfss.com/",
          "name": "MC-MMS",
          "publisher": {
            "@id": "https://madrasa.mamfss.com/#organization"
          },
          "inLanguage": "ur-PK"
        },
        {
          "@type": "FAQPage",
          "@id": "https://madrasa.mamfss.com/#faq",
          "mainEntity": [
            {
              "@type": "Question",
              "name": "کیا 20 طلبہ کا فری ٹرائل واقعی ہمیشہ کے لیے مفت ہے؟",
              "acceptedAnswer": {
                "@type": "Answer",
                "text": "جی ہاں، 20 طلبہ اور 1 کلاس کے لیے ہمارا بنیادی پیکج بغیر کسی کریڈٹ کارڈ یا وقتی پابندی کے بالکل مفت ہے۔ آپ جب چاہیں ادارہ بڑھنے پر اسے باآسانی اپ گریڈ کر سکتے ہیں۔"
              }
            },
            {
              "@type": "Question",
              "name": "کیا انٹرنیٹ منقطع ہونے کی صورت میں بھی سافٹ ویئر کام کرتا ہے؟",
              "acceptedAnswer": {
                "@type": "Answer",
                "text": "جی ہاں! ونڈوز ڈیسک ٹاپ سافٹ ویئر اور موبائل ایپ میں مکمل آف لائن لوکل ڈیٹابیس سپورٹ موجود ہے، انٹرنیٹ بحال ہوتے ہی تمام ڈیٹا خودکار سنک ہو جاتا ہے۔"
              }
            },
            {
              "@type": "Question",
              "name": "کیا اس میں مسجد اور مکتب کا انتظام الگ سے موجود ہے؟",
              "acceptedAnswer": {
                "@type": "Answer",
                "text": "جی ہاں! سسٹم میں مدرسہ موڈ اور مسجد/مکتب موڈ کا الگ الگ مکمل فریم ورک موجود ہے جو نمازیوں، چندہ جات، امام و موذن کے معاملات اور جزوقتی مکتب کو الگ الگ سنبھالتا ہے۔"
              }
            },
            {
              "@type": "Question",
              "name": "اس سسٹم میں اے آئی (AI) کے کیا کیا فیچرز شامل ہیں؟",
              "acceptedAnswer": {
                "@type": "Answer",
                "text": "سسٹم میں سمارٹ اے آئی اسسٹنٹ شامل ہے جو حفظ قرآن کی پیشرفت کا جائزہ لیتا ہے، تعلیمی کمزوریوں کی نشاندہی کرتا ہے، فنانس کے بجٹ الرٹس دیتا ہے اور خودکار اردو و عربی سرٹیفکیٹس و رپورٹس تیار کرتا ہے۔"
              }
            }
          ]
        }
      ]
    }
    </script>
    
    <!-- CSS & Fonts -->
    <?php if ($lang == 'ur'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <?php else: ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Kufi+Arabic:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --sa-bg: #090e17;
            --sa-card-bg: rgba(18, 26, 43, 0.75);
            --sa-card-border: rgba(255, 255, 255, 0.08);
            --sa-card-hover: rgba(26, 38, 64, 0.85);
            --sa-primary: #10b981;
            --sa-primary-glow: rgba(16, 185, 129, 0.25);
            --sa-secondary: #06b6d4;
            --sa-accent: #f59e0b;
            --sa-text-main: #f8fafc;
            --sa-text-muted: #cbd5e1;
            --sa-text-sub: #f1f5f9;
        }

        /* High-Contrast Readability Overrides for Dark Backgrounds */
        .text-muted, p.text-muted, div.text-muted, span.text-muted, small.text-muted, td.text-muted, li.text-muted {
            color: #cbd5e1 !important;
        }
        .text-sub {
            color: #f1f5f9 !important;
        }
        p {
            color: #e2e8f0;
        }
        .glass-panel p {
            color: #cbd5e1;
        }
        .stat-box .text-muted {
            color: #e2e8f0 !important;
        }
        .pricing-card .text-muted {
            color: #cbd5e1 !important;
        }
        .feature-item span {
            color: #f8fafc !important;
        }
        .footer-desc {
            color: #cbd5e1 !important;
        }

        * {
            box-sizing: border-box;
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Noto Nastaliq Urdu', Tahoma, sans-serif;
            background-color: var(--sa-bg);
            color: var(--sa-text-main);
            overflow-x: hidden;
            line-height: 1.7;
            background-image: 
                radial-gradient(at 10% 10%, rgba(16, 185, 129, 0.12) 0px, transparent 50%),
                radial-gradient(at 90% 20%, rgba(6, 182, 212, 0.10) 0px, transparent 50%),
                radial-gradient(at 50% 90%, rgba(245, 158, 11, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
        }

        .urdu-font {
            font-family: 'Noto Nastaliq Urdu', Tahoma, sans-serif;
            line-height: 2.1;
        }

        .kufi-font {
            font-family: 'Noto Kufi Arabic', sans-serif;
        }

        /* Glassmorphism Classes */
        .glass-panel {
            background: var(--sa-card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--sa-card-border);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            transition: all 0.3s ease;
        }

        .glass-panel:hover {
            border-color: rgba(16, 185, 129, 0.3);
            box-shadow: 0 15px 35px rgba(16, 185, 129, 0.15);
        }

        /* Navigation Bar */
        .navbar-custom {
            background: rgba(9, 14, 23, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            transition: all 0.3s ease;
        }

        .nav-link-custom {
            color: var(--sa-text-sub) !important;
            font-weight: 500;
            padding: 8px 16px !important;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }

        .nav-link-custom:hover {
            color: var(--sa-primary) !important;
            transform: translateY(-1px);
        }

        /* Buttons */
        .btn-sa-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff !important;
            font-weight: 700;
            border: none;
            border-radius: 50px;
            padding: 12px 28px;
            box-shadow: 0 6px 20px var(--sa-primary-glow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-sa-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
            color: #ffffff;
        }

        .btn-sa-outline {
            background: rgba(255, 255, 255, 0.05);
            color: var(--sa-text-main) !important;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 50px;
            padding: 12px 26px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-sa-outline:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--sa-primary);
            color: var(--sa-primary) !important;
            transform: translateY(-2px);
        }

        /* Hero Badges */
        .badge-pill-glow {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .hero-title {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1.35;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 50%, #10b981 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.1rem;
            }
        }

        /* Stat Counters */
        .stat-box {
            padding: 24px;
            text-align: center;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .stat-number {
            font-size: 2.3rem;
            font-weight: 800;
            color: var(--sa-primary);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* Platform Feature Cards */
        .platform-icon-circle {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
        }

        /* Pricing Cards */
        .pricing-card {
            background: var(--sa-card-bg);
            border: 1px solid var(--sa-card-border);
            border-radius: 22px;
            padding: 32px 24px;
            position: relative;
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .pricing-card.popular {
            border: 2px solid var(--sa-primary);
            background: linear-gradient(180deg, rgba(16, 185, 129, 0.08) 0%, rgba(18, 26, 43, 0.9) 100%);
            transform: scale(1.03);
            box-shadow: 0 15px 40px rgba(16, 185, 129, 0.25);
        }

        .pricing-card:hover {
            transform: translateY(-6px);
            border-color: var(--sa-primary);
        }

        .pricing-card.popular:hover {
            transform: scale(1.03) translateY(-6px);
        }

        .popular-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 18px;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            letter-spacing: 0.5px;
        }

        .price-tag {
            font-size: 2.4rem;
            font-weight: 800;
            color: #ffffff;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 0.92rem;
            color: var(--sa-text-sub);
        }

        .feature-item i.check {
            color: var(--sa-primary);
            margin-top: 4px;
        }

        .feature-item i.cross {
            color: #ef4444;
            margin-top: 4px;
        }

        /* Step Card */
        .step-num-badge {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--sa-primary) 0%, var(--sa-secondary) 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.2rem;
            margin-bottom: 16px;
        }

        /* Accordion */
        .accordion-button {
            background-color: var(--sa-card-bg) !important;
            color: var(--sa-text-main) !important;
            border: 1px solid var(--sa-card-border);
            border-radius: 12px !important;
            padding: 18px 24px;
            font-weight: 600;
            box-shadow: none !important;
        }

        .accordion-button:not(.collapsed) {
            border-color: var(--sa-primary);
            color: var(--sa-primary) !important;
        }

        .accordion-body {
            background-color: rgba(18, 26, 43, 0.4);
            border: 1px solid var(--sa-card-border);
            border-top: none;
            border-radius: 0 0 12px 12px;
            color: var(--sa-text-sub);
            padding: 20px 24px;
            font-size: 0.95rem;
        }

        .accordion-item {
            background: transparent;
            border: none;
            margin-bottom: 14px;
        }

        /* Footer */
        footer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(6, 10, 18, 0.95);
        }

        /* Floating WhatsApp Button */
        .float-whatsapp {
            position: fixed;
            bottom: 25px;
            left: 25px;
            background: #25d366;
            color: white;
            border-radius: 50px;
            padding: 12px 22px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(37, 211, 102, 0.4);
            z-index: 1000;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
        }

        .float-whatsapp:hover {
            transform: scale(1.05);
            color: white;
            box-shadow: 0 12px 30px rgba(37, 211, 102, 0.6);
        }
    </style>
</head>
<body>

    <!-- 🌟 Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom fixed-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="#">
                <img src="logo_mindcrafts.svg" alt="Mindcrafts AI" style="width: 44px; height: 44px; border-radius: 12px; object-fit: contain; background: #061814; border: 1px solid rgba(16, 185, 129, 0.4); padding: 2px;">
                <div>
                    <span class="fw-bold fs-5 tracking-tight" style="color: #fff;"><?php echo ($lang=='ur') ? 'مدرسہ مینیجمنٹ <span style="color: var(--sa-primary);">سسٹم</span>' : 'Madrasa Management <span style="color: var(--sa-primary);">System</span>'; ?></span>
                    <small class="d-block text-muted" style="font-size: 11px; margin-top: -4px;"><?php echo ($lang=='ur') ? 'بائے مائنڈ کرافٹس اے آئی (Mindcrafts AI)' : 'By MindCrafts AI'; ?> — <span class="badge bg-success bg-opacity-25 text-success font-monospace" style="font-size: 10px;">v5.7.134 (SaaS Edition)</span></small>
                </div>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#home"><?php echo __('ہوم', 'Home'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#platforms"><?php echo __('پلیٹ فارمز', 'Platforms'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#ai-features"><i class="fas fa-sparkles text-warning me-1"></i> <?php echo __('AI فیچرز', 'AI Features'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#features"><?php echo __('خصوصیات', 'Features'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#how-it-works"><?php echo __('طریقہ کار', 'How It Works'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#pricing"><?php echo __('پیکجز و قیمتیں', 'Pricing'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#rules"><?php echo __('قواعد و ضوابط', 'Terms'); ?></a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#faq"><?php echo __('سوالات', 'FAQs'); ?></a></li>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <a href="?lang=<?php echo ($lang=='ur')?'en':'ur'; ?>" class="btn btn-sa-outline py-2 px-3 fs-6" title="<?php echo ($lang=='ur')?'Switch to English':'اردو میں دیکھیں'; ?>">
                        <i class="fas fa-globe"></i> <?php echo ($lang=='ur') ? 'English' : 'اردو'; ?>
                    </a>
                    <a href="login.php" class="btn btn-sa-outline py-2 px-3 fs-6">
                        <i class="fas fa-sign-in-alt"></i> <?php echo __('لاگ ان', 'Login'); ?>
                    </a>
                    <a href="register.php" class="btn btn-sa-primary py-2 px-3 fs-6">
                        <i class="fas fa-rocket"></i> <?php echo __('مفت رجسٹریشن', 'Free Register'); ?>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- 🌟 Hero Section -->
    <section id="home" class="py-5" style="margin-top: 90px;">
        <div class="container py-4 text-center">
            <div class="mb-3">
                <span class="badge-pill-glow">
                    <i class="fas fa-shield-halved"></i> <?php echo __('محفوظ، تیز رفتار، ملٹی ٹیننٹ کلاؤڈ سسٹم (SaaS)', 'Secure, High-Speed, Multi-Tenant Cloud SaaS System'); ?>
                </span>
            </div>

            <h1 class="hero-title <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-4" style="max-width: 950px; margin: 0 auto;">
                <?php echo __('مدارس، جامعات اور مساجد کا مکمل ڈیجیٹل انتظام — ایک ہی مرکزی پلیٹ فارم پر', 'Complete Digital Management for Islamic Schools, Seminaries & Masjids — On a Unified Cloud Platform'); ?>
            </h1>

            <p class="fs-5 mb-5 mx-auto <?php echo ($lang=='ur')?'urdu-font':''; ?>" style="max-width: 800px; color: var(--sa-text-sub);">
                <?php echo __('ویب ایڈمن پورٹل، اینڈرائڈ ایپ اور ونڈوز ڈیسک ٹاپ سافٹ ویئر کے ذریعے حفظ، ناظرہ، کتب، طلبہ و اساتذہ بائیو میٹرک حاضری، مکمل ڈبل انٹری فنانس اور فیسوں کا 100% محفوظ و خودکار نظام۔', 'Web Admin Portal, Android App, and Windows Desktop Software for Hifz, Nazra, Academics, Biometric Attendance, Double-Entry Accounting, and 100% Automated Fee Management.'); ?>
            </p>

            <div class="d-flex flex-wrap justify-content-center gap-3 mb-5">
                <a href="register.php" class="btn btn-sa-primary fs-5 px-4 py-3">
                    <i class="fas fa-sparkles"></i> <?php echo __('مفت رجسٹریشن شروع کریں (20 طلبہ فری)', 'Start Free Registration (20 Students Free)'); ?>
                </a>
                <a href="login.php" class="btn btn-sa-outline fs-5 px-4 py-3">
                    <i class="fas fa-building"></i> <?php echo __('ادارہ لاگ ان پورٹل', 'Institute Login Portal'); ?>
                </a>
                <a href="#download-apps" class="btn btn-sa-outline fs-5 px-4 py-3">
                    <i class="fas fa-download"></i> <?php echo __('ایپس ڈاؤن لوڈ کریں', 'Download Applications'); ?>
                </a>
            </div>

            <!-- Live Metrics Grid -->
            <div class="row g-3 justify-content-center mt-3">
                <div class="col-6 col-md-3">
                    <div class="stat-box glass-panel">
                        <div class="stat-number"><?php echo number_format($stat_tenants); ?>+</div>
                        <div class="text-muted small fw-semibold"><?php echo __('رجسٹرڈ ادارے و جامعات', 'Registered Institutes'); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box glass-panel">
                        <div class="stat-number"><?php echo number_format($stat_students); ?>+</div>
                        <div class="text-muted small fw-semibold"><?php echo __('زیرِ تعلیم فعال طلبہ', 'Active Students Enrolled'); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box glass-panel">
                        <div class="stat-number">100%</div>
                        <div class="text-muted small fw-semibold"><?php echo __('ڈیٹا پرائیویسی و آئسولیشن', 'Data Privacy & Isolation'); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-box glass-panel">
                        <div class="stat-number">99.9%</div>
                        <div class="text-muted small fw-semibold"><?php echo __('کلاؤڈ سرور اپ ٹائم', 'Cloud Server Uptime'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 Tri-Platform Ecosystem Showcase -->
    <section id="platforms" class="py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="badge-pill-glow mb-2"><i class="fas fa-cubes"></i> <?php echo __('3 پلیٹ فارمز ایک ساتھ', '3 Platforms Simultaneously'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1"><?php echo __('تینوں پلیٹ فارمز پر بیک وقت یکساں ہم آہنگی', 'Seamless Synchronization Across All 3 Platforms'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('چاہے آپ دفتر میں ہوں، کلاس روم میں یا سفر میں — آپ کا ادارہ ہمیشہ آپ کے ساتھ ہے', 'Whether in the office, classroom, or on the go — your institute stays synchronized'); ?></p>
            </div>

            <div class="row g-4">
                <!-- 1. Web Portal -->
                <div class="col-12 col-md-4">
                    <div class="glass-panel p-4 h-100">
                        <div class="platform-icon-circle" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                            <i class="fas fa-globe"></i>
                        </div>
                        <h4 class="fw-bold mb-3"><?php echo __('1. پی ایچ پی ویب ایڈمن پینل', '1. PHP Web Admin Panel'); ?></h4>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-4">
                            <?php echo __('ہر قسم کے براؤزر اور لیپ ٹاپ پر بغیر کسی انسٹالیشن کے فوری دستیاب۔ پرنسپل، مہتمم اور اکاونٹنٹ کے لیے وسیع ترین ڈیش بورڈ، رپورٹس، اور رسیدیں پرنٹنگ۔', 'Instantly accessible on all modern browsers and laptops without installation. Comprehensive dashboards, reporting, and receipt printing for principals and accountants.'); ?>
                        </p>
                        <ul class="list-unstyled small text-sub">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo __('مکمل اکاؤنٹنگ و واؤچرز', 'Complete Accounting & Vouchers'); ?></li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo __('ایکسل/CSV بلک ڈیٹا امپورٹ', 'Excel / CSV Bulk Data Import'); ?></li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo __('خوبصورت کشف الدرجات و اسناد', 'Exam Result Cards & Certificates'); ?></li>
                        </ul>
                    </div>
                </div>

                <!-- 2. Android Mobile App -->
                <div class="col-12 col-md-4">
                    <div class="glass-panel p-4 h-100">
                        <div class="platform-icon-circle" style="background: rgba(6, 182, 212, 0.15); color: #06b6d4;">
                            <i class="fas fa-mobile-screen"></i>
                        </div>
                        <h4 class="fw-bold mb-3"><?php echo __('2. اینڈرائڈ موبائل ایپلیکیشن', '2. Android Mobile App'); ?></h4>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-4">
                            <?php echo __('اساتذہ اور ناظمین کی جیب میں۔ کلاس میں کھڑے ہو کر روزانہ سبق، سبقی، منزل اور حاضری لگائیں۔ آف لائن موڈ اور پش نوٹیفیکیشنز کی مکمل سہولت۔', 'Right in teachers\' pockets. Record daily Sabaq, Sabqi, Manzil, and attendance on the spot. Full offline capability with push notifications.'); ?>
                        </p>
                        <ul class="list-unstyled small text-sub">
                            <li class="mb-2"><i class="fas fa-check text-info me-2"></i> <?php echo __('آف لائن فرسٹ (بغیر نیٹ بھی کام جاری)', 'Offline-First (Continues without Internet)'); ?></li>
                            <li class="mb-2"><i class="fas fa-check text-info me-2"></i> <?php echo __('روزانہ سبق و حفظ پروگریس ٹریکر', 'Daily Lesson & Hifz Progress Tracker'); ?></li>
                            <li class="mb-2"><i class="fas fa-check text-info me-2"></i> <?php echo __('فوری رخصت و شکایات نوٹیفیکیشن', 'Instant Leave & Complaint Alerts'); ?></li>
                        </ul>
                    </div>
                </div>

                <!-- 3. Windows Desktop App -->
                <div class="col-12 col-md-4">
                    <div class="glass-panel p-4 h-100">
                        <div class="platform-icon-circle" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                            <i class="fas fa-desktop"></i>
                        </div>
                        <h4 class="fw-bold mb-3"><?php echo __('3. ونڈوز ڈیسک ٹاپ سافٹ ویئر', '3. Windows Desktop Software'); ?></h4>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-4">
                            <?php echo __('کمپیوٹر پر مقامی FFI SQLite سپیڈ کے ساتھ تیز ترین ڈیٹا انٹری۔ انٹرنیٹ منقطع ہونے پر بھی فیس کٹنگ اور رسیدوں کی خودکار تھرمل و لیزر پرنٹنگ۔', 'Blazing-fast local FFI SQLite speed on desktop PCs. Seamless fee collection, thermal roll, and laser printer receipt generation offline.'); ?>
                        </p>
                        <ul class="list-unstyled small text-sub">
                            <li class="mb-2"><i class="fas fa-check text-warning me-2"></i> <?php echo __('انتہائی تیز رفتار کی بورڈ شارٹ کٹس', 'High-Speed Keyboard Shortcuts'); ?></li>
                            <li class="mb-2"><i class="fas fa-check text-warning me-2"></i> <?php echo __('تھرمل رول اور A4 رسید پرنٹنگ', 'Thermal Roll & A4 Receipt Printing'); ?></li>
                            <li class="mb-2"><i class="fas fa-check text-warning me-2"></i> <?php echo __('کلاؤڈ کے ساتھ بیک گراؤنڈ خودکار سنک', 'Background Cloud Auto-Sync'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 AI Powered Innovation Showcase -->
    <section id="ai-features" class="py-5" style="background: radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.08) 0%, transparent 70%);">
        <div class="container py-4">
            <div class="glass-panel p-4 p-md-5" style="border: 1px solid rgba(212, 172, 13, 0.4); box-shadow: 0 10px 40px rgba(0,0,0,0.4);">
                <div class="row align-items-center mb-4">
                    <div class="col-12 col-lg-8">
                        <span class="badge-pill-glow mb-2" style="background: rgba(212, 172, 13, 0.15); border-color: rgba(212, 172, 13, 0.4); color: #facc15;">
                            <i class="fas fa-wand-magic-sparkles"></i> <?php echo __('جدید مصنوعی ذہانت (AI) انضمام — MC-MMS 2026', 'Modern Artificial Intelligence (AI) Integration — MC-MMS 2026'); ?>
                        </span>
                        <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1 text-white mt-2">
                            <?php echo __('مصنوعی ذہانت (AI) سے لیس پاکستان کا پہلا جامع مدرسہ ERP', 'Pakistan\'s First Comprehensive Madrasa ERP Powered by Artificial Intelligence (AI)'); ?>
                        </h2>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-6 mb-0">
                            <?php echo __('تعلیمی رفتار کے سمارٹ تجزیے، آواز سے اردو ٹائپنگ اور خودکار واٹس ایپ الرٹس کے ساتھ اپنے ادارے کا 70% قیمتی وقت اور افرادی قوت بچائیں۔', 'Save 70% of staff time with smart academic progress analytics, voice typing in Urdu & English, and automated WhatsApp alerts.'); ?>
                        </p>
                    </div>
                    <div class="col-12 col-lg-4 text-lg-start mt-3 mt-lg-0">
                        <div class="d-inline-flex align-items-center gap-2 p-2 px-3 rounded-pill" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);">
                            <i class="fas fa-microchip text-warning fs-4"></i>
                            <div class="text-end">
                                <div class="text-white small fw-bold"><?php echo __('100% کلاؤڈ سمارٹ اے آئی', '100% Cloud Smart AI'); ?></div>
                                <div class="text-muted" style="font-size: 11px;"><?php echo __('خودکار ڈیٹا پروسیسنگ', 'Automated Processing'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-2">
                    <div class="col-12 col-md-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="rounded-circle p-2 text-center" style="width: 42px; height: 42px; background: rgba(13, 148, 136, 0.2); color: #2dd4bf;">
                                    <i class="fas fa-microphone-lines"></i>
                                </div>
                                <h6 class="fw-bold text-white mb-0 <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('AI وائس ٹائپنگ', 'AI Voice Typing'); ?></h6>
                            </div>
                            <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-0">
                                <?php echo __('بغیر کی بورڈ کے بول کر اسباق، حاضری، نوٹسز اور طلباء کے کوائف کا فوری اردو و انگریزی اندراج۔', 'Record lessons, attendance, and student notes effortlessly using speech-to-text voice input.'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="rounded-circle p-2 text-center" style="width: 42px; height: 42px; background: rgba(124, 58, 237, 0.2); color: #a78bfa;">
                                    <i class="fas fa-brain"></i>
                                </div>
                                <h6 class="fw-bold text-white mb-0 <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('AI تعلیمی پیشین گوئی', 'AI Academic Forecasting'); ?></h6>
                            </div>
                            <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-0">
                                <?php echo __('طلباء کے حفظ و ناظرہ کی رفتار کا سمارٹ تجزیہ، غلطیوں کی نشاندہی اور حفظ کی تکمیل کا تخمینہ۔', 'Smart analysis of Hifz velocity, weakness identification, and projected completion timelines.'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="rounded-circle p-2 text-center" style="width: 42px; height: 42px; background: rgba(37, 99, 235, 0.2); color: #60a5fa;">
                                    <i class="fas fa-bell"></i>
                                </div>
                                <h6 class="fw-bold text-white mb-0 <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('AI سمارٹ الرٹس', 'AI Smart Alerts'); ?></h6>
                            </div>
                            <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-0">
                                <?php echo __('غیر حاضری، فیس کے بقایا جات اور اسباق کی کمی پر والدین اور اساتذہ کو فوری خودکار واٹس ایپ پیغامات۔', 'Instant WhatsApp notifications for continuous absences, fee dues, and lesson deficiencies.'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="rounded-circle p-2 text-center" style="width: 42px; height: 42px; background: rgba(217, 119, 6, 0.2); color: #fbbf24;">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h6 class="fw-bold text-white mb-0 <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('AI مالیاتی اینالیٹکس', 'AI Financial Analytics'); ?></h6>
                            </div>
                            <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-0">
                                <?php echo __('اخراجات اور آمدن کا خودکار تقابلی جائزہ، مالیاتی خسارے کی پیشگی تنبیہ اور سمارٹ بجٹ پلاننگ۔', 'Automated revenue vs. expense forecasting, budget variance tracking, and deficit warnings.'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                            <p class="text-muted small urdu-font mb-0">
                                اخراجات اور آمدن کا خودکار تقابلی جائزہ، مالیاتی خسارے کی پیشگی تنبیہ اور سمارٹ بجٹ پلاننگ۔
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 Features Showcase Grid -->
    <section id="features" class="py-5 bg-opacity-25" style="background: rgba(18, 26, 43, 0.4);">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="badge-pill-glow mb-2"><i class="fas fa-layer-group"></i> <?php echo __('جامع ماڈیولز', 'Comprehensive Modules'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1"><?php echo __('ایک مدرسے کی تمام تر ضروریات کا احاطہ', 'Covering Every Need of an Islamic Institute'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('دینی اداروں کی خصوصی شرعی و انتظامی نزاکتوں کو مدنظر رکھ کر تیار کیا گیا نظام', 'Designed specifically keeping Islamic jurisprudence and administrative subtleties in mind'); ?></p>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-success fs-3 mb-3"><i class="fas fa-quran"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('شعبہ حفظ و ناظرہ', 'Hifz & Nazra Section'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('روزانہ سبق، سبقی، منزل، پارہ اور غلطیوں کی نشاندہی مع ماہانہ تعلیمی گراف۔', 'Daily Sabaq, Sabqi, Manzil tracking, error counts, and visual progress charts.'); ?></p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-info fs-3 mb-3"><i class="fas fa-clipboard-user"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('حاضری و بائیو میٹرک', 'Attendance & Biometrics'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('کیو آر کوڈ کارڈز، فنگر پرنٹ اور پیریڈ وائز حاضری مع غیر حاضری پر خودکار SMS۔', 'QR code student cards, fingerprint/period attendance, and automated absence alerts.'); ?></p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-warning fs-3 mb-3"><i class="fas fa-scale-balanced"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('ڈبل انٹری اکاؤنٹنگ', 'Double-Entry Accounting'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('مکمل چارٹ آف اکاؤنٹس، انکم و ایکسپینس واؤچرز، زرِ پیشگی (ایڈوانس) اور کیش بک۔', 'Standard Chart of Accounts, Income/Expense vouchers, staff advances, and cash book.'); ?></p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-danger fs-3 mb-3"><i class="fas fa-receipt"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('فیس و عطیات', 'Fee & Donations'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('ماہانہ فیس چالان، بقایا جات لسٹ، کمپیوٹرائزڈ رسیدیں اور جنسی عطیات (راشن) ریکارڈ۔', 'Monthly challans, dues ledger, thermal receipt printing, and in-kind donation logs.'); ?></p>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-primary fs-3 mb-3"><i class="fas fa-bed"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('دارالاقامہ و ہاسٹل', 'Boarding & Hostel'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('کمروں اور بیڈز کی الاٹمنٹ، ہاسٹل میس، رہائشی طلبہ حاضری اور نائٹ چیکنگ۔', 'Room & bed allocations, hostel mess management, resident attendance, and night checks.'); ?></p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-success fs-3 mb-3"><i class="fas fa-book-bookmark"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('کتب خانہ و لائبریری', 'Library Management'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('کتب کا بارکوڈ ریکارڈ، طلبہ و اساتذہ کو کتب کا اجراء، واپسی اور تاخیر پر جرمانہ۔', 'Barcode book cataloging, book issuance/returns, and late return fine management.'); ?></p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-info fs-3 mb-3"><i class="fas fa-money-check-dollar"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('اساتذہ تنخواہیں و وظائف', 'Staff Payroll & Stipends'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('ماہانہ پے رول، کٹوتی و الاؤنسز، عملہ قرضہ جات، اقساط اور خودکار سیلری سلپس۔', 'Monthly payroll, deductions, staff loans/installments, and automated salary payslips.'); ?></p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 h-100">
                        <div class="text-warning fs-3 mb-3"><i class="fas fa-mosque"></i></div>
                        <h5 class="fw-bold mb-2"><?php echo __('مدرسہ و مسجد موڈ', 'Madrasa & Masjid Dual Mode'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('ایک کلک پر مدرسہ سے جامع مسجد موڈ میں منتقلی، چندہ بکس اور مصلیان کا حساب کتاب۔', 'Switch between Madrasa and Masjid modes in one click with isolated ledgers and reports.'); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 How It Works (4 Steps) -->
    <section id="how-it-works" class="py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="badge-pill-glow mb-2"><i class="fas fa-shoe-prints"></i> <?php echo __('آسان ترین آغاز', 'Simple Onboarding'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1"><?php echo __('نظام کو کیسے استعمال کریں؟ صرف 4 مراحل', 'How It Works: Just 4 Simple Steps'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('کسی پیچیدہ تربیت کی ضرورت نہیں، پہلے ہی دن سے مکمل روانی کے ساتھ کام شروع کریں', 'No complex training required; get up and running smoothly on day one'); ?></p>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 text-center h-100">
                        <div class="step-num-badge mx-auto">1</div>
                        <h5 class="fw-bold mb-3"><?php echo __('آن لائن رجسٹریشن', 'Online Registration'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('رجسٹریشن فارم میں ادارے کا نام، شہر اور پاسورڈ درج کریں۔ سسٹم آپ کو فوری طور پر ایک منفرد <strong>ادارہ کوڈ (جیسے MAIN01)</strong> جاری کرے گا۔', 'Fill in your institute name, city, and password. You will instantly receive a unique <strong>Institute Code (e.g. M-26-001)</strong>.'); ?>
                        </p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 text-center h-100">
                        <div class="step-num-badge mx-auto">2</div>
                        <h5 class="fw-bold mb-3"><?php echo __('شعبہ جات و اساتذہ', 'Departments & Teachers'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('ویب ایڈمن پینل میں لاگ ان ہوں اور اپنے درجات (حفظ، ناظرہ، کتب) اور اساتذہ و عملہ کے نام مع اختیارات درج کریں۔', 'Sign in to the web panel, add your classes (Hifz, Nazra, Books), and register teachers with custom permissions.'); ?>
                        </p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 text-center h-100">
                        <div class="step-num-badge mx-auto">3</div>
                        <h5 class="fw-bold mb-3"><?php echo __('طلبہ اندراج و سبق', 'Student Enrollment'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('طلبہ کے کوائف درج کریں (یا ایکسل سے امپورٹ کریں)۔ اساتذہ کو موبائل ایپ دیں تاکہ وہ روزانہ حاضری اور سبق درج کر سکیں۔', 'Enroll students or import from Excel. Hand the mobile app to teachers for daily attendance and lesson logging.'); ?>
                        </p>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="glass-panel p-4 text-center h-100">
                        <div class="step-num-badge mx-auto">4</div>
                        <h5 class="fw-bold mb-3"><?php echo __('رپورٹس و فنانس', 'Reports & Finance'); ?></h5>
                        <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('ایک کلک سے ماہانہ فیس وصولی، اساتذہ تنخواہیں، طلبہ کے نتائج رجسٹر اور تعلیمی کارکردگی رپورٹس پرنٹ کریں۔', 'Collect fees with one click, disburse staff stipends, and generate performance report cards.'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 Pricing & Packages Section -->
    <section id="pricing" class="py-5" style="background: rgba(18, 26, 43, 0.6);">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="badge-pill-glow mb-2"><i class="fas fa-tags"></i> <?php echo __('شفاف و منصفانہ قیمتیں', 'Transparent & Fair Pricing'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1"><?php echo __('تمام سائز کے مدارس کے لیے موزوں سبسکرپشن پیکجز', 'Flexible Subscription Packages for All Institutes'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('کوئی پوشیدہ فیس نہیں، ادارے کے حجم کے مطابق مناسب ترین انتخاب کریں', 'Zero hidden fees; choose the best plan that fits your institute size'); ?></p>
            </div>

            <?php
            $row1Plans = array_slice($plans, 0, 3);
            $row2Plans = array_slice($plans, 3, 2);

            if (!function_exists('renderPricingPlanCard')) {
                function renderPricingPlanCard($p) {
                    global $lang;
                    $code = strtolower($p['code'] ?? '');
                    $isPopular = ($code === 'standard');
                    $isFree = (!empty($p['is_free_trial']) || $code === 'free');
                    $isEnterprise = ($code === 'enterprise');
                    
                    if ($code === 'basic') {
                        $priceMonthly = !empty($p['price_monthly']) ? (float)$p['price_monthly'] : (!empty($p['price']) ? (float)$p['price'] : 1000);
                        $priceYearly = !empty($p['price_yearly']) ? (float)$p['price_yearly'] : 10000;
                    } elseif ($code === 'standard') {
                        $priceMonthly = !empty($p['price_monthly']) ? (float)$p['price_monthly'] : (!empty($p['price']) ? (float)$p['price'] : 2000);
                        $priceYearly = !empty($p['price_yearly']) ? (float)$p['price_yearly'] : 20000;
                    } elseif (in_array($code, ['premium_200', 'advanced', 'premium'])) {
                        $priceMonthly = !empty($p['price_monthly']) ? (float)$p['price_monthly'] : (!empty($p['price']) ? (float)$p['price'] : 3000);
                        $priceYearly = !empty($p['price_yearly']) ? (float)$p['price_yearly'] : 30000;
                    } elseif ($isFree) {
                        $priceMonthly = 0;
                        $priceYearly = 0;
                    } else {
                        $priceMonthly = (float)($p['price_monthly'] ?? $p['price'] ?? 0);
                        $priceYearly = (float)($p['price_yearly'] ?? ($priceMonthly * 10));
                    }

                    $maxStd = (int)($p['max_students'] ?? 50);
                    $maxCls = (int)($p['max_classes'] ?? 5);
                    $maxTea = (int)($p['max_teachers'] ?? 10);
                    $hasMobile = !empty($p['has_mobile_app']);
                    $hasDesktop = !empty($p['has_desktop_app']);

                    $planTitles = [
                        'free' => ['ur' => 'مفت ٹرائل (Free Trial)', 'en' => 'Free Trial (30 Days)'],
                        'basic' => ['ur' => 'بنیادی پیکج (Basic)', 'en' => 'Basic Package'],
                        'standard' => ['ur' => 'معیاری پیکج (Standard)', 'en' => 'Standard Package'],
                        'premium_200' => ['ur' => 'ایڈوانس پیکج (Advanced)', 'en' => 'Advanced Package'],
                        'enterprise' => ['ur' => 'لامحدود انٹرپرائز (Unlimited)', 'en' => 'Unlimited Enterprise'],
                    ];
                    $planDescs = [
                        'free' => ['ur' => '20 طلبہ، 1 کلاس، 2 اساتذہ و عملہ — صرف ویب پینل رسائی', 'en' => '20 Students, 1 Class, 2 Staff — Web Panel Access Only'],
                        'basic' => ['ur' => '50 طلبہ، 3 کلاسز، 4 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + موبائل + ونڈوز)', 'en' => '50 Students, 3 Classes, 4 Staff — Tri-Platform (Web + Mobile + Windows)'],
                        'standard' => ['ur' => '100 طلبہ، 6 کلاسز، 8 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + موبائل + ونڈوز)', 'en' => '100 Students, 6 Classes, 8 Staff — Tri-Platform (Web + Mobile + Windows)'],
                        'premium_200' => ['ur' => '200 طلبہ، 12 کلاسز، 16 اساتذہ و عملہ — تینوں پلیٹ فارمز (ویب + موبائل + ونڈوز)', 'en' => '200 Students, 12 Classes, 16 Staff — Tri-Platform (Web + Mobile + Windows)'],
                        'enterprise' => ['ur' => 'لامحدود طلبہ، کلاسز، اساتذہ و عملہ — تمام ماڈیولز مع کسٹم ڈیڈیکیٹڈ سپورٹ', 'en' => 'Unlimited Students, Classes & Staff — Dedicated Custom Support'],
                    ];
                    $pName = $planTitles[$code][$lang] ?? $p['name'];
                    $pDesc = $planDescs[$code][$lang] ?? ($p['description'] ?? '');
                    ?>
                    <div class="col-12 col-md-6 col-lg-4 d-flex">
                        <div class="pricing-card <?php echo $isPopular ? 'popular' : ''; ?> w-100">
                            <?php if ($isPopular): ?>
                                <div class="popular-badge"><i class="fas fa-crown me-1"></i> <?php echo __('سب سے مقبول انتخاب', 'Most Popular'); ?></div>
                            <?php endif; ?>

                            <div>
                                <h4 class="fw-bold mb-2"><?php echo htmlspecialchars($pName); ?></h4>
                                <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-3"><?php echo htmlspecialchars($pDesc); ?></p>

                                <div class="mb-4">
                                    <?php if ($isEnterprise): ?>
                                        <div class="price-tag text-info fs-3"><?php echo __('قیمت رابطہ پر', 'Custom Quote'); ?></div>
                                        <small class="text-muted"><?php echo __('کسٹم ضروریات کے مطابق', 'Tailored to your scale'); ?></small>
                                        <div class="mt-2"><span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50 px-2 py-1"><i class="fas fa-headset me-1"></i> <?php echo __('کسٹم ڈیمو دستیاب', 'Live Demo Available'); ?></span></div>
                                    <?php elseif ($isFree): ?>
                                        <div class="price-tag text-success"><?php echo __('مفت', 'Free'); ?> <small class="fs-6 text-muted"><?php echo __('/ 30 دن', '/ 30 Days'); ?></small></div>
                                        <small class="text-muted"><?php echo __('آزمائشی بنیادوں پر', 'Trial Evaluation'); ?></small>
                                        <div class="mt-2"><span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-2 py-1"><i class="fas fa-bolt me-1"></i> <?php echo __('بغیر کریڈٹ کارڈ', 'No Credit Card Needed'); ?></span></div>
                                    <?php else: ?>
                                        <div class="price-tag">
                                            <small class="fs-6 fw-normal text-muted"><?php echo __('روپے', 'PKR'); ?></small> <?php echo number_format($priceMonthly); ?>
                                            <small class="fs-6 fw-normal text-muted"><?php echo __('/ ماہ', '/ mo'); ?></small>
                                        </div>
                                        <div class="small text-light mt-1"><i class="fas fa-tag text-warning me-1"></i> <?php echo __('سالانہ:', 'Yearly:'); ?> <strong class="text-warning"><?php echo __('روپے', 'PKR') . ' ' . number_format($priceYearly); ?></strong></div>
                                        <div class="mt-2"><span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-2 py-1"><i class="fas fa-gift me-1"></i> <?php echo __('30 دن کا مفت ٹرائل شامل ہے', '30-Day Free Trial Included'); ?></span></div>
                                    <?php endif; ?>
                                </div>

                                <hr style="border-color: rgba(255,255,255,0.08);">

                                <div class="features-list mb-4">
                                    <div class="feature-item">
                                        <i class="fas fa-check check"></i>
                                        <span><?php echo __('طلبہ کی حد:', 'Student Limit:'); ?> <strong><?php echo $maxStd > 9000 ? __('لامحدود', 'Unlimited') : ($maxStd . ' ' . __('طلبہ', 'Students')); ?></strong></span>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-check check"></i>
                                        <span><?php echo __('کلاسز کی حد:', 'Class Limit:'); ?> <strong><?php echo $maxCls > 9000 ? __('لامحدود', 'Unlimited') : ($maxCls . ' ' . __('کلاسز', 'Classes')); ?></strong></span>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-check check"></i>
                                        <span><?php echo __('اساتذہ و عملہ:', 'Teachers & Staff:'); ?> <strong><?php echo $maxTea > 9000 ? __('لامحدود', 'Unlimited') : ($maxTea . ' ' . __('اساتذہ', 'Staff')); ?></strong></span>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-check check"></i>
                                        <span><?php echo __('پی ایچ پی ویب پینل:', 'PHP Web Panel:'); ?> <strong><?php echo __('مکمل رسائی', 'Full Access'); ?></strong></span>
                                    </div>
                                    <div class="feature-item">
                                        <?php if ($hasMobile): ?>
                                            <i class="fas fa-check check"></i>
                                            <span><?php echo __('اینڈرائڈ ایپ:', 'Android App:'); ?> <strong><?php echo __('شامل ہے', 'Included'); ?></strong></span>
                                        <?php else: ?>
                                            <i class="fas fa-times cross"></i>
                                            <span class="text-muted text-decoration-line-through"><?php echo __('اینڈرائڈ ایپ (مفت میں بند)', 'Android App (Disabled in Free)'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="feature-item">
                                        <?php if ($hasDesktop): ?>
                                            <i class="fas fa-check check"></i>
                                            <span><?php echo __('ونڈوز ایپ:', 'Windows App:'); ?> <strong><?php echo __('شامل ہے', 'Included'); ?></strong></span>
                                        <?php else: ?>
                                            <i class="fas fa-times cross"></i>
                                            <span class="text-muted text-decoration-line-through"><?php echo __('ونڈوز ایپ (مفت میں بند)', 'Windows App (Disabled in Free)'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-check check"></i>
                                        <span><?php echo __('ڈیٹا سیکیورٹی:', 'Data Security:'); ?> <strong><?php echo __('100% انفرادی بیک اپ', '100% Isolated Backup'); ?></strong></span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <?php if ($isEnterprise): ?>
                                    <a href="https://wa.me/923012454595?text=<?php echo urlencode(__('السلام علیکم! ہم انٹرپرائز لامحدود پیکج کے بارے میں معلومات چاہتے ہیں۔', 'Hello! We would like more details about the Enterprise Unlimited Plan.')); ?>" target="_blank" class="btn btn-sa-outline w-100 fw-bold">
                                        <i class="fab fa-whatsapp me-1"></i> <?php echo __('رابطہ کریں', 'Contact Us'); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="register.php?plan=<?php echo urlencode($code); ?>" class="btn <?php echo $isPopular ? 'btn-sa-primary' : 'btn-sa-outline'; ?> w-100 fw-bold">
                                        <i class="fas fa-rocket me-1"></i> <?php echo __('30 روزہ ٹرائل شروع کریں', 'Start 30-Day Free Trial'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            }
            ?>

            <!-- پہلی قطار: 3 پیکجز (مفت آزمائش، بنیادی، معیاری) -->
            <div class="row g-4 justify-content-center mb-4">
                <?php foreach ($row1Plans as $p) { renderPricingPlanCard($p); } ?>
            </div>

            <!-- دوسری قطار: 2 پیکجز (ایڈوانس، انٹرپرائز — متوازن و مرکز میں) -->
            <div class="row g-4 justify-content-center">
                <?php foreach ($row2Plans as $p) { renderPricingPlanCard($p); } ?>
            </div>
        </div>
    </section>

    <!-- 🌟 Rules, Terms & Conditions, and Data Privacy -->
    <section id="rules" class="py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="badge-pill-glow mb-2"><i class="fas fa-scale-unbalanced-flip"></i> <?php echo __('قواعد و ضوابط و سیکیورٹی', 'Terms & Security'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1"><?php echo __('ڈیٹا پرائیویسی اور استعمال کے لازمی اصول', 'Data Privacy & Essential Terms of Use'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('ہماری اولین ترجیح دینی اداروں کے کوائف کی مکمل رازداری اور شرعی امانت داری ہے', 'Our foremost priority is complete confidentiality and trust for religious institutions'); ?></p>
            </div>

            <div class="row g-4">
                <div class="col-12 col-md-6">
                    <div class="glass-panel p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width: 44px; height: 44px; background: rgba(16, 185, 129, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 20px;">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <h5 class="fw-bold mb-0"><?php echo __('1. ڈیٹا کی مکمل ملکیت اور رازداری', '1. Complete Data Ownership & Privacy'); ?></h5>
                        </div>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('آپ کے ادارے کا تمام ڈیٹا صرف اور صرف آپ کی ملکیت ہے۔ سرور پر ہر ادارے کا ریکارڈ سخت <strong>Tenant Isolation</strong> اور انکرپٹڈ سیکیورٹی کے تحت رکھا جاتا ہے۔ کوئی بھی دوسرا مدرسہ یا غیر متعلقہ شخص آپ کے کوائف، مالیات یا طلبہ کے ریکارڈ کو نہیں دیکھ سکتا۔', 'All your institute data belongs solely to you. Stored under strict <strong>Tenant Isolation</strong> and end-to-end encryption. No third party or other institute can ever access your data.'); ?>
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="glass-panel p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width: 44px; height: 44px; background: rgba(6, 182, 212, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #06b6d4; font-size: 20px;">
                                <i class="fas fa-cloud-arrow-up"></i>
                            </div>
                            <h5 class="fw-bold mb-0"><?php echo __('2. روزانہ خودکار کلاؤڈ بیک اپ', '2. Daily Automated Cloud Backups'); ?></h5>
                        </div>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('سسٹم ڈیٹابیس کا روزانہ کی بنیاد پر خودکار انکرپٹڈ بیک اپ تیار ہوتا ہے۔ اگر آپ کا کمپیوٹر یا موبائل گم یا خراب ہو جائے تو نیا فون یا پی سی لے کر لاگ ان کریں، آپ کا سارا ڈیٹا ایک سیکنڈ میں بحال ہو جائے گا۔', 'Automated encrypted daily backups safeguard your records. Even if devices are lost or damaged, simply log in from any new device to restore immediately.'); ?>
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="glass-panel p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width: 44px; height: 44px; background: rgba(245, 158, 11, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #f59e0b; font-size: 20px;">
                                <i class="fas fa-hand-holding-hand"></i>
                            </div>
                            <h5 class="fw-bold mb-0"><?php echo __('3. شرعی و اخلاقی ضوابط کی پاسداری', '3. Strict Shariah & Ethical Compliance'); ?></h5>
                        </div>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('نظام خالص دینی مقاصد کے لیے بنایا گیا ہے۔ اس میں کوئی تجارتی اشتہارات یا غیر اخلاقی الگورتھم شامل نہیں۔ نظام میں موجود چندہ، فیس، زکوٰۃ اور صدقات کے کھاتے شرعی اصولوں کے عین مطابق الگ الگ رکھے جاتے ہیں۔', 'Engineered purely for Islamic education and masjids without intrusive commercial ads. Sadaqat, Zakat, and donation ledgers are kept strictly isolated per Shariah rules.'); ?>
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="glass-panel p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width: 44px; height: 44px; background: rgba(239, 68, 68, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #ef4444; font-size: 20px;">
                                <i class="fas fa-ban"></i>
                            </div>
                            <h5 class="fw-bold mb-0"><?php echo __('4. فری پلان اور پلیٹ فارم رسائی کی حدود', '4. Free Plan & Platform Access Limits'); ?></h5>
                        </div>
                        <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('مفت ٹرائل پلان صرف اور صرف ویب پینل کے ذریعے آزمائش کے لیے ہے۔ فری ٹرائل صارفین کو اینڈرائڈ اور ونڈوز ایپ کی اجازت نہیں ہوگی۔ موبائل ایپ اور ونڈوز سافٹ ویئر کی مکمل صلاحیت حاصل کرنے کے لیے بیسک یا سٹینڈرڈ پلان منتخب کرنا لازمی ہے۔', 'The free evaluation tier is available via the Web Panel. Mobile and Windows native apps are bundled with paid subscription tiers (Basic, Standard, Advanced).'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 Frequently Asked Questions (FAQ) -->
    <section id="faq" class="py-5" style="background: rgba(18, 26, 43, 0.4);">
        <div class="container py-4" style="max-width: 900px;">
            <div class="text-center mb-5">
                <span class="badge-pill-glow mb-2"><i class="fas fa-circle-question"></i> <?php echo __('سوالات و جوابات', 'FAQs'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1"><?php echo __('اکثر پوچھے گئے ضروری سوالات', 'Frequently Asked Questions'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?>"><?php echo __('اگر آپ کا سوال یہاں موجود نہ ہو تو واٹس ایپ پر ہماری ٹیم سے فوری رابطہ کر سکتے ہیں', 'Have additional questions? Contact our dedicated support team via WhatsApp'); ?></p>
            </div>

            <div class="accordion" id="faqAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?php echo ($lang=='ur')?'urdu-font':''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            <?php echo __('کیا ہمارا پرانا ڈیٹا ایکسل یا رجسٹر سے اس سافٹ ویئر میں منتقل ہو سکتا ہے؟', 'Can we migrate existing student records from Excel or paper registers?'); ?>
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                        <div class="accordion-body <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('جی ہاں! سسٹم کے ویب ایڈمن پینل میں <strong>Excel اور CSV امپورٹ</strong> کی زبردست سہولت موجود ہے۔ آپ چند منٹوں میں سینکڑوں طلبہ کا ڈیٹا داخل کر سکتے ہیں، یا ہماری ٹیکنیکل سپورٹ ٹیم سے مفت مدد لے سکتے ہیں۔', 'Yes! The web admin panel includes full <strong>Excel and CSV import</strong> tools. You can import hundreds of student records in minutes, or contact our support team for free assistance.'); ?>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed <?php echo ($lang=='ur')?'urdu-font':''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            <?php echo __('اگر مدرسے میں انٹرنیٹ بند ہو جائے تو کیا موبائل یا ونڈوز ایپ چلے گی؟', 'Will mobile and desktop apps work if the internet goes down?'); ?>
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('بالکل! ہماری اینڈرائڈ ایپ اور ونڈوز سافٹ ویئر <strong>Offline-First</strong> بنیادوں پر تیار کیے گئے ہیں۔ آپ انٹرنیٹ کے بغیر بھی معمول کے مطابق سبق، حاضری اور فیس کی انٹری کر سکتے ہیں۔ جیسے ہی انٹرنیٹ منسلک ہوگا، تمام ریکارڈ خودکار طور پر کلاؤڈ سے سنک ہو جائے گا۔', 'Absolutely! Both Android and Windows apps are built on an <strong>Offline-First</strong> architecture. You can take attendance, log lessons, and record fees without an active connection; everything syncs automatically upon reconnection.'); ?>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed <?php echo ($lang=='ur')?'urdu-font':''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            <?php echo __('مفت ٹرائل ختم ہونے کے بعد ہمارے طلبہ کے ریکارڈ کا کیا ہوگا؟', 'What happens to our data after the 30-day free trial?'); ?>
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('آپ کا تمام ریکارڈ کلاؤڈ سرور پر 100% محفوظ رہے گا اور کوئی چیز ضائع نہیں ہوگی۔ آپ جب بھی مناسب سبسکرپشن پلان کا انتخاب کریں گے، وہیں سے آگے بغیر کسی پریشانی کے جاری رکھ سکیں گے۔', 'All your data remains 100% safe and secure in the cloud. You can activate any subscription package whenever you wish and resume immediately without data loss.'); ?>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed <?php echo ($lang=='ur')?'urdu-font':''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            <?php echo __('کیا ہم ایک ہی سسٹم میں جامع مسجد اور مکتب کا انتظام بھی چلا سکتے ہیں؟', 'Can we manage both a Madrasa and a Central Masjid on the same account?'); ?>
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body <?php echo ($lang=='ur')?'urdu-font':''; ?>">
                            <?php echo __('جی ہاں! سسٹم میں <strong>مدرسہ و مسجد ڈوئل موڈ</strong> کا مکمل فیچر شامل ہے۔ اوپر دیے گئے بٹن سے آپ ایک سیکنڈ میں مدرسہ سے جامع مسجد کے کھاتوں، مصلیان اور مکتب اکیڈمکس میں سوئچ کر سکتے ہیں۔', 'Yes! The system includes full <strong>Madrasa & Masjid Dual-Mode</strong> support, allowing instant switching between madrasa academics and masjid ledgers.'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 Download Apps Section -->
    <section id="download-apps" class="py-5 text-center">
        <div class="container py-4">
            <div class="glass-panel p-5 mx-auto" style="max-width: 900px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(6, 182, 212, 0.1) 100%);">
                <span class="badge-pill-glow mb-3"><i class="fas fa-download"></i> <?php echo __('باضابطہ ایپلیکیشنز', 'Official Applications'); ?></span>
                <h2 class="fw-bold <?php echo ($lang=='ur')?'urdu-font':''; ?> fs-1 mb-3"><?php echo __('موبائل اور ونڈوز ڈیسک ٹاپ سافٹ ویئر ڈاؤن لوڈ کریں', 'Download Mobile & Windows Desktop Apps'); ?></h2>
                <p class="text-muted <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-4">
                    <?php echo __('اپنے اساتذہ کو موبائل ایپ دیں اور اپنے کمپیوٹر پر تیز ترین ونڈوز ورژن انسٹال کریں', 'Equip teachers with the mobile app and install high-speed native software on office PCs'); ?>
                </p>

                <div class="d-flex flex-wrap justify-content-center gap-3">
                    <a href="https://madrasa.mamfss.com/downloads/MAMFSS_App.apk" class="btn btn-sa-primary py-3 px-4 fs-5">
                        <i class="fab fa-android fs-4"></i> <?php echo __('اینڈرائڈ ایپ ڈاؤن لوڈ (APK)', 'Download Android App (APK)'); ?>
                    </a>
                    <a href="https://madrasa.mamfss.com/downloads/MAMFSS_Setup.exe" class="btn btn-sa-outline py-3 px-4 fs-5">
                        <i class="fab fa-windows fs-4"></i> <?php echo __('ونڈوز انسٹالر ڈاؤن لوڈ (EXE)', 'Download Windows Setup (EXE)'); ?>
                    </a>
                    <a href="login.php" class="btn btn-sa-outline py-3 px-4 fs-5">
                        <i class="fas fa-desktop fs-4"></i> <?php echo __('ویب ایڈمن میں داخل ہوں', 'Open Web Admin Panel'); ?>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 Footer -->
    <footer class="py-5 text-sub">
        <div class="container">
            <div class="row g-4 mb-4">
                <div class="col-12 col-md-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <img src="logo_mindcrafts.svg" alt="Mindcrafts AI" style="width: 42px; height: 42px; border-radius: 12px; object-fit: contain; background: #061814; border: 1px solid rgba(16, 185, 129, 0.4); padding: 2px;">
                        <div>
                            <span class="fw-bold fs-5 text-white"><?php echo ($lang=='ur') ? 'مدرسہ مینیجمنٹ <span style="color: var(--sa-primary);">سسٹم</span>' : 'Madrasa Management <span style="color: var(--sa-primary);">System</span>'; ?></span>
                            <small class="d-block text-muted" style="font-size: 11px;"><?php echo ($lang=='ur') ? 'بائے مائنڈ کرافٹس اے آئی (Mindcrafts AI)' : 'By MindCrafts AI'; ?></small>
                        </div>
                    </div>
                    <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-3">
                        <?php echo __('پاکستان کا سب سے جدید اور قابلِ اعتماد کلاؤڈ مدرسہ و مسجد ERP مینیجمنٹ پلیٹ فارم۔ دینی اداروں کو جدید ترین ٹیکنالوجی سے لیس کر کے شفافیت اور روانی لانا ہمارا مشن ہے۔', 'Pakistan\'s premier and most reliable cloud madrasa & masjid ERP platform, modernizing educational institutes with transparency and ease.'); ?>
                    </p>
                    <div class="text-muted small">
                        <i class="fas fa-location-dot me-1 text-primary"></i> <?php echo __('سرگودھا، پنجاب، پاکستان', 'Sargodha, Punjab, Pakistan'); ?>
                    </div>
                </div>

                <div class="col-6 col-md-2">
                    <h6 class="fw-bold text-white mb-3"><?php echo __('اہم لنکس', 'Quick Links'); ?></h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="#home" class="text-muted text-decoration-none"><?php echo __('ہوم پیج', 'Home Page'); ?></a></li>
                        <li class="mb-2"><a href="#features" class="text-muted text-decoration-none"><?php echo __('خصوصیات', 'Features'); ?></a></li>
                        <li class="mb-2"><a href="#pricing" class="text-muted text-decoration-none"><?php echo __('پیکجز و قیمتیں', 'Pricing'); ?></a></li>
                        <li class="mb-2"><a href="#rules" class="text-muted text-decoration-none"><?php echo __('قواعد و ضوابط', 'Terms & Rules'); ?></a></li>
                        <li class="mb-2"><a href="#download-apps" class="text-muted text-decoration-none"><?php echo __('ایپس ڈاؤن لوڈ', 'Download Apps'); ?></a></li>
                    </ul>
                </div>

                <div class="col-6 col-md-3">
                    <h6 class="fw-bold text-white mb-3"><?php echo __('پورٹلز و لاگ ان', 'Portals & Login'); ?></h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="login.php" class="text-muted text-decoration-none"><i class="fas fa-key me-1 text-primary"></i> <?php echo __('ادارہ لاگ ان پورٹل', 'Institute Login'); ?></a></li>
                        <li class="mb-2"><a href="register.php" class="text-muted text-decoration-none"><i class="fas fa-user-plus me-1 text-primary"></i> <?php echo __('نیا ادارہ مفت رجسٹریشن', 'New Institute Register'); ?></a></li>
                        <li class="mb-2"><a href="super_admin/login.php" class="text-muted text-decoration-none"><i class="fas fa-shield-halved me-1 text-warning"></i> <?php echo __('سپر ایڈمن ماسٹر پورٹل', 'Super Admin Master'); ?></a></li>
                    </ul>
                </div>

                <div class="col-12 col-md-3">
                    <h6 class="fw-bold text-white mb-3"><?php echo __('براہِ راست سپورٹ و رابطہ', 'Support & Contact'); ?></h6>
                    <p class="text-muted small <?php echo ($lang=='ur')?'urdu-font':''; ?> mb-2"><?php echo __('ہیلپ ڈیسک اور سسٹم ڈیمو کے لیے ہم سے رابطہ کریں:', 'Reach out for helpdesk assistance or live software demo:'); ?></p>
                    <div class="text-muted small mb-2"><i class="fas fa-phone me-1 text-success"></i> 0301-2454595</div>
                    <div class="text-muted small mb-3"><i class="fas fa-envelope me-1 text-info"></i> info@mamfss.com</div>
                    <a href="https://wa.me/923012454595" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1">
                        <i class="fab fa-whatsapp me-1"></i> <?php echo __('واٹس ایپ سپورٹ', 'WhatsApp Support'); ?>
                    </a>
                </div>
            </div>

            <div class="border-top pt-4 d-flex flex-wrap justify-content-between align-items-center text-muted small" style="border-color: rgba(255,255,255,0.06) !important;">
                <div>
                    &copy; <?php echo date('Y'); ?> MAMFSS Cloud Madrasa Management System. <?php echo __('تمام جملہ حقوق محفوظ ہیں۔', 'All rights reserved.'); ?>
                </div>
                <div class="d-flex gap-3 align-items-center">
                    <span class="badge bg-secondary bg-opacity-25 text-white border border-secondary font-monospace" style="font-size: 11px;">v5.7.134 (SaaS Edition)</span>
                    <span>|</span>
                    <a href="#rules" class="text-muted text-decoration-none"><?php echo __('پرائیویسی پالیسی', 'Privacy Policy'); ?></a>
                </div>
            </div>
        </div>
    </footer>

    <!-- 🌟 Floating WhatsApp Support Button -->
    <a href="https://wa.me/923012454595?text=<?php echo urlencode(__('السلام علیکم! مجھے MAMFSS کلاؤڈ مدرسہ سسٹم کے متعلق معلومات چاہیے ہیں۔', 'Hello! I need information regarding MAMFSS Cloud Madrasa Management System.')); ?>" class="float-whatsapp" target="_blank">
        <i class="fab fa-whatsapp fs-4"></i>
        <span><?php echo __('واٹس ایپ رابطہ', 'WhatsApp Chat'); ?></span>
    </a>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
