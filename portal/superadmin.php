<?php
// Panel del superadmin: crea administradores (con su clave), ve todas las instalaciones, fija límites,
// suspende, regenera claves, recalcula estadísticas y elimina instalaciones.
require_once __DIR__ . '/lib.php';

if (($_SESSION['portal_rol'] ?? '') !== 'superadmin') { header('Location: /'); exit; }
$yo = $_SESSION['portal_usuario']; $yoId = (int)$_SESSION['portal_id'];
portal_migrar($master);   // agrega la columna de vigencia si la BD es anterior

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$credenciales = $_SESSION['credenciales'] ?? null; unset($_SESSION['credenciales']);
function volver($ok = null, $err = null){ if ($ok) $_SESSION['flash'] = ['ok', $ok]; if ($err) $_SESSION['flash'] = ['err', $err]; header('Location: superadmin.php'); exit; }

function cargar_inst($master, $id){
  $s = $master->prepare("SELECT i.*, a.usuario, a.nombre admin_nombre, a.id aid FROM portal_instalaciones i JOIN portal_admins a ON a.id=i.admin_id WHERE i.id=?");
  $s->bind_param("i", $id); $s->execute(); return $s->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validar();
  $a = $_POST['accion'] ?? '';
  try {
    if ($a === 'nuevo_admin') {
      $nom = mb_substr(trim($_POST['nombre'] ?? ''), 0, 100); $email = mb_substr(trim($_POST['email'] ?? ''), 0, 150);
      $usr = trim($_POST['usuario'] ?? '');
      $mc = max(0, (int)($_POST['max_cursos'] ?? 10)); $mu = max(0, (int)($_POST['max_usuarios'] ?? 20));
      $dias = min(3650, max(0, (int)($_POST['dias_vigencia'] ?? 0))); $vence = calcular_vence($dias); $demo = !empty($_POST['es_demo']) ? 1 : 0;
      $desc = mb_substr(trim($_POST['descripcion'] ?? ''), 0, 255);
      if ($nom === '') volver(null, 'Escribe el nombre de la persona.');
      if (!usuario_valido($usr)) volver(null, 'Usuario no válido (3-50 caracteres: letras, números, . _ -).');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) volver(null, 'Correo no válido.');
      $q = $master->prepare("SELECT (SELECT COUNT(*) FROM portal_admins WHERE usuario=?) + (SELECT COUNT(*) FROM portal_superadmins WHERE usuario=?) t");
      $q->bind_param("ss", $usr, $usr); $q->execute();
      if ((int)$q->get_result()->fetch_assoc()['t'] > 0) volver(null, 'Ese usuario ya existe.');
      $clave = generar_clave(); $hash = password_hash($clave, PASSWORD_DEFAULT); $f = ahora();
      $s = $master->prepare("INSERT INTO portal_admins (nombre,email,usuario,password,creado,creado_por) VALUES (?,?,?,?,?,?)");
      $s->bind_param("ssssss", $nom, $email, $usr, $hash, $f, $yo); $s->execute(); $aid = (int)$master->insert_id;
      $s = $master->prepare("INSERT INTO portal_instalaciones (admin_id,max_cursos,max_usuarios,vence,es_demo,descripcion,creada) VALUES (?,?,?,?,?,?,?)");
      $s->bind_param("iiisiss", $aid, $mc, $mu, $vence, $demo, $desc, $f); $s->execute();
      auditar($yo, 'admin_creado', "$usr ($nom) cursos=$mc usuarios=$mu vigencia=" . ($dias ? "$dias días" : 'sin vencimiento') . ($demo ? ' DEMO' : ''));
      $_SESSION['credenciales'] = ['usuario' => $usr, 'clave' => $clave, 'nombre' => $nom, 'nuevo' => true];
      volver();

    } elseif ($a === 'limites') {
      $i = cargar_inst($master, (int)$_POST['id']); if (!$i) volver(null, 'Instalación no encontrada.');
      $mc = max(0, (int)$_POST['max_cursos']); $mu = max(0, (int)$_POST['max_usuarios']);
      $s = $master->prepare("UPDATE portal_instalaciones SET max_cursos=?, max_usuarios=?, descripcion=? WHERE id=?"); $desc = mb_substr(trim($_POST['descripcion'] ?? ''), 0, 255); $s->bind_param("iisi", $mc, $mu, $desc, $i['id']); $s->execute();
      auditar($yo, 'limites', ($i['slug'] ?: $i['usuario']) . " cursos=$mc usuarios=$mu");
      volver('Límites actualizados.');

    } elseif ($a === 'vigencia') {
      $i = cargar_inst($master, (int)$_POST['id']); if (!$i) volver(null, 'Instalación no encontrada.');
      $dias = min(3650, max(0, (int)($_POST['dias'] ?? 0))); $demo = !empty($_POST['es_demo']) ? 1 : 0;
      if (!empty($_POST['sin_vencimiento'])) { $nuevo = null; }
      elseif ($dias < 1) { $nuevo = $i['vence'] ?: null; }   // 0 días: no se cambia la fecha (sirve para marcar/desmarcar demo)
      else {
        // Si aún está vigente se suma al vencimiento actual; si ya venció (o no tenía) se cuenta desde hoy.
        $base = (!empty($i['vence']) && strtotime($i['vence']) > time()) ? strtotime($i['vence']) : time();
        $nuevo = calcular_vence($dias, $base);
      }
      $s = $master->prepare("UPDATE portal_instalaciones SET vence=?, es_demo=? WHERE id=?"); $s->bind_param("sii", $nuevo, $demo, $i['id']); $s->execute();
      auditar($yo, 'vigencia', ($i['slug'] ?: $i['usuario']) . ' → ' . ($nuevo ?: 'sin vencimiento') . ($demo ? ' (demo)' : ''));
      volver('Vigencia actualizada: ' . ($nuevo ? 'hasta el ' . date('d-m-Y', strtotime($nuevo)) : 'sin vencimiento') . '.');

    } elseif ($a === 'estado') {
      $i = cargar_inst($master, (int)$_POST['id']);
      if (!$i || $i['estado'] === 'pendiente') volver(null, 'Solo se pueden suspender/reactivar instalaciones ya configuradas.');
      $nuevo = $i['estado'] === 'activa' ? 'suspendida' : 'activa';
      $s = $master->prepare("UPDATE portal_instalaciones SET estado=? WHERE id=?"); $s->bind_param("si", $nuevo, $i['id']); $s->execute();
      auditar($yo, $nuevo === 'activa' ? 'reactivada' : 'suspendida', $i['slug']);
      volver('«' . $i['nombre_colegio'] . '» ahora está ' . $nuevo . '.');

    } elseif ($a === 'regenerar') {
      $i = cargar_inst($master, (int)$_POST['id']); if (!$i) volver(null, 'Instalación no encontrada.');
      $clave = generar_clave(); $hash = password_hash($clave, PASSWORD_DEFAULT);
      $s = $master->prepare("UPDATE portal_admins SET password=? WHERE id=?"); $s->bind_param("si", $hash, $i['aid']); $s->execute();
      if ($i['estado'] !== 'pendiente' && $i['db_name']) {   // mantener la BD del colegio sincronizada
        $t = db_abrir($i['db_name']);
        $q = $t->prepare("SELECT id FROM maestros WHERE usuario=?"); $q->bind_param("s", $i['usuario']); $q->execute();
        if ($q->get_result()->fetch_assoc()) {
          $u = $t->prepare("UPDATE maestros SET password=?, rol='admin', clave_texto=NULL WHERE usuario=?"); $u->bind_param("ss", $hash, $i['usuario']); $u->execute();
        } else {
          $n = $i['admin_nombre']; $e = ''; $u = $t->prepare("INSERT INTO maestros (nombre,apellido,usuario,password,rol) VALUES (?,?,?,?,'admin')");
          $u->bind_param("ssss", $n, $e, $i['usuario'], $hash); $u->execute();
        }
      }
      auditar($yo, 'clave_regenerada', $i['usuario']);
      $_SESSION['credenciales'] = ['usuario' => $i['usuario'], 'clave' => $clave, 'nombre' => $i['admin_nombre'], 'nuevo' => false];
      volver();

    } elseif ($a === 'eliminar') {
      $i = cargar_inst($master, (int)$_POST['id']); if (!$i) volver(null, 'Instalación no encontrada.');
      $esperado = $i['slug'] ?: $i['usuario'];
      if (trim($_POST['confirmar'] ?? '') !== $esperado) volver(null, 'Para eliminar debes escribir exactamente «' . $esperado . '».');
      if ($i['db_name']) eliminar_bd_colegio($master, $i['db_name']);
      $s = $master->prepare("DELETE FROM portal_admins WHERE id=?"); $s->bind_param("i", $i['aid']); $s->execute();   // la instalación cae en cascada
      auditar($yo, 'eliminada', $esperado . ($i['db_name'] ? ' (BD ' . $i['db_name'] . ' borrada)' : ''));
      volver('Instalación «' . $esperado . '» eliminada.');

    } elseif ($a === 'recontar') {
      $i = cargar_inst($master, (int)$_POST['id']); if ($i) recontar_instalacion($master, $i);
      volver('Estadísticas actualizadas.');

    } elseif ($a === 'recontar_todo') {
      $r = $master->query("SELECT * FROM portal_instalaciones WHERE estado<>'pendiente'");
      $n = 0; while ($i = $r->fetch_assoc()) { recontar_instalacion($master, $i); $n++; }
      volver("Estadísticas de $n instalación(es) actualizadas.");

    } elseif ($a === 'mi_clave') {
      $p = $_POST['nueva'] ?? '';
      $s = $master->prepare("SELECT password FROM portal_superadmins WHERE id=?"); $s->bind_param("i", $yoId); $s->execute();
      $cur = $s->get_result()->fetch_assoc();
      if (!$cur || !password_verify($_POST['actual'] ?? '', $cur['password'])) volver(null, 'La contraseña actual no es correcta.');
      if (strlen($p) < 10) volver(null, 'La nueva contraseña debe tener al menos 10 caracteres.');
      if ($p !== ($_POST['nueva2'] ?? '')) volver(null, 'Las contraseñas nuevas no coinciden.');
      $h = password_hash($p, PASSWORD_DEFAULT);
      $s = $master->prepare("UPDATE portal_superadmins SET password=? WHERE id=?"); $s->bind_param("si", $h, $yoId); $s->execute();
      auditar($yo, 'cambio_clave', 'Superadmin');
      volver('Contraseña actualizada.');
    }
  } catch (Throwable $e) { volver(null, 'Error: ' . $e->getMessage()); }
}

// ---------------- Datos para mostrar ----------------
$filas = $master->query("SELECT i.*, a.usuario, a.nombre admin_nombre, a.email, a.ultimo_acceso admin_acceso
                         FROM portal_instalaciones i JOIN portal_admins a ON a.id=i.admin_id ORDER BY i.creada DESC")->fetch_all(MYSQLI_ASSOC);
$tot = ['inst' => count($filas), 'act' => 0, 'pend' => 0, 'sus' => 0, 'cursos' => 0, 'alumnos' => 0];
foreach ($filas as $f) {
  if ($f['estado'] === 'activa') $tot['act']++; elseif ($f['estado'] === 'pendiente') $tot['pend']++; else $tot['sus']++;
  $tot['cursos'] += (int)$f['cursos_count']; $tot['alumnos'] += (int)$f['alumnos_count'];
}
$audit = $master->query("SELECT * FROM portal_auditoria ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
$base = origen_sitio();
function medidor($act, $max){
  if ($max <= 0) return '<span>' . (int)$act . '</span> <small class="text-muted">/ ∞</small>';
  $pct = min(100, round($act * 100 / $max)); $cl = $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success');
  return '<span class="fw-semibold">' . (int)$act . '</span> <small class="text-muted">/ ' . (int)$max . '</small>'
       . '<div class="progress" style="height:5px;min-width:70px"><div class="progress-bar ' . $cl . '" style="width:' . $pct . '%"></div></div>';
}
$badge = ['activa' => 'success', 'pendiente' => 'secondary', 'suspendida' => 'danger'];
?>
<!DOCTYPE html><html lang="es"><head><?php portal_head('Panel superadmin'); ?>
<style>.tabla td,.tabla th{vertical-align:middle;font-size:.88rem}.mono{font-family:ui-monospace,Consolas,monospace;font-size:.82rem}</style></head>
<body>
<div class="pt-brand py-3 mb-4"><div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div><strong>🛡️ Panel superadmin</strong><div class="small opacity-75">Sesión: <?= h($yo) ?></div></div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-light" data-bs-toggle="collapse" data-bs-target="#miCuenta">🔒 Mi contraseña</button>
    <a href="salir.php" class="btn btn-sm btn-light">Salir</a></div></div></div>

<div class="container pb-5">
  <?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'success' : 'danger' ?>"><?= h($flash[1]) ?></div><?php endif; ?>

  <?php if ($credenciales): ?>
  <div class="alert alert-warning border-warning">
    <h5 class="mb-2">🔑 <?= $credenciales['nuevo'] ? 'Administrador creado' : 'Nueva clave generada' ?> — <?= h($credenciales['nombre']) ?></h5>
    <p class="mb-2">Entrega estos datos a la persona. <strong>La clave solo se muestra ahora</strong> (se guarda cifrada y no se puede volver a ver; si se pierde, regenérala).</p>
    <div class="bg-white border rounded p-3 mono" id="credBox">Dirección: <?= h($base) ?>/<br>Usuario: <strong><?= h($credenciales['usuario']) ?></strong><br>Clave: <strong><?= h($credenciales['clave']) ?></strong></div>
    <button class="btn btn-sm btn-dark mt-2" onclick="navigator.clipboard.writeText(document.getElementById('credBox').innerText)">📋 Copiar</button>
  </div>
  <?php endif; ?>

  <div class="collapse mb-3" id="miCuenta"><div class="pt-card p-3"><form method="POST" class="row g-2"><?= csrf_campo() ?><input type="hidden" name="accion" value="mi_clave">
    <div class="col-md-3"><input type="password" name="actual" class="form-control" placeholder="Contraseña actual" required></div>
    <div class="col-md-3"><input type="password" name="nueva" class="form-control" placeholder="Nueva (mín. 10)" required autocomplete="new-password"></div>
    <div class="col-md-3"><input type="password" name="nueva2" class="form-control" placeholder="Repetir nueva" required autocomplete="new-password"></div>
    <div class="col-md-3"><button class="btn btn-dark w-100">Cambiar</button></div></form></div></div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $tot['inst'] ?></b>Instalaciones</div></div>
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $tot['act'] ?></b>Activas <small class="text-muted">· <?= $tot['pend'] ?> pend. · <?= $tot['sus'] ?> susp.</small></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $tot['cursos'] ?></b>Cursos en total</div></div>
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $tot['alumnos'] ?></b>Alumnos en total</div></div>
  </div>

  <div class="pt-card p-4 mb-4">
    <h5 class="fw-bold">➕ Nuevo administrador de colegio</h5>
    <p class="text-muted small">Se genera una clave segura. Al ingresar por primera vez, la persona configurará su colegio (nombre, colores, mascota…) y se creará su base de datos.</p>
    <form method="POST" class="row g-2" autocomplete="off"><?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_admin">
      <div class="col-md-4"><input name="nombre" class="form-control" placeholder="Nombre y apellido" required maxlength="100"></div>
      <div class="col-md-4"><input type="email" name="email" class="form-control" placeholder="Correo (opcional)" maxlength="150"></div>
      <div class="col-md-4"><input name="usuario" class="form-control" placeholder="Usuario de acceso" required maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}"></div>
      <div class="col-12"><input name="descripcion" class="form-control" maxlength="255" placeholder="Descripción / referencia (opcional): ¿a quién se asigna? Ej: Profesora de Lenguaje, Escuela X, contacto por WhatsApp"></div>
      <div class="col-md-3"><label class="small text-muted">Máx. cursos (0 = sin límite)</label><input type="number" min="0" name="max_cursos" class="form-control" value="10"></div>
      <div class="col-md-3"><label class="small text-muted">Máx. usuarios (0 = sin límite)</label><input type="number" min="0" name="max_usuarios" class="form-control" value="20"></div>
      <div class="col-md-3"><label class="small text-muted">Vigencia en días (0 = sin vencimiento)</label><input type="number" min="0" max="3650" name="dias_vigencia" class="form-control" value="30"></div>
      <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">Crear y generar clave</button></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="es_demo" value="1" id="nuevo_demo"><label class="form-check-label" for="nuevo_demo"><strong>Versión demo</strong> <span class="text-muted small">— el sitio mostrará un aviso de «versión de prueba» con los días de vigencia que quedan (en el pie de página y en una ventana al iniciar sesión).</span></label></div></div>
    </form>
  </div>

  <div class="pt-card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <h5 class="fw-bold mb-0">🏫 Instalaciones</h5>
      <form method="POST"><?= csrf_campo() ?><input type="hidden" name="accion" value="recontar_todo"><button class="btn btn-sm btn-outline-secondary">↻ Actualizar todos los conteos</button></form>
    </div>
    <div class="table-responsive"><table class="table table-hover tabla mb-0">
      <thead class="table-light"><tr><th>Colegio</th><th>Administrador</th><th>Base de datos</th><th>Cursos</th><th>Usuarios</th><th>Alumnos</th><th>Tamaño</th><th>Estado</th><th>Vigencia</th><th></th></tr></thead>
      <tbody>
      <?php if (!$filas): ?><tr><td colspan="10" class="text-center text-muted py-4">Aún no hay instalaciones. Crea el primer administrador arriba.</td></tr><?php endif; ?>
      <?php foreach ($filas as $f): $conf = $f['estado'] !== 'pendiente'; ?>
      <tr>
        <td><?php if ($conf): ?><strong><?= h($f['nombre_colegio']) ?></strong><br><a class="mono" target="_blank" href="/e/<?= h($f['slug']) ?>/">/e/<?= h($f['slug']) ?>/</a>
            <?php else: ?><em class="text-muted">Sin configurar</em><br><small class="text-muted">creada <?= h(substr($f['creada'], 0, 10)) ?></small><?php endif; ?></td>
        <td><?= h($f['admin_nombre']) ?><br><span class="mono"><?= h($f['usuario']) ?></span><?php if ($f['email']): ?><br><small class="text-muted"><?= h($f['email']) ?></small><?php endif; ?>
            <br><small class="text-muted">Últ. ingreso: <?= $f['admin_acceso'] ? h(substr($f['admin_acceso'], 0, 16)) : '—' ?></small>
            <?php if (!empty($f['descripcion'])): ?><div class="small fst-italic mt-1" style="max-width:230px;color:#5a6b7d">📝 <?= h($f['descripcion']) ?></div><?php endif; ?></td>
        <td><?= $f['db_name'] ? '<span class="mono">' . h($f['db_name']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td><?= medidor($f['cursos_count'], $f['max_cursos']) ?></td>
        <td><?= medidor($f['usuarios_count'], $f['max_usuarios']) ?></td>
        <td><?= (int)$f['alumnos_count'] ?></td>
        <td><?= $conf ? h(number_format((float)$f['tamano_mb'], 2)) . ' MB' : '—' ?></td>
        <td><span class="badge text-bg-<?= $badge[$f['estado']] ?>"><?= h($f['estado']) ?></span>
            <?php if ($conf && $f['stats_actualizadas']): ?><br><small class="text-muted" title="Última actualización de conteos"><?= h(substr($f['stats_actualizadas'], 5, 11)) ?></small><?php endif; ?></td>
        <td><?php if (!empty($f['es_demo'])) echo '<span class="badge text-bg-warning mb-1">DEMO</span><br>'; $dr = dias_restantes($f);
          if ($dr === null) echo '<span class="text-muted">Sin vencimiento</span>';
          elseif ($dr < 0) echo '<span class="badge text-bg-danger">Vencida</span><br><small class="text-muted">' . h(date('d-m-Y', strtotime($f['vence']))) . '</small>';
          else echo '<span class="' . ($dr <= 5 ? 'text-danger fw-semibold' : '') . '">' . h(date('d-m-Y', strtotime($f['vence']))) . '</span><br><small class="text-muted">' . ($dr === 0 ? 'vence hoy' : 'quedan ' . $dr . ' día' . ($dr === 1 ? '' : 's')) . '</small>'; ?></td>
        <td class="text-end text-nowrap">
          <div class="dropdown"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">Acciones ▾</button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#mLim" data-id="<?= (int)$f['id'] ?>" data-mc="<?= (int)$f['max_cursos'] ?>" data-mu="<?= (int)$f['max_usuarios'] ?>" data-desc="<?= h($f['descripcion'] ?? '') ?>" data-n="<?= h($f['nombre_colegio'] ?: $f['usuario']) ?>">📏 Editar límites y descripción</button></li>
            <?php if ($conf): ?>
            <li><form method="POST"><?= csrf_campo() ?><input type="hidden" name="accion" value="recontar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="dropdown-item">↻ Actualizar conteos</button></form></li>
            <li><form method="POST"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="dropdown-item"><?= $f['estado'] === 'activa' ? '⏸️ Suspender' : '▶️ Reactivar' ?></button></form></li>
            <?php endif; ?>
            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#mVig" data-id="<?= (int)$f['id'] ?>" data-n="<?= h($f['nombre_colegio'] ?: $f['usuario']) ?>" data-v="<?= h($f['vence'] ? date('d-m-Y', strtotime($f['vence'])) : 'sin vencimiento') ?>" data-demo="<?= !empty($f['es_demo']) ? 1 : 0 ?>">📅 Extender / reactivar vigencia</button></li>
            <li><form method="POST" onsubmit="return confirm('Se generará una clave nueva y la anterior dejará de funcionar. ¿Continuar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="regenerar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="dropdown-item">🔑 Regenerar clave del admin</button></form></li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#mDel" data-id="<?= (int)$f['id'] ?>" data-c="<?= h($f['slug'] ?: $f['usuario']) ?>" data-bd="<?= h($f['db_name'] ?? '') ?>">🗑️ Eliminar…</button></li>
          </ul></div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p class="small text-muted mt-2 mb-0">Los conteos se actualizan solos cuando el colegio crea o borra cursos, alumnos y usuarios; usa «Actualizar» para forzar una lectura en vivo.</p>
  </div>

  <div class="pt-card p-3">
    <h6 class="fw-bold">📜 Actividad reciente</h6>
    <div class="table-responsive"><table class="table table-sm tabla mb-0"><tbody>
      <?php foreach ($audit as $x): ?><tr><td class="text-muted text-nowrap"><?= h($x['fecha']) ?></td><td><?= h($x['actor']) ?></td><td><span class="badge text-bg-light border"><?= h($x['accion']) ?></span></td><td><?= h($x['detalle']) ?></td></tr><?php endforeach; ?>
      <?php if (!$audit): ?><tr><td class="text-muted">Sin actividad todavía.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>

<!-- Modal límites -->
<div class="modal fade" id="mLim" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><?= csrf_campo() ?><input type="hidden" name="accion" value="limites"><input type="hidden" name="id" id="lim_id">
  <div class="modal-header"><h5 class="modal-title">Límites y descripción de <span id="lim_n"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">Máximo de cursos <small class="text-muted">(0 = sin límite)</small></label><input type="number" min="0" name="max_cursos" id="lim_mc" class="form-control mb-3">
    <label class="form-label">Máximo de usuarios (docentes + administradores) <small class="text-muted">(0 = sin límite)</small></label><input type="number" min="0" name="max_usuarios" id="lim_mu" class="form-control mb-3">
    <label class="form-label">Descripción / referencia <small class="text-muted">(solo la ve el superadmin)</small></label><textarea name="descripcion" id="lim_desc" class="form-control" rows="2" maxlength="255" placeholder="¿A quién se asignó este administrador?"></textarea>
    <p class="small text-muted mt-2 mb-0">Si el colegio ya supera el nuevo límite, conserva lo creado pero no podrá agregar más.</p></div>
  <div class="modal-footer"><button class="btn btn-primary">Guardar</button></div></form></div></div>

<!-- Modal vigencia -->
<div class="modal fade" id="mVig" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><?= csrf_campo() ?><input type="hidden" name="accion" value="vigencia"><input type="hidden" name="id" id="vig_id">
  <div class="modal-header"><h5 class="modal-title">Vigencia de <span id="vig_n"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p class="small text-muted">Vencimiento actual: <strong id="vig_v"></strong></p>
    <label class="form-label">Días a agregar <small class="text-muted">(0 = no cambiar la fecha)</small></label><input type="number" min="0" max="3650" name="dias" id="vig_d" class="form-control mb-2" value="30">
    <div class="small text-muted mb-3">Si aún está vigente, se suman al vencimiento actual. Si ya venció, se cuentan desde hoy y el colegio vuelve a quedar habilitado (sus datos no se pierden).</div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="sin_vencimiento" value="1" id="vig_sv"><label class="form-check-label" for="vig_sv">Sin vencimiento (acceso indefinido)</label></div>
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="es_demo" value="1" id="vig_demo"><label class="form-check-label" for="vig_demo">Versión demo (mostrar aviso de prueba en el sitio)</label></div></div>
  <div class="modal-footer"><button class="btn btn-primary">Guardar</button></div></form></div></div>

<!-- Modal eliminar -->
<div class="modal fade" id="mDel" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" id="del_id">
  <div class="modal-header"><h5 class="modal-title text-danger">Eliminar instalación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p>Se eliminará <strong>definitivamente</strong> el administrador<span id="del_bdtxt"></span>. <strong>No se puede deshacer.</strong></p>
    <label class="form-label">Escribe <code id="del_c"></code> para confirmar</label><input name="confirmar" class="form-control" required autocomplete="off"></div>
  <div class="modal-footer"><button class="btn btn-danger">Eliminar para siempre</button></div></form></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Los menús "Acciones" se posicionan de forma fija para que la tabla (overflow) no los recorte
document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(b){
  new bootstrap.Dropdown(b,{popperConfig:function(c){return Object.assign({},c,{strategy:'fixed'});}});
});
document.getElementById('mLim').addEventListener('show.bs.modal',function(e){var b=e.relatedTarget;
  lim_id.value=b.dataset.id;lim_mc.value=b.dataset.mc;lim_mu.value=b.dataset.mu;lim_desc.value=b.dataset.desc||'';lim_n.textContent=b.dataset.n;});
document.getElementById('mVig').addEventListener('show.bs.modal',function(e){var b=e.relatedTarget;vig_id.value=b.dataset.id;vig_n.textContent=b.dataset.n;vig_v.textContent=b.dataset.v;vig_demo.checked=b.dataset.demo==='1';vig_sv.checked=false;});
document.getElementById('mDel').addEventListener('show.bs.modal',function(e){var b=e.relatedTarget;
  del_id.value=b.dataset.id;del_c.textContent=b.dataset.c;del_bdtxt.textContent=b.dataset.bd?' y su base de datos «'+b.dataset.bd+'» (cursos, alumnos, puntos, todo)':'';});
</script>
</body></html>
