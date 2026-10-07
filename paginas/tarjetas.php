<?php
require_once 'conexion.php'; requiere_login();

$cursos = $conn->query("SELECT id, nombre FROM cursos ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$fc  = (int)($_GET['curso'] ?? 0);
$fal = trim($_GET['alumno'] ?? '');

$types=''; $vals=[];
$sql = "SELECT al.id, al.nombre, al.qr_code, al.qr_apoderado, c.nombre curso
        FROM alumnos al JOIN cursos c ON c.id=al.curso_id
        WHERE 1=1";
if ($fc)  { $sql.=" AND c.id=?";  $types.='i'; $vals[]=$fc; }
if ($fal !== '') { $sql.=" AND al.nombre LIKE ?"; $types.='s'; $vals[]='%'.$fal.'%'; }
$sql.=" ORDER BY c.nombre, al.nombre";
$s=$conn->prepare($sql); if($vals) $s->bind_param($types,...$vals); $s->execute();
$alumnos = $s->get_result()->fetch_all(MYSQLI_ASSOC);

$qs = http_build_query(array_filter(['curso'=>$fc,'alumno'=>$fal]));
?>
<!DOCTYPE html><html lang="es"><head><title>Tarjetas de alumnos</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <div class="card p-3 shadow-sm mb-3">
    <h4 class="text-primary mb-3">Tarjetas de alumnos (QR)</h4>
    <form method="GET" class="row g-2">
      <div class="col-md-4"><select name="curso" class="form-select"><option value="0">Todos los cursos</option>
        <?php foreach($cursos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fc==$c['id']?'selected':'' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-md-4"><input type="text" name="alumno" class="form-control" placeholder="Buscar alumno..." value="<?= h($fal) ?>"></div>
      <div class="col-12"><button class="btn btn-primary">Filtrar</button>
        <?php if($alumnos): ?>
        <a class="btn btn-outline-primary" target="_blank" href="tarjetas_imprimir.php?<?= $qs ?>">🖨️ Imprimir tarjetas de alumno (<?= count($alumnos) ?>)</a>
        <a class="btn btn-outline-warning text-dark" target="_blank" href="tarjetas_imprimir.php?tipo=apoderado&<?= $qs ?>">👪 Imprimir tarjetas de apoderado (<?= count($alumnos) ?>)</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card p-3 shadow-sm table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>Alumno</th><th>Curso</th><th class="text-center">QR Alumno</th><th class="text-center">QR Apoderado</th></tr></thead>
      <tbody>
      <?php foreach($alumnos as $a): ?>
        <tr><td><?= h($a['nombre']) ?></td><td><?= h($a['curso']) ?></td>
          <td class="text-center"><span class="badge bg-primary">✓</span></td>
          <td class="text-center"><span class="badge <?= $a['qr_apoderado'] ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $a['qr_apoderado'] ? '✓' : '—' ?></span></td></tr>
      <?php endforeach; if(!$alumnos) echo "<tr><td colspan='4' class='text-center text-muted'>Sin resultados para este filtro</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include 'footer.php'; ?>
</body></html>
