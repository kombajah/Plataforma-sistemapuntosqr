<?php
// Asistente de primera configuración del colegio (solo administradores con instalación pendiente).
require_once __DIR__ . '/lib.php';

if (($_SESSION['portal_rol'] ?? '') !== 'admin') { header('Location: /'); exit; }
$aid = (int)$_SESSION['portal_id'];
$s = $master->prepare("SELECT * FROM portal_admins WHERE id=? AND activo=1"); $s->bind_param("i", $aid); $s->execute();
$admin = $s->get_result()->fetch_assoc();
$inst = $admin ? portal_instalacion_de_admin($master, $aid) : null;
if (!$admin || !$inst) { portal_cerrar_sesion_y_redirigir('/'); }

// Si ya está configurado, va directo a su colegio (la configuración se edita allí).
if ($inst['estado'] === 'activa') { header('Location: /e/' . $inst['slug'] . '/reporte.php'); exit; }
if ($inst['estado'] === 'suspendida') pagina_mensaje('Institución suspendida', 'Tu institución está suspendida. Contacta al administrador del portal.', 403);

$cfg = brand_defaults(); $slug = ''; $errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validar();
  [$dat, $img, , $errores] = brand_validar($_POST, $_FILES, []);
  $cfg = array_merge($cfg, $dat);
  $slug = strtolower(trim($_POST['slug'] ?? ''));
  if (!slug_valido($slug)) $errores[] = 'El código del colegio debe tener 3 a 30 caracteres: minúsculas, números y guiones (sin guiones dobles ni al inicio/fin).';

  $nueva = $_POST['nueva_clave'] ?? '';
  if ($nueva !== '' && strlen($nueva) < 8) $errores[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
  if ($nueva !== '' && $nueva !== ($_POST['nueva_clave2'] ?? '')) $errores[] = 'Las contraseñas no coinciden.';

  if (!$errores) {
    try {
      $hash = $nueva !== '' ? password_hash($nueva, PASSWORD_DEFAULT) : $admin['password'];
      $r = provisionar_colegio($master, $inst, $admin, $slug, $cfg, $img, $hash);
      if ($nueva !== '') { $x = $master->prepare("UPDATE portal_admins SET password=? WHERE id=?"); $x->bind_param("si", $hash, $aid); $x->execute(); }
      auditar($admin['usuario'], 'colegio_configurado', $slug . ' → ' . $r['db_name']);
      $inst = portal_instalacion_de_admin($master, $aid);
      session_regenerate_id(true);
      entrar_a_colegio($inst, $r['conn'], $admin['usuario']);
      header('Location: /e/' . $slug . '/configuracion.php?nuevo=1'); exit;
    } catch (Throwable $e) { $errores[] = $e->getMessage(); }
  }
}
?>
<!DOCTYPE html><html lang="es"><head><?php portal_head('Configura tu colegio'); ?></head>
<body>
<div class="pt-brand py-3 mb-4"><div class="container d-flex justify-content-between align-items-center" style="max-width:860px">
  <div><strong>🎓 Configura tu colegio</strong><div class="small opacity-75">Hola, <?= h($admin['nombre']) ?>. Este asistente se completa una sola vez; después podrás editarlo.</div></div>
  <a href="salir.php" class="btn btn-sm btn-outline-light">Salir</a></div></div>
<div class="container pb-5" style="max-width:860px">
  <?php if ($errores): ?><div class="alert alert-danger"><strong>Revisa lo siguiente:</strong><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div><?php endif; ?>
  <div class="alert alert-info small">Al guardar se creará una <strong>base de datos exclusiva</strong> para tu colegio y tu usuario <strong><?= h($admin['usuario']) ?></strong> quedará como administrador. Límites asignados: <strong><?= (int)$inst['max_cursos'] ?: 'sin límite' ?></strong> cursos y <strong><?= (int)$inst['max_usuarios'] ?: 'sin límite' ?></strong> usuarios.</div>
  <form method="POST" enctype="multipart/form-data" autocomplete="off">
    <?= csrf_campo() ?>
    <?php brand_formulario($cfg, 'setup', ['slug' => $slug]); ?>
    <div class="bf-sec" style="background:#fff;border:1px solid #e3e6ea;border-radius:14px;padding:18px;margin-bottom:16px">
      <h5 class="fw-bold">🔒 Tu contraseña <small class="text-muted fw-normal">(opcional)</small></h5>
      <p class="small text-muted">Si quieres cambiar la clave que te entregaron, escríbela aquí (mínimo 8 caracteres). Si lo dejas vacío, se mantiene.</p>
      <div class="row g-2"><div class="col-md-6"><input type="password" name="nueva_clave" class="form-control" placeholder="Nueva contraseña" autocomplete="new-password"></div>
      <div class="col-md-6"><input type="password" name="nueva_clave2" class="form-control" placeholder="Repite la contraseña" autocomplete="new-password"></div></div>
    </div>
    <button class="btn btn-primary btn-lg w-100">✅ Crear mi colegio</button>
    <p class="text-center text-muted small mt-2">La creación puede tardar unos segundos.</p>
  </form>
</div></body></html>
