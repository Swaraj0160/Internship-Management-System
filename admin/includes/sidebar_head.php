<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar "shell" styles for all admin pages.

   This is NOT a redesign. It is the exact same sidebar CSS that
   already exists and works on admin/dashboard.php (colors, layout,
   mobile off-canvas behavior), extracted into one shared file so it
   can be reused instead of re-invented per page. Every selector name
   below (.sidebar, .app, .main, .brand, .profile-mini, .nav,
   .nav .account-bottom, .mobile-menu-toggle, .sidebar-backdrop) is
   copied verbatim from admin/dashboard.php's <style> block.

   Usage: include this file inside <head>, AFTER any page-specific
   <style> block that defines its own :root variables the page relied
   on before (e.g. body background) — this file only styles the
   sidebar/shell selectors and will not touch anything else on the
   page. Then include includes/sidebar_nav.php right after <body>,
   and includes/sidebar_scripts.php right before </body>.
   ----------------------------------------------------------------- */
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --sb-purple:#5b3df5;
    --sb-border:#e7e1f7;
    --sb-shadow-soft:0 10px 22px rgba(73,45,150,.06);
    --sidebar-width:288px;
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
    width:var(--sidebar-width);
    min-height:100vh;
    position:fixed;
    inset:0 auto 0 0;
    background:linear-gradient(180deg, #3f0f68 0%, #6d1a88 48%, #4a1474 100%);
    color:#fff;
    box-shadow:10px 0 30px rgba(4,2,18,.18);
    z-index:20;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border-right:1px solid rgba(255,255,255,.06);
    transition:transform .3s ease;
}
.mobile-menu-toggle{
    display:none;
    align-items:center;
    justify-content:center;
    width:44px;
    height:44px;
    border-radius:14px;
    border:1px solid var(--sb-border);
    background:#fff;
    color:var(--sb-purple);
    font-size:1.1rem;
    box-shadow:var(--sb-shadow-soft);
    flex:0 0 auto;
    cursor:pointer;
}
.sidebar-backdrop{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(20,10,40,.45);
    z-index:15;
    backdrop-filter:blur(2px);
}
.sidebar-backdrop.show{display:block}
.sidebar::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, rgba(255,255,255,.06), transparent 40%, rgba(255,255,255,.03));
    pointer-events:none;
}
.brand{
    padding:16px 16px 12px;
    text-align:center;
    border-bottom:1px solid rgba(255,255,255,.08);
}
.brand img{
    width:120px;
    height:auto;
    object-fit:contain;
    display:block;
    margin:0 auto 10px;
    background:none;
    box-shadow:none;
}
.brand .title{
    font-size:1.08rem;
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
/* -----------------------------------------------------------------
   REDESIGNED (post-implementation audit — exact sidebar requirement):
   .profile-mini is now a clickable <button> (.profile-trigger) that
   opens .profile-dropdown, a real functional dropdown panel — this
   is where Change Password / Settings / Logout (and Risk Insights /
   Export Students, kept out of the primary nav per your instruction)
   now live, instead of being nav items.
   ----------------------------------------------------------------- */
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
    font-size:.93rem;
    font-weight:700;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.profile-mini .meta .small{
    color:rgba(255,255,255,.65);
    font-size:.80rem;
}
.nav{
    padding:10px 12px 12px;
    gap:3px;
    min-height:0;
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    flex-wrap:nowrap;
    overflow:auto;
    position:relative;
    z-index:1;
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
    padding:10px 12px;
    border-radius:15px;
    transition:transform .22s ease, background .22s ease, box-shadow .22s ease, color .22s ease;
    font-weight:600;
    margin-bottom:2px;
    text-decoration:none;
    min-width:0;
    font-size:.92rem;
    position:relative;
    overflow:hidden;
}
.nav a i{
    width:18px;
    text-align:center;
    font-size:.98rem;
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
    margin-left:var(--sidebar-width);
    width:calc(100% - var(--sidebar-width));
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
        min-height:100vh;
        height:100vh;
        visibility:hidden;
        transition:transform .3s ease, visibility 0s linear .3s;
    }
    .sidebar.open{
        transform:translateX(0);
        visibility:visible;
        transition:transform .3s ease, visibility 0s linear 0s;
        box-shadow:24px 0 60px rgba(4,2,18,.35);
    }
    .nav{
        overflow:auto;
        max-height:calc(100vh - 150px);
    }
    .main{
        margin-left:0;
        width:100%;
    }
}
</style>