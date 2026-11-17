<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Portal Design System & Shared Styles
   Visual standard matching approved reference design:
   media_1794935844127.jpg
   ----------------------------------------------------------------- */
?>
<!-- Web Fonts & FontAwesome -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600;1,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
:root {
    --f-font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --f-font-serif: 'Playfair Display', Georgia, serif;
    
    /* Plum & Deep Purple Sidebar Gradients */
    --f-plum-dark: #1f0529;
    --f-plum-mid: #2e0d3c;
    --f-plum-light: #3d1452;
    --f-plum-accent: #7c3aed;
    --f-plum-soft: #f5f3ff;
    
    /* Workspace Backgrounds */
    --f-bg-main: #f7f6fb;
    --f-bg-card: #ffffff;
    --f-text-dark: #1e1b4b;
    --f-text-muted: #64748b;
    
    /* Pastel KPI Palette */
    --f-peach-bg: #fff1ec;
    --f-peach-border: #fed7aa;
    --f-peach-text: #c2410c;
    
    --f-lavender-bg: #f4edfd;
    --f-lavender-border: #e9d5ff;
    --f-lavender-text: #7e22ce;
    
    --f-mint-bg: #ecfaf4;
    --f-mint-border: #a7f3d0;
    --f-mint-text: #047857;
    
    --f-sky-bg: #eff6ff;
    --f-sky-border: #bfdbfe;
    --f-sky-text: #1d4ed8;
    
    --f-rose-bg: #fff0f2;
    --f-rose-border: #fecdd3;
    --f-rose-text: #be123c;
    
    --f-gold-bg: #fdf8ea;
    --f-gold-border: #fef08a;
    --f-gold-text: #b45309;
    
    --f-sidebar-width: 255px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--f-font-sans);
    background-color: var(--f-bg-main);
    color: var(--f-text-dark);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

/* App Container */
.app {
    display: flex;
    min-height: 100vh;
    position: relative;
    width: 100%;
    overflow-x: hidden;
}

/* Sidebar */
.sidebar {
    width: var(--f-sidebar-width);
    min-height: 100vh;
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    background: linear-gradient(180deg, #3d1452 0%, #300f40 35%, #2a0b38 70%, #1f0529 100%);
    color: #ffffff;
    z-index: 1050;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 4px 0 24px rgba(23, 5, 33, 0.28);
}

.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }

/* Sidebar Branding Header */
.sidebar-brand-box {
    padding: 20px 16px 12px;
    text-align: center;
}

.sidebar-crest-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 10px;
}

.sidebar-crest-img {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    background: #ffffff;
    padding: 2px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    flex-shrink: 0;
}

.sidebar-crest-text {
    text-align: left;
}

.sidebar-crest-title {
    font-size: 0.96rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    color: #ffffff;
    line-height: 1.15;
    display: inline-block;
}

.sidebar-crest-subtitle {
    font-size: 0.65rem;
    letter-spacing: 0.16em;
    color: rgba(255, 255, 255, 0.7);
    margin-top: 2px;
    font-weight: 600;
}

.sidebar-gold-line {
    height: 1px;
    background: linear-gradient(90deg, transparent, #fbbf24 30%, #f59e0b 70%, transparent);
    margin: 4px 0 12px;
}

/* Faculty Profile Card in Sidebar */
.sidebar-profile-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    padding: 10px 12px;
    margin: 0 14px 12px;
    transition: all 0.2s ease;
}

.sidebar-profile-card:hover {
    background: rgba(255, 255, 255, 0.12);
}

.sidebar-avatar-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: #1e1b4b;
    font-weight: 800;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    flex-shrink: 0;
}

.sidebar-profile-meta {
    flex: 1;
    min-width: 0;
}

.sidebar-profile-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-profile-role {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.72);
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-profile-division {
    display: inline-block;
    background: rgba(251, 191, 36, 0.22);
    border: 1px solid rgba(251, 191, 36, 0.4);
    color: #fef08a;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
    margin-top: 3px;
    letter-spacing: 0.04em;
}

/* Sidebar Nav Container */
.sidebar-nav-container {
    flex: 1;
    padding: 6px 14px 14px;
    display: flex;
    flex-direction: column;
}

.sidebar-nav-section-title {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: rgba(255, 255, 255, 0.48);
    margin: 14px 10px 8px;
}

.sidebar-nav-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.sidebar-nav-item a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    font-size: 0.875rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.84);
    text-decoration: none;
    border-radius: 999px;
    transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.sidebar-nav-item a i {
    font-size: 1.0rem;
    width: 20px;
    text-align: center;
    color: rgba(255, 255, 255, 0.75);
    transition: color 0.18s ease;
}

.sidebar-nav-item a:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    transform: translateX(2px);
}

.sidebar-nav-item a:hover i {
    color: #fbbf24;
}

/* Active State: White Rounded Pill with Plum Text */
.sidebar-nav-item.active a {
    background: #ffffff;
    color: #3d1452 !important;
    font-weight: 700;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2);
}

.sidebar-nav-item.active a i {
    color: #3d1452 !important;
}

/* Badges on Nav items */
.sidebar-badge {
    margin-left: auto;
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}

.sidebar-badge-new {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
}

.sidebar-badge-count {
    background: #ef4444;
    color: #ffffff;
}

/* Bottom Artwork */
.sidebar-bottom-art {
    margin-top: auto;
    width: 100%;
    padding: 12px 14px 2px;
    text-align: center;
    pointer-events: none;
    opacity: 0.88;
}

.sidebar-bottom-art img {
    width: 100%;
    max-width: 210px;
    height: auto;
    display: block;
    margin: 0 auto;
    filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.25));
}

@media (max-height: 720px) {
    .sidebar-bottom-art {
        display: none;
    }
}

/* Bottom Logout Row */
.sidebar-footer-row {
    padding: 8px 14px 16px;
}

.sidebar-logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 9px 14px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 999px;
    color: rgba(255, 255, 255, 0.88);
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.sidebar-logout-btn:hover {
    background: rgba(239, 68, 68, 0.22);
    border-color: rgba(239, 68, 68, 0.4);
    color: #fca5a5;
}

/* Main Workspace Content Area */
.main {
    flex: 1;
    margin-left: var(--f-sidebar-width);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background-color: var(--f-bg-main);
    position: relative;
    padding-bottom: 40px;
    width: calc(100% - var(--f-sidebar-width));
}

/* Topbar */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 32px 14px;
    gap: 20px;
    background: transparent;
}

.topbar-left {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.topbar-greeting-name {
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--f-text-dark);
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.topbar-greeting-small {
    font-size: 0.82rem;
    font-weight: 500;
    color: #6b21a8;
}

/* Topbar Center: Quick Search */
.topbar-search-wrap {
    flex: 1;
    max-width: 440px;
    position: relative;
}

.topbar-search-input {
    width: 100%;
    padding: 10px 42px 10px 42px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    font-size: 0.88rem;
    color: var(--f-text-dark);
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    outline: none;
    transition: all 0.2s ease;
    font-family: var(--f-font-sans);
}

.topbar-search-input:focus {
    border-color: #a855f7;
    box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.15);
}

.topbar-search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.95rem;
    pointer-events: none;
}

.topbar-search-shortcut {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 6px;
    pointer-events: none;
}

/* Topbar Right: Actions */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 14px;
}

.topbar-btn {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    transition: all 0.2s ease;
    text-decoration: none;
}

.topbar-btn:hover {
    background: #f8fafc;
    color: #1e1b4b;
    border-color: #cbd5e1;
}

.topbar-btn-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #ef4444;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #ffffff;
}

/* Profile Trigger in Topbar */
.topbar-user-menu {
    position: relative;
}

.topbar-user-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 4px 14px 4px 5px;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    transition: all 0.2s ease;
}

.topbar-user-btn:hover {
    border-color: #cbd5e1;
}

.topbar-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #7c3aed, #4f46e5);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.topbar-user-name {
    font-size: 0.84rem;
    font-weight: 600;
    color: var(--f-text-dark);
}

/* Dropdown Menu */
.user-dropdown-popover {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 220px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    padding: 6px;
    z-index: 1100;
}

.user-dropdown-popover.open {
    display: block;
}

.user-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    font-size: 0.84rem;
    color: #334155;
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.15s ease;
}

.user-dropdown-item:hover {
    background: #f1f5f9;
    color: #1e1b4b;
}

.user-dropdown-item i {
    width: 18px;
    color: #64748b;
    text-align: center;
}

.user-dropdown-divider {
    height: 1px;
    background: #f1f5f9;
    margin: 4px 6px;
}

/* Mobile Off-Canvas */
.mobile-menu-toggle {
    display: none;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #1e1b4b;
    font-size: 1.1rem;
    cursor: pointer;
    align-items: center;
    justify-content: center;
}

.sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    z-index: 1040;
    backdrop-filter: blur(2px);
}

.sidebar-backdrop.show { display: block; }

@media (max-width: 992px) {
    .mobile-menu-toggle { display: inline-flex; }
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
    }
    .sidebar.open {
        transform: translateX(0);
    }
    .main {
        margin-left: 0;
        width: 100%;
    }
    .topbar {
        padding: 14px 18px;
    }
    .topbar-search-wrap {
        display: none;
    }
}
</style>
