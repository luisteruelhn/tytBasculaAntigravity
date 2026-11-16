"""
Simulador de Báscula para Entorno de Desarrollo
Proyecto: tytBasculaAntigravity

Genera lecturas de peso simuladas con variación natural y control de estabilidad
para permitir el desarrollo y pruebas completas sin necesidad de hardware físico.
"""

import math
import random
import time
from typing import Dict, Any


class SimuladorBáscula:
    """
    Simula el comportamiento de una báscula camionera.
    Permite fijar un peso objetivo o simular lecturas en movimiento hasta estabilizarse.
    """

    def __init__(self, peso_base: float = 0.0):
        self.peso_objetivo: float = peso_base
        self.peso_actual: float = peso_base
        self.estable: bool = True
        self.ultima_actualizacion: float = time.monotonic()
        self.modo_escenario: str = "estatico"  # 'estatico', 'subiendo_camion', 'vacio'

    def fijar_peso_objetivo(self, peso_lb: float) -> None:
        """Permite a la interfaz o pruebas fijar un peso arbitrario (ej: 28,450 lb)."""
        self.peso_objetivo = max(0.0, float(peso_lb))
        self.estable = False

    def simular_camion(self, peso_bruto_lb: float = 42500.0) -> None:
        """Simula la entrada de un camión a la báscula."""
        self.peso_objetivo = float(peso_bruto_lb)
        self.estable = False
        self.modo_escenario = "subiendo_camion"

    def simular_despeje(self) -> None:
        """Simula el camión saliendo de la báscula (regreso a cero)."""
        self.peso_objetivo = 0.0
        self.estable = False
        self.modo_escenario = "vacio"

    def obtener_lectura(self) -> Dict[str, Any]:
        """
        Calcula y retorna la lectura de peso en este instante.
        Retorna formato compatible con el protocolo industrial.
        """
        ahora = time.monotonic()
        delta = ahora - self.ultima_actualizacion
        self.ultima_actualizacion = ahora

        diferencia = self.peso_objetivo - self.peso_actual

        if abs(diferencia) > 10.0:
            # En movimiento hacia el objetivo (velocidad de aproximación)
            paso = diferencia * min(1.0, delta * 3.5)
            # Agregar una ligera fluctuación mecánica mientras el vehículo se acomoda
            ruido = random.uniform(-40.0, 40.0)
            self.peso_actual += paso + ruido
            self.estable = False
        else:
            # Cerca del objetivo: estabilizado con mínima variación de división (ej: 10 o 20 lb)
            self.peso_actual = self.peso_objetivo
            self.estable = True

        # Redondear a múltiplos de 10 o 20 libras (división típica industrial)
        peso_redondeado = round(self.peso_actual / 10.0) * 10
        if peso_redondeado < 0:
            peso_redondeado = 0

        return {
            "peso_lb": int(peso_redondeado),
            "estable": bool(self.estable),
            "unidad": "lb",
            "origen": "simulado",
            "timestamp": time.time(),
        }


# Instancia global del simulador para el servicio
simulador_global = SimuladorBáscula(peso_base=0.0)
