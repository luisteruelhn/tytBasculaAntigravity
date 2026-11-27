/**
 * Monitor en Vivo de Báscula (FastAPI + Vanilla JS)
 * Proyecto: tytBasculaAntigravity
 *
 * Consulta periódicamente el microservicio local de lectura y actualiza
 * el display de peso, estado de estabilidad y modo de operación.
 */

class MonitorBascula {
    constructor(opciones = {}) {
        this.apiUrl = opciones.apiUrl || 'api_peso_local.php?accion=peso';
        this.intervaloMs = opciones.intervaloMs || 350;
        this.temporizador = null;
        this.pesoActual = 0;
        this.esEstable = false;
        this.modoActual = 'real';

        // Elementos del DOM
        this.elDisplayValor = document.getElementById('displayPesoValor');
        this.elDisplayUnidad = document.getElementById('displayPesoUnidad');
        this.elBadgeEstable = document.getElementById('badgeEstable');
        this.elBadgeModo = document.getElementById('badgeModo');
        this.elEstadoServicio = document.getElementById('estadoServicioApi');

        this.iniciar();
    }

    iniciar() {
        this.consultarPeso();
        this.temporizador = setInterval(() => this.consultarPeso(), this.intervaloMs);
    }

    detener() {
        if (this.temporizador) {
            clearInterval(this.temporizador);
            this.temporizador = null;
        }
    }

    async consultarPeso() {
        try {
            const respuesta = await fetch(this.apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });

            if (!respuesta.ok) {
                throw new Error(`Error HTTP: ${respuesta.status}`);
            }

            const datos = await respuesta.json();
            this.actualizarUI(datos);
        } catch (error) {
            this.mostrarErrorServicio();
        }
    }

    actualizarUI(datos) {
        this.pesoActual = Number(datos.peso_lb || 0);
        this.esEstable = Boolean(datos.estable);
        this.modoActual = String(datos.origen || 'real').toUpperCase();

        if (datos.error) {
            if (this.elDisplayValor) {
                this.elDisplayValor.textContent = '---';
            }
            if (this.elBadgeEstable) {
                this.elBadgeEstable.textContent = String(datos.error).slice(0, 38).toUpperCase();
                this.elBadgeEstable.title = String(datos.error);
                this.elBadgeEstable.className = 'badge badge-inestable';
            }
            if (this.elBadgeModo) {
                this.elBadgeModo.textContent = this.modoActual;
                this.elBadgeModo.className = 'badge badge-modo';
            }
            return;
        }

        // 1. Actualizar número de peso formateado (ej: "15,420")
        if (this.elDisplayValor) {
            this.elDisplayValor.textContent = this.pesoActual.toLocaleString('en-US');
        }

        // 2. Actualizar badge de estabilidad
        if (this.elBadgeEstable) {
            if (this.esEstable) {
                this.elBadgeEstable.textContent = 'ESTABLE';
                this.elBadgeEstable.className = 'badge badge-estable';
            } else {
                this.elBadgeEstable.textContent = 'EN MOVIMIENTO';
                this.elBadgeEstable.className = 'badge badge-inestable';
            }
        }

        // 3. Actualizar badge de modo (REAL / SIMULADO)
        if (this.elBadgeModo) {
            this.elBadgeModo.textContent = this.modoActual;
            this.elBadgeModo.className = 'badge badge-modo';
        }

        // 4. Notificar a formularios que requieran el peso actual
        document.dispatchEvent(new CustomEvent('bascula:lectura', {
            detail: {
                peso_lb: this.pesoActual,
                estable: this.esEstable,
                modo: this.modoActual
            }
        }));
    }

    mostrarErrorServicio() {
        if (this.elDisplayValor) {
            this.elDisplayValor.textContent = '---';
        }
        if (this.elBadgeEstable) {
            this.elBadgeEstable.textContent = 'DESCONECTADO';
            this.elBadgeEstable.className = 'badge badge-inestable';
        }
    }
}

// Iniciar automáticamente al cargar la página si existen los elementos y no es vista remota/solo consulta
document.addEventListener('DOMContentLoaded', () => {
    const elDisplay = document.getElementById('displayPesoValor');
    if (elDisplay && elDisplay.dataset.modoRemoto !== '1') {
        window.monitorBascula = new MonitorBascula();
    }
    if (document.getElementById('btnToggleLecturaComLive')) {
        consultarEstadoLecturaComLive();
    }
});

let lecturaComLiveActiva = false;

function actualizarBotonLecturaComLive(activo) {
    lecturaComLiveActiva = Boolean(activo);
    const btn = document.getElementById('btnToggleLecturaComLive');
    if (!btn) return;
    btn.textContent = lecturaComLiveActiva ? '⏹️ Parar' : '▶️ Arrancar';
    btn.style.background = lecturaComLiveActiva ? '#b91c1c' : '#1e40af';
    btn.title = lecturaComLiveActiva ? 'Detener lectura y liberar puerto COM' : 'Arrancar lectura del indicador';
}

async function consultarEstadoLecturaComLive() {
    try {
        const resp = await fetch('api_peso_local.php?accion=salud', { credentials: 'same-origin' });
        if (resp.ok) {
            const s = await resp.json();
            actualizarBotonLecturaComLive(Boolean(s.lector_activo));
        } else {
            actualizarBotonLecturaComLive(false);
        }
    } catch (e) {
        actualizarBotonLecturaComLive(false);
    }
}

async function alternarLecturaComLive() {
    const btn = document.getElementById('btnToggleLecturaComLive');
    if (!btn) return;
    const accion = lecturaComLiveActiva ? 'parar' : 'arrancar';
    btn.disabled = true;
    btn.textContent = accion === 'arrancar' ? 'Iniciando...' : 'Deteniendo...';

    try {
        const resp = await fetch('api_bascula_control.php?accion=' + accion, {
            method: 'POST',
            credentials: 'same-origin',
        });
        const data = await resp.json();
        if (!data.ok) {
            alert(data.error || 'No se pudo cambiar el estado del lector.');
        }
        await consultarEstadoLecturaComLive();
    } catch (e) {
        alert('Error de comunicación con el servidor local.');
        await consultarEstadoLecturaComLive();
    } finally {
        btn.disabled = false;
    }
}
