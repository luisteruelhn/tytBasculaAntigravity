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

// Control estricto de roles: Solo SUPER y ADMINISTRADORES
if (!tieneRol($usuario, ['SUPER', 'ADMINISTRADOR', 'ADMIN'])) {
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
        'respuesta' => "El Asistente Operativo está instalado y listo para responder consultas sobre boletas, camiones y reportes.\n\nPara activarlo en vivo, solo necesitas ingresar tu API Key de Gemini en **Configuración Báscula**.",
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

    // D. Catálogo de productos y presentaciones
    $stmtProd = $pdo->prepare("SELECT nombre FROM t_productos WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY nombre");
    $stmtProd->execute([':empresa_id' => $empresaId]);
    $productos = $stmtProd->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al consultar datos locales: ' . $e->getMessage()]);
    exit;
}

// 3. CONSTRUCCIÓN DEL PROMPT CON REGLAS ESTRICTAS
$contextoTexto = "=== DATOS EN VIVO DEL SISTEMA DE BÁSCULA ===\n";
$contextoTexto .= "Empresa: " . ($usuario['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB') . "\n";
$contextoTexto .= "Hoy: " . date('Y-m-d H:i') . "\n";
$contextoTexto .= "Resumen de hoy: Cerradas=" . ($resHoy['cerradas_hoy'] ?? 0) . 
                  ", Pendientes en patio=" . count($pendientes) . 
                  ", Compras=" . number_format((float)($resHoy['lb_compras_hoy'] ?? 0)) . " lb (" . round((float)($resHoy['lb_compras_hoy'] ?? 0)/100, 2) . " QQ)" .
                  ", Ventas=" . number_format((float)($resHoy['lb_ventas_hoy'] ?? 0)) . " lb (" . round((float)($resHoy['lb_ventas_hoy'] ?? 0)/100, 2) . " QQ)\n\n";

$contextoTexto .= "Notas actualmente abiertas en patio:\n";
if (empty($pendientes)) {
    $contextoTexto .= "No hay notas pendientes en patio.\n";
} else {
    foreach ($pendientes as $p) {
        $num = ($p['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $p['id_compra']) : '#' . sprintf('%07d', $p['id_venta']);
        $contextoTexto .= "- {$num} ({$p['tipo_operacion']}): Placa {$p['placa']}, Cliente/Prov {$p['cliente_proveedor']}, Producto {$p['producto']}, Bruto {$p['peso_bruto_lb']} lb, Entrada: {$p['primera_pesada_at']}\n";
    }
}

$contextoTexto .= "\nÚltimas boletas cerradas recientemente:\n";
foreach ($recientes as $r) {
    $num = ($r['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $r['id_compra']) : '#' . sprintf('%07d', $r['id_venta']);
    $contextoTexto .= "- {$num} ({$r['tipo_operacion']}): {$r['cliente_proveedor']}, {$r['producto']}, Neto final: {$r['peso_final_lb']} lb (" . round((float)$r['peso_final_lb']/100, 2) . " QQ), Salida: {$r['segunda_pesada_at']}\n";
}

$contextoTexto .= "\nCatálogo de productos activos: " . implode(', ', $productos) . "\n";

$systemInstruction = "Eres el Asistente Operativo Inteligente de TYTONE Báscula Camionera. "
    . "Tu única y estricta función es responder preguntas sobre el pesaje, boletas de peso (compras y ventas), pesos brutos, taras de vehículos y de empaques, pesos netos, quintales (1 QQ = 100 lb), clientes, proveedores, productos, presentaciones y camiones en patio. "
    . "REGLA MANDATORIA DE SEGURIDAD: Tienes estrictamente prohibido responder o hablar de temas ajenos a la operación de la báscula y el negocio de pesaje. Si el usuario te saluda o pregunta algo ajeno, responde amablemente pero enfócate de inmediato en cómo puedes ayudarle con las pesadas y reportes. "
    . "Responde de forma concisa, profesional, con cálculos matemáticos precisos y siempre en español latinoamericano.";

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

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errorCurl = curl_error($ch);
curl_close($ch);

if ($errorCurl !== '') {
    echo json_encode(['ok' => false, 'error' => 'Error de conexión con Gemini: ' . $errorCurl]);
    exit;
}

if ($httpCode !== 200) {
    $errDecoded = json_decode((string)$response, true);
    $msgErr = $errDecoded['error']['message'] ?? ('HTTP Error ' . $httpCode);
    echo json_encode(['ok' => false, 'error' => 'Gemini API Error: ' . $msgErr]);
    exit;
}

$dataRes = json_decode((string)$response, true);
$textoRespuesta = $dataRes['candidates'][0]['content']['parts'][0]['text'] ?? 'No se pudo obtener respuesta del modelo.';

echo json_encode([
    'ok' => true,
    'respuesta' => trim($textoRespuesta)
]);
