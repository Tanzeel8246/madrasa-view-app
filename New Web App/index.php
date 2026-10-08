<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

$m_name = 'مدرسہ اشرف المدارس'; 
$phone = '0301-2454595';
$address = "321 Addheal Rossn, Sargodha";

// Get live data - Updated: 2026-04-30 (v2)
try {
    // 1. Total Active Students
    $total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE (status = 'Active' OR status = '' OR status IS NULL) AND is_deleted = 0")->fetchColumn() ?: 0;
    
    // 2. Total Active Teachers
    $total_teachers = $pdo->query("SELECT COUNT(*) FROM teachers WHERE (status = 'Active' OR status = '' OR status IS NULL) AND is_deleted = 0")->fetchColumn() ?: 0;
    
    // 3. Huffaz (Alumni/Graduates)
    $huffaz = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Alumni' AND is_deleted = 0")->fetchColumn() ?: 0;
    
    // 4. Active Classes
    $active_classes = $pdo->query("SELECT COUNT(*) FROM classes WHERE status = 'Active' OR status = '' OR status IS NULL")->fetchColumn() ?: 0;

    // 5. Current Month Expenses (Real Data)
    $current_month = date('m');
    $current_year = date('Y');
    $monthly_expenses = $pdo->query("SELECT SUM(amount) FROM finance WHERE type = 'Expense' AND is_deleted = 0 AND MONTH(date) = '$current_month' AND YEAR(date) = '$current_year'")->fetchColumn() ?: 0;

} catch (Exception $e) {
    $total_students = 0; $total_teachers = 0; $huffaz = 0; $active_classes = 0; $monthly_expenses = 0;
}

// Get notices for Marquee (latest 1) and Notice Board (latest 5)
$notices = $pdo->query("SELECT * FROM notices ORDER BY date DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ur" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="مدرسہ اشرف المدارس - <?php echo t('hero_desc'); ?>۔ داخلہ جاری ہے۔">
    <meta name="keywords" content="مدرسہ، اشرف المدارس، حفظ قرآن، تجوید، حسن صوت، دینی تعلیم، سرگودھا، مدرسہ مینجمنٹ سسٹم">
    <title><?php echo $m_name; ?> - <?php echo t('hero_subtitle'); ?> | Home</title>
    
    <script>
        // Immediate theme load logic to prevent FOUC
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'default';
            if (savedTheme !== 'default') {
                document.documentElement.className = 'theme-' + savedTheme;
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://unpkg.com">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=Outfit:wght@300;400;600;700&display=swap&font-display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --emerald-royal: var(--emerald-royal);
            --emerald-mid: var(--emerald-mid);
            --gold-burnished: var(--gold-burnished);
            --gold-light: var(--gold-light);
            --cream-bg: var(--cream-bg);
            --text-dark: var(--text-dark);
            --glass-white: rgba(255, 255, 255, 0.45);
            --glass-border: rgba(255, 255, 255, 0.25);
        }

        html.theme-dark-emerald {
            --emerald-royal: #0c0f16;
            --emerald-mid: #111622;
            --gold-burnished: #00e676;
            --gold-light: #ffd700;
            --cream-bg: #0c0f16;
            --text-dark: #f8fafc;
            --glass-white: rgba(23, 29, 44, 0.75);
            --glass-border: rgba(255, 255, 255, 0.05);
        }

        html.theme-classic-navy {
            --emerald-royal: #0b2545;
            --emerald-mid: #1e3a8a;
            --gold-burnished: #3b82f6;
            --gold-light: #93c5fd;
            --cream-bg: #f4f6f9;
            --text-dark: #1e293b;
            --glass-white: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(11, 37, 69, 0.1);
        }

        html.theme-warm-terracotta {
            --emerald-royal: #2d3d41;
            --emerald-mid: #34495e;
            --gold-burnished: #c87a53;
            --gold-light: #f5e9d9;
            --cream-bg: #f5f4eb;
            --text-dark: #2c3e50;
            --glass-white: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(200, 122, 83, 0.15);
        }

        html.theme-mint-glass {
            --emerald-royal: #004d40;
            --emerald-mid: #00796b;
            --gold-burnished: #00bfa5;
            --gold-light: #e0f2f1;
            --cream-bg: #e0f2f1;
            --text-dark: #004d40;
            --glass-white: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(0, 191, 165, 0.2);
        }

        html.theme-sage-sand {
            --emerald-royal: #3c4e4b;
            --emerald-mid: #2f3a38;
            --gold-burnished: #7b9e87;
            --gold-light: #faf8f0;
            --cream-bg: #faf8f0;
            --text-dark: #2f3a38;
            --glass-white: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(123, 158, 135, 0.2);
        }
        
        body { 
            font-family: 'Outfit', 'Noto Nastaliq Urdu', serif;
            background-color: var(--cream-bg); 
            color: var(--text-dark);
            overflow-x: hidden;
            scroll-behavior: smooth;
        }

        /* Premium UI Components */
        .glass-card {
            background: var(--glass-white);
            backdrop-filter: blur(15px) saturate(180%);
            -webkit-backdrop-filter: blur(15px) saturate(180%);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            box-shadow: 0 10px 40px -10px rgba(10, 46, 36, 0.08);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 60px -15px rgba(10, 46, 36, 0.15);
            border-color: var(--gold-burnished);
        }

        .btn-royal {
            background: linear-gradient(135deg, var(--gold-burnished) 0%, var(--gold-light) 100%);
            color: var(--emerald-royal) !important;
            border: none;
            padding: 14px 40px;
            border-radius: 100px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            transition: all 0.4s ease;
            box-shadow: 0 6px 20px rgba(197, 160, 89, 0.3);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-royal:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 30px rgba(197, 160, 89, 0.5);
        }

        /* Hero Full-Screen Overhaul */
        .hero-section {
            background: linear-gradient(rgba(10, 46, 36, 0.7), rgba(10, 46, 36, 0.9)), url('uploads/hero_mosque.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            position: relative;
            margin: 0;
            border-radius: 0;
        }
        .hero-overlay-pattern {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image: url('uploads/islamic_pattern.png');
            background-size: 400px;
            opacity: 0.04;
            pointer-events: none;
        }
        .hero-content h1 {
            font-size: 5.5rem;
            font-weight: 800;
            background: linear-gradient(to bottom, #fff 40%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 2rem;
            line-height: 1.1;
        }

        .section-title {
            color: var(--emerald-royal);
            font-weight: 800;
            text-align: center;
            margin-bottom: 60px;
            text-transform: uppercase;
            letter-spacing: 2px;
            position: relative;
        }
        .section-title::after {
            content: '';
            display: block;
            width: 100px;
            height: 4px;
            background: var(--gold-burnished);
            margin: 20px auto 0;
            border-radius: 10px;
        }

        /* Stats & Department Polish */
        .stat-icon { font-size: 3rem; color: var(--gold-burnished); margin-bottom: 20px; text-shadow: 0 5px 15px rgba(197, 160, 89, 0.2); }
        .dept-card { position: relative; border-radius: 30px; overflow: hidden; height: 350px; border: none; }
        .dept-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.8s ease; }
        .dept-card:hover .dept-img { transform: scale(1.15); }
        .dept-content {
            position: absolute;
            bottom: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(to top, rgba(10, 46, 36, 0.95) 10%, transparent 60%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 40px;
            color: white;
        }

        /* Navbar Sophistication */
        .navbar { 
            background: rgba(10, 46, 36, 0.85) !important; 
            backdrop-filter: blur(20px);
            padding: 20px 0; 
            border-bottom: 1px solid rgba(255,255,255,0.08);
            transition: all 0.3s ease;
        }
        .navbar.scrolled { padding: 10px 0; background: rgba(10, 46, 36, 0.98) !important; }
        
        /* Interactive Animations */
        @keyframes ripple {
            0% { box-shadow: 0 0 0 0 rgba(197, 160, 89, 0.4); }
            100% { box-shadow: 0 0 0 20px rgba(197, 160, 89, 0); }
        }
        .animate-border { animation: ripple 2s infinite; }
        
        .hover-lift { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .hover-lift:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important; }

        /* Audio Player Refined */
        .audio-track {
            padding: 24px;
            border-radius: 20px;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .play-btn-royal {
            width: 56px; height: 56px; border-radius: 18px;
            background: var(--emerald-royal);
            color: var(--gold-burnished);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; cursor: pointer; transition: 0.3s;
        }
        .play-btn-royal:hover { transform: rotate(10deg); filter: brightness(1.2); }

        .footer-luxury {
            background: linear-gradient(to bottom, var(--emerald-royal), #051612);
            color: rgba(255,255,255,0.6);
            padding: 120px 0 60px;
            position: relative;
            z-index: 1;
        }
        .footer-logo { font-size: 2rem; color: #fff; font-weight: 800; margin-bottom: 2rem; }
        .footer-link { color: rgba(255,255,255,0.6); text-decoration: none; transition: 0.3s; }
        .footer-link:hover { color: var(--gold-burnished); padding-right: 10px; }
        
        @media (max-width: 768px) {
            .hero-content h1 { font-size: 2.2rem; }
            .hero-content p.fs-3 { font-size: 1.1rem !important; }
            .dept-card { height: 250px; }
            .dept-content { padding: 20px; }
            .audio-track { flex-direction: row; gap: 10px; padding: 15px; }
            .footer-luxury { padding: 60px 0 30px; }
            .section-title { font-size: 1.3rem; letter-spacing: 1px; margin-bottom: 30px; }
            .glass-card.p-5 { padding: 1.5rem !important; }
            .hero-section { background-attachment: scroll; min-height: 85vh; }
        }
        @media (max-width: 480px) {
            .hero-content h1 { font-size: 1.7rem; line-height: 1.3; }
            .hero-content p.fs-3 { font-size: 1rem !important; }
            .stat-icon { font-size: 1.5rem !important; }
            .btn-royal { padding: 10px 24px; font-size: 0.9rem; }
            .hero-section { min-height: 80vh; }
        }
        /* Fix overflow on all screen sizes */
        html, body { overflow-x: hidden; max-width: 100%; }
        .container, .container-fluid { overflow-x: hidden; }
        .row { margin-left: 0; margin-right: 0; }
    </style>
</head>
<body>

    <!-- Header (Navbar) -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="#">
                <img src="logo.png" alt="Logo" style="width: 40px; height: 40px; border-radius: 50%; margin-left: 10px; background: white; border: 2px solid var(--gold-burnished); object-fit: contain;">
                <?php echo $m_name; ?>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link px-3" href="#"><?php echo t('nav_home'); ?></a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#about"><?php echo t('nav_about'); ?></a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#depts"><?php echo t('nav_depts'); ?></a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#contact"><?php echo t('nav_contact'); ?></a></li>
                </ul>
                <div class="ms-lg-4">
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <a href="dashboard.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm"><?php echo t('nav_dashboard'); ?></a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline-light rounded-pill px-4 fw-bold"><?php echo t('nav_login'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Section 1: Hero -->
    <div class="hero-section"> 
        <div class="hero-overlay-pattern"></div>
        <div class="hero-content container text-center" data-aos="zoom-out" data-aos-duration="2000">
            <p class="mb-4 text-warning fw-bold fs-5" style="letter-spacing: 4px; text-transform: uppercase;" data-aos="fade-down" data-aos-delay="500"><?php echo t('hero_subtitle'); ?></p>
            <div class="mb-4" data-aos="zoom-in" data-aos-delay="600">
                <img src="logo.png" alt="Logo" style="width: 150px; height: 150px; border-radius: 50%; border: 4px solid var(--gold-burnished); background: white; padding: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); object-fit: contain;">
            </div>
            <h1 class="display-1 fw-bold mb-4" data-aos="fade-up" data-aos-delay="800"><?php echo t('madrasa_ashraf'); ?></h1>
            <p class="mb-5 fs-3 opacity-90 mx-auto col-md-8" data-aos="fade-up" data-aos-delay="1000"><?php echo t('hero_desc'); ?></p>
            <div class="d-flex flex-wrap gap-4 justify-content-center" data-aos="fade-up" data-aos-delay="1200">
                <a href="#admission" class="btn-royal fs-5"><i class="fas fa-graduation-cap"></i> <?php echo t('hero_btn_admission'); ?></a>
                <a href="#about" class="btn btn-outline-light rounded-pill px-5 py-3 fs-5 border-2 fw-bold"><?php echo t('hero_btn_movement'); ?></a>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="position-absolute bottom-0 start-50 translate-middle-x mb-4 text-white opacity-50" data-aos="fade-up" data-aos-anchor-placement="top-bottom">
            <p class="small mb-1">Scroll Down</p>
            <div class="mx-auto" style="width: 2px; height: 30px; background: white;"></div>
        </div>
    </div>

    <div class="container py-5">
        <!-- Section 2: Marquee -->
        <?php if(count($notices) > 0): ?>
        <div class="glass-card p-2 mb-5 overflow-hidden" data-aos="fade-in">
            <div class="d-flex align-items-center">
                <div class="bg-danger text-white px-3 py-1 rounded-pill me-3 small fw-bold"><?php echo t('marquee_latest'); ?></div>
                <marquee onmouseover="this.stop()" onmouseout="this.start()" class="fw-bold text-danger">
                    <?php echo $notices[0]['title'] . ": " . $notices[0]['message']; ?>
                </marquee>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 3: Stats -->
        <div class="row g-3 g-md-4 mb-5 text-center justify-content-center">
            <div class="col-6 col-lg-2" data-aos="zoom-in" data-aos-delay="100">
                <div class="glass-card stat-box h-100 p-3">
                    <i class="fas fa-user-graduate stat-icon" style="font-size: 2rem;"></i>
                    <h3 class="fw-bold mb-0"><?php echo $total_students; ?></h3>
                    <p class="text-muted small mb-0"><?php echo t('stat_students'); ?></p>
                </div>
            </div>
            <div class="col-6 col-lg-2" data-aos="zoom-in" data-aos-delay="200">
                <div class="glass-card stat-box h-100 p-3">
                    <i class="fas fa-chalkboard-teacher stat-icon" style="font-size: 2rem;"></i>
                    <h3 class="fw-bold mb-0"><?php echo $total_teachers; ?></h3>
                    <p class="text-muted small mb-0"><?php echo t('stat_teachers'); ?></p>
                </div>
            </div>
            <div class="col-6 col-lg-2" data-aos="zoom-in" data-aos-delay="300">
                <div class="glass-card stat-box h-100 p-3">
                    <i class="fas fa-quran stat-icon" style="font-size: 2rem;"></i>
                    <h3 class="fw-bold mb-0"><?php echo $huffaz; ?></h3>
                    <p class="text-muted small mb-0"><?php echo t('stat_huffaz'); ?></p>
                </div>
            </div>
            <div class="col-6 col-lg-2" data-aos="zoom-in" data-aos-delay="400">
                <div class="glass-card stat-box h-100 p-3">
                    <i class="fas fa-chalkboard stat-icon" style="font-size: 2rem;"></i>
                    <h3 class="fw-bold mb-0"><?php echo $active_classes; ?></h3>
                    <p class="text-muted small mb-0"><?php echo t('stat_classes'); ?></p>
                </div>
            </div>
            <div class="col-6 col-lg-2" data-aos="zoom-in" data-aos-delay="500">
                <div class="glass-card stat-box h-100 p-3 border-danger">
                    <i class="fas fa-hand-holding-usd stat-icon text-danger" style="font-size: 2rem;"></i>
                    <h3 class="fw-bold mb-0 text-danger"><?php echo number_format($monthly_expenses); ?></h3>
                    <p class="text-muted small mb-0"><?php echo t('stat_expenses'); ?></p>
                </div>
            </div>
        </div>

        <div class="row align-items-center mb-5 py-5" id="about">
            <div class="col-md-6 mb-5 mb-md-0" data-aos="fade-right">
                <div class="position-relative p-3">
                    <div class="position-absolute translate-middle-x top-0 start-0 w-100 h-100 border border-3 rounded-4" style="border-color: var(--gold-burnished) !important; z-index: 0; margin-top: -20px; margin-left: -20px; opacity: 0.3;"></div>
                    <img src="uploads/about_madrasa1.png" alt="About Us" class="img-fluid rounded-4 shadow-lg position-relative" style="z-index: 1;" loading="lazy">
                    <div class="glass-card p-4 position-absolute bottom-0 end-0 m-4 d-none d-md-block" style="z-index: 2; background: var(--emerald-royal);" data-aos="zoom-in" data-aos-delay="500">
                        <p class="text-warning small fw-bold mb-1">Founder & Patron</p>
                        <h5 class="mb-0 fw-bold text-white">مولانا قاری حماد اللہ ساجد</h5>
                    </div>
                </div>
            </div>
            <div class="col-md-6 px-lg-5" data-aos="fade-left">
                <p class="text-warning fw-bold mb-2">Our Legacy</p>
                <h2 class="fw-bold mb-4" style="color: var(--emerald-royal); font-size: 3rem;"><?php echo t('about_title'); ?></h2>
                <p class="fs-5 opacity-80 mb-4" style="text-align: justify; line-height: 2;">
                    <?php echo $m_name; ?> - <?php echo t('about_desc_1'); ?>
                </p>
                <div class="p-4 mb-4 rounded-4" style="background: rgba(255,255,255,0.5); border-right: 4px solid var(--gold-burnished);">
                    <h5 class="fw-bold mb-3" style="color: var(--emerald-royal);"><i class="fas fa-mobile-alt me-2" style="color:var(--gold-burnished)"></i> <?php echo t('about_digital'); ?></h5>
                    <p class="small mb-3" style="line-height: 1.9;"><?php echo t('about_digital_desc'); ?></p>
                    <div class="d-flex align-items-start mb-3">
                        <div class="p-2 rounded-circle me-3 flex-shrink-0" style="background: var(--emerald-royal);">
                            <i class="fas fa-user-shield" style="color: var(--gold-burnished);"></i>
                        </div>
                        <div>
                            <strong><?php echo t('about_admin_panel'); ?></strong>
                            <small class="d-block text-muted"><?php echo t('about_admin_desc'); ?></small>
                        </div>
                    </div>
                    <div class="d-flex align-items-start">
                        <div class="p-2 rounded-circle me-3 flex-shrink-0" style="background: var(--emerald-royal);">
                            <i class="fas fa-users" style="color: var(--gold-burnished);"></i>
                        </div>
                        <div>
                            <strong><?php echo t('about_parent_panel'); ?></strong>
                            <small class="d-block text-muted"><?php echo t('about_parent_desc'); ?></small>
                        </div>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-3 bg-white rounded-circle shadow-sm"><i class="fas fa-certificate text-warning fs-3"></i></div>
                            <span class="fw-bold"><?php echo t('about_wifaq'); ?></span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-3 bg-white rounded-circle shadow-sm"><i class="fas fa-user-shield text-warning fs-3"></i></div>
                            <span class="fw-bold"><?php echo t('about_hostel'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 5: Departments -->
        <div class="py-5" id="depts">
            <h2 class="section-title">نمایاں <?php echo t('nav_depts'); ?></h2>
            <div class="row g-4 justify-content-center">
                <?php 
                $depts = [
                    [t('dept_hifz'), t('dept_hifz_desc'), 'uploads/quran_rihal.png', '100'],
                    [t('dept_nazra'), t('dept_nazra_desc'), 'uploads/quran_rihal.png', '300'],
                    [t('dept_qirat'), t('dept_qirat_desc'), 'uploads/quran_rihal.png', '500']
                ];
                foreach($depts as $d):
                ?>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="<?php echo $d[3]; ?>">
                    <div class="dept-card box-shadow">
                        <img src="<?php echo $d[2]; ?>" class="dept-img" loading="lazy">
                        <div class="dept-content text-center">
                            <h4 class="fw-bold mb-3"><?php echo $d[0]; ?></h4>
                            <p class="small opacity-80 mb-4"><?php echo $d[1]; ?></p>
                            <a href="#" class="btn btn-outline-warning btn-sm rounded-pill px-4"><?php echo t('btn_details'); ?></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Secondary Services -->
        <div class="row g-4 mb-5">
            <div class="col-md-6 col-lg-6" data-aos="fade-up">
                <div class="glass-card d-flex align-items-center h-100 p-3">
                    <div class="p-3 bg-success bg-opacity-10 rounded-circle me-3"><i class="fas fa-university fs-2 text-success"></i></div>
                    <div>
                        <h5 class="fw-bold mb-1"><?php echo t('service_dars'); ?></h5>
                        <small class="text-muted"><?php echo t('service_dars_desc'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-6" data-aos="fade-up" data-aos-delay="100">
                <div class="glass-card d-flex align-items-center h-100 p-3">
                    <div class="p-3 bg-success bg-opacity-10 rounded-circle me-3"><i class="fas fa-laptop-code fs-2 text-success"></i></div>
                    <div>
                        <h5 class="fw-bold mb-1"><?php echo t('service_modern'); ?> (مڈل + میٹرک)</h5>
                        <small class="text-muted">دینی اور دنیاوی تعلیم کا حسین امتزاج۔</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 6.1: Seven Qira'at -->
        <div class="py-5 bg-white bg-opacity-50 rounded-5 my-5 p-4 p-md-5">
            <h2 class="section-title">سات قراءات (Seven Qira'at)</h2>
            <p class="text-center text-muted mb-5 fs-5">
                بہت سی صحیح احادیث سے واضح ہوتا ہے کہ قرآن کریم ’’سات حروف‘‘ پر اتارا گیا ہے، اس سے مراد وہ قراءتیں ہیں جو تواتر کے ساتھ منقول چلی آرہی ہیں۔ متواتر قرائتوں کی قبولیت کے لیے یہ شرط ہے کہ عربی زبان میں اس کی قوی وجہ ہو، رسم عثمانی کے موافق ہو اور تواتر کے ساتھ منقول ہو۔ یہی ’’قراءات سبعہ متواترہ‘‘ کہلاتی ہیں۔
            </p>
            <div class="row g-4">
                <?php 
                $qiraat = [
                    ['نافع المدنی', 'راوی: ورش اور قالون۔ مثال: مَالِكِ يَوْمِ الدِّينِ بمقابلہ مَلِكِ يَوْمِ الدِّينِ', 'Nafi al-Madani', '0'],
                    ['ابن کثیر المکی', 'راوی: البزی اور قنبل۔ مثال: إِنَّ نَاشِئَةَ اللَّيْلِ (تلفظ میں اختلاف)', 'Ibn Kathir', '100'],
                    ['ابو عمرو البصری', 'راوی: دوری اور السوسی۔ مثال: حَافِظُوا عَلَى الصَّلَوَاتِ', 'Abu Amr', '200'],
                    ['ابن عامر الشامی', 'راوی: ہشام، ابن ذکوان۔ مثال: وَاتَّبَعُوا مَا تَتْلُو الشَّيَاطِينُ', 'Ibn Amir', '300'],
                    ['عاصم الکوفی', 'راوی: حفص، ابوبکر شعبہ۔ مثال: مَالِكِ يَوْمِ الدِّينِ', 'Asim al-Kufi', '400'],
                    ['حمزہ الکوفی', 'راوی: خلف اور خلاد۔ مثال: فَمَن كَانَ مِنكُم مَّرِيضًا', 'Hamzah al-Kufi', '500'],
                    ['الکسائی الکوفی', 'راوی: دوری، ابوالحارث۔ مثال: وَاسْتَشْهِدُوا شَهِيدَيْنِ', 'Al-Kisa\'i', '600']
                ];
                foreach($qiraat as $index => $q): 
                ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?php echo $q[3]; ?>">
                    <div class="audio-track glass-card border-0 h-100">
                        <div class="play-btn-royal"><i class="fas fa-book-open"></i></div>
                        <div class="flex-grow-1">
                            <p class="text-warning small fw-bold mb-1"><?php echo $q[2]; ?></p>
                            <h6 class="fw-bold mb-0" style="color: var(--emerald-royal);"><?php echo $q[0]; ?></h6>
                            <small class="opacity-80 d-block mt-2 lh-base"><?php echo $q[1]; ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section 6.2: Maqamat-e-Qurania -->
        <div class="py-5 rounded-5 my-5 p-4 p-md-5 position-relative" style="background: var(--emerald-royal); color: white;">
            <h2 class="section-title text-white">مقاماتِ قرآنیہ (Seven Maqamat)</h2>
            <p class="text-center opacity-75 mb-5 fs-5">
                مقامات سے مراد قرآن کریم کو پڑھنے کے مختلف سوز و گداز کے انداز ہیں۔ یہ انداز موسیقی کے اصولوں پر مبنی ہیں اور تلاوت کو خوبصورت بناتے ہیں۔ ان ساتوں لہجوں کے نام یاد رکھنے کے لیے اکابر قراء اساتذہ کرام نے "صُنِعَ بِسِحْرِکَ" کا لفظ تجویز فرمایا ہے۔
            </p>
            <div class="row g-4">
                <?php 
                $maqamat = [
                    ['مقام صبا', 'تلاوت قرآن کریم کا وہ لہجہ جو غم اور رقت کے جذبات کو ظاہر کرتا ہے', 'Saba', '0'],
                    ['مقام نہاوند', 'تلاوت قرآن کریم کا وہ لہجہ جو نرم اور دلکش انداز تلاوت کے لیے استعمال ہوتا ہے', 'Nahawand', '100'],
                    ['مقام عجم', 'تلاوت قرآن کریم کا وہ لہجہ جو خوشی اور جشن کے جذبات کو ظاہر کرتا ہے', 'Ajam', '200'],
                    ['مقام بیات', 'تلاوت قرآن کریم کا وہ لہجہ جو نرم اور سادہ انداز تلاوت کے لیے استعمال ہوتا ہے', 'Bayati', '300'],
                    ['مقام سيكاه', 'تلاوت قرآن کریم کا وہ لہجہ جو جوش اور ولولہ کو ظاہر کرتا ہے', 'Sikah', '400'],
                    ['مقام حجاز', 'تلاوت قرآن کریم کا وہ لہجہ جو سفر یا غربت کے جذبات کو ظاہر کرتا ہے', 'Hijaz', '500'],
                    ['مقام رست', 'تلاوت قرآن کریم کا وہ لہجہ جو وقار اور سنجیدگی کو ظاہر کرتا ہے', 'Rast', '600']
                ];
                foreach($maqamat as $index => $m): 
                ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?php echo $m[3]; ?>">
                    <div class="audio-track bg-white bg-opacity-10 border-0 h-100">
                        <div class="play-btn-royal bg-white text-dark"><i class="fas fa-play"></i></div>
                        <div class="flex-grow-1 text-white">
                            <p class="text-warning small fw-bold mb-1"><?php echo $m[2]; ?></p>
                            <h6 class="fw-bold mb-0 text-white"><?php echo $m[0]; ?></h6>
                            <small class="opacity-75 d-block mt-2"><?php echo $m[1]; ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section 7: Facilities -->
        <div class="py-5">
            <h2 class="section-title">دیگر سہولیات</h2>
            <div class="row g-4 text-center">
                <?php 
                $facilities = [
                    ['پرتعیش ہاسٹل', 'fas fa-hotel', '0'],
                    ['معیاری طعام', 'fas fa-utensils', '100'],
                    ['جدید لائبریری', 'fas fa-book-reader', '200'],
                    ['ٹرانسپورٹ', 'fas fa-bus', '300']
                ];
                foreach($facilities as $f):
                ?>
                <div class="col-6 col-md-3" data-aos="zoom-in" data-aos-delay="<?php echo $f[2]; ?>">
                    <div class="glass-card p-5 h-100 border-0 shadow-sm">
                        <i class="<?php echo $f[1]; ?> fs-1 mb-4 text-warning"></i>
                        <h5 class="fw-bold mb-0" style="color: var(--emerald-royal);"><?php echo $f[0]; ?></h5>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section 8: Admission Journey -->
        <div class="py-5" id="admission">
            <h2 class="section-title">طریقہ داخلہ</h2>
            <div class="mt-4" data-aos="fade-up">
                <div class="row g-4 text-center">
                    <div class="col-md-4">
                        <div class="glass-card p-5 h-100 position-relative animate-border">
                            <div class="d-none d-md-block position-absolute top-50 start-100 translate-middle-y" style="width: 50px; height: 2px; border-top: 2px dashed var(--gold-burnished); z-index: 5;"></div>
                            <i class="fas fa-edit fs-1 mb-4 text-warning"></i>
                            <h4 class="fw-bold text-success mb-3">1. آن لائن رجسٹریشن</h4>
                            <p class="text-muted mb-4 small">ویب سائٹ پر موجود فارم کے ذریعے اپنی معلومات فراہم کریں</p>
                            <button class="btn btn-royal btn-sm w-100" data-bs-toggle="modal" data-bs-target="#admissionModal">ابھی اپلائی کریں</button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="glass-card p-5 h-100 position-relative">
                            <div class="d-none d-md-block position-absolute top-50 start-100 translate-middle-y" style="width: 50px; height: 2px; border-top: 2px dashed var(--gold-burnished); z-index: 5;"></div>
                            <i class="fas fa-calendar-check fs-1 mb-4 text-warning"></i>
                            <h4 class="fw-bold text-success mb-3">2. ٹیسٹ کی اطلاع</h4>
                            <p class="text-muted mb-0 small">آپ کو <?php echo t('contact_email'); ?> یا فون پر ٹیسٹ کی تاریخ سے مطلع کیا جائے گا</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="glass-card p-5 h-100">
                            <i class="fas fa-user-graduate fs-1 mb-4 text-warning"></i>
                            <h4 class="fw-bold text-success mb-3">3. حتمی داخلہ</h4>
                            <p class="text-muted mb-0 small">انٹرویو اور ٹیسٹ میں کامیابی کے بعد کلاسز کا باقاعدہ آغاز</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 9: Notice Board -->
        <div class="py-5 mb-5 bg-white bg-opacity-50 rounded-5 p-4 p-md-5">
            <div class="row align-items-center">
                <div class="col-md-5" data-aos="fade-right">
                    <p class="text-warning fw-bold mb-2">Announcements</p>
                    <h2 class="fw-bold mb-4" style="color: var(--emerald-royal); font-size: 2.5rem;"><?php echo t('notice_board_ur'); ?></h2>
                    <p class="text-muted fs-5 mb-5">ادارے کی جانب سے <?php echo t('marquee_latest'); ?> اعلانات اور خبریں یہاں ملاحظہ فرمائیں۔</p>
                    <div class="d-none d-md-block">
                        <i class="fas fa-bullhorn" style="font-size: 180px; color: var(--emerald-royal); opacity: 0.03; transform: rotate(-15deg);"></i>
                    </div>
                </div>
                <div class="col-md-7" data-aos="fade-left">
                    <div class="glass-card p-2 p-md-4" style="max-height: 500px; overflow-y: auto;">
                        <?php if(count($notices) > 0): ?>
                            <div class="timeline-royal">
                                <?php foreach($notices as $n): ?>
                                <div class="p-4 border-start border-3 border-warning mb-4 bg-white rounded-4 shadow-sm mx-3">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="fw-bold mb-0 text-success"><?php echo $n['title']; ?></h5>
                                        <span class="badge bg-light text-muted fw-normal px-3 py-2" dir="ltr"><?php echo date('d M Y', strtotime($n['date'])); ?></span>
                                    </div>
                                    <p class="mb-0 text-muted" style="line-height: 1.8;"><?php echo $n['message']; ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard-list fs-1 text-muted opacity-20 mb-3"></i>
                                <p class="text-muted">فی الوقت کوئی نیا نوٹس موجود نہیں ہے۔</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- End Main Container -->

    </div>

    <!-- Section 10: Footer (Very Attractive) -->
    <!-- Section 10: Footer (Luxury Redesign) -->
    <!-- Section 10: Footer (Luxury Signature) -->
    <footer class="footer-luxury">
        <div class="container">
            <div class="row g-5">
                <!-- Branding -->
                <div class="col-lg-5" data-aos="fade-up">
                    <div class="footer-logo">
                        <img src="logo.png" alt="Logo" style="width: 50px; height: 50px; border-radius: 50%; margin-left: 10px; background: white; border: 2px solid var(--gold-burnished); object-fit: contain;"><?php echo $m_name; ?>
                    </div>
                    <p class="fs-5 lh-lg mb-5" style="text-align: justify; max-width: 90%;">
                        ہماری بنیادیں قرآن و سنت پر ہیں، اور ہمارا مقصد ایک ایسے تعلیمی ماحول کی فراہمی ہے جہاں طلبا اپنی روحانی اور علمی پیاس بجھا سکیں۔ ہم بہترین تربیت کے ساتھ مستند دینی تعلیم کے علمبردار ہیں۔
                    </p>
                    <div class="d-flex gap-3">
                        <a href="#" class="btn btn-outline-light rounded-circle shadow-sm" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="btn btn-outline-light rounded-circle shadow-sm" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="btn btn-outline-light rounded-circle shadow-sm" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="btn btn-outline-light rounded-circle shadow-sm" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                
                <!-- Links -->
                <div class="col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <h5 class="text-white fw-bold mb-4">فوری رسائی</h5>
                    <ul class="list-unstyled d-grid gap-3">
                        <li><a href="#" class="footer-link"><i class="fas fa-angle-left me-2 text-warning small"></i> ہماری ویب سائٹ</a></li>
                        <li><a href="#about" class="footer-link"><i class="fas fa-angle-left me-2 text-warning small"></i> <?php echo t('nav_about'); ?></a></li>
                        <li><a href="#depts" class="footer-link"><i class="fas fa-angle-left me-2 text-warning small"></i> <?php echo t('nav_depts'); ?> کی تفصیل</a></li>
                        <li><a href="#admission" class="footer-link"><i class="fas fa-angle-left me-2 text-warning small"></i> آن لائن رجسٹریشن</a></li>
                        <li><a href="login.php" class="footer-link"><i class="fas fa-angle-left me-2 text-warning small"></i> والدین کا پورٹل</a></li>
                    </ul>
                </div>

                <!-- Contact Info -->
                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="400">
                    <h5 class="text-white fw-bold mb-4"><?php echo t('nav_contact'); ?> کی معلومات</h5>
                    <div class="glass-card p-4 shadow-none bg-white bg-opacity-10 border-0">
                        <div class="d-flex mb-4">
                            <div class="p-3 bg-warning text-dark rounded-4 me-3"><i class="fas fa-map-marker-alt fs-4"></i></div>
                            <div>
                                <p class="text-white mb-0 small opacity-50">Main Campus</p>
                                <span class="text-white fw-bold" dir="ltr"><?php echo $address; ?></span>
                            </div>
                        </div>
                        <div class="d-flex">
                            <div class="p-3 bg-warning text-dark rounded-4 me-3"><i class="fas fa-phone-alt fs-4"></i></div>
                            <div>
                                <p class="text-white mb-0 small opacity-50">Support & Admissions</p>
                                <span class="text-white fw-bold" dir="ltr"><?php echo $phone; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-5 border-top border-white border-opacity-10 text-center">
                <p class="small mb-0 opacity-50">
                    کاپي رائٹ © 2026 <?php echo $m_name; ?>۔ تمام حقوق بحق CodeHubb محفوظ ہیں۔
                </p>
            </div>
        </div>
    </footer>


    <!-- Admission Modal -->
    <div class="modal fade" id="admissionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 15px; border: 2px solid var(--primary-green);">
                <div class="modal-header" style="background: var(--primary-green);">
                    <h5 class="modal-title fw-bold text-white"><i class="fas fa-graduation-cap me-2"></i> آن لائن <?php echo t('footer_admission'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-4">براہ کرم اپنی معلومات درج کریں۔ ایڈمن کی منظوری کے بعد آپ کی <strong class="text-success"><?php echo t('contact_email'); ?></strong> پر ٹیسٹ کی تاریخ و وقت بھیجا جائے گا۔</p>
                    <form id="admissionForm">
                        <div class="row g-3">
                            <div class="col-md-9 order-2 order-md-1"> <!-- Fields on Right/Center -->
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">طالب علم کا نام <span class="text-danger">*</span></label>
                                        <input type="text" name="student_name" class="form-control" placeholder="محمد احمد" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">والد کا نام <span class="text-danger">*</span></label>
                                        <input type="text" name="father_name" class="form-control" placeholder="محمد اکرم" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">تاریخ پیدائش <span class="text-danger">*</span></label>
                                        <input type="date" name="dob" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold"><?php echo t('nav_contact'); ?> نمبر <span class="text-danger">*</span></label>
                                        <input type="tel" name="phone" class="form-control" placeholder="03001234567" dir="ltr" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 order-1 order-md-2 text-center"> <!-- Photo on Left -->
                                <label class="form-label fw-bold">پاسپورٹ تصویر <span class="text-danger">*</span></label>
                                <div class="border rounded p-2 bg-light mb-2" style="height: 150px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                    <i class="fas fa-user-tie fa-4x text-muted" id="photo_preview_icon"></i>
                                    <img id="photo_preview_img" class="img-fluid d-none" style="max-height: 100%;">
                                </div>
                                <input type="file" name="photo" id="photo_input" class="form-control form-control-sm" accept="image/*" required onchange="previewPhoto(this)">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold"><?php echo t('contact_email'); ?> ایڈریس <small class="text-success">(اطلاع کے لیے)</small></label>
                                <input type="email" name="email" class="form-control" placeholder="example@gmail.com" dir="ltr">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تعلیمی قابلیت</label>
                                <input type="text" name="qualification" class="form-control" placeholder="مثلاً میٹرک، مڈل">
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label fw-bold">مستقل <?php echo t('contact_address'); ?> <span class="text-danger">*</span></label>
                                <textarea name="permanent_address" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">آخری تعلیم کہاں حاصل کی؟</label>
                                <input type="text" name="last_studied" class="form-control" placeholder="اسکول یا مدرسہ کا نام">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">پڑھنے کا مقصد (Aim)</label>
                                <input type="text" name="aim" class="form-control" placeholder="مثلاً عالم بننا، حفظ کرنا">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">سابقہ مدارس (اگر ہوں)</label>
                                <input type="text" name="prev_madaris" class="form-control" placeholder="سابقہ مدارس کے نام">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">پچھلا مدرسہ / اسکول چھوڑنے کی وجہ</label>
                                <textarea name="leave_reason" class="form-control" rows="1"></textarea>
                            </div>
                            <hr>
                            <h6 class="fw-bold text-primary mb-0 mt-3">سرپرست کی معلومات (Guardian Details)</h6>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">سرپرست کا نام</label>
                                <input type="text" name="guardian_name" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">سرپرست کا شناختی کارڈ نمبر</label>
                                <input type="text" name="guardian_cnic" class="form-control" placeholder="xxxxx-xxxxxxx-x" dir="ltr">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">طلبہ سے رشتہ</label>
                                <input type="text" name="guardian_relation" class="form-control" placeholder="مثلاً والد، بھائی، چچا">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">سرپرست کا <?php echo t('contact_address'); ?></label>
                                <input type="text" name="guardian_address" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><?php echo t('age'); ?></label>
                                <input type="number" name="age" class="form-control" placeholder="مثلاً: 12" min="5" max="25">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">شعبہ منتخب کریں</label>
                                <select name="department" class="form-select">
                                    <option value="">شعبہ منتخب کریں...</option>
                                    <option value="حفظ قرآن"><?php echo t('dept_hifz'); ?></option>
                                    <option value="<?php echo t('dept_nazra'); ?>"><?php echo t('dept_nazra'); ?></option>
                                    <option value="حسن صوت"><?php echo t('dept_qirat'); ?></option>
                                    <option value="درس نظامی"><?php echo t('service_dars'); ?></option>
                                    <option value="<?php echo t('service_modern'); ?>"><?php echo t('service_modern'); ?> (مڈل + میٹرک)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">موجودہ <?php echo t('contact_address'); ?> (Current Address)</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="گھر کا <?php echo t('contact_address'); ?>"></textarea>
                            </div>
                        </div>
                        <div id="admissionAlert" class="mt-3"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">بند کریں</button>
                    <button type="button" id="preSubmitBtn" class="btn btn-success rounded-pill px-4 fw-bold">
                        <i class="fas fa-check-circle me-2"></i> آگے بڑھیں
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rules Modal (Ahd-nama) -->
    <div class="modal fade" id="rulesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 15px; border: 2px solid var(--gold-burnished);">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold">عہدنامہ ازطالب علم (قواعد و ضوابط)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="background: #fbf9f4;">
                    <div id="rulesContent" style="height: 300px; overflow-y: auto; text-align: justify; line-height: 2; padding: 20px; border: 1px solid #ddd; background: white; border-radius: 10px;">
                        <h4 class="text-center fw-bold mb-4">ضروری قواعد و ضوابط</h4>
                        <p>1. مدرسہ میں داخلہ کے بعد طالب علم کو مقررہ نصاب اور اوقات کار کی مکمل پابندی کرنی ہوگی۔</p>
                        <p>2. طالب علم کو مدرسہ کے تمام اساتذہ اور عملہ کے ساتھ باادب رویہ اختیار کرنا ہوگا۔</p>
                        <p>3. غیر حاضری کی صورت میں پیشگی اطلاع دینا لازمی ہے، بلا اطلاع لمبی غیر حاضری پر داخلہ خارج کیا جا سکتا ہے۔</p>
                        <p>4. مدرسہ کی املاک کو نقصان پہنچانے کی صورت میں ہرجانہ طالب علم یا سرپرست کے ذمہ ہوگا۔</p>
                        <p>5. مدرسہ کے اندر کسی بھی قسم کی غیر اخلاقی سرگرمی یا سیاست میں حصہ لینے کی سختی سے ممانعت ہے۔</p>
                        <p>6. طالب علم کو اپنی صفائی ستھرائی اور لباس کا خاص خیال رکھنا ہوگا۔</p>
                        <p>7. <?php echo t('footer_admission'); ?> میں فراہم کردہ تمام معلومات درست ہیں، کسی بھی غلط بیانی کی صورت میں ادارہ تادیبی کارروائی کا حق محفوظ رکھتا ہے۔</p>
                    </div>
                    <div class="mt-4 p-3 bg-white rounded-3 shadow-sm border">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="agreeCheckbox" style="width: 25px; height: 25px; margin-top: 0;">
                            <label class="form-check-label fw-bold ms-3" for="agreeCheckbox" style="font-size: 1.1rem; color: var(--emerald-royal); cursor: pointer;">
                                میں تمام قواعد و ضوابط سے اتفاق کرتا ہوں اور ان کی پابندی کا عہد کرتا ہوں۔ (I Agree)
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#admissionModal">واپس جائیں</button>
                    <button type="button" id="submitAdmissionBtn" class="btn btn-success px-5 fw-bold" disabled>
                        <i class="fas fa-paper-plane me-2"></i> عہدنامے کے ساتھ فارم جمع کریں
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
    // Initialize AOS
    AOS.init({
        duration: 1200,
        once: true,
        easing: 'ease-out-cubic'
    });

    // Photo Preview
    function previewPhoto(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('photo_preview_img').src = e.target.result;
                document.getElementById('photo_preview_img').classList.remove('d-none');
                document.getElementById('photo_preview_icon').classList.add('d-none');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Navbar Scroll Effect
    window.addEventListener('scroll', () => {
        const nav = document.querySelector('.navbar');
        if (window.scrollY > 50) {
            nav.classList.add('scrolled', 'shadow-lg');
        } else {
            nav.classList.remove('scrolled', 'shadow-lg');
        }
    });

    // Admission Workflow
    const admissionModal = new bootstrap.Modal(document.getElementById('admissionModal'));
    const rulesModal = new bootstrap.Modal(document.getElementById('rulesModal'));

    document.getElementById('preSubmitBtn').addEventListener('click', function() {
        const form = document.getElementById('admissionForm');
        if (form.checkValidity()) {
            admissionModal.hide();
            rulesModal.show();
        } else {
            form.reportValidity();
        }
    });

    document.getElementById('agreeCheckbox').addEventListener('change', function() {
        document.getElementById('submitAdmissionBtn').disabled = !this.checked;
    });

    // Admission Form AJAX Submission
    document.getElementById('submitAdmissionBtn').addEventListener('click', function() {
        const btn = this;
        const form = document.getElementById('admissionForm');
        const alertBox = document.getElementById('admissionAlert');
        const agreeCheckbox = document.getElementById('agreeCheckbox');
        
        if(!agreeCheckbox.checked) {
            alert('براہ کرم قواعد و ضوابط سے اتفاق کریں۔');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> جمع ہو رہا ہے...';

        const formData = new FormData(form);

        fetch('apply.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    rulesModal.hide();
                    admissionModal.hide();
                    
                    Swal.fire({
                        title: 'ماشاءاللہ!',
                        text: data.message,
                        icon: 'success',
                        confirmButtonText: 'ٹھیک ہے',
                        confirmButtonColor: 'var(--emerald-royal)'
                    });

                    form.reset();
                    document.getElementById('photo_preview_img').classList.add('d-none');
                    document.getElementById('photo_preview_icon').classList.remove('d-none');
                    agreeCheckbox.checked = false;
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> عہدنامے کے ساتھ فارم جمع کریں';
                } else {
                    alert('Error: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = 'دوبارہ کوشش کریں';
                }
            })
            .catch(() => {
                alert('نیٹ ورک خرابی، براہ کرم انٹرنیٹ چیک کریں۔');
                btn.disabled = false;
                btn.innerHTML = 'دوبارہ کوشش کریں';
            });
    });
    </script>
</body>
</html>
