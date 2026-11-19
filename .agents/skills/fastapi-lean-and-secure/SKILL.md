---
name: fastapi-lean-and-secure
description: >-
  Estandar de desarrollo para APIs en FastAPI ultra-ligeras, de alto rendimiento y maximamente seguras.
  Enfocado en Pydantic V2, validacion estricta de esquemas, inyeccion de dependencias, CORS controlado, seguridad de endpoints, tipado estricto y cero sobre-ingenieria.
---

# FastAPI Lean & Secure Standard

Esta skill define las mejores prácticas universales para crear y mantener microservicios y APIs con **FastAPI** que sean extremadamente rápidas, ligeras y seguras.

---

## 1. Filosofía de Arquitectura Ligera (Anti-Bloat)
- **Cero sobre-ingeniería**: No crear capas intermedias innecesarias (servicios de servicios, adaptadores abstractos excesivos).
- **Estructura plana y directa**:
  - `main.py`: Inicialización de la app, routers y middleware.
  - `schemas.py`: Modelos Pydantic de entrada y salida (DTOs).
  - `routes/`: Endpoints organizados por dominio con `APIRouter`.
  - `dependencies.py`: Autenticación, sesión de base de datos o utilidades compartidas (`Depends()`).
- **Arranque asíncrono limpio (`lifespan`)**:
  - Usar siempre el context manager `lifespan` moderno de FastAPI en lugar de los eventos obsoletos `@app.on_event("startup")` y `@app.on_event("shutdown")`:
    ```python
    from contextlib import asynccontextmanager
    from fastapi import FastAPI

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        # Startup: Conexiones a hardware, DB pool, etc.
        yield
        # Shutdown: Cierre de puertos seriales, conexiones, etc.

    app = FastAPI(lifespan=lifespan)
    ```

---

## 2. Validación Estricta con Pydantic V2
- **Separación de esquemas (Input vs. Output)**:
  - Nunca retornar el modelo de base de datos crudo ni contraseñas/hashes.
  - Usar esquemas `Create`, `Update` y `Response`:
    ```python
    from pydantic import BaseModel, Field, ConfigDict
    from typing import Optional

    class PesadaCreate(BaseModel):
        peso_bruto: float = Field(..., gt=0, le=200_000, description="Peso en libras")
        placa: str = Field(..., min_length=3, max_length=15, pattern=r"^[A-Z0-9\-]+$")
        empresa_id: int = Field(..., gt=0)

    class PesadaResponse(BaseModel):
        id: int
        peso_bruto: float
        placa: str
        model_config = ConfigDict(from_attributes=True)
    ```
- **Validación granular**:
  - Usar `@field_validator` y `@model_validator` para reglas de negocio cruzadas (ej. `peso_bruto > tara_vehiculo`).

---

## 3. Seguridad y Blindaje de Endpoints
- **CORS Estricto**:
  - Jamás usar `allow_origins=["*"]` en producción con credenciales activadas.
  - Limitar explícitamente a los orígenes autorizados:
    ```python
    from fastapi.middleware.cors import CORSMiddleware

    app.add_middleware(
        CORSMiddleware,
        allow_origins=["http://localhost", "https://mi-dominio.com"],
        allow_credentials=True,
        allow_methods=["GET", "POST", "PUT", "DELETE"],
        allow_headers=["*"],
    )
    ```
- **Autenticación y Autorización con `Depends`**:
  - Usar tokens JWT o API Keys con `HTTPBearer` / `APIKeyHeader`.
  - Desacoplar la seguridad en dependencias inyectables:
    ```python
    from fastapi import Depends, HTTPException, status
    from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials

    security = HTTPBearer()

    def verificar_token(credentials: HTTPAuthorizationCredentials = Depends(security)) -> dict:
        token = credentials.credentials
        # Validar token criptográficamente
        if not token_valido:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Token no válido o expirado"
            )
        return payload_usuario
    ```

---

## 4. Respuestas de Error Estandarizadas
- Evitar que excepciones internas (Tracebacks, errores de SQL o puertos seriales) se fuguen al cliente.
- Manejar excepciones con `HTTPException`:
  ```python
  from fastapi import HTTPException, status

  if not boleta:
      raise HTTPException(
          status_code=status.HTTP_404_NOT_FOUND,
          detail="La boleta solicitada no existe o no pertenece a su empresa"
      )
  ```

---

## 5. Rendimiento y Concurrencia
- **Operaciones Asíncronas vs. Síncronas**:
  - Si un endpoint hace llamadas de red asíncronas o usa drivers async (`asyncpg`, `httpx`): definirlo con `async def`.
  - Si un endpoint ejecuta código bloqueante intensivo de CPU o hardware (librería serial `pyserial` síncrona): definirlo con `def` estándar para que FastAPI lo ejecute automáticamente en el threadpool sin congelar el bucle de eventos.
