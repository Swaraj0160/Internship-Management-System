<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar mobile-toggle JS for all admin pages.

   Include this once, right before </body> (after
   includes/sidebar_nav.php's opening tags have been closed with
   "</main></div>" by the including page). Identical logic to the
   setupMobileSidebar() function already used on admin/dashboard.php.
   ----------------------------------------------------------------- */
?>
<script>
(function () {
    function setupMobileSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const toggle = document.getElementById('mobileMenuToggle');
        if (!sidebar || !backdrop || !toggle) return;
        function openSidebar() {
            sidebar.classList.add('open');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function closeSidebar() {
            sidebar.classList.remove('open');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }
        toggle.addEventListener('click', () => {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
        backdrop.addEventListener('click', closeSidebar);
        sidebar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 992) closeSidebar();
            });
        });
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) closeSidebar();
        });
    }
    /* -----------------------------------------------------------------
       NEW (post-implementation audit — exact sidebar requirement):
       makes the profile-mini block a real functional dropdown toggle
       for Change Password / Settings / Logout / Risk Insights /
       Export Students, which were moved out of the primary nav.
       ----------------------------------------------------------------- */
    function setupProfileDropdown() {
        const trigger = document.getElementById('profileTrigger');
        const dropdown = document.getElementById('profileDropdown');
        if (!trigger || !dropdown) return;

        function closeDropdown() {
            dropdown.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        }
        function openDropdown() {
            dropdown.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (dropdown.classList.contains('open')) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target) && e.target !== trigger) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDropdown();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupMobileSidebar);
        document.addEventListener('DOMContentLoaded', setupProfileDropdown);
    } else {
        setupMobileSidebar();
        setupProfileDropdown();
    }
})();
</script>
