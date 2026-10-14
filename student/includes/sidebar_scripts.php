<?php
/* -----------------------------------------------------------------
   NEW FILE — mobile sidebar toggle JS for student pages.

   Include this right before </body>. Requires the including page to
   have closed the markup opened by sidebar_nav.php as:

       </main>
   </div>   (closes .app)

   right before this include (any page-specific content/scripts can
   sit between the page's own closing tags and </main></div>).
   ----------------------------------------------------------------- */
?>
<script>
(function () {
    var sidebar = document.getElementById('stuSidebar');
    var backdrop = document.getElementById('stuSidebarBackdrop');
    var toggle = document.getElementById('stuMobileMenuToggle');

    if (!sidebar || !backdrop || !toggle) return;

    function openSidebar() {
        sidebar.classList.add('open');
        backdrop.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('show');
    }

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    backdrop.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        });
    });

    /* NEW (post-implementation audit — exact sidebar requirement):
       functional profile dropdown, same pattern as admin/faculty. */
    var profileTrigger = document.getElementById('stuProfileTrigger');
    var profileDropdown = document.getElementById('stuProfileDropdown');

    if (profileTrigger && profileDropdown) {
        function closeProfileDropdown() {
            profileDropdown.classList.remove('open');
            profileTrigger.setAttribute('aria-expanded', 'false');
        }
        function openProfileDropdown() {
            profileDropdown.classList.add('open');
            profileTrigger.setAttribute('aria-expanded', 'true');
        }

        profileTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (profileDropdown.classList.contains('open')) {
                closeProfileDropdown();
            } else {
                openProfileDropdown();
            }
        });

        document.addEventListener('click', function (e) {
            if (!profileDropdown.contains(e.target) && e.target !== profileTrigger) {
                closeProfileDropdown();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeProfileDropdown();
        });
    }
})();
</script>
