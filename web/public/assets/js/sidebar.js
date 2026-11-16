/**
 * Control del Menú Lateral (Sidebar Moderno)
 * Proyecto: tytBasculaAntigravity
 *
 * Maneja el colapso en escritorio (modo iconos) y el menú tipo drawer en móviles/tablets.
 */

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (!sidebar) return;

    // 1. Restaurar estado colapsado previo en escritorio
    const estadoColapsado = localStorage.getItem('bascula_sidebar_colapsado') === 'true';
    if (estadoColapsado && window.innerWidth > 900) {
        sidebar.classList.add('sidebar-collapsed');
        document.body.classList.add('sidebar-is-collapsed');
    }

    // 2. Colapsar / Expandir en escritorio
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('sidebar-collapsed');
            const estaColapsado = sidebar.classList.contains('sidebar-collapsed');
            document.body.classList.toggle('sidebar-is-collapsed', estaColapsado);
            localStorage.setItem('bascula_sidebar_colapsado', estaColapsado);
        });
    }

    // 3. Abrir / Cerrar menú en dispositivos móviles
    if (mobileBtn) {
        mobileBtn.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('active');
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', () => {
            sidebar.classList.remove('mobile-open');
            backdrop.classList.remove('active');
        });
    }

    // 4. Actualizar mini-monitor de báscula en el menú lateral con lecturas en vivo
    document.addEventListener('bascula:lectura', (e) => {
        const detalle = e.detail;
        const elPeso = document.getElementById('sidebarPesoLabel');
        const elModo = document.getElementById('sidebarModoLabel');
        const elDot = document.getElementById('sidebarStatusDot');

        if (elPeso) {
            elPeso.textContent = Number(detalle.peso_lb || 0).toLocaleString('en-US') + ' LB';
        }

        if (elModo) {
            elModo.textContent = (detalle.modo || 'SIMULADO');
        }

        if (elDot) {
            elDot.className = 'bascula-status-dot ' + (detalle.estable ? 'dot-estable' : 'dot-inestable');
        }
    });
});
