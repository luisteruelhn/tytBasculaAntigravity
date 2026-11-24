<?php
/**
 * Sincronización Idempotente de Catálogos Maestros:
 * - Categorías (t_categorias)
 * - Productos + Presentaciones (t_productos, t_producto_presentaciones)
 * - Clientes / Proveedores (t_clie_prov)
 *
 * Respeta registros existentes y llaves foráneas de t_pesadas.
 * No altera t_configuracion_bascula ni pesadas existentes.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/db.php';

$pdo = obtenerDB();
$pdo->exec("SET NAMES utf8mb4");
$empresaId = 1;

// 0. Asegurar columnas auxiliares opcionales en t_productos y t_clie_prov (idempotente)
$pdo->exec("
    ALTER TABLE t_productos
        ADD COLUMN IF NOT EXISTS codigo VARCHAR(30) DEFAULT NULL AFTER categoria_id,
        ADD COLUMN IF NOT EXISTS observaciones VARCHAR(255) DEFAULT NULL AFTER nombre
");
$pdo->exec("
    ALTER TABLE t_clie_prov
        ADD COLUMN IF NOT EXISTS codigo VARCHAR(30) DEFAULT NULL AFTER empresa_id,
        ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL AFTER telefono,
        ADD COLUMN IF NOT EXISTS ciudad VARCHAR(100) DEFAULT NULL AFTER direccion,
        ADD COLUMN IF NOT EXISTS observaciones VARCHAR(255) DEFAULT NULL AFTER ciudad
");

$pdo->beginTransaction();

// =========================================================================
// 1. CATEGORÍAS (t_categorias)
// =========================================================================
$categoriasBase = [
    ['codigo' => 'CAFE',           'nombre' => 'CAFE',           'descripcion' => 'Café pergamino, húmedo, seco, oro, resaca y derivados'],
    ['codigo' => 'ACEITES',        'nombre' => 'ACEITES',        'descripcion' => 'Aceite crudo, refinado de palma y grasas'],
    ['codigo' => 'GRANOS_BASICOS', 'nombre' => 'GRANOS BASICOS', 'descripcion' => 'Maíz, frijol, arroz, sorgo y granos alimenticios'],
    ['codigo' => 'COQUILLO',       'nombre' => 'COQUILLO',       'descripcion' => 'Coquillo y subproductos de palma'],
    ['codigo' => 'SALVADO',        'nombre' => 'SALVADO',        'descripcion' => 'Salvado e insumos agroindustriales'],
];

$mapaCatId = [];
foreach ($categoriasBase as $cat) {
    $stmt = $pdo->prepare("
        SELECT id FROM t_categorias
        WHERE empresa_id = :eid AND (codigo = :cod OR UPPER(nombre) = UPPER(:nom))
        LIMIT 1
    ");
    $stmt->execute([':eid' => $empresaId, ':cod' => $cat['codigo'], ':nom' => $cat['nombre']]);
    $catId = (int)($stmt->fetchColumn() ?: 0);

    if ($catId > 0) {
        $upd = $pdo->prepare("
            UPDATE t_categorias
            SET nombre = :nom, descripcion = COALESCE(descripcion, :desc), activo = 1
            WHERE id = :id AND empresa_id = :eid
        ");
        $upd->execute([':nom' => $cat['nombre'], ':desc' => $cat['descripcion'], ':id' => $catId, ':eid' => $empresaId]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO t_categorias (empresa_id, codigo, nombre, descripcion, activo)
            VALUES (:eid, :cod, :nom, :desc, 1)
        ");
        $ins->execute([
            ':eid'  => $empresaId,
            ':cod'  => $cat['codigo'],
            ':nom'  => $cat['nombre'],
            ':desc' => $cat['descripcion'],
        ]);
        $catId = (int)$pdo->lastInsertId();
    }
    $mapaCatId[$cat['nombre']] = $catId;
}

// =========================================================================
// 2. PRODUCTOS Y PRESENTACIONES (t_productos + t_producto_presentaciones)
// =========================================================================
$productosEntrada = [
    ['codigo' => '01',              'nombre' => 'CAFE STOCK',     'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
    ['codigo' => '03',              'nombre' => 'CAFE HÚMEDO',    'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => ['CAFE HUMEDO']],
    ['codigo' => '04',              'nombre' => 'CAFE RESACA',    'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
    ['codigo' => '05',              'nombre' => 'FRIJOL',         'activo' => 1, 'categoria' => 'GRANOS BASICOS', 'obs' => '',    'alias' => []],
    ['codigo' => '06',              'nombre' => 'MAÍZ',           'activo' => 1, 'categoria' => 'GRANOS BASICOS', 'obs' => '',    'alias' => ['MAIZ']],
    ['codigo' => '07',              'nombre' => 'ACEITE',         'activo' => 1, 'categoria' => 'ACEITES',        'obs' => '',    'alias' => []],
    ['codigo' => '08',              'nombre' => 'CAFE SECO',      'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
    ['codigo' => 'COQ-26227113323', 'nombre' => 'COQUILLO',       'activo' => 1, 'categoria' => 'COQUILLO',       'obs' => 'XXX', 'alias' => []],
    ['codigo' => 'CAF-264148340',   'nombre' => 'CAFE CORRIENTE', 'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
    ['codigo' => 'CAF-264148415',   'nombre' => 'CAFE GUACUCO',   'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
    ['codigo' => 'CAF-264177548',   'nombre' => 'CAFE NATURAL',   'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
    ['codigo' => 'SAL-265791524',   'nombre' => 'SALVADO',        'activo' => 1, 'categoria' => 'SALVADO',        'obs' => '',    'alias' => []],
    ['codigo' => 'CAF-2662312348',  'nombre' => 'CAFE ORO SHG',   'activo' => 1, 'categoria' => 'CAFE',           'obs' => '',    'alias' => []],
];

$prodCreados = 0;
$prodActualizados = 0;

foreach ($productosEntrada as $p) {
    $catId = $mapaCatId[$p['categoria']] ?? null;
    $nombresBuscar = array_merge([$p['nombre']], $p['alias']);
    $prodId = 0;

    foreach ($nombresBuscar as $nomB) {
        $stmt = $pdo->prepare("SELECT id FROM t_productos WHERE empresa_id = :eid AND nombre = :nom LIMIT 1");
        $stmt->execute([':eid' => $empresaId, ':nom' => $nomB]);
        $hallado = (int)($stmt->fetchColumn() ?: 0);
        if ($hallado > 0) {
            $prodId = $hallado;
            break;
        }
    }

    if ($prodId === 0) {
        $stmtCod = $pdo->prepare("SELECT id FROM t_productos WHERE empresa_id = :eid AND codigo = :cod LIMIT 1");
        $stmtCod->execute([':eid' => $empresaId, ':cod' => $p['codigo']]);
        $prodId = (int)($stmtCod->fetchColumn() ?: 0);
    }

    $obsVal = $p['obs'] !== '' ? $p['obs'] : null;

    if ($prodId > 0) {
        $upd = $pdo->prepare("
            UPDATE t_productos
            SET categoria_id = :cid, codigo = :cod, nombre = :nom, observaciones = :obs, activo = :act
            WHERE id = :id AND empresa_id = :eid
        ");
        $upd->execute([
            ':cid' => $catId,
            ':cod' => $p['codigo'],
            ':nom' => $p['nombre'],
            ':obs' => $obsVal,
            ':act' => $p['activo'],
            ':id'  => $prodId,
            ':eid' => $empresaId,
        ]);
        $prodActualizados++;
    } else {
        $ins = $pdo->prepare("
            INSERT INTO t_productos (empresa_id, categoria_id, codigo, nombre, observaciones, activo)
            VALUES (:eid, :cid, :cod, :nom, :obs, :act)
        ");
        $ins->execute([
            ':eid' => $empresaId,
            ':cid' => $catId,
            ':cod' => $p['codigo'],
            ':nom' => $p['nombre'],
            ':obs' => $obsVal,
            ':act' => $p['activo'],
        ]);
        $prodId = (int)$pdo->lastInsertId();
        $prodCreados++;
    }

    // Asegurar presentaciones por producto sin eliminar las existentes (respeta FKs en t_pesadas)
    $presDeseadas = ($p['nombre'] === 'ACEITE')
        ? ['A GRANEL']
        : (in_array($p['nombre'], ['COQUILLO', 'SALVADO'], true) ? ['SACOS', 'A GRANEL'] : ['SACOS', 'A GRANEL']);

    foreach ($presDeseadas as $prNom) {
        $stmtPr = $pdo->prepare("SELECT id FROM t_producto_presentaciones WHERE producto_id = :pid AND UPPER(nombre) = UPPER(:nom) LIMIT 1");
        $stmtPr->execute([':pid' => $prodId, ':nom' => $prNom]);
        if (!$stmtPr->fetchColumn()) {
            $insPr = $pdo->prepare("INSERT INTO t_producto_presentaciones (producto_id, nombre, activo) VALUES (:pid, :nom, 1)");
            $insPr->execute([':pid' => $prodId, ':nom' => $prNom]);
        }
    }
}

// =========================================================================
// 3. CLIENTES Y PROVEEDORES (t_clie_prov)
//    Se marcan con es_cliente=1 y es_proveedor=1 para estar disponibles
//    tanto en COMPRA como en VENTA dentro de Primera Pesada.
// =========================================================================
$clientesCsv = <<<'CSV'
10,CONSUMIDOR FINAL,0001,2500-0000,xxx@xxx.com,,,,True
10114,JOSE ISAIAS REYES DUBON,CLI-26224143625,9749-0082,,LA UNION LEMPIRA,,,True
10115,SAMUEL HENRRIQUE FERNANDEZ,CLI-263231845,94706642,,OCOTEPEQUE,,,True
10116,ADELCIS GEOVANY MEZA FUNEZ,CLI-263248128,95370258,,YOYO/YORO,,CAFE HUMEDO,True
10117,JOSE DARIO ENAMOARADO VALLECILLO,CLI-26324105116,96951760,,RIO FRIO SAN NICOLAS,,,True
10118,INDUSTRIAS EL DORAO,CLI-26324111945,,,PEÑA BLANCA,,,True
10119,CAFESCOR,CLI-2632573552,98294885,,CORQU9IN,,RAFAEL RIVERA,True
10120,SOGIMEX S. A,CLI-26325153430,,,SAN PEDRO SULA,,,True
10121,JAIRO BETANCO VARGAS,CLI-2632673434,95520086,,EL EMPALME EL PARAISO,,,True
10122,OMAR EDGARDO SAGASTUME MURILLO,CLI-2632681434,,,SAN PEDRO SULA,,,True
10123,MERLIN OSMAN RIVERA,CLI-2632615236,96565112,,SAN RAFAEL LEMPIRA,,,True
10124,EXPORTADORA OG,CLI-26327113510,,,VILLANUEVA,,,True
10125,FELIX CERRATO ROCHA,CLI-2632884531,,,NICARAGUA,,,True
10126,IMSA,CLI-26330163020,,,CHOLOMA,,,True
10127,CAFE UNO,CLI-2644121650,,,EL PARAISO,,,True
10128,HARIN HERNANDEZ,CLI-2644173724,,,EL PARAISO,,,True
10129,JUAN CARLOS MONGE,CLI-2646152037,,,CORQUIN,,,True
10130,DANILO CLAROS MEJIA,CLI-2641474325,,,JESUS DE OTORO,,,True
10131,INERSIONES JERUSALEN/JOSE FRANCISCO CUELLAR FAJARDO,CLI-2641981159,,,,,,True
10132,EXPORADORA GLOBAL COFFE GRUOP,CLI-264208855,,,VILLANUEVA CORTES,,,True
10133,HONDUPALMA,CLI-2642115358,,,EL PROGRESO,,,True
10134,JOSE ALFREDO MADRID,CLI-26424124442,,,SAN RAFAEL LEMPIRA,,,True
10135,CESAR SORTO,CLI-26512161139,,,JARDINES COMAYAGUA,,,True
10136,JASSUAR TROCHEZ,CLI-26513111750,,,,,,True
10137,JOSE RAMON ENAMORADO VALLECILLO,CLI-26520103732,,,RIO FRIO,,,True
10138,TROPICAL COFFE  S.A,CLI-2652016635,,,SAN PEDRO SULA,,,True
10139,JOSE AVILIO FERNANDEZ SANCHEZ,CLI-26521104426,,,SANTA BARBARA,,,True
10140,JOSE MEDARDO ORTEGA,CLI-26525142743,,,TUTULE,,,True
10141,MARCIO VICENTE CABRERRA ORTIZ,CLI-2652615958,,,COPAN,,,True
10142,CAFES FINOS DE MONTECILLOS/MARIO SAMUEL MEJIA,CLI-2662141659,,,SPS,,,True
10143,YESTER ESMELIN RIVERA ALCANTARA,CLI-266684042,,,SANTA BARBARA,,,True
10144,BENEFICIO DE CAFE TOLEDO,CLI-2662311924,,,EL PARAISO,,,True
10145,RODOLFO CASTILLO CARRASCO,CLI-261014824,9832-1736,,TROJES EL PARAISO,,,True
10146,TRINIDAD ALVARADO CASTILLO,CLI-26116135525,,,"LA PAZ , LA PAZ",,,True
10147,LOUIS DREYFUS COMPANY,CLI-2611874924,,,"VILLANUEVA, CORTES",,,True
10148,HENRY RONALD SAAVEDRA POLANCO,CLI-26112094539,96778807,,CEIBITA SUR SB,,,True
10149,FLOR DE MARIA ZAVALA,CLI-261123135042,,,SAN PEDRO SULA,,,True
CSV;

$cliCreados = 0;
$cliActualizados = 0;

foreach (preg_split('/\r\n|\r|\n/', trim($clientesCsv)) as $linea) {
    if (trim($linea) === '') continue;
    $campos = str_getcsv($linea);
    // ID_CLIENTE,NOMBRE,CODIGO,TELEFONO,EMAIL,DIRECCION,CIUDAD,OBSERVACIONES,ACTIVO
    $nombre   = trim((string)($campos[1] ?? ''));
    $codigo   = trim((string)($campos[2] ?? ''));
    $telefono = trim((string)($campos[3] ?? ''));
    $email    = trim((string)($campos[4] ?? ''));
    $dirRaw   = trim((string)($campos[5] ?? ''));
    $ciudad   = trim((string)($campos[6] ?? ''));
    $obs      = trim((string)($campos[7] ?? ''));
    $activo   = strtolower(trim((string)($campos[8] ?? 'true'))) === 'true' ? 1 : 0;

    if ($nombre === '') continue;

    // Si viene ciudad y dirección, concatenar limpiamente para que se vea también en boletas/vistas
    $direccionFinal = $dirRaw;
    if ($ciudad !== '' && $direccionFinal !== '' && stripos($direccionFinal, $ciudad) === false) {
        $direccionFinal .= ', ' . $ciudad;
    } elseif ($direccionFinal === '' && $ciudad !== '') {
        $direccionFinal = $ciudad;
    }

    $stmtFind = $pdo->prepare("
        SELECT id FROM t_clie_prov
        WHERE empresa_id = :eid AND ((:cod <> '' AND codigo = :cod2) OR UPPER(nombre) = UPPER(:nom))
        LIMIT 1
    ");
    $stmtFind->execute([
        ':eid'  => $empresaId,
        ':cod'  => $codigo,
        ':cod2' => $codigo,
        ':nom'  => $nombre,
    ]);
    $idExistente = (int)($stmtFind->fetchColumn() ?: 0);

    $paramsCli = [
        ':cod' => ($codigo !== '' ? $codigo : null),
        ':nom' => $nombre,
        ':tel' => ($telefono !== '' ? $telefono : null),
        ':ema' => ($email !== '' ? $email : null),
        ':dir' => ($direccionFinal !== '' ? $direccionFinal : null),
        ':ciu' => ($ciudad !== '' ? $ciudad : null),
        ':obs' => ($obs !== '' ? $obs : null),
        ':act' => $activo,
        ':eid' => $empresaId,
    ];

    if ($idExistente > 0) {
        $updCli = $pdo->prepare("
            UPDATE t_clie_prov
            SET codigo = :cod,
                nombre = :nom,
                telefono = :tel,
                email = :ema,
                direccion = :dir,
                ciudad = :ciu,
                observaciones = :obs,
                es_cliente = 1,
                es_proveedor = 1,
                activo = :act
            WHERE id = :id AND empresa_id = :eid
        ");
        $updCli->execute($paramsCli + [':id' => $idExistente]);
        $cliActualizados++;
    } else {
        $insCli = $pdo->prepare("
            INSERT INTO t_clie_prov
                (empresa_id, codigo, nombre, telefono, email, direccion, ciudad, observaciones, es_cliente, es_proveedor, activo)
            VALUES
                (:eid, :cod, :nom, :tel, :ema, :dir, :ciu, :obs, 1, 1, :act)
        ");
        $insCli->execute($paramsCli);
        $cliCreados++;
    }
}

$pdo->commit();

echo json_encode([
    'ok'                     => true,
    'categorias_activas'     => count($mapaCatId),
    'productos_creados'      => $prodCreados,
    'productos_actualizados' => $prodActualizados,
    'clientes_creados'       => $cliCreados,
    'clientes_actualizados'  => $cliActualizados,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
