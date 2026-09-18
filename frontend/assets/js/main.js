// Auto-hide flash messages after 4 seconds
document.addEventListener('DOMContentLoaded', function () {
    const flash = document.querySelector('.flash');
    if (flash) {
        setTimeout(() => flash.remove(), 4000);
    }

    const navToggle = document.querySelector('.nav-toggle');
    const navLinks = document.querySelector('.nav-links');
    if (navToggle && navLinks) {
        navToggle.addEventListener('click', () => {
            navLinks.classList.toggle('nav-open');
            navToggle.classList.toggle('open');
        });
        navLinks.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                if (navLinks.classList.contains('nav-open')) {
                    navLinks.classList.remove('nav-open');
                    navToggle.classList.remove('open');
                }
            });
        });
    }

    const adminSidebarToggle = document.querySelector('.admin-sidebar-toggle');
    const adminSidebar = document.querySelector('.admin-sidebar');
    const adminBackdrop = document.querySelector('.admin-sidebar-backdrop');
    if (adminSidebarToggle && adminSidebar) {
        const closeSidebar = () => {
            adminSidebar.classList.remove('open');
            adminSidebarToggle.classList.remove('open');
            adminSidebarToggle.setAttribute('aria-expanded', 'false');
            adminSidebar.setAttribute('aria-hidden', 'true');
        };

        const openSidebar = () => {
            adminSidebar.classList.add('open');
            adminSidebarToggle.classList.add('open');
            adminSidebarToggle.setAttribute('aria-expanded', 'true');
            adminSidebar.setAttribute('aria-hidden', 'false');
        };

        adminSidebarToggle.addEventListener('click', () => {
            if (adminSidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        adminSidebar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                if (adminSidebar.classList.contains('open')) {
                    closeSidebar();
                }
            });
        });

        if (adminBackdrop) {
            adminBackdrop.addEventListener('click', closeSidebar);
        }
    }

    // Confirm on dangerous actions
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });
});
