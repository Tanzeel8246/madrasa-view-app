<?php
$current_lang = $lang ?? 'ur';
?>
<style>
    :root {
        --primary: #284D3D;
        --secondary: #D4AC0D;
        --bg: #F1EFE7;
        --card-bg: #ffffff;
        --card-border: rgba(0, 0, 0, 0.02);
        --card-shadow: 0 10px 25px rgba(0,0,0,0.05);
        --text-main: #212529;
        --text-muted: #6c757d;
        --sidebar-bg: #284D3D;
        --sidebar-text: #eee;
        --sidebar-hover-bg: rgba(212, 172, 13, 0.2);
        --sidebar-hover-text: #fff;
        --sidebar-border: #D4AC0D;
        --navbar-bg: #ffffff;
        --navbar-text: #284D3D;
        --table-header-bg: #f8fafc;
        --table-hover-bg: #f1f5f9;
        --input-bg: #ffffff;
        --input-text: #212529;
        --input-border: #ced4da;
        --btn-primary-bg: #284D3D;
        --btn-primary-border: #284D3D;
        --btn-primary-hover-bg: #1f3f31;
        --btn-primary-hover-border: #1f3f31;
        --sidebar-w: 260px;

        /* Stat card variables */
        --bg-gradient-green-val: linear-gradient(135deg, #284D3D 0%, #1a3329 100%);
        --bg-gradient-gold-val: linear-gradient(135deg, #D4AC0D 0%, #a6870a 100%);
        --bg-gradient-blue-val: linear-gradient(135deg, #2980b9 0%, #2c3e50 100%);
        --bg-gradient-red-val: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        --bg-gradient-dark-val: linear-gradient(135deg, #2c3e50 0%, #000000 100%);
        --bg-gradient-info-val: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);

        --stat-card-green-text: #ffffff;
        --stat-card-gold-text: #ffffff;
        --stat-card-blue-text: #ffffff;
        --stat-card-red-text: #ffffff;
        --stat-card-dark-text: #ffffff;
        --stat-card-info-text: #ffffff;

        /* Secondary Buttons */
        --secondary-hover: #bda20b;
        --btn-secondary-text: #000000;

        /* Status & Alert elements: Default Bootstrap values */
        --status-success: #284D3D;
        --status-success-hover: #1f3f31;
        --status-success-text: #ffffff;
        --status-success-bg: #d1e7dd;
        --status-success-border: #badbcc;
        --status-success-text-alert: #0f5132;

        --status-danger: #dc3545;
        --status-danger-hover: #bb2d3b;
        --status-danger-text: #ffffff;
        --status-danger-bg: #f8d7da;
        --status-danger-border: #f5c2c7;
        --status-danger-text-alert: #842029;

        --status-warning: #ffc107;
        --status-warning-hover: #ffca2c;
        --status-warning-text: #000000;
        --status-warning-bg: #fff3cd;
        --status-warning-border: #ffecb5;
        --status-warning-text-alert: #664d03;

        --status-info: #0dcaf0;
        --status-info-hover: #31d2f2;
        --status-info-text: #000000;
        --status-info-bg: #cff4fc;
        --status-info-border: #b6effb;
        --status-info-text-alert: #087990;
    }

    html.theme-dark-emerald {
        --primary: #00e676;
        --secondary: #ffd700;
        --bg: #0c0f16;
        --card-bg: #171d2c;
        --card-border: rgba(255, 255, 255, 0.05);
        --card-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        --text-main: #f8fafc;
        --text-muted: #94a3b8;
        --sidebar-bg: #111622;
        --sidebar-text: #94a3b8;
        --sidebar-hover-bg: rgba(0, 230, 118, 0.15);
        --sidebar-hover-text: #00e676;
        --sidebar-border: #00e676;
        --navbar-bg: #111622;
        --navbar-text: #f8fafc;
        --table-header-bg: #1e293b;
        --table-hover-bg: #1e293b;
        --input-bg: #1e293b;
        --input-text: #f8fafc;
        --input-border: #334155;
        --btn-primary-bg: #00e676;
        --btn-primary-border: #00e676;
        --btn-primary-hover-bg: #00b35c;
        --btn-primary-hover-border: #00b35c;

        /* Stat card variables: Dark card glows */
        --bg-gradient-green-val: linear-gradient(135deg, #0b2216, #171d2c);
        --bg-gradient-gold-val: linear-gradient(135deg, #251e0f, #171d2c);
        --bg-gradient-blue-val: linear-gradient(135deg, #0d1e2d, #171d2c);
        --bg-gradient-red-val: linear-gradient(135deg, #2a0f12, #171d2c);
        --bg-gradient-dark-val: linear-gradient(135deg, #171d2c, #0d1117);
        --bg-gradient-info-val: linear-gradient(135deg, #0c2025, #171d2c);

        --stat-card-green-text: #00e676;
        --stat-card-gold-text: #ffd700;
        --stat-card-blue-text: #3b82f6;
        --stat-card-red-text: #e74c3c;
        --stat-card-dark-text: #f8fafc;
        --stat-card-info-text: #17a2b8;

        /* Secondary Buttons */
        --secondary-hover: #e6c200;
        --btn-secondary-text: #0c0f16;

        /* Status & Alert elements: Emerald themes */
        --status-success: #00e676;
        --status-success-hover: #00c853;
        --status-success-text: #0c0f16;
        --status-success-bg: rgba(0, 230, 118, 0.15);
        --status-success-border: rgba(0, 230, 118, 0.3);
        --status-success-text-alert: #00e676;

        --status-danger: #ff1744;
        --status-danger-hover: #d50000;
        --status-danger-text: #ffffff;
        --status-danger-bg: rgba(255, 23, 68, 0.15);
        --status-danger-border: rgba(255, 23, 68, 0.3);
        --status-danger-text-alert: #ff1744;

        --status-warning: #ffd700;
        --status-warning-hover: #ffb300;
        --status-warning-text: #0c0f16;
        --status-warning-bg: rgba(255, 215, 0, 0.15);
        --status-warning-border: rgba(255, 215, 0, 0.3);
        --status-warning-text-alert: #ffd700;

        --status-info: #00e5ff;
        --status-info-hover: #00b8d4;
        --status-info-text: #0c0f16;
        --status-info-bg: rgba(0, 229, 255, 0.15);
        --status-info-border: rgba(0, 229, 255, 0.3);
        --status-info-text-alert: #00e5ff;
    }

    html.theme-classic-navy {
        --primary: #0b2545;
        --secondary: #3b82f6;
        --bg: #f4f6f9;
        --card-bg: #ffffff;
        --card-border: rgba(0, 0, 0, 0.05);
        --card-shadow: 0 10px 25px rgba(11, 37, 69, 0.05);
        --text-main: #1e293b;
        --text-muted: #64748b;
        --sidebar-bg: #0b2545;
        --sidebar-text: #e0e6ed;
        --sidebar-hover-bg: rgba(255, 255, 255, 0.1);
        --sidebar-hover-text: #ffffff;
        --sidebar-border: #3b82f6;
        --navbar-bg: #ffffff;
        --navbar-text: #0b2545;
        --table-header-bg: #f8fafc;
        --table-hover-bg: #f1f5f9;
        --input-bg: #ffffff;
        --input-text: #1e293b;
        --input-border: #cbd5e1;
        --btn-primary-bg: #0b2545;
        --btn-primary-border: #0b2545;
        --btn-primary-hover-bg: #071930;
        --btn-primary-hover-border: #071930;

        /* Stat card variables: Soft pastels */
        --bg-gradient-green-val: linear-gradient(135deg, #e8f5e9, #c8e6c9);
        --bg-gradient-gold-val: linear-gradient(135deg, #fff9c4, #fff59d);
        --bg-gradient-blue-val: linear-gradient(135deg, #e8eaf6, #c5cae9);
        --bg-gradient-red-val: linear-gradient(135deg, #ffebee, #ffcdd2);
        --bg-gradient-dark-val: linear-gradient(135deg, #f5f5f5, #e0e0e0);
        --bg-gradient-info-val: linear-gradient(135deg, #e0f7fa, #b2ebf2);

        --stat-card-green-text: #1b5e20;
        --stat-card-gold-text: #f57f17;
        --stat-card-blue-text: #1a237e;
        --stat-card-red-text: #b71c1c;
        --stat-card-dark-text: #212121;
        --stat-card-info-text: #006064;

        /* Secondary Buttons */
        --secondary-hover: #2563eb;
        --btn-secondary-text: #ffffff;

        /* Status & Alert elements: Classic corporate */
        --status-success: #2e7d32;
        --status-success-hover: #1b5e20;
        --status-success-text: #ffffff;
        --status-success-bg: #e8f5e9;
        --status-success-border: #c8e6c9;
        --status-success-text-alert: #1b5e20;

        --status-danger: #c62828;
        --status-danger-hover: #b71c1c;
        --status-danger-text: #ffffff;
        --status-danger-bg: #ffebee;
        --status-danger-border: #ffcdd2;
        --status-danger-text-alert: #b71c1c;

        --status-warning: #f9a825;
        --status-warning-hover: #f57f17;
        --status-warning-text: #ffffff;
        --status-warning-bg: #fffde7;
        --status-warning-border: #fff9c4;
        --status-warning-text-alert: #f57f17;

        --status-info: #1565c0;
        --status-info-hover: #0d47a1;
        --status-info-text: #ffffff;
        --status-info-bg: #e8eaf6;
        --status-info-border: #c5cae9;
        --status-info-text-alert: #0d47a1;
    }

    html.theme-warm-terracotta {
        --primary: #34495e;
        --secondary: #c87a53;
        --bg: #f5f4eb;
        --card-bg: #ffffff;
        --card-border: rgba(200, 122, 83, 0.1);
        --card-shadow: 0 10px 25px rgba(200, 122, 83, 0.05);
        --text-main: #2c3e50;
        --text-muted: #7f8c8d;
        --sidebar-bg: #2d3d41;
        --sidebar-text: #ecf0f1;
        --sidebar-hover-bg: rgba(200, 122, 83, 0.2);
        --sidebar-hover-text: #ffffff;
        --sidebar-border: #c87a53;
        --navbar-bg: #ffffff;
        --navbar-text: #2d3d41;
        --table-header-bg: #faf9f5;
        --table-hover-bg: #f5f4eb;
        --input-bg: #ffffff;
        --input-text: #2c3e50;
        --input-border: #bdc3c7;
        --btn-primary-bg: #c87a53;
        --btn-primary-border: #c87a53;
        --btn-primary-hover-bg: #b0653f;
        --btn-primary-hover-border: #b0653f;

        /* Stat card variables: Warm sand and terracotta clay tones */
        --bg-gradient-green-val: linear-gradient(135deg, #eef2f3, #e3e7e8);
        --bg-gradient-gold-val: linear-gradient(135deg, #fcf8f2, #f5e9d9);
        --bg-gradient-blue-val: linear-gradient(135deg, #eaecef, #dcdde1);
        --bg-gradient-red-val: linear-gradient(135deg, #fbeee6, #f5d6c6);
        --bg-gradient-dark-val: linear-gradient(135deg, #e5e8e8, #ccd1d1);
        --bg-gradient-info-val: linear-gradient(135deg, #f5f4eb, #ebdcb9);

        --stat-card-green-text: #2c3e50;
        --stat-card-gold-text: #b87352;
        --stat-card-blue-text: #34495e;
        --stat-card-red-text: #c87a53;
        --stat-card-dark-text: #2c3e50;
        --stat-card-info-text: #8e8071;

        /* Secondary Buttons */
        --secondary-hover: #b0653f;
        --btn-secondary-text: #ffffff;

        /* Status & Alert elements: Warm clay tones */
        --status-success: #3c6e47;
        --status-success-hover: #2f5637;
        --status-success-text: #ffffff;
        --status-success-bg: #eef6f0;
        --status-success-border: #d2ead7;
        --status-success-text-alert: #2f5637;

        --status-danger: #b03a2e;
        --status-danger-hover: #922b21;
        --status-danger-text: #ffffff;
        --status-danger-bg: #fbeee6;
        --status-danger-border: #f5d6c6;
        --status-danger-text-alert: #922b21;

        --status-warning: #b87352;
        --status-warning-hover: #a25d3c;
        --status-warning-text: #ffffff;
        --status-warning-bg: #fcf8f2;
        --status-warning-border: #f5e9d9;
        --status-warning-text-alert: #a25d3c;

        --status-info: #2e4053;
        --status-info-hover: #212f3d;
        --status-info-text: #ffffff;
        --status-info-bg: #eaecef;
        --status-info-border: #dcdde1;
        --status-info-text-alert: #212f3d;
    }

    html.theme-mint-glass {
        --primary: #00bfa5;
        --secondary: #00bfa5;
        --bg: #e0f2f1;
        --card-bg: rgba(255, 255, 255, 0.75);
        --card-border: rgba(255, 255, 255, 0.45);
        --card-shadow: 0 10px 30px rgba(0, 191, 165, 0.05);
        --text-main: #004d40;
        --text-muted: #00796b;
        --sidebar-bg: #ffffff;
        --sidebar-text: #5a6a85;
        --sidebar-hover-bg: rgba(0, 191, 165, 0.1);
        --sidebar-hover-text: #00bfa5;
        --sidebar-border: #00bfa5;
        --navbar-bg: rgba(255, 255, 255, 0.8);
        --navbar-text: #004d40;
        --table-header-bg: rgba(0, 191, 165, 0.05);
        --table-hover-bg: rgba(0, 191, 165, 0.02);
        --input-bg: rgba(255, 255, 255, 0.9);
        --input-text: #004d40;
        --input-border: rgba(0, 191, 165, 0.3);
        --btn-primary-bg: #00bfa5;
        --btn-primary-border: #00bfa5;
        --btn-primary-hover-bg: #00a58e;
        --btn-primary-hover-border: #00a58e;

        /* Stat card variables: Glassy frosted pastels */
        --bg-gradient-green-val: linear-gradient(135deg, rgba(224, 242, 241, 0.85), rgba(178, 223, 219, 0.85));
        --bg-gradient-gold-val: linear-gradient(135deg, rgba(255, 243, 224, 0.85), rgba(255, 224, 178, 0.85));
        --bg-gradient-blue-val: linear-gradient(135deg, rgba(232, 240, 253, 0.85), rgba(210, 227, 252, 0.85));
        --bg-gradient-red-val: linear-gradient(135deg, rgba(253, 237, 237, 0.85), rgba(249, 203, 203, 0.85));
        --bg-gradient-dark-val: linear-gradient(135deg, rgba(241, 243, 244, 0.85), rgba(218, 220, 224, 0.85));
        --bg-gradient-info-val: linear-gradient(135deg, rgba(224, 247, 250, 0.85), rgba(178, 235, 242, 0.85));

        --stat-card-green-text: #004d40;
        --stat-card-gold-text: #e65100;
        --stat-card-blue-text: #0d47a1;
        --stat-card-red-text: #b71c1c;
        --stat-card-dark-text: #37474f;
        --stat-card-info-text: #006064;

        /* Secondary Buttons */
        --secondary-hover: #00a58e;
        --btn-secondary-text: #ffffff;

        /* Status & Alert elements: Mint frosted */
        --status-success: #00bfa5;
        --status-success-hover: #00a58e;
        --status-success-text: #ffffff;
        --status-success-bg: rgba(0, 191, 165, 0.15);
        --status-success-border: rgba(0, 191, 165, 0.3);
        --status-success-text-alert: #004d40;

        --status-danger: #ff5252;
        --status-danger-hover: #ff1717;
        --status-danger-text: #ffffff;
        --status-danger-bg: rgba(255, 82, 82, 0.15);
        --status-danger-border: rgba(255, 82, 82, 0.3);
        --status-danger-text-alert: #b71c1c;

        --status-warning: #ffab40;
        --status-warning-hover: #ff9100;
        --status-warning-text: #ffffff;
        --status-warning-bg: rgba(255, 171, 64, 0.15);
        --status-warning-border: rgba(255, 171, 64, 0.3);
        --status-warning-text-alert: #e65100;

        --status-info: #00e5ff;
        --status-info-hover: #00b8d4;
        --status-info-text: #ffffff;
        --status-info-bg: rgba(0, 229, 255, 0.15);
        --status-info-border: rgba(0, 229, 255, 0.3);
        --status-info-text-alert: #006064;
    }

    html.theme-sage-sand {
        --primary: #7b9e87;
        --secondary: #b87352;
        --bg: #faf8f0;
        --card-bg: #ffffff;
        --card-border: rgba(123, 158, 135, 0.1);
        --card-shadow: 0 10px 25px rgba(123, 158, 135, 0.05);
        --text-main: #2f3a38;
        --text-muted: #5c6e6b;
        --sidebar-bg: #3c4e4b;
        --sidebar-text: #eef2ec;
        --sidebar-hover-bg: rgba(123, 158, 135, 0.2);
        --sidebar-hover-text: #ffffff;
        --sidebar-border: #b87352;
        --navbar-bg: #ffffff;
        --navbar-text: #3c4e4b;
        --table-header-bg: #f5f2e9;
        --table-hover-bg: #faf8f0;
        --input-bg: #ffffff;
        --input-text: #2f3a38;
        --input-border: #cbd5e1;
        --btn-primary-bg: #7b9e87;
        --btn-primary-border: #7b9e87;
        --btn-primary-hover-bg: #63856e;
        --btn-primary-hover-border: #63856e;

        /* Stat card variables: Sage and Sand theme flat blocks */
        --bg-gradient-green-val: #7b9e87;
        --bg-gradient-gold-val: #f5f2e9;
        --bg-gradient-blue-val: #c87a53;
        --bg-gradient-red-val: #b87352;
        --bg-gradient-dark-val: #3c4e4b;
        --bg-gradient-info-val: #eef2ec;

        --stat-card-green-text: #ffffff;
        --stat-card-gold-text: #2f3a38;
        --stat-card-blue-text: #ffffff;
        --stat-card-red-text: #ffffff;
        --stat-card-dark-text: #ffffff;
        --stat-card-info-text: #2f3a38;

        /* Secondary Buttons */
        --secondary-hover: #9c5a3b;
        --btn-secondary-text: #ffffff;

        /* Status & Alert elements: Sage & Sand */
        --status-success: #7b9e87;
        --status-success-hover: #63856e;
        --status-success-text: #ffffff;
        --status-success-bg: #f0f5f2;
        --status-success-border: #d3e2d9;
        --status-success-text-alert: #2f3a38;

        --status-danger: #b87352;
        --status-danger-hover: #9c5a3b;
        --status-danger-text: #ffffff;
        --status-danger-bg: #faf1ec;
        --status-danger-border: #f2dcd0;
        --status-danger-text-alert: #9c5a3b;

        --status-warning: #b87352;
        --status-warning-hover: #9c5a3b;
        --status-warning-text: #ffffff;
        --status-warning-bg: #fcf8f2;
        --status-warning-border: #f5e9d9;
        --status-warning-text-alert: #2f3a38;

        --status-info: #3c4e4b;
        --status-info-hover: #2f3d3b;
        --status-info-text: #ffffff;
        --status-info-bg: #eef2ec;
        --status-info-border: #cbdcd8;
        --status-info-text-alert: #2f3a38;
    }

    body {
        font-family: <?php echo ($current_lang == 'ur') ? "'Noto Nastaliq Urdu', serif" : "'Roboto', sans-serif"; ?>;
        background-color: var(--bg);
        color: var(--text-main);
        line-height: 2.2;
        font-size: 15px;
        overflow-x: hidden;
    }
    /* Sidebar Styles */
    #sidebar-wrapper {
        width: var(--sidebar-w); height: 100vh; 
        background: var(--sidebar-bg);
        color: var(--sidebar-text); position: fixed; top: 0; z-index: 1000; overflow-y: auto;
        transition: all 0.3s; box-shadow: 0 0 15px rgba(0,0,0,0.5);
    }
    #sidebar-wrapper .list-group-item {
        background: transparent;
        color: var(--sidebar-text);
        border: none;
        padding: 10px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    #sidebar-wrapper .list-group-item:hover, .active-link {
        background: var(--sidebar-hover-bg) !important;
        color: var(--sidebar-hover-text) !important; 
        border-right: 4px solid var(--sidebar-border) !important;
    }
    html[dir="ltr"] #sidebar-wrapper .list-group-item:hover, html[dir="ltr"] .active-link {
        border-right: none !important;
        border-left: 4px solid var(--sidebar-border) !important;
    }
    
    /* Overriding Primary Bootstrap Colors */
    .bg-primary { background-color: var(--btn-primary-bg) !important; }
    .text-primary { color: var(--btn-primary-bg) !important; }
    .btn-primary {
        background-color: var(--btn-primary-bg) !important;
        border-color: var(--btn-primary-border) !important;
        color: #fff !important;
    }
    .btn-primary:hover {
        background-color: var(--btn-primary-hover-bg) !important;
        border-color: var(--btn-primary-hover-border) !important;
    }
    .border-primary { border-color: var(--btn-primary-bg) !important; }

    /* Modern Dashboard Aesthetic (NeoBox) */
    .neobox {
        background: var(--card-bg) !important;
        border-radius: 15px;
        box-shadow: var(--card-shadow) !important;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid var(--card-border) !important;
        color: var(--text-main) !important;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .neobox:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.08);
    }
    
    /* General Theme Overrides */
    .navbar {
        background-color: var(--navbar-bg) !important;
        color: var(--navbar-text) !important;
    }
    .navbar #menu-toggle, .navbar span.text-primary {
        color: var(--navbar-text) !important;
    }
    .card {
        background-color: var(--card-bg) !important;
        border-color: var(--card-border) !important;
        color: var(--text-main) !important;
    }
    .card-body, .card-title, .card-text {
        color: var(--text-main) !important;
    }
    .modal-content {
        background-color: var(--card-bg) !important;
        border-color: var(--card-border) !important;
        color: var(--text-main) !important;
    }
    .modal-header {
        background-color: var(--sidebar-bg) !important;
        color: #fff !important;
        border-bottom-color: var(--card-border) !important;
    }
    .modal-footer {
        border-top-color: var(--card-border) !important;
    }
    .table {
        --bs-table-bg: var(--card-bg) !important;
        --bs-table-color: var(--text-main) !important;
        --bs-table-hover-color: var(--text-main) !important;
        --bs-table-hover-bg: var(--table-hover-bg) !important;
        color: var(--text-main) !important;
        border-color: var(--card-border) !important;
    }
    .table th, .table td {
        color: var(--text-main) !important;
        background-color: var(--card-bg) !important;
        border-bottom-color: var(--card-border) !important;
    }
    .table-light {
        --bs-table-bg: var(--table-header-bg) !important;
        --bs-table-color: var(--text-main) !important;
    }
    
    /* Inputs & forms */
    .form-control, .form-select {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    .form-control:focus, .form-select:focus {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        box-shadow: 0 0 0 0.25rem rgba(40, 77, 61, 0.25) !important;
    }
    .form-label, label {
        color: var(--text-main) !important;
    }
    .bg-light {
        background-color: var(--table-header-bg) !important;
        color: var(--text-main) !important;
        border-color: var(--card-border) !important;
    }
    .text-dark {
        color: var(--text-main) !important;
    }
    .text-muted {
        color: var(--text-muted) !important;
    }
    
    
    /* Stat Cards text overlay fallback for dark theme */
    .stat-card {
        border-radius: 15px;
        overflow: hidden;
        border: none;
        position: relative;
        z-index: 1;
        padding: 25px;
        height: 100%;
    }
    
    /* Theme Dropdown switcher theme-dot styling */
    .theme-dot {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        display: inline-block;
        vertical-align: middle;
    }

    /* Select2 override for Dark and Light Themes */
    .select2-container .select2-selection--single {
        background-color: var(--input-bg) !important;
        border-color: var(--input-border) !important;
        height: 38px;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 38px;
        color: var(--input-text) !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px;
    }
    .select2-dropdown {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: var(--primary) !important;
        color: #fff !important;
    }
    .select2-container--default .select2-results__option[aria-selected=true] {
        background-color: var(--sidebar-hover-bg) !important;
        color: var(--text-main) !important;
    }

    .dropdown-menu {
        background-color: var(--card-bg) !important;
        border-color: var(--card-border) !important;
        color: var(--text-main) !important;
    }
    .dropdown-item {
        color: var(--text-main) !important;
    }
    .dropdown-item:hover, .dropdown-item:focus {
        background-color: var(--table-hover-bg) !important;
        color: var(--text-main) !important;
    }
    
    /* Responsive Sidebar */
    @media (min-width: 769px) {
        html[dir="rtl"] #sidebar-wrapper { right: 0; } 
        html[dir="ltr"] #sidebar-wrapper { left: 0; }
        html[dir="rtl"] #page-content-wrapper { margin-right: var(--sidebar-w); } 
        html[dir="ltr"] #page-content-wrapper { margin-left: var(--sidebar-w); }
        #menu-toggle { display: none; }
    }
    @media (max-width: 768px) {
        html[dir="rtl"] #sidebar-wrapper { right: -280px; } 
        html[dir="ltr"] #sidebar-wrapper { left: -280px; }
        html[dir="rtl"] body.active-nav #sidebar-wrapper { right: 0; } 
        html[dir="ltr"] body.active-nav #sidebar-wrapper { left: 0; }
        #page-content-wrapper { margin: 0 !important; width: 100%; }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
        body.active-nav .sidebar-overlay { display: block; }
    }
    .stat-card.bg-gradient-green {
        background: var(--bg-gradient-green-val) !important;
        color: var(--stat-card-green-text) !important;
    }
    .stat-card.bg-gradient-green .stat-value,
    .stat-card.bg-gradient-green .stat-label,
    .stat-card.bg-gradient-green .stat-icon {
        color: var(--stat-card-green-text) !important;
    }

    .stat-card.bg-gradient-gold {
        background: var(--bg-gradient-gold-val) !important;
        color: var(--stat-card-gold-text) !important;
    }
    .stat-card.bg-gradient-gold .stat-value,
    .stat-card.bg-gradient-gold .stat-label,
    .stat-card.bg-gradient-gold .stat-icon {
        color: var(--stat-card-gold-text) !important;
    }

    .stat-card.bg-gradient-blue {
        background: var(--bg-gradient-blue-val) !important;
        color: var(--stat-card-blue-text) !important;
    }
    .stat-card.bg-gradient-blue .stat-value,
    .stat-card.bg-gradient-blue .stat-label,
    .stat-card.bg-gradient-blue .stat-icon {
        color: var(--stat-card-blue-text) !important;
    }

    .stat-card.bg-gradient-red {
        background: var(--bg-gradient-red-val) !important;
        color: var(--stat-card-red-text) !important;
    }
    .stat-card.bg-gradient-red .stat-value,
    .stat-card.bg-gradient-red .stat-label,
    .stat-card.bg-gradient-red .stat-icon {
        color: var(--stat-card-red-text) !important;
    }

    .stat-card.bg-gradient-dark {
        background: var(--bg-gradient-dark-val) !important;
        color: var(--stat-card-dark-text) !important;
    }
    .stat-card.bg-gradient-dark .stat-value,
    .stat-card.bg-gradient-dark .stat-label,
    .stat-card.bg-gradient-dark .stat-icon {
        color: var(--stat-card-dark-text) !important;
    }

    .stat-card.bg-gradient-info {
        background: var(--bg-gradient-info-val) !important;
        color: var(--stat-card-info-text) !important;
    }
    .stat-card.bg-gradient-info .stat-value,
    .stat-card.bg-gradient-info .stat-label,
    .stat-card.bg-gradient-info .stat-icon {
        color: var(--stat-card-info-text) !important;
    }

    /* Heading elements explicit coloring */
    h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 {
        color: var(--text-main) !important;
    }

    /* Card header and card footer overrides */
    .card-header, .card-footer {
        background-color: var(--table-header-bg) !important;
        color: var(--text-main) !important;
        border-color: var(--card-border) !important;
    }

    /* Secondary & Outline Buttons */
    .btn-secondary {
        background-color: var(--secondary) !important;
        border-color: var(--secondary) !important;
        color: var(--btn-secondary-text) !important;
    }
    .btn-secondary:hover {
        background-color: var(--secondary-hover) !important;
        border-color: var(--secondary-hover) !important;
    }
    .btn-outline-secondary {
        border-color: var(--secondary) !important;
        color: var(--secondary) !important;
    }
    .btn-outline-secondary:hover {
        background-color: var(--secondary) !important;
        color: var(--btn-secondary-text) !important;
    }

    /* Dynamic Status & Alert overrides (Success, Danger, Warning, Info) */
    
    /* Success Overrides */
    .text-success { color: var(--status-success) !important; }
    .btn-success {
        background-color: var(--status-success) !important;
        border-color: var(--status-success) !important;
        color: var(--status-success-text) !important;
    }
    .btn-success:hover, .btn-success:focus {
        background-color: var(--status-success-hover) !important;
        border-color: var(--status-success-hover) !important;
        color: var(--status-success-text) !important;
    }
    .btn-outline-success {
        border-color: var(--status-success) !important;
        color: var(--status-success) !important;
    }
    .btn-outline-success:hover {
        background-color: var(--status-success) !important;
        color: var(--status-success-text) !important;
    }
    .bg-success { background-color: var(--status-success) !important; color: #fff !important; }
    .alert-success {
        background-color: var(--status-success-bg) !important;
        border-color: var(--status-success-border) !important;
        color: var(--status-success-text-alert) !important;
    }
    .badge.bg-success, .badge.badge-success {
        background-color: var(--status-success-bg) !important;
        color: var(--status-success) !important;
        border: 1px solid var(--status-success-border) !important;
    }

    /* Danger Overrides */
    .text-danger { color: var(--status-danger) !important; }
    .btn-danger {
        background-color: var(--status-danger) !important;
        border-color: var(--status-danger) !important;
        color: var(--status-danger-text) !important;
    }
    .btn-danger:hover, .btn-danger:focus {
        background-color: var(--status-danger-hover) !important;
        border-color: var(--status-danger-hover) !important;
        color: var(--status-danger-text) !important;
    }
    .btn-outline-danger {
        border-color: var(--status-danger) !important;
        color: var(--status-danger) !important;
    }
    .btn-outline-danger:hover {
        background-color: var(--status-danger) !important;
        color: var(--status-danger-text) !important;
    }
    .bg-danger { background-color: var(--status-danger) !important; color: #fff !important; }
    .alert-danger {
        background-color: var(--status-danger-bg) !important;
        border-color: var(--status-danger-border) !important;
        color: var(--status-danger-text-alert) !important;
    }
    .badge.bg-danger, .badge.badge-danger {
        background-color: var(--status-danger-bg) !important;
        color: var(--status-danger) !important;
        border: 1px solid var(--status-danger-border) !important;
    }

    /* Warning Overrides */
    .text-warning { color: var(--status-warning) !important; }
    .btn-warning {
        background-color: var(--status-warning) !important;
        border-color: var(--status-warning) !important;
        color: var(--status-warning-text) !important;
    }
    .btn-warning:hover, .btn-warning:focus {
        background-color: var(--status-warning-hover) !important;
        border-color: var(--status-warning-hover) !important;
        color: var(--status-warning-text) !important;
    }
    .btn-outline-warning {
        border-color: var(--status-warning) !important;
        color: var(--status-warning) !important;
    }
    .btn-outline-warning:hover {
        background-color: var(--status-warning) !important;
        color: var(--status-warning-text) !important;
    }
    .bg-warning { background-color: var(--status-warning) !important; color: var(--status-warning-text) !important; }
    .alert-warning {
        background-color: var(--status-warning-bg) !important;
        border-color: var(--status-warning-border) !important;
        color: var(--status-warning-text-alert) !important;
    }
    .badge.bg-warning, .badge.badge-warning {
        background-color: var(--status-warning-bg) !important;
        color: var(--status-warning) !important;
        border: 1px solid var(--status-warning-border) !important;
    }

    /* Info Overrides */
    .text-info { color: var(--status-info) !important; }
    .btn-info {
        background-color: var(--status-info) !important;
        border-color: var(--status-info) !important;
        color: var(--status-info-text) !important;
    }
    .btn-info:hover, .btn-info:focus {
        background-color: var(--status-info-hover) !important;
        border-color: var(--status-info-hover) !important;
        color: var(--status-info-text) !important;
    }
    .btn-outline-info {
        border-color: var(--status-info) !important;
        color: var(--status-info) !important;
    }
    .btn-outline-info:hover {
        background-color: var(--status-info) !important;
        color: var(--status-info-text) !important;
    }
    .bg-info { background-color: var(--status-info) !important; color: #fff !important; }
    .alert-info {
        background-color: var(--status-info-bg) !important;
        border-color: var(--status-info-border) !important;
        color: var(--status-info-text-alert) !important;
    }
    .badge.bg-info, .badge.badge-info {
        background-color: var(--status-info-bg) !important;
        color: var(--status-info) !important;
        border: 1px solid var(--status-info-border) !important;
    }

    /* Pagination Overrides */
    .page-link {
        background-color: var(--card-bg) !important;
        color: var(--primary) !important;
        border-color: var(--card-border) !important;
    }
    .page-item.active .page-link {
        background-color: var(--primary) !important;
        border-color: var(--primary) !important;
        color: #fff !important;
    }

    /* Voice Search Mic Panels Theme-responsive overrides */
    .sticky-top .card.bg-dark,
    .sticky-top .card[style*="linear-gradient"],
    .card.mb-3.bg-dark[style*="linear-gradient"],
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] {
        background: var(--bg-gradient-green-val) !important;
        color: var(--stat-card-green-text) !important;
        border-color: var(--card-border) !important;
    }
    .sticky-top .card.bg-dark #status_text,
    .sticky-top .card.bg-dark b,
    .sticky-top .card.bg-dark small,
    .sticky-top .card.bg-dark span,
    .card.mb-3.bg-dark[style*="linear-gradient"] #status_text,
    .card.mb-3.bg-dark[style*="linear-gradient"] b,
    .card.mb-3.bg-dark[style*="linear-gradient"] small,
    .card.mb-3.bg-dark[style*="linear-gradient"] span,
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] #status_text,
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] b,
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] small,
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] span {
        color: var(--stat-card-green-text) !important;
    }
    .sticky-top .card.bg-dark #mic_btn.btn-light,
    .card.mb-3.bg-dark[style*="linear-gradient"] #mic_btn.btn-light,
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] #mic_btn.btn-light,
    .sticky-top .card.bg-dark #mic_btn.btn-warning,
    .card.mb-3.bg-dark[style*="linear-gradient"] #mic_btn.btn-warning,
    .card.mb-3.bg-dark[style*="linear-gradient(135deg"] #mic_btn.btn-warning {
        background-color: var(--card-bg) !important;
        color: var(--primary) !important;
        border-color: var(--card-border) !important;
    }

    /* Print overrides to enforce high contrast and prevent wasting ink in dark themes */
    @media print {
        :root, html, body {
            --bg: #ffffff !important;
            --card-bg: #ffffff !important;
            --text-main: #000000 !important;
            --text-muted: #555555 !important;
            --primary: #284D3D !important;
            --secondary: #D4AC0D !important;
            background: #ffffff !important;
            background-color: #ffffff !important;
            color: #000000 !important;
        }
        .card, .modal-content, .table, .table td, .table th, thead, tbody, tr, td, th {
            background: #ffffff !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            border-color: #000000 !important;
        }
        #sidebar-wrapper, .navbar, #themeDropdown, .lang-btn, .d-print-none, .btn, button {
            display: none !important;
        }
    }
</style>
