<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================================================================================
// Bootstrap común del PORTAL multi-colegio.
//  - $master : conexión a la BD maestra (portal_*, sesiones). Credenciales por variables de entorno.
//  - $conn   : en el portal = $master; dentro de /e/{colegio}/ = BD propia de ese colegio (la abre el router).
// Para pruebas locales copia config.local.example.php a config.local.php (no se sube a git).
// =====================================================================================
if (file_exists(__DIR__ . '/../config.local.php')) require_once __DIR__ . '/../config.local.php';

if (!function_exists('env')) {
  function env($k, $def = '') { $v = getenv($k); return ($v === false || $v === '') ? $def : $v; }
}

if (!function_exists('db_abrir')) {
  // Abre una conexión al servidor configurado, a la base indicada (misma credencial para todas las BD).
  function db_abrir(?string $nombreBD = null): mysqli {
    $c = mysqli_init();
    $ssl = env('DB_SSL', '1') !== '0';
    $ca  = __DIR__ . '/../certs/ca.pem';
    if ($ssl) $c->ssl_set(null, null, is_file($ca) ? $ca : null, null, null);
    $c->real_connect(env('DB_HOST'), env('DB_USER'), env('DB_PASS'), $nombreBD ?? env('DB_NAME'),
                     (int)env('DB_PORT', '3306'), null, $ssl ? MYSQLI_CLIENT_SSL : 0);
    $c->set_charset('utf8mb4');
    return $c;
  }
}

if (!function_exists('pagina_mensaje')) {
  // Página simple y neutra para errores/avisos (sin depender de ningún tema de colegio).
  function pagina_mensaje(string $titulo, string $texto, int $codigo = 200, string $enlace = '/'): void {
    http_response_code($codigo);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($titulo) . '</title>'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
       . '<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh"><div class="card shadow-sm p-4 text-center" style="max-width:460px">'
       . '<h4>' . htmlspecialchars($titulo) . '</h4><p class="text-muted mb-3">' . htmlspecialchars($texto) . '</p>'
       . '<a class="btn btn-outline-secondary" href="' . htmlspecialchars($enlace) . '">Volver al inicio</a></div></body></html>';
    exit;
  }
}

try {
  $master = db_abrir();
} catch (mysqli_sql_exception $e) {
  pagina_mensaje('Sin conexión a la base de datos',
    'No se pudo conectar a la BD maestra. Revisa las variables de entorno DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS (y DB_SSL=0 si es un MySQL local sin SSL).', 500);
}
$conn = $master;   // en el portal; el router lo reemplaza por la BD del colegio

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---------------------------- Contexto del colegio (tenant) ----------------------------
$TENANT = null;   // fila de portal_instalaciones del colegio actual (la define el router)
$CFG    = [];     // configuración/branding del colegio actual (tabla config)

if (!function_exists('tenant')) {
  function tenant(){ return $GLOBALS['TENANT']; }
  function cfg($clave, $def = ''){ $c = $GLOBALS['CFG']; return (isset($c[$clave]) && $c[$clave] !== '') ? $c[$clave] : $def; }
  function tz_colegio(){ return cfg('zona_horaria', 'America/Santiago'); }
  function es_https(){
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
  }
  function origen_sitio(){ return (es_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); }
  // URL absoluta de la raíz del colegio actual, p. ej. https://dominio/e/mi-colegio/
  function url_sitio(){ return origen_sitio() . '/e/' . $GLOBALS['TENANT']['slug'] . '/'; }
  // Imagen de marca del colegio (logo | icono | mascota); ?v= evita caché al cambiarla.
  function img_url($r){ return 'img.php?r=' . $r . '&v=' . urlencode(cfg('img_ver', '0')); }
}

// Fecha y hora actual en la zona horaria del colegio (Y-m-d H:i:s).
if (!function_exists('ahora_chile')) {   // nombre histórico; ahora respeta la zona del colegio
  function ahora_chile(){
    try { return (new DateTime('now', new DateTimeZone(tz_colegio())))->format('Y-m-d H:i:s'); }
    catch (Throwable $e) { return date('Y-m-d H:i:s'); }
  }
}

// ---------------------------- Sesiones (en la BD maestra) ----------------------------
// En Vercel cada solicitud puede atenderla un contenedor distinto: las sesiones no pueden estar en archivos.
if (!class_exists('SesionBD')) {
  class SesionBD implements SessionHandlerInterface {
    private $conn;
    function __construct($conn){ $this->conn = $conn; }
    function open($path, $name): bool { return true; }
    function close(): bool { return true; }
    function read($id): string|false {
      $s = $this->conn->prepare("SELECT datos FROM sesiones WHERE id=? AND expira > NOW()");
      $s->bind_param("s", $id); $s->execute();
      $r = $s->get_result()->fetch_assoc();
      return $r ? $r['datos'] : '';
    }
    function write($id, $datos): bool {
      $s = $this->conn->prepare("INSERT INTO sesiones (id,datos,expira) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 2 HOUR)) ON DUPLICATE KEY UPDATE datos=VALUES(datos), expira=VALUES(expira)");
      $s->bind_param("ss", $id, $datos);
      return $s->execute();
    }
    function destroy($id): bool {
      $s = $this->conn->prepare("DELETE FROM sesiones WHERE id=?"); $s->bind_param("s", $id);
      return $s->execute();
    }
    function gc($max_lifetime): int|false {
      $this->conn->query("DELETE FROM sesiones WHERE expira < NOW()");
      return 0;
    }
  }
}

if (!function_exists('iniciar_sesion')) {
  function iniciar_sesion(){
    global $master;
    if (session_status() === PHP_SESSION_NONE) {
      session_set_cookie_params(['lifetime'=>7200,'path'=>'/','secure'=>es_https(),'httponly'=>true,'samesite'=>'Lax']);
      session_set_save_handler(new SesionBD($master), true);
      session_start();
    }
  }
}

// ---------------------------- Seguridad: CSRF y bloqueo de intentos ----------------------------
if (!function_exists('csrf_token')) {
  function csrf_token(){ iniciar_sesion(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
  function csrf_campo(){ return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
  function csrf_validar(){
    iniciar_sesion();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
      pagina_mensaje('Solicitud no válida', 'El formulario caducó. Vuelve atrás, recarga la página e inténtalo de nuevo.', 400, 'javascript:history.back()');
    }
  }
  function ip_cliente(){
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
  }
  // Más de 8 intentos fallidos en 15 minutos para la misma clave (usuario+IP) → bloqueado.
  function login_bloqueado($clave){
    global $master;
    try {
      $s = $master->prepare("SELECT COUNT(*) t FROM portal_intentos WHERE clave=? AND fecha > DATE_SUB(?, INTERVAL 15 MINUTE)");
      $ahora = date('Y-m-d H:i:s'); $s->bind_param("ss", $clave, $ahora); $s->execute();
      return (int)$s->get_result()->fetch_assoc()['t'] >= 8;
    } catch (Throwable $e) { return false; }
  }
  function login_fallido($clave){
    global $master;
    try {
      $ahora = date('Y-m-d H:i:s');
      $s = $master->prepare("INSERT INTO portal_intentos (clave, fecha) VALUES (?,?)"); $s->bind_param("ss", $clave, $ahora); $s->execute();
      $master->query("DELETE FROM portal_intentos WHERE fecha < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    } catch (Throwable $e) {}
  }
  function login_ok($clave){
    global $master;
    try { $s = $master->prepare("DELETE FROM portal_intentos WHERE clave=?"); $s->bind_param("s", $clave); $s->execute(); } catch (Throwable $e) {}
  }
  function auditar($actor, $accion, $detalle = ''){
    global $master;
    try {
      $f = date('Y-m-d H:i:s'); $d = mb_substr($detalle, 0, 255);
      $s = $master->prepare("INSERT INTO portal_auditoria (fecha,actor,accion,detalle) VALUES (?,?,?,?)");
      $s->bind_param("ssss", $f, $actor, $accion, $d); $s->execute();
    } catch (Throwable $e) {}
  }
}

// ---------------------------- Login / roles dentro de un colegio ----------------------------
if (!function_exists('requiere_login')) {
  function requiere_login(){
    iniciar_sesion();
    // La sesión solo vale para el colegio donde se inició (aislamiento entre instalaciones).
    if (empty($_SESSION['maestro']) || ($_SESSION['tenant'] ?? '') !== ($GLOBALS['TENANT']['slug'] ?? '#')) {
      header("Location: index.php"); exit;
    }
  }
}
if (!function_exists('es_admin')) {
  function es_admin(){ return ($_SESSION['rol'] ?? '') === 'admin'; }
}
if (!function_exists('docente_id')) {
  function docente_id(){ return (int)($_SESSION['id'] ?? 0); }
}

// ---------------------------- Límites de la instalación (los fija el superadmin) ----------------------------
if (!function_exists('contar_tabla')) {
  function contar_tabla($conn, $tabla){ return (int)$conn->query("SELECT COUNT(*) t FROM `$tabla`")->fetch_assoc()['t']; }
  // Devuelve null si hay cupo para crear $cuantos más, o un mensaje de error si se superaría el límite.
  function limite_cursos($conn, $cuantos = 1){
    $max = (int)($GLOBALS['TENANT']['max_cursos'] ?? 0);
    if ($max <= 0) return null;
    $act = contar_tabla($conn, 'cursos');
    return ($act + $cuantos > $max) ? "Se alcanzó el límite de cursos de esta instalación ($act de $max). Pide al administrador del portal que lo amplíe." : null;
  }
  function limite_usuarios($conn, $cuantos = 1){
    $max = (int)($GLOBALS['TENANT']['max_usuarios'] ?? 0);
    if ($max <= 0) return null;
    $act = contar_tabla($conn, 'maestros');
    return ($act + $cuantos > $max) ? "Se alcanzó el límite de usuarios de esta instalación ($act de $max). Pide al administrador del portal que lo amplíe." : null;
  }
  // Refresca en la BD maestra los contadores de este colegio (se llama tras crear/borrar cursos, alumnos o usuarios).
  function sync_stats(){
    global $master, $conn, $TENANT;
    if (!$TENANT) return;
    try {
      $c = contar_tabla($conn, 'cursos'); $a = contar_tabla($conn, 'alumnos'); $u = contar_tabla($conn, 'maestros');
      $f = date('Y-m-d H:i:s'); $id = (int)$TENANT['id'];
      $s = $master->prepare("UPDATE portal_instalaciones SET cursos_count=?, alumnos_count=?, usuarios_count=?, stats_actualizadas=? WHERE id=?");
      $s->bind_param("iiisi", $c, $a, $u, $f, $id); $s->execute();
    } catch (Throwable $e) { /* nunca debe romper la página */ }
  }
}

// Ya no se usa para cursos/alumnos/metas (son compartidos por todos los docentes);
// solo filtra las opciones de canje, que siguen siendo propias de cada docente.
if (!function_exists('filtro_docente')) {
  function filtro_docente(&$sql, &$types, &$vals, $alias='c'){
    if (!es_admin()) { $sql .= " AND $alias.docente_id = ?"; $types .= 'i'; $vals[] = docente_id(); }
  }
}

// Expresión SQL con "Nombre Apellido" del maestro (si no tiene nombre cargado, usa el usuario).
if (!function_exists('sql_nombre_maestro')) {
  function sql_nombre_maestro($alias='m'){
    return "COALESCE(NULLIF(TRIM(CONCAT($alias.nombre,' ',$alias.apellido)),''), $alias.usuario)";
  }
}
// Registra un inicio de sesión exitoso (hora de Chile). Nunca debe impedir el login: si la
// tabla log_sesiones aún no existe o falla el INSERT, se ignora el error.
if (!function_exists('registrar_login')) {
  function registrar_login($conn, $maestroId, $usuario){
    try {
      $fecha = ahora_chile();
      $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))[0]);
      if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = null;
      $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
      // Ubicación aproximada: cabeceras que agrega Vercel según la IP (la ciudad viene codificada en URL).
      $pais   = strtoupper(substr($_SERVER['HTTP_X_VERCEL_IP_COUNTRY'] ?? '', 0, 2)) ?: null;
      $region = mb_substr(urldecode($_SERVER['HTTP_X_VERCEL_IP_COUNTRY_REGION'] ?? ''), 0, 10) ?: null;
      $ciudad = mb_substr(urldecode($_SERVER['HTTP_X_VERCEL_IP_CITY'] ?? ''), 0, 100) ?: null;
      $s = $conn->prepare("INSERT INTO log_sesiones (maestro_id, usuario, fecha, ip, pais, region, ciudad, user_agent) VALUES (?,?,?,?,?,?,?,?)");
      $s->bind_param("isssssss", $maestroId, $usuario, $fecha, $ip, $pais, $region, $ciudad, $ua);
      $s->execute();
    } catch (Throwable $e) { /* sin registro, pero el usuario entra igual */ }
  }
}
// --- Categorías de meta ---
// categorias.docente_id NULL = categoría base (todos); con valor = categoría propia de ese profesor.
// Categorías que el profesor con sesión puede usar al asignar puntos: las base + sus propias activas.
if (!function_exists('categorias_disponibles')) {
  function categorias_disponibles($conn){
    $id = docente_id();
    $s = $conn->prepare("SELECT id, nombre, docente_id FROM categorias WHERE docente_id IS NULL OR (docente_id=? AND activa=1) ORDER BY (docente_id IS NOT NULL), id");
    $s->bind_param("i", $id); $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
  }
}
// Imprime las <option> de categorías (las propias van agrupadas aparte).
if (!function_exists('opciones_categorias')) {
  function opciones_categorias($cats){
    $propias = [];
    foreach ($cats as $c) {
      if ($c['docente_id'] === null) echo "<option value='".(int)$c['id']."'>".h($c['nombre'])."</option>";
      else $propias[] = $c;
    }
    if ($propias) {
      echo "<optgroup label='Mis categorías de meta'>";
      foreach ($propias as $c) echo "<option value='".(int)$c['id']."'>".h($c['nombre'])."</option>";
      echo "</optgroup>";
    }
  }
}
// Subconsulta con el avance de una meta: puntos asignados DENTRO de su semana (lunes a domingo), solo de los
// alumnos del curso y solo con la categoría de la meta. Meta sin categoría (antiguas) = todos los puntos.
if (!function_exists('sql_avance_meta')) {
  function sql_avance_meta($mt='mt', $c='c'){
    return "COALESCE((SELECT SUM(r.puntos) FROM registro_puntos r JOIN alumnos al ON al.id=r.alumno_id
              WHERE al.curso_id=$c.id
                AND r.fecha >= $mt.semana_inicio AND r.fecha < DATE_ADD($mt.semana_inicio, INTERVAL 7 DAY)
                AND ($mt.categoria_id IS NULL OR r.categoria_id = $mt.categoria_id)),0)";
  }
}
// Asignatura del maestro con sesión iniciada (null si no tiene). Se guarda en cada movimiento.
if (!function_exists('asignatura_docente')) {
  function asignatura_docente($conn){
    static $cache = false;
    if ($cache === false) {
      $id = docente_id();
      $s = $conn->prepare("SELECT asignatura_id FROM maestros WHERE id=?");
      $s->bind_param("i", $id); $s->execute();
      $r = $s->get_result()->fetch_assoc();
      $cache = ($r && $r['asignatura_id'] !== null) ? (int)$r['asignatura_id'] : null;
    }
    return $cache;
  }
}

// Branding del colegio: se carga siempre que exista contexto de colegio (el router la usa antes de las páginas).
require_once __DIR__ . '/branding.php';
if (!function_exists('brand_cargar_seguro')) {
  function brand_cargar_seguro($conn){ return brand_cargar($conn); }
}
