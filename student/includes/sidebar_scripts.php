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
    var notifBellBtn = document.getElementById('notifBellBtn');
    var notifMenu = document.getElementById('topNotifMenu');

    if (profileTrigger && profileMenu) {
        function toggleProfileMenu(e) {
            e.stopPropagation();
            if (notifMenu) notifMenu.classList.remove('show');
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
    }

    if (notifBellBtn && notifMenu) {
        function toggleNotifMenu(e) {
            e.stopPropagation();
            if (profileMenu) profileMenu.classList.remove('show');
            var isOpen = notifMenu.classList.contains('show');
            if (isOpen) {
                notifMenu.classList.remove('show');
                notifBellBtn.setAttribute('aria-expanded', 'false');
            } else {
                notifMenu.classList.add('show');
                notifBellBtn.setAttribute('aria-expanded', 'true');
            }
        }

        notifBellBtn.addEventListener('click', toggleNotifMenu);
    }

    document.addEventListener('click', function (e) {
        if (profileTrigger && profileMenu && !profileTrigger.contains(e.target) && !profileMenu.contains(e.target)) {
            profileMenu.classList.remove('show');
            profileTrigger.setAttribute('aria-expanded', 'false');
        }
        if (notifBellBtn && notifMenu && !notifBellBtn.contains(e.target) && !notifMenu.contains(e.target)) {
            notifMenu.classList.remove('show');
            notifBellBtn.setAttribute('aria-expanded', 'false');
        }
    });

    // 3. Global Escape Key Handler
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeSidebar();
            if (profileMenu) {
                profileMenu.classList.remove('show');
                if (profileTrigger) profileTrigger.setAttribute('aria-expanded', 'false');
            }
            if (notifMenu) {
                notifMenu.classList.remove('show');
                if (notifBellBtn) notifBellBtn.setAttribute('aria-expanded', 'false');
            }
        }
    });
})();
</script>
