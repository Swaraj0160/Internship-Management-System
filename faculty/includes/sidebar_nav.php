<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar markup for all faculty pages.

   Mirrors admin/includes/sidebar_nav.php's pattern. Reuses
   $faculty_name / $faculty_initial / $faculty_division /
   $faculty_divisions if the including page already computed them
   (faculty/dashboard.php does); otherwise computes them itself from
   the session + faculty_divisions table (with the legacy
   users.faculty_division column as fallback), so this include is
   safe to drop into any faculty page standalone.

   Added two nav links that did not exist anywhere in the faculty
   dashboard's original sidebar: Risk Insights and Export Students —
   both pages existed in the project already but were unreachable
   from any UI before this change.

   Set $activePage to this page's own filename before including.
   ----------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($faculty_divisions) || !is_array($faculty_divisions)) {
    $faculty_id_for_nav = (int)($_SESSION['user_id'] ?? 0);
    $faculty_divisions = [];

    $fd_res = mysqli_query(
        $conn,
        "SELECT DISTINCT division FROM faculty_divisions
         WHERE faculty_id='$faculty_id_for_nav'
           AND division IS NOT NULL
           AND TRIM(division) <> ''"
    );

    if ($fd_res) {
        while ($row = mysqli_fetch_assoc($fd_res)) {
            $v = trim((string)$row['division']);
            if ($v !== '') {
                $faculty_divisions[] = $v;
            }
        }
    }

    if (empty($faculty_divisions)) {
        $fac_res = mysqli_query($conn, "SELECT full_name, faculty_division FROM users WHERE id='$faculty_id_for_nav'");
        $fac_row = $fac_res ? mysqli_fetch_assoc($fac_res) : [];
        $legacy = trim((string)($fac_row['faculty_division'] ?? ''));
        if ($legacy !== '') {
            $faculty_divisions[] = $legacy;
        }
    } else {
        $fac_res = mysqli_query($conn, "SELECT full_name FROM users WHERE id='$faculty_id_for_nav'");
        $fac_row = $fac_res ? mysqli_fetch_assoc($fac_res) : [];
    }

    $faculty_divisions = array_values(array_unique($faculty_divisions));
}

if (!isset($faculty_name)) {
    $faculty_name = trim((string)($fac_row['full_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Faculty'));
}

if (!isset($faculty_division)) {
    $faculty_division = implode(', ', $faculty_divisions);
}

if (!isset($faculty_initial)) {
    $faculty_name_clean = preg_replace('/^(Prof\.?|Dr\.?|Professor)\s*/i', '', $faculty_name);
    $faculty_initial = strtoupper(substr($faculty_name_clean ?: $faculty_name ?: 'F', 0, 1));
}

$activePage = $activePage ?? '';

function fsb_active($page, $current)
{
    return $page === $current ? ' class="active"' : '';
}
?>
<div class="app">
    <aside class="sidebar" id="fsbSidebar">
        <div class="brand">
            <img src="../assets/images/MIT_LOGO.jpg" alt="MIT-ADT University">
            <div class="title">MIT-ADT University</div>
            <div class="subtitle">PUNE, INDIA</div>
        </div>
        <button type="button" class="profile-mini profile-trigger" id="fsbProfileTrigger" aria-haspopup="true" aria-expanded="false">
            <div class="avatar"><?php echo h($faculty_initial); ?></div>
            <div class="meta">
                <div class="name"><?php echo h($faculty_name); ?></div>
                <div class="small"><?php echo h($faculty_division); ?></div>
            </div>
            <i class="fa-solid fa-chevron-down profile-chevron"></i>
        </button>

        <div class="profile-dropdown" id="fsbProfileDropdown">
            <div class="profile-dropdown-section-label">Tools</div>
            <a href="risk_insights.php"<?php echo fsb_active('risk_insights.php', $activePage); ?>><i class="fa-solid fa-triangle-exclamation"></i>Risk Insights</a>
            <a href="export_students.php"<?php echo fsb_active('export_students.php', $activePage); ?>><i class="fa-solid fa-file-export"></i>Export Students</a>
            <div class="profile-dropdown-divider"></div>
            <div class="profile-dropdown-section-label">Account</div>
            <a href="change_password.php"<?php echo fsb_active('change_password.php', $activePage); ?>><i class="fa-solid fa-key"></i>Change Password</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
        </div>

        <!-- -------------------------------------------------------------
             REDESIGNED (post-implementation audit — exact sidebar
             requirement): Risk Insights, Export Students, Change
             Password and Logout moved out of the primary nav list and
             into the functional profile dropdown above (same pattern
             as admin). Primary nav below is now the faculty equivalent
             of the requested 8-item admin list.
             ------------------------------------------------------------- -->
        <nav class="nav">
            <div class="nav-label">Workspace</div>
            <a href="dashboard.php"<?php echo fsb_active('dashboard.php', $activePage); ?>><i class="fa-solid fa-house"></i>Dashboard</a>
            <a href="manage_applications.php"<?php echo fsb_active('manage_applications.php', $activePage); ?>><i class="fa-solid fa-circle-check"></i>Applications / Internships</a>
            <a href="view_updates.php"<?php echo fsb_active('view_updates.php', $activePage); ?>><i class="fa-solid fa-calendar-days"></i>Weekly Reports</a>
            <a href="student_evaluations.php"<?php echo fsb_active('student_evaluations.php', $activePage); ?>><i class="fa-solid fa-star"></i>Student Evaluations</a>
            <a href="student_showcase.php"<?php echo fsb_active('student_showcase.php', $activePage); ?>><i class="fa-solid fa-diagram-project"></i>Student Internship Work</a>
            <a href="manage_deadlines.php"<?php echo fsb_active('manage_deadlines.php', $activePage); ?>><i class="fa-solid fa-clock"></i>Manage Deadlines</a>
            <a href="view_reports.php"<?php echo fsb_active('view_reports.php', $activePage); ?>><i class="fa-solid fa-file-lines"></i>Reports</a>
            <a href="manage_notifications.php"<?php echo fsb_active('manage_notifications.php', $activePage); ?>><i class="fa-solid fa-bell"></i>Notifications</a>
        </nav>
    </aside>
    <div class="sidebar-backdrop" id="fsbSidebarBackdrop"></div>
    <main class="main">
        <div class="sidebar-topbar">
            <button type="button" class="mobile-menu-toggle" id="fsbMobileMenuToggle" aria-label="Toggle navigation menu">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
