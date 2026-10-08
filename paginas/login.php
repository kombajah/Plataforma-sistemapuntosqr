<?php
// Login de docentes y administradores de UN colegio (BD propia). Lo incluye el router en /e/{colegio}/.
if (!empty($_SESSION['maestro']) && ($_SESSION['tenant'] ?? '') === $TENANT['slug']) { header("Location: reporte.php"); exit; }
$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $u = trim($_POST['usuario'] ?? '');
  $clave = 't:' . $TENANT['slug'] . ':' . mb_strtolower($u) . ':' . ip_cliente();
  if (login_bloqueado($clave)) {
    $error = "Demasiados intentos fallidos. Espera 15 minutos e inténtalo de nuevo.";
  } else {
    $s = $conn->prepare("SELECT id, usuario, password, rol FROM maestros WHERE usuario = ?");
    $s->bind_param("s", $u); $s->execute();
    $r = $s->get_result()->fetch_assoc();
    if ($r && password_verify($_POST['password'] ?? '', $r['password'])) {
      session_regenerate_id(true);
      $_SESSION['maestro'] = $r['usuario']; $_SESSION['id'] = $r['id']; $_SESSION['rol'] = $r['rol'];
      $_SESSION['tenant'] = $TENANT['slug'];
      $_SESSION['demo_aviso'] = 1;   // el pie de página muestra el aviso de versión demo una vez, si corresponde
      login_ok($clave);
      registrar_login($conn, (int)$r['id'], $r['usuario']);
      header("Location: reporte.php"); exit;
    }
    login_fallido($clave);
    $error = "Usuario o contraseña incorrectos.";
  }
}
?>
<!DOCTYPE html><html lang="es"><head><title><?= h(cfg('nombre_colegio')) ?> - Sistema de Puntos</title><?php include __DIR__ . '/head.php'; ?></head>
<body class="d-flex flex-column" style="min-height:100vh">
  <div class="flex-grow-1 d-flex align-items-center justify-content-center px-3">
    <div class="card login-card p-4 text-center w-100">
      <img src="<?= h(img_url('logo')) ?>" alt="<?= h(cfg('nombre_colegio')) ?>" class="login-avatar mx-auto mb-3">
      <h2 class="login-title mb-0"><?= h(cfg('nombre_colegio')) ?></h2>
      <p class="login-subtitle mb-4"><?= h(cfg('subtitulo_login', 'Acceso Docente')) ?></p>
      <form method="POST">
        <?php if($error) echo "<div class='alert alert-danger'>".h($error)."</div>"; ?>
        <input type="text" name="usuario" class="form-control mb-3" placeholder="Usuario" required autofocus autocomplete="username">
        <input type="password" name="password" class="form-control mb-3" placeholder="Contraseña" required autocomplete="current-password">
        <button class="btn login-btn w-100 rounded-pill py-2">Ingresar al Sistema</button>
      </form>
    </div>
  </div>
  <?php include __DIR__ . '/footer.php'; ?>
</body></html>
