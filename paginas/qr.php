<?php
require_once 'conexion.php'; requiere_login();
$cid = (int)($_GET['curso'] ?? 0);
$s = $conn->prepare("SELECT c.nombre curso FROM cursos c WHERE c.id=?");
$s->bind_param("i",$cid); $s->execute(); $curso = $s->get_result()->fetch_assoc();
if (!$curso) { http_response_code(404); die("Curso no encontrado."); }
$s = $conn->prepare("SELECT nombre, qr_code FROM alumnos WHERE curso_id=? ORDER BY nombre");
$s->bind_param("i",$cid); $s->execute(); $al = $s->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>QR del curso</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>body{font-family:sans-serif}.g{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.t{border:1px dashed #999;padding:10px;text-align:center;break-inside:avoid}.t div.q{display:flex;justify-content:center}
@media print{.np{display:none}}</style></head><body>
<h3><?= h($curso['curso']) ?> <button class="np" onclick="print()">Imprimir</button></h3>
<div class="g"><?php foreach($al as $i=>$a): ?>
  <div class="t"><div class="q" id="q<?= $i ?>"></div><b><?= h($a['nombre']) ?></b></div>
<?php endforeach; if(!$al) echo "<p>Este curso aún no tiene alumnos.</p>"; ?></div>
<script>const D=<?= json_encode(array_column($al,'qr_code'), JSON_HEX_TAG) ?>;
D.forEach((c,i)=>new QRCode(document.getElementById('q'+i),{text:c,width:130,height:130}));</script>
</body></html>
