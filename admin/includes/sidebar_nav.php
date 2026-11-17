<?php
/* -----------------------------------------------------------------
   InternHub — Admin Portal Unified Navigation Shell
   Structure & visual fidelity aligned with media_1794942190320.jpg
   ----------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);
$admin_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'Administrator'));
$admin_initial = strtoupper(substr($admin_name ?: 'A', 0, 1));

// Pending counts for badges
$pending_faculty_count = 0;
$pfc_q = @mysqli_query($conn, "SELECT COUNT(*) as c FROM faculty_approval_requests WHERE status = 'pending'");
if ($pfc_q) {
    $pending_faculty_count = (int)(mysqli_fetch_assoc($pfc_q)['c'] ?? 0);
}

$pending_internships_count = 0;
$pic_q = @mysqli_query($conn, "SELECT COUNT(*) as c FROM internships WHERE status = 'Pending'");
if ($pic_q) {
    $pending_internships_count = (int)(mysqli_fetch_assoc($pic_q)['c'] ?? 0);
}

$unread_notif_count = 0;
$notif_q = @mysqli_query($conn, "SELECT COUNT(*) as c FROM admin_notifications");
if ($notif_q) {
    $unread_notif_count = (int)(mysqli_fetch_assoc($notif_q)['c'] ?? 0);
}

$activePage = $activePage ?? 'dashboard.php';

function adm_active($page, $current)
{
    return $page === $current ? ' class="sidebar-nav-item active"' : ' class="sidebar-nav-item"';
}
?>

<div class="app">
    <!-- Sidebar matching media_1794942190320.jpg -->
    <aside class="sidebar" id="adminSidebar">
        <!-- Brand Header -->
        <div class="sidebar-brand-box">
            <div class="sidebar-crest-row">
                <img src="../assets/images/mit_adt_crest.png" alt="MIT-ADT Crest" class="sidebar-crest-img" onerror="this.src='../assets/images/MIT_LOGO.jpg'">
                <div class="sidebar-crest-text">
                    <div class="sidebar-crest-title">MIT-ADT UNIVERSITY</div>
                    <div class="sidebar-crest-subtitle">PUNE, INDIA</div>
                </div>
            </div>
            
            <!-- Sub-Brand: InternHub University Administration -->
            <div class="sidebar-subbrand">
                <div class="sidebar-subbrand-icon">
                    <i class="fa-solid fa-briefcase"></i>
                </div>
                <div class="sidebar-subbrand-text">
                    <span class="sidebar-subbrand-name">InternHub</span>
                    <span class="sidebar-subbrand-tag">University Administration</span>
                </div>
            </div>
        </div>

        <!-- Navigation Menu Structured into 5 Sections -->
        <div class="sidebar-nav-container">
            <!-- OVERVIEW -->
            <div class="sidebar-nav-section-title">Overview</div>
            <ul class="sidebar-nav-list">
                <li<?php echo adm_active('dashboard.php', $activePage); ?>>
                    <a href="dashboard.php">
                        <i class="fa-solid fa-house"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            </ul>

            <!-- UNIVERSITY MANAGEMENT -->
            <div class="sidebar-nav-section-title">University Management</div>
            <ul class="sidebar-nav-list">
                <li<?php echo adm_active('user_management.php', $activePage); ?>>
                    <a href="user_management.php">
                        <i class="fa-solid fa-users-gear"></i>
                        <span>User Management</span>
                        <?php if ($pending_faculty_count > 0): ?>
                            <span class="sidebar-badge sidebar-badge-count"><?php echo $pending_faculty_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li<?php echo adm_active('student_directory.php', $activePage); ?>>
                    <a href="student_directory.php">
                        <i class="fa-solid fa-id-card"></i>
                        <span>Student Directory</span>
                    </a>
                </li>
                <li<?php echo adm_active('faculty_directory.php', $activePage); ?>>
                    <a href="faculty_directory.php">
                        <i class="fa-solid fa-user-tie"></i>
                        <span>Faculty Directory</span>
                    </a>
                </li>
            </ul>

            <!-- PLATFORM MANAGEMENT -->
            <div class="sidebar-nav-section-title">Platform Management</div>
            <ul class="sidebar-nav-list">
                <li<?php echo adm_active('internship_oversight.php', $activePage); ?>>
                    <a href="internship_oversight.php">
                        <i class="fa-solid fa-briefcase"></i>
                        <span>Internship Oversight</span>
                        <?php if ($pending_internships_count > 0): ?>
                            <span class="sidebar-badge sidebar-badge-count"><?php echo $pending_internships_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li<?php echo adm_active('community_moderation.php', $activePage); ?>>
                    <a href="community_moderation.php">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Community & Moderation</span>
                    </a>
                </li>
                <li<?php echo adm_active('content_resources.php', $activePage); ?>>
                    <a href="content_resources.php">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Content & Resources</span>
                    </a>
                </li>
            </ul>

            <!-- INTELLIGENCE -->
            <div class="sidebar-nav-section-title">Intelligence</div>
            <ul class="sidebar-nav-list">
                <li<?php echo adm_active('ai_insights.php', $activePage); ?>>
                    <a href="ai_insights.php">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>AI Analytics</span>
                        <span class="sidebar-badge sidebar-badge-new">New</span>
                    </a>
                </li>
                <li<?php echo adm_active('reports_analytics.php', $activePage); ?>>
                    <a href="reports_analytics.php">
                        <i class="fa-solid fa-chart-column"></i>
                        <span>Reports & Insights</span>
                    </a>
                </li>
            </ul>

            <!-- SYSTEM -->
            <div class="sidebar-nav-section-title">System</div>
            <ul class="sidebar-nav-list">
                <li<?php echo adm_active('manage_notifications.php', $activePage); ?>>
                    <a href="manage_notifications.php">
                        <i class="fa-solid fa-bell"></i>
                        <span>Notifications</span>
                    </a>
                </li>
                <li<?php echo adm_active('system_settings.php', $activePage); ?>>
                    <a href="system_settings.php">
                        <i class="fa-solid fa-gear"></i>
                        <span>System Settings</span>
                    </a>
                </li>
                <li<?php echo adm_active('audit_logs.php', $activePage); ?>>
                    <a href="audit_logs.php">
                        <i class="fa-solid fa-clipboard-check"></i>
                        <span>Audit & Logs</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Transparent Dome Illustration at Bottom Left -->
        <div class="sidebar-bottom-art">
            <img src="../assets/images/sidebar_dome_bottom.png" alt="MIT-ADT World Peace Dome" onerror="this.style.display='none'">
        </div>
    </aside>

    <!-- Mobile Backdrop -->
    <div class="sidebar-backdrop" id="adminSidebarBackdrop"></div>

    <!-- Main Content Shell -->
    <main class="main" id="mainContent">
        <!-- Universal Topbar matching media_1794942190320.jpg -->
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 14px;">
                <button type="button" class="mobile-menu-toggle" id="adminMenuToggle" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left">
                    <div class="topbar-greeting-over">Welcome Back,</div>
                    <div class="topbar-greeting-name">
                        <span><?php echo h($admin_name); ?></span>
                        <i class="fa-solid fa-circle-check topbar-verified-badge" title="Verified Administrator"></i>
                    </div>
                    <div class="topbar-greeting-sub">System Control • University Internship Hub</div>
                </div>
            </div>

            <!-- Global Search Pill with Right Icon -->
            <form action="student_directory.php" method="GET" class="topbar-search-wrap">
                <input type="text" name="q" id="globalAdminSearchInput" class="topbar-search-input" placeholder="Search users, internships, departments, reports..." autocomplete="off">
                <i class="fa-solid fa-magnifying-glass topbar-search-icon"></i>
            </form>

            <!-- Topbar Right Actions -->
            <div class="topbar-right">
                <a href="manage_notifications.php" class="topbar-bell-btn" title="System Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notif_count > 0 || $pending_faculty_count > 0): ?>
                        <span class="topbar-bell-dot"></span>
                    <?php endif; ?>
                </a>

                <div class="topbar-user-menu">
                    <button type="button" class="topbar-user-btn" id="topbarUserDropdownTrigger">
                        <div class="topbar-user-avatar"><?php echo h($admin_initial); ?></div>
                        <div class="topbar-user-meta">
                            <span class="topbar-user-name"><?php echo h($admin_name); ?></span>
                            <span class="topbar-user-role">Super Admin</span>
                        </div>
                        <i class="fa-solid fa-chevron-down topbar-user-chevron"></i>
                    </button>

                    <div class="user-dropdown-popover" id="topbarUserDropdownPopover">
                        <div style="padding: 8px 12px 10px; border-bottom: 1px solid #f1f5f9;">
                            <div style="font-size: 0.84rem; font-weight: 700; color: #1e1b4b;"><?php echo h($admin_name); ?></div>
                            <div style="font-size: 0.72rem; color: #64748b;">System Administrator (Root)</div>
                        </div>
                        <a href="user_management.php" class="user-dropdown-item"><i class="fa-solid fa-users-gear"></i>User Management</a>
                        <a href="internship_oversight.php" class="user-dropdown-item"><i class="fa-solid fa-briefcase"></i>Internship Oversight</a>
                        <a href="ai_insights.php" class="user-dropdown-item"><i class="fa-solid fa-wand-magic-sparkles"></i>AI Analytics</a>
                        <div class="user-dropdown-divider"></div>
                        <a href="change_password.php" class="user-dropdown-item"><i class="fa-solid fa-key"></i>Change Password</a>
                        <a href="system_settings.php" class="user-dropdown-item"><i class="fa-solid fa-gear"></i>System Settings</a>
                        <a href="audit_logs.php" class="user-dropdown-item"><i class="fa-solid fa-shield-halved"></i>Audit & Logs</a>
                        <div class="user-dropdown-divider"></div>
                        <a href="../logout.php" class="user-dropdown-item" style="color: #ef4444;"><i class="fa-solid fa-right-from-bracket" style="color: #ef4444;"></i>Logout</a>
                    </div>
                </div>
            </div>
        </header>
