<?php
require_once 'conexion.php'; requiere_login();
//if (!es_admin()) { http_response_code(403); die("Solo el administrador puede usar la carga masiva."); }

function _nuevo_qr(){ return bin2hex(random_bytes(6)); }

// Deja un texto del archivo en UTF-8 limpio. Excel en Windows suele guardar el CSV en Windows-1252
// (tildes y ñ se rompen) y deja espacios "duros" invisibles al inicio; aquí se corrige todo.
function _limpiar($v){
  $v = (string)$v;
  if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
  $v = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}\s]+/u', ' ', $v);
  return trim((string)$v);
}

$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    $resultado = ['ok'=>0, 'errores'=>[['linea'=>0,'motivo'=>'No se recibió un archivo válido (revisa que sea .csv).']]];
  } else {
    $fh = fopen($_FILES['archivo']['tmp_name'], 'r');
    if (!$fh) {
      $resultado = ['ok'=>0, 'errores'=>[['linea'=>0,'motivo'=>'No se pudo leer el archivo.']]];
    } else {
      $bom = fread($fh, 3); if ($bom !== "\xEF\xBB\xBF") rewind($fh);
      
      // Detectar el delimitador (, o ;) según la primera línea
      $linea_prueba = fgets($fh);
      $delimitador = (substr_count($linea_prueba, ';') >= substr_count($linea_prueba, ',')) ? ';' : ',';
      
      // Volver al inicio después del BOM para procesar los datos
      fseek($fh, $bom === "\xEF\xBB\xBF" ? 3 : 0);

      $header = fgetcsv($fh, 0, $delimitador);
      if (!$header) {
        $resultado = ['ok'=>0, 'errores'=>[['linea'=>1,'motivo'=>'Archivo vacío o con formato inválido.']]];
      } else {
        $header = array_map(fn($c) => strtolower(_limpiar($c)), $header);
        $idx = array_flip($header);
        // Las columnas antiguas docente_usuario y asignatura (si vienen en el archivo) se ignoran.
        $faltan = array_diff(['curso','alumno'], array_keys($idx));
        if ($faltan) {
          $resultado = ['ok'=>0, 'errores'=>[['linea'=>1,'motivo'=>'Faltan columnas obligatorias: '.implode(', ', $faltan)]]];
        } else {
          $ok = 0; $errores = []; $linea = 1;
          while (($fila = fgetcsv($fh, 0, $delimitador)) !== false) {
            $linea++;
            if (count(array_filter($fila, fn($v) => _limpiar($v) !== '')) === 0) continue; // fila vacía
            $curN = $alN = $nfc = '';
            try {
              $curN = mb_substr(_limpiar($fila[$idx['curso']] ?? ''), 0, 100);
              $alN  = mb_substr(_limpiar($fila[$idx['alumno']] ?? ''), 0, 100);
              $nfc  = isset($idx['nfc_uid']) ? mb_substr(_limpiar($fila[$idx['nfc_uid']] ?? ''), 0, 100) : '';
              if ($curN === '' || $alN === '') throw new Exception('Faltan datos obligatorios en la fila (curso y alumno).');

              // El alumno se asocia solo al curso; si el curso no existe, se crea.
              $s = $conn->prepare("SELECT id FROM cursos WHERE nombre=?");
              $s->bind_param("s", $curN); $s->execute();
              $cur = $s->get_result()->fetch_assoc();
              if ($cur) { $curId = (int)$cur['id']; }
              else {
                if ($lim = limite_cursos($conn)) throw new Exception($lim);   // límite de cursos de la instalación
                $s = $conn->prepare("INSERT INTO cursos (nombre) VALUES (?)");
                $s->bind_param("s", $curN); $s->execute(); $curId = $conn->insert_id;
              }

              $s = $conn->prepare("SELECT COUNT(*) t FROM alumnos WHERE curso_id=?");
              $s->bind_param("i", $curId); $s->execute();
              if ($s->get_result()->fetch_assoc()['t'] >= 50) throw new Exception("El curso '$curN' ya tiene 50 alumnos.");

              $qr = _nuevo_qr();
              $nfcVal = $nfc !== '' ? $nfc : null;
              $qrApod = _nuevo_qr();
              $s = $conn->prepare("INSERT INTO alumnos (curso_id,nombre,nfc_uid,qr_code,qr_apoderado) VALUES (?,?,?,?,?)");
              $s->bind_param("issss", $curId, $alN, $nfcVal, $qr, $qrApod); $s->execute();
              $ok++;
            } catch (mysqli_sql_exception $e) {
              $motivo = 'No se pudo guardar el alumno (error '.(int)$e->getCode().': '.mb_substr(_limpiar($e->getMessage()), 0, 140).').';
              if ((int)$e->getCode() === 1062 && stripos($e->getMessage(), 'nfc_uid') !== false) {
                $motivo = "La tarjeta NFC «$nfc» ya está asignada a otro alumno";
                try {
                  $q = $conn->prepare("SELECT a.nombre AS alumno, c.nombre AS curso FROM alumnos a JOIN cursos c ON c.id=a.curso_id WHERE a.nfc_uid=? LIMIT 1");
                  $q->bind_param("s", $nfc); $q->execute();
                  if ($dup = $q->get_result()->fetch_assoc()) $motivo .= ": {$dup['alumno']} ({$dup['curso']}). Si es el mismo código repetido en tu archivo, deja solo una fila o borra el nfc_uid de la repetida.";
                  else $motivo .= '.';
                } catch (mysqli_sql_exception $e2) { $motivo .= '.'; }
              } else {
                error_log('carga_masiva línea '.$linea.': '.$e->getMessage());
              }
              $errores[] = ['linea'=>$linea, 'curso'=>$curN, 'alumno'=>$alN, 'nfc'=>$nfc, 'motivo'=>$motivo];
            } catch (Exception $e) {
              $errores[] = ['linea'=>$linea, 'curso'=>$curN, 'alumno'=>$alN, 'nfc'=>$nfc, 'motivo'=>$e->getMessage()];
            }
          }
          $resultado = ['ok'=>$ok, 'errores'=>$errores];
        }
      }
      fclose($fh);
    }
  }
  sync_stats();   // actualiza los contadores del portal
}
?>
<!DOCTYPE html><html lang="es"><head><title>Carga masiva</title><?php include 'head.php'; ?></head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
  <div class="card p-4 shadow-sm mb-3">
    <h4 class="text-primary mb-3">Carga masiva de alumnos</h4>
    <p class="text-muted">Sube un archivo <b>.csv</b> con las columnas <code>curso, alumno, nfc_uid</code>.
      Los alumnos se asocian solo al curso; si el curso no existe, se crea automáticamente. La columna <code>nfc_uid</code> es opcional.
      (Las columnas antiguas <code>docente_usuario</code> y <code>asignatura</code> se ignoran si vienen en el archivo.)</p>
    <a href="plantilla_alumnos.php" class="btn btn-outline-primary mb-3">⬇️ Descargar plantilla CSV</a>
    <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
      <input type="file" name="archivo" accept=".csv" class="form-control" style="max-width:320px" required>
      <button class="btn btn-primary">Cargar alumnos</button>
    </form>
    <small class="text-muted d-block mt-2">¿Tienes un archivo Excel (.xlsx)? Ábrelo y usa "Guardar como" → CSV (delimitado por comas o punto y coma) antes de subirlo.</small>
  </div>

  <?php if ($resultado): ?>
  <div class="card p-4 shadow-sm">
    <div class="alert <?= ($resultado['ok']>0 && !$resultado['errores']) ? 'alert-success' : 'alert-warning' ?>">
      <?= (int)$resultado['ok'] ?> alumno(s) registrados correctamente.
      <?php if($resultado['errores']): ?> <?= count($resultado['errores']) ?> fila(s) con error.<?php endif; ?>
    </div>
    <?php if($resultado['errores']): ?>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Línea</th><th>Curso</th><th>Alumno</th><th>NFC</th><th>Motivo</th></tr></thead>
      <tbody><?php foreach($resultado['errores'] as $e): ?>
        <tr><td><?= (int)$e['linea'] ?></td><td><?= h($e['curso'] ?? '') ?></td><td><?= h($e['alumno'] ?? '') ?></td><td><code><?= h($e['nfc'] ?? '') ?></code></td><td><?= h($e['motivo']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    </div>
    <?php
      // CSV con solo las filas fallidas, para corregirlas y volver a subirlas (separador ; y BOM para que Excel lea las tildes)
      $csvErr = "\xEF\xBB\xBFcurso;alumno;nfc_uid\r\n"; $hayFilas = false;
      foreach ($resultado['errores'] as $e) {
        if (empty($e['alumno'])) continue;
        $hayFilas = true;
        $csvErr .= implode(';', array_map(fn($v) => '"'.str_replace('"','""',(string)$v).'"', [$e['curso'] ?? '', $e['alumno'], $e['nfc'] ?? ''])) . "\r\n";
      }
    ?>
    <?php if ($hayFilas): ?>
    <a download="filas_con_error.csv" class="btn btn-outline-primary btn-sm" href="data:text/csv;base64,<?= base64_encode($csvErr) ?>">⬇️ Descargar solo las filas con error</a>
    <?php endif; ?>
    <small class="text-muted d-block mt-2">«Línea» es el número de fila de tu archivo (la 1 es el encabezado). Los alumnos sin error <b>ya quedaron registrados</b>: corrige y sube solo las filas con error para no duplicarlos.</small>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
</body></html>