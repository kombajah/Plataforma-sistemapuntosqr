<?php
require_once 'conexion.php';
requiere_login();
$mensaje = ''; $error = ''; $canje_exitoso = false;

function tasa($conn){ $r=$conn->query("SELECT valor FROM config WHERE clave='tasa_canje'")->fetch_assoc(); return max(1,(int)($r['valor']??10)); }
// Los cursos/alumnos son compartidos por todos los docentes: basta con que el alumno exista.
function alumno_permitido($conn,$id){
  $s=$conn->prepare("SELECT id FROM alumnos WHERE id=?"); $s->bind_param("i",$id); $s->execute();
  return (bool)$s->get_result()->fetch_assoc();
}
function opcion_permitida($conn,$id){
  $s=$conn->prepare("SELECT docente_id, nombre, costo_puntos, activa FROM opciones_canje WHERE id=?"); $s->bind_param("i",$id); $s->execute();
  $r=$s->get_result()->fetch_assoc();
  if (!$r || !$r['activa']) return null;
  if (!es_admin() && (int)$r['docente_id']!==docente_id()) return null;
  return $r;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['guardar_tasa'])) {
    $t = max(1,(int)$_POST['tasa']);
    $s = $conn->prepare("INSERT INTO config (clave,valor) VALUES ('tasa_canje',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $v = (string)$t; $s->bind_param("s",$v); $s->execute(); $mensaje = "Tasa actualizada.";

  } elseif (isset($_POST['canjear'])) {
    $alumno = (int)$_POST['alumno_id']; $base = (int)$_POST['puntos_base'];
    $obs = mb_substr(trim($_POST['observacion'] ?? ''), 0, 200);
    if (!alumno_permitido($conn,$alumno)) $error = "Alumno no válido.";
    elseif ($base < 1 || $base > 100) $error = "Puntos base inválidos (1 a 100).";
    else {
      $tasa = tasa($conn); $costo = $base * $tasa;
      $mid = docente_id(); $asig = asignatura_docente($conn);
      $s = $conn->prepare("INSERT INTO canjes (alumno_id,puntos_virtuales,puntos_base,observacion,maestro_id,asignatura_id,fecha)
        SELECT a.id,?,?,?,?,?,? FROM alumnos a WHERE a.id=?
        AND (COALESCE((SELECT SUM(puntos) FROM registro_puntos WHERE alumno_id=a.id),0)
           - COALESCE((SELECT SUM(puntos_virtuales) FROM canjes WHERE alumno_id=a.id),0)) >= ?");
      $fecha = ahora_chile();
      $s->bind_param("iisiisii", $costo, $base, $obs, $mid, $asig, $fecha, $alumno, $costo); $s->execute();
      if ($s->affected_rows > 0) { $mensaje = "Canje registrado: -$costo pts virtuales → +$base pt(s) base."; $canje_exitoso = true; }
      else $error = "Saldo insuficiente (se necesitan $costo pts virtuales).";
    }

  } elseif (isset($_POST['canjear_opcion'])) {
    $alumno = (int)$_POST['alumno_id_opcion']; $opcionId = (int)$_POST['opcion_id'];
    $obs = mb_substr(trim($_POST['observacion_opcion'] ?? ''), 0, 200);
    $opcion = opcion_permitida($conn, $opcionId);
    if (!alumno_permitido($conn,$alumno)) $error = "Alumno no válido.";
    elseif (!$opcion) $error = "Esa opción de canje ya no está disponible.";
    else {
      $costo = (int)$opcion['costo_puntos'];
      $mid = docente_id(); $asig = asignatura_docente($conn);
      $s = $conn->prepare("INSERT INTO canjes (alumno_id,puntos_virtuales,puntos_base,observacion,opcion_id,nombre_opcion,maestro_id,asignatura_id,fecha)
        SELECT a.id,?,0,?,?,?,?,?,? FROM alumnos a WHERE a.id=?
        AND (COALESCE((SELECT SUM(puntos) FROM registro_puntos WHERE alumno_id=a.id),0)
           - COALESCE((SELECT SUM(puntos_virtuales) FROM canjes WHERE alumno_id=a.id),0)) >= ?");
      $fecha = ahora_chile();
      $s->bind_param("isisiisii", $costo, $obs, $opcionId, $opcion['nombre'], $mid, $asig, $fecha, $alumno, $costo); $s->execute();
      if ($s->affected_rows > 0) { $mensaje = "Canje registrado: -$costo pts virtuales → ".$opcion['nombre']."."; $canje_exitoso = true; }
      else $error = "Saldo insuficiente (se necesitan $costo pts virtuales).";
    }

  } elseif (isset($_POST['crear_opcion'])) {
    $nombre = trim($_POST['nombre_opcion'] ?? ''); $costo = (int)($_POST['costo_opcion'] ?? 0);
    if ($nombre === '' || $costo < 1) $error = "Ingresa un nombre y un costo válido para la opción.";
    else {
      $did = docente_id();
      $s = $conn->prepare("INSERT INTO opciones_canje (docente_id,nombre,costo_puntos) VALUES (?,?,?)");
      $s->bind_param("isi", $did, $nombre, $costo); $s->execute();
      $mensaje = "Opción de canje creada.";
    }
  } elseif (isset($_POST['activar_opcion']) || isset($_POST['desactivar_opcion'])) {
    $oid = (int)$_POST['id']; $activa = isset($_POST['activar_opcion']) ? 1 : 0;
    $s=$conn->prepare("SELECT docente_id FROM opciones_canje WHERE id=?"); $s->bind_param("i",$oid); $s->execute();
    $o=$s->get_result()->fetch_assoc();
    if (!$o || (!es_admin() && (int)$o['docente_id']!==docente_id())) $error = "No tienes permiso sobre esa opción.";
    else { $s=$conn->prepare("UPDATE opciones_canje SET activa=? WHERE id=?"); $s->bind_param("ii",$activa,$oid); $s->execute(); $mensaje="Opción actualizada."; }
  } elseif (isset($_POST['borrar_opcion'])) {
    $oid = (int)$_POST['id'];
    $s=$conn->prepare("SELECT docente_id FROM opciones_canje WHERE id=?"); $s->bind_param("i",$oid); $s->execute();
    $o=$s->get_result()->fetch_assoc();
    if (!$o || (!es_admin() && (int)$o['docente_id']!==docente_id())) $error = "No tienes permiso sobre esa opción.";
    else { $s=$conn->prepare("DELETE FROM opciones_canje WHERE id=?"); $s->bind_param("i",$oid); $s->execute(); $mensaje="Opción eliminada."; }
  }
}

$types=''; $vals=[];
$sqlOp = "SELECT oc.id, oc.nombre, oc.costo_puntos, oc.activa, m.usuario docente FROM opciones_canje oc JOIN maestros m ON m.id=oc.docente_id WHERE 1=1";
filtro_docente($sqlOp,$types,$vals,'oc');
$s=$conn->prepare($sqlOp." ORDER BY oc.activa DESC, oc.nombre"); if($vals) $s->bind_param($types,...$vals); $s->execute();
$opciones = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$opcionesActivas = array_values(array_filter($opciones, fn($o)=>$o['activa']));

$tasa = tasa($conn);
$filtro = (int)($_GET['curso'] ?? 0);
$cursos = $conn->query("SELECT id, nombre FROM cursos ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$types=''; $vals=[];
$sql = "SELECT a.id, a.nombre, c.nombre curso,
  COALESCE((SELECT SUM(puntos) FROM registro_puntos WHERE alumno_id=a.id),0) ganados,
  COALESCE((SELECT SUM(puntos_virtuales) FROM canjes WHERE alumno_id=a.id),0) canjeados,
  COALESCE((SELECT SUM(puntos_base) FROM canjes WHERE alumno_id=a.id),0) base
  FROM alumnos a JOIN cursos c ON a.curso_id=c.id WHERE 1=1";
if ($filtro) { $sql .= " AND c.id=?"; $types.='i'; $vals[]=$filtro; }
$sql .= " ORDER BY c.nombre, a.nombre";
$s = $conn->prepare($sql); if ($vals) $s->bind_param($types,...$vals); $s->execute();
$alumnos = $s->get_result()->fetch_all(MYSQLI_ASSOC);

// Premios canjeados por alumno (se usa nombre_opcion guardado en el canje, así se conserva aunque la opción se elimine)
$premiosPorAlumno = [];
$rp = $conn->query("SELECT alumno_id, nombre_opcion, COUNT(*) n FROM canjes
  WHERE nombre_opcion IS NOT NULL AND nombre_opcion <> ''
  GROUP BY alumno_id, nombre_opcion ORDER BY nombre_opcion");
while ($p = $rp->fetch_assoc()) { $premiosPorAlumno[(int)$p['alumno_id']][] = $p; }
?>
<!DOCTYPE html><html lang="es"><head><title>Canje de puntos</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <?php if($mensaje) echo "<div class='alert alert-success'>".h($mensaje)."</div>"; ?>
  <?php if($error) echo "<div class='alert alert-danger'>".h($error)."</div>"; ?>
  <div class="row">
    <div class="col-md-7 mb-4"><div class="card p-4 shadow-sm">
      <h4 class="text-primary mb-3">Canjear puntos</h4>
      <form method="POST">
        <select name="alumno_id" class="form-select mb-3" required>
          <option value="" disabled selected>Elige un alumno...</option>
          <?php foreach($alumnos as $a): $saldo=$a['ganados']-$a['canjeados']; ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['curso']) ?> — <?= h($a['nombre']) ?> (saldo: <?= $saldo ?>)</option>
          <?php endforeach; ?>
        </select>
        <label class="form-label">Puntos base a otorgar (1 pt base = <?= $tasa ?> pts virtuales)</label>
        <input type="number" name="puntos_base" id="pb" class="form-control mb-1" min="1" max="100" value="1" required oninput="cst()">
        <small class="text-muted d-block mb-3">Costo: <strong id="costo"><?= $tasa ?></strong> pts virtuales</small>
        <input type="text" name="observacion" class="form-control mb-3" maxlength="200" placeholder="Observación (ej: Prueba de Matemática)">
        <button name="canjear" class="btn btn-primary w-100" onclick="return confirm('¿Confirmar canje?')">Confirmar canje</button>
      </form></div></div>
    <div class="col-md-5 mb-4"><div class="card p-4 shadow-sm">
      <h5 class="mb-3">Tasa de canje</h5>
      <form method="POST" class="d-flex gap-2">
        <input type="number" name="tasa" class="form-control" min="1" value="<?= $tasa ?>" required>
        <button name="guardar_tasa" class="btn btn-outline-primary">Guardar</button>
      </form>
      <small class="text-muted mt-2">Tasa global para todos los docentes. No altera canjes pasados.</small>
    </div></div>
  </div>

  <div class="row">
    <div class="col-md-7 mb-4"><div class="card p-4 shadow-sm">
      <h4 class="text-primary mb-3">🎁 Canjear por un premio</h4>
      <?php if(!$opcionesActivas): ?>
        <p class="text-muted">Aún no tienes opciones de canje activas. Crea una a la derecha (ej: "Sticker", "Salir 5 min antes", "Elegir juego libre").</p>
      <?php else: ?>
      <form method="POST">
        <select name="alumno_id_opcion" class="form-select mb-3" required>
          <option value="" disabled selected>Elige un alumno...</option>
          <?php foreach($alumnos as $a): $saldo=$a['ganados']-$a['canjeados']; ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['curso']) ?> — <?= h($a['nombre']) ?> (saldo: <?= $saldo ?>)</option>
          <?php endforeach; ?>
        </select>
        <select name="opcion_id" class="form-select mb-3" required>
          <option value="" disabled selected>Elige un premio/ítem...</option>
          <?php foreach($opcionesActivas as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h($o['nombre']) ?> — <?= (int)$o['costo_puntos'] ?> pts<?= es_admin()?' ('.h($o['docente']).')':'' ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="observacion_opcion" class="form-control mb-3" maxlength="200" placeholder="Observación (opcional)">
        <button name="canjear_opcion" class="btn btn-primary w-100" onclick="return confirm('¿Confirmar canje?')">Confirmar canje</button>
      </form>
      <?php endif; ?>
    </div></div>

    <div class="col-md-5 mb-4"><div class="card p-4 shadow-sm">
      <h5 class="mb-3">Mis opciones de canje</h5>
      <form method="POST" class="d-flex gap-2 mb-3">
        <input type="text" name="nombre_opcion" class="form-control" placeholder="Nombre (ej: Sticker)" maxlength="100" required>
        <input type="number" name="costo_opcion" class="form-control" placeholder="Costo" min="1" style="max-width:100px" required>
        <button name="crear_opcion" class="btn btn-outline-primary">Agregar</button>
      </form>
      <?php foreach($opciones as $o): ?>
        <div class="d-flex justify-content-between align-items-center border-top py-2">
          <div class="<?= $o['activa']?'':'text-muted text-decoration-line-through' ?>">
            <?= h($o['nombre']) ?> <span class="badge bg-light text-dark border"><?= (int)$o['costo_puntos'] ?> pts</span>
            <?php if(es_admin()): ?><span class="badge bg-secondary"><?= h($o['docente']) ?></span><?php endif; ?>
          </div>
          <div class="d-flex gap-1">
            <form method="POST"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button name="<?= $o['activa']?'desactivar_opcion':'activar_opcion' ?>" class="btn btn-sm btn-outline-secondary"><?= $o['activa']?'Desactivar':'Activar' ?></button></form>
            <form method="POST" onsubmit="return confirm('¿Eliminar esta opción?')"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <button name="borrar_opcion" class="btn btn-sm btn-outline-danger">✕</button></form>
          </div>
        </div>
      <?php endforeach; if(!$opciones) echo "<p class='text-muted small mb-0'>Sin opciones creadas todavía.</p>"; ?>
    </div></div>
  </div>

  <div class="card p-3 shadow-sm table-responsive">
    <form method="GET" class="mb-3"><select name="curso" class="form-select w-auto" onchange="this.form.submit()">
      <option value="0">Todos los cursos</option>
      <?php foreach($cursos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $filtro==$c['id']?'selected':'' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
    </select></form>
    <table class="table table-striped align-middle">
      <thead><tr><th>Curso</th><th>Alumno</th><th class="text-center">Ganados</th><th class="text-center">Canjeados</th><th class="text-center">Saldo</th><th class="text-center">Pts base obtenidos</th><th>Premios canjeados</th></tr></thead>
      <tbody>
      <?php foreach($alumnos as $a): ?>
        <tr><td><?= h($a['curso']) ?></td><td><?= h($a['nombre']) ?></td>
          <td class="text-center"><?= (int)$a['ganados'] ?></td><td class="text-center"><?= (int)$a['canjeados'] ?></td>
          <td class="text-center"><strong><?= $a['ganados']-$a['canjeados'] ?></strong></td><td class="text-center"><?= (int)$a['base'] ?></td>
          <td>
            <?php $prem = $premiosPorAlumno[(int)$a['id']] ?? []; ?>
            <?php if (!$prem): ?><span class="text-muted">—</span>
            <?php else: foreach ($prem as $p): ?>
              <span class="badge bg-light text-dark border me-1"><?= h($p['nombre_opcion']) ?><?= $p['n'] > 1 ? ' ×' . (int)$p['n'] : '' ?></span>
            <?php endforeach; endif; ?>
          </td></tr>
      <?php endforeach; ?>
      </tbody></table>
  </div>
</div>
<script>
const T=<?= $tasa ?>;
function cst(){document.getElementById('pb')&&(document.getElementById('costo').innerText=(parseInt(document.getElementById('pb').value)||0)*T);}

// Reproducción de archivo de audio real de caja registradora
function reproducirCajaRegistradora() {
  const audio = new Audio('/sonidos/caja.mp3');
  audio.volume = 0.8;
  audio.play().catch(e => {
    console.log("El navegador bloqueó la reproducción automática o falló la carga: ", e);
  });
}

<?php if ($canje_exitoso): ?>
  document.addEventListener("DOMContentLoaded", () => {
    reproducirCajaRegistradora();
  });
<?php endif; ?>
</script>
<?php include 'footer.php'; ?>
</body></html>