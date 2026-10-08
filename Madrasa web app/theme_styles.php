<?php
$current_lang = $lang ?? 'ur';
?>
<style>
    /* ==========================================================================
       THEME (DEFAULT): عاجی زمرد و شاہی وقار (Image 3: Ivory Parchment & Sage Emerald - DEFAULT)
       ========================================================================== */
    :root, html.theme-parchment-sage {
        --primary: #1B4332;
        --secondary: #B89230;
        --bg: #F7F2EA;
        --card-bg: #FFFFFF;
        --card-border: #E8E2D5;
        --card-shadow: 0 4px 14px rgba(45, 55, 72, 0.04);
        --text-main: #1F2937;
        --text-muted: #6B7280;
        --sidebar-bg: #FAF6F0;
        --sidebar-text: #2D3748;
        --sidebar-hover-bg: rgba(27, 67, 50, 0.08);
        --sidebar-border: #2D6A4F;
        --sidebar-submenu-bg: #FFFFFF;
        --sidebar-submenu-text: #143326;
        --sidebar-submenu-border: #D1E2D7;
        --sidebar-submenu-hover-bg: #EAF4EE;
        --sidebar-submenu-hover-text: #0D241B;
        --sidebar-submenu-shadow: 0 4px 14px rgba(27, 67, 50, 0.08);
        --navbar-bg: #E6ECE4;
        --navbar-text: #1B4332;
        --table-header-bg: #F2EFE9;
        --table-hover-bg: rgba(27, 67, 50, 0.04);
        --input-bg: #FFFFFF;
        --input-text: #1F2937;
        --input-border: #D5CFC2;
        --btn-primary-bg: #1B4332;
        --btn-primary-border: #1B4332;
        --btn-primary-hover-bg: #143326;
        --btn-primary-hover-border: #143326;
        --sidebar-w: 260px;

        /* Stat card variables */
        --bg-gradient-green-val: #EAF4EE;
        --bg-gradient-gold-val: #FDF6E9;
        --bg-gradient-blue-val: #EFF6FF;
        --bg-gradient-red-val: #FEF2F2;
        --bg-gradient-dark-val: #F9F6F0;
        --bg-gradient-info-val: #F0FDF4;

        --stat-card-green-text: #1B4332;
        --stat-card-gold-text: #78350F;
        --stat-card-blue-text: #1E3A8A;
        --stat-card-red-text: #991B1B;
        --stat-card-dark-text: #292524;
        --stat-card-info-text: #14532D;

        /* Secondary Buttons */
        --secondary-hover: #9E7B23;
        --btn-secondary-text: #FFFFFF;

        /* Status & Alert elements */
        --status-success: #2D6A4F;
        --status-success-hover: #1B4332;
        --status-success-text: #FFFFFF;
        --status-success-bg: #EAF4EE;
        --status-success-border: #B7E4C7;
        --status-success-text-alert: #1B4332;

        --status-danger: #D32F2F;
        --status-danger-hover: #B71C1C;
        --status-danger-text: #FFFFFF;
        --status-danger-bg: #FFEBEE;
        --status-danger-border: #FFCDD2;
        --status-danger-text-alert: #C62828;

        --status-warning: #B89230;
        --status-warning-hover: #8C6D1F;
        --status-warning-text: #FFFFFF;
        --status-warning-bg: #FEF9E7;
        --status-warning-border: #F9E79F;
        --status-warning-text-alert: #7D6608;

        --status-info: #2B6CB0;
        --status-info-hover: #2C5282;
        --status-info-text: #FFFFFF;
        --status-info-bg: #EBF8FF;
        --status-info-border: #BEE3F8;
        --status-info-text-alert: #2B6CB0;
    }

    /* ==========================================================================
       THEME: نرم نیلم و فیروزی لطافت (Image 1: Pastel Sky Breeze)
       ========================================================================== */
    html.theme-pastel-sky {
        --primary: #0284C7;
        --secondary: #06B6D4;
        --bg: #EDF4F9;
        --card-bg: #FFFFFF;
        --card-border: #E0EBF3;
        --card-shadow: 0 4px 16px rgba(2, 132, 199, 0.06);
        --text-main: #1E293B;
        --text-muted: #64748B;
        --sidebar-bg: #FFFFFF;
        --sidebar-text: #334155;
        --sidebar-hover-bg: rgba(2, 132, 199, 0.08);
        --sidebar-border: #0284C7;
        --sidebar-submenu-bg: #FFFFFF;
        --sidebar-submenu-text: #0C4A6E;
        --sidebar-submenu-border: #BAE6FD;
        --sidebar-submenu-hover-bg: #E0F2FE;
        --sidebar-submenu-hover-text: #0369A1;
        --sidebar-submenu-shadow: 0 4px 14px rgba(2, 132, 199, 0.08);
        --navbar-bg: #DDEAF2;
        --navbar-text: #0369A1;
        --table-header-bg: #F0F6FA;
        --table-hover-bg: rgba(2, 132, 199, 0.04);
        --input-bg: #FFFFFF;
        --input-text: #1E293B;
        --input-border: #BAE6FD;
        --btn-primary-bg: #0284C7;
        --btn-primary-border: #0284C7;
        --btn-primary-hover-bg: #0369A1;
        --btn-primary-hover-border: #0369A1;

        --bg-gradient-green-val: #D8EDFA;
        --bg-gradient-gold-val: #FFF1DB;
        --bg-gradient-blue-val: #DBF1F1;
        --bg-gradient-red-val: #FCE3E2;
        --bg-gradient-dark-val: #F3F4F6;
        --bg-gradient-info-val: #DCF5E9;

        --stat-card-green-text: #0C4A6E;
        --stat-card-gold-text: #78350F;
        --stat-card-blue-text: #134E4A;
        --stat-card-red-text: #9F1239;
        --stat-card-dark-text: #1F2937;
        --stat-card-info-text: #064E3B;

        --secondary-hover: #0891B2;
        --btn-secondary-text: #FFFFFF;

        --status-success: #059669;
        --status-success-hover: #047857;
        --status-success-text: #FFFFFF;
        --status-success-bg: #ECFDF5;
        --status-success-border: #A7F3D0;
        --status-success-text-alert: #065F46;

        --status-danger: #E11D48;
        --status-danger-hover: #BE123C;
        --status-danger-text: #FFFFFF;
        --status-danger-bg: #FFF1F2;
        --status-danger-border: #FECDD3;
        --status-danger-text-alert: #9F1239;

        --status-warning: #D97706;
        --status-warning-hover: #B45309;
        --status-warning-text: #FFFFFF;
        --status-warning-bg: #FFFBEB;
        --status-warning-border: #FDE68A;
        --status-warning-text-alert: #92400E;

        --status-info: #0284C7;
        --status-info-hover: #0369A1;
        --status-info-text: #FFFFFF;
        --status-info-bg: #F0F9FF;
        --status-info-border: #BAE6FD;
        --status-info-text-alert: #075985;
    }

    /* ==========================================================================
       THEME: شاہی قہوہ و صندل (Image 2: Dark Espresso & Warm Cream)
       ========================================================================== */
    html.theme-espresso-cream {
        --primary: #C89D5C;
        --secondary: #D4AF37;
        --bg: #181613;
        --card-bg: #24201A;
        --card-border: #38322A;
        --card-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
        --text-main: #F5EFE6;
        --text-muted: #A89F91;
        --sidebar-bg: #1A1714;
        --sidebar-text: #D5CCC0;
        --sidebar-hover-bg: rgba(200, 157, 92, 0.12);
        --sidebar-border: #C89D5C;
        --sidebar-submenu-bg: #28231D;
        --sidebar-submenu-text: #FAF5EE;
        --sidebar-submenu-border: rgba(200, 157, 92, 0.35);
        --sidebar-submenu-hover-bg: rgba(200, 157, 92, 0.22);
        --sidebar-submenu-hover-text: #FFDF9E;
        --sidebar-submenu-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
        --navbar-bg: #231F1A;
        --navbar-text: #F5EFE6;
        --table-header-bg: #1F1C18;
        --table-hover-bg: rgba(200, 157, 92, 0.05);
        --input-bg: #1E1A16;
        --input-text: #F5EFE6;
        --input-border: rgba(200, 157, 92, 0.25);
        --btn-primary-bg: #C89D5C;
        --btn-primary-border: #C89D5C;
        --btn-primary-hover-bg: #B38947;
        --btn-primary-hover-border: #B38947;

        --bg-gradient-green-val: #EFE9DF;
        --bg-gradient-gold-val: #F2EDE3;
        --bg-gradient-blue-val: #EAE2D7;
        --bg-gradient-red-val: #F3E8E4;
        --bg-gradient-dark-val: #EDE5D8;
        --bg-gradient-info-val: #EDE5D8;

        --stat-card-green-text: #1C1916;
        --stat-card-gold-text: #1C1916;
        --stat-card-blue-text: #1C1916;
        --stat-card-red-text: #1C1916;
        --stat-card-dark-text: #1C1916;
        --stat-card-info-text: #1C1916;

        --secondary-hover: #C89D5C;
        --btn-secondary-text: #181613;

        --status-success: #10B981;
        --status-success-hover: #059669;
        --status-success-text: #181613;
        --status-success-bg: rgba(16, 185, 129, 0.15);
        --status-success-border: rgba(16, 185, 129, 0.35);
        --status-success-text-alert: #34D399;

        --status-danger: #F87171;
        --status-danger-hover: #EF4444;
        --status-danger-text: #181613;
        --status-danger-bg: rgba(248, 113, 113, 0.15);
        --status-danger-border: rgba(248, 113, 113, 0.35);
        --status-danger-text-alert: #FCA5A5;

        --status-warning: #FBBF24;
        --status-warning-hover: #F59E0B;
        --status-warning-text: #181613;
        --status-warning-bg: rgba(251, 191, 36, 0.15);
        --status-warning-border: rgba(251, 191, 36, 0.35);
        --status-warning-text-alert: #FCD34D;

        --status-info: #38BDF8;
        --status-info-hover: #0EA5E9;
        --status-info-text: #181613;
        --status-info-bg: rgba(56, 189, 248, 0.15);
        --status-info-border: rgba(56, 189, 248, 0.35);
        --status-info-text-alert: #7DD3FC;
    }

    /* ==========================================================================
       THEME: کلاسک ادارہ جاتی زمرد (Classic Royal Emerald)
       ========================================================================== */
    html.theme-classic-emerald {
        --primary: #133E2F;
        --secondary: #B89230;
        --bg: #F8F9FA;
        --card-bg: #FFFFFF;
        --card-border: #EAEAEA;
        --card-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
        --text-main: #1F2937;
        --text-muted: #6B7280;
        --sidebar-bg: #133E2F;
        --sidebar-text: #E2ECE7;
        --sidebar-border: #2E7D32;
        --sidebar-submenu-bg: #0C281E;
        --sidebar-submenu-text: #F0FDF4;
        --sidebar-submenu-border: rgba(184, 146, 48, 0.4);
        --sidebar-submenu-hover-bg: rgba(184, 146, 48, 0.18);
        --sidebar-submenu-hover-text: #FFD700;
        --sidebar-submenu-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
        --navbar-bg: #FFFFFF;
        --navbar-text: #133E2F;
        --table-header-bg: #F9FAFB;
        --table-hover-bg: #F3F4F6;
        --input-bg: #FFFFFF;
        --input-text: #1F2937;
        --input-border: #D1D5DB;
        --btn-primary-bg: #133E2F;
        --btn-primary-border: #133E2F;
        --btn-primary-hover-bg: #0E2C21;
        --btn-primary-hover-border: #0E2C21;

        --bg-gradient-green-val: linear-gradient(135deg, #133E2F 0%, #0E2C21 100%);
        --bg-gradient-gold-val: linear-gradient(135deg, #B89230 0%, #8C6D1F 100%);
        --bg-gradient-blue-val: linear-gradient(135deg, #1F5243 0%, #133E2F 100%);
        --bg-gradient-red-val: linear-gradient(135deg, #B95944 0%, #94402E 100%);
        --bg-gradient-dark-val: linear-gradient(135deg, #1A2E26 0%, #0E1B16 100%);
        --bg-gradient-info-val: linear-gradient(135deg, #246B54 0%, #184A3A 100%);

        --stat-card-green-text: #ffffff;
        --stat-card-gold-text: #ffffff;
        --stat-card-blue-text: #ffffff;
        --stat-card-red-text: #ffffff;
        --stat-card-dark-text: #ffffff;
        --stat-card-info-text: #ffffff;

        --secondary-hover: #9E7B23;
        --btn-secondary-text: #FFFFFF;

        --status-success: #2E7D32;
        --status-success-hover: #1B5E20;
        --status-success-text: #FFFFFF;
        --status-success-bg: #E8F5E9;
        --status-success-border: #C8E6C9;
        --status-success-text-alert: #1B5E20;

        --status-danger: #D32F2F;
        --status-danger-hover: #B71C1C;
        --status-danger-text: #FFFFFF;
        --status-danger-bg: #FFEBEE;
        --status-danger-border: #FFCDD2;
        --status-danger-text-alert: #C62828;

        --status-warning: #B89230;
        --status-warning-hover: #8C6D1F;
        --status-warning-text: #FFFFFF;
        --status-warning-bg: #FEF9E7;
        --status-warning-border: #F9E79F;
        --status-warning-text-alert: #7D6608;

        --status-info: #3A8275;
        --status-info-hover: #295F55;
        --status-info-text: #FFFFFF;
        --status-info-bg: #E0F2F1;
        --status-info-border: #B2DFDB;
        --status-info-text-alert: #004D40;
    }

    /* ==========================================================================
       THEME 2: اورورا فراسٹیڈ گلاس (Image 2: Frosted Aurora Glassmorphism)
       ========================================================================== */
    html.theme-aurora-glass {
        --primary: #047857;
        --secondary: #10B981;
        --bg: #F2F6FA;
        --card-bg: rgba(255, 255, 255, 0.76);
        --card-border: rgba(255, 255, 255, 0.8);
        --card-shadow: 0 10px 30px rgba(31, 38, 135, 0.05);
        --text-main: #1E293B;
        --text-muted: #64748B;
        --sidebar-bg: rgba(255, 255, 255, 0.68);
        --sidebar-text: #334155;
        --sidebar-border: #10B981;
        --sidebar-submenu-bg: rgba(255, 255, 255, 0.92);
        --sidebar-submenu-text: #064E3B;
        --sidebar-submenu-border: rgba(16, 185, 129, 0.35);
        --sidebar-submenu-hover-bg: rgba(16, 185, 129, 0.14);
        --sidebar-submenu-hover-text: #022C22;
        --sidebar-submenu-shadow: 0 8px 24px rgba(16, 185, 129, 0.12);
        --navbar-bg: rgba(255, 255, 255, 0.78);
        --navbar-text: #047857;
        --table-header-bg: rgba(255, 255, 255, 0.6);
        --table-hover-bg: rgba(16, 185, 129, 0.04);
        --input-bg: rgba(255, 255, 255, 0.85);
        --input-text: #1E293B;
        --input-border: rgba(16, 185, 129, 0.25);
        --btn-primary-bg: #059669;
        --btn-primary-border: #059669;
        --btn-primary-hover-bg: #047857;
        --btn-primary-hover-border: #047857;

        --bg-gradient-green-val: linear-gradient(135deg, #10B981 0%, #059669 100%);
        --bg-gradient-gold-val: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
        --bg-gradient-blue-val: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
        --bg-gradient-red-val: linear-gradient(135deg, #F43F5E 0%, #E11D48 100%);
        --bg-gradient-dark-val: linear-gradient(135deg, #475569 0%, #334155 100%);
        --bg-gradient-info-val: linear-gradient(135deg, #06B6D4 0%, #0891B2 100%);

        --stat-card-green-text: #ffffff;
        --stat-card-gold-text: #ffffff;
        --stat-card-blue-text: #ffffff;
        --stat-card-red-text: #ffffff;
        --stat-card-dark-text: #ffffff;
        --stat-card-info-text: #ffffff;

        --secondary-hover: #059669;
        --btn-secondary-text: #ffffff;

        --status-success: #10B981;
        --status-success-hover: #059669;
        --status-success-text: #ffffff;
        --status-success-bg: rgba(16, 185, 129, 0.15);
        --status-success-border: rgba(16, 185, 129, 0.3);
        --status-success-text-alert: #047857;

        --status-danger: #F43F5E;
        --status-danger-hover: #E11D48;
        --status-danger-text: #ffffff;
        --status-danger-bg: rgba(244, 63, 94, 0.12);
        --status-danger-border: rgba(244, 63, 94, 0.3);
        --status-danger-text-alert: #BE123C;

        --status-warning: #F59E0B;
        --status-warning-hover: #D97706;
        --status-warning-text: #ffffff;
        --status-warning-bg: rgba(245, 158, 11, 0.12);
        --status-warning-border: rgba(245, 158, 11, 0.3);
        --status-warning-text-alert: #B45309;

        --status-info: #06B6D4;
        --status-info-hover: #0891B2;
        --status-info-text: #ffffff;
        --status-info-bg: rgba(6, 182, 212, 0.12);
        --status-info-border: rgba(6, 182, 212, 0.3);
        --status-info-text-alert: #0E7490;
    }

    /* ==========================================================================
       THEME 3: سافٹ اسلیٹ نیومورفزم (Image 3: Soft Slate Minimal Neumorphic)
       ========================================================================== */
    html.theme-soft-neumorphic {
        --primary: #10B981;
        --secondary: #64748B;
        --bg: #E9EEF4;
        --card-bg: #E9EEF4;
        --card-border: transparent;
        --card-shadow: 8px 8px 18px rgba(166, 180, 200, 0.42), -8px -8px 18px rgba(255, 255, 255, 0.95);
        --text-main: #1E293B;
        --text-muted: #64748B;
        --sidebar-bg: #E9EEF4;
        --sidebar-text: #475569;
        --sidebar-border: #10B981;
        --sidebar-submenu-bg: #FFFFFF;
        --sidebar-submenu-text: #0F172A;
        --sidebar-submenu-border: #D5DEE8;
        --sidebar-submenu-hover-bg: #EEF2F6;
        --sidebar-submenu-hover-text: #059669;
        --sidebar-submenu-shadow: 0 4px 14px rgba(166, 180, 200, 0.25);
        --navbar-bg: #E9EEF4;
        --navbar-text: #1E293B;
        --table-header-bg: rgba(166, 180, 200, 0.15);
        --table-hover-bg: rgba(255, 255, 255, 0.4);
        --input-bg: #E9EEF4;
        --input-text: #1E293B;
        --input-border: transparent;
        --btn-primary-bg: #10B981;
        --btn-primary-border: #10B981;
        --btn-primary-hover-bg: #059669;
        --btn-primary-hover-border: #059669;

        --bg-gradient-green-val: linear-gradient(135deg, #10B981 0%, #059669 100%);
        --bg-gradient-gold-val: linear-gradient(135deg, #EAB308 0%, #CA8A04 100%);
        --bg-gradient-blue-val: linear-gradient(135deg, #475569 0%, #334155 100%);
        --bg-gradient-red-val: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
        --bg-gradient-dark-val: linear-gradient(135deg, #334155 0%, #1E293B 100%);
        --bg-gradient-info-val: linear-gradient(135deg, #64748B 0%, #475569 100%);

        --stat-card-green-text: #ffffff;
        --stat-card-gold-text: #ffffff;
        --stat-card-blue-text: #ffffff;
        --stat-card-red-text: #ffffff;
        --stat-card-dark-text: #ffffff;
        --stat-card-info-text: #ffffff;

        --secondary-hover: #475569;
        --btn-secondary-text: #ffffff;

        --status-success: #10B981;
        --status-success-hover: #059669;
        --status-success-text: #ffffff;
        --status-success-bg: rgba(16, 185, 129, 0.15);
        --status-success-border: rgba(16, 185, 129, 0.3);
        --status-success-text-alert: #047857;

        --status-danger: #EF4444;
        --status-danger-hover: #DC2626;
        --status-danger-text: #ffffff;
        --status-danger-bg: rgba(239, 68, 68, 0.12);
        --status-danger-border: rgba(239, 68, 68, 0.3);
        --status-danger-text-alert: #B91C1C;

        --status-warning: #F59E0B;
        --status-warning-hover: #D97706;
        --status-warning-text: #ffffff;
        --status-warning-bg: rgba(245, 158, 11, 0.12);
        --status-warning-border: rgba(245, 158, 11, 0.3);
        --status-warning-text-alert: #B45309;

        --status-info: #64748B;
        --status-info-hover: #475569;
        --status-info-text: #ffffff;
        --status-info-bg: rgba(100, 116, 139, 0.12);
        --status-info-border: rgba(100, 116, 139, 0.3);
        --status-info-text-alert: #334155;
    }

    /* ==========================================================================
       THEME 4: لگژری اوبسیڈین سائبر (Image 4: Luxury Obsidian Cyber Glow)
       ========================================================================== */
    html.theme-obsidian-glow {
        --primary: #00F2FE;
        --secondary: #FF6584;
        --bg: #0D1117;
        --card-bg: rgba(22, 27, 38, 0.88);
        --card-border: rgba(255, 255, 255, 0.08);
        --card-shadow: 0 10px 25px rgba(0, 0, 0, 0.45);
        --text-main: #F8FAFC;
        --text-muted: #8F9CAE;
        --sidebar-bg: #10141D;
        --sidebar-text: #8F9CAE;
        --sidebar-border: #00F2FE;
        --sidebar-submenu-bg: #151A24;
        --sidebar-submenu-text: #FFFFFF;
        --sidebar-submenu-border: rgba(0, 242, 254, 0.35);
        --sidebar-submenu-hover-bg: rgba(0, 242, 254, 0.16);
        --sidebar-submenu-hover-text: #00F2FE;
        --sidebar-submenu-shadow: 0 6px 18px rgba(0, 0, 0, 0.6), inset 0 0 10px rgba(0, 242, 254, 0.06);
        --navbar-bg: #10141D;
        --navbar-text: #F8FAFC;
        --table-header-bg: #161D2B;
        --table-hover-bg: rgba(0, 242, 254, 0.04);
        --input-bg: #161D2B;
        --input-text: #F8FAFC;
        --input-border: rgba(255, 255, 255, 0.12);
        --btn-primary-bg: #00F2FE;
        --btn-primary-border: #00F2FE;
        --btn-primary-hover-bg: #00C9A7;
        --btn-primary-hover-border: #00C9A7;

        --bg-gradient-green-val: linear-gradient(135deg, #00F2FE 0%, #00C9A7 100%);
        --bg-gradient-gold-val: linear-gradient(135deg, #F9D423 0%, #FF4E50 100%);
        --bg-gradient-blue-val: linear-gradient(135deg, #1F2937 0%, #111827 100%);
        --bg-gradient-red-val: linear-gradient(135deg, #FF6584 0%, #FF758C 100%);
        --bg-gradient-dark-val: linear-gradient(135deg, #1F2937 0%, #0D1117 100%);
        --bg-gradient-info-val: linear-gradient(135deg, #00C9A7 0%, #047857 100%);

        --stat-card-green-text: #0D1117;
        --stat-card-gold-text: #0D1117;
        --stat-card-blue-text: #F8FAFC;
        --stat-card-red-text: #FFFFFF;
        --stat-card-dark-text: #F8FAFC;
        --stat-card-info-text: #0D1117;

        --secondary-hover: #FF4E50;
        --btn-secondary-text: #FFFFFF;

        --status-success: #00F2FE;
        --status-success-hover: #00C9A7;
        --status-success-text: #0D1117;
        --status-success-bg: rgba(0, 242, 254, 0.15);
        --status-success-border: rgba(0, 242, 254, 0.35);
        --status-success-text-alert: #00F2FE;

        --status-danger: #FF6584;
        --status-danger-hover: #FF4E50;
        --status-danger-text: #FFFFFF;
        --status-danger-bg: rgba(255, 101, 132, 0.15);
        --status-danger-border: rgba(255, 101, 132, 0.35);
        --status-danger-text-alert: #FF6584;

        --status-warning: #F9D423;
        --status-warning-hover: #E1B12C;
        --status-warning-text: #0D1117;
        --status-warning-bg: rgba(249, 212, 35, 0.15);
        --status-warning-border: rgba(249, 212, 35, 0.35);
        --status-warning-text-alert: #F9D423;

        --status-info: #00C9A7;
        --status-info-hover: #00B4D8;
        --status-info-text: #0D1117;
        --status-info-bg: rgba(0, 201, 167, 0.15);
        --status-info-border: rgba(0, 201, 167, 0.35);
        --status-info-text-alert: #00C9A7;
    }

    /* ==========================================================================
       THEME 5: شاہی صندل و ریتلا نیومورفزم (Image 5: Royal Sand & Cream Neumorphic)
       ========================================================================== */
    html.theme-warm-sand {
        --primary: #0284C7;
        --secondary: #F97316;
        --bg: #F5F0E6;
        --card-bg: #FAF7F2;
        --card-border: rgba(255, 255, 255, 0.7);
        --card-shadow: 6px 6px 16px rgba(210, 198, 180, 0.42), -6px -6px 16px rgba(255, 255, 255, 0.95);
        --text-main: #2C2621;
        --text-muted: #786F66;
        --sidebar-bg: #F5F0E6;
        --sidebar-text: #595147;
        --sidebar-border: #0284C7;
        --sidebar-submenu-bg: #FFFDF9;
        --sidebar-submenu-text: #1C1917;
        --sidebar-submenu-border: #E8DEC8;
        --sidebar-submenu-hover-bg: #F5EBE1;
        --sidebar-submenu-hover-text: #B45309;
        --sidebar-submenu-shadow: 0 4px 14px rgba(180, 140, 90, 0.12);
        --navbar-bg: #F5F0E6;
        --navbar-text: #2C2621;
        --table-header-bg: rgba(210, 198, 180, 0.15);
        --table-hover-bg: rgba(255, 255, 255, 0.6);
        --input-bg: #F5F0E6;
        --input-text: #2C2621;
        --input-border: transparent;
        --btn-primary-bg: #0284C7;
        --btn-primary-border: #0284C7;
        --btn-primary-hover-bg: #0369A1;
        --btn-primary-hover-border: #0369A1;

        --bg-gradient-green-val: linear-gradient(135deg, #10B981 0%, #059669 100%);
        --bg-gradient-gold-val: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
        --bg-gradient-blue-val: linear-gradient(135deg, #0284C7 0%, #0369A1 100%);
        --bg-gradient-red-val: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
        --bg-gradient-dark-val: linear-gradient(135deg, #786F66 0%, #595147 100%);
        --bg-gradient-info-val: linear-gradient(135deg, #0EA5E9 0%, #0284C7 100%);

        --stat-card-green-text: #ffffff;
        --stat-card-gold-text: #ffffff;
        --stat-card-blue-text: #ffffff;
        --stat-card-red-text: #ffffff;
        --stat-card-dark-text: #ffffff;
        --stat-card-info-text: #ffffff;

        --secondary-hover: #EA580C;
        --btn-secondary-text: #ffffff;

        --status-success: #10B981;
        --status-success-hover: #059669;
        --status-success-text: #ffffff;
        --status-success-bg: rgba(16, 185, 129, 0.15);
        --status-success-border: rgba(16, 185, 129, 0.3);
        --status-success-text-alert: #047857;

        --status-danger: #EF4444;
        --status-danger-hover: #DC2626;
        --status-danger-text: #ffffff;
        --status-danger-bg: rgba(239, 68, 68, 0.12);
        --status-danger-border: rgba(239, 68, 68, 0.3);
        --status-danger-text-alert: #B91C1C;

        --status-warning: #F97316;
        --status-warning-hover: #EA580C;
        --status-warning-text: #ffffff;
        --status-warning-bg: rgba(249, 115, 22, 0.12);
        --status-warning-border: rgba(249, 115, 22, 0.3);
        --status-warning-text-alert: #C2410C;

        --status-info: #0284C7;
        --status-info-hover: #0369A1;
        --status-info-text: #ffffff;
        --status-info-bg: rgba(2, 132, 199, 0.12);
        --status-info-border: rgba(2, 132, 199, 0.3);
        --status-info-text-alert: #075985;
    }

    /* ==========================================================================
       SPECIAL VISUAL ENHANCEMENTS PER THEME (Glassmorphism & Neumorphism Overrides)
       ========================================================================== */
    /* Base Sidebar Header Elements */
    .sidebar-top-header { transition: all 0.3s ease; }
    .sidebar-logo { transition: all 0.3s ease; }
    .sidebar-app-title { transition: all 0.3s ease; }
    .sidebar-switcher-group { transition: all 0.3s ease; }
    .system-switch-btn { transition: all 0.25s ease; border: none !important; }

    /* Theme (Default): عاجی زمرد و شاہی وقار (Image 3: Ivory Parchment & Sage Emerald - DEFAULT) */
    :root #sidebar-wrapper .active-link,
    html.theme-parchment-sage #sidebar-wrapper .active-link {
        background: #E4EFE8 !important;
        color: #1B4332 !important;
        border-radius: 10px;
        margin: 2px 10px;
        border-right: 4px solid #2D6A4F !important;
        box-shadow: 0 2px 6px rgba(27, 67, 50, 0.08);
        font-weight: bold;
    }
    :root .sidebar-top-header,
    html.theme-parchment-sage .sidebar-top-header {
        background: #E8EFE6 !important;
        border-bottom: 1px solid rgba(27, 67, 50, 0.15) !important;
    }
    :root .sidebar-logo,
    html.theme-parchment-sage .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #B89230 !important;
        background-color: #FFFFFF !important;
        box-shadow: 0 4px 10px rgba(27, 67, 50, 0.12);
    }
    :root .sidebar-app-title,
    html.theme-parchment-sage .sidebar-app-title {
        color: #1B4332 !important;
    }
    :root .sidebar-switcher-group,
    html.theme-parchment-sage .sidebar-switcher-group {
        background: #DCE8DC !important;
        border: 1px solid rgba(27, 67, 50, 0.2) !important;
    }
    :root .system-switch-btn,
    html.theme-parchment-sage .system-switch-btn {
        color: #4A5568 !important;
    }
    :root .system-switch-btn.active,
    html.theme-parchment-sage .system-switch-btn.active {
        background: linear-gradient(135deg, #1B4332, #143326) !important;
        border: 1px solid #B89230 !important;
        color: #FFFFFF !important;
        box-shadow: 0 2px 6px rgba(27, 67, 50, 0.3) !important;
    }
    :root .system-switch-btn:hover:not(.active),
    html.theme-parchment-sage .system-switch-btn:hover:not(.active) {
        color: #1B4332 !important;
        background: rgba(27, 67, 50, 0.06) !important;
    }
    :root .card,
    html.theme-parchment-sage .card {
        border: 1px solid #E8E2D5 !important;
        border-radius: 16px !important;
        background: #FFFFFF !important;
    }
    :root .stat-card,
    html.theme-parchment-sage .stat-card {
        border-radius: 16px !important;
        box-shadow: 0 4px 12px rgba(45, 55, 72, 0.04) !important;
    }
    :root .stat-card.bg-gradient-green, html.theme-parchment-sage .stat-card.bg-gradient-green { border: 1px solid #34D399 !important; }
    :root .stat-card.bg-gradient-blue, html.theme-parchment-sage .stat-card.bg-gradient-blue { border: 1px solid #60A5FA !important; }
    :root .stat-card.bg-gradient-gold, html.theme-parchment-sage .stat-card.bg-gradient-gold { border: 1px solid #F59E0B !important; }
    :root .stat-card.bg-gradient-info, html.theme-parchment-sage .stat-card.bg-gradient-info { border: 1px solid #4ADE80 !important; }
    :root .stat-card.bg-gradient-primary, html.theme-parchment-sage .stat-card.bg-gradient-primary { border: 1px solid #38BDF8 !important; }
    :root .stat-card.bg-gradient-success, html.theme-parchment-sage .stat-card.bg-gradient-success { border: 1px solid #F87171 !important; }
    :root .stat-card.bg-gradient-dark, html.theme-parchment-sage .stat-card.bg-gradient-dark { border: 1px solid #D1C7B7 !important; }
    :root .stat-card.bg-gradient-red, html.theme-parchment-sage .stat-card.bg-gradient-red { border: 1px solid #FCA5A5 !important; }

    /* Theme: Pastel Sky (Image 1) */
    html.theme-pastel-sky #sidebar-wrapper .active-link {
        background: #E0F2FE !important;
        color: #0369A1 !important;
        border-radius: 10px;
        margin: 2px 10px;
        border-right: 4px solid #0284C7 !important;
        box-shadow: 0 2px 6px rgba(2, 132, 199, 0.1);
        font-weight: bold;
    }
    html.theme-pastel-sky .sidebar-top-header {
        background: #E3EEF6 !important;
        border-bottom: 1px solid #CCE0ED !important;
    }
    html.theme-pastel-sky .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #0284C7 !important;
        background-color: #FFFFFF !important;
        box-shadow: 0 4px 10px rgba(2, 132, 199, 0.15);
    }
    html.theme-pastel-sky .sidebar-app-title {
        color: #0369A1 !important;
    }
    html.theme-pastel-sky .sidebar-switcher-group {
        background: #CFE2EF !important;
        border: 1px solid rgba(2, 132, 199, 0.25) !important;
    }
    html.theme-pastel-sky .system-switch-btn {
        color: #475569 !important;
    }
    html.theme-pastel-sky .system-switch-btn.active {
        background: linear-gradient(135deg, #0284C7, #0369A1) !important;
        border: 1px solid #0284C7 !important;
        color: #FFFFFF !important;
        box-shadow: 0 2px 8px rgba(2, 132, 199, 0.3) !important;
    }
    html.theme-pastel-sky .system-switch-btn:hover:not(.active) {
        color: #0284C7 !important;
        background: rgba(2, 132, 199, 0.08) !important;
    }
    html.theme-pastel-sky .card {
        border: 1px solid #E0EBF3 !important;
        border-radius: 16px !important;
    }
    html.theme-pastel-sky .stat-card {
        border: 1px solid rgba(255, 255, 255, 0.6) !important;
        border-radius: 16px !important;
        box-shadow: 0 4px 14px rgba(2, 132, 199, 0.06) !important;
    }

    /* Theme: Dark Espresso & Warm Cream (Image 2) */
    html.theme-espresso-cream #sidebar-wrapper .active-link {
        background: #2C2620 !important;
        color: #F5EFE6 !important;
        border-radius: 10px;
        margin: 2px 10px;
        border-right: 4px solid #C89D5C !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        font-weight: bold;
    }
    html.theme-espresso-cream .sidebar-top-header {
        background: #24201A !important;
        border-bottom: 1px solid rgba(200, 157, 92, 0.25) !important;
    }
    html.theme-espresso-cream .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #C89D5C !important;
        background-color: #FFFFFF !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    }
    html.theme-espresso-cream .sidebar-app-title {
        color: #F5EFE6 !important;
    }
    html.theme-espresso-cream .sidebar-switcher-group {
        background: #151310 !important;
        border: 1px solid rgba(200, 157, 92, 0.3) !important;
    }
    html.theme-espresso-cream .system-switch-btn {
        color: #A89F91 !important;
    }
    html.theme-espresso-cream .system-switch-btn.active {
        background: linear-gradient(135deg, #C89D5C, #B38947) !important;
        border: 1px solid #EFE9DF !important;
        color: #181613 !important;
        font-weight: bold;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4) !important;
    }
    html.theme-espresso-cream .system-switch-btn:hover:not(.active) {
        color: #C89D5C !important;
        background: rgba(200, 157, 92, 0.1) !important;
    }
    html.theme-espresso-cream .card {
        border: 1px solid #38322A !important;
        border-radius: 16px !important;
        background: #24201A !important;
    }
    html.theme-espresso-cream .stat-card {
        border: 1px solid rgba(200, 157, 92, 0.2) !important;
        border-radius: 16px !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35) !important;
    }

    /* Theme: Classic Emerald (Previous Theme 1) */
    html.theme-classic-emerald #sidebar-wrapper .active-link {
        background: #1E4E3D !important;
        color: #FFFFFF !important;
        border-radius: 10px;
        margin: 2px 10px;
        border-right: none !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }
    html.theme-classic-emerald .sidebar-top-header {
        background: #0E2C21 !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
    }
    html.theme-classic-emerald .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #B89230 !important;
        background-color: #FFFFFF !important;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
    }
    html.theme-classic-emerald .sidebar-app-title {
        color: #FFFFFF !important;
    }
    html.theme-classic-emerald .sidebar-switcher-group {
        background: rgba(0, 0, 0, 0.28) !important;
        border: 1px solid rgba(184, 146, 48, 0.35) !important;
    }
    html.theme-classic-emerald .system-switch-btn {
        color: rgba(255, 255, 255, 0.7) !important;
    }
    html.theme-classic-emerald .system-switch-btn.active {
        background: linear-gradient(135deg, #133E2F, #0E2C21) !important;
        border: 1px solid #B89230 !important;
        color: #FFFFFF !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3) !important;
    }
    html.theme-classic-emerald .system-switch-btn:hover:not(.active) {
        color: #FFFFFF !important;
        background: rgba(255, 255, 255, 0.08) !important;
    }
    html.theme-classic-emerald .card {
        border: 1px solid #EAEAEA !important;
        border-radius: 16px !important;
    }

    /* Theme 2: Aurora Frosted Glass */
    html.theme-aurora-glass body {
        background: linear-gradient(135deg, #e3ecf6 0%, #f7f4ee 45%, #dcefe7 100%) fixed !important;
    }
    html.theme-aurora-glass .card,
    html.theme-aurora-glass #sidebar-wrapper,
    html.theme-aurora-glass .navbar {
        backdrop-filter: blur(20px) !important;
        -webkit-backdrop-filter: blur(20px) !important;
    }
    html.theme-aurora-glass .card {
        background: rgba(255, 255, 255, 0.72) !important;
        border: 1px solid rgba(255, 255, 255, 0.8) !important;
        border-radius: 18px !important;
        box-shadow: 0 10px 30px rgba(31, 38, 135, 0.05) !important;
    }
    html.theme-aurora-glass #sidebar-wrapper {
        background: rgba(255, 255, 255, 0.65) !important;
        border-left: 1px solid rgba(255, 255, 255, 0.8) !important;
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.06) !important;
    }
    html.theme-aurora-glass #sidebar-wrapper .active-link {
        background: rgba(16, 185, 129, 0.18) !important;
        color: #047857 !important;
        border: 1px solid rgba(16, 185, 129, 0.3) !important;
        border-radius: 12px !important;
        margin: 3px 12px;
        box-shadow: 0 0 12px rgba(16, 185, 129, 0.15);
        border-right: none !important;
    }
    html.theme-aurora-glass .sidebar-top-header {
        background: rgba(255, 255, 255, 0.45) !important;
        backdrop-filter: blur(20px) !important;
        -webkit-backdrop-filter: blur(20px) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.8) !important;
    }
    html.theme-aurora-glass .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #10B981 !important;
        background-color: #FFFFFF !important;
        box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
    }
    html.theme-aurora-glass .sidebar-app-title {
        color: #047857 !important;
        text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8) !important;
    }
    html.theme-aurora-glass .sidebar-switcher-group {
        background: rgba(255, 255, 255, 0.65) !important;
        border: 1px solid rgba(16, 185, 129, 0.3) !important;
        box-shadow: inset 0 2px 4px rgba(16, 185, 129, 0.05) !important;
    }
    html.theme-aurora-glass .system-switch-btn {
        color: #64748B !important;
    }
    html.theme-aurora-glass .system-switch-btn.active {
        background: linear-gradient(135deg, #10B981, #06B6D4) !important;
        color: #FFFFFF !important;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35) !important;
        border: none !important;
    }
    html.theme-aurora-glass .system-switch-btn:hover:not(.active) {
        color: #047857 !important;
        background: rgba(16, 185, 129, 0.1) !important;
    }

    /* Theme 3: Soft Slate Neumorphic */
    html.theme-soft-neumorphic .card {
        background: #E9EEF4 !important;
        border: none !important;
        border-radius: 18px !important;
        box-shadow: 8px 8px 18px rgba(166, 180, 200, 0.42), -8px -8px 18px rgba(255, 255, 255, 0.95) !important;
    }
    html.theme-soft-neumorphic #sidebar-wrapper {
        background: #E9EEF4 !important;
        border: none !important;
        box-shadow: 6px 0 16px rgba(166, 180, 200, 0.35) !important;
    }
    html.theme-soft-neumorphic #sidebar-wrapper .active-link {
        background: #E9EEF4 !important;
        box-shadow: inset 3px 3px 6px rgba(166, 180, 200, 0.45), inset -3px -3px 6px #ffffff !important;
        color: #10B981 !important;
        border-radius: 12px !important;
        margin: 3px 12px;
        border-right: 4px solid #10B981 !important;
    }
    html.theme-soft-neumorphic .sidebar-top-header {
        background: #E9EEF4 !important;
        border-bottom: 1px solid rgba(166, 180, 200, 0.35) !important;
    }
    html.theme-soft-neumorphic .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #E9EEF4 !important;
        background-color: #FFFFFF !important;
        box-shadow: 3px 3px 8px rgba(166, 180, 200, 0.5), -3px -3px 8px #ffffff !important;
    }
    html.theme-soft-neumorphic .sidebar-app-title {
        color: #1E293B !important;
    }
    html.theme-soft-neumorphic .sidebar-switcher-group {
        background: #E9EEF4 !important;
        box-shadow: inset 3px 3px 6px rgba(166, 180, 200, 0.5), inset -3px -3px 6px #ffffff !important;
        border: none !important;
    }
    html.theme-soft-neumorphic .system-switch-btn {
        color: #64748B !important;
    }
    html.theme-soft-neumorphic .system-switch-btn.active {
        background: #10B981 !important;
        color: #FFFFFF !important;
        box-shadow: 2px 2px 6px rgba(166, 180, 200, 0.45), -2px -2px 6px #ffffff !important;
        border: none !important;
    }
    html.theme-soft-neumorphic .system-switch-btn:hover:not(.active) {
        color: #1E293B !important;
    }
    html.theme-soft-neumorphic input.form-control,
    html.theme-soft-neumorphic select.form-select,
    html.theme-soft-neumorphic textarea.form-control {
        background: #E9EEF4 !important;
        border: none !important;
        box-shadow: inset 3px 3px 6px rgba(166, 180, 200, 0.4), inset -3px -3px 6px #ffffff !important;
        border-radius: 12px !important;
    }

    /* Theme 4: Obsidian Cyber Glow */
    html.theme-obsidian-glow .card {
        background: rgba(22, 27, 38, 0.88) !important;
        border: 1px solid rgba(255, 255, 255, 0.08) !important;
        border-radius: 16px !important;
        box-shadow: 0 10px 25px rgba(0,0,0,0.45) !important;
        backdrop-filter: blur(12px) !important;
        -webkit-backdrop-filter: blur(12px) !important;
    }
    html.theme-obsidian-glow #sidebar-wrapper {
        background: #10141D !important;
        border-color: rgba(255, 255, 255, 0.08) !important;
    }
    html.theme-obsidian-glow #sidebar-wrapper .active-link {
        background: rgba(0, 242, 254, 0.12) !important;
        color: #00F2FE !important;
        border: 1px solid #00F2FE !important;
        box-shadow: 0 0 16px rgba(0, 242, 254, 0.35) !important;
        border-radius: 10px !important;
        margin: 3px 12px;
        border-right: none !important;
    }
    html.theme-obsidian-glow .sidebar-top-header {
        background: #0D1117 !important;
        border-bottom: 1px solid rgba(0, 242, 254, 0.2) !important;
    }
    html.theme-obsidian-glow .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #00F2FE !important;
        background-color: #FFFFFF !important;
        box-shadow: 0 0 12px rgba(0, 242, 254, 0.4) !important;
    }
    html.theme-obsidian-glow .sidebar-app-title {
        color: #F8FAFC !important;
        text-shadow: 0 0 10px rgba(0, 242, 254, 0.4) !important;
    }
    html.theme-obsidian-glow .sidebar-switcher-group {
        background: #161B26 !important;
        border: 1px solid rgba(0, 242, 254, 0.3) !important;
        box-shadow: 0 0 10px rgba(0, 242, 254, 0.1) !important;
    }
    html.theme-obsidian-glow .system-switch-btn {
        color: #8F9CAE !important;
    }
    html.theme-obsidian-glow .system-switch-btn.active {
        background: linear-gradient(135deg, #00F2FE, #00C9A7) !important;
        color: #0D1117 !important;
        font-weight: 800 !important;
        box-shadow: 0 0 15px rgba(0, 242, 254, 0.6) !important;
        border: none !important;
    }
    html.theme-obsidian-glow .system-switch-btn:hover:not(.active) {
        color: #00F2FE !important;
        background: rgba(0, 242, 254, 0.08) !important;
    }
    html.theme-obsidian-glow .navbar {
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    /* Theme 5: Royal Sand Neumorphic */
    html.theme-warm-sand .card {
        background: #FAF7F2 !important;
        border: 1px solid rgba(255, 255, 255, 0.7) !important;
        border-radius: 18px !important;
        box-shadow: 6px 6px 16px rgba(210, 198, 180, 0.42), -6px -6px 16px rgba(255, 255, 255, 0.95) !important;
    }
    html.theme-warm-sand #sidebar-wrapper {
        background: #F5F0E6 !important;
        border: none !important;
        box-shadow: 6px 0 16px rgba(210, 198, 180, 0.35) !important;
    }
    html.theme-warm-sand #sidebar-wrapper .active-link {
        background: #F5F0E6 !important;
        box-shadow: inset 2px 2px 5px rgba(210, 198, 180, 0.5), inset -2px -2px 5px #ffffff !important;
        color: #0284C7 !important;
        border-radius: 12px !important;
        margin: 3px 12px;
        border-right: 4px solid #0284C7 !important;
    }
    html.theme-warm-sand .sidebar-top-header {
        background: #F5F0E6 !important;
        border-bottom: 1px solid rgba(210, 198, 180, 0.45) !important;
    }
    html.theme-warm-sand .sidebar-logo {
        width: 65px; height: 65px; object-fit: contain; border-radius: 50%;
        border: 2px solid #FAF7F2 !important;
        background-color: #FFFFFF !important;
        box-shadow: 3px 3px 8px rgba(210, 198, 180, 0.5), -3px -3px 8px #ffffff !important;
    }
    html.theme-warm-sand .sidebar-app-title {
        color: #2C2621 !important;
    }
    html.theme-warm-sand .sidebar-switcher-group {
        background: #F5F0E6 !important;
        box-shadow: inset 3px 3px 6px rgba(210, 198, 180, 0.6), inset -3px -3px 6px #ffffff !important;
        border: none !important;
    }
    html.theme-warm-sand .system-switch-btn {
        color: #786F66 !important;
    }
    html.theme-warm-sand .system-switch-btn.active {
        background: linear-gradient(135deg, #0284C7, #0369A1) !important;
        color: #FFFFFF !important;
        box-shadow: 2px 2px 6px rgba(210, 198, 180, 0.5), -2px -2px 6px #ffffff !important;
        border: none !important;
    }
    html.theme-warm-sand .system-switch-btn:hover:not(.active) {
        color: #0284C7 !important;
    }
    html.theme-warm-sand input.form-control,
    html.theme-warm-sand select.form-select,
    html.theme-warm-sand textarea.form-control {
        background: #F5F0E6 !important;
        border: none !important;
        box-shadow: inset 2px 2px 5px rgba(210, 198, 180, 0.4), inset -2px -2px 5px #ffffff !important;
        border-radius: 12px !important;
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
    
    /* Sidebar Dropdown Submenu Styling (Professional & Premium Theme-Aligned) */
    #sidebar-wrapper a[data-bs-toggle="collapse"] .fa-caret-down {
        transition: transform 0.25s ease;
    }
    #sidebar-wrapper a[data-bs-toggle="collapse"][aria-expanded="true"] .fa-caret-down,
    #sidebar-wrapper a[data-bs-toggle="collapse"]:not(.collapsed) .fa-caret-down {
        transform: rotate(180deg);
    }
    #sidebar-wrapper .sidebar-submenu {
        background: var(--sidebar-submenu-bg) !important;
        border: 1px solid var(--sidebar-submenu-border) !important;
        border-radius: 12px !important;
        margin: 4px 10px 10px 10px !important;
        padding: 6px 4px !important;
        box-shadow: var(--sidebar-submenu-shadow, none) !important;
        transition: all 0.25s ease;
    }
    #sidebar-wrapper .sidebar-submenu .list-group-item {
        color: var(--sidebar-submenu-text) !important;
        background: transparent !important;
        border: none !important;
        border-bottom: none !important;
        padding: 8px 14px 8px 18px !important;
        font-size: 13.5px !important;
        font-weight: 500 !important;
        border-radius: 8px !important;
        margin: 2px 0 !important;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        text-decoration: none !important;
    }
    #sidebar-wrapper .sidebar-submenu .list-group-item:hover {
        background: var(--sidebar-submenu-hover-bg) !important;
        color: var(--sidebar-submenu-hover-text) !important;
        border-right: none !important;
        border-left: none !important;
    }
    #sidebar-wrapper .sidebar-submenu .list-group-item.active-link {
        background: var(--sidebar-hover-bg) !important;
        color: var(--sidebar-hover-text) !important;
        border-right: 4px solid var(--sidebar-border) !important;
        font-weight: 700 !important;
    }
    html[dir="ltr"] #sidebar-wrapper .sidebar-submenu .list-group-item.active-link {
        border-right: none !important;
        border-left: 4px solid var(--sidebar-border) !important;
    }
    #sidebar-wrapper .sidebar-submenu .list-group-item i {
        width: 22px;
        text-align: center;
        margin-left: 8px;
    }
    html[dir="ltr"] #sidebar-wrapper .sidebar-submenu .list-group-item i {
        margin-left: 0;
        margin-right: 8px;
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
    .form-control, .form-select, select {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    .form-select option, select option {
        background-color: var(--card-bg, #ffffff) !important;
        color: var(--text-main, #212529) !important;
    }
    .form-control:focus, .form-select:focus, select:focus {
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
        /* Prevents whole-page horizontal layout breakage */
        html, body {
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100vw !important;
        }
        #wrapper {
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100vw !important;
        }
        #page-content-wrapper {
            margin: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        .container-fluid {
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        html[dir="rtl"] #sidebar-wrapper { right: -300px; } 
        html[dir="ltr"] #sidebar-wrapper { left: -300px; }
        html[dir="rtl"] body.active-nav #sidebar-wrapper { right: 0 !important; } 
        html[dir="ltr"] body.active-nav #sidebar-wrapper { left: 0 !important; }
        body.active-nav #sidebar-wrapper {
            z-index: 10000 !important;
            position: fixed !important;
            top: 0 !important;
            height: 100vh !important;
            height: 100dvh !important;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.7) !important;
            visibility: visible !important;
            pointer-events: auto !important;
        }
        .sidebar-overlay {
            display: none;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            height: 100dvh !important;
            background: rgba(0, 0, 0, 0.55) !important;
            backdrop-filter: blur(3px) !important;
            -webkit-backdrop-filter: blur(3px) !important;
            z-index: 9998 !important;
            cursor: pointer;
        }
        body.active-nav .sidebar-overlay { display: block !important; }

        /* Mobile table responsiveness */
        .table-responsive {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            margin-bottom: 1rem;
            border-radius: 8px;
        }
        .table-responsive > .table {
            margin-bottom: 0 !important;
        }
        .table-responsive th,
        .table-responsive td {
            white-space: nowrap !important;
            font-size: 0.88rem !important;
            padding: 8px 10px !important;
        }

        /* Mobile action buttons in tables & headers */
        .table-responsive .btn-sm {
            padding: 4px 8px !important;
            font-size: 0.8rem !important;
        }

        /* Mobile form elements touch target & typography */
        .form-control, .form-select, .select2-container .select2-selection--single {
            font-size: 15px !important;
            min-height: 40px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px !important;
            font-size: 15px !important;
        }

        /* Mobile Modals */
        .modal-dialog {
            margin: 0.5rem auto !important;
            max-width: calc(100% - 1rem) !important;
        }

        /* Stat cards scaling */
        .stat-card {
            padding: 16px !important;
            margin-bottom: 12px !important;
        }
        .stat-card .stat-value {
            font-size: 1.5rem !important;
        }
    }

    @media (max-width: 576px) {
        h2, .h2 { font-size: 1.35rem !important; }
        h3, .h3 { font-size: 1.2rem !important; }
        h4, .h4 { font-size: 1.1rem !important; }
        h5, .h5 { font-size: 1.0rem !important; }

        .btn {
            padding: 6px 12px;
            font-size: 0.88rem;
        }
        
        .navbar {
            padding-left: 8px !important;
            padding-right: 8px !important;
        }
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

    .stat-card.bg-gradient-primary {
        background: var(--bg-gradient-blue-val) !important;
        color: var(--stat-card-blue-text) !important;
    }
    .stat-card.bg-gradient-primary .stat-value,
    .stat-card.bg-gradient-primary .stat-label,
    .stat-card.bg-gradient-primary .stat-icon {
        color: var(--stat-card-blue-text) !important;
    }

    .stat-card.bg-gradient-success {
        background: var(--bg-gradient-red-val) !important;
        color: var(--stat-card-red-text) !important;
    }
    .stat-card.bg-gradient-success .stat-value,
    .stat-card.bg-gradient-success .stat-label,
    .stat-card.bg-gradient-success .stat-icon {
        color: var(--stat-card-red-text) !important;
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

    /* ==========================================================================
       THEME SWITCHER SELECTION BAR & DROPDOWN (Zero-Scroll Seamless Display)
       ========================================================================== */
    .navbar,
    .navbar .container,
    .navbar .container-fluid,
    #page-content-wrapper {
        overflow: visible !important;
    }
    .theme-dropdown-menu {
        border-radius: 20px !important;
        min-width: 320px !important;
        max-width: 95vw !important;
        z-index: 1070 !important;
        box-shadow: 0 12px 35px rgba(0,0,0,0.18) !important;
    }
    .theme-selection-bar {
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .theme-selection-bar::-webkit-scrollbar {
        display: none !important;
    }
    .theme-select-btn {
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        flex-shrink: 0;
    }
    .theme-select-btn:hover {
        transform: scale(1.15);
    }
    .theme-select-btn.active-theme-ring {
        outline: 2px solid var(--primary, #1B4332);
        outline-offset: 2px;
        transform: scale(1.12);
        box-shadow: 0 0 8px rgba(0, 0, 0, 0.25) !important;
    }

    /* Mobile: anchor dropdown directly beneath header without page/header scroll */
    @media (max-width: 576px) {
        .theme-dropdown-menu {
            position: fixed !important;
            top: 60px !important;
            left: 10px !important;
            right: 10px !important;
            width: auto !important;
            min-width: unset !important;
            max-width: none !important;
            transform: none !important;
            margin: 0 !important;
            z-index: 1095 !important;
        }
    }

    /* Responsive Single-Row Horizontal Scrollable Tabs (Never wrap on mobile) */
    .nav-tabs, .nav-pills {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        white-space: nowrap !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: thin !important;
    }
    .nav-tabs::-webkit-scrollbar, .nav-pills::-webkit-scrollbar {
        height: 4px;
    }
    .nav-tabs::-webkit-scrollbar-thumb, .nav-pills::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
    }
    .nav-tabs .nav-item, .nav-pills .nav-item {
        flex: 0 0 auto !important;
    }
    .nav-tabs .nav-link, .nav-pills .nav-link {
        white-space: nowrap !important;
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

    /* ==========================================================================
       UNIVERSAL FORM CONTROL & PLACEHOLDER HIGH-CONTRAST ACCESSIBILITY
       ========================================================================== */
    .form-control::placeholder,
    .form-select::placeholder,
    textarea::placeholder,
    input::placeholder {
        color: #94a3b8 !important;
        opacity: 0.95 !important;
    }
    .form-control::-webkit-input-placeholder,
    textarea::-webkit-input-placeholder,
    input::-webkit-input-placeholder {
        color: #94a3b8 !important;
        opacity: 0.95 !important;
    }
    .form-control::-moz-placeholder,
    textarea::-moz-placeholder,
    input::-moz-placeholder {
        color: #94a3b8 !important;
        opacity: 0.95 !important;
    }
    .form-text, .help-block, small.form-text {
        color: var(--text-muted, #94a3b8) !important;
    }
</style>
