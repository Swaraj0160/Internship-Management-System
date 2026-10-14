<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar markup for all admin pages.

   Include this immediately after <body>. It opens <div class="app">,
   prints the sidebar (same structure/links as admin/dashboard.php),
   the mobile backdrop, and opens <main class="main"> with a small
   top row containing the mobile menu toggle button. The including
   page's own content follows right after this include, and the page
   MUST close with </main></div> before </body> (see
   includes/sidebar_scripts.php's comment for the exact closing tags).

   Set $activePage to this page's own filename (e.g. 'manage_students.php')
   before including this file so the correct nav link gets the
   "active" highlight. If not set, no link is highlighted.

   -------------------------------------------------------------------
   REDESIGNED (post-implementation audit — exact sidebar requirement):
   The primary nav list is now EXACTLY the 8 requested items —
   Dashboard, Manage Students, Manage Faculty, All Internships, Weekly
   Reports, Student Evaluations, Reports, Notifications. Risk Insights
   and Export Students were removed from this list (they are real,
   working pages — just no longer in the primary 8-item nav). Change
   Password, Settings, and Logout moved out of the nav entirely and
   into a functional profile dropdown, opened by clicking the
   profile-mini block (avatar + name) at the top of the sidebar. Risk
   Insights and Export Students remain reachable from that same
   profile dropdown, under a separate "Tools" section, clearly
   distinguished from the "Account" section below it.
   ----------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$admin_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Admin'));
$admin_initial = strtoupper(substr(preg_replace('/^(Prof\.?|Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s*/i', '', $admin_name) ?: 'A', 0, 1));


$action_center_count = 0;
// Quick count of pending approvals and reports
$ac_res1 = mysqli_query($conn, "SELECT COUNT(*) as c FROM internships WHERE status = 'Pending'");
if($ac_res1) $action_center_count += mysqli_fetch_assoc($ac_res1)['c'];

$ac_res2 = mysqli_query($conn, "SELECT COUNT(*) as c FROM weekly_updates WHERE review_status = 'Pending'");
if($ac_res2) $action_center_count += mysqli_fetch_assoc($ac_res2)['c'];

$ac_res3 = mysqli_query($conn, "SELECT COUNT(*) as c FROM internships WHERE status = 'Rejected'");
if($ac_res3) $action_center_count += mysqli_fetch_assoc($ac_res3)['c'];

$activePage = $activePage ?? '';

function sb_active($page, $current)
{
    return $page === $current ? ' class="active"' : '';
}
?>
<div class="app">
    <aside class="sidebar" id="appSidebar">
        <div class="brand">
            <img src="../assets/images/MIT_LOGO.jpg" alt="MIT-ADT University">
            <div class="title">MIT-ADT University</div>
            <div class="subtitle">PUNE, INDIA</div>
        </div>

        <button type="button" class="profile-mini profile-trigger" id="profileTrigger" aria-haspopup="true" aria-expanded="false">
            <div class="avatar"><?php echo h($admin_initial); ?></div>
            <div class="meta">
                <div class="name"><?php echo h($admin_name); ?></div>
                <div class="small">Administrator</div>
            </div>
            <i class="fa-solid fa-chevron-down profile-chevron"></i>
        </button>

        <div class="profile-dropdown" id="profileDropdown">
            <div class="profile-dropdown-section-label">Tools</div>
            <a href="risk_insights.php"<?php echo sb_active('risk_insights.php', $activePage); ?>><i class="fa-solid fa-triangle-exclamation"></i>Risk Insights</a>
            <a href="export_students.php"<?php echo sb_active('export_students.php', $activePage); ?>><i class="fa-solid fa-file-export"></i>Export Students</a>
            <div class="profile-dropdown-divider"></div>
            <div class="profile-dropdown-section-label">Account</div>
            <a href="change_password.php"<?php echo sb_active('change_password.php', $activePage); ?>><i class="fa-solid fa-key"></i>Change Password</a>
            <a href="settings.php"<?php echo sb_active('settings.php', $activePage); ?>><i class="fa-solid fa-gear"></i>Settings</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
        </div>

        <nav class="nav">
            <a href="dashboard.php"<?php echo sb_active('dashboard.php', $activePage); ?>><i class="fa-solid fa-house"></i>Dashboard</a>
            <a href="action_center.php"<?php echo sb_active('action_center.php', $activePage); ?> style="justify-content: space-between;"><span style="display:flex;align-items:center;gap:11px;"><i class="fa-solid fa-bolt" style="color:#fbbf24;"></i>Action Center</span> <span class="badge" style="background:#f43f5e; color:#fff; padding:3px 8px; border-radius:12px; font-size:0.7rem; font-weight:800;"><?php echo $action_center_count; ?></span></a>
            <a href="manage_students.php"<?php echo sb_active('manage_students.php', $activePage); ?>><i class="fa-solid fa-users"></i>Manage Students</a>
            <a href="student_showcase.php"<?php echo sb_active('student_showcase.php', $activePage); ?>><i class="fa-solid fa-diagram-project"></i>Student Internship Work</a>
            <a href="manage_faculty.php"<?php echo sb_active('manage_faculty.php', $activePage); ?>><i class="fa-solid fa-user-tie"></i>Manage Faculty</a>
            <a href="all_internships.php"<?php echo sb_active('all_internships.php', $activePage); ?>><i class="fa-solid fa-list-check"></i>All Internships</a>
            <a href="view_updates.php"<?php echo sb_active('view_updates.php', $activePage); ?>><i class="fa-solid fa-calendar-days"></i>Weekly Reports</a>
            <a href="student_evaluations.php"<?php echo sb_active('student_evaluations.php', $activePage); ?>><i class="fa-solid fa-clipboard-check"></i>Student Evaluations</a>
            <a href="reports.php"<?php echo sb_active('reports.php', $activePage); ?>><i class="fa-solid fa-chart-line"></i>Reports</a>
            <a href="manage_notifications.php"<?php echo sb_active('manage_notifications.php', $activePage); ?>><i class="fa-solid fa-bell"></i>Notifications</a>
        </nav>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <main class="main">
        <div class="sidebar-topbar">
            <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
