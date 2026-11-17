    </main>
</div>

<!-- Faculty Portal Shared Scripts -->
<script>
(function() {
    // Mobile Sidebar Drawer
    const sidebar = document.getElementById('facultySidebar');
    const backdrop = document.getElementById('facultySidebarBackdrop');
    const toggle = document.getElementById('facultyMenuToggle');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (toggle) {
        toggle.addEventListener('click', (e) => {
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

    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) closeSidebar();
    });

    // Topbar Profile Popover
    const profileBtn = document.getElementById('topbarUserDropdownTrigger');
    const profilePopover = document.getElementById('topbarUserDropdownPopover');

    if (profileBtn && profilePopover) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profilePopover.classList.toggle('open');
        });

        document.addEventListener('click', (e) => {
            if (!profilePopover.contains(e.target) && e.target !== profileBtn) {
                profilePopover.classList.remove('open');
            }
        });
    }

    // Ctrl + K Global Search Shortcut
    window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('globalSearchInput');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });
})();
</script>
