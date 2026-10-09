<?php
// Único punto de entrada desplegado como función Vercel (límite de 12 funciones del plan Hobby).
//   /                      → portal (login de administradores y superadmin, acceso docentes por código)
//   /superadmin.php        → panel del superadmin
//   /setup.php             → asistente de primera configuración del colegio (administrador)
//   /instalar.php          → crea las tablas del portal y el primer superadmin
//   /e/{colegio}/pagina    → aplicación del colegio (BD propia de cada colegio)
require_once __DIR__ . '/../paginas/conexion.php';

$ruta = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// ======================= APLICACIÓN DE UN COLEGIO =======================
if (preg_match('#^/e/([a-z0-9][a-z0-9-]{1,28}[a-z0-9])(/.*)?$#', $ruta, $m)) {
  $slug = $m[1]; $resto = $m[2] ?? '';
  if ($resto === '') {   // las URL relativas de las páginas necesitan la barra final
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /e/' . $slug . '/' . ($qs !== '' ? '?' . $qs : '')); exit;
  }

  $s = $master->prepare("SELECT * FROM portal_instalaciones WHERE slug=?");
  $s->bind_param("s", $slug); $s->execute();
  $TENANT = $s->get_result()->fetch_assoc();
  if (!$TENANT || $TENANT['estado'] === 'pendiente') pagina_mensaje('Colegio no encontrado', 'No existe un colegio con el código «' . $slug . '». Revisa la dirección.', 404);
  if ($TENANT['estado'] === 'suspendida') pagina_mensaje('Servicio suspendido', 'El acceso de este colegio está suspendido temporalmente. Contacta al administrador del portal.', 503);

  if (inst_vencida($TENANT)) pagina_mensaje('Periodo de prueba vencido', 'La vigencia de este colegio terminó el ' . date('d-m-Y', strtotime($TENANT['vence'])) . '. Contacta al administrador del portal para reactivarla.', 403);

  try { $conn = db_abrir($TENANT['db_name']); }
  catch (mysqli_sql_exception $e) { pagina_mensaje('Base de datos no disponible', 'No se pudo abrir la base de datos de este colegio. Inténtalo más tarde.', 500); }

  $CFG = brand_cargar_seguro($conn);
  try {   // la fecha de la BD queda en la hora del colegio
    $off = (new DateTime('now', new DateTimeZone(tz_colegio())))->format('P');
    $conn->query("SET time_zone = '" . $off . "'");
  } catch (Throwable $e) {}

  $pagina = basename($resto);

  // Páginas públicas (sin sesión): imágenes de marca y reporte para apoderados.
  if ($pagina === 'img.php' || $pagina === 'reporte_apoderado.php') { require __DIR__ . '/../paginas/' . $pagina; exit; }

  iniciar_sesion();
  // Una sesión iniciada en otro colegio no vale aquí.
  if (($_SESSION['tenant'] ?? '') !== $slug) { unset($_SESSION['maestro'], $_SESSION['id'], $_SESSION['rol']); }

  $permitidas = [
    'reporte.php','nfc.php','canje.php','historico.php','contenido.php','maestros.php',
    'tarjetas.php','tarjetas_imprimir.php','carga_masiva.php','plantilla_alumnos.php',
    'qr.php','buscar_alumno.php','salir.php','metas.php','log_sesiones.php','configuracion.php'
  ];
  if (in_array($pagina, $permitidas, true)) { require __DIR__ . '/../paginas/' . $pagina; exit; }
  if ($pagina === '' || $pagina === 'index.php') { require __DIR__ . '/../paginas/login.php'; exit; }
  pagina_mensaje('Página no encontrada', 'La página solicitada no existe.', 404, '/e/' . $slug . '/');
}

// ======================= PORTAL =======================
$pagina = basename($ruta);
$rutasPortal = ['' => 'home.php', 'index.php' => 'home.php', 'superadmin.php' => 'superadmin.php',
                'setup.php' => 'setup.php', 'instalar.php' => 'instalar.php', 'salir.php' => 'salir.php', 'solicitudes.php' => 'solicitudes.php'];
if (!isset($rutasPortal[$pagina])) pagina_mensaje('Página no encontrada', 'La página solicitada no existe.', 404);

if ($pagina !== 'instalar.php') {
  // Si el portal aún no se instaló (faltan tablas) se envía al instalador.
  $ok = $master->query("SHOW TABLES LIKE 'portal_superadmins'")->num_rows > 0 && $master->query("SHOW TABLES LIKE 'sesiones'")->num_rows > 0;
  if (!$ok) { header('Location: /instalar.php'); exit; }
  iniciar_sesion();
}
require __DIR__ . '/../portal/' . $rutasPortal[$pagina];
