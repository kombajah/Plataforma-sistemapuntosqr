<?php
require_once 'conexion.php'; requiere_login();
if (!es_admin()) { http_response_code(403); die("Solo el administrador puede gestionar maestros."); }
$mensaje=''; $error='';
$ed  = (int)($_GET['editar'] ?? 0);       // maestro en edición
$eda = (int)($_GET['editar_asig'] ?? 0);  // asignatura en edición

function asignatura_existe($conn,$id){
  if ($id <= 0) return false;
  $s=$conn->prepare("SELECT id FROM asignaturas WHERE id=?"); $s->bind_param("i",$id); $s->execute();
  return (bool)$s->get_result()->fetch_assoc();
}
function nombre_asignatura_repetido($conn,$nombre,$excluir=0){
  $s=$conn->prepare("SELECT id FROM asignaturas WHERE nombre=? AND id<>?"); $s->bind_param("si",$nombre,$excluir); $s->execute();
  return (bool)$s->get_result()->fetch_assoc();
}

// ---------- TARJETAS DE ACCESO DE DOCENTES (vista de impresión) ----------
// ?tarjetas=todos  -> todos los docentes   |   ?tarjetas=ID -> un docente en particular
if (isset($_GET['tarjetas'])) {
  $sel = $_GET['tarjetas'];
  $sql = "SELECT id, nombre, apellido, usuario, clave_texto FROM maestros WHERE rol='docente'";
  if ($sel === 'todos') { $s = $conn->prepare($sql." ORDER BY apellido, nombre, usuario"); }
  else { $sid = (int)$sel; $s = $conn->prepare($sql." AND id=?"); $s->bind_param("i", $sid); }
  $s->execute();
  $docs = $s->get_result()->fetch_all(MYSQLI_ASSOC);
  $conClave = array_values(array_filter($docs, fn($d) => (string)$d['clave_texto'] !== ''));
  $sinClave = array_values(array_filter($docs, fn($d) => (string)$d['clave_texto'] === ''));

  // La tarjeta lleva el QR de acceso al sistema (misma URL del sitio desde donde se genera)
  $urlSitio = url_sitio();
  $mascota = img_url('mascota');
  ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Tarjetas de acceso docentes</title>
<style><?= css_tema($GLOBALS['CFG']) ?></style>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
@page{size:A4;margin:8mm}
*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Poppins','Quicksand',system-ui,sans-serif;background:var(--forest-tint);color:#222;margin:16px}
.bar{margin-bottom:14px;font-size:13px}
.bar a,.bar button{display:inline-block;margin:2px 4px 2px 0;padding:5px 10px;border:1px solid var(--forest-primary);border-radius:6px;background:#fff;color:var(--forest-text);text-decoration:none;font-size:13px;cursor:pointer}
.aviso{background:#fff3cd;border:1px solid #ffe08a;border-radius:6px;padding:8px 12px;margin:8px 0;font-size:13px}

.pagina{display:grid;grid-template-columns:190mm;gap:5mm;justify-content:center;margin-bottom:6mm;page-break-after:always;break-after:page}
.pagina:last-child{page-break-after:auto;break-after:auto}

.tarjeta{display:flex;width:190mm;height:58mm;background:#fff;border:.3mm dashed #9aa79a;border-radius:4mm;overflow:hidden;break-inside:avoid}
.izq{position:relative;width:50%;background:linear-gradient(135deg,var(--forest-tint),var(--forest-tint));border-right:.3mm dashed #9aa79a;display:flex;align-items:flex-end}
.izq .marca{position:absolute;left:5mm;top:5mm;display:flex;align-items:center;gap:2mm;font-weight:700;font-size:7pt;letter-spacing:.3mm;color:var(--forest-primary-dark)}
.izq .marca img{width:7mm;height:7mm;object-fit:contain}
.izq .mascota{height:50mm;width:36mm;object-fit:contain;margin-left:2mm}
.izq .txt{position:absolute;left:41mm;top:12mm;right:3mm}
.izq .hola{font-size:9pt;color:var(--forest-text)}
.izq .nombre{font-size:21pt;font-weight:800;color:var(--forest-primary-dark);line-height:1.05}
.izq .sub{font-size:7.5pt;color:#6b786a;margin-top:2mm;line-height:1.3}
.izq .pill{display:inline-block;margin-top:3.5mm;border:.3mm solid var(--forest-primary);background:#fff;color:var(--forest-primary-dark);border-radius:5mm;padding:1.2mm 3mm;font-size:6.5pt;font-weight:700}

.der{position:relative;width:50%;padding:4.5mm 5mm 3mm 5mm;display:flex;flex-direction:column}
.der .top{display:flex;justify-content:space-between;align-items:center}
.der .tag{background:var(--forest-tint);color:var(--forest-primary-dark);font-size:5.8pt;font-weight:800;letter-spacing:.3mm;border-radius:3mm;padding:1mm 2.5mm}
.der .esc{font-size:7pt;color:#6b6b6b}
.der .medio{flex:1;display:flex;align-items:center;gap:3.5mm}
.der .logo{width:15mm;height:15mm;border:.3mm solid #e1e1e1;border-radius:2.5mm;padding:1.2mm;object-fit:contain;flex:none}
.der .datos{flex:1;min-width:0}
.der .datos small{display:block;font-size:4.6pt;font-weight:700;letter-spacing:.3mm;color:#777;margin-top:1.3mm}
.der .datos .doc{font-size:11pt;font-weight:700;line-height:1.1;color:#1a1a1a}
.der .datos .usr{font-family:'Courier New',monospace;font-size:10pt;font-weight:700;color:var(--forest-primary-dark)}
.der .datos .clv{font-family:'Courier New',monospace;font-size:10pt;font-weight:700;color:#d9531e;word-break:break-all}
.der .qrbox{text-align:center;flex:none}
.der .qrbox .qr{border:.3mm solid #e1e1e1;border-radius:2.5mm;padding:1.2mm;line-height:0;display:inline-block}
.der .qrbox .qr img,.der .qrbox .qr canvas{width:19mm!important;height:19mm!important}
.der .qrbox small{display:block;font-size:4.6pt;color:#888;margin-top:.8mm;line-height:1.15}
.der .pie{border-top:.3mm solid #e3e3e3;text-align:center;padding-top:1.5mm}
.der .pie .url{font-size:6pt;color:#555}
.der .pie .nota{font-size:4.8pt;color:#999;margin-top:.5mm}

@media print{.np{display:none!important}body{margin:0;background:#fff}}
</style></head><body>

<div class="bar np">
  <b>Tarjetas de acceso docentes (<?= count($conClave) ?>)</b> &nbsp;
  <button onclick="print()">🖨️ Imprimir / Guardar como PDF</button>
  <a href="maestros.php">← Volver a Maestros</a>
  <br><small>Hoja A4 con 4 tarjetas. Imprime al 100 % (sin “ajustar a página”).</small>
  <?php if($sinClave): ?>
  <div class="aviso">⚠️ No se generó tarjeta para <?= count($sinClave) ?> docente(s) porque no tienen clave guardada:
    <?= h(implode(', ', array_map(fn($d) => trim($d['nombre'].' '.$d['apellido']) ?: $d['usuario'], $sinClave))) ?>.
    Asígnales una nueva contraseña desde «Maestros registrados» (campo «Nueva contraseña») y vuelve a generar.</div>
  <?php endif; ?>
</div>

<?php
if (!$conClave && !$sinClave) echo "<p>No hay docentes para generar tarjetas.</p>";
$idx = 0;
foreach (array_chunk($conClave, 4) as $grupo): ?>
<div class="pagina">
  <?php foreach ($grupo as $d): $nom = trim($d['nombre'].' '.$d['apellido']) ?: $d['usuario']; ?>
  <div class="tarjeta">
    <div class="izq">
      <div class="marca"><img src="<?= h(img_url('logo')) ?>" alt=""><?= h(mb_strtoupper(cfg('nombre_colegio'))) ?></div>
      <img class="mascota" src="<?= h($mascota) ?>" alt="">
      <div class="txt">
        <div class="hola">¡Hola! Soy</div>
        <div class="nombre"><?= h(cfg('nombre_chatbot', 'Asistente')) ?></div>
        <div class="sub">Sistema de Puntos<br><?= h(cfg('nombre_colegio')) ?></div>
        <span class="pill">Tarjeta de acceso del docente</span>
      </div>
    </div>
    <div class="der">
      <div class="top"><span class="tag">ACCESO DOCENTE</span><span class="esc"><?= h(cfg('nombre_colegio')) ?></span></div>
      <div class="medio">
        <img class="logo" src="<?= h(img_url('logo')) ?>" alt="">
        <div class="datos">
          <small>DOCENTE</small><div class="doc"><?= h($nom) ?></div>
          <small>USUARIO</small><div class="usr"><?= h($d['usuario']) ?></div>
          <small>CLAVE</small><div class="clv"><?= h($d['clave_texto']) ?></div>
        </div>
        <div class="qrbox"><div class="qr"><div id="q<?= $idx ?>"></div></div><small>Escanea para<br>ingresar</small></div>
      </div>
      <div class="pie"><div class="url"><?= h($urlSitio) ?></div><div class="nota">Tarjeta personal. Guárdala en un lugar seguro y no compartas tu clave.</div></div>
    </div>
  </div>
  <?php $idx++; endforeach; ?>
</div>
<?php endforeach; ?>

<script>
const URL_SITIO = <?= json_encode($urlSitio, JSON_HEX_TAG) ?>;
for (let i = 0; i < <?= (int)$idx ?>; i++) {
  const el = document.getElementById('q' + i);
  if (el) new QRCode(el, {text: URL_SITIO, width: 128, height: 128, correctLevel: QRCode.CorrectLevel.M});
}
</script>
</body></html>
<?php
  exit;
}

if ($_SERVER["REQUEST_METHOD"]=="POST") {
  try {
    // ---------- MANTENEDOR DE ASIGNATURAS (movido desde Contenido) ----------
    if (isset($_POST['crear_asignatura'])) {
      $n = mb_substr(trim($_POST['nombre_asignatura'] ?? ''), 0, 100);
      if ($n === '') $error = "Escribe el nombre de la asignatura.";
      elseif (nombre_asignatura_repetido($conn,$n)) $error = "Esa asignatura ya existe.";
      else { $s=$conn->prepare("INSERT INTO asignaturas (nombre) VALUES (?)"); $s->bind_param("s",$n); $s->execute(); $mensaje = "Asignatura creada."; }

    } elseif (isset($_POST['editar_asignatura'])) {
      $id=(int)$_POST['id']; $n = mb_substr(trim($_POST['nombre_asignatura'] ?? ''), 0, 100);
      if ($n === '') $error = "Escribe el nombre de la asignatura.";
      elseif (!asignatura_existe($conn,$id)) $error = "Asignatura no válida.";
      elseif (nombre_asignatura_repetido($conn,$n,$id)) $error = "Ya existe otra asignatura con ese nombre.";
      else { $s=$conn->prepare("UPDATE asignaturas SET nombre=? WHERE id=?"); $s->bind_param("si",$n,$id); $s->execute(); $mensaje = "Asignatura actualizada."; $eda = 0; }

    } elseif (isset($_POST['borrar_asignatura'])) {
      $id=(int)$_POST['id'];
      $s=$conn->prepare("SELECT
          (SELECT COUNT(*) FROM maestros WHERE asignatura_id=?) maestros,
          (SELECT COUNT(*) FROM registro_puntos WHERE asignatura_id=?) puntos,
          (SELECT COUNT(*) FROM canjes WHERE asignatura_id=?) canjes");
      $s->bind_param("iii",$id,$id,$id); $s->execute(); $u=$s->get_result()->fetch_assoc();
      if (!asignatura_existe($conn,$id)) $error = "Asignatura no válida.";
      elseif ((int)$u['maestros'] > 0) $error = "No se puede eliminar: hay ".(int)$u['maestros']." maestro(s) con esta asignatura. Cámbialos de asignatura primero.";
      elseif ((int)$u['puntos'] + (int)$u['canjes'] > 0) $error = "No se puede eliminar: tiene movimientos en el histórico. Puedes renombrarla en su lugar.";
      else { $s=$conn->prepare("DELETE FROM asignaturas WHERE id=?"); $s->bind_param("i",$id); $s->execute(); $mensaje = "Asignatura eliminada."; }

    // ---------- MAESTROS ----------
    } elseif (isset($_POST['crear'])) {
      $nom = mb_substr(trim($_POST['nombre'] ?? ''), 0, 100);
      $ape = mb_substr(trim($_POST['apellido'] ?? ''), 0, 100);
      $u   = trim($_POST['usuario'] ?? ''); $p = $_POST['password'] ?? '';
      $rol = ($_POST['rol'] ?? '')==='admin' ? 'admin' : 'docente';
      $aid = (int)($_POST['asignatura_id'] ?? 0);
      if ($nom === '' || $ape === '' || $u === '') $error = "Nombre, apellido y usuario son obligatorios.";
      elseif (strlen($p)<6) $error = "La contraseña debe tener al menos 6 caracteres.";
      elseif ($aid > 0 && !asignatura_existe($conn,$aid)) $error = "Asignatura no válida.";
      elseif ($rol==='docente' && $aid <= 0) $error = "Elige la asignatura del docente (si no existe, créala en el cuadro Asignaturas).";
      elseif ($lim = limite_usuarios($conn)) $error = $lim;   // límite fijado por el superadmin del portal
      else {
        $aidVal = $aid > 0 ? $aid : null;
        $h=password_hash($p,PASSWORD_DEFAULT);
        // clave_texto: solo para docentes, se usa únicamente para imprimir su tarjeta de acceso.
        $claveTxt = ($rol==='docente') ? $p : null;
        $s=$conn->prepare("INSERT INTO maestros (nombre,apellido,usuario,password,clave_texto,rol,asignatura_id) VALUES (?,?,?,?,?,?,?)");
        $s->bind_param("ssssssi",$nom,$ape,$u,$h,$claveTxt,$rol,$aidVal); $s->execute(); $mensaje="Maestro creado.";
      }

    } elseif (isset($_POST['editar_maestro'])) {
      $id=(int)$_POST['id'];
      $nom = mb_substr(trim($_POST['nombre'] ?? ''), 0, 100);
      $ape = mb_substr(trim($_POST['apellido'] ?? ''), 0, 100);
      $aid = (int)($_POST['asignatura_id'] ?? 0);
      $s=$conn->prepare("SELECT rol FROM maestros WHERE id=?"); $s->bind_param("i",$id); $s->execute(); $m=$s->get_result()->fetch_assoc();
      if (!$m) $error = "Maestro no válido.";
      elseif ($nom === '' || $ape === '') $error = "Nombre y apellido son obligatorios.";
      elseif ($aid > 0 && !asignatura_existe($conn,$aid)) $error = "Asignatura no válida.";
      elseif ($m['rol']==='docente' && $aid <= 0) $error = "Un docente debe tener asignatura.";
      else {
        $aidVal = $aid > 0 ? $aid : null;
        $s=$conn->prepare("UPDATE maestros SET nombre=?, apellido=?, asignatura_id=? WHERE id=?");
        $s->bind_param("ssii",$nom,$ape,$aidVal,$id); $s->execute(); $mensaje="Maestro actualizado."; $ed = 0;
      }

    } elseif (isset($_POST['clave'])) {
      $id=(int)$_POST['id']; $p=$_POST['password'];
      if (strlen($p)<6) $error="La contraseña debe tener al menos 6 caracteres.";
      else { $h=password_hash($p,PASSWORD_DEFAULT);
        // Se mantiene sincronizada la clave de la tarjeta (solo docentes; administradores no guardan clave en texto).
        $s=$conn->prepare("UPDATE maestros SET password=?, clave_texto=IF(rol='docente', ?, NULL) WHERE id=?"); $s->bind_param("ssi",$h,$p,$id); $s->execute(); $mensaje="Contraseña actualizada."; }

    } elseif (isset($_POST['borrar'])) {
      $id=(int)$_POST['id'];
      $s=$conn->prepare("SELECT usuario FROM maestros WHERE id=?"); $s->bind_param("i",$id); $s->execute(); $r=$s->get_result()->fetch_assoc();
      if (($r['usuario']??'')===$_SESSION['maestro']) $error="No puedes eliminar tu propio usuario.";
      else { $s=$conn->prepare("DELETE FROM maestros WHERE id=?"); $s->bind_param("i",$id); $s->execute(); $mensaje="Maestro eliminado (sus opciones de canje se eliminan; el histórico y las metas se conservan)."; }
    }
  } catch (mysqli_sql_exception $e) {
    $error = ((int)$e->getCode() === 1062) ? "Ese usuario o nombre ya existe." : "No se pudo completar la operación.";
  }
  if ($mensaje) sync_stats();   // actualiza los contadores que ve el superadmin
}

$asignaturas = $conn->query("SELECT ag.id, ag.nombre, (SELECT COUNT(*) FROM maestros WHERE asignatura_id=ag.id) tot FROM asignaturas ag ORDER BY ag.nombre")->fetch_all(MYSQLI_ASSOC);
$m = $conn->query("SELECT m.id, m.nombre, m.apellido, m.usuario, m.rol, m.asignatura_id, ag.nombre asignatura,
                          (m.clave_texto IS NOT NULL AND m.clave_texto <> '') tiene_clave
                   FROM maestros m LEFT JOIN asignaturas ag ON ag.id=m.asignatura_id
                   ORDER BY m.rol DESC, m.apellido, m.nombre, m.usuario")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head><title>Maestros</title><?php include 'head.php'; ?>
<style>
  .tabla-maestros thead th{background:var(--forest-tint);color:var(--forest-text);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid var(--forest-tint);white-space:nowrap;padding:.65rem .75rem}
  .tabla-maestros td{padding:.55rem .75rem}
  .tabla-maestros .fila-edicion td{background:#fff8e6}
</style></head>
<body class="bg-light"><?php include 'menu.php'; ?>
<div class="container mt-2">
  <?php if($mensaje) echo "<div class='alert alert-success'>".h($mensaje)."</div>"; ?>
  <?php if($error) echo "<div class='alert alert-danger'>".h($error)."</div>"; ?>

  <div class="row">
    <div class="col-lg-7 mb-3"><div class="card p-4 shadow-sm h-100"><h4 class="text-primary mb-3">Nuevo maestro</h4>
      <form method="POST" class="row g-2" autocomplete="off">
        <div class="col-md-6"><input name="nombre" class="form-control" placeholder="Nombre" maxlength="100" required></div>
        <div class="col-md-6"><input name="apellido" class="form-control" placeholder="Apellido" maxlength="100" required></div>
        <div class="col-md-6"><input name="usuario" class="form-control" placeholder="Usuario" maxlength="50" autocomplete="off" required></div>
        <div class="col-md-6"><input type="password" name="password" class="form-control" placeholder="Contraseña (mín. 6)" autocomplete="new-password" required></div>
        <div class="col-md-6"><select name="asignatura_id" class="form-select">
          <option value="">Elige una asignatura...</option>
          <?php foreach($asignaturas as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-3"><select name="rol" class="form-select"><option value="docente">Docente</option><option value="admin">Administrador</option></select></div>
        <div class="col-md-3"><button name="crear" class="btn btn-primary w-100">Crear</button></div>
      </form>
      <?php if(!$asignaturas): ?><small class="text-danger d-block mt-2">Primero crea una asignatura (cuadro de la derecha).</small><?php endif; ?>
      <small class="text-muted d-block mt-2">La asignatura es obligatoria para docentes; para administradores es opcional.</small>
    </div></div>

    <div class="col-lg-5 mb-3"><div class="card p-4 shadow-sm h-100"><h4 class="text-primary mb-3">Asignaturas</h4>
      <form method="POST" class="d-flex gap-2 mb-3">
        <input type="text" name="nombre_asignatura" class="form-control" required maxlength="100" placeholder="Ej: Lenguaje">
        <button name="crear_asignatura" class="btn btn-primary">Agregar</button>
      </form>
      <?php foreach($asignaturas as $a): ?>
        <?php if($eda==$a['id']): ?>
        <form method="POST" class="d-flex gap-2 border-top py-2">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <input type="text" name="nombre_asignatura" class="form-control form-control-sm" value="<?= h($a['nombre']) ?>" maxlength="100" required>
          <button name="editar_asignatura" class="btn btn-sm btn-primary">Guardar</button>
          <a href="maestros.php" class="btn btn-sm btn-light">Cancelar</a>
        </form>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center border-top py-2">
          <div><?= h($a['nombre']) ?> <small class="text-muted">(<?= (int)$a['tot'] ?> maestro<?= $a['tot']==1?'':'s' ?>)</small></div>
          <div class="d-flex gap-1">
            <a href="?editar_asig=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
            <form method="POST" onsubmit="return confirm('¿Eliminar la asignatura?')"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button name="borrar_asignatura" class="btn btn-sm btn-outline-danger">✕</button></form>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; if(!$asignaturas) echo "<p class='text-muted small mb-0'>Aún no hay asignaturas.</p>"; ?>
    </div></div>
  </div>

  <div class="card p-4 shadow-sm mb-3">
    <h4 class="text-primary mb-1">🪪 Tarjetas de acceso docentes</h4>
    <p class="text-muted small mb-3">Genera las tarjetas con el usuario y la clave de cada docente (datos tomados de la tabla de maestros). Se abren en una pestaña nueva lista para imprimir.</p>
    <form method="GET" action="maestros.php" target="_blank" class="row g-2 align-items-end">
      <div class="col-md-8">
        <label class="form-label small mb-1">Docente</label>
        <select name="tarjetas" class="form-select">
          <option value="todos">Todos los docentes</option>
          <?php foreach($m as $x): if($x['rol']!=='docente') continue; ?>
          <option value="<?= (int)$x['id'] ?>"><?= h(trim($x['nombre'].' '.$x['apellido']) ?: $x['usuario']) ?> (@<?= h($x['usuario']) ?>)<?= $x['tiene_clave'] ? '' : ' — sin clave guardada' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><button class="btn btn-primary w-100">Generar tarjetas</button></div>
    </form>
    <?php $sinClaveN = count(array_filter($m, fn($x) => $x['rol']==='docente' && !$x['tiene_clave'])); if($sinClaveN): ?>
    <small class="text-warning-emphasis d-block mt-2">⚠️ <?= $sinClaveN ?> docente(s) no tienen clave guardada: no saldrán en las tarjetas hasta que les asignes una nueva contraseña en la tabla de abajo.</small>
    <?php endif; ?>
  </div>

  <div class="card shadow-sm">
    <div class="d-flex align-items-center gap-2 px-3 pt-3 pb-2">
      <h5 class="mb-0 text-primary">Maestros registrados</h5>
      <span class="badge bg-light text-dark border"><?= count($m) ?></span>
    </div>
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 tabla-maestros">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Usuario</th>
          <th>Rol</th>
          <th>Asignatura</th>
          <th>Nueva contraseña</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($m as $x): $mid = (int)$x['id']; ?>
        <?php if($ed==$mid): ?>
        <tr class="fila-edicion">
          <td>
            <form id="fe-<?= $mid ?>" method="POST"><input type="hidden" name="id" value="<?= $mid ?>"></form>
            <div class="d-flex gap-1">
              <input form="fe-<?= $mid ?>" name="nombre" class="form-control form-control-sm" style="min-width:110px" value="<?= h($x['nombre']) ?>" placeholder="Nombre" maxlength="100" required>
              <input form="fe-<?= $mid ?>" name="apellido" class="form-control form-control-sm" style="min-width:110px" value="<?= h($x['apellido']) ?>" placeholder="Apellido" maxlength="100" required>
            </div>
          </td>
          <td class="text-muted">@<?= h($x['usuario']) ?></td>
          <td><span class="badge <?= $x['rol']=='admin'?'bg-dark':'bg-primary' ?>"><?= $x['rol']=='admin'?'Administrador':'Docente' ?></span></td>
          <td>
            <select form="fe-<?= $mid ?>" name="asignatura_id" class="form-select form-select-sm" style="min-width:150px">
              <option value="">Sin asignatura</option>
              <?php foreach($asignaturas as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $a['id']==$x['asignatura_id']?'selected':'' ?>><?= h($a['nombre']) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td class="text-muted small">—</td>
          <td class="text-end text-nowrap">
            <button form="fe-<?= $mid ?>" name="editar_maestro" class="btn btn-sm btn-primary">Guardar</button>
            <a href="maestros.php" class="btn btn-sm btn-light">Cancelar</a>
          </td>
        </tr>
        <?php else: ?>
        <tr>
          <td class="fw-semibold"><?= h(trim($x['nombre'].' '.$x['apellido']) ?: $x['usuario']) ?></td>
          <td class="text-muted">@<?= h($x['usuario']) ?></td>
          <td><span class="badge <?= $x['rol']=='admin'?'bg-dark':'bg-primary' ?>"><?= $x['rol']=='admin'?'Administrador':'Docente' ?></span></td>
          <td>
            <?php if($x['asignatura']): ?><span class="badge bg-primary-subtle text-primary"><?= h($x['asignatura']) ?></span>
            <?php else: ?><span class="badge bg-warning-subtle text-warning-emphasis">Sin asignatura</span><?php endif; ?>
          </td>
          <td>
            <form method="POST" class="d-flex gap-1" style="min-width:240px">
              <input type="hidden" name="id" value="<?= $mid ?>">
              <input type="password" name="password" class="form-control form-control-sm" placeholder="Nueva clave (mín. 6)" autocomplete="new-password" minlength="6" required>
              <button name="clave" class="btn btn-sm btn-outline-primary">Cambiar</button>
            </form>
          </td>
          <td class="text-end text-nowrap">
            <?php if($x['rol']==='docente'): ?>
              <?php if($x['tiene_clave']): ?><a href="?tarjetas=<?= $mid ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Generar tarjeta de acceso">🪪</a>
              <?php else: ?><span class="d-inline-block" title="Sin clave guardada: asigna una nueva contraseña"><button class="btn btn-sm btn-outline-secondary" disabled>🪪</button></span><?php endif; ?>
            <?php endif; ?>
            <a href="?editar=<?= $mid ?>" class="btn btn-sm btn-outline-primary">Editar</a>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este maestro? Sus opciones de canje se borran; el histórico y las metas se conservan.')"><input type="hidden" name="id" value="<?= $mid ?>"><button name="borrar" class="btn btn-sm btn-outline-danger">✕</button></form>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div><?php include 'footer.php'; ?>
</body></html>
