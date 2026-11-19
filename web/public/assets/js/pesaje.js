/**
 * Monitor en Vivo de Báscula (FastAPI + Vanilla JS)
 * Proyecto: tytBasculaAntigravity
 *
 * Consulta periódicamente el microservicio local de lectura y actualiza
 * el display de peso, estado de estabilidad y modo de operación.
 */

class MonitorBascula {
    constructor(opciones = {}) {
        this.apiUrl = opciones.apiUrl || 'http://127.0.0.1:8018/api/v1/peso';
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
});
