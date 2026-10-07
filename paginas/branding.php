<?php
// =====================================================================================
// Branding / configuración del colegio: valores por defecto, colores derivados, tema CSS,
// imágenes (guardadas en la BD del colegio) y el formulario usado por el asistente de
// primera configuración (portal/setup.php) y por la edición posterior (configuracion.php).
// =====================================================================================

const BRAND_RECURSOS = ['logo' => 512, 'icono' => 256, 'mascota' => 700];   // clave => lado máximo en px

function brand_defaults(): array {
  return [
    'nombre_colegio'  => 'Mi Colegio',
    'subtitulo_login' => 'Acceso Docente',
    'color_primario'  => '#6ea36f',
    'color_secundario'=> '#e7c9a9',
    'color_fondo'     => '#f3f7ef',
    'color_texto'     => '#38452f',
    'nombre_chatbot'  => 'Asistente',
    'chatbot_activo'  => '1',
    'footer_texto'    => '',
    'instagram'       => '',
    'whatsapp'        => '',
    'zona_horaria'    => 'America/Santiago',
    'img_ver'         => '0',
  ];
}

function brand_presets(): array {
  return [
    'Bosque'    => ['#6ea36f', '#e7c9a9', '#f3f7ef', '#38452f'],
    'Océano'    => ['#3b82c4', '#f2c46d', '#eef5fb', '#1f3550'],
    'Atardecer' => ['#e07a3f', '#f4d58d', '#fdf4ec', '#4a2e1f'],
    'Lavanda'   => ['#8b6fc7', '#f0c9e0', '#f5f1fb', '#3a2f5b'],
    'Frutilla'  => ['#d65a7b', '#f9d9a8', '#fdf0f3', '#4d2433'],
    'Grafito'   => ['#4b5d6b', '#d9b36c', '#f1f3f5', '#26303a'],
  ];
}

function brand_zonas(): array {
  return ['America/Santiago' => 'Chile (Santiago)', 'America/Argentina/Buenos_Aires' => 'Argentina', 'America/Bogota' => 'Colombia',
          'America/Lima' => 'Perú', 'America/Mexico_City' => 'México (Ciudad de México)', 'America/Montevideo' => 'Uruguay',
          'America/La_Paz' => 'Bolivia', 'America/Caracas' => 'Venezuela', 'America/Guayaquil' => 'Ecuador',
          'America/Asuncion' => 'Paraguay', 'America/Sao_Paulo' => 'Brasil (São Paulo)', 'Europe/Madrid' => 'España (Madrid)', 'UTC' => 'UTC'];
}

// ---- Lectura / escritura de la tabla config del colegio ----
function brand_cargar($conn): array {
  $cfg = brand_defaults();
  try {
    $r = $conn->query("SELECT clave, valor FROM config");
    while ($f = $r->fetch_assoc()) $cfg[$f['clave']] = $f['valor'];
  } catch (Throwable $e) {}
  return $cfg;
}
function brand_guardar_clave($conn, string $k, string $v): void {
  $s = $conn->prepare("INSERT INTO config (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
  $s->bind_param("ss", $k, $v); $s->execute();
}

// ---- Colores ----
function hex_valido($c): bool { return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c); }
function _rgb(string $h): array { $h = ltrim($h, '#'); return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))]; }
function _hex(array $c): string { return sprintf('#%02x%02x%02x', max(0,min(255,round($c[0]))), max(0,min(255,round($c[1]))), max(0,min(255,round($c[2])))); }
function color_mezclar(string $a, string $b, float $t): string {   // t=0 → a, t=1 → b
  $x = _rgb($a); $y = _rgb($b);
  return _hex([$x[0]+($y[0]-$x[0])*$t, $x[1]+($y[1]-$x[1])*$t, $x[2]+($y[2]-$x[2])*$t]);
}
function color_aclarar(string $c, float $p): string { return color_mezclar($c, '#ffffff', $p); }
function color_oscurecer(string $c, float $p): string { return color_mezclar($c, '#000000', $p); }
function color_texto_sobre(string $bg): string {   // blanco u oscuro según luminosidad del fondo
  [$r,$g,$b] = _rgb($bg);
  return (0.299*$r + 0.587*$g + 0.114*$b) > 160 ? '#26303a' : '#ffffff';
}

// CSS con las variables de tema (usadas por head.php y por las páginas de impresión).
function css_tema(array $cfg): string {
  $d = brand_defaults();
  foreach (['color_primario','color_secundario','color_fondo','color_texto'] as $k) if (!hex_valido($cfg[$k] ?? '')) $cfg[$k] = $d[$k];
  $p = $cfg['color_primario']; $sec = $cfg['color_secundario']; $bg = $cfg['color_fondo']; $tx = $cfg['color_texto'];
  $v = [
    'forest-primary'      => $p,
    'forest-primary-dark' => color_oscurecer($p, .28),
    'forest-primary-light'=> color_aclarar($p, .45),
    'forest-tint'         => color_aclarar($p, .86),
    'forest-bg'           => $bg,
    'forest-card'         => '#ffffff',
    'forest-text'         => $tx,
    'forest-muted'        => color_mezclar($tx, '#ffffff', .4),
    'forest-line'         => color_mezclar($p, $bg, .78),
    'forest-accent'       => $sec,
    'forest-warn'         => color_oscurecer($sec, .2),
    'on-primary'          => color_texto_sobre($p),
  ];
  $o = ':root{';
  foreach ($v as $k => $c) $o .= "--$k:$c;";
  return $o . '}';
}

// ---- Imágenes ----
// Devuelve [mime, binario] o lanza Exception con un mensaje legible.
function brand_procesar_imagen(array $f, int $lado): array {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('No se pudo recibir la imagen (¿pesa demasiado?). Máximo recomendado: 1 MB.');
  if ($f['size'] > 3 * 1024 * 1024) throw new Exception('La imagen supera los 3 MB.');
  $info = @getimagesize($f['tmp_name']);
  if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true))
    throw new Exception('Formato no válido. Usa PNG, JPG, GIF o WEBP.');
  $bin = file_get_contents($f['tmp_name']);
  if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
    $im = @imagecreatefromstring($bin);
    if ($im) {
      $w = imagesx($im); $hh = imagesy($im); $esc = min(1, $lado / max($w, $hh));
      $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($hh * $esc));
      $dst = imagecreatetruecolor($nw, $nh);
      imagealphablending($dst, false); imagesavealpha($dst, true);
      imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
      imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $hh);
      ob_start(); imagepng($dst, null, 7); $png = ob_get_clean();
      imagedestroy($im); imagedestroy($dst);
      return ['image/png', $png];
    }
  }
  if ($f['size'] > 1200 * 1024) throw new Exception('La imagen pesa más de 1,2 MB. Reduce su tamaño e inténtalo de nuevo.');
  return [image_type_to_mime_type($info[2]), $bin];
}
function brand_guardar_recurso($conn, string $clave, string $mime, string $datos): void {
  $f = date('Y-m-d H:i:s');
  $s = $conn->prepare("INSERT INTO recursos (clave,mime,datos,actualizado) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime), datos=VALUES(datos), actualizado=VALUES(actualizado)");
  $s->bind_param("ssss", $clave, $mime, $datos, $f); $s->execute();
}

// ---- Validación del formulario ----
// Devuelve [datos(array de config), imagenes(clave=>[mime,bin]), borrar(array de claves), errores(array)]
function brand_validar(array $post, array $files, array $actual): array {
  $err = []; $d = brand_defaults(); $dat = [];
  $dat['nombre_colegio'] = mb_substr(trim($post['nombre_colegio'] ?? ''), 0, 120);
  if ($dat['nombre_colegio'] === '') $err[] = 'Escribe el nombre del colegio.';
  $dat['subtitulo_login'] = mb_substr(trim($post['subtitulo_login'] ?? ''), 0, 60) ?: $d['subtitulo_login'];
  foreach (['color_primario','color_secundario','color_fondo','color_texto'] as $k) {
    $v = strtolower(trim($post[$k] ?? ''));
    if (!hex_valido($v)) { $err[] = 'Color no válido: ' . $k; $v = $d[$k]; }
    $dat[$k] = $v;
  }
  $dat['nombre_chatbot'] = mb_substr(trim($post['nombre_chatbot'] ?? ''), 0, 30) ?: $d['nombre_chatbot'];
  $dat['chatbot_activo'] = !empty($post['chatbot_activo']) ? '1' : '0';
  $dat['footer_texto']   = mb_substr(trim($post['footer_texto'] ?? ''), 0, 120);
  $ig = trim($post['instagram'] ?? ''); $ig = preg_replace('~^(https?://)?(www\.)?instagram\.com/~i', '', $ig); $ig = ltrim($ig, '@/');
  $dat['instagram'] = preg_match('/^[A-Za-z0-9._]{0,30}$/', $ig) ? $ig : '';
  if ($ig !== '' && $dat['instagram'] === '') $err[] = 'Usuario de Instagram no válido.';
  $wa = preg_replace('/\D+/', '', $post['whatsapp'] ?? '');
  $dat['whatsapp'] = (strlen($wa) >= 8 && strlen($wa) <= 15) ? $wa : '';
  if (trim($post['whatsapp'] ?? '') !== '' && $dat['whatsapp'] === '') $err[] = 'Número de WhatsApp no válido (incluye código de país, solo dígitos).';
  $zona = $post['zona_horaria'] ?? $d['zona_horaria'];
  $dat['zona_horaria'] = isset(brand_zonas()[$zona]) ? $zona : $d['zona_horaria'];

  $img = []; $borrar = [];
  foreach (BRAND_RECURSOS as $k => $lado) {
    if (!empty($post['quitar_' . $k])) $borrar[] = $k;
    if (isset($files[$k]) && ($files[$k]['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
      try { $img[$k] = brand_procesar_imagen($files[$k], $lado); $borrar = array_diff($borrar, [$k]); }
      catch (Exception $e) { $err[] = ucfirst($k) . ': ' . $e->getMessage(); }
    }
  }
  return [$dat, $img, array_values($borrar), $err];
}

// Aplica los cambios a la BD del colegio (se usa en la edición; el asistente inserta igual).
function brand_aplicar($conn, array $dat, array $img, array $borrar): void {
  foreach ($dat as $k => $v) brand_guardar_clave($conn, $k, (string)$v);
  foreach ($img as $k => [$mime, $bin]) brand_guardar_recurso($conn, $k, $mime, $bin);
  foreach ($borrar as $k) { $s = $conn->prepare("DELETE FROM recursos WHERE clave=?"); $s->bind_param("s", $k); $s->execute(); }
  if ($img || $borrar) brand_guardar_clave($conn, 'img_ver', (string)time());
}

// ---- Slug / nombre de BD ----
function slugificar(string $t): string {
  $t = mb_strtolower($t, 'UTF-8');
  $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
  $t = trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
  return substr($t, 0, 30);
}
function slug_valido(string $s): bool { return (bool)preg_match('/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/', $s) && !str_contains($s, '--'); }

// ---- Formulario (HTML) ----
// $modo: 'setup' (incluye código del colegio y contraseña) | 'editar'
function brand_formulario(array $cfg, string $modo, array $extra = []): void {
  $e = fn($k) => h($cfg[$k] ?? '');
  $slugVal = h($extra['slug'] ?? '');
  $imgBase = $extra['img_base'] ?? '';   // en edición: URL base para previsualizar imágenes actuales
  ?>
<style>
.bf-sec{background:#fff;border:1px solid #e3e6ea;border-radius:14px;padding:18px;margin-bottom:16px}
.bf-sec h5{font-weight:700;margin-bottom:12px}
.bf-colores{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.bf-colores label{font-size:.82rem;font-weight:600;display:block;margin-bottom:4px}
.bf-colores input[type=color]{width:100%;height:42px;border:1px solid #ced4da;border-radius:8px;padding:2px;background:#fff}
.bf-presets button{border:1px solid #ced4da;background:#fff;border-radius:20px;padding:3px 12px 3px 6px;margin:0 6px 6px 0;font-size:.82rem;display:inline-flex;align-items:center;gap:6px}
.bf-presets i{display:inline-block;width:14px;height:14px;border-radius:50%}
.bf-img{display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.bf-img .vista{width:72px;height:72px;border:1px dashed #adb5bd;border-radius:12px;object-fit:contain;background:#fafafa}
.bf-prev{border-radius:16px;overflow:hidden;border:1px solid var(--pv-line,#ddd);background:var(--pv-bg)}
.bf-prev .hd{background:linear-gradient(135deg,color-mix(in srgb,var(--pv-p) 55%,#fff),var(--pv-p));color:var(--pv-on);padding:10px 14px;font-weight:700;font-size:.9rem}
.bf-prev .bd{padding:14px;color:var(--pv-t);font-size:.9rem}
.bf-prev .bt{display:inline-block;background:var(--pv-p);color:var(--pv-on);border-radius:20px;padding:5px 16px;font-weight:700;font-size:.85rem}
.bf-prev .ac{display:inline-block;background:var(--pv-a);border-radius:20px;padding:5px 12px;font-size:.8rem;margin-left:6px;color:var(--pv-t)}
</style>

<div class="bf-sec">
  <h5>🏫 Datos del colegio</h5>
  <div class="row g-3">
    <div class="col-md-7">
      <label class="form-label fw-semibold">Nombre del colegio *</label>
      <input type="text" name="nombre_colegio" id="bf_nombre" class="form-control" maxlength="120" required value="<?= $e('nombre_colegio') ?>" placeholder="Ej: Escuela Los Aromos">
    </div>
    <div class="col-md-5">
      <label class="form-label fw-semibold">Subtítulo del acceso</label>
      <input type="text" name="subtitulo_login" class="form-control" maxlength="60" value="<?= $e('subtitulo_login') ?>">
    </div>
    <?php if ($modo === 'setup'): ?>
    <div class="col-md-7">
      <label class="form-label fw-semibold">Código del colegio (dirección web) *</label>
      <div class="input-group"><span class="input-group-text">/e/</span>
        <input type="text" name="slug" id="bf_slug" class="form-control" maxlength="30" required pattern="[a-z0-9][a-z0-9\-]{1,28}[a-z0-9]" value="<?= $slugVal ?>" placeholder="mi-colegio"></div>
      <div class="form-text">Solo minúsculas, números y guiones (3 a 30). Tus docentes entrarán con este código y <strong>no se puede cambiar después</strong>. Con él se nombra tu base de datos.</div>
    </div>
    <?php else: ?>
    <div class="col-md-7">
      <label class="form-label fw-semibold">Dirección de acceso para docentes</label>
      <input type="text" class="form-control" readonly value="<?= h($extra['url'] ?? '') ?>" onclick="this.select()">
    </div>
    <?php endif; ?>
    <div class="col-md-5">
      <label class="form-label fw-semibold">Zona horaria</label>
      <select name="zona_horaria" class="form-select"><?php foreach (brand_zonas() as $z => $n): ?>
        <option value="<?= h($z) ?>" <?= ($cfg['zona_horaria'] ?? '') === $z ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
    </div>
  </div>
</div>

<div class="bf-sec">
  <h5>🎨 Colores</h5>
  <div class="bf-presets mb-2"><span class="small text-muted me-2">Paletas rápidas:</span>
    <?php foreach (brand_presets() as $n => $c): ?><button type="button" data-p='<?= h(json_encode($c)) ?>'><i style="background:<?= h($c[0]) ?>"></i><?= h($n) ?></button><?php endforeach; ?></div>
  <div class="bf-colores mb-3">
    <div><label>Color principal</label><input type="color" name="color_primario" id="c_p" value="<?= $e('color_primario') ?>"></div>
    <div><label>Color de acento</label><input type="color" name="color_secundario" id="c_a" value="<?= $e('color_secundario') ?>"></div>
    <div><label>Fondo de página</label><input type="color" name="color_fondo" id="c_f" value="<?= $e('color_fondo') ?>"></div>
    <div><label>Color del texto</label><input type="color" name="color_texto" id="c_t" value="<?= $e('color_texto') ?>"></div>
  </div>
  <div class="bf-prev" id="bf_prev">
    <div class="hd" id="pv_nombre"><?= $e('nombre_colegio') ?></div>
    <div class="bd">Así se verá tu sistema: textos, <span class="bt">botones</span><span class="ac">acentos</span></div>
  </div>
</div>

<div class="bf-sec">
  <h5>🖼️ Imágenes <small class="text-muted fw-normal">(PNG, JPG o WEBP; se ajustan solas de tamaño)</small></h5>
  <?php
  $items = ['logo' => ['Logo del colegio', 'Aparece en el acceso y en las tarjetas impresas.'],
            'icono' => ['Ícono', 'Pequeño; aparece en el pie de página y como favicon.'],
            'mascota' => ['Mascota / avatar del asistente', 'Aparece en el chat de ayuda y en las tarjetas de docentes.']];
  foreach ($items as $k => [$tit, $ayuda]): $src = $imgBase !== '' ? $imgBase . $k . '&v=' . urlencode($cfg['img_ver'] ?? '0') : ''; ?>
  <div class="bf-img">
    <img class="vista" id="v_<?= $k ?>" alt="" <?= $src ? 'src="' . h($src) . '"' : '' ?>>
    <div class="flex-grow-1" style="min-width:220px">
      <label class="form-label fw-semibold mb-0"><?= h($tit) ?></label>
      <div class="form-text mt-0 mb-1"><?= h($ayuda) ?></div>
      <input type="file" name="<?= $k ?>" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control form-control-sm" data-vista="v_<?= $k ?>">
      <?php if ($modo === 'editar'): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="quitar_<?= $k ?>" value="1" id="q_<?= $k ?>"><label class="form-check-label small" for="q_<?= $k ?>">Volver a la imagen predeterminada</label></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="bf-sec">
  <h5>💬 Asistente de ayuda (chatbot)</h5>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Nombre del asistente / mascota</label>
      <input type="text" name="nombre_chatbot" id="bf_bot" class="form-control" maxlength="30" value="<?= $e('nombre_chatbot') ?>" placeholder="Ej: Lucho"></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch" name="chatbot_activo" value="1" id="bf_botact" <?= ($cfg['chatbot_activo'] ?? '1') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label" for="bf_botact">Mostrar el asistente a los docentes</label></div></div>
  </div>
</div>

<div class="bf-sec">
  <h5>🔗 Pie de página <small class="text-muted fw-normal">(opcional)</small></h5>
  <div class="row g-3">
    <div class="col-md-12"><label class="form-label fw-semibold">Texto del pie</label>
      <input type="text" name="footer_texto" class="form-control" maxlength="120" value="<?= $e('footer_texto') ?>" placeholder="Si lo dejas vacío se usa: «Nombre del colegio · Año escolar»"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Instagram (usuario)</label>
      <input type="text" name="instagram" class="form-control" maxlength="60" value="<?= $e('instagram') ?>" placeholder="micolegio"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">WhatsApp (con código de país)</label>
      <input type="text" name="whatsapp" class="form-control" maxlength="20" value="<?= $e('whatsapp') ?>" placeholder="56912345678"></div>
  </div>
</div>

<script>
(function(){
  var $ = function(i){ return document.getElementById(i); };
  var prev = $('bf_prev');
  function hexLum(h){ var n=parseInt(h.slice(1),16); return 0.299*(n>>16)+0.587*((n>>8)&255)+0.114*(n&255); }
  function pintar(){
    var p=$('c_p').value;
    prev.style.setProperty('--pv-p',p); prev.style.setProperty('--pv-a',$('c_a').value);
    prev.style.setProperty('--pv-bg',$('c_f').value); prev.style.setProperty('--pv-t',$('c_t').value);
    prev.style.setProperty('--pv-on', hexLum(p)>160 ? '#26303a' : '#ffffff');
    $('pv_nombre').textContent = $('bf_nombre').value || 'Nombre del colegio';
  }
  ['c_p','c_a','c_f','c_t','bf_nombre'].forEach(function(i){ $(i).addEventListener('input',pintar); });
  document.querySelectorAll('.bf-presets button').forEach(function(b){
    b.addEventListener('click',function(){ var c=JSON.parse(b.dataset.p); $('c_p').value=c[0]; $('c_a').value=c[1]; $('c_f').value=c[2]; $('c_t').value=c[3]; pintar(); });
  });
  document.querySelectorAll('input[type=file][data-vista]').forEach(function(f){
    f.addEventListener('change',function(){ if(f.files[0]) $(f.dataset.vista).src=URL.createObjectURL(f.files[0]); });
  });
  <?php if ($modo === 'setup'): ?>
  var slug=$('bf_slug'), tocado=false;
  slug.addEventListener('input',function(){ tocado=true; slug.value=slug.value.toLowerCase().replace(/[^a-z0-9-]/g,''); });
  $('bf_nombre').addEventListener('input',function(){
    if(tocado) return;
    slug.value=$('bf_nombre').value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,30);
  });
  <?php endif; ?>
  pintar();
})();
</script>
<?php
}
