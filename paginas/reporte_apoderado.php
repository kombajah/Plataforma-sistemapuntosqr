<?php
require_once 'conexion.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
  die("Acceso denegado: Token no proporcionado.");
}

function pts(int $n): string {
  return $n . ($n === 1 || $n === -1 ? ' pt' : ' pts');
}

// 1. Información general del alumno
$stmt = $conn->prepare("
  SELECT a.id, a.curso_id, a.nombre AS alumno_nombre, c.nombre AS curso_nombre
  FROM alumnos a
  JOIN cursos c ON c.id = a.curso_id
  WHERE a.qr_apoderado = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$alumno = $stmt->get_result()->fetch_assoc();

if (!$alumno) {
  die("Acceso denegado: Token inválido.");
}

$alumno_id = (int)$alumno['id'];
$curso_id  = (int)$alumno['curso_id'];

// 2. Puntos por categoría
$stmtCat = $conn->prepare("
  SELECT cat.nombre AS categoria, COALESCE(SUM(p.puntos), 0) AS total_puntos
  FROM categorias cat
  LEFT JOIN registro_puntos p ON p.categoria_id = cat.id AND p.alumno_id = ?
  GROUP BY cat.id, cat.nombre, cat.docente_id
  HAVING cat.docente_id IS NULL OR COALESCE(SUM(p.puntos), 0) > 0  -- las categorías de meta solo si el alumno tiene puntos
  ORDER BY cat.nombre ASC
");
$stmtCat->bind_param("i", $alumno_id);
$stmtCat->execute();
$reporteCategorias = $stmtCat->get_result()->fetch_all(MYSQLI_ASSOC);

$totalGeneral = (int)array_sum(array_column($reporteCategorias, 'total_puntos'));

// 3. Puntos por asignatura (de mayor a menor)
$stmtAsg = $conn->prepare("
  SELECT asg.nombre AS asignatura, COALESCE(SUM(p.puntos), 0) AS total_puntos
  FROM asignaturas asg
  LEFT JOIN registro_puntos p ON p.asignatura_id = asg.id AND p.alumno_id = ?
  GROUP BY asg.id, asg.nombre
  ORDER BY total_puntos DESC, asg.nombre ASC
");
$stmtAsg->bind_param("i", $alumno_id);
$stmtAsg->execute();
$reporteAsignaturas = $stmtAsg->get_result()->fetch_all(MYSQLI_ASSOC);

$maxAsg = 0;
foreach ($reporteAsignaturas as $r) { $maxAsg = max($maxAsg, (int)$r['total_puntos']); }
$asgDestacada = ($maxAsg > 0) ? $reporteAsignaturas[0]['asignatura'] : null;

// 4. Tendencia: últimos 7 días vs. los 7 anteriores
$stmtSem = $conn->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN puntos END), 0) AS semana_actual,
    COALESCE(SUM(CASE WHEN fecha <  DATE_SUB(NOW(), INTERVAL 7 DAY)
                       AND fecha >= DATE_SUB(NOW(), INTERVAL 14 DAY) THEN puntos END), 0) AS semana_anterior
  FROM registro_puntos
  WHERE alumno_id = ?
");
$stmtSem->bind_param("i", $alumno_id);
$stmtSem->execute();
$sem = $stmtSem->get_result()->fetch_assoc();
$semActual   = (int)$sem['semana_actual'];
$semAnterior = (int)$sem['semana_anterior'];
$delta       = $semActual - $semAnterior;

// 5. Comparación con el promedio del curso
$stmtCurso = $conn->prepare("
  SELECT COALESCE(SUM(p.puntos), 0) AS total
  FROM alumnos a
  LEFT JOIN registro_puntos p ON p.alumno_id = a.id
  WHERE a.curso_id = ?
  GROUP BY a.id
");
$stmtCurso->bind_param("i", $curso_id);
$stmtCurso->execute();
$totalesCurso = array_map('intval', array_column($stmtCurso->get_result()->fetch_all(MYSQLI_ASSOC), 'total'));
$promedioCurso = count($totalesCurso) ? round(array_sum($totalesCurso) / count($totalesCurso)) : 0;
$difCurso = $totalGeneral - $promedioCurso;

// 6. Últimos 5 registros
$stmtRec = $conn->prepare("
  SELECT p.puntos, p.fecha, cat.nombre AS categoria, asg.nombre AS asignatura
  FROM registro_puntos p
  JOIN categorias cat ON cat.id = p.categoria_id
  LEFT JOIN asignaturas asg ON asg.id = p.asignatura_id
  WHERE p.alumno_id = ?
  ORDER BY p.fecha DESC, p.id DESC
  LIMIT 5
");
$stmtRec->bind_param("i", $alumno_id);
$stmtRec->execute();
$recientes = $stmtRec->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reporte de Alumno - <?= h(cfg('nombre_colegio')) ?></title>
  <link rel="icon" href="<?= h(img_url('icono')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style><?= css_tema($GLOBALS['CFG']) ?>
  body.bg-light{background:var(--forest-bg)!important;color:var(--forest-text)}
  .bg-primary{background:linear-gradient(135deg,var(--forest-primary-light),var(--forest-primary))!important;color:var(--on-primary)!important}
  .bg-primary .text-white-50{color:var(--on-primary)!important;opacity:.8}</style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 600px;">

  <div class="text-center mb-3">
    <img src="<?= h(img_url('logo')) ?>" alt="" style="width:56px;height:56px;object-fit:contain;border-radius:50%;background:#fff;padding:4px;box-shadow:0 2px 8px #0002">
    <div class="fw-bold mt-1" style="color:var(--forest-primary-dark)"><?= h(cfg('nombre_colegio')) ?></div>
  </div>

  <!-- Encabezado + total -->
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body text-center bg-primary text-white rounded-top">
      <h4 class="mb-1"><?= htmlspecialchars($alumno['alumno_nombre']) ?></h4>
      <p class="mb-0 text-white-50"><?= htmlspecialchars($alumno['curso_nombre']) ?></p>
    </div>
    <div class="card-body text-center border-bottom">
      <span class="text-muted d-block text-uppercase fw-bold small">Puntaje Total</span>
      <h1 class="display-4 fw-bold text-success mb-0"><?= $totalGeneral ?></h1>
    </div>
  </div>

  <!-- Indicadores -->
  <div class="row g-2 mb-3">
    <div class="col-4">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body p-2">
          <div class="small text-muted">Últimos 7 días</div>
          <div class="fs-4 fw-bold"><?= $semActual ?></div>
          <div class="small fw-semibold <?= $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : 'text-muted') ?>">
            <?= $delta > 0 ? '▲ +' . $delta : ($delta < 0 ? '▼ ' . $delta : '= igual') ?>
          </div>
          <div class="text-muted" style="font-size: .7rem;">vs. semana previa</div>
        </div>
      </div>
    </div>
    <div class="col-4">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body p-2">
          <div class="small text-muted">Promedio del curso</div>
          <div class="fs-4 fw-bold"><?= $promedioCurso ?></div>
          <div class="small fw-semibold <?= $difCurso >= 0 ? 'text-success' : 'text-secondary' ?>">
            <?= $difCurso > 0 ? '+' . $difCurso . ' sobre el promedio' : ($difCurso < 0 ? abs($difCurso) . ' bajo el promedio' : 'En el promedio') ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-4">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body p-2">
          <div class="small text-muted">Asignatura destacada</div>
          <div class="fs-6 fw-bold mt-2"><?= $asgDestacada ? htmlspecialchars($asgDestacada) : '—' ?></div>
          <?php if ($asgDestacada): ?>
            <div class="small text-success fw-semibold"><?= pts($maxAsg) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Por asignatura -->
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white py-3">
      <h5 class="mb-0 text-primary">Puntos por Asignatura</h5>
    </div>
    <ul class="list-group list-group-flush">
      <?php if (empty($reporteAsignaturas)): ?>
        <li class="list-group-item text-center text-muted py-3">No hay asignaturas registradas.</li>
      <?php else: ?>
        <?php foreach ($reporteAsignaturas as $a):
          $v = (int)$a['total_puntos'];
          $pct = $maxAsg > 0 ? max(0, round($v / $maxAsg * 100)) : 0; ?>
          <li class="list-group-item py-3">
            <div class="d-flex justify-content-between mb-1">
              <span class="fw-semibold"><?= htmlspecialchars($a['asignatura']) ?></span>
              <span class="fw-bold text-primary"><?= pts($v) ?></span>
            </div>
            <div class="progress" style="height: 6px;" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar" style="width: <?= $pct ?>%"></div>
            </div>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Por categoría -->
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white py-3">
      <h5 class="mb-0 text-primary">Detalle por Categoría</h5>
    </div>
    <ul class="list-group list-group-flush">
      <?php if (empty($reporteCategorias)): ?>
        <li class="list-group-item text-center text-muted py-3">No hay categorías registradas.</li>
      <?php else: ?>
        <?php foreach ($reporteCategorias as $cat): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-3">
            <span class="fw-semibold"><?= htmlspecialchars($cat['categoria']) ?></span>
            <span class="badge bg-primary rounded-pill fs-6"><?= pts((int)$cat['total_puntos']) ?></span>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Actividad reciente -->
  <div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3">
      <h5 class="mb-0 text-primary">Actividad Reciente</h5>
    </div>
    <ul class="list-group list-group-flush">
      <?php if (empty($recientes)): ?>
        <li class="list-group-item text-center text-muted py-3">Aún no hay registros.</li>
      <?php else: ?>
        <?php foreach ($recientes as $r): $v = (int)$r['puntos']; ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-3">
            <div>
              <div class="fw-semibold"><?= htmlspecialchars($r['categoria']) ?></div>
              <div class="small text-muted">
                <?= date('d/m/Y', strtotime($r['fecha'])) ?><?= $r['asignatura'] ? ' · ' . htmlspecialchars($r['asignatura']) : '' ?>
              </div>
            </div>
            <span class="fw-bold <?= $v >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($v > 0 ? '+' : '') . $v ?></span>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>

</div>
</body>
</html>
