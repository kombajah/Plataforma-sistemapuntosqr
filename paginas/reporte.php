<?php
require_once 'conexion.php'; requiere_login();
$cursos = $conn->query("SELECT id, nombre FROM cursos ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
usort($cursos, fn($x,$y) => strnatcasecmp($x['nombre'], $y['nombre'])); // orden natural: 2° antes que 10°
// Columnas: categorías base + las de meta del propio profesor (el admin ve además todas las que tienen puntos).
// Los puntos de categorías de meta de otros profesores se suman en la columna "Otras".
if (es_admin()) {
  $cats = $conn->query("SELECT * FROM categorias WHERE docente_id IS NULL OR id IN (SELECT DISTINCT categoria_id FROM registro_puntos) ORDER BY id")->fetch_all(MYSQLI_ASSOC);
} else {
  $uidc = docente_id();
  $sc = $conn->prepare("SELECT * FROM categorias WHERE docente_id IS NULL OR (docente_id=? AND (activa=1 OR id IN (SELECT DISTINCT categoria_id FROM registro_puntos))) ORDER BY id");
  $sc->bind_param("i", $uidc); $sc->execute();
  $cats = $sc->get_result()->fetch_all(MYSQLI_ASSOC);
}
$filtro = (int)($_GET['curso'] ?? 0);

// --- Totales por curso ---
$totalesCurso = $conn->query("SELECT c.id, c.nombre curso,
  COALESCE((SELECT SUM(r.puntos) FROM registro_puntos r JOIN alumnos al ON al.id=r.alumno_id WHERE al.curso_id=c.id),0) total
  FROM cursos c ORDER BY total DESC")->fetch_all(MYSQLI_ASSOC);
usort($totalesCurso, fn($a,$b) => ((int)$b['total'] <=> (int)$a['total']) ?: strnatcasecmp($a['curso'], $b['curso']));

// --- Totales por asignatura: puntos registrados por los profesores de cada asignatura ---
$totalesAsignatura = [];
foreach ($conn->query("SELECT COALESCE(ag.nombre,'Sin asignatura') nombre, SUM(r.puntos) total
  FROM registro_puntos r LEFT JOIN asignaturas ag ON ag.id=r.asignatura_id GROUP BY ag.id, ag.nombre") as $row) {
  $totalesAsignatura[$row['nombre']] = (int)$row['total'];
}
arsort($totalesAsignatura);

// --- Metas de la semana actual (widget) ---
$hoy = new DateTime(); $lunesHoy = (clone $hoy)->modify('monday this week')->format('Y-m-d');
$sqlM = "SELECT mt.puntos_objetivo, mt.descripcion, k.nombre categoria, c.nombre curso, ".sql_nombre_maestro('pm')." profesor,
  ".sql_avance_meta('mt','c')." avance
  FROM metas mt JOIN cursos c ON c.id=mt.curso_id LEFT JOIN maestros pm ON pm.id=mt.creado_por LEFT JOIN categorias k ON k.id=mt.categoria_id
  WHERE mt.semana_inicio = ? ORDER BY c.nombre";
$s=$conn->prepare($sqlM); $s->bind_param("s",$lunesHoy); $s->execute();
$metasSemana = $s->get_result()->fetch_all(MYSQLI_ASSOC);
usort($metasSemana, fn($a,$b) => strnatcasecmp($a['curso'], $b['curso']));

$types=''; $vals=[];
$sql = "SELECT a.id, a.nombre alumno, c.nombre curso, r.categoria_id, SUM(r.puntos) pts
        FROM alumnos a JOIN cursos c ON a.curso_id=c.id
        LEFT JOIN registro_puntos r ON r.alumno_id=a.id WHERE 1=1";
if ($filtro) { $sql .= " AND c.id=?"; $types.='i'; $vals[]=$filtro; }
$sql .= " GROUP BY a.id, a.nombre, c.nombre, r.categoria_id ORDER BY c.nombre, a.nombre";
$s = $conn->prepare($sql); if ($vals) $s->bind_param($types, ...$vals); $s->execute();

$filas = [];
foreach ($s->get_result() as $r) {
  $id = $r['id'];
  $filas[$id] ??= ['alumno'=>$r['alumno'],'curso'=>$r['curso'],'cat'=>[],'total'=>0];
  if ($r['categoria_id']) { $filas[$id]['cat'][$r['categoria_id']] = (int)$r['pts']; $filas[$id]['total'] += (int)$r['pts']; }
}
$idsMostrados = array_column($cats, 'id'); $hayOtras = false;
foreach ($filas as &$f) {
  $suma = 0; foreach ($idsMostrados as $cid) $suma += $f['cat'][$cid] ?? 0;
  $f['otras'] = $f['total'] - $suma; if ($f['otras'] > 0) $hayOtras = true;
} unset($f);
$can=[]; foreach($conn->query('SELECT alumno_id, SUM(puntos_virtuales) t FROM canjes GROUP BY alumno_id') as $q) $can[$q['alumno_id']]=(int)$q['t'];
foreach($filas as $id=>&$f) $f['canje']=$can[$id]??0; unset($f);
uasort($filas, fn($x,$y) => strnatcasecmp($x['curso'], $y['curso']) ?: ($y['total'] <=> $x['total']));
?>
<!DOCTYPE html><html lang="es"><head><title>Reportería</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">

  <?php if($metasSemana): ?>
  <div class="card p-3 shadow-sm mb-3">
    <h5 class="mb-3">🎯 Metas de esta semana</h5>
    <?php foreach($metasSemana as $m): $pct = $m['puntos_objetivo']>0 ? min(100, round($m['avance']/$m['puntos_objetivo']*100)) : 0; $ok = $m['avance']>=$m['puntos_objetivo']; ?>
      <div class="mb-2">
        <div class="d-flex justify-content-between small"><span><?= h($m['curso']) ?></span><span><?= (int)$m['avance'] ?> / <?= (int)$m['puntos_objetivo'] ?> pts</span></div>
        <div class="small">
          <span class="badge bg-secondary">👤 Profesor: <?= $m['profesor'] ? h($m['profesor']) : '—' ?></span>
          <?php if(!empty($m['categoria'])): ?><span class="badge bg-info text-dark">🏷️ <?= h($m['categoria']) ?></span><?php endif; ?>
          <?php if(!empty($m['descripcion'])): ?><span class="text-muted fst-italic">📝 <?= h($m['descripcion']) ?></span><?php endif; ?>
        </div>
        <div style="background:var(--forest-tint);border-radius:8px;overflow:hidden;height:12px">
          <div style="width:<?= $pct ?>%;background:<?= $ok?'linear-gradient(90deg,var(--forest-primary-light),var(--forest-primary-dark))':'linear-gradient(90deg,var(--forest-warn),var(--forest-accent))' ?>;height:100%"></div>
        </div>
      </div>
    <?php endforeach; ?>
    <a href="metas.php" class="small">Gestionar metas →</a>
  </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-md-6 mb-3"><div class="card p-3 shadow-sm h-100">
      <h5 class="mb-3">Puntos totales por curso</h5>
      <?php $max = max(array_column($totalesCurso,'total') ?: [0]) ?: 1; ?>
      <?php foreach($totalesCurso as $row): $pct = round($row['total']/$max*100); ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small"><span><?= h($row['curso']) ?></span><span><?= (int)$row['total'] ?> pts</span></div>
          <div style="background:var(--forest-tint);border-radius:8px;overflow:hidden;height:12px"><div style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--forest-primary-light),var(--forest-primary));height:100%"></div></div>
        </div>
      <?php endforeach; if(!$totalesCurso) echo "<p class='text-muted small mb-0'>Sin datos aún.</p>"; ?>
    </div></div>
    <div class="col-md-6 mb-3"><div class="card p-3 shadow-sm h-100">
      <h5 class="mb-3">Puntos totales por asignatura</h5>
      <?php $maxAg = $totalesAsignatura ? max($totalesAsignatura) : 1; ?>
      <?php foreach($totalesAsignatura as $nombreAg=>$total): $pct = round($total/$maxAg*100); ?>
        <div class="mb-2">
          <div class="d-flex justify-content-between small"><span><?= h($nombreAg) ?></span><span><?= (int)$total ?> pts</span></div>
          <div style="background:var(--forest-tint);border-radius:8px;overflow:hidden;height:12px"><div style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--forest-accent),var(--forest-warn));height:100%"></div></div>
        </div>
      <?php endforeach; if(!$totalesAsignatura) echo "<p class='text-muted small mb-0'>Sin datos aún.</p>"; ?>
    </div></div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h3 class="mb-0">Dashboard de Puntos</h3>
    <form method="GET"><select name="curso" class="form-select" onchange="this.form.submit()">
      <option value="0">Todos los cursos</option>
      <?php foreach($cursos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $filtro==$c['id']?'selected':'' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
    </select></form>
  </div>
  <div class="card p-3 shadow-sm table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th class="text-nowrap">Curso</th><th style="min-width:220px">Alumno</th>
        <?php foreach($cats as $k): ?><th class="text-center"><?= h($k['nombre']) ?></th><?php endforeach; ?>
        <?php if($hayOtras): ?><th class="text-center">Otras</th><?php endif; ?>
        <th class="text-center">Total</th><th class="text-center">Canjeado</th><th class="text-center">Saldo</th></tr></thead>
      <tbody>
      <?php foreach($filas as $f): ?>
        <tr><td class="text-nowrap"><?= h($f['curso']) ?></td><td><?= h($f['alumno']) ?></td>
          <?php foreach($cats as $k): ?><td class="text-center"><?= $f['cat'][$k['id']] ?? 0 ?></td><?php endforeach; ?>
          <?php if($hayOtras): ?><td class="text-center"><?= (int)$f['otras'] ?></td><?php endif; ?>
          <td class="text-center"><strong><?= $f['total'] ?> pts</strong></td>
          <td class="text-center"><?= $f['canje'] ?></td>
          <td class="text-center"><strong><?= $f['total']-$f['canje'] ?></strong></td></tr>
      <?php endforeach; if(!$filas) echo "<tr><td colspan='".(5+count($cats)+($hayOtras?1:0))."' class='text-center text-muted'>Sin datos</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div><?php include 'footer.php'; ?>
</body></html>
