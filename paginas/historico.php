<?php
require_once 'conexion.php'; requiere_login();
$cursos = $conn->query("SELECT id, nombre FROM cursos ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$asignaturas = $conn->query("SELECT id, nombre FROM asignaturas ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$fc = (int)($_GET['curso'] ?? 0);
$fa = (int)($_GET['alumno'] ?? 0);
$fag = (int)($_GET['asignatura'] ?? 0);
$desde = $_GET['desde'] ?? ''; $hasta = $_GET['hasta'] ?? '';
$okf = fn($d)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$d);

$alumnosSel = $conn->query("SELECT a.id,a.nombre FROM alumnos a ORDER BY a.nombre")->fetch_all(MYSQLI_ASSOC);

$types=''; $vals=[];
$where = '';
if ($fc) { $where .= " AND c.id=?"; $types.='i'; $vals[]=$fc; }
if ($fa) { $where .= " AND a.id=?"; $types.='i'; $vals[]=$fa; }
$sql = "SELECT m.*, a.nombre alumno, c.nombre curso, ag.nombre asignatura, ".sql_nombre_maestro('p')." profesor FROM (
   SELECT 'ganado' tipo, r.id, r.alumno_id, r.fecha, r.puntos delta, k.nombre detalle, r.maestro_id, r.asignatura_id, r.masivo
     FROM registro_puntos r JOIN categorias k ON k.id=r.categoria_id
   UNION ALL
   SELECT 'canje', x.id, x.alumno_id, x.fecha, -x.puntos_virtuales,
     CONCAT(
       CASE WHEN x.opcion_id IS NOT NULL THEN CONCAT('Canje por: ', COALESCE(x.nombre_opcion,'ítem eliminado'))
            ELSE CONCAT('Canje por ', x.puntos_base, ' pt base') END,
       IF(x.observacion<>'', CONCAT(': ', x.observacion), '')
     )
     , x.maestro_id, x.asignatura_id, 0
     FROM canjes x) m
  JOIN alumnos a ON a.id=m.alumno_id JOIN cursos c ON c.id=a.curso_id
  LEFT JOIN asignaturas ag ON ag.id=m.asignatura_id
  LEFT JOIN maestros p ON p.id=m.maestro_id
  WHERE 1=1 $where";
$sql .= " ORDER BY m.alumno_id, m.fecha, m.tipo, m.id";
$s = $conn->prepare($sql); if ($vals) $s->bind_param($types, ...$vals); $s->execute();

$saldo = []; $mov = [];
foreach ($s->get_result() as $r) {
  $saldo[$r['alumno_id']] = ($saldo[$r['alumno_id']] ?? 0) + $r['delta'];
  $r['saldo'] = $saldo[$r['alumno_id']];
  $d = substr($r['fecha'],0,10);
  if ($okf($desde) && $d < $desde) continue;
  if ($okf($hasta) && $d > $hasta) continue;
  // El filtro por asignatura se aplica aquí (y no en SQL) para que el saldo siga siendo el acumulado real del alumno.
  if ($fag && (int)$r['asignatura_id'] !== $fag) continue;
  $mov[] = $r;
}
usort($mov, fn($x,$y)=>[$y['fecha'],$y['id']]<=>[$x['fecha'],$x['id']]);
?>
<!DOCTYPE html><html lang="es"><head><title>Histórico</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <h3 class="mb-3">Histórico de movimientos</h3>
  <form method="GET" class="card p-3 shadow-sm mb-3 row g-2 flex-row">
    <div class="col-md-2"><select name="curso" class="form-select"><option value="0">Todos los cursos</option>
      <?php foreach($cursos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fc==$c['id']?'selected':'' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="asignatura" class="form-select"><option value="0">Todas las asignaturas</option>
      <?php foreach($asignaturas as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $fag==$g['id']?'selected':'' ?>><?= h($g['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="alumno" class="form-select"><option value="0">Todos los alumnos</option>
      <?php foreach($alumnosSel as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $fa==$a['id']?'selected':'' ?>><?= h($a['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><input type="date" name="desde" class="form-control" value="<?= h($desde) ?>"></div>
    <div class="col-md-2"><input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Filtrar</button></div>
  </form>
  <div class="card p-3 shadow-sm table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>Fecha</th><th>Curso</th><th>Asignatura</th><th>Alumno</th><th>Profesor</th><th>Tipo</th><th>Detalle</th><th class="text-center">Puntos</th><th class="text-center">Saldo</th></tr></thead>
      <tbody>
      <?php foreach($mov as $m): ?>
        <tr><td><?= h(date('d/m/Y H:i', strtotime($m['fecha']))) ?></td><td><?= h($m['curso']) ?></td><td><?= $m['asignatura'] ? h($m['asignatura']) : '—' ?></td><td><?= h($m['alumno']) ?></td><td><?= $m['profesor'] ? h($m['profesor']) : '—' ?></td>
          <td><?= $m['tipo']=='ganado' ? '<span class="badge bg-success">Ganado</span>' : '<span class="badge bg-warning text-dark">Canje</span>' ?></td>
          <td><?= h($m['detalle']) ?><?= !empty($m['masivo']) ? ' <span class="badge bg-secondary">👥 Asignación masiva (curso)</span>' : '' ?></td>
          <td class="text-center <?= $m['delta']>0?'text-success':'text-danger' ?>"><strong><?= $m['delta']>0?'+':'' ?><?= (int)$m['delta'] ?></strong></td>
          <td class="text-center"><?= (int)$m['saldo'] ?></td></tr>
      <?php endforeach; if(!$mov) echo "<tr><td colspan='9' class='text-center text-muted'>Sin movimientos</td></tr>"; ?>
      </tbody></table>
  </div>
  <small class="text-muted">El saldo es acumulado por alumno (incluye movimientos anteriores al rango de fechas).</small>
</div><?php include 'footer.php'; ?>
</body></html>
