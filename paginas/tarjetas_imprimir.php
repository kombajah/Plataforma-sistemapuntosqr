<?php
require_once 'conexion.php'; requiere_login();

$esApoderado = ($_GET['tipo'] ?? '') === 'apoderado';
// caras: ambos (frente | dorso lado a lado), frente, dorso
$caras = in_array($_GET['caras'] ?? '', ['frente','dorso'], true) ? $_GET['caras'] : 'ambos';

$fc  = (int)($_GET['curso'] ?? 0);
$fal = trim($_GET['alumno'] ?? '');

$types=''; $vals=[];
$sql = "SELECT al.nombre, al.qr_code, al.qr_apoderado, c.nombre curso
        FROM alumnos al JOIN cursos c ON c.id=al.curso_id
        WHERE 1=1";
if ($fc)  { $sql.=" AND c.id=?";  $types.='i'; $vals[]=$fc; }
if ($fal !== '') { $sql.=" AND al.nombre LIKE ?"; $types.='s'; $vals[]='%'.$fal.'%'; }
if ($esApoderado) { $sql.=" AND al.qr_apoderado IS NOT NULL"; }
$sql.=" ORDER BY c.nombre, al.nombre";
$s=$conn->prepare($sql); if($vals) $s->bind_param($types,...$vals); $s->execute();
$alumnos = $s->get_result()->fetch_all(MYSQLI_ASSOC);

// Mismo formato de URL que usa contenido.php para el QR del apoderado (incluye el código del colegio)
$baseUrlApoderado = url_sitio() . 'reporte_apoderado.php?token=';
$codigos = $esApoderado
  ? array_map(fn($a) => $baseUrlApoderado . $a['qr_apoderado'], $alumnos)
  : array_column($alumnos, 'qr_code');

// Links para cambiar de modo de impresión
$base = array_filter(['tipo'=>$esApoderado?'apoderado':'', 'curso'=>$fc, 'alumno'=>$fal]);
$lnk = fn($c) => '?' . http_build_query($base + ['caras'=>$c]);

// Mascota del colegio (se configura en Configuración; si no hay, se usa la genérica).
$mascota = img_url('mascota');
$tieneMascota = true;
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Tarjetas de alumnos</title>
<style><?= css_tema($GLOBALS['CFG']) ?></style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
@page{size:A4;margin:8mm}
*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Quicksand',system-ui,sans-serif;background:var(--forest-tint);color:var(--forest-text);margin:16px}
.bar{margin-bottom:14px}
.bar a,.bar button{display:inline-block;margin:2px 4px 2px 0;padding:5px 10px;border:1px solid var(--forest-primary);border-radius:6px;background:#fff;color:var(--forest-text);text-decoration:none;font-size:13px;cursor:pointer}
.bar a.on{background:var(--forest-primary);color:#fff}

/* ===== Hoja ===== */
.pagina{display:grid;gap:3mm;justify-content:center;margin-bottom:6mm;page-break-after:always;break-after:page}
.pagina:last-child{page-break-after:auto;break-after:auto}
.pagina.ambos{grid-template-columns:repeat(2,85.6mm)}
.pagina.solo{grid-template-columns:repeat(2,85.6mm)}

/* ===== Tarjeta base (CR80: 85.6 x 54 mm) ===== */
.card{position:relative;width:85.6mm;height:54mm;border-radius:3.5mm;overflow:hidden;break-inside:avoid;box-shadow:0 0 0 .2mm rgba(0,0,0,.18)}

/* ===== FRENTE ===== */
.front{background:linear-gradient(160deg,#f6e7c8 0%,#efd9a8 38%,var(--forest-primary-light) 38.1%,var(--forest-primary) 100%);color:var(--forest-primary-dark)}
.front .franja{position:absolute;left:0;top:0;bottom:0;width:4.2mm;background:linear-gradient(180deg,#bfe0ff,#ffd3f2,#fff3b0,#c9ffd9,#bfe0ff)}
.front .titulo{position:absolute;left:4.2mm;right:0;top:0;height:7mm;background:var(--forest-primary);color:#fff;font-weight:800;font-size:11.5pt;letter-spacing:.3mm;text-align:center;line-height:7mm;text-shadow:0 .3mm 0 rgba(0,0,0,.25)}
.front .chip{position:absolute;left:8mm;top:10mm;width:10mm;height:7.5mm;border-radius:1.2mm;background:linear-gradient(135deg,#e8e8e8,#a9a9a9);box-shadow:inset 0 0 0 .25mm #8a8a8a}
.front .chip:before{content:"";position:absolute;left:3.3mm;right:3.3mm;top:0;bottom:0;border-left:.2mm solid #8a8a8a;border-right:.2mm solid #8a8a8a}
.front .chip:after{content:"";position:absolute;top:2.5mm;bottom:2.5mm;left:0;right:0;border-top:.2mm solid #8a8a8a;border-bottom:.2mm solid #8a8a8a}
.front .nfc{position:absolute;left:19.5mm;top:11mm;font-size:11pt;color:#555;font-weight:700;transform:rotate(0deg)}
.front .pay{position:absolute;left:8mm;top:8mm;font-size:4.5pt;font-weight:700;color:#4a3d26}
.front .campo{position:absolute;left:8mm;width:44mm}
.front .campo small{display:block;font-size:5pt;font-weight:800;color:#3b3b2a;margin-bottom:.4mm}
.front .campo .v{background:#fff;border-radius:1.6mm;padding:0 2mm;height:5.2mm;line-height:5.2mm;font-size:8.5pt;font-weight:700;color:#1d1d1d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-shadow:inset 0 -.2mm 0 #ccc}
.front .c1{top:19mm}
.front .c2{top:27.5mm}
.front .mascota{position:absolute;right:1.5mm;top:8mm;width:27mm;height:30mm;object-fit:contain}
.front .mascota-emoji{position:absolute;right:5mm;top:12mm;font-size:34pt}
.front .lema{position:absolute;left:6mm;right:1mm;top:36.2mm;text-align:center;font-weight:800;font-size:6.6pt;line-height:1.15;color:#2f5d1c;text-transform:uppercase}
.front .puntos{position:absolute;left:7mm;top:42.5mm;background:#fff;border-radius:1.5mm;padding:.5mm 2mm;font-size:4.8pt;font-weight:800;color:var(--forest-primary-dark);line-height:1.25}
.front .puntos i{display:inline-block;width:2.3mm;height:2.3mm;border-radius:.6mm;background:#d6d6d6;margin-left:.6mm;vertical-align:middle}
.front .puntos i.p{border-radius:50%;background:#aaa}
.front .pie{position:absolute;left:4.2mm;right:0;bottom:0;height:5.4mm;background:#3f7d2a;display:flex;justify-content:space-around;align-items:center;color:#fff;font-size:4.6pt;font-weight:800;letter-spacing:.1mm}
.front .pie span:before{content:"P";display:inline-block;width:2.6mm;height:2.6mm;border-radius:50%;background:#e8b84a;color:#fff;font-size:4pt;line-height:2.6mm;text-align:center;margin-right:.8mm;vertical-align:middle}
.front .etiqueta-ap{position:absolute;right:2mm;top:8mm;background:var(--forest-warn);color:#fff;font-size:5pt;font-weight:800;letter-spacing:.3mm;border-radius:1mm;padding:.4mm 1.8mm;z-index:2}

/* ===== DORSO ===== */
.back{background:#fff;border:.6mm solid var(--forest-primary);color:var(--forest-primary-dark);text-align:center}
.back.ap{border-color:#e0a869}
.back .banda{position:absolute;left:0;right:0;top:4mm;height:7mm;background:#2d3b25}
.back .franja{position:absolute;left:0;right:0;bottom:0;height:4mm;background:linear-gradient(90deg,#bfe0ff,#ffd3f2,#fff3b0,#c9ffd9,#bfe0ff)}
.back .cont{position:absolute;left:0;right:0;top:13mm;bottom:5mm;display:flex;align-items:center;justify-content:center;gap:4mm;padding:0 5mm}
.back .qr{background:#fff;padding:1mm;border:.3mm solid var(--forest-tint);border-radius:1.5mm;line-height:0}
.back .qr img,.back .qr canvas{width:27mm!important;height:27mm!important}
.back .info{text-align:left;max-width:38mm}
.back .info .logo{width:8mm;height:8mm;object-fit:contain}
.back .info b{display:block;font-size:8pt;line-height:1.15;margin-top:1mm}
.back .info .cur{font-size:6.5pt;color:var(--forest-muted);font-weight:700}
.back .info .ayuda{font-size:5pt;color:var(--forest-text);margin-top:1.5mm;line-height:1.25}
.back .tag{display:inline-block;background:var(--forest-warn);color:#fff;font-size:5pt;font-weight:800;letter-spacing:.3mm;border-radius:1mm;padding:.3mm 1.5mm;margin-top:1mm}

@media print{.np{display:none!important}body{margin:0;background:#fff}}
</style></head><body>

<div class="bar np">
  <b>Tarjetas de <?= $esApoderado ? 'apoderado' : 'alumno' ?> (<?= count($alumnos) ?>)</b> &nbsp;
  <button onclick="print()">🖨️ Imprimir / Guardar como PDF</button>
  <a href="<?= h($lnk('ambos')) ?>" class="<?= $caras==='ambos'?'on':'' ?>">Frente + dorso</a>
  <a href="<?= h($lnk('frente')) ?>" class="<?= $caras==='frente'?'on':'' ?>">Solo frente</a>
  <a href="<?= h($lnk('dorso')) ?>" class="<?= $caras==='dorso'?'on':'' ?>">Solo dorso (QR)</a>
  <?php if($esApoderado): ?><br><small>Alumnos sin QR de apoderado generado quedan fuera (edítalos en Contenido para generarlo).</small><?php endif; ?>
  <br><small>Tamaño real 85,6 × 54 mm. Imprime al 100 % (sin “ajustar a página”).</small>
</div>

<?php
function cara_frente($a, $esApoderado, $mascota, $tieneMascota){ ?>
  <div class="card front">
    <div class="franja"></div>
    <div class="titulo">PUNTOS <?= h(mb_strtoupper(mb_substr(cfg('nombre_colegio'), 0, 16))) ?></div>
    <?php if($esApoderado): ?><span class="etiqueta-ap">APODERADO</span><?php endif; ?>
    <div class="pay">SIN CONTACTO</div>
    <div class="chip"></div><div class="nfc">)))</div>
    <div class="campo c1"><small>NOMBRE DEL ALUMNO:</small><div class="v"><?= h($a['nombre']) ?></div></div>
    <div class="campo c2"><small>CURSO:</small><div class="v"><?= h($a['curso']) ?></div></div>
    <img class="mascota" src="<?= h($mascota) ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
    <div class="mascota-emoji" style="display:none">⭐</div>
    <div class="lema">¡Ven al colegio, cumple desafíos<br>y canjea tus puntos!</div>
    <!--div class="puntos">PUNTOS JUNTADOS:<br>RE-FILLABLE: <i></i><i></i><i></i><i></i><i class="p"></i></div-->
    <div class="pie"><span>ASISTENCIA</span><span>PARTICIPACION</span><span>BUEN COMPORTAMIENTO</span></div>
  </div>
<?php }
function cara_dorso($a, $esApoderado, $idx){ ?>
  <div class="card back <?= $esApoderado ? 'ap' : '' ?>">
    <div class="banda"></div>
    <div class="cont">
      <div class="qr"><div id="q<?= $idx ?>"></div></div>
      <div class="info">
        <img src="<?= h(img_url('logo')) ?>" class="logo" alt="">
        <?php if($esApoderado): ?><br><span class="tag">APODERADO</span><?php endif; ?>
        <b><?= h($a['nombre']) ?></b>
        <span class="cur"><?= h($a['curso']) ?></span>
        <div class="ayuda"><?= $esApoderado ? 'Escanea para ver el reporte de tu pupilo/a.' : 'Tu profesor/a te debe escanear esta tarjeta para sumar puntos.' ?></div>
      </div>
    </div>
    <div class="franja"></div>
  </div>
<?php }

$porPagina = 4; // 4 filas por hoja A4 (frente | dorso)
$grupos = array_chunk($alumnos, $porPagina, true);
$qrIdx = [];  // ids de contenedores QR => código
foreach ($grupos as $g): ?>
<div class="pagina <?= $caras==='ambos' ? 'ambos' : 'solo' ?>">
  <?php foreach ($g as $i => $a):
    if ($caras !== 'dorso') cara_frente($a, $esApoderado, $mascota, $tieneMascota);
    if ($caras !== 'frente') { cara_dorso($a, $esApoderado, $i); $qrIdx[] = $i; }
  endforeach; ?>
</div>
<?php endforeach;
if(!$alumnos) echo "<p>No hay alumnos para este filtro.</p>"; ?>

<script>
const D=<?= json_encode($codigos, JSON_HEX_TAG) ?>;
const IDX=<?= json_encode($qrIdx) ?>;
IDX.forEach(i=>{
  const el=document.getElementById('q'+i);
  if(el) new QRCode(el,{text:D[i],width:128,height:128,correctLevel:QRCode.CorrectLevel.M});
});
</script>
</body></html>
