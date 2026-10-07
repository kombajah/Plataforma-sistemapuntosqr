<?php
// Portal: acceso de superadmin / administradores de colegio, y entrada de docentes por código.
require_once __DIR__ . '/lib.php';

// Ya con sesión de portal → al destino correspondiente.
if (($_SESSION['portal_rol'] ?? '') === 'superadmin') { header('Location: superadmin.php'); exit; }
if (($_SESSION['portal_rol'] ?? '') === 'admin') { header('Location: setup.php'); exit; }

$error = ''; $errorDoc = '';

// ---- Acceso docentes: código del colegio ----
if (($_POST['tipo'] ?? '') === 'docente') {
  $cod = strtolower(trim($_POST['codigo'] ?? ''));
  $s = $master->prepare("SELECT slug FROM portal_instalaciones WHERE slug=? AND estado<>'pendiente'"); $s->bind_param("s", $cod); $s->execute();
  if ($cod !== '' && $s->get_result()->fetch_assoc()) { header('Location: /e/' . $cod . '/'); exit; }
  $errorDoc = 'No encontramos un colegio con ese código.';
}

// ---- Acceso administradores / superadmin ----
if (($_POST['tipo'] ?? '') === 'admin') {
  $u = trim($_POST['usuario'] ?? ''); $p = $_POST['password'] ?? '';
  $clave = 'p:' . mb_strtolower($u) . ':' . ip_cliente();
  if (login_bloqueado($clave)) {
    $error = 'Demasiados intentos fallidos. Espera 15 minutos e inténtalo de nuevo.';
  } else {
    $ok = false;
    // 1) Superadmin
    $s = $master->prepare("SELECT id, usuario, password FROM portal_superadmins WHERE usuario=?"); $s->bind_param("s", $u); $s->execute();
    $sa = $s->get_result()->fetch_assoc();
    if ($sa && password_verify($p, $sa['password'])) {
      session_regenerate_id(true);
      $_SESSION['portal_rol'] = 'superadmin'; $_SESSION['portal_id'] = (int)$sa['id']; $_SESSION['portal_usuario'] = $sa['usuario'];
      $f = ahora(); $i = (int)$sa['id'];
      $x = $master->prepare("UPDATE portal_superadmins SET ultimo_acceso=? WHERE id=?"); $x->bind_param("si", $f, $i); $x->execute();
      login_ok($clave); auditar($sa['usuario'], 'login', 'Superadmin');
      header('Location: superadmin.php'); exit;
    }
    // 2) Administrador de colegio
    $s = $master->prepare("SELECT * FROM portal_admins WHERE usuario=? AND activo=1"); $s->bind_param("s", $u); $s->execute();
    $ad = $s->get_result()->fetch_assoc();
    if ($ad) {
      $inst = portal_instalacion_de_admin($master, (int)$ad['id']);
      if ($inst && $inst['estado'] === 'suspendida') { $error = 'Tu institución está suspendida. Contacta al administrador del portal.'; }
      elseif ($inst && $inst['estado'] === 'activa') {
        // Ya configurado: la fuente de verdad de la contraseña es la BD del colegio.
        try {
          $t = db_abrir($inst['db_name']);
          $q = $t->prepare("SELECT password FROM maestros WHERE usuario=? AND rol='admin'"); $q->bind_param("s", $ad['usuario']); $q->execute();
          $m = $q->get_result()->fetch_assoc();
          if ($m && password_verify($p, $m['password'])) {
            session_regenerate_id(true);
            entrar_a_colegio($inst, $t, $ad['usuario']);
            $_SESSION['portal_rol'] = 'admin'; $_SESSION['portal_id'] = (int)$ad['id']; $_SESSION['portal_usuario'] = $ad['usuario'];
            $f = ahora(); $i = (int)$ad['id'];
            $x = $master->prepare("UPDATE portal_admins SET ultimo_acceso=? WHERE id=?"); $x->bind_param("si", $f, $i); $x->execute();
            login_ok($clave);
            header('Location: /e/' . $inst['slug'] . '/reporte.php'); exit;
          }
        } catch (Throwable $e) { $error = 'No se pudo abrir la base de datos de tu colegio. Inténtalo más tarde.'; }
      } elseif (password_verify($p, $ad['password'])) {
        // Primera vez: aún sin colegio configurado → asistente de configuración.
        session_regenerate_id(true);
        $_SESSION['portal_rol'] = 'admin'; $_SESSION['portal_id'] = (int)$ad['id']; $_SESSION['portal_usuario'] = $ad['usuario'];
        $f = ahora(); $i = (int)$ad['id'];
        $x = $master->prepare("UPDATE portal_admins SET ultimo_acceso=? WHERE id=?"); $x->bind_param("si", $f, $i); $x->execute();
        login_ok($clave);
        header('Location: setup.php'); exit;
      }
    }
    if (!$error) { login_fallido($clave); $error = 'Usuario o clave incorrectos.'; }
  }
}
$portal = env('PORTAL_NOMBRE', 'Portal Sistema de Puntos');
?>
<!DOCTYPE html><html lang="es"><head><?php portal_head($portal); ?></head>
<body class="d-flex align-items-center" style="min-height:100vh">
<div class="container py-4" style="max-width:920px">
  <div class="text-center mb-4"><div style="font-size:3rem">🎓</div><h2 class="fw-bold mb-1"><?= h($portal) ?></h2>
    <p class="text-muted mb-0">Gestión de puntos con NFC/QR para colegios</p></div>
  <div class="row g-3">
    <div class="col-md-6"><div class="pt-card p-4 h-100">
      <h5 class="fw-bold mb-1">🔑 Administradores</h5>
      <p class="text-muted small">Ingresa con el usuario y la clave que te entregó el administrador del portal. La primera vez podrás configurar tu colegio.</p>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
      <form method="POST"><input type="hidden" name="tipo" value="admin">
        <input name="usuario" class="form-control mb-2" placeholder="Usuario" required autocomplete="username">
        <input type="password" name="password" class="form-control mb-3" placeholder="Clave" required autocomplete="current-password">
        <button class="btn btn-primary w-100">Ingresar</button></form>
    </div></div>
    <div class="col-md-6"><div class="pt-card p-4 h-100">
      <h5 class="fw-bold mb-1">👩‍🏫 Docentes</h5>
      <p class="text-muted small">Escribe el código de tu colegio (te lo da tu administrador) para ir a tu acceso.</p>
      <?php if ($errorDoc): ?><div class="alert alert-warning py-2"><?= h($errorDoc) ?></div><?php endif; ?>
      <form method="POST"><input type="hidden" name="tipo" value="docente">
        <input name="codigo" class="form-control mb-3" placeholder="Código del colegio (ej: mi-colegio)" required autocapitalize="none">
        <button class="btn btn-outline-primary w-100">Ir a mi colegio</button></form>
    </div></div>
  </div>
</div></body></html>
