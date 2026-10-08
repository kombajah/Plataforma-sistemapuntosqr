<?php
// Instalador del portal: crea/actualiza las tablas de la BD maestra y el PRIMER superadmin.
// Protegido con la variable de entorno INSTALL_KEY (así nadie más puede reclamar el portal).
require_once __DIR__ . '/lib.php';

$installKey = env('INSTALL_KEY');
$msg = ''; $err = ''; $hecho = false;
$yaHay = false;
try { $yaHay = $master->query("SHOW TABLES LIKE 'portal_superadmins'")->num_rows > 0 && contar_tabla($master, 'portal_superadmins') > 0; } catch (Throwable $e) {}

if ($installKey === '') {
  $err = 'Falta definir la variable de entorno INSTALL_KEY (una frase larga y secreta) en tu hosting. Defínela, vuelve a desplegar y recarga esta página.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($installKey, (string)($_POST['install_key'] ?? ''))) {
    $err = 'La clave de instalación no es correcta.';
  } else {
    try {
      ejecutar_sql_multiple($master, file_get_contents(__DIR__ . '/../sql/master_schema.sql'));
      portal_migrar($master);
      $msg = 'Tablas del portal creadas/actualizadas correctamente.';
      $n = contar_tabla($master, 'portal_superadmins');
      if ($n === 0) {
        $u = trim($_POST['usuario'] ?? ''); $p = $_POST['password'] ?? ''; $nom = trim($_POST['nombre'] ?? '') ?: 'Superadministrador';
        if (!usuario_valido($u)) throw new RuntimeException('Usuario no válido (3-50 caracteres: letras, números, . _ -).');
        if (strlen($p) < 10) throw new RuntimeException('La contraseña del superadmin debe tener al menos 10 caracteres.');
        $f = ahora(); $h = password_hash($p, PASSWORD_DEFAULT);
        $s = $master->prepare("INSERT INTO portal_superadmins (usuario,nombre,password,creado) VALUES (?,?,?,?)");
        $s->bind_param("ssss", $u, $nom, $h, $f); $s->execute();
        $msg .= ' Superadmin «' . $u . '» creado.';
      }
      $hecho = true; $yaHay = true;
    } catch (Throwable $e) { $err = $e->getMessage(); }
  }
}
?>
<!DOCTYPE html><html lang="es"><head><?php portal_head('Instalar portal'); ?></head>
<body class="d-flex align-items-center" style="min-height:100vh"><div class="container py-4" style="max-width:520px">
<div class="pt-card p-4">
  <h4 class="fw-bold">⚙️ Instalación del portal</h4>
  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($hecho): ?>
    <a class="btn btn-primary w-100" href="/">Ir al portal e ingresar</a>
    <p class="small text-muted mt-3 mb-0">Por seguridad, puedes quitar la variable <code>INSTALL_KEY</code> de tu hosting cuando termines.</p>
  <?php elseif ($installKey !== ''): ?>
  <form method="POST" autocomplete="off">
    <label class="form-label fw-semibold">Clave de instalación (INSTALL_KEY)</label>
    <input type="password" name="install_key" class="form-control mb-3" required>
    <?php if (!$yaHay): ?>
      <hr><p class="fw-semibold mb-2">Crear el primer superadmin</p>
      <input name="nombre" class="form-control mb-2" placeholder="Nombre">
      <input name="usuario" class="form-control mb-2" placeholder="Usuario" required>
      <input type="password" name="password" class="form-control mb-3" placeholder="Contraseña (mín. 10 caracteres)" required autocomplete="new-password">
    <?php else: ?><p class="small text-muted">El portal ya tiene superadmin. Esta acción solo actualiza las tablas (seguro de repetir).</p><?php endif; ?>
    <button class="btn btn-primary w-100"><?= $yaHay ? 'Actualizar tablas' : 'Instalar' ?></button>
  </form>
  <?php endif; ?>
</div></div></body></html>
