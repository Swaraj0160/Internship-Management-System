<?php
/* -----------------------------------------------------------------
   InternHub — Student Portal Shell Design System & Styles
   Pixel-faithful reproduction of approved visual reference:
   media_1794927520336.jpg
   ----------------------------------------------------------------- */
?>
<!-- Fonts & Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600;1,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
:root {
    --ih-font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --ih-font-serif: 'Playfair Display', Georgia, serif;
    
    /* Brand Colors */
    --ih-purple-900: #20062b;
    --ih-purple-800: #2e0d3c;
    --ih-purple-700: #3b124d;
    --ih-purple-600: #581c87;
    --ih-purple-500: #7c3aed;
    --ih-purple-100: #ede9fe;
    --ih-purple-50:  #f5f3ff;
    
    /* Accents */
    --ih-gold-500: #f59e0b;
    --ih-gold-400: #fbbf24;
    --ih-gold-100: #fef3c7;
    --ih-gold-50:  #fef9e7;
    
    --ih-green-500: #10b981;
    --ih-green-100: #d1fae5;
    --ih-green-50:  #e8f7ee;
    
    --ih-blue-500: #3b82f6;
    --ih-blue-100: #dbeafe;
    --ih-blue-50:  #e8f4fd;
    
    --ih-sidebar-width: 250px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--ih-font-sans);
    background-color: #f7f6fb;
    color: #1e1b4b;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

/* Main App Layout */
.app {
    display: flex;
    min-height: 100vh;
    position: relative;
}

/* Sidebar */
.sidebar {
    width: var(--ih-sidebar-width);
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
    box-shadow: 4px 0 24px rgba(23, 5, 33, 0.25);
}

.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }

/* Sidebar Branding */
.sidebar-brand-box {
    padding: 22px 18px 14px;
    text-align: center;
}

.sidebar-crest-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 12px;
}

.sidebar-crest-img {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    background: #ffffff;
    padding: 2px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.3);
}

.sidebar-crest-text {
    text-align: left;
}

.sidebar-crest-title {
    font-size: 0.95rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #ffffff;
    line-height: 1.1;
    border-bottom: 1px solid rgba(255, 255, 255, 0.4);
    padding-bottom: 2px;
    display: inline-block;
}

.sidebar-crest-subtitle {
    font-size: 0.65rem;
    letter-spacing: 0.16em;
    color: rgba(255, 255, 255, 0.7);
    margin-top: 3px;
    font-weight: 600;
}

.sidebar-gold-line {
    height: 1px;
    background: linear-gradient(90deg, transparent, #fbbf24 30%, #f59e0b 70%, transparent);
    margin: 4px 0 14px;
}

.sidebar-product-identity {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 2px;
}

.sidebar-product-icon {
    font-size: 1.35rem;
    color: #fbbf24;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.25));
}

.sidebar-product-name {
    font-size: 1.35rem;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.02em;
}

.sidebar-product-tagline {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.75);
    margin-top: 2px;
    font-weight: 500;
}

/* Sidebar Nav */
.sidebar-nav-container {
    flex: 1;
    padding: 16px 14px 20px;
    display: flex;
    flex-direction: column;
}

.sidebar-nav-section-title {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: rgba(255, 255, 255, 0.45);
    margin: 14px 10px 8px;
}

.sidebar-nav-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sidebar-nav-item a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 11px 18px;
    font-size: 0.88rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.84);
    text-decoration: none;
    border-radius: 999px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.sidebar-nav-item a i {
    font-size: 1.05rem;
    width: 20px;
    text-align: center;
    color: rgba(255, 255, 255, 0.78);
    transition: color 0.2s ease;
}

.sidebar-nav-item a:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    transform: translateX(2px);
}

.sidebar-nav-item a:hover i {
    color: #fbbf24;
}

/* Active State: White Rounded Pill with Purple Text */
.sidebar-nav-item.active a {
    background: #ffffff;
    color: #43185b !important;
    font-weight: 700;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.16);
}

.sidebar-nav-item.active a i {
    color: #43185b !important;
}

/* Sidebar Dome Illustration at Bottom */
.sidebar-bottom-art {
    margin-top: auto;
    width: 100%;
    padding: 12px 14px 4px;
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
    filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.2));
}

@media (max-height: 720px) {
    .sidebar-bottom-art {
        display: none;
    }
}

/* Main Content Area */
.main {
    flex: 1;
    margin-left: var(--ih-sidebar-width);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background-color: #f7f6fb;
    position: relative;
    padding-bottom: 40px;
}

/* Top Bar */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 32px 14px;
    gap: 20px;
}

.topbar-left {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.topbar-greeting-small {
    font-size: 0.82rem;
    font-weight: 600;
    color: #6b21a8;
}

.topbar-greeting-name {
    font-size: 1.45rem;
    font-weight: 800;
    color: #1e1b4b;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.topbar-badges-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}

.topbar-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 12px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    background: #ede9fe;
    color: #5b21b6;
    letter-spacing: 0.02em;
}

/* Topbar Center: Search Pill */
.topbar-search-box {
    flex: 1;
    max-width: 440px;
    position: relative;
}

.topbar-search-input {
    width: 100%;
    padding: 10px 42px 10px 18px;
    border-radius: 999px;
    border: 1px solid rgba(203, 213, 225, 0.7);
    background: #ffffff;
    font-family: inherit;
    font-size: 0.86rem;
    color: #1e293b;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    outline: none;
    transition: all 0.2s ease;
}

.topbar-search-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
}

.topbar-search-icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 0.95rem;
    pointer-events: none;
}

/* Topbar Right: Notification Bell & Profile Dropdown */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.topbar-bell-btn {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    cursor: pointer;
    color: #334155;
    font-size: 1.1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: all 0.2s ease;
}

.topbar-bell-btn:hover {
    background: #f8fafc;
    color: #6d28d9;
}

.topbar-bell-dot {
    position: absolute;
    top: 10px;
    right: 11px;
    width: 8px;
    height: 8px;
    background: #ef4444;
    border-radius: 50%;
    border: 2px solid #ffffff;
}

.topbar-profile-pill {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 16px 6px 8px;
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 999px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    cursor: pointer;
    position: relative;
    user-select: none;
    transition: all 0.2s ease;
}

.topbar-profile-pill:hover {
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
}

.topbar-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    background: #ede9fe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #6d28d9;
    overflow: hidden;
}

.topbar-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.topbar-user-info {
    text-align: left;
    line-height: 1.2;
}

.topbar-user-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: #1e1b4b;
}

.topbar-user-role {
    font-size: 0.72rem;
    color: #64748b;
}

.topbar-profile-chevron {
    font-size: 0.75rem;
    color: #64748b;
    margin-left: 2px;
}

/* Profile Dropdown Menu */
.profile-menu {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 220px;
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    border: 1px solid rgba(226, 232, 240, 0.8);
    padding: 8px 0;
    display: none;
    z-index: 1100;
}

.profile-menu.show { display: block; }

.profile-menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    font-size: 0.85rem;
    font-weight: 500;
    color: #334155;
    text-decoration: none;
    transition: background 0.15s ease;
}

.profile-menu-item i {
    width: 16px;
    color: #64748b;
    font-size: 0.95rem;
}

.profile-menu-item:hover {
    background: #f5f3ff;
    color: #6d28d9;
}

.profile-menu-item:hover i {
    color: #6d28d9;
}

.profile-menu-divider {
    height: 1px;
    background: #e2e8f0;
    margin: 6px 0;
}

/* Notification Popover Menu */
.notif-menu {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 340px;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 12px 36px rgba(0,0,0,0.12);
    border: 1px solid rgba(226, 232, 240, 0.85);
    padding: 0;
    display: none;
    z-index: 1100;
    overflow: hidden;
}

.notif-menu.show { display: block; }

.notif-menu-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    background: #faf8fd;
    border-bottom: 1px solid #f1f5f9;
}

.notif-menu-header-title {
    font-size: 0.88rem;
    font-weight: 800;
    color: #1e1b4b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.notif-menu-badge {
    background: #ede9fe;
    color: #6d28d9;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}

.notif-menu-list {
    max-height: 320px;
    overflow-y: auto;
}

.notif-menu-item {
    padding: 12px 18px;
    border-bottom: 1px solid #f8fafc;
    transition: background 0.15s ease;
}

.notif-menu-item:last-child { border-bottom: none; }
.notif-menu-item:hover { background: #fdf8ff; }

.notif-menu-item-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: #1e1b4b;
    margin-bottom: 3px;
}

.notif-menu-item-msg {
    font-size: 0.76rem;
    color: #475569;
    line-height: 1.35;
}

.notif-menu-item-date {
    font-size: 0.68rem;
    color: #94a3b8;
    margin-top: 4px;
}

/* Mobile Hamburger Toggle */
.mobile-toggle {
    display: none;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    align-items: center;
    justify-content: center;
    color: #1e1b4b;
    font-size: 1.15rem;
    cursor: pointer;
}

.sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(2px);
    z-index: 1040;
}

/* Responsive Breakpoints */
@media (max-width: 991px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .sidebar.open {
        transform: translateX(0);
    }
    .sidebar-backdrop.open {
        display: block;
    }
    .main {
        margin-left: 0;
    }
    .mobile-toggle {
        display: flex;
    }
    .topbar {
        padding: 16px 20px;
    }
    .topbar-search-box {
        display: none;
    }
}
</style>
