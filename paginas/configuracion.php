<?php
// Configuración del colegio (nombre, colores, imágenes, asistente…). Solo administrador del colegio.
require_once 'conexion.php'; requiere_login();
if (!es_admin()) { http_response_code(403); die("Solo el administrador puede editar la configuración."); }

$mensaje = ''; $errores = [];
$cfg = $GLOBALS['CFG'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validar();
  [$dat, $img, $borrar, $errores] = brand_validar($_POST, $_FILES, $cfg);
  if (!$errores) {
    try {
      brand_aplicar($conn, $dat, $img, $borrar);
      // Mantiene el nombre visible en el panel del superadmin
      $n = $dat['nombre_colegio']; $id = (int)$TENANT['id'];
      $s = $master->prepare("UPDATE portal_instalaciones SET nombre_colegio=? WHERE id=?"); $s->bind_param("si", $n, $id); $s->execute();
      $GLOBALS['CFG'] = brand_cargar($conn);   // recarga: el tema nuevo se ve de inmediato
      $cfg = $GLOBALS['CFG'];
      $mensaje = 'Configuración guardada.';
    } catch (Throwable $e) { $errores[] = 'No se pudo guardar: ' . $e->getMessage(); }
  } else {
    $cfg = array_merge($cfg, $dat);   // conserva lo escrito para corregirlo
  }
}

$nuevo = isset($_GET['nuevo']) && $_SERVER['REQUEST_METHOD'] !== 'POST';
$mc = (int)($TENANT['max_cursos'] ?? 0); $mu = (int)($TENANT['max_usuarios'] ?? 0);
$usoC = contar_tabla($conn, 'cursos'); $usoU = contar_tabla($conn, 'maestros');
?>
<!DOCTYPE html><html lang="es"><head><title>Configuración</title><?php include 'head.php'; ?></head>
<body class="bg-light"><?php include 'menu.php'; ?>
<div class="container mt-2 mb-4" style="max-width:860px">
  <?php if ($nuevo): ?>
    <div class="alert alert-success"><strong>🎉 ¡Tu colegio está listo!</strong> Comparte esta dirección con tus docentes para que ingresen con el usuario y clave que tú les crees en <a href="maestros.php">Maestros</a>:
      <div class="mt-2"><input class="form-control" readonly value="<?= h(url_sitio()) ?>" onclick="this.select()"></div>
      <div class="small mt-2">También pueden entrar escribiendo el código <strong><?= h($TENANT['slug']) ?></strong> en la portada del portal. Te recomendamos empezar creando <a href="contenido.php">cursos</a> y <a href="maestros.php">asignaturas y docentes</a>.</div></div>
  <?php endif; ?>
  <?php if ($mensaje) echo "<div class='alert alert-success'>" . h($mensaje) . "</div>"; ?>
  <?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div><?php endif; ?>

  <div class="card p-3 mb-3 shadow-sm">
    <h6 class="text-primary mb-2">Tu plan</h6>
    <div class="row small">
      <div class="col-6">Cursos: <strong><?= $usoC ?></strong> de <strong><?= $mc > 0 ? $mc : 'sin límite' ?></strong></div>
      <div class="col-6">Usuarios (docentes + admins): <strong><?= $usoU ?></strong> de <strong><?= $mu > 0 ? $mu : 'sin límite' ?></strong></div>
    </div>
    <?php $dv = dias_restantes($TENANT); ?>
    <div class="small mt-1">Vigencia: <strong><?= $dv === null ? 'sin vencimiento' : 'hasta el ' . h(date('d-m-Y', strtotime($TENANT['vence']))) . ' (' . ($dv <= 0 ? 'vence hoy' : 'quedan ' . $dv . ' día' . ($dv === 1 ? '' : 's')) . ')' ?></strong></div>
    <div class="small text-muted mt-1">Si necesitas más cupo, solicítalo al administrador del portal.</div>
  </div>

  <h4 class="text-primary mb-3">🎨 Configuración del sitio</h4>
  <form method="POST" enctype="multipart/form-data" autocomplete="off">
    <?= csrf_campo() ?>
    <?php brand_formulario($cfg, 'editar', ['url' => url_sitio(), 'img_base' => 'img.php?r=']); ?>
    <button class="btn btn-primary w-100 py-2">💾 Guardar cambios</button>
  </form>
</div>
<?php include 'footer.php'; ?>
</body></html>
