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
      if ($inst && inst_vencida($inst)) { $error = 'Tu periodo de prueba venció el ' . date('d-m-Y', strtotime($inst['vence'])) . '. Contacta al administrador del portal para reactivarlo.'; }
      elseif ($inst && $inst['estado'] === 'suspendida') { $error = 'Tu institución está suspendida. Contacta al administrador del portal.'; }
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
// ---- Solicitud de acceso (formulario público) ----
$solOk = !empty($_SESSION['sol_ok']); unset($_SESSION['sol_ok']);
$solErr = '';
$solDatos = ['nombre' => '', 'email' => '', 'telefono' => '', 'institucion' => '', 'cargo' => '', 'mensaje' => ''];
if (($_POST['tipo'] ?? '') === 'solicitud') {
  csrf_validar();
  $lim = ['nombre' => 100, 'email' => 150, 'telefono' => 30, 'institucion' => 150, 'cargo' => 100, 'mensaje' => 1000];
  foreach ($lim as $k => $max) $solDatos[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
  $ipSol = ip_cliente(); $claveSol = 'sol:' . $ipSol;
  $t0 = (int)($_SESSION['sol_t'] ?? 0);

  if (!empty($_POST['web'])) {                      // campo trampa para bots: se finge éxito y no se guarda nada
    $_SESSION['sol_ok'] = 1; header('Location: /'); exit;
  }
  if (solicitud_limite($claveSol)) {
    $solErr = 'Has enviado varias solicitudes en poco tiempo. Inténtalo de nuevo más tarde.';
  } else {
    login_fallido($claveSol);                       // cuenta el intento para el límite por IP
    if ($solDatos['nombre'] === '' || $solDatos['institucion'] === '') $solErr = 'Completa tu nombre y el nombre de tu institución.';
    elseif (!filter_var($solDatos['email'], FILTER_VALIDATE_EMAIL)) $solErr = 'Escribe un correo electrónico válido.';
    elseif ($solDatos['telefono'] !== '' && !preg_match('/^[0-9+\s().-]{7,25}$/', $solDatos['telefono'])) $solErr = 'El teléfono no es válido (solo números, +, espacios y guiones).';
    elseif ($t0 && time() - $t0 < 3) $solErr = 'Envío demasiado rápido. Inténtalo nuevamente.';
    elseif (!captcha_validar($_POST)) $solErr = 'La verificación no es correcta. Inténtalo de nuevo.';
    else {
      try {
        solicitudes_asegurar_tabla($master);
        $q = $master->prepare("SELECT id FROM portal_solicitudes WHERE email=? AND estado='pendiente' LIMIT 1");
        $q->bind_param("s", $solDatos['email']); $q->execute();
        if (!$q->get_result()->fetch_assoc()) {      // si ya hay una pendiente con ese correo, no se duplica (igual se agradece)
          $f = ahora();
          $i = $master->prepare("INSERT INTO portal_solicitudes (nombre,email,telefono,institucion,cargo,mensaje,ip,creada) VALUES (?,?,?,?,?,?,?,?)");
          $i->bind_param("ssssssss", $solDatos['nombre'], $solDatos['email'], $solDatos['telefono'], $solDatos['institucion'], $solDatos['cargo'], $solDatos['mensaje'], $ipSol, $f);
          $i->execute();
        }
        $_SESSION['sol_ok'] = 1; header('Location: /'); exit;
      } catch (Throwable $e) { $solErr = 'No pudimos registrar tu solicitud en este momento. Inténtalo más tarde.'; }
    }
  }
}
$capPregunta = turnstile_activo() ? null : captcha_nuevo();
$_SESSION['sol_t'] = time();
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

  <div class="row g-3 mt-1"><div class="col-12"><div class="pt-card p-4" id="solicitar">
    <?php if ($solOk): ?>
      <div class="alert alert-success mb-0">✅ <strong>¡Gracias!</strong> Hemos recibido tu solicitud. Nos pondremos en contacto contigo a la brevedad.</div>
    <?php else: ?>
    <details <?= $solErr ? 'open' : '' ?>>
      <summary class="fw-bold fs-5" style="cursor:pointer">📨 ¿Aún no tienes acceso? Solicítalo aquí</summary>
      <p class="text-muted small mt-2">Déjanos tus datos y nos pondremos en contacto contigo para habilitar tu acceso.</p>
      <?php if ($solErr): ?><div class="alert alert-danger py-2"><?= h($solErr) ?></div><?php endif; ?>
      <form method="POST" class="row g-2" autocomplete="off"><?= csrf_campo() ?><input type="hidden" name="tipo" value="solicitud">
        <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true"><label>No completar este campo<input type="text" name="web" tabindex="-1" autocomplete="off"></label></div>
        <div class="col-md-6"><label class="form-label small mb-1">Nombre y apellido *</label><input name="nombre" class="form-control" required maxlength="100" value="<?= h($solDatos['nombre']) ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-1">Correo electrónico *</label><input type="email" name="email" class="form-control" required maxlength="150" value="<?= h($solDatos['email']) ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-1">Teléfono / WhatsApp</label><input name="telefono" class="form-control" maxlength="30" placeholder="+56 9 1234 5678" value="<?= h($solDatos['telefono']) ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-1">Colegio o institución *</label><input name="institucion" class="form-control" required maxlength="150" value="<?= h($solDatos['institucion']) ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-1">Cargo</label><input name="cargo" class="form-control" maxlength="100" placeholder="Ej: Profesor, Director" value="<?= h($solDatos['cargo']) ?>"></div>
        <div class="col-12"><label class="form-label small mb-1">Mensaje (opcional)</label><textarea name="mensaje" class="form-control" rows="2" maxlength="1000"><?= h($solDatos['mensaje']) ?></textarea></div>
        <div class="col-md-6">
          <?php if ($capPregunta === null): ?>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <div class="cf-turnstile" data-sitekey="<?= h(env('TURNSTILE_SITE_KEY')) ?>"></div>
          <?php else: ?>
            <label class="form-label small mb-1">Verificación: ¿cuánto es <strong><?= h($capPregunta) ?></strong>? *</label>
            <input name="captcha" class="form-control" inputmode="numeric" required maxlength="3" style="max-width:120px">
          <?php endif; ?>
        </div>
        <div class="col-md-6 d-flex align-items-end justify-content-md-end"><button class="btn btn-primary px-4">Enviar solicitud</button></div>
        <p class="small text-muted mb-0">Usaremos tus datos solo para contactarte por esta solicitud.</p>
      </form>
    </details>
    <?php endif; ?>
  </div></div></div>
</div></body></html>
