(function () {
    const shell = document.getElementById('appShell');
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const backdrop = document.getElementById('sidebarBackdrop');
    const storageKey = 'zendy_sidebar_collapsed';

    if (!shell) return;

    if (localStorage.getItem(storageKey) === '1' && window.innerWidth > 991) {
        shell.classList.add('sidebar-collapsed');
    }

    collapseBtn?.addEventListener('click', () => {
        shell.classList.toggle('sidebar-collapsed');
        localStorage.setItem(storageKey, shell.classList.contains('sidebar-collapsed') ? '1' : '0');
    });

    function openMobile() {
        shell.classList.add('sidebar-mobile-open');
    }

    function closeMobile() {
        shell.classList.remove('sidebar-mobile-open');
    }

    mobileBtn?.addEventListener('click', openMobile);
    backdrop?.addEventListener('click', closeMobile);

    window.addEventListener('resize', () => {
        if (window.innerWidth > 991) {
            closeMobile();
        }
    });

    document.querySelectorAll('.sidebar-link').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 991) closeMobile();
        });
    });
})();
