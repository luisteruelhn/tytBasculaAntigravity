"""Consulta el estado actual del COM; no aplica parámetros ni lee/escribe datos."""
import ctypes
import importlib.util
import json
from pathlib import Path
import re
import sys
from ctypes import wintypes

# PHP usa -I: cargar por ruta fija sin depender del directorio de imports.
_spec = importlib.util.spec_from_file_location('bascula_lector_local', Path(__file__).with_name('probar_lectura_serial.py'))
_reader = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_reader)
DCB = _reader.DCB


def consultar(port, kernel=None):
    failure = {'ok': False, 'mensaje': 'No se pudo consultar el puerto. No se modificó el COM ni se guardaron cambios.'}
    if not isinstance(port, str) or not re.fullmatch(r'COM[1-9][0-9]{0,2}', port):
        return failure
    kernel = kernel or ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.CreateFileW.argtypes = [wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD,
                                  wintypes.LPVOID, wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE]
    kernel.CreateFileW.restype = wintypes.HANDLE
    kernel.GetCommState.argtypes = [wintypes.HANDLE, ctypes.POINTER(DCB)]
    kernel.GetCommState.restype = wintypes.BOOL
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    kernel.CloseHandle.restype = wintypes.BOOL
    handle = kernel.CreateFileW('\\\\.\\' + port, 0x80000000, 0, None, 3, 0x80, None)
    if handle == ctypes.c_void_p(-1).value:
        return {**failure, 'mensaje': 'No se pudo abrir el puerto para consultar sus parámetros. Puede estar ocupado. No se guardaron cambios.'}
    result = failure
    try:
        dcb = DCB()
        dcb.DCBlength = ctypes.sizeof(DCB)
        if kernel.GetCommState(handle, ctypes.byref(dcb)):
            # Solo representar combinaciones que el formulario puede conservar fielmente.
            flow_bits = dcb.flags & ((1 << 2) | (1 << 3) | (3 << 4) | (3 << 8) | (3 << 12))
            flows = {0: 'NINGUNO', (1 << 2) | (2 << 12): 'RTS_CTS',
                     (1 << 8) | (1 << 9): 'XON_XOFF', (1 << 3) | (2 << 4): 'DSR_DTR'}
            # DTR/RTS habilitados fijos no son control de flujo.
            if (dcb.flags >> 4) & 3 == 1:
                flow_bits &= ~(3 << 4)
            if (dcb.flags >> 12) & 3 == 1:
                flow_bits &= ~(3 << 12)
            parity = {0: 'N', 1: 'O', 2: 'E', 3: 'M', 4: 'S'}.get(dcb.Parity)
            stop = {0: '1', 1: '1.5', 2: '2'}.get(dcb.StopBits)
            flow = flows.get(flow_bits)
            if parity is not None and stop is not None and flow is not None:
                result = {'ok': True, 'puerto_com': port,
                          'mensaje': 'Parámetros actuales consultados sin modificar el COM.',
                          'valores': {'baudios': str(dcb.BaudRate), 'bits_datos': str(dcb.ByteSize),
                                      'paridad': parity, 'bits_parada': stop, 'control_flujo': flow}}
            else:
                result = {**failure, 'mensaje': 'La configuración actual contiene una combinación no representable en el formulario. No se supusieron ni guardaron valores.'}
    finally:
        if not kernel.CloseHandle(handle):
            result = {**failure, 'mensaje': 'No se confirmó el cierre de la consulta. No se guardaron cambios.'}
    return result


if __name__ == '__main__':
    try:
        result = consultar(sys.argv[1] if len(sys.argv) == 2 else '')
    except Exception:
        result = {'ok': False, 'mensaje': 'No se pudo consultar el COM. No se guardaron cambios.'}
    print(json.dumps(result, ensure_ascii=True))
