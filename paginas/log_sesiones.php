<?php
require_once 'conexion.php'; requiere_login();
if (!es_admin()) { http_response_code(403); die("Solo el administrador puede ver el registro de inicios de sesión."); }

$fu    = trim($_GET['usuario'] ?? '');
$desde = $_GET['desde'] ?? ''; $hasta = $_GET['hasta'] ?? '';
$okf   = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$pag   = max(1, (int)($_GET['p'] ?? 1));
$porPag = 50;

$where = ''; $types = ''; $vals = [];
if ($fu !== '')    { $where .= " AND l.usuario = ?"; $types .= 's'; $vals[] = $fu; }
if ($okf($desde))  { $where .= " AND l.fecha >= ?"; $types .= 's'; $vals[] = $desde . ' 00:00:00'; }
if ($okf($hasta))  { $where .= " AND l.fecha < DATE_ADD(?, INTERVAL 1 DAY)"; $types .= 's'; $vals[] = $hasta; }

// Total para paginar
$s = $conn->prepare("SELECT COUNT(*) n FROM log_sesiones l WHERE 1=1 $where");
if ($vals) $s->bind_param($types, ...$vals);
$s->execute();
$total = (int)$s->get_result()->fetch_assoc()['n'];
$paginas = max(1, (int)ceil($total / $porPag));
$pag = min($pag, $paginas);
$off = ($pag - 1) * $porPag;

$sql = "SELECT l.id, l.usuario, l.fecha, l.ip, l.pais, l.region, l.ciudad, l.user_agent, l.maestro_id,
          NULLIF(TRIM(CONCAT(m.nombre,' ',m.apellido)),'') nombre, m.rol
        FROM log_sesiones l LEFT JOIN maestros m ON m.id = l.maestro_id
        WHERE 1=1 $where ORDER BY l.fecha DESC, l.id DESC LIMIT $porPag OFFSET $off";
$s = $conn->prepare($sql);
if ($vals) $s->bind_param($types, ...$vals);
$s->execute();
$filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);

$usuarios = $conn->query("SELECT DISTINCT usuario FROM log_sesiones ORDER BY usuario")->fetch_all(MYSQLI_ASSOC);

// "Santiago, RM, Chile" a partir de lo guardado (— si Vercel no entregó ubicación).
function texto_ubicacion($f){
  $partes = [];
  if (!empty($f['ciudad'])) $partes[] = $f['ciudad'];
  if (!empty($f['region'])) $partes[] = $f['region'];
  if (!empty($f['pais'])) {
    $partes[] = class_exists('Locale') ? (Locale::getDisplayRegion('-' . $f['pais'], 'es') ?: $f['pais']) : $f['pais'];
  }
  return $partes ? implode(', ', $partes) : '—';
}

// "Chrome · Android" a partir del user agent (el texto completo queda en el tooltip).
function resumen_ua($ua){
  if ($ua === null || $ua === '') return '—';
  $nav = 'Otro';
  foreach (['Edg/'=>'Edge','OPR/'=>'Opera','SamsungBrowser'=>'Samsung','Firefox'=>'Firefox','Chrome'=>'Chrome','CriOS'=>'Chrome','Safari'=>'Safari'] as $k=>$v) {
    if (stripos($ua, $k) !== false) { $nav = $v; break; }
  }
  $so = 'Otro';
  foreach (['Android'=>'Android','iPhone'=>'iPhone','iPad'=>'iPad','Windows'=>'Windows','Mac OS'=>'macOS','Linux'=>'Linux'] as $k=>$v) {
    if (stripos($ua, $k) !== false) { $so = $v; break; }
  }
  return "$nav · $so";
}

$qs = fn($extra = []) => http_build_query(array_filter(array_merge(
  ['usuario'=>$fu, 'desde'=>$desde, 'hasta'=>$hasta], $extra), fn($v) => $v !== '' && $v !== null));
?>
<!DOCTYPE html><html lang="es"><head><title>Inicios de sesión</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <h3 class="mb-3">Inicios de sesión</h3>
  <form method="GET" class="card p-3 shadow-sm mb-3 row g-2 flex-row align-items-end">
    <div class="col-md-4"><label class="form-label small">Usuario</label>
      <select name="usuario" class="form-select"><option value="">Todos los usuarios</option>
        <?php foreach($usuarios as $u): ?><option value="<?= h($u['usuario']) ?>" <?= $fu===$u['usuario']?'selected':'' ?>><?= h($u['usuario']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label small">Desde</label>
      <input type="date" name="desde" class="form-control" value="<?= h($desde) ?>"></div>
    <div class="col-md-3"><label class="form-label small">Hasta</label>
      <input type="date" name="hasta" class="form-control" value="<?= h($hasta) ?>"></div>
    <div class="col-md-2 d-flex gap-1">
      <button class="btn btn-primary flex-fill">Filtrar</button>
      <a href="log_sesiones.php" class="btn btn-outline-secondary" title="Limpiar filtros">✕</a></div>
  </form>

  <div class="card p-3 shadow-sm">
    <div class="small text-muted mb-2"><?= $total ?> inicio<?= $total==1?'':'s' ?> de sesión · horario de Chile</div>
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0">
        <thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Nombre</th><th>Rol</th><th>IP</th><th>Ubicación (aprox.)</th><th>Dispositivo</th></tr></thead>
        <tbody>
        <?php foreach($filas as $f): ?>
          <tr>
            <td><?= h(date('d/m/Y H:i:s', strtotime($f['fecha']))) ?></td>
            <td><strong><?= h($f['usuario']) ?></strong></td>
            <td><?= $f['nombre'] ? h($f['nombre']) : '—' ?></td>
            <td><?php if($f['maestro_id'] === null): ?><span class="badge bg-secondary">Usuario eliminado</span>
                <?php else: ?><span class="badge <?= $f['rol']==='admin'?'bg-warning':'bg-success' ?>"><?= $f['rol']==='admin'?'Administrador':'Docente' ?></span><?php endif; ?></td>
            <td><?= $f['ip'] ? h($f['ip']) : '—' ?></td>
            <td><?= h(texto_ubicacion($f)) ?></td>
            <td title="<?= h($f['user_agent'] ?? '') ?>"><?= h(resumen_ua($f['user_agent'])) ?></td>
          </tr>
        <?php endforeach; if(!$filas) echo "<tr><td colspan='7' class='text-center text-muted'>Sin registros</td></tr>"; ?>
        </tbody>
      </table>
    </div>
    <?php if($paginas > 1): ?>
    <nav class="d-flex justify-content-between align-items-center mt-3">
      <a class="btn btn-sm btn-outline-primary <?= $pag<=1?'disabled':'' ?>" href="?<?= h($qs(['p'=>$pag-1])) ?>">← Anterior</a>
      <span class="small text-muted">Página <?= $pag ?> de <?= $paginas ?></span>
      <a class="btn btn-sm btn-outline-primary <?= $pag>=$paginas?'disabled':'' ?>" href="?<?= h($qs(['p'=>$pag+1])) ?>">Siguiente →</a>
    </nav>
    <?php endif; ?>
  </div>
</div>
<?php include 'footer.php'; ?>
</body></html>
