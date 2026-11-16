"""Muestra en flujo los datos recibidos por un puerto serial de Windows."""
import ctypes
import json
import os
import re
import sys
import time
from ctypes import wintypes


class DCB(ctypes.Structure):
    _fields_ = [
        ("DCBlength", wintypes.DWORD), ("BaudRate", wintypes.DWORD),
        ("flags", wintypes.DWORD), ("wReserved", wintypes.WORD),
        ("XonLim", wintypes.WORD), ("XoffLim", wintypes.WORD),
        ("ByteSize", ctypes.c_ubyte), ("Parity", ctypes.c_ubyte),
        ("StopBits", ctypes.c_ubyte), ("XonChar", ctypes.c_char),
        ("XoffChar", ctypes.c_char), ("ErrorChar", ctypes.c_char),
        ("EofChar", ctypes.c_char), ("EvtChar", ctypes.c_char),
        ("wReserved1", wintypes.WORD),
    ]


class COMSTAT(ctypes.Structure):
    _fields_ = [("flags", wintypes.DWORD), ("cbInQue", wintypes.DWORD),
                ("cbOutQue", wintypes.DWORD)]


def emit(ok, message, data=b""):
    print(json.dumps({"ok": ok, "estado": 'datos' if data else ('esperando' if ok else 'error'), "mensaje": message, "bytes": len(data),
                      "hex": data.hex(" ").upper(),
                      "texto": data.decode("ascii", errors="replace")},
                     ensure_ascii=True, separators=(",", ":")), flush=True)


def run(config, output=emit, should_stop=lambda: False, state_output=None):
    def state(value):
        if state_output is not None:
            state_output(value)
    port = config.get("puerto_com")
    if os.name != "nt" or not isinstance(port, str) or not re.fullmatch(r"COM[1-9][0-9]{0,2}", port):
        output(False, "Indica un puerto COM válido de este equipo.")
        return

    baud, data_bits = config.get("baudios"), config.get("bits_datos")
    parity_name, stop_name, flow = config.get("paridad"), config.get("bits_parada"), config.get("control_flujo")
    if not all(isinstance(value, str) for value in (baud, data_bits, parity_name, stop_name, flow)):
        output(False, "Completa baudios, bits de datos, paridad, parada y control de flujo antes de probar.")
        return
    if data_bits not in {"5", "6", "7", "8"} or parity_name not in {"N", "E", "O", "M", "S"} or stop_name not in {"1", "1.5", "2"} or flow not in {"NINGUNO", "RTS_CTS", "XON_XOFF", "DSR_DTR"}:
        output(False, "Selecciona parámetros seriales válidos.")
        return

    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateFileW.restype = wintypes.HANDLE
    kernel.CreateFileW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
                                   wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
    state('abriendo')
    handle = kernel.CreateFileW("\\\\.\\" + port, 0x80000000, 0, None, 3, 0x80, None)
    if handle == ctypes.c_void_p(-1).value:
        output(False, f"Windows no pudo abrir {port} (error {ctypes.get_last_error()}). Comprueba que esté conectado y libre.")
        return

    state('abierto')

    kernel.GetCommState.argtypes = [wintypes.HANDLE, ctypes.POINTER(DCB)]
    kernel.GetCommState.restype = wintypes.BOOL
    kernel.ClearCommError.argtypes = [wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD), ctypes.POINTER(COMSTAT)]
    kernel.ClearCommError.restype = wintypes.BOOL
    kernel.ReadFile.argtypes = [wintypes.HANDLE, wintypes.LPVOID, wintypes.DWORD,
                                ctypes.POINTER(wintypes.DWORD), wintypes.LPVOID]
    kernel.ReadFile.restype = wintypes.BOOL
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    kernel.CloseHandle.restype = wintypes.BOOL

    try:
        dcb = DCB()
        dcb.DCBlength = ctypes.sizeof(DCB)
        if not kernel.GetCommState(handle, ctypes.byref(dcb)):
            output(False, f"No se pudo consultar la configuración actual de {port}.")
            return
        parity = {0: "N", 1: "O", 2: "E", 3: "M", 4: "S"}.get(dcb.Parity, str(dcb.Parity))
        stop = {0: "1", 1: "1.5", 2: "2"}.get(dcb.StopBits, str(dcb.StopBits))
        flow_parts = []
        if dcb.flags & (1 << 2): flow_parts.append('CTS')
        if dcb.flags & (1 << 3): flow_parts.append('DSR')
        if dcb.flags & ((1 << 8) | (1 << 9)): flow_parts.append('XON/XOFF')
        flow_actual = ', '.join(flow_parts) or 'sin control de flujo'
        settings = f"{port} · {dcb.BaudRate} baudios · {dcb.ByteSize} bits · paridad {parity} · parada {stop} · {flow_actual} · configuración existente sin cambios"
        state({'estado': 'configuracion', 'parametros': settings})
        output(True, f"Escuchando {port} con su configuración existente, sin modificar parámetros ni enviar datos.")
        deadline = float('inf') if config.get('continuo') is True else time.monotonic() + 30
        last_heartbeat = time.monotonic()
        while time.monotonic() < deadline and not should_stop():
            comm = COMSTAT()
            errors = wintypes.DWORD()
            if not kernel.ClearCommError(handle, ctypes.byref(errors), ctypes.byref(comm)):
                output(False, f"No se pudo consultar la recepción de {port} (error {ctypes.get_last_error()}).")
                return
            if errors.value:
                output(False, f"El puerto informó errores de comunicación (código {errors.value}); no se cambió su configuración.")
                return
            buffer = ctypes.create_string_buffer(128)
            count = wintypes.DWORD()
            # Leer solo bytes ya recibidos: no esperar a llenar un bloque de 128.
            available = min(comm.cbInQue, len(buffer))
            if available and not kernel.ReadFile(handle, buffer, available, ctypes.byref(count), None):
                output(False, f"Error al leer {port} (error {ctypes.get_last_error()}).")
                return
            now = time.monotonic()
            if count.value:
                output(True, f"Se recibieron {count.value} bytes.", buffer.raw[:count.value])
                last_heartbeat = now
            elif now - last_heartbeat >= 0.5:
                output(True, f"Escuchando {port}; todavía no llegan datos.")
                last_heartbeat = now
            time.sleep(0.02)
        output(True, "Finalizó el límite automático de 30 segundos.")
    finally:
        if kernel.CloseHandle(handle):
            state('cerrado')
        else:
            output(False, 'Windows no confirmó el cierre del puerto.')


def main():
    try:
        def state_output(value):
            print(json.dumps({'ok': True, **(value if isinstance(value, dict) else {'estado': value})}), flush=True)
        run(json.load(sys.stdin), state_output=state_output)
    except Exception:
        emit(False, "No se pudo completar la prueba serial.")


if __name__ == "__main__":
    main()
