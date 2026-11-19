"""
Servicio API de Báscula (FastAPI)
Proyecto: tytBasculaAntigravity

Microservicio local de alto rendimiento y bajo consumo para gestión de lecturas de peso
(modo Real con indicador COM y modo Simulado para desarrollo).
"""

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from typing import Dict, Any, List

from api.simulator import simulador_global
from api.serial_reader import lector_global, listar_puertos_com


app = FastAPI(
    title="tytBasculaAntigravity Hardware API",
    description="Microservicio local de lectura para indicadores de báscula camionera.",
    version="1.0.0",
)

# Permitir consumo local desde Apache / PHP en el mismo equipo o red local
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["GET", "POST", "OPTIONS"],
    allow_headers=["*"],
)

# Estado global del microservicio
# Regla del proyecto: fuente predeterminada es 'real' (indicador físico).
estado_servicio = {
    "modo": "real",  # 'real' o 'simulado'
    "puerto_com": None,
    "baudios": 9600,
}


class ModoRequest(BaseModel):
    modo: str = Field(..., description="Modo de operación: 'real' o 'simulado'")


class PesoObjetivoRequest(BaseModel):
    peso_lb: float = Field(..., ge=0, description="Peso objetivo en libras a simular")


class SerialConfigRequest(BaseModel):
    puerto_com: str = Field(..., description="Puerto COM (ej: COM3)")
    baudios: int = Field(default=9600, description="Baudios (ej: 9600)")


@app.get("/")
def raiz():
    return {
        "sistema": "tytBasculaAntigravity Hardware API",
        "estado": "operativo",
        "modo_actual": estado_servicio["modo"],
    }


@app.get("/api/v1/salud")
def salud():
    return {
        "ok": True,
        "modo": estado_servicio["modo"],
        "puerto_com": estado_servicio["puerto_com"],
        "lector_activo": lector_global.corriendo,
    }


@app.get("/api/v1/peso")
@app.get("/api/v1/lecturas/actual")
def obtener_peso() -> Dict[str, Any]:
    """Retorna la lectura de peso actual en tiempo real."""
    if estado_servicio["modo"] == "real":
        lectura = lector_global.obtener_lectura()
    else:
        lectura = simulador_global.obtener_lectura()

    return lectura


@app.get("/api/v1/puertos")
def obtener_puertos() -> List[str]:
    """Retorna la lista de puertos COM detectados en Windows."""
    return listar_puertos_com()


@app.post("/api/v1/modo")
def cambiar_modo(req: ModoRequest):
    """
    Alterna entre Real y Simulado.
    En 'real', arranca el lector en background si el puerto está configurado.
    """
    nuevo_modo = req.modo.lower().strip()
    if nuevo_modo not in ["real", "simulado"]:
        raise HTTPException(status_code=400, detail="Modo no válido. Use 'real' o 'simulado'.")

    estado_servicio["modo"] = nuevo_modo

    if nuevo_modo == "real":
        if estado_servicio["puerto_com"]:
            lector_global.configurar(estado_servicio["puerto_com"], estado_servicio["baudios"])
            lector_global.iniciar()
    else:
        lector_global.detener()

    return {
        "ok": True,
        "mensaje": f"Modo cambiado a {nuevo_modo}",
        "modo": estado_servicio["modo"],
    }


@app.post("/api/v1/simulador/fijar")
def fijar_peso_simulado(req: PesoObjetivoRequest):
    """Permite en modo de desarrollo fijar un peso para probar pesadas."""
    simulador_global.fijar_peso_objetivo(req.peso_lb)
    return {
        "ok": True,
        "peso_objetivo": req.peso_lb,
    }


@app.post("/api/v1/simulador/camion")
def simular_entrada_camion(peso_bruto: float = 38500.0):
    """Atajo para simular la llegada de un camión con peso bruto."""
    simulador_global.simular_camion(peso_bruto)
    return {"ok": True, "mensaje": f"Simulando camión con {peso_bruto} lb"}


@app.post("/api/v1/simulador/despejar")
def simular_despeje():
    """Atajo para simular el despeje de la báscula a 0 lb."""
    simulador_global.simular_despeje()
    return {"ok": True, "mensaje": "Simulando báscula despejada (0 lb)"}


@app.post("/api/v1/configurar_serial")
def configurar_serial(req: SerialConfigRequest):
    """Configura el puerto COM y baudios para el modo real."""
    estado_servicio["puerto_com"] = req.puerto_com
    estado_servicio["baudios"] = req.baudios
    lector_global.configurar(req.puerto_com, req.baudios)

    if estado_servicio["modo"] == "real":
        lector_global.detener()
        lector_global.iniciar()

    return {
        "ok": True,
        "mensaje": f"Puerto {req.puerto_com} configurado a {req.baudios} baudios",
    }


@app.post("/api/v1/lector/detener")
def detener_lector_temporal():
    """Detiene temporalmente el lector serial para liberar el puerto COM en pruebas."""
    lector_global.detener()
    return {"ok": True, "lector_activo": lector_global.corriendo}


@app.post("/api/v1/lector/iniciar")
def iniciar_lector_temporal():
    """Reanuda el lector serial si el modo activo es 'real' y hay puerto configurado."""
    if estado_servicio["modo"] == "real" and estado_servicio["puerto_com"]:
        lector_global.configurar(estado_servicio["puerto_com"], estado_servicio["baudios"])
        lector_global.iniciar()
    return {"ok": True, "lector_activo": lector_global.corriendo}
