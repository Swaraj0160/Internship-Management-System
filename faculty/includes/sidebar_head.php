<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar "shell" styles for all faculty pages.

   Extracted from faculty/dashboard.php's existing sidebar CSS
   (.sidebar, .app, .main, .brand, .profile-mini, .nav,
   .nav .account-bottom) so every faculty page can reuse the exact
   same look instead of re-inventing it. faculty/dashboard.php's
   sidebar previously had NO mobile breakpoint at all (verified: no
   @media rule, no mobile-menu-toggle, no backdrop existed anywhere
   in that file) — the off-canvas mobile behavior below is new,
   modeled on the equivalent admin/includes/sidebar_head.php pattern,
   and is a genuine responsiveness fix, not just a copy.
   ----------------------------------------------------------------- */
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --fsb-sidebar-width:clamp(225px,18vw,288px);
}
.app{
    min-height:100vh;
    display:flex;
    width:100%;
    overflow-x:hidden;
    position:relative;
    z-index:1;
}
.sidebar{
    width:var(--fsb-sidebar-width);
    height:100vh;
    position:fixed;
    inset:0 auto 0 0;
    background:linear-gradient(180deg, rgba(123,97,255,.24), rgba(123,97,255,0)),linear-gradient(180deg, #3f0f68 0%, #6d1a88 48%, #4a1474 100%);
    color:#fff;
    box-shadow:10px 0 30px rgba(4, 2, 18, .36);
    z-index:20;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border-right:1px solid rgba(255,255,255,.06);
    transition:transform .3s ease;
}
.sidebar::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, rgba(255,255,255,.05), transparent 35%, rgba(255,255,255,.03));
    pointer-events:none;
}
.mobile-menu-toggle{
    display:none;
    align-items:center;
    justify-content:center;
    width:44px;
    height:44px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.16);
    background:rgba(255,255,255,.08);
    color:#fff;
    font-size:1.1rem;
    flex:0 0 auto;
    cursor:pointer;
}
.sidebar-backdrop{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(10,4,28,.55);
    z-index:15;
    backdrop-filter:blur(2px);
}
.sidebar-backdrop.show{display:block}
.brand{
    padding:16px 16px 12px;
    text-align:center;
    border-bottom:1px solid rgba(255,255,255,.08);
}
.brand img{
    width:clamp(85px,8vw,120px);
    height:auto;
    object-fit:contain;
    display:block;
    margin:0 auto 10px;
}
.brand .title{
    font-size:clamp(.88rem,1vw,1.08rem);
    font-weight:800;
    line-height:1.08;
    letter-spacing:-.03em;
}
.brand .subtitle{
    margin-top:7px;
    font-size:.70rem;
    letter-spacing:.34em;
    color:rgba(255,255,255,.58);
}
/* REDESIGNED (post-implementation audit — exact sidebar requirement):
   same functional profile dropdown pattern as admin — see
   admin/includes/sidebar_head.php for the full explanation. */
.profile-mini{
    display:flex;
    gap:12px;
    align-items:center;
    padding:12px 16px;
    border-bottom:1px solid rgba(255,255,255,.08);
    min-width:0;
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
    background:rgba(255,255,255,.06);
}
.profile-chevron{
    margin-left:auto;
    font-size:.78rem;
    color:rgba(255,255,255,.55);
    flex:0 0 auto;
    transition:transform .2s ease;
}
.profile-trigger[aria-expanded="true"] .profile-chevron{
    transform:rotate(180deg);
}
.profile-dropdown{
    display:none;
    flex-direction:column;
    background:rgba(0,0,0,.22);
    border-bottom:1px solid rgba(255,255,255,.08);
    padding:8px 10px;
}
.profile-dropdown.open{
    display:flex;
}
.profile-dropdown a{
    display:flex;
    align-items:center;
    gap:10px;
    color:rgba(255,255,255,.92);
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
    background:linear-gradient(90deg, #cf1e71, #f26d21);
    color:#fff;
}
.profile-dropdown-section-label{
    padding:6px 10px 3px;
    color:rgba(255,255,255,.42);
    font-size:.66rem;
    letter-spacing:.14em;
    text-transform:uppercase;
    font-weight:700;
}
.profile-dropdown-divider{
    height:1px;
    background:rgba(255,255,255,.08);
    margin:4px 6px;
}
.profile-mini .avatar{
    width:44px;
    height:44px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:linear-gradient(135deg, #7c5cff, #4f7cff);
    font-weight:800;
    color:#fff;
    box-shadow:0 12px 24px rgba(0,0,0,.24), 0 0 0 6px rgba(255,255,255,.05);
    flex:0 0 auto;
}
.profile-mini .meta{min-width:0}
.profile-mini .meta .name{
    font-size:.90rem;
    font-weight:700;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.profile-mini .meta .small{
    color:rgba(255,255,255,.65);
    font-size:.78rem;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.nav{
    padding:10px 12px 12px;
    gap:3px;
    min-height:0;
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    overflow-y:auto;
    overflow-x:hidden;
}
.nav-label{
    padding:7px 10px 4px;
    color:rgba(255,255,255,.42);
    font-size:.70rem;
    letter-spacing:.16em;
    text-transform:uppercase;
    font-weight:700;
}
.nav a{
    display:flex;
    align-items:center;
    gap:11px;
    color:rgba(255,255,255,.94);
    padding:9px 12px;
    border-radius:15px;
    font-weight:600;
    margin-bottom:2px;
    text-decoration:none;
    min-width:0;
    font-size:.88rem;
    position:relative;
    overflow:hidden;
}
.nav a i{
    width:18px;
    text-align:center;
    font-size:.95rem;
    flex:0 0 auto;
}
.nav a:hover, .nav a.active{
    background:linear-gradient(90deg, #cf1e71, #f26d21);
    color:#fff;
}
.nav .account-bottom{
    margin-top:auto;
    padding-top:8px;
    border-top:1px solid rgba(255,255,255,.08);
}
.main{
    margin-left:var(--fsb-sidebar-width);
    width:calc(100% - var(--fsb-sidebar-width));
    min-width:0;
    overflow-x:hidden;
    position:relative;
    z-index:1;
}
.sidebar-topbar{
    display:flex;
    align-items:center;
    gap:14px;
    padding:14px 20px;
}
@media (max-width: 992px){
    .mobile-menu-toggle{display:inline-flex}
    .sidebar{
        position:fixed;
        width:min(300px, 84vw);
        transform:translateX(-100%);
        visibility:hidden;
        transition:transform .3s ease, visibility 0s linear .3s;
    }
    .sidebar.open{
        transform:translateX(0);
        visibility:visible;
        transition:transform .3s ease, visibility 0s linear 0s;
        box-shadow:24px 0 60px rgba(4,2,18,.45);
    }
    .main{
        margin-left:0;
        width:100%;
    }
}
</style>
