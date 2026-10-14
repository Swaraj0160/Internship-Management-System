<?php
/* NEW FILE — shared sidebar mobile-toggle JS for all faculty pages.
   Same pattern as admin/includes/sidebar_scripts.php. Include right
   before </body>, after the including page has closed
   "</main></div>". */
?>
<script>
(function () {
    function setupMobileSidebar() {
        const sidebar = document.getElementById('fsbSidebar');
        const backdrop = document.getElementById('fsbSidebarBackdrop');
        const toggle = document.getElementById('fsbMobileMenuToggle');
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
    /* NEW (post-implementation audit — exact sidebar requirement):
       functional profile dropdown, same pattern as admin. */
    function setupProfileDropdown() {
        const trigger = document.getElementById('fsbProfileTrigger');
        const dropdown = document.getElementById('fsbProfileDropdown');
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
