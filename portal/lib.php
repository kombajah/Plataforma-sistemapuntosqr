<?php
// Funciones del portal: aprovisionamiento de colegios (una BD por instalación), estadísticas y claves.
require_once __DIR__ . '/../paginas/branding.php';

function portal_prefijo_bd(): string {
  $p = preg_replace('/[^a-z0-9_]/', '', strtolower(env('TENANT_DB_PREFIX', 'nfc_')));
  return $p === '' ? 'nfc_' : $p;
}
function portal_nombre_bd(string $slug): string { return portal_prefijo_bd() . str_replace('-', '_', $slug); }

// Clave legible y segura: 12 caracteres sin ambiguos (0/O, 1/l/I), en grupos XXXX-XXXX-XXXX.
function generar_clave(): string {
  $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'; $n = strlen($abc); $o = '';
  for ($i = 0; $i < 12; $i++) { $o .= $abc[random_int(0, $n - 1)]; if ($i % 4 === 3 && $i < 11) $o .= '-'; }
  return $o;
}
function usuario_valido(string $u): bool { return (bool)preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u); }
function ahora(): string { return date('Y-m-d H:i:s'); }

// Tabla de solicitudes de acceso (formulario público de la portada). Se crea si no existe.
function solicitudes_asegurar_tabla(mysqli $master): void {
  $master->query("CREATE TABLE IF NOT EXISTS portal_solicitudes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL, telefono VARCHAR(30) NOT NULL DEFAULT '',
    institucion VARCHAR(150) NOT NULL, cargo VARCHAR(100) NOT NULL DEFAULT '', mensaje VARCHAR(1000) NOT NULL DEFAULT '',
    estado ENUM('pendiente','contactado','descartado') NOT NULL DEFAULT 'pendiente',
    nota VARCHAR(500) NOT NULL DEFAULT '', ip VARCHAR(45) NULL, creada DATETIME NOT NULL, actualizada DATETIME NULL,
    gestionada_por VARCHAR(50) NULL, INDEX idx_sol_estado (estado, creada))");
  $master->query("CREATE TABLE IF NOT EXISTS portal_solicitud_notas (
    id INT AUTO_INCREMENT PRIMARY KEY, solicitud_id INT NOT NULL, tipo VARCHAR(10) NOT NULL DEFAULT 'nota',
    autor VARCHAR(50) NOT NULL, texto VARCHAR(500) NOT NULL, creada DATETIME NOT NULL, INDEX idx_nota_sol (solicitud_id, creada))");
}
// Historial de notas: SIEMPRE se agrega una fila nueva; nunca se modifica ni reemplaza una anterior.
function solicitud_agregar_nota(mysqli $master, int $solicitudId, string $autor, string $texto, string $tipo = 'nota'): void {
  $texto = mb_substr(trim($texto), 0, 500);
  if ($texto === '') return;
  $f = ahora();
  $s = $master->prepare("INSERT INTO portal_solicitud_notas (solicitud_id,tipo,autor,texto,creada) VALUES (?,?,?,?,?)");
  $s->bind_param("issss", $solicitudId, $tipo, $autor, $texto, $f); $s->execute();
}

// ---- Captcha del formulario de solicitud ----
// Si hay claves de Cloudflare Turnstile (TURNSTILE_SITE_KEY y TURNSTILE_SECRET_KEY) se usa Turnstile;
// si no, un desafío matemático propio guardado en la sesión. En ambos casos hay campo trampa, tiempo mínimo y límite por IP.
function turnstile_activo(): bool { return env('TURNSTILE_SITE_KEY') !== '' && env('TURNSTILE_SECRET_KEY') !== ''; }
function turnstile_verificar(string $token): bool {
  if ($token === '' || strlen($token) > 2048) return false;
  $post = http_build_query(['secret' => env('TURNSTILE_SECRET_KEY'), 'response' => $token, 'remoteip' => ip_cliente()]);
  $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; $r = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $r = curl_exec($ch); curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 8]]);
    $r = @file_get_contents($url, false, $ctx);
  }
  $j = json_decode((string)$r, true);
  return is_array($j) && !empty($j['success']);
}
function captcha_nuevo(): string {
  $a = random_int(2, 9); $b = random_int(2, 9);
  $_SESSION['cap'] = ['r' => $a + $b, 't' => time()];
  return "$a + $b";
}
function captcha_validar(array $post): bool {
  if (turnstile_activo()) return turnstile_verificar((string)($post['cf-turnstile-response'] ?? ''));
  $c = $_SESSION['cap'] ?? null; unset($_SESSION['cap']);
  $resp = trim((string)($post['captcha'] ?? ''));
  return $c && $resp !== '' && (time() - $c['t']) <= 1800 && (int)$resp === (int)$c['r'];
}
// Máximo 5 envíos por hora desde la misma IP.
function solicitud_limite(string $clave): bool {
  global $master;
  try {
    $s = $master->prepare("SELECT COUNT(*) t FROM portal_intentos WHERE clave=? AND fecha > DATE_SUB(?, INTERVAL 1 HOUR)");
    $ahora = date('Y-m-d H:i:s'); $s->bind_param("ss", $clave, $ahora); $s->execute();
    return (int)$s->get_result()->fetch_assoc()['t'] >= 5;
  } catch (Throwable $e) { return false; }
}

// Agrega columnas nuevas a una BD maestra ya instalada (seguro de repetir).
function portal_migrar(mysqli $master): void {
  try { solicitudes_asegurar_tabla($master); } catch (Throwable $e) {}
  try {
    $r = $master->query("SELECT COUNT(*) t FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='portal_instalaciones' AND column_name='vence'")->fetch_assoc();
    if (!(int)$r['t']) $master->query("ALTER TABLE portal_instalaciones ADD COLUMN vence DATETIME NULL AFTER max_usuarios");
    $r = $master->query("SELECT COUNT(*) t FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='portal_instalaciones' AND column_name='es_demo'")->fetch_assoc();
    if (!(int)$r['t']) $master->query("ALTER TABLE portal_instalaciones ADD COLUMN es_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER max_usuarios");
    $r = $master->query("SELECT COUNT(*) t FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='portal_instalaciones' AND column_name='descripcion'")->fetch_assoc();
    if (!(int)$r['t']) $master->query("ALTER TABLE portal_instalaciones ADD COLUMN descripcion VARCHAR(255) NULL AFTER max_usuarios");
  } catch (Throwable $e) {}
}
// Fecha de vencimiento: fin del día, $dias días después de $desde (timestamp). 0 días → null (sin vencimiento).
function calcular_vence(int $dias, ?int $desde = null): ?string {
  if ($dias <= 0) return null;
  return date('Y-m-d 23:59:59', ($desde ?? time()) + $dias * 86400);
}

function portal_instalacion_de_admin($master, int $adminId): ?array {
  $s = $master->prepare("SELECT * FROM portal_instalaciones WHERE admin_id=?"); $s->bind_param("i", $adminId); $s->execute();
  return $s->get_result()->fetch_assoc() ?: null;
}

// Ejecuta el contenido de un .sql con varias sentencias.
function ejecutar_sql_multiple(mysqli $c, string $sql): void {
  $c->multi_query($sql);
  do { if ($r = $c->store_result()) $r->free(); } while ($c->more_results() && $c->next_result());
}

// ---------------------------------------------------------------------------------------
// Crea la base de datos del colegio, le aplica el esquema y guarda configuración, imágenes y
// el usuario administrador. Si algo falla después de crear la BD, la elimina (sin dejar basura).
// $admin: fila de portal_admins. $hashAdmin: hash de la contraseña con que quedará en el colegio.
// ---------------------------------------------------------------------------------------
function provisionar_colegio(mysqli $master, array $inst, array $admin, string $slug, array $cfg, array $imagenes, string $hashAdmin): array {
  if (!slug_valido($slug)) throw new RuntimeException('El código del colegio no es válido.');
  $s = $master->prepare("SELECT id FROM portal_instalaciones WHERE slug=?"); $s->bind_param("s", $slug); $s->execute();
  if ($s->get_result()->fetch_assoc()) throw new RuntimeException('Ese código de colegio ya está en uso. Elige otro.');

  $bd = portal_nombre_bd($slug);
  $q = $master->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?"); $q->bind_param("s", $bd); $q->execute();
  if ($q->get_result()->fetch_assoc()) throw new RuntimeException('Ya existe una base de datos llamada «' . $bd . '». Elige otro código.');

  try {
    $master->query("CREATE DATABASE `$bd` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  } catch (mysqli_sql_exception $e) {
    throw new RuntimeException('No se pudo crear la base de datos del colegio. El usuario de base de datos (DB_USER) necesita permiso CREATE sobre «' . $bd . '». Detalle: ' . $e->getMessage());
  }

  $t = null;
  try {
    $t = db_abrir($bd);
    $sql = file_get_contents(__DIR__ . '/../sql/tenant_schema.sql');
    ejecutar_sql_multiple($t, $sql);

    foreach ($cfg as $k => $v) brand_guardar_clave($t, $k, (string)$v);
    foreach ($imagenes as $k => [$mime, $bin]) brand_guardar_recurso($t, $k, $mime, $bin);
    if ($imagenes) brand_guardar_clave($t, 'img_ver', (string)time());

    $nom = $admin['nombre']; $ape = ''; $usr = $admin['usuario'];
    $a = $t->prepare("INSERT INTO maestros (nombre,apellido,usuario,password,rol) VALUES (?,?,?,?,'admin')");
    $a->bind_param("ssss", $nom, $ape, $usr, $hashAdmin); $a->execute();

    $f = ahora(); $nombre = $cfg['nombre_colegio']; $iid = (int)$inst['id'];
    $u = $master->prepare("UPDATE portal_instalaciones SET slug=?, nombre_colegio=?, db_name=?, estado='activa', configurada=?, usuarios_count=1, stats_actualizadas=? WHERE id=?");
    $u->bind_param("sssssi", $slug, $nombre, $bd, $f, $f, $iid); $u->execute();
  } catch (Throwable $e) {
    try { $master->query("DROP DATABASE IF EXISTS `$bd`"); } catch (Throwable $e2) {}
    if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) throw new RuntimeException('Ese código de colegio ya está en uso. Elige otro.');
    throw new RuntimeException('No se pudo crear la instalación: ' . $e->getMessage());
  }
  return ['db_name' => $bd, 'conn' => $t];
}

// Elimina la base de datos del colegio (irreversible).
function eliminar_bd_colegio(mysqli $master, ?string $bd): void {
  if (!$bd || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $bd)) return;
  if (strpos($bd, portal_prefijo_bd()) !== 0) throw new RuntimeException('Nombre de base de datos inesperado; no se eliminó.');
  $master->query("DROP DATABASE IF EXISTS `$bd`");
}

// Recalcula y guarda las estadísticas de una instalación leyendo su BD en vivo. Devuelve la fila actualizada.
function recontar_instalacion(mysqli $master, array $inst): array {
  if (empty($inst['db_name']) || $inst['estado'] === 'pendiente') return $inst;
  try {
    $t = db_abrir($inst['db_name']);
    $c = contar_tabla($t, 'cursos'); $a = contar_tabla($t, 'alumnos'); $u = contar_tabla($t, 'maestros');
    $ult = $t->query("SELECT MAX(fecha) f FROM log_sesiones")->fetch_assoc()['f'] ?? null;
    $q = $master->prepare("SELECT COALESCE(ROUND(SUM(data_length+index_length)/1048576,2),0) mb FROM information_schema.TABLES WHERE table_schema=?");
    $q->bind_param("s", $inst['db_name']); $q->execute(); $mb = (float)$q->get_result()->fetch_assoc()['mb'];
    $f = ahora(); $id = (int)$inst['id'];
    $s = $master->prepare("UPDATE portal_instalaciones SET cursos_count=?, alumnos_count=?, usuarios_count=?, tamano_mb=?, ultimo_acceso_colegio=?, stats_actualizadas=? WHERE id=?");
    $s->bind_param("iiidssi", $c, $a, $u, $mb, $ult, $f, $id); $s->execute();
    $t->close();
    $inst = array_merge($inst, ['cursos_count'=>$c,'alumnos_count'=>$a,'usuarios_count'=>$u,'tamano_mb'=>$mb,'ultimo_acceso_colegio'=>$ult,'stats_actualizadas'=>$f]);
  } catch (Throwable $e) { /* BD inaccesible: se conservan los últimos valores */ }
  return $inst;
}

// Inicia la sesión de un administrador dentro de su colegio (usado tras el login del portal o el asistente).
function entrar_a_colegio(array $inst, mysqli $t, string $usuario): void {
  $s = $t->prepare("SELECT id, rol FROM maestros WHERE usuario=?"); $s->bind_param("s", $usuario); $s->execute();
  $m = $s->get_result()->fetch_assoc();
  if (!$m) throw new RuntimeException('No se encontró el usuario administrador dentro del colegio.');
  $_SESSION['maestro'] = $usuario; $_SESSION['id'] = (int)$m['id']; $_SESSION['rol'] = $m['rol']; $_SESSION['tenant'] = $inst['slug'];
  $_SESSION['demo_aviso'] = 1;   // muestra el aviso de versión demo una vez al ingresar (si la instalación es demo)
  $GLOBALS['TENANT'] = $inst; $GLOBALS['CFG'] = brand_cargar($t);
  registrar_login($t, (int)$m['id'], $usuario);
}

function portal_cerrar_sesion_y_redirigir(string $destino = '/'): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  header('Location: ' . $destino); exit;
}

// Estilo común (neutro) de las pantallas del portal.
function portal_head(string $titulo): void { ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($titulo) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f1f4f8;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#1f2d3d}
.pt-card{background:#fff;border:1px solid #e3e8ef;border-radius:16px;box-shadow:0 6px 24px #1f2d3d12}
.pt-brand{background:linear-gradient(135deg,#243b55,#3b6ea5);color:#fff}
.kpi{border-radius:14px;background:#fff;border:1px solid #e3e8ef;padding:14px 16px}
.kpi b{font-size:1.7rem;display:block;line-height:1.1}
</style>
<?php }
