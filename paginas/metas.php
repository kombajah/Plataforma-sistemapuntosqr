<?php
require_once 'conexion.php'; requiere_login();
$mensaje=''; $error='';

// Las metas se asocian solo al curso; los cursos son compartidos, basta con que exista.
function curso_existe_meta($conn,$cid){
  $s=$conn->prepare("SELECT id FROM cursos WHERE id=?"); $s->bind_param("i",$cid); $s->execute();
  return (bool)$s->get_result()->fetch_assoc();
}
// La categoría debe ser del profesor con sesión y estar activa.
function categoria_propia_activa($conn,$id){
  $uid = docente_id();
  $s=$conn->prepare("SELECT id FROM categorias WHERE id=? AND docente_id=? AND activa=1"); $s->bind_param("ii",$id,$uid); $s->execute();
  return (bool)$s->get_result()->fetch_assoc();
}
function lunes_de_semana_iso($valorWeek){
  // $valorWeek viene de <input type="week"> con formato "YYYY-Www"
  if (!preg_match('/^(\d{4})-W(\d{2})$/', $valorWeek, $m)) return null;
  $dt = new DateTime();
  $dt->setISODate((int)$m[1], (int)$m[2], 1); // 1 = lunes
  return $dt->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['crear_meta'])) {
    $cid = (int)$_POST['curso_id'];
    $obj = (int)$_POST['puntos_objetivo'];
    $desc = mb_substr(trim($_POST['descripcion'] ?? ''), 0, 255);
    $catId = (int)($_POST['categoria_id'] ?? 0);
    $lunes = lunes_de_semana_iso(trim($_POST['semana'] ?? ''));
    if (!curso_existe_meta($conn,$cid)) $error = "Curso no válido.";
    elseif (!$lunes) $error = "Elige una semana válida.";
    elseif ($obj < 1) $error = "El puntaje objetivo debe ser mayor a 0.";
    elseif (!categoria_propia_activa($conn, $catId)) $error = "Elige una de tus categorías de meta activas.";
    else {
      try {
        $s = $conn->prepare("INSERT INTO metas (curso_id,semana_inicio,puntos_objetivo,descripcion,creado_por,categoria_id) VALUES (?,?,?,?,?,?)");
        $mid = docente_id();
        $descVal = $desc !== '' ? $desc : null;
        $s->bind_param("isisii", $cid, $lunes, $obj, $descVal, $mid, $catId); $s->execute();
        $mensaje = "Meta creada.";
      } catch (mysqli_sql_exception $e) { $error = "Ya tienes una meta para ese curso en esa semana."; }
    }
  } elseif (isset($_POST['crear_categoria'])) {
    $nom = mb_substr(trim($_POST['nombre_categoria'] ?? ''), 0, 50);
    $uid = docente_id();
    if ($nom === '') $error = "Escribe el nombre de la categoría.";
    else {
      // No se repite con las categorías base ni con las propias.
      $s=$conn->prepare("SELECT id FROM categorias WHERE nombre=? AND (docente_id IS NULL OR docente_id=?)"); $s->bind_param("si",$nom,$uid); $s->execute();
      if ($s->get_result()->fetch_assoc()) $error = "Ya existe una categoría con ese nombre.";
      else {
        $s=$conn->prepare("INSERT INTO categorias (nombre, docente_id, activa) VALUES (?,?,1)"); $s->bind_param("si",$nom,$uid); $s->execute();
        $mensaje = "Categoría creada. Ya puedes usarla en una meta y al asignar puntos.";
      }
    }
  } elseif (isset($_POST['toggle_categoria'])) {
    $id = (int)$_POST['id']; $nuevo = (int)($_POST['activa'] ?? 0) ? 1 : 0; $uid = docente_id();
    // Solo el dueño de la categoría puede activarla o desactivarla.
    $s=$conn->prepare("UPDATE categorias SET activa=? WHERE id=? AND docente_id=?"); $s->bind_param("iii",$nuevo,$id,$uid); $s->execute();
    $mensaje = $nuevo ? "Categoría activada." : "Categoría desactivada: ya no aparece al asignar puntos ni para nuevas metas.";
  } elseif (isset($_POST['borrar_meta'])) {
    $id = (int)$_POST['id'];
    $s=$conn->prepare("SELECT creado_por FROM metas WHERE id=?"); $s->bind_param("i",$id); $s->execute();
    $m=$s->get_result()->fetch_assoc();
    // Solo el profesor que asignó la meta (o el administrador) puede eliminarla.
    if (!$m || (!es_admin() && (int)$m['creado_por'] !== docente_id())) $error = "No tienes permiso sobre esa meta.";
    else { $s=$conn->prepare("DELETE FROM metas WHERE id=?"); $s->bind_param("i",$id); $s->execute(); $mensaje="Meta eliminada."; }
  }
}

$cursos = $conn->query("SELECT id, nombre FROM cursos ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$misCats = (function($conn){ $uid=docente_id();
  $s=$conn->prepare("SELECT id, nombre, activa FROM categorias WHERE docente_id=? ORDER BY activa DESC, nombre"); $s->bind_param("i",$uid); $s->execute();
  return $s->get_result()->fetch_all(MYSQLI_ASSOC); })($conn);
$catsActivas = array_values(array_filter($misCats, fn($k) => (int)$k['activa'] === 1));

$sql = "SELECT mt.id, mt.semana_inicio, mt.puntos_objetivo, mt.descripcion, mt.creado_por, c.nombre curso, k.nombre categoria, k.activa categoria_activa, ".sql_nombre_maestro('m')." profesor,
  ".sql_avance_meta('mt','c')." avance
  FROM metas mt JOIN cursos c ON c.id=mt.curso_id LEFT JOIN maestros m ON m.id=mt.creado_por LEFT JOIN categorias k ON k.id=mt.categoria_id
  ORDER BY mt.semana_inicio DESC, c.nombre";
$metas = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$semanaActual = (new DateTime())->format('o-\WW');
?>
<!DOCTYPE html><html lang="es"><head><title>Metas semanales</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <?php if($mensaje) echo "<div class='alert alert-success'>".h($mensaje)."</div>"; ?>
  <?php if($error) echo "<div class='alert alert-danger'>".h($error)."</div>"; ?>

  <div class="card p-4 shadow-sm mb-3">
    <h4 class="text-primary mb-3">🎯 Nueva meta semanal</h4>
    <?php if(!$cursos): ?><p class="text-muted">Primero crea un curso en Contenido.</p><?php else: ?>
    <?php if(!$catsActivas): ?>
      <div class="alert alert-warning py-2">Para crear una meta primero crea una categoría en <a href="#misCategorias">Mis categorías de meta</a> (ej. "Reunión de apoderado").</div>
    <?php endif; ?>
    <form method="POST" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label small">Curso</label>
        <select name="curso_id" class="form-select" required>
          <?php foreach($cursos as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small">Semana</label>
        <input type="week" name="semana" class="form-control" value="<?= $semanaActual ?>" required></div>
      <div class="col-md-3"><label class="form-label small">Categoría de la meta</label>
        <select name="categoria_id" class="form-select" required <?= $catsActivas ? '' : 'disabled' ?>>
          <option value="" disabled selected>Elige una categoría...</option>
          <?php foreach($catsActivas as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-2"><label class="form-label small">Puntos meta</label>
        <input type="number" name="puntos_objetivo" class="form-control" min="1" value="20" required></div>
      <div class="col-md-10"><label class="form-label small">Descripción (qué se debe lograr)</label>
        <input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="Ej: 85% de asistencia a la reunión de apoderados"></div>
      <div class="col-md-2"><button name="crear_meta" class="btn btn-primary w-100" <?= $catsActivas ? '' : 'disabled' ?>>Crear meta</button></div>
    </form>
    <div class="small text-muted mt-2">El avance cuenta solo los puntos asignados con esta categoría, a los alumnos del curso, entre el lunes y el domingo de la semana elegida.</div>
    <?php endif; ?>
  </div>

  <div class="card p-3 shadow-sm mb-3" id="misCategorias">
    <h5 class="mb-1">🏷️ Mis categorías de meta</h5>
    <div class="small text-muted mb-3">Solo tú las ves. Las activas aparecen como motivo al asignar puntos y se pueden elegir al crear una meta.</div>
    <form method="POST" class="row g-2 mb-3">
      <div class="col-md-9"><input type="text" name="nombre_categoria" class="form-control" maxlength="50" placeholder="Ej: Reunión de apoderado" required></div>
      <div class="col-md-3"><button name="crear_categoria" class="btn btn-outline-primary w-100">Agregar categoría</button></div>
    </form>
    <?php if(!$misCats): ?><p class="text-muted text-center mb-0">Aún no tienes categorías.</p><?php endif; ?>
    <?php foreach($misCats as $k): ?>
      <div class="d-flex justify-content-between align-items-center border-top py-2">
        <div><?= h($k['nombre']) ?>
          <span class="badge <?= $k['activa'] ? 'bg-success' : 'bg-secondary' ?> ms-1"><?= $k['activa'] ? 'Activa' : 'Inactiva' ?></span></div>
        <form method="POST" class="m-0">
          <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
          <input type="hidden" name="activa" value="<?= $k['activa'] ? 0 : 1 ?>">
          <button name="toggle_categoria" class="btn btn-sm <?= $k['activa'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"><?= $k['activa'] ? 'Desactivar' : 'Activar' ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card p-3 shadow-sm">
    <h5 class="mb-3">Metas registradas</h5>
    <?php if(!$metas): ?><p class="text-muted text-center">Aún no hay metas creadas.</p><?php endif; ?>
    <?php foreach($metas as $m):
      $inicio = new DateTime($m['semana_inicio']); $fin = (clone $inicio)->modify('+6 days');
      $pct = $m['puntos_objetivo']>0 ? min(100, round($m['avance']/$m['puntos_objetivo']*100)) : 0;
      $cumplida = $m['avance'] >= $m['puntos_objetivo'];
    ?>
    <div class="border-top py-3">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-1">
        <div><strong><?= h($m['curso']) ?></strong>
          <div class="small text-muted"><?= $inicio->format('d/m') ?> — <?= $fin->format('d/m/Y') ?></div>
          <div class="small mt-1">
            <span class="badge bg-secondary">👤 Profesor: <?= $m['profesor'] ? h($m['profesor']) : '—' ?></span>
            <?php if($m['categoria']): ?><span class="badge bg-info text-dark">🏷️ <?= h($m['categoria']) ?><?= $m['categoria_activa'] ? '' : ' (desactivada)' ?></span>
            <?php else: ?><span class="badge bg-light text-muted border">Sin categoría: cuenta todos los puntos</span><?php endif; ?>
            <?php if(!empty($m['descripcion'])): ?><span class="fst-italic">📝 <?= h($m['descripcion']) ?></span><?php endif; ?>
          </div></div>
        <div class="text-end">
          <span class="fw-bold"><?= (int)$m['avance'] ?> / <?= (int)$m['puntos_objetivo'] ?> pts</span>
          <?php if(es_admin() || (int)$m['creado_por']===docente_id()): ?>
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta meta?')">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button name="borrar_meta" class="btn btn-sm btn-outline-danger ms-2">✕</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <div style="background:var(--forest-tint);border-radius:8px;overflow:hidden;height:16px">
        <div style="width:<?= $pct ?>%;background:<?= $cumplida ? 'linear-gradient(90deg,var(--forest-primary-light),var(--forest-primary-dark))' : 'linear-gradient(90deg,var(--forest-warn),var(--forest-accent))' ?>;height:100%;transition:width .3s"></div>
      </div>
      <div class="small text-muted mt-1"><?= $pct ?>%<?= $cumplida ? ' 🎉 ¡Meta cumplida!' : '' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include 'footer.php'; ?>
</body></html>
