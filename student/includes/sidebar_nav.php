<?php
/* -----------------------------------------------------------------
   InternHub — Student Portal Sidebar & Universal Top Bar
   Pixel-faithful reproduction of approved visual reference:
   media_1794927520336.jpg
   ----------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Student data resolution
$ssb_student_id = (int)($_SESSION['user_id'] ?? 0);

if (!isset($student_name) || !isset($student_division) || !isset($profile_image) || !isset($enrollment_no) || !isset($student_department)) {
    $ssb_stmt = $conn->prepare("SELECT full_name, email, phone, division, department, enrollment_no, roll_no, profile_photo FROM users WHERE id=? LIMIT 1");
    if ($ssb_stmt) {
        $ssb_stmt->bind_param("i", $ssb_student_id);
        $ssb_stmt->execute();
        $ssb_row = $ssb_stmt->get_result()->fetch_assoc() ?: [];
        $ssb_stmt->close();
    } else {
        $ssb_row = [];
    }

    $student_name = $student_name ?? ($ssb_row['full_name'] ?: ($_SESSION['name'] ?? 'Student'));
    $student_division = $student_division ?? ($ssb_row['division'] ?: 'SY-4');
    $student_department = $student_department ?? ($ssb_row['department'] ?: 'SOC');
    $enrollment_no = $enrollment_no ?? ($ssb_row['enrollment_no'] ?: 'ADT24SOCB1260');
    $student_roll = $student_roll ?? ($ssb_row['roll_no'] ?: '');
    $student_email = $student_email ?? ($ssb_row['email'] ?: '');
    $student_phone = $student_phone ?? ($ssb_row['phone'] ?: '');
    $student_initial = strtoupper(substr(trim((string)$student_name), 0, 1)) ?: 'S';

    $ssb_photo = trim($ssb_row['profile_photo'] ?? '');
    if (!empty($ssb_photo) && file_exists(__DIR__ . "/../../uploads/profile_photos/" . $ssb_photo)) {
        $profile_image = "../uploads/profile_photos/" . rawurlencode($ssb_photo);
    } else {
        $profile_image = "";
    }
}

$activePage = $activePage ?? 'dashboard.php';

// Helper for active link checking with legacy alias compatibility
function is_nav_active($target, $current) {
    if ($target === $current) return true;
    if ($target === 'my_internship.php' && in_array($current, ['apply_internship.php', 'my_internships.php', 'add_update.php', 'my_updates.php', 'marks.php', 'upload_report.php'])) return true;
    if ($target === 'my_portfolio.php' && in_array($current, ['profile.php', 'my_showcase.php'])) return true;
    if ($target === 'settings.php' && in_array($current, ['change_password.php'])) return true;
    return false;
}

// Fetch unread notification count
$unread_count = 0;
$unread_q = mysqli_query($conn, "
    SELECT COUNT(*) AS c FROM (
        SELECT id FROM admin_notifications WHERE (target_role='student' OR target_role='all') AND id NOT IN (
            SELECT notification_id FROM student_notification_reads WHERE student_id='{$ssb_student_id}' AND notification_type='admin'
        )
        UNION ALL
        SELECT id FROM faculty_notifications WHERE division='{$student_division}' AND id NOT IN (
            SELECT notification_id FROM student_notification_reads WHERE student_id='{$ssb_student_id}' AND notification_type='faculty'
        )
    ) AS unread
");
if ($unread_q) {
    $unread_count = (int)(mysqli_fetch_assoc($unread_q)['c'] ?? 0);
}
?>
<div class="app">
    <!-- Sidebar -->
    <aside class="sidebar" id="stuSidebar">
        <!-- University Crest & Portal Identity -->
        <div class="sidebar-brand-box">
            <div class="sidebar-crest-row">
                <img src="../assets/images/mit_adt_crest.png" alt="MIT-ADT Crest" class="sidebar-crest-img" onerror="this.src='../assets/images/logo.png'">
                <div class="sidebar-crest-text">
                    <div class="sidebar-crest-title">MIT-ADT</div>
                    <div style="font-size:0.75rem; font-weight:700; color:#fff; letter-spacing:0.04em;">UNIVERSITY</div>
                    <div class="sidebar-crest-subtitle">PUNE, INDIA</div>
                </div>
            </div>

            <div class="sidebar-gold-line"></div>

            <div class="sidebar-product-identity">
                <i class="fa-solid fa-briefcase sidebar-product-icon"></i>
                <span class="sidebar-product-name">InternHub</span>
            </div>
            <div class="sidebar-product-tagline">Internship Management Portal</div>
        </div>

        <!-- Navigation Links -->
        <div class="sidebar-nav-container">
            <div class="sidebar-nav-section-title">Main Menu</div>
            <ul class="sidebar-nav-list">
                <li class="sidebar-nav-item <?php echo is_nav_active('dashboard.php', $activePage) ? 'active' : ''; ?>">
                    <a href="dashboard.php">
                        <i class="fa-solid fa-house"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-nav-item <?php echo is_nav_active('my_internship.php', $activePage) ? 'active' : ''; ?>">
                    <a href="my_internship.php">
                        <i class="fa-solid fa-briefcase"></i>
                        <span>My Internship</span>
                    </a>
                </li>
                <li class="sidebar-nav-item <?php echo is_nav_active('my_portfolio.php', $activePage) ? 'active' : ''; ?>">
                    <a href="my_portfolio.php">
                        <i class="fa-solid fa-address-card"></i>
                        <span>My Portfolio</span>
                    </a>
                </li>
                <li class="sidebar-nav-item <?php echo is_nav_active('community.php', $activePage) ? 'active' : ''; ?>">
                    <a href="community.php">
                        <i class="fa-solid fa-users"></i>
                        <span>Community</span>
                    </a>
                </li>
                <li class="sidebar-nav-item <?php echo is_nav_active('discover.php', $activePage) ? 'active' : ''; ?>">
                    <a href="discover.php">
                        <i class="fa-solid fa-compass"></i>
                        <span>Discover Students</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-nav-section-title" style="margin-top:20px;">Personal</div>
            <ul class="sidebar-nav-list">
                <li class="sidebar-nav-item <?php echo is_nav_active('settings.php', $activePage) ? 'active' : ''; ?>">
                    <a href="settings.php">
                        <i class="fa-solid fa-gear"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li class="sidebar-nav-item <?php echo is_nav_active('help.php', $activePage) ? 'active' : ''; ?>">
                    <a href="help.php">
                        <i class="fa-solid fa-headset"></i>
                        <span>Help & Support</span>
                    </a>
                </li>
            </ul>

            <!-- Bottom Dome Illustration -->
            <div class="sidebar-bottom-art">
                <img src="../assets/images/sidebar_dome_bottom.png" alt="MIT-ADT Dome">
            </div>
        </div>
    </aside>

    <!-- Mobile Backdrop -->
    <div class="sidebar-backdrop" id="stuSidebarBackdrop"></div>

    <!-- Main Workspace -->
    <main class="main">
        <!-- Universal Top Bar -->
        <header class="topbar">
            <!-- Mobile Menu Toggle Button -->
            <button type="button" class="mobile-toggle" id="stuMobileMenuToggle" aria-label="Toggle Navigation">
                <i class="fa-solid fa-bars"></i>
            </button>

            <!-- Greeting and Badges -->
            <div class="topbar-left">
                <div class="topbar-greeting-small">Welcome Back,</div>
                <div class="topbar-greeting-name">
                    <span><?php echo h($student_name); ?></span>
                    <span style="display:inline-block; animation: wave 1.5s infinite transform; transform-origin: 70% 70%;">👋</span>
                </div>
                <div class="topbar-badges-row">
                    <?php if (!empty($student_division)): ?>
                        <span class="topbar-badge"><?php echo h($student_division); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($student_department)): ?>
                        <span class="topbar-badge"><?php echo h($student_department); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($enrollment_no)): ?>
                        <span class="topbar-badge"><?php echo h($enrollment_no); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Global Search Pill -->
            <div class="topbar-search-box">
                <form action="discover.php" method="GET" style="margin:0;">
                    <input type="text" name="q" class="topbar-search-input" placeholder="Search internships, updates, resources..." autocomplete="off">
                    <i class="fa-solid fa-magnifying-glass topbar-search-icon"></i>
                </form>
            </div>

            <!-- Notification Bell & Profile Dropdown -->
            <div class="topbar-right">
                <button type="button" class="topbar-bell-btn" id="notifBellBtn" title="Notifications" onclick="location.href='dashboard.php#notifications';">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="topbar-bell-dot"></span>
                    <?php endif; ?>
                </button>

                <!-- Profile Dropdown Trigger -->
                <div class="topbar-profile-pill" id="topProfileTrigger" tabindex="0" role="button" aria-haspopup="true" aria-expanded="false">
                    <div class="topbar-avatar">
                        <?php if (!empty($profile_image)): ?>
                            <img src="<?php echo h($profile_image); ?>" alt="Avatar">
                        <?php else: ?>
                            <span><?php echo h($student_initial); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="topbar-user-info">
                        <div class="topbar-user-name"><?php echo h($student_name); ?></div>
                        <div class="topbar-user-role">Student</div>
                    </div>
                    <i class="fa-solid fa-chevron-down topbar-profile-chevron"></i>

                    <!-- Dropdown Content -->
                    <div class="profile-menu" id="topProfileMenu">
                        <a href="my_portfolio.php" class="profile-menu-item">
                            <i class="fa-solid fa-user"></i>
                            <span>View Profile</span>
                        </a>
                        <a href="settings.php" class="profile-menu-item">
                            <i class="fa-solid fa-gear"></i>
                            <span>Account Settings</span>
                        </a>
                        <div class="profile-menu-divider"></div>
                        <a href="../logout.php" class="profile-menu-item" style="color:#ef4444;">
                            <i class="fa-solid fa-right-from-bracket" style="color:#ef4444;"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>
