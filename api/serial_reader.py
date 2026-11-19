"""
Lector Serial Directo de Indicadores de Báscula (Win32 API)
Proyecto: tytBasculaAntigravity

Lee indicadores de peso industriales (LP7510, C18 y compatibles) usando las APIs
nativas de Windows (kernel32.dll) sin alterar configuraciones de hardware y
manteniendo acceso seguro al puerto.
"""

import ctypes
import os
import re
import threading
import time
from ctypes import wintypes
from decimal import Decimal
from typing import Dict, Any, Optional, List


# Estructuras nativas de Win32 para configuración COM
class DCB(ctypes.Structure):
    _fields_ = [
        ("DCBlength", wintypes.DWORD),
        ("BaudRate", wintypes.DWORD),
        ("flags", wintypes.DWORD),
        ("wReserved", wintypes.WORD),
        ("XonLim", wintypes.WORD),
        ("XoffLim", wintypes.WORD),
        ("ByteSize", ctypes.c_ubyte),
        ("Parity", ctypes.c_ubyte),
        ("StopBits", ctypes.c_ubyte),
        ("XonChar", ctypes.c_char),
        ("XoffChar", ctypes.c_char),
        ("ErrorChar", ctypes.c_char),
        ("EofChar", ctypes.c_char),
        ("EvtChar", ctypes.c_char),
        ("wReserved1", wintypes.WORD),
    ]


class COMSTAT(ctypes.Structure):
    _fields_ = [
        ("flags", wintypes.DWORD),
        ("cbInQue", wintypes.DWORD),
        ("cbOutQue", wintypes.DWORD),
    ]


def listar_puertos_com() -> List[str]:
    """Detecta los puertos COM presentes en el equipo revisando hasta COM32."""
    if os.name != "nt":
        return []

    puertos = []
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateFileW.restype = wintypes.HANDLE
    kernel.CreateFileW.argtypes = [
        wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
        wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE
    ]
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    kernel.CloseHandle.restype = wintypes.BOOL

    for i in range(1, 33):
        nombre = f"COM{i}"
        ruta = f"\\\\.\\{nombre}"
        # Intentar apertura de consulta
        handle = kernel.CreateFileW(ruta, 0x80000000, 0, None, 3, 0x80, None)
        if handle != ctypes.c_void_p(-1).value:
            puertos.append(nombre)
            kernel.CloseHandle(handle)
        else:
            error = ctypes.get_last_error()
            # Error 5 = Acceso denegado (el puerto existe pero está en uso por otro programa)
            if error == 5:
                puertos.append(f"{nombre} (en uso)")

    return puertos


def parsear_trama_lp7510(trama_bytes: bytes) -> Optional[Dict[str, Any]]:
    """
    Parsea una trama continua de indicador industrial LP7510 / C18.
    Formato típico: ST,GS,+  15420 lb\r\n  o  US,GS,+  15400 lb\r\n
    """
    texto = trama_bytes.strip(b" \r\n\t")
    # Expresión regular para validar trama estándar de pesaje industrial
    patron = rb"^(ST|US|OL)\s*,\s*(GS|NT)\s*,\s*([+-])\s*(\d{1,9}(?:\.\d{1,3})?)\s*(lb|lbs|kg)$"
    coincidencia = re.fullmatch(patron, texto, re.IGNORECASE)
    if not coincidencia:
        return None

    estado_trama, modo, signo, valor_str, unidad = coincidencia.groups()
    estado_upper = estado_trama.upper()

    if estado_upper == b"OL":
        # Sobrecarga de báscula
        return {"error": "Sobrecarga en el indicador de báscula"}

    try:
        peso_num = int(float(valor_str.decode("ascii")))
        if signo == b"-":
            peso_num = -peso_num
    except ValueError:
        return None

    unidad_norm = unidad.decode("ascii").lower()
    if unidad_norm == "lbs":
        unidad_norm = "lb"

    return {
        "peso_lb": peso_num,
        "estable": (estado_upper == b"ST"),
        "unidad": unidad_norm,
        "origen": "real",
        "timestamp": time.time(),
    }


class LectorSerialWin32:
    """Gestiona la lectura en segundo plano del puerto COM real."""

    def __init__(self):
        self.puerto: Optional[str] = None
        self.baudios: int = 9600
        self.corriendo: bool = False
        self.hilo: Optional[threading.Thread] = None
        self.ultima_lectura: Optional[Dict[str, Any]] = None
        self.ultimo_error: Optional[str] = "Lector serial no iniciado"
        self.timestamp_recepcion: float = 0.0

    def configurar(self, puerto: str, baudios: int = 9600) -> None:
        self.puerto = puerto
        self.baudios = baudios

    def iniciar(self) -> None:
        if self.corriendo:
            return
        if not self.puerto:
            self.ultimo_error = "Puerto COM no configurado"
            return

        self.corriendo = True
        self.hilo = threading.Thread(target=self._bucle_lectura, daemon=True)
        self.hilo.start()

    def detener(self) -> None:
        self.corriendo = False
        if self.hilo and self.hilo.is_alive():
            self.hilo.join(timeout=1.5)

    def _configurar_sesion_serial(self, kernel: Any, handle: Any) -> Optional[DCB]:
        """Guarda el DCB actual y aplica 9600/baudios 8N1 solo en la sesión abierta."""
        kernel.GetCommState.argtypes = [wintypes.HANDLE, ctypes.POINTER(DCB)]
        kernel.GetCommState.restype = wintypes.BOOL
        kernel.SetCommState.argtypes = [wintypes.HANDLE, ctypes.POINTER(DCB)]
        kernel.SetCommState.restype = wintypes.BOOL

        dcb_original = DCB()
        dcb_original.DCBlength = ctypes.sizeof(DCB)
        if not kernel.GetCommState(handle, ctypes.byref(dcb_original)):
            return None

        dcb_sesion = DCB()
        ctypes.memmove(ctypes.byref(dcb_sesion), ctypes.byref(dcb_original), ctypes.sizeof(DCB))
        dcb_sesion.BaudRate = int(self.baudios)
        dcb_sesion.ByteSize = 8
        dcb_sesion.Parity = 0   # NOPARITY
        dcb_sesion.StopBits = 0 # ONESTOPBIT (1 bit de parada)
        kernel.SetCommState(handle, ctypes.byref(dcb_sesion))
        return dcb_original

    def _restaurar_sesion_serial(self, kernel: Any, handle: Any, dcb_original: Optional[DCB]) -> None:
        """Restaura el DCB original al cerrar el puerto sin alterar el controlador de Windows."""
        if dcb_original is not None:
            kernel.SetCommState(handle, ctypes.byref(dcb_original))

    def _bucle_lectura(self) -> None:
        """Hilo de lectura continua con kernel32."""
        kernel = ctypes.WinDLL("kernel32", use_last_error=True)
        kernel.CreateFileW.restype = wintypes.HANDLE
        ruta = f"\\\\.\\{self.puerto}"

        handle = kernel.CreateFileW(ruta, 0x80000000, 0, None, 3, 0x80, None)
        if handle == ctypes.c_void_p(-1).value:
            self.ultimo_error = f"No se pudo abrir {self.puerto}. Verifica conexión."
            self.corriendo = False
            return

        kernel.ClearCommError.argtypes = [wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD), ctypes.POINTER(COMSTAT)]
        kernel.ReadFile.argtypes = [wintypes.HANDLE, wintypes.LPVOID, wintypes.DWORD, ctypes.POINTER(wintypes.DWORD), wintypes.LPVOID]
        kernel.CloseHandle.argtypes = [wintypes.HANDLE]

        dcb_original = self._configurar_sesion_serial(kernel, handle)
        buffer = b""
        try:
            while self.corriendo:
                comm = COMSTAT()
                errores = wintypes.DWORD()
                if not kernel.ClearCommError(handle, ctypes.byref(errores), ctypes.byref(comm)):
                    break

                bytes_en_cola = comm.cbInQue
                if bytes_en_cola > 0:
                    datos = ctypes.create_string_buffer(min(bytes_en_cola, 512))
                    leidos = wintypes.DWORD()
                    if kernel.ReadFile(handle, datos, min(bytes_en_cola, 512), ctypes.byref(leidos), None):
                        buffer += datos.raw[: leidos.value]
                        while b"\r" in buffer or b"\n" in buffer:
                            linea, buffer = re.split(b"[\r\n]+", buffer, maxsplit=1)
                            lectura = parsear_trama_lp7510(linea)
                            if lectura and "peso_lb" in lectura:
                                self.ultima_lectura = lectura
                                self.timestamp_recepcion = time.monotonic()
                                self.ultimo_error = None

                time.sleep(0.05)
        finally:
            self._restaurar_sesion_serial(kernel, handle, dcb_original)
            kernel.CloseHandle(handle)
            self.corriendo = False

    def obtener_lectura(self) -> Dict[str, Any]:
        # Si no se han recibido datos en más de 2 segundos, reportar lectura vencida
        if not self.corriendo or self.ultima_lectura is None:
            return {
                "peso_lb": 0,
                "estable": False,
                "unidad": "lb",
                "origen": "real",
                "error": self.ultimo_error or "Sin lectura del indicador",
                "timestamp": time.time(),
            }

        if time.monotonic() - self.timestamp_recepcion > 2.0:
            return {
                "peso_lb": 0,
                "estable": False,
                "unidad": "lb",
                "origen": "real",
                "error": "Lectura serial vencida (sin datos recientes)",
                "timestamp": time.time(),
            }

        return self.ultima_lectura


# Instancia global del lector
lector_global = LectorSerialWin32()
