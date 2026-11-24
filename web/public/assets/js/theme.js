/**
 * Selector de Tema Claro / Oscuro (Dark & Light Mode)
 * Proyecto: tytBasculaAntigravity
 *
 * Aplica el tema guardado en localStorage sin parpadeo (FOUC)
 * y provee la función de alternancia global.
 */

// 1. Aplicación inmediata en el encabezado
(function() {
    const temaGuardado = localStorage.getItem('bascula_tema') || 'light';
    document.documentElement.setAttribute('data-theme', temaGuardado);
})();

/**
 * Alterna entre modo 'dark' y 'light' y guarda la preferencia.
 */
function alternarTema() {
    const actual = document.documentElement.getAttribute('data-theme') || 'light';
    const nuevo = (actual === 'dark') ? 'light' : 'dark';
    
    document.documentElement.setAttribute('data-theme', nuevo);
    localStorage.setItem('bascula_tema', nuevo);
    actualizarBotonesTema(nuevo);
}

/**
 * Actualiza el texto e icono de los botones de cambio de tema en pantalla.
 */
function actualizarBotonesTema(tema) {
    document.querySelectorAll('.btn-tema-toggle').forEach(boton => {
        if (tema === 'dark') {
            boton.innerHTML = '☀️ <span class="tema-texto">Modo Claro</span>';
            boton.setAttribute('title', 'Cambiar a Modo Claro');
        } else {
            boton.innerHTML = '🌙 <span class="tema-texto">Modo Oscuro</span>';
            boton.setAttribute('title', 'Cambiar a Modo Oscuro');
        }
    });
}

// Sincronizar los botones cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    const temaActual = document.documentElement.getAttribute('data-theme') || 'light';
    actualizarBotonesTema(temaActual);
});
