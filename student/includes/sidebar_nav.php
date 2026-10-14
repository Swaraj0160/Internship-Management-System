<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar markup for all student pages.

   Include this immediately after <body>. It opens <div class="app">,
   prints the sidebar (same structure/links as the original
   student/dashboard.php sidebar), the mobile backdrop, and opens
   <main class="main"> with a small top row containing the mobile
   menu toggle button. The including page's own content follows
   right after this include, and the page MUST close with
   </main></div> before </body> (see includes/sidebar_scripts.php's
   comment for the exact closing tags).

   Self-sufficient: if the including page hasn't already computed
   $student_name / $student_division / $student_initial /
   $profile_image, this file looks them up itself from the session +
   users table, so it can be dropped into any student page without
   requiring that page's own query structure to match dashboard.php's.

   Set $activePage to this page's own filename (e.g. 'profile.php')
   before including this file so the correct nav link gets the
   "active" highlight. If not set, no link is highlighted.
   ----------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($student_name) || !isset($student_division) || !isset($student_initial) || !isset($profile_image)) {

    $ssb_student_id = (int)($_SESSION['user_id'] ?? 0);

    $ssb_stmt = $conn->prepare("SELECT full_name, division, profile_photo FROM users WHERE id=? LIMIT 1");
    $ssb_stmt->bind_param("i", $ssb_student_id);
    $ssb_stmt->execute();
    $ssb_row = $ssb_stmt->get_result()->fetch_assoc() ?: [];

    if (!isset($student_name)) {
        $student_name = $ssb_row['full_name'] ?: ($_SESSION['name'] ?? 'Student');
    }
    if (!isset($student_division)) {
        $student_division = $ssb_row['division'] ?? '';
    }
    if (!isset($student_initial)) {
        $student_initial = strtoupper(substr(trim((string)$student_name), 0, 1)) ?: 'S';
    }
    if (!isset($profile_image)) {
        $ssb_photo = trim($ssb_row['profile_photo'] ?? '');
        $profile_image = (!empty($ssb_photo) && file_exists(__DIR__ . "/../../uploads/profile_photos/" . $ssb_photo))
            ? "../uploads/profile_photos/" . rawurlencode($ssb_photo)
            : "";
    }
}

$activePage = $activePage ?? '';

if (!function_exists('sb_active')) {
    function sb_active($page, $current)
    {
        return $page === $current ? ' class="active"' : '';
    }
}
?>
<div class="app">
    <aside class="sidebar" id="stuSidebar">
        <div class="brand">
            <img src="../assets/images/logo.png" alt="MIT-ADT University">
            <div class="title">MIT-ADT University</div>
            <div class="subtitle">PUNE, INDIA</div>
        </div>

        <!-- -------------------------------------------------------------
             REDESIGNED (post-implementation audit — exact sidebar
             requirement): same functional profile dropdown pattern as
             admin/faculty. Profile, Change Password, and Logout moved
             out of the nav and into this dropdown.
             ------------------------------------------------------------- -->
        <button type="button" class="profile-mini profile-trigger" id="stuProfileTrigger" aria-haspopup="true" aria-expanded="false">
            <div class="avatar">
                <?php if (!empty($profile_image)): ?>
                    <img src="<?php echo h($profile_image); ?>" alt="Profile">
                <?php else: ?>
                    <span><?php echo h($student_initial ?: 'S'); ?></span>
                <?php endif; ?>
            </div>
            <div class="meta">
                <div class="name"><?php echo h($student_name); ?></div>
                <div class="small"><?php echo h($student_division ?: 'Student'); ?></div>
            </div>
            <i class="fa-solid fa-chevron-down profile-chevron"></i>
        </button>

        <div class="profile-dropdown" id="stuProfileDropdown">
            <div class="profile-dropdown-section-label">Account</div>
            <a href="profile.php"<?php echo sb_active('profile.php', $activePage); ?>><i class="fa-solid fa-user"></i>Profile</a>
            <a href="change_password.php"<?php echo sb_active('change_password.php', $activePage); ?>><i class="fa-solid fa-lock"></i>Change Password</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
        </div>

        <nav class="nav">
            <div class="nav-label">Workspace</div>
            <a href="dashboard.php"<?php echo sb_active('dashboard.php', $activePage); ?>><i class="fa-solid fa-house"></i>Dashboard</a>
            <a href="apply_internship.php"<?php echo sb_active('apply_internship.php', $activePage); ?>><i class="fa-solid fa-briefcase"></i>Apply Internship Form</a>
            <a href="my_internships.php"<?php echo sb_active('my_internships.php', $activePage); ?>><i class="fa-solid fa-list"></i>My Internships</a>
            <a href="add_update.php"<?php echo sb_active('add_update.php', $activePage); ?>><i class="fa-solid fa-calendar-days"></i>Weekly Updates</a>
            <a href="my_updates.php"<?php echo sb_active('my_updates.php', $activePage); ?>><i class="fa-solid fa-clipboard-list"></i>My Weekly Work</a>
            <a href="marks.php"<?php echo sb_active('marks.php', $activePage); ?>><i class="fa-solid fa-award"></i>Marks</a>
            <a href="upload_report.php"<?php echo sb_active('upload_report.php', $activePage); ?>><i class="fa-solid fa-file-arrow-up"></i>Final Internship Certificate</a>
            <a href="my_showcase.php"<?php echo sb_active('my_showcase.php', $activePage); ?>><i class="fa-solid fa-diagram-project"></i>My Internship Work</a>
        </nav>
    </aside>
    <div class="sidebar-backdrop" id="stuSidebarBackdrop"></div>
    <main class="main">
        <div class="sidebar-topbar">
            <button type="button" class="mobile-menu-toggle" id="stuMobileMenuToggle" aria-label="Toggle navigation menu">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
