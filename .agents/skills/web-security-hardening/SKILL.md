---
name: web-security-hardening
description: >-
  Estandar y mejores practicas para blindar aplicaciones web contra vulnerabilidades criticas: Inyeccion SQL, XSS, CSRF, RCE, IDOR y manipulacion de datos.
  Usalo al crear o modificar endpoints, formularios, consultas a base de datos, sesiones y manejo de inputs de usuario en cualquier proyecto web (PHP, Node, Python).
---

# Web Security Hardening Standard

Esta skill contiene las directivas obligatorias para garantizar código 100% resistente contra inyecciones y vulnerabilidades comunes (OWASP Top 10).

---

## 1. Prevención Absoluta de Inyecciones SQL (SQLi)
- **Regla Mandatoria**: **Prohibido concatenar o interpolar variables directamente en sentencias SQL.**
  - ❌ **INSEGURO**: `$pdo->query("SELECT * FROM t_pesadas WHERE id = " . $_GET['id']);`
  - ❌ **INSEGURO**: `$sql = "SELECT * FROM t_usuarios WHERE usuario = '$user'";`
- **Uso estricto de Consultas Preparadas (Prepared Statements con PDO)**:
  - ✔️ **SEGURO**:
    ```php
    $stmt = $pdo->prepare("SELECT * FROM t_pesadas WHERE empresa_id = :empresa_id AND estado = :estado");
    $stmt->execute([
        ':empresa_id' => (int)$empresaId,
        ':estado'     => 'CERRADA'
    ]);
    $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ```
- **Casting y tipado forzado en PHP**:
  - Para IDs numéricos, forzar siempre tipo entero: `$id = (int)($_GET['id'] ?? 0);`
  - Para límites o paginación: `$limit = max(1, min(100, (int)$_GET['limit']));`

---

## 2. Prevención de Cross-Site Scripting (XSS)
- **Escape obligatorio en renderizado HTML**:
  - Jamás imprimir datos del usuario o base de datos sin sanitizar.
  - Usar siempre `htmlspecialchars` con flags estrictos:
    - ✔️ `<?= htmlspecialchars((string)$variable, ENT_QUOTES, 'UTF-8') ?>`
- **Escape en atributos HTML**:
  - Evitar inyecciones en atributos `value="<?= ... ?>"`, `href="<?= ... ?>"`.
  - En enlaces con URLs externas o de usuario, validar el esquema (evitar `javascript:`):
    ```php
    $url = filter_var($urlIngresada, FILTER_VALIDATE_URL);
    ```
- **JSON incrustado en HTML/JavaScript**:
  - Al pasar variables de PHP a bloques `<script>`, usar flags de escape contra etiquetas de script:
    ```php
    const DATOS = <?= json_encode($datos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    ```

---

## 3. Protección contra Cross-Site Request Forgery (CSRF)
- Todo formulario con acciones de escritura (`POST`, `PUT`, `DELETE`) debe incluir un token CSRF criptográficamente seguro:
  - Generación en sesión:
    ```php
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    ```
  - Verificación en el backend:
    ```php
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Token CSRF inválido o sesión caducada.');
    }
    ```

---

## 4. Control de Acceso y Autorización (Anti-IDOR)
- **Insecure Direct Object References (IDOR)**:
  - Nunca confiar ciegamente en que el usuario tiene derecho a ver/modificar un recurso solo porque envió el ID.
  - Validar siempre la pertenencia al inquilino/empresa y rol activo:
    ```php
    $stmt = $pdo->prepare("SELECT * FROM t_pesadas WHERE id = :id AND empresa_id = :empresa_id");
    $stmt->execute([':id' => $id, ':empresa_id' => $usuario['empresa_id']]);
    ```

---

## 5. Prevención de Inyección de Comandos (RCE / Remote Code Execution)
- Prohibido pasar variables sin sanitizar a `exec()`, `shell_exec()`, `system()`, `passthru()` o `proc_open()`.
- Si es imprescindible invocar un proceso del sistema, utilizar arrays de argumentos directos o escapar rigurosamente con `escapeshellarg()` y `escapeshellcmd()`.
- Prohibido el uso de `eval()` bajo cualquier circunstancia.

---

## 6. Manejo Seguro de Archivos y Subidas
- Validar tipos MIME reales (usando `finfo_file` o `mime_content_type`), no confiar únicamente en la extensión del archivo.
- Generar nombres de archivo aleatorios o hasheados (evitar guardar nombres originales para prevenir `Path Traversal` como `../../archivo.php`).
- Guardar archivos fuera del directorio raíz ejecutable o configurar el servidor para denegar ejecución de scripts en carpetas de uploads.
