<?php
/* -----------------------------------------------------------------
   NEW FILE — shared sidebar mobile-toggle JS for all admin pages.

   Include this once, right before </body> (after
   includes/sidebar_nav.php's opening tags have been closed with
   "</main></div>" by the including page). Identical logic to the
   setupMobileSidebar() function already used on admin/dashboard.php.
   ----------------------------------------------------------------- */
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    function setupMobileSidebar() {
        const sidebar = document.getElementById('adminSidebar') || document.getElementById('appSidebar');
        const backdrop = document.getElementById('adminSidebarBackdrop') || document.getElementById('sidebarBackdrop');
        const toggle = document.getElementById('adminMenuToggle') || document.getElementById('mobileMenuToggle');
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

    function setupProfileDropdown() {
        const trigger = document.getElementById('topbarUserDropdownTrigger') || document.getElementById('profileTrigger');
        const dropdown = document.getElementById('topbarUserDropdownPopover') || document.getElementById('profileDropdown');
        if (!trigger || !dropdown) return;

        function closeDropdown() {
            dropdown.classList.remove('open');
            dropdown.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
        }
        function openDropdown() {
            dropdown.classList.add('open');
            dropdown.style.display = 'block';
            trigger.setAttribute('aria-expanded', 'true');
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (dropdown.classList.contains('open') || dropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target) && !trigger.contains(e.target)) {
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
