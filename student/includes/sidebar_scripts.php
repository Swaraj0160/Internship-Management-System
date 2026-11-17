<?php
/* -----------------------------------------------------------------
   InternHub — Student Shell Interactive Scripts
   Handles mobile sidebar off-canvas toggle and topbar profile menu
   ----------------------------------------------------------------- */
?>
<script>
(function () {
    // 1. Mobile Sidebar Controls
    var sidebar = document.getElementById('stuSidebar');
    var backdrop = document.getElementById('stuSidebarBackdrop');
    var mobileToggle = document.getElementById('stuMobileMenuToggle');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
    }

    if (mobileToggle) {
        mobileToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    // 2. Topbar Profile Dropdown
    var profileTrigger = document.getElementById('topProfileTrigger');
    var profileMenu = document.getElementById('topProfileMenu');

    if (profileTrigger && profileMenu) {
        function toggleProfileMenu(e) {
            e.stopPropagation();
            var isOpen = profileMenu.classList.contains('show');
            if (isOpen) {
                profileMenu.classList.remove('show');
                profileTrigger.setAttribute('aria-expanded', 'false');
            } else {
                profileMenu.classList.add('show');
                profileTrigger.setAttribute('aria-expanded', 'true');
            }
        }

        profileTrigger.addEventListener('click', toggleProfileMenu);
        profileTrigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleProfileMenu(e);
            }
        });

        document.addEventListener('click', function (e) {
            if (!profileTrigger.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // 3. Global Escape Key Handler
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeSidebar();
            if (profileMenu) {
                profileMenu.classList.remove('show');
                if (profileTrigger) profileTrigger.setAttribute('aria-expanded', 'false');
            }
        }
    });
})();
</script>
