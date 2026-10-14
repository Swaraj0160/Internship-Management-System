<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar CSS shell for all student pages, mirroring
   student/dashboard.php's existing dark-purple sidebar theme
   (.app / .sidebar / .brand / .profile-mini / .nav / .account-bottom /
   .main), so every retrofitted page looks identical to the dashboard.

   Adds mobile responsiveness that did NOT exist before anywhere on
   the student side: a hamburger toggle + off-canvas collapse under
   992px (.mobile-menu-toggle, .sidebar-backdrop, @media block).

   Include this inside <head>, after the page's own Bootstrap/Font
   Awesome/Google Fonts links (Font Awesome is required for the nav
   icons; if a page doesn't already load it, add it before this
   include).
   ----------------------------------------------------------------- */
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --ssb-sidebar-width:clamp(225px,18vw,288px);
}

*{box-sizing:border-box}

.app{min-height:100vh;display:flex;position:relative;z-index:1}

.sidebar{
    width:var(--ssb-sidebar-width);
    min-height:100vh;
    position:fixed;
    inset:0 auto 0 0;

    background:linear-gradient(
        180deg,
        #5b2d90 0%,
        #4b2484 20%,
        #3d1f73 45%,
        #6d1b88 70%,
        #5a1778 100%
    );

    color:#fff;
    border-right:none;
    box-shadow:0 10px 30px rgba(0,0,0,.18);

    z-index:1200;
    display:flex;
    flex-direction:column;
    overflow-y:auto;
    overflow-x:hidden;
    transition:transform .28s ease;
}

.brand{
    padding:16px 16px 12px;
    text-align:center;
    border-bottom:1px solid rgba(255,255,255,.12);
}
.brand img{
    width:88px;
    max-width:100%;
    height:auto;
    object-fit:contain;
    display:block;
    margin:0 auto 8px;
    box-shadow:0 16px 30px rgba(0,0,0,.16);
    border-radius:16px;
}
.brand .title{
    font-size:1.05rem;
    font-weight:800;
    line-height:1.08;
    color:#fff;
}
.brand .subtitle{
    margin-top:6px;
    font-size:.68rem;
    letter-spacing:.3em;
    color:rgba(255,255,255,.58);
}

/* REDESIGNED (post-implementation audit — exact sidebar requirement):
   same functional profile dropdown pattern as admin/faculty. */
.profile-mini{
    display:flex;
    gap:12px;
    align-items:center;
    padding:8px 16px 10px;
    border-bottom:1px solid rgba(255,255,255,.12);
}
.profile-trigger{
    width:100%;
    background:none;
    border:none;
    cursor:pointer;
    text-align:left;
    color:inherit;
    font:inherit;
    position:relative;
}
.profile-trigger:hover{
    background:rgba(255,255,255,.08);
}
.profile-chevron{
    margin-left:auto;
    font-size:.78rem;
    color:rgba(255,255,255,.6);
    flex:0 0 auto;
    transition:transform .2s ease;
}
.profile-trigger[aria-expanded="true"] .profile-chevron{
    transform:rotate(180deg);
}
.profile-dropdown{
    display:none;
    flex-direction:column;
    background:rgba(0,0,0,.2);
    border-bottom:1px solid rgba(255,255,255,.12);
    padding:8px 10px;
}
.profile-dropdown.open{
    display:flex;
}
.profile-dropdown a{
    display:flex;
    align-items:center;
    gap:10px;
    color:#fff;
    padding:9px 10px;
    border-radius:12px;
    font-weight:600;
    font-size:.87rem;
    text-decoration:none;
    margin-bottom:1px;
}
.profile-dropdown a i{
    width:16px;
    text-align:center;
    font-size:.88rem;
}
.profile-dropdown a:hover, .profile-dropdown a.active{
    background:linear-gradient(90deg,#ff5b5b,#ff8c3a);
    color:#fff;
}
.profile-dropdown-section-label{
    padding:6px 10px 3px;
    color:rgba(255,255,255,.5);
    font-size:.66rem;
    letter-spacing:.14em;
    text-transform:uppercase;
    font-weight:700;
}
.profile-dropdown-divider{
    height:1px;
    background:rgba(255,255,255,.1);
    margin:4px 6px;
}
.profile-mini .avatar{
    width:42px;
    height:42px;
    border-radius:50%;
    overflow:hidden;
    flex-shrink:0;
    border:2px solid rgba(255,255,255,.25);
    box-shadow:0 4px 12px rgba(0,0,0,.25);
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#6f42ff,#8e5cff);
    color:#fff;
    font-size:17px;
    font-weight:700;
    text-transform:uppercase;
}
.profile-mini .avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.profile-mini .meta{min-width:0}
.profile-mini .meta .name{
    font-size:.94rem;
    font-weight:700;
    color:#fff;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.profile-mini .meta .small{
    color:rgba(255,255,255,.65);
    font-size:.8rem;
}

.nav{
    padding:8px 12px 10px;
    gap:2px;
    display:flex;
    flex-direction:column;
    flex:1 1 auto;
    min-height:0;
}
.nav-label{
    color:rgba(255,255,255,.55);
    font-size:.72rem;
    letter-spacing:.16em;
    font-weight:700;
    text-transform:uppercase;
    padding:10px 12px 4px;
}
.nav a{
    position:relative;
    display:flex;
    align-items:center;
    gap:12px;
    color:#ffffff;
    padding:11px 16px;
    margin-bottom:4px;
    border-radius:16px;
    font-weight:600;
    font-size:14.5px;
    transition:all .25s ease;
    text-decoration:none;
}
.nav a i{
    width:20px;
    text-align:center;
    font-size:16px;
}
.nav a:hover{
    background:linear-gradient(90deg,#ff5b5b,#ff8c3a);
    color:#fff;
    transform:translateX(4px);
    box-shadow:0 10px 22px rgba(255,120,60,.35);
}
.nav a.active{
    background:linear-gradient(90deg,#ff5b6b,#ff8c3a);
    color:#fff;
    box-shadow:0 8px 18px rgba(255,107,107,.30);
}
.account-bottom{
    margin-top:8px;
    padding-top:6px;
    border-top:1px solid rgba(255,255,255,.08);
}

.main{
    margin-left:var(--ssb-sidebar-width);
    width:calc(100% - var(--ssb-sidebar-width));
    background:#f5f6fa;
    color:#1f2430;
    position:relative;
    z-index:1;
    min-height:100vh;
}

.sidebar-topbar{
    display:none;
    align-items:center;
    padding:10px 16px;
    background:#fff;
    border-bottom:1px solid #e7e1f7;
    position:sticky;
    top:0;
    z-index:1000;
}

.mobile-menu-toggle{
    display:none;
    align-items:center;
    justify-content:center;
    width:40px;
    height:40px;
    border-radius:10px;
    border:1px solid #e7e1f7;
    background:#fff;
    color:#3d1f73;
    font-size:18px;
    cursor:pointer;
}

.sidebar-backdrop{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,10,30,.45);
    z-index:1100;
}
.sidebar-backdrop.show{
    display:block;
}

@media (max-width:992px){
    .sidebar{
        transform:translateX(-100%);
        visibility:hidden;
        transition:transform .28s ease, visibility 0s linear .28s;
    }
    .sidebar.open{
        transform:translateX(0);
        visibility:visible;
        transition:transform .28s ease, visibility 0s linear 0s;
    }
    .main{
        margin-left:0;
        width:100%;
    }
    .sidebar-topbar,
    .mobile-menu-toggle{
        display:flex;
    }
}
</style>
