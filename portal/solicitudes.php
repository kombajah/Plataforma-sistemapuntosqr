<?php
// Gestión de solicitudes de acceso enviadas desde el formulario público de la portada.
require_once __DIR__ . '/lib.php';

if (($_SESSION['portal_rol'] ?? '') !== 'superadmin') { header('Location: /'); exit; }
$yo = $_SESSION['portal_usuario'];
portal_migrar($master);   // asegura que la tabla exista

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$filtro = $_GET['f'] ?? 'pendiente';
if (!in_array($filtro, ['pendiente', 'contactado', 'descartado', 'todas'], true)) $filtro = 'pendiente';
function volver_sol($f, $ok = null, $err = null){
  if ($ok) $_SESSION['flash'] = ['ok', $ok]; if ($err) $_SESSION['flash'] = ['err', $err];
  header('Location: solicitudes.php?f=' . urlencode($f)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validar();
  $a = $_POST['accion'] ?? ''; $id = (int)($_POST['id'] ?? 0); $f = ahora();
  try {
    if ($a === 'estado') {
      $e = $_POST['estado'] ?? '';
      if (!in_array($e, ['pendiente', 'contactado', 'descartado'], true)) volver_sol($filtro, null, 'Estado no válido.');
      $p = $master->prepare("SELECT estado FROM portal_solicitudes WHERE id=?"); $p->bind_param("i", $id); $p->execute();
      $antes = $p->get_result()->fetch_assoc()['estado'] ?? '';
      $s = $master->prepare("UPDATE portal_solicitudes SET estado=?, actualizada=?, gestionada_por=? WHERE id=?");
      $s->bind_param("sssi", $e, $f, $yo, $id); $s->execute();
      if ($antes !== $e) solicitud_agregar_nota($master, $id, $yo, 'Estado: ' . $antes . ' → ' . $e, 'evento');
      auditar($yo, 'solicitud_' . $e, 'ID ' . $id);
      volver_sol($filtro, 'Solicitud marcada como ' . $e . '.');
    } elseif ($a === 'nota') {
      $n = trim($_POST['nota'] ?? '');
      if ($n === '') volver_sol($filtro, null, 'Escribe el texto de la nota.');
      solicitud_agregar_nota($master, $id, $yo, $n);   // se agrega al historial; no reemplaza notas anteriores
      $s = $master->prepare("UPDATE portal_solicitudes SET actualizada=?, gestionada_por=? WHERE id=?");
      $s->bind_param("ssi", $f, $yo, $id); $s->execute();
      volver_sol($filtro, 'Nota agregada al historial.');
    } elseif ($a === 'eliminar') {
      $s = $master->prepare("DELETE FROM portal_solicitud_notas WHERE solicitud_id=?"); $s->bind_param("i", $id); $s->execute();
      $s = $master->prepare("DELETE FROM portal_solicitudes WHERE id=?"); $s->bind_param("i", $id); $s->execute();
      auditar($yo, 'solicitud_eliminada', 'ID ' . $id);
      volver_sol($filtro, 'Solicitud eliminada.');
    }
  } catch (Throwable $e) { volver_sol($filtro, null, 'Error: ' . $e->getMessage()); }
}

$cont = ['pendiente' => 0, 'contactado' => 0, 'descartado' => 0];
foreach ($master->query("SELECT estado, COUNT(*) t FROM portal_solicitudes GROUP BY estado") as $r) $cont[$r['estado']] = (int)$r['t'];
$total = array_sum($cont);
if ($filtro === 'todas') $filas = $master->query("SELECT * FROM portal_solicitudes ORDER BY creada DESC LIMIT 300")->fetch_all(MYSQLI_ASSOC);
else {
  $s = $master->prepare("SELECT * FROM portal_solicitudes WHERE estado=? ORDER BY creada DESC LIMIT 300");
  $s->bind_param("s", $filtro); $s->execute(); $filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);
}
$notasPor = [];
if ($filas) {
  $ids = implode(',', array_map(fn($r) => (int)$r['id'], $filas));
  foreach ($master->query("SELECT * FROM portal_solicitud_notas WHERE solicitud_id IN ($ids) ORDER BY creada ASC, id ASC") as $n) $notasPor[(int)$n['solicitud_id']][] = $n;
}
$badge = ['pendiente' => 'warning', 'contactado' => 'success', 'descartado' => 'secondary'];
$tabs = ['pendiente' => 'Pendientes', 'contactado' => 'Contactados', 'descartado' => 'Descartados', 'todas' => 'Todas'];
?>
<!DOCTYPE html><html lang="es"><head><?php portal_head('Solicitudes de acceso'); ?>
<style>.tabla td,.tabla th{vertical-align:top;font-size:.88rem}.msg{max-width:280px;white-space:pre-wrap;word-break:break-word}</style></head>
<body>
<div class="pt-brand py-3 mb-4"><div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div><strong>📨 Solicitudes de acceso</strong><div class="small opacity-75">Sesión: <?= h($yo) ?></div></div>
  <div class="d-flex gap-2"><a href="superadmin.php" class="btn btn-sm btn-outline-light">← Panel</a><a href="salir.php" class="btn btn-sm btn-light">Salir</a></div></div></div>

<div class="container pb-5">
  <?php if ($flash): ?><div class="alert alert-<?= $flash[0] === 'ok' ? 'success' : 'danger' ?>"><?= h($flash[1]) ?></div><?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $cont['pendiente'] ?></b>Pendientes</div></div>
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $cont['contactado'] ?></b>Contactados</div></div>
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $cont['descartado'] ?></b>Descartados</div></div>
    <div class="col-6 col-md-3"><div class="kpi"><b><?= $total ?></b>Total recibidas</div></div>
  </div>

  <ul class="nav nav-pills mb-3">
    <?php foreach ($tabs as $k => $t): ?><li class="nav-item"><a class="nav-link <?= $filtro === $k ? 'active' : '' ?>" href="solicitudes.php?f=<?= $k ?>"><?= $t ?><?= $k !== 'todas' ? ' (' . $cont[$k] . ')' : '' ?></a></li><?php endforeach; ?>
  </ul>

  <div class="pt-card p-3"><div class="table-responsive"><table class="table table-hover tabla mb-0">
    <thead class="table-light"><tr><th>Recibida</th><th>Solicitante</th><th>Contacto</th><th>Mensaje</th><th>Estado y notas</th><th></th></tr></thead>
    <tbody>
    <?php if (!$filas): ?><tr><td colspan="6" class="text-center text-muted py-4">No hay solicitudes en esta vista.</td></tr><?php endif; ?>
    <?php foreach ($filas as $r): $tel = preg_replace('/\D+/', '', $r['telefono']); ?>
    <tr>
      <td class="text-nowrap"><?= h(substr($r['creada'], 0, 16)) ?></td>
      <td><strong><?= h($r['nombre']) ?></strong><br><?= h($r['institucion']) ?><?php if ($r['cargo']): ?><br><small class="text-muted"><?= h($r['cargo']) ?></small><?php endif; ?></td>
      <td><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a>
        <?php if ($r['telefono']): ?><br><?= h($r['telefono']) ?><?php if (strlen($tel) >= 8): ?> · <a target="_blank" rel="noopener" href="https://wa.me/<?= h($tel) ?>">WhatsApp</a><?php endif; ?><?php endif; ?></td>
      <td class="msg"><?= $r['mensaje'] !== '' ? h($r['mensaje']) : '<span class="text-muted">—</span>' ?></td>
      <td>
        <span class="badge text-bg-<?= $badge[$r['estado']] ?>"><?= h($r['estado']) ?></span>
        <?php if ($r['gestionada_por']): ?><br><small class="text-muted"><?= h($r['gestionada_por']) ?> · <?= h(substr((string)$r['actualizada'], 0, 16)) ?></small><?php endif; ?>
        <div class="mt-2" style="min-width:260px;max-width:340px">
          <?php if ($r['nota'] !== ''): ?>
            <div class="small border-start border-2 ps-2 mb-1"><span class="text-muted">Nota anterior</span><br><?= nl2br(h($r['nota'])) ?></div>
          <?php endif; ?>
          <?php foreach ($notasPor[(int)$r['id']] ?? [] as $n): ?>
            <div class="small border-start border-2 ps-2 mb-1 <?= $n['tipo'] === 'evento' ? 'text-muted fst-italic' : '' ?>" style="<?= $n['tipo'] === 'evento' ? '' : 'border-color:#3b6ea5!important' ?>">
              <span class="text-muted"><?= h(substr($n['creada'], 0, 16)) ?> · <?= h($n['autor']) ?></span><br><?= nl2br(h($n['texto'])) ?></div>
          <?php endforeach; ?>
          <form method="POST" class="mt-2"><?= csrf_campo() ?><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <textarea name="nota" class="form-control form-control-sm" rows="2" maxlength="500" required placeholder="Agregar una nota…"></textarea>
            <button class="btn btn-sm btn-outline-secondary mt-1">➕ Agregar nota</button></form>
        </div>
      </td>
      <td class="text-nowrap">
        <?php foreach (['contactado' => ['✅ Contactado', 'success'], 'pendiente' => ['⏳ Pendiente', 'warning'], 'descartado' => ['🚫 Descartar', 'secondary']] as $e => [$lbl, $cl]): if ($e === $r['estado']) continue; ?>
          <form method="POST" class="d-block mb-1"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="estado" value="<?= $e ?>"><button class="btn btn-sm btn-outline-<?= $cl ?> w-100"><?= $lbl ?></button></form>
        <?php endforeach; ?>
        <a class="btn btn-sm btn-primary w-100 mb-1" href="superadmin.php?desde=<?= (int)$r['id'] ?>#nuevoAdmin">➕ Crear administrador</a>
        <form method="POST" onsubmit="return confirm('¿Eliminar esta solicitud definitivamente?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">Eliminar</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="small text-muted mt-2 mb-0">Se muestran las 300 solicitudes más recientes de cada vista. Los datos los ingresa quien visita la portada; trátalos con confidencialidad.</p>
  </div>
</div>
</body></html>
