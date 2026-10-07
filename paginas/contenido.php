<?php
require_once 'conexion.php'; requiere_login();
$mensaje='';$error='';
function nuevo_qr(){ return bin2hex(random_bytes(6)); }
// Los cursos son compartidos por todos los docentes: basta con que el curso exista.
function curso_existe($conn,$cid){$s=$conn->prepare("SELECT id FROM cursos WHERE id=?"); $s->bind_param("i",$cid);$s->execute();
  return (bool)$s->get_result()->fetch_assoc();
}
function cupo($conn,$cid,$excluir=0){
  $s=$conn->prepare("SELECT COUNT(*) t FROM alumnos WHERE curso_id=? AND id<>?"); $s->bind_param("ii",$cid,$excluir);$s->execute();
  return $s->get_result()->fetch_assoc()['t'] < 50;
}

// Curso que se muestra desplegado (por URL; las acciones de abajo lo ajustan para no perder el contexto)
$abrirId = (int)($_GET['curso'] ?? 0);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  try {
    if ((isset($_POST['crear_curso']) || isset($_POST['crear_alumno'])) && !es_admin()) {
      // Crear cursos y registrar alumnos es exclusivo del administrador (también se valida en el servidor).
      $error = "Solo el administrador puede crear cursos o registrar alumnos.";

    } elseif (isset($_POST['crear_curso'])) {
      $n = mb_substr(trim($_POST['nombre_curso'] ?? ''), 0, 100);
      if ($n === '') $error = "Escribe el nombre del curso.";
      else {
        $s=$conn->prepare("SELECT id FROM cursos WHERE nombre=?"); $s->bind_param("s",$n); $s->execute();
        if ($s->get_result()->fetch_assoc()) $error = "Ya existe un curso con ese nombre.";
        elseif ($lim = limite_cursos($conn)) $error = $lim;   // límite fijado por el superadmin del portal
        else { $s=$conn->prepare("INSERT INTO cursos (nombre) VALUES (?)"); $s->bind_param("s",$n); $s->execute(); $abrirId=(int)$conn->insert_id; $mensaje = "Curso creado."; }
      }

    } elseif (isset($_POST['crear_alumno'])) {
      $cid=(int)$_POST['curso_id']; $n=trim($_POST['nombre_alumno']);
      $qr=nuevo_qr();$qr_apoderado=nuevo_qr(); // QR generado automáticamente para el apoderado
      $abrirId=$cid;
      if (!curso_existe($conn,$cid))$error = "Curso no válido.";
      elseif (!cupo($conn,$cid))$error = "El curso ya tiene el máximo de 50 alumnos.";
      else { 
        $s=$conn->prepare("INSERT INTO alumnos (curso_id,nombre,qr_code,qr_apoderado) VALUES (?,?,?,?)");
        $s->bind_param("isss",$cid,$n,$qr,$qr_apoderado); $s->execute();$mensaje="Alumno registrado (QRs generados automáticamente)."; 
      }

    } elseif ((isset($_POST['editar_alumno']) || isset($_POST['borrar_alumno'])) && !es_admin()) {
      // Editar o eliminar alumnos es exclusivo del administrador (también se valida en el servidor).
      $error = "Solo el administrador puede editar o eliminar alumnos.";

    } elseif (isset($_POST['editar_alumno'])) {
      $id=(int)$_POST['id']; $cid=(int)$_POST['curso_id']; $n=trim($_POST['nombre_alumno']);
      $s=$conn->prepare("SELECT curso_id FROM alumnos WHERE id=?"); $s->bind_param("i",$id);$s->execute();
      $act=$s->get_result()->fetch_assoc();
      if ($act) $abrirId=(int)$act['curso_id'];
      if (!$act || !curso_existe($conn,$cid))$error = "Alumno o curso no válido.";
      elseif (!cupo($conn,$cid,$id))$error = "El curso destino ya tiene 50 alumnos.";
      else {
        $s=$conn->prepare("UPDATE alumnos SET curso_id=?, nombre=? WHERE id=?"); $s->bind_param("isi",$cid,$n,$id);$s->execute();
        if (!empty($_POST['regen_qr'])) { 
          $q=nuevo_qr();$q_apod=nuevo_qr(); 
          $s=$conn->prepare("UPDATE alumnos SET qr_code=?, qr_apoderado=? WHERE id=?"); 
          $s->bind_param("ssi",$q,$q_apod,$id);$s->execute(); 
        }
        $abrirId=$cid;
        $mensaje = "Alumno actualizado.";
      }

    } elseif (isset($_POST['borrar_alumno'])) {$id=(int)$_POST['id'];$s=$conn->prepare("SELECT curso_id FROM alumnos WHERE id=?"); $s->bind_param("i",$id);$s->execute(); $a=$s->get_result()->fetch_assoc();
      if (!$a)$error = "Alumno no válido.";
      else { $abrirId=(int)$a['curso_id']; $s=$conn->prepare("DELETE FROM alumnos WHERE id=?"); $s->bind_param("i",$id); $s->execute();$mensaje="Alumno eliminado."; }

    } elseif (isset($_POST['borrar_curso'])) {
      $id=(int)$_POST['id'];
      // Los cursos son compartidos y al borrarlos se pierden alumnos y puntos de todos los docentes: solo admin.
      if (!es_admin())$error = "Solo el administrador puede eliminar cursos.";
      elseif (!curso_existe($conn,$id))$error = "Curso no válido.";
      else { $s=$conn->prepare("DELETE FROM cursos WHERE id=?"); $s->bind_param("i",$id); $s->execute();$mensaje="Curso eliminado."; $abrirId=0; }
    }
  } catch (mysqli_sql_exception $e) {$error = "No se pudo guardar. Intenta nuevamente."; }
  if ($mensaje) sync_stats();   // actualiza los contadores que ve el superadmin
}

// --- Listados ---
// Resumen por curso (solo contadores: los alumnos se cargan únicamente del curso que está abierto)
$cursos = $conn->query("SELECT c.*,
    (SELECT COUNT(*) FROM alumnos WHERE curso_id=c.id) tot
  FROM cursos c ORDER BY c.nombre")->fetch_all(MYSQLI_ASSOC);
usort($cursos, fn($x,$y) => strnatcasecmp($x['nombre'], $y['nombre'])); // orden natural: 2° antes que 10°
$totalAlumnos = array_sum(array_column($cursos,'tot'));

// Edición: se mantiene abierto tras un error, se cierra tras guardar
$ed = ($_SERVER["REQUEST_METHOD"]=="POST" && $mensaje) ? 0 : (es_admin() ? (int)($_GET['editar'] ?? 0) : 0);

$q = trim($_GET['q'] ?? ''); if (mb_strlen($q) > 60) $q = mb_substr($q,0,60);

$resultados = []; $hayMas = false; $alumnosAbierto = [];
if ($q !== '') {
  // Modo búsqueda: coincidencias por nombre en todos los cursos (máx. 50)
  $like = '%'.addcslashes($q, '%_\\').'%';
  $s=$conn->prepare("SELECT a.*, c.nombre AS curso_nombre FROM alumnos a JOIN cursos c ON c.id=a.curso_id WHERE a.nombre LIKE ? ORDER BY a.nombre LIMIT 51");
  $s->bind_param("s",$like); $s->execute();
  $resultados = $s->get_result()->fetch_all(MYSQLI_ASSOC);
  if (count($resultados) > 50) { $hayMas = true; array_pop($resultados); }
  $volver = 'q='.rawurlencode($q);
} else {
  // Si se llega solo con ?editar=ID, abrir el curso de ese alumno
  if ($ed && !$abrirId) {
    $s=$conn->prepare("SELECT curso_id FROM alumnos WHERE id=?"); $s->bind_param("i",$ed); $s->execute();
    if ($r=$s->get_result()->fetch_assoc()) $abrirId=(int)$r['curso_id'];
  }
  if ($abrirId) {
    $sql = "SELECT * FROM alumnos WHERE curso_id=? ORDER BY nombre";
    $s=$conn->prepare($sql); $s->bind_param("i",$abrirId); $s->execute();
    $alumnosAbierto = $s->get_result()->fetch_all(MYSQLI_ASSOC);
  }
  $volver = 'curso='.$abrirId;
}

// El panel "Agregar" se mantiene abierto al crear (para registrar varios seguidos), si hay un error o si no hay cursos
$abrirAgregar = es_admin() && (!$cursos || $error || ($_SERVER["REQUEST_METHOD"]=="POST" && (isset($_POST['crear_alumno']) || isset($_POST['crear_curso']))));

// Detectar esquema y dominio base para construir la URL del apoderado
$baseUrl = url_sitio() . 'reporte_apoderado.php?token=';

// Fila de un alumno (normal o en modo edición). $volver conserva el contexto (curso abierto o búsqueda).
function fila_alumno($a, $cursos, $ed, $baseUrl, $volver, $mostrarCurso = false) {
  $id = (int)$a['id'];
  if ($ed === $id) { ?>
  <form method="POST" id="a-<?= $id ?>" class="border rounded p-2 my-2 bg-white">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="text" name="nombre_alumno" class="form-control mb-2" value="<?= h($a['nombre']) ?>" required>
    <select name="curso_id" class="form-select mb-2"><?php foreach($cursos as $c2): ?><option value="<?= (int)$c2['id'] ?>" <?= $c2['id']==$a['curso_id']?'selected':'' ?>><?= h($c2['nombre']) ?></option><?php endforeach; ?></select>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="regen_qr" value="1" id="rq"><label class="form-check-label" for="rq">Generar nuevos QRs (Alumno y Apoderado)</label></div>
    <button name="editar_alumno" class="btn btn-sm btn-primary">Guardar</button> <a href="?<?= h($volver) ?>" class="btn btn-sm btn-light">Cancelar</a>
  </form>
  <?php return; } ?>
  <div id="a-<?= $id ?>" class="d-flex justify-content-between align-items-center border-top py-2 gap-2">
    <div style="min-width:0">
      <?= h($a['nombre']) ?>
      <?php if ($mostrarCurso): ?><a href="?curso=<?= (int)$a['curso_id'] ?>#c-<?= (int)$a['curso_id'] ?>" class="badge bg-light text-dark border text-decoration-none"><?= h($a['curso_nombre'] ?? '') ?></a><?php endif; ?>
    </div>
    <div class="dropdown flex-shrink-0">
      <button class="btn btn-sm btn-outline-secondary px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acciones de <?= h($a['nombre']) ?>">⋯</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><button type="button" class="dropdown-item" data-qr="<?= h($a['qr_code']) ?>" data-titulo="Alumno: <?= h($a['nombre']) ?>" onclick="verQR(this.dataset.qr,this.dataset.titulo)">QR Alumno</button></li>
        <?php if (!empty($a['qr_apoderado'])): ?>
        <li><button type="button" class="dropdown-item" data-qr="<?= h($baseUrl . $a['qr_apoderado']) ?>" data-titulo="Apoderado de: <?= h($a['nombre']) ?>" onclick="verQR(this.dataset.qr,this.dataset.titulo)">QR Apoderado</button></li>
        <li><button type="button" class="dropdown-item" data-token="<?= h($a['qr_apoderado']) ?>" data-nombre="<?= h($a['nombre']) ?>" onclick="verReporte(this.dataset.token,this.dataset.nombre)">📄 Reporte</button></li>
        <?php endif; ?>
        <?php if (es_admin()): ?>
        <li><a class="dropdown-item" href="?<?= h($volver) ?>&amp;editar=<?= $id ?>#a-<?= $id ?>">Editar</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><form method="POST" onsubmit="return confirm('¿Eliminar alumno?')"><input type="hidden" name="id" value="<?= $id ?>"><button name="borrar_alumno" class="dropdown-item text-danger">Eliminar</button></form></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
<?php }
?>
<!DOCTYPE html><html lang="es"><head><title>Contenido</title><?php include 'head.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  .curso-link{color:inherit;border-radius:0}
  .curso-link:hover{background:var(--forest-tint)}
  .curso-link.abierto{background:var(--forest-tint);font-weight:700}
  .curso-link .flecha{width:1.2em;color:var(--forest-primary)}
  .curso-wrap{scroll-margin-top:70px}
  [id^="a-"]{scroll-margin-top:70px}
</style></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <?php if($mensaje) echo "<div class='alert alert-success'>".h($mensaje)."</div>"; ?>
  <?php if($error) echo "<div class='alert alert-danger'>".h($error)."</div>"; ?>

  <!-- Barra superior: resumen, buscador y botón Agregar -->
  <div class="card p-3 shadow-sm mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <h4 class="text-primary mb-0 me-auto">Contenido <small class="text-muted fs-6"><?= count($cursos) ?> cursos · <?= (int)$totalAlumnos ?> alumnos</small></h4>
      <?php if (es_admin()): ?>
      <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#panelAgregar" aria-expanded="<?= $abrirAgregar?'true':'false' ?>">＋ Agregar</button>
      <?php endif; ?>
    </div>
    <form method="GET" action="contenido.php" class="d-flex gap-2 mt-3">
      <input type="search" name="q" class="form-control" placeholder="🔍 Buscar alumno por nombre..." value="<?= h($q) ?>" maxlength="60" aria-label="Buscar alumno">
      <button class="btn btn-primary">Buscar</button>
      <?php if($q !== ''): ?><a href="contenido.php" class="btn btn-light">Limpiar</a><?php endif; ?>
    </form>
  </div>

  <?php if (es_admin()): ?>
  <!-- Panel Agregar curso / Registrar alumno (solo administrador) -->
  <div class="collapse <?= $abrirAgregar?'show':'' ?>" id="panelAgregar">
    <div class="row">
      <div class="col-md-6 mb-3"><div class="card p-4 shadow-sm h-100">
        <h5 class="text-primary mb-3">Agregar Curso</h5>
        <form method="POST" action="contenido.php">
          <input type="text" name="nombre_curso" class="form-control mb-3" required maxlength="100" placeholder="Ej: 1ro Básico A">
          <button name="crear_curso" class="btn btn-primary w-100">Crear Curso</button>
        </form>
        <small class="text-muted d-block mt-2">Los cursos son compartidos por todos los docentes.<?php $mx=(int)(tenant()['max_cursos']??0); ?> Cursos creados: <strong><?= count($cursos) ?></strong> de <strong><?= $mx>0 ? $mx : 'sin límite' ?></strong>.</small>
      </div></div>

      <div class="col-md-6 mb-3"><div class="card p-4 shadow-sm h-100">
        <h5 class="text-primary mb-3">Registrar Alumno</h5>
        <form method="POST" action="contenido.php">
          <select name="curso_id" id="sel_curso" class="form-select mb-3" required>
            <option value="" disabled <?= $abrirId?'':'selected' ?>>Elige un curso...</option>
            <?php foreach($cursos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $c['id']==$abrirId?'selected':'' ?>><?= h($c['nombre']) ?> (<?= (int)$c['tot'] ?>/50)</option><?php endforeach; ?>
          </select>
          <input type="text" name="nombre_alumno" id="inp_nombre" class="form-control mb-1" required placeholder="Nombre del alumno" <?= $cursos?'':'disabled' ?>>
          <small class="text-muted d-block mb-3">Se generarán automáticamente los códigos QR del alumno y del apoderado.</small>
          <button name="crear_alumno" class="btn btn-success w-100" <?= $cursos?'':'disabled' ?>>Guardar Alumno</button>
        </form>
        <?php if(!$cursos): ?><small class="text-danger d-block mt-2">Primero crea un curso.</small><?php endif; ?>
      </div></div>
    </div>
  </div>
  <?php endif; ?>

<?php if ($q !== ''): ?>
  <!-- Resultados de búsqueda -->
  <div class="card p-3 shadow-sm mb-3" id="resultados">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
      <h5 class="mb-0">Resultados para «<?= h($q) ?>» <small class="text-muted">(<?= count($resultados) ?><?= $hayMas?'+':'' ?>)</small></h5>
      <a href="contenido.php" class="btn btn-sm btn-outline-secondary">← Volver a los cursos</a>
    </div>
    <?php if ($hayMas): ?><small class="text-muted">Se muestran los primeros 50. Escribe más letras del nombre para afinar la búsqueda.</small><?php endif; ?>
    <div class="mt-2">
      <?php if (!$resultados): ?><div class="text-muted py-3">No se encontraron alumnos con ese nombre.</div><?php endif; ?>
      <?php foreach($resultados as $a) fila_alumno($a, $cursos, $ed, $baseUrl, $volver, true); ?>
    </div>
  </div>

<?php else: ?>
  <!-- Lista de cursos (plegados; solo se carga el curso abierto) -->
  <div class="card shadow-sm mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center p-3 pb-2">
      <h5 class="mb-0 me-auto">Cursos</h5>
      <?php if (count($cursos) > 8): ?><input type="search" id="fcurso" class="form-control form-control-sm" style="max-width:220px" placeholder="Filtrar cursos..." aria-label="Filtrar cursos"><?php endif; ?>
    </div>
    <?php if (!$cursos): ?><div class="text-muted p-3 pt-0">Aún no hay cursos.<?= es_admin() ? ' Usa «＋ Agregar» para crear el primero.' : ' El administrador debe crearlos.' ?></div><?php endif; ?>

    <?php foreach($cursos as $c):
      $cid = (int)$c['id']; $abierto = ($cid === $abrirId); ?>
    <div class="curso-wrap border-top" data-nombre="<?= h($c['nombre']) ?>">
      <a id="c-<?= $cid ?>" class="curso-link d-flex align-items-center gap-2 px-3 py-2 text-decoration-none <?= $abierto?'abierto':'' ?>"
         href="<?= $abierto ? 'contenido.php#c-'.$cid : '?curso='.$cid.'#c-'.$cid ?>">
        <span class="flecha"><?= $abierto?'▾':'▸' ?></span>
        <span class="flex-grow-1"><?= h($c['nombre']) ?></span>
        <span class="badge bg-light text-dark border"><?= (int)$c['tot'] ?>/50</span>
      </a>

      <?php if ($abierto): ?>
      <div class="px-3 pb-3">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php if(es_admin()): ?><button type="button" class="btn btn-sm btn-success" onclick="agregarEn(<?= $cid ?>)">＋ Registrar alumno</button><?php endif; ?>
          <a href="tarjetas_imprimir.php?curso=<?= $cid ?>" target="_blank" class="btn btn-sm btn-outline-primary">🖨️ Imprimir QRs</a>
          <?php if(es_admin()): ?>
          <form method="POST" action="contenido.php" class="d-inline" onsubmit="return confirm('¿Eliminar curso, sus alumnos y todos sus puntos?')">
            <input type="hidden" name="id" value="<?= $cid ?>"><button name="borrar_curso" class="btn btn-sm btn-outline-danger">Eliminar curso</button></form>
          <?php endif; ?>
        </div>
        <?php if (!$alumnosAbierto): ?>
          <div class="text-muted py-2">Este curso aún no tiene alumnos.</div>
        <?php endif; ?>
        <?php foreach($alumnosAbierto as $a) fila_alumno($a, $cursos, $ed, $baseUrl, $volver); ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="modal fade" id="mqr" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content text-center p-3">
  <h6 id="qrNombre"></h6><div id="qrBox" class="d-flex justify-content-center bg-white p-2"></div>
  <button class="btn btn-light mt-2" data-bs-dismiss="modal">Cerrar</button></div></div></div>

<div class="modal fade" id="mrep" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2">
    <h6 class="modal-title" id="repNombre"></h6>
    <a id="repLink" href="#" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto me-2">Abrir en pestaña nueva</a>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
  </div>
  <div class="modal-body p-0"><iframe id="repFrame" title="Reporte del apoderado" style="width:100%;height:75vh;border:0"></iframe></div>
</div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function verReporte(token, nombre){
  const url = 'reporte_apoderado.php?token=' + encodeURIComponent(token);
  document.getElementById('repNombre').innerText = 'Reporte de ' + nombre;
  document.getElementById('repFrame').src = url;
  document.getElementById('repLink').href = url;
  new bootstrap.Modal(document.getElementById('mrep')).show();
}
document.getElementById('mrep').addEventListener('hidden.bs.modal', () => {
  document.getElementById('repFrame').src = 'about:blank';
});

function verQR(textoQR, titulo){
  document.getElementById('qrNombre').innerText = titulo;
  const b = document.getElementById('qrBox'); b.innerHTML = '';
  new QRCode(b, {text: textoQR, width: 200, height: 200});
  new bootstrap.Modal(document.getElementById('mqr')).show();
}

// "＋ Registrar alumno" dentro de un curso: abre el panel Agregar con ese curso ya elegido
function agregarEn(cursoId){
  const panel = document.getElementById('panelAgregar');
  bootstrap.Collapse.getOrCreateInstance(panel, {toggle:false}).show();
  document.getElementById('sel_curso').value = cursoId;
  panel.scrollIntoView({behavior:'smooth', block:'start'});
  setTimeout(() => document.getElementById('inp_nombre').focus(), 350);
}

// Filtro rápido de cursos (en el navegador, sin recargar)
(function(){
  const f = document.getElementById('fcurso'); if(!f) return;
  const nz = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  f.addEventListener('input', () => {
    const t = nz(f.value.trim());
    document.querySelectorAll('.curso-wrap').forEach(w => { w.style.display = nz(w.dataset.nombre).includes(t) ? '' : 'none'; });
  });
})();
</script><?php include 'footer.php'; ?>
