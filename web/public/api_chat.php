<?php
/**
 * Endpoint del Asistente Operativo IA (Google Gemini)
 * Proyecto: tytBasculaAntigravity
 *
 * Responde exclusivamente consultas sobre pesadas, boletas, camiones,
 * clientes, productos y reportes de la báscula activa.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = obtenerUsuarioActual();
if (!$usuario) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada o no autenticado.']);
    exit;
}
session_write_close();

// Control estricto de roles: Solo SUPER, ADMINISTRADORES y REMOTO (solo lectura)
if (!tieneRol($usuario, ['SUPER', 'ADMINISTRADOR', 'ADMIN', 'REMOTO'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado. Solo administradores y superusuarios pueden consultar el asistente.']);
    exit;
}

$empresaId = (int)$usuario['empresa_id'];
$pdo = obtenerDB();

// 1. OBTENER API KEY DE GEMINI
$stmtCfg = $pdo->prepare("SELECT gemini_api_key FROM t_configuracion_bascula WHERE empresa_id = :empresa_id AND estacion = 'PRINCIPAL'");
$stmtCfg->execute([':empresa_id' => $empresaId]);
$cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);

$apiKey = trim((string)($cfg['gemini_api_key'] ?? ''));
if ($apiKey === '' && defined('GEMINI_API_KEY')) {
    $apiKey = trim((string)GEMINI_API_KEY);
}
if ($apiKey === '') {
    $apiKey = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
}

$input = json_decode((string)file_get_contents('php://input'), true);
$pregunta = trim((string)($input['mensaje'] ?? ''));

if ($pregunta === '') {
    echo json_encode(['ok' => false, 'error' => 'Pregunta vacía.']);
    exit;
}

if ($apiKey === '') {
    echo json_encode([
        'ok' => true,
        'respuesta' => "El Asistente TYTOne está instalado y listo para responder consultas sobre boletas, camiones y reportes.\n\nPara activarlo en vivo, solo necesitas ingresar tu API Key de Gemini en **Configuración Báscula**.",
        'necesita_key' => true
    ]);
    exit;
}

// 2. CONTEXTO OPERATIVO DE SOLO LECTURA (SELECT)
try {
    // A. Resumen general de hoy
    $stmtHoy = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN estado = 'CERRADA' THEN 1 END) AS cerradas_hoy,
            COUNT(CASE WHEN estado = 'ABIERTA' THEN 1 END) AS abiertas_hoy,
            COALESCE(SUM(CASE WHEN estado = 'CERRADA' AND tipo_operacion = 'COMPRA' THEN peso_final_lb ELSE 0 END), 0) AS lb_compras_hoy,
            COALESCE(SUM(CASE WHEN estado = 'CERRADA' AND tipo_operacion = 'VENTA' THEN peso_final_lb ELSE 0 END), 0) AS lb_ventas_hoy
        FROM t_pesadas
        WHERE empresa_id = :empresa_id AND DATE(fecha_at) = CURRENT_DATE()
    ");
    $stmtHoy->execute([':empresa_id' => $empresaId]);
    $resHoy = $stmtHoy->fetch(PDO::FETCH_ASSOC);

    // B. Últimas 10 boletas cerradas
    $stmtRecientes = $pdo->prepare("
        SELECT p.tipo_operacion, p.id_compra, p.id_venta, p.placa, p.peso_final_lb,
               p.primera_pesada_at, p.segunda_pesada_at,
               cp.nombre AS cliente_proveedor, prod.nombre AS producto
        FROM t_pesadas p
        LEFT JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
        LEFT JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
        LEFT JOIN t_productos prod ON prod.id = pr.producto_id
        WHERE p.empresa_id = :empresa_id AND p.estado = 'CERRADA'
        ORDER BY p.idpesada DESC LIMIT 10
    ");
    $stmtRecientes->execute([':empresa_id' => $empresaId]);
    $recientes = $stmtRecientes->fetchAll(PDO::FETCH_ASSOC);

    // C. Notas actualmente abiertas (en patio)
    $stmtPendientes = $pdo->prepare("
        SELECT p.tipo_operacion, p.id_compra, p.id_venta, p.placa, p.peso_bruto_lb,
               p.primera_pesada_at, cp.nombre AS cliente_proveedor, prod.nombre AS producto
        FROM t_pesadas p
        LEFT JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
        LEFT JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
        LEFT JOIN t_productos prod ON prod.id = pr.producto_id
        WHERE p.empresa_id = :empresa_id AND p.estado = 'ABIERTA'
        ORDER BY p.primera_pesada_at ASC
    ");
    $stmtPendientes->execute([':empresa_id' => $empresaId]);
    $pendientes = $stmtPendientes->fetchAll(PDO::FETCH_ASSOC);

    // D. Catálogo de categorías, productos y presentaciones
    $stmtCat = $pdo->query("SELECT id, nombre FROM t_categorias ORDER BY nombre ASC");
    $categorias = $stmtCat ? $stmtCat->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmtProd = $pdo->prepare("
        SELECT prod.nombre, cat.nombre AS categoria, pp.nombre AS presentacion
        FROM t_productos prod
        LEFT JOIN t_categorias cat ON cat.id = prod.categoria_id
        LEFT JOIN t_producto_presentaciones pp ON pp.producto_id = prod.id
        WHERE prod.empresa_id = :empresa_id AND prod.activo = 1 
        ORDER BY prod.nombre ASC
    ");
    $stmtProd->execute([':empresa_id' => $empresaId]);
    $productosDetalle = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    // E. Directorio completo de Clientes y Proveedores (Teléfono, Dirección, RTN)
    $stmtDirectorio = $pdo->prepare("
        SELECT nombre, telefono, direccion, rtn,
               CASE 
                   WHEN es_cliente = 1 AND es_proveedor = 1 THEN 'Cliente y Proveedor'
                   WHEN es_cliente = 1 THEN 'Cliente'
                   WHEN es_proveedor = 1 THEN 'Proveedor'
                   ELSE 'Contacto'
               END AS tipo
        FROM t_clie_prov 
        WHERE empresa_id = :empresa_id AND activo = 1 
        ORDER BY nombre ASC
    ");
    $stmtDirectorio->execute([':empresa_id' => $empresaId]);
    $directorio = $stmtDirectorio->fetchAll(PDO::FETCH_ASSOC);

    // F. Catálogo de Taras de empaque predeterminadas
    $stmtTaras = $pdo->query("SELECT nombre, taralbs FROM t_taras ORDER BY nombre ASC");
    $catalogoTaras = $stmtTaras ? $stmtTaras->fetchAll(PDO::FETCH_ASSOC) : [];

    // G. Resumen histórico acumulado por producto (Compras y Ventas totales)
    $stmtAcumulados = $pdo->prepare("
        SELECT prod.nombre AS producto, p.tipo_operacion,
               COUNT(*) AS total_pesadas,
               COALESCE(SUM(p.peso_final_lb), 0) AS total_lb,
               ROUND(COALESCE(SUM(p.peso_final_lb), 0) / 100, 2) AS total_qq
        FROM t_pesadas p
        JOIN t_producto_presentaciones pp ON pp.id = p.presentacion_id
        JOIN t_productos prod ON prod.id = pp.producto_id
        WHERE p.empresa_id = :empresa_id AND p.estado = 'CERRADA'
        GROUP BY prod.nombre, p.tipo_operacion
        ORDER BY prod.nombre ASC, p.tipo_operacion ASC
    ");
    $stmtAcumulados->execute([':empresa_id' => $empresaId]);
    $acumulados = $stmtAcumulados->fetchAll(PDO::FETCH_ASSOC);

    // H. Historial de boletas anuladas recientemente
    $stmtAnuladas = $pdo->prepare("
        SELECT p.tipo_operacion, p.id_compra, p.id_venta, p.placa, an.motivo, an.anulado_at, u.nombre AS anulado_por
        FROM t_pesada_anulaciones an
        JOIN t_pesadas p ON p.idpesada = an.idpesada
        LEFT JOIN t_usuarios u ON u.id = an.anulado_por
        WHERE p.empresa_id = :empresa_id
        ORDER BY an.anulado_at DESC LIMIT 5
    ");
    $stmtAnuladas->execute([':empresa_id' => $empresaId]);
    $anuladas = $stmtAnuladas ? $stmtAnuladas->fetchAll(PDO::FETCH_ASSOC) : [];

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al consultar datos locales: ' . $e->getMessage()]);
    exit;
}

// 3. CONSTRUCCIÓN DEL PROMPT CON REGLAS Y CONTEXTO MULTI-TABLA (SOLO LECTURA)
$contextoTexto = "=== DATOS COMPLETOS DE LA BASE DE DATOS (SOLO LECTURA) ===\n";
$contextoTexto .= "Empresa activa: " . ($usuario['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB') . "\n";
$contextoTexto .= "Fecha y hora actual: " . date('Y-m-d H:i:s') . "\n\n";

$contextoTexto .= "1. RESUMEN OPERATIVO DE HOY (" . date('d/m/Y') . "):\n";
$contextoTexto .= "- Boletas cerradas hoy: " . ($resHoy['cerradas_hoy'] ?? 0) . "\n";
$contextoTexto .= "- Camiones pendientes en patio: " . count($pendientes) . "\n";
$contextoTexto .= "- Compras hoy: " . number_format((float)($resHoy['lb_compras_hoy'] ?? 0)) . " lb (" . round((float)($resHoy['lb_compras_hoy'] ?? 0)/100, 2) . " QQ)\n";
$contextoTexto .= "- Ventas hoy: " . number_format((float)($resHoy['lb_ventas_hoy'] ?? 0)) . " lb (" . round((float)($resHoy['lb_ventas_hoy'] ?? 0)/100, 2) . " QQ)\n\n";

$contextoTexto .= "2. CAMIONES EN PATIO (NOTAS ABIERTAS):\n";
if (empty($pendientes)) {
    $contextoTexto .= "No hay camiones en patio en este momento.\n\n";
} else {
    foreach ($pendientes as $p) {
        $num = ($p['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $p['id_compra']) : '#' . sprintf('%07d', $p['id_venta']);
        $contextoTexto .= "- {$num} ({$p['tipo_operacion']}): Placa {$p['placa']}, Cliente/Prov: {$p['cliente_proveedor']}, Producto: {$p['producto']}, Peso Bruto: {$p['peso_bruto_lb']} lb, Entrada: {$p['primera_pesada_at']}\n";
    }
    $contextoTexto .= "\n";
}

$contextoTexto .= "3. ÚLTIMAS BOLETAS CERRADAS:\n";
foreach ($recientes as $r) {
    $num = ($r['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $r['id_compra']) : '#' . sprintf('%07d', $r['id_venta']);
    $contextoTexto .= "- {$num} ({$r['tipo_operacion']}): Placa: {$r['placa']}, Cliente/Prov: {$r['cliente_proveedor']}, Producto: {$r['producto']}, Neto final: {$r['peso_final_lb']} lb (" . round((float)$r['peso_final_lb']/100, 2) . " QQ), Cierre: {$r['segunda_pesada_at']}\n";
}
$contextoTexto .= "\n";

$contextoTexto .= "4. DIRECTORIO DE CLIENTES Y PROVEEDORES (t_clie_prov):\n";
if (empty($directorio)) {
    $contextoTexto .= "No hay clientes ni proveedores registrados.\n\n";
} else {
    foreach ($directorio as $d) {
        $tel = !empty($d['telefono']) ? $d['telefono'] : 'Sin teléfono';
        $rtn = !empty($d['rtn']) ? "RTN: {$d['rtn']}, " : '';
        $dir = !empty($d['direccion']) ? "Dirección: {$d['direccion']}" : '';
        $contextoTexto .= "- [{$d['tipo']}] {$d['nombre']}: Tel: {$tel}, {$rtn}{$dir}\n";
    }
    $contextoTexto .= "\n";
}

$contextoTexto .= "5. CATÁLOGO DE PRODUCTOS Y PRESENTACIONES (t_productos, t_categorias, t_producto_presentaciones):\n";
foreach ($productosDetalle as $pd) {
    $cat = $pd['categoria'] ?: 'General';
    $pres = $pd['presentacion'] ?: 'Estándar';
    $contextoTexto .= "- {$pd['nombre']} (Categoría: {$cat}, Presentación: {$pres})\n";
}
$contextoTexto .= "\n";

$contextoTexto .= "6. TOTALES ACUMULADOS HISTÓRICOS POR PRODUCTO:\n";
foreach ($acumulados as $ac) {
    $contextoTexto .= "- {$ac['producto']} ({$ac['tipo_operacion']}): {$ac['total_pesadas']} pesadas, " . number_format((float)$ac['total_lb']) . " lb ({$ac['total_qq']} QQ)\n";
}
$contextoTexto .= "\n";

if (!empty($anuladas)) {
    $contextoTexto .= "7. BOLETAS ANULADAS RECIENTES:\n";
    foreach ($anuladas as $an) {
        $num = ($an['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $an['id_compra']) : '#' . sprintf('%07d', $an['id_venta']);
        $contextoTexto .= "- {$num} ({$an['tipo_operacion']}, Placa {$an['placa']}): Motivo: {$an['motivo']}, Anulada por: {$an['anulado_por']}, Fecha: {$an['anulado_at']}\n";
    }
    $contextoTexto .= "\n";
}

$systemInstruction = "Eres el Asistente TYTOne de Báscula Camionera, con acceso de SOLO LECTURA a todas las tablas del sistema de báscula de la empresa. "
    . "Tienes visibilidad de: "
    . "1) Boletas de pesaje y camiones en patio (t_pesadas), "
    . "2) Directorio comercial con teléfonos, direcciones y RTN de clientes y proveedores (t_clie_prov), "
    . "3) Catálogo de productos, categorías y presentaciones (t_productos, t_categorias, t_presentaciones, t_producto_presentaciones), "
    . "4) Taras de empaque (t_taras), "
    . "5) Totales y acumulados históricos en libras y quintales (1 QQ = 100 lb), "
    . "6) Historial de boletas anuladas y motivos (t_pesada_anulaciones). "
    . "DIRECTIVA OBLIGATORIA: Puedes y DEBES responder cualquier consulta sobre estos datos (números telefónicos, datos de contacto, totales por cliente, pesajes, placas, productos, etc.) de forma directa, útil, precisa y veraz sin negar información disponible en tus datos. "
    . "REGLA DE SEGURIDAD ESTRICTA: Eres un asistente de SOLO CONSULTA (SELECT). Tienes estrictamente prohibido ejecutar o simular modificaciones, inserciones, anulaciones directas o eliminaciones de datos en la base de datos (NO INSERT, NO UPDATE, NO DELETE, NO DROP). "
    . "Responde con claridad, profesionalismo, usando formato markdown limpio y siempre en español latinoamericano.";

// 4. LLAMADA A LA API DE GEMINI VÍA CURL NATIVO
$payload = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => $contextoTexto . "\n\nPregunta del operador: " . $pregunta]
            ]
        ]
    ],
    'systemInstruction' => [
        'parts' => [
            ['text' => $systemInstruction]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 800
    ]
];

// 4. LLAMADA HTTPS A LA API DE GEMINI (CON CASCADA DE MODELOS Y RESPALDO NATIVO SIN CURL)
$jsonPayload = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
$modelosCandidatos = [
    'gemini-flash-lite-latest',
    'gemini-3.5-flash-lite',
    'gemini-3.7-flash',
    'gemini-3.8-flash',
    'gemini-flash-latest',
];

/**
 * Ejecuta una petición POST JSON hacia Gemini usando cURL si está cargado o streams OpenSSL nativos.
 *
 * @return array{http: int, body: string, error: string}
 */
function consultarEndpointGemini(string $url, string $jsonBody, int $timeout = 15): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [
            'http'  => $http,
            'body'  => ($resp !== false) ? (string)$resp : '',
            'error' => $err,
        ];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'ignore_errors' => true,
            'timeout'       => $timeout,
            'header'        => "Content-Type: application/json; charset=utf-8\r\n",
            'content'       => $jsonBody,
        ],
    ]);

    $http_response_header = [];
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $lastErr = error_get_last();
        return [
            'http'  => 0,
            'body'  => '',
            'error' => (string)($lastErr['message'] ?? 'No se pudo conectar con generativelanguage.googleapis.com'),
        ];
    }

    $httpCode = 200;
    foreach ($http_response_header as $hdr) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $hdr, $m)) {
            $httpCode = (int)$m[1];
        }
    }

    return [
        'http'  => $httpCode,
        'body'  => (string)$resp,
        'error' => '',
    ];
}

$response  = '';
$httpCode  = 0;
$errorRed  = '';

foreach ($modelosCandidatos as $modelo) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent?key=' . urlencode($apiKey);
    $resIntento = consultarEndpointGemini($url, $jsonPayload, 15);
    $httpCode = $resIntento['http'];
    $response = $resIntento['body'];
    $errorRed = $resIntento['error'];

    if ($errorRed !== '') {
        break;
    }
    if ($httpCode === 200) {
        break;
    }
    // Si el modelo puntual está en alta demanda (503) o retirado (404), probar el siguiente candidato
    if (!in_array($httpCode, [404, 503], true)) {
        break;
    }
}

if ($errorRed !== '') {
    echo json_encode(['ok' => false, 'error' => 'Error de conexión con Gemini: ' . $errorRed]);
    exit;
}

if ($httpCode !== 200) {
    $errDecoded = json_decode($response, true);
    $msgErr = $errDecoded['error']['message'] ?? ('HTTP Error ' . $httpCode);
    echo json_encode(['ok' => false, 'error' => 'Gemini API Error: ' . $msgErr]);
    exit;
}

$dataRes = json_decode($response, true);
$textoRespuesta = $dataRes['candidates'][0]['content']['parts'][0]['text'] ?? 'No se pudo obtener respuesta del modelo.';

echo json_encode([
    'ok' => true,
    'respuesta' => trim($textoRespuesta)
], JSON_UNESCAPED_UNICODE);
