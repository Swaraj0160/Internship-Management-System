<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Portal Design System & Shared Styles
   Visual standard matching approved reference design:
   media_1794939092717.jpg
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
    --f-plum-mid: #29083b;
    --f-plum-sidebar: #3a104f;
    --f-plum-accent: #7c3aed;
    --f-plum-soft: #f5f3ff;
    
    /* Active Nav Item Tokens */
    --f-nav-active-bg: #eddff7;
    --f-nav-active-text: #3a104f;
    
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
    background: linear-gradient(180deg, #3a104f 0%, #2f0b40 45%, #240733 80%, #1a0424 100%);
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
    padding: 20px 18px 12px;
}

.sidebar-crest-row {
    display: flex;
    align-items: center;
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
    flex-shrink: 0;
}

.sidebar-crest-text {
    text-align: left;
}

.sidebar-crest-title {
    font-size: 0.95rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #ffffff;
    line-height: 1.15;
    text-transform: uppercase;
}

.sidebar-crest-subtitle {
    font-size: 0.65rem;
    letter-spacing: 0.16em;
    color: rgba(255, 255, 255, 0.7);
    margin-top: 2px;
    font-weight: 600;
}

/* Sub-Brand: Gold briefcase + InternHub + University Collaboration Portal */
.sidebar-subbrand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 4px 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 12px;
}

.sidebar-subbrand-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(251, 191, 36, 0.2);
    border: 1px solid rgba(251, 191, 36, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fbbf24;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.sidebar-subbrand-text {
    display: flex;
    flex-direction: column;
}

.sidebar-subbrand-name {
    font-size: 0.92rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.1;
    letter-spacing: -0.01em;
}

.sidebar-subbrand-tag {
    font-size: 0.65rem;
    color: rgba(255, 255, 255, 0.65);
    margin-top: 1px;
}

/* Faculty Profile Card in Sidebar */
.sidebar-profile-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    padding: 8px 12px;
    margin: 0 14px 14px;
    transition: all 0.2s ease;
}

.sidebar-profile-card:hover {
    background: rgba(255, 255, 255, 0.12);
}

.sidebar-avatar-img {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    background: #fbbf24;
    border: 2px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 3px 8px rgba(0,0,0,0.25);
    flex-shrink: 0;
}

.sidebar-profile-meta {
    flex: 1;
    min-width: 0;
}

.sidebar-profile-name {
    font-size: 0.86rem;
    font-weight: 700;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-profile-role {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.68);
    margin-top: 1px;
}

.sidebar-profile-chevron {
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.72rem;
    flex-shrink: 0;
}

/* Sidebar Nav Container */
.sidebar-nav-container {
    flex: 1;
    padding: 4px 14px 14px;
    display: flex;
    flex-direction: column;
}

.sidebar-nav-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.sidebar-nav-item a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    font-size: 0.875rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
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

/* Active State: Soft Lavender Pill with Deep Plum Text */
.sidebar-nav-item.active a {
    background: var(--f-nav-active-bg);
    color: var(--f-nav-active-text) !important;
    font-weight: 700;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
}

.sidebar-nav-item.active a i {
    color: var(--f-nav-active-text) !important;
}

/* Badges on Nav items */
.sidebar-badge {
    margin-left: auto;
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
}

.sidebar-badge-new {
    background: #e9d5ff;
    color: #6b21a8;
}

.sidebar-badge-count {
    background: #ef4444;
    color: #ffffff;
}

/* Bottom Artwork: MIT-ADT World Peace Dome transparent outline */
.sidebar-bottom-art {
    margin-top: auto;
    width: 100%;
    padding: 10px 14px 4px;
    text-align: center;
    pointer-events: none;
    opacity: 0.85;
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
    .sidebar-bottom-art { display: none; }
}

/* Bottom Logout Row */
.sidebar-footer-row {
    padding: 6px 14px 14px;
}

.sidebar-logout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 8px 14px;
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

/* Universal Topbar */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 32px 14px;
    gap: 20px;
    background: transparent;
}

.topbar-left {
    display: flex;
    flex-direction: column;
}

.topbar-greeting-over {
    font-size: 0.82rem;
    font-weight: 500;
    color: #64748b;
    line-height: 1.2;
}

.topbar-greeting-name {
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--f-text-dark);
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.25;
    margin-top: 1px;
}

.topbar-greeting-sub {
    font-size: 0.80rem;
    font-weight: 500;
    color: #64748b;
    margin-top: 2px;
}

/* Topbar Center: Quick Search Pill */
.topbar-search-wrap {
    flex: 1;
    max-width: 440px;
    position: relative;
}

.topbar-search-input {
    width: 100%;
    padding: 10px 44px 10px 18px;
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

.topbar-search-input::placeholder {
    color: #94a3b8;
    font-size: 0.86rem;
}

.topbar-search-icon {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 0.95rem;
    pointer-events: none;
}

/* Topbar Right: Actions */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.topbar-bell-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
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
    font-size: 1.05rem;
}

.topbar-bell-btn:hover {
    background: #f8fafc;
    color: #1e1b4b;
    border-color: #cbd5e1;
}

.topbar-bell-dot {
    position: absolute;
    top: 9px;
    right: 10px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ef4444;
    border: 1.5px solid #ffffff;
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
    object-fit: cover;
    background: #fbbf24;
    flex-shrink: 0;
}

.topbar-user-meta {
    display: flex;
    flex-direction: column;
    text-align: left;
}

.topbar-user-name {
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--f-text-dark);
    line-height: 1.1;
}

.topbar-user-role {
    font-size: 0.68rem;
    color: #64748b;
    line-height: 1;
}

.topbar-user-chevron {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-left: 4px;
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

/* Global Content Card Utilities */
.f-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #eef2f6;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
    padding: 22px;
    margin-bottom: 24px;
}

.f-page-header {
    margin-bottom: 22px;
}

.f-page-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1e1b4b;
    letter-spacing: -0.02em;
}

.f-page-subtitle {
    font-size: 0.88rem;
    color: #64748b;
    margin-top: 3px;
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
}

/* Custom Global Modals */
.custom-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 1200;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.custom-modal.open {
    display: flex !important;
}

.modal-box {
    background: #ffffff;
    border-radius: 20px;
    max-width: 580px;
    width: 100%;
    padding: 26px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-close-btn {
    position: absolute;
    top: 20px;
    right: 20px;
    background: #f1f5f9;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    transition: all 0.2s;
}

.modal-close-btn:hover {
    background: #e2e8f0;
    color: #1e1b4b;
}
</style>
