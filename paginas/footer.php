<?php
// Usuario conectado (defensivo: $_SESSION['maestro'] puede ser array o un id)
$m = $_SESSION['maestro'] ?? null;
$nombreMaestro = '';
if (is_array($m)) $nombreMaestro = $m['nombre'] ?? '';
$esc = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
?>
<style>
.app-footer .pie-marca{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap}
.app-footer .pie-marca img{width:28px;height:28px;object-fit:contain}
.app-footer .pie-links a{margin:0 6px;white-space:nowrap}
.app-footer .pie-sesion{font-size:.85em;opacity:.8}
#btnArriba{position:fixed;left:16px;bottom:16px;z-index:1000;display:none;align-items:center;gap:6px;
  padding:8px 14px;border:0;border-radius:999px;background:var(--forest-primary);color:var(--on-primary);font-weight:700;font-size:.9rem;
  box-shadow:0 2px 8px rgba(0,0,0,.25);cursor:pointer}
#btnArriba:hover{background:var(--forest-primary-dark)}
#btnArriba.visible{display:inline-flex}
@media print{#btnArriba{display:none!important}}
.pie-demo{display:inline-block;background:var(--forest-accent);color:var(--forest-text);font-weight:700;border-radius:999px;padding:4px 14px;margin-bottom:8px;font-size:.9em}
#demoOverlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:3000;display:flex;align-items:center;justify-content:center;padding:16px}
#demoOverlay .demo-box{background:#fff;color:var(--forest-text);border-radius:20px;max-width:420px;width:100%;padding:26px 24px;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.35)}
#demoOverlay .demo-dias{font-family:Cambria,Georgia,serif;font-size:3.2rem;font-weight:700;line-height:1;color:var(--forest-primary-dark)}
#demoOverlay button{background:var(--forest-primary);color:var(--on-primary);border:0;border-radius:999px;padding:9px 28px;font-weight:700;margin-top:16px}
</style>
<?php
// Versión demo: etiqueta en el pie y ventana informativa una vez al iniciar sesión.
$esDemo = !empty($GLOBALS['TENANT']['es_demo']);
$dd = $esDemo ? dias_restantes($GLOBALS['TENANT']) : null;
$demoCorto = $dd === null ? 'sin fecha de vencimiento' : ($dd <= 0 ? 'vence hoy' : 'quedan ' . $dd . ' día' . ($dd === 1 ? '' : 's'));
$demoHasta = ($esDemo && $dd !== null) ? date('d-m-Y', strtotime($GLOBALS['TENANT']['vence'])) : '';
$mostrarModalDemo = $esDemo && !empty($_SESSION['maestro']) && !empty($_SESSION['demo_aviso']);
if (isset($_SESSION['demo_aviso']) && !empty($_SESSION['maestro'])) unset($_SESSION['demo_aviso']);   // solo una vez por inicio de sesión
?>
<footer class="app-footer text-center">
  <?php if ($esDemo): ?><div><span class="pie-demo">🧪 Versión de prueba · <?= h($demoCorto) ?></span></div><?php endif; ?>
  <div class="pie-marca">
    <img src="<?= h(img_url('icono')) ?>" alt="" onerror="this.style.display='none'">
    <span><?= h(cfg('footer_texto') !== '' ? cfg('footer_texto') : cfg('nombre_colegio') . ' · Año escolar ' . date('Y')) ?></span>
  </div>

  <?php if (!empty($_SESSION['maestro'])): ?>
    <div class="pie-links mt-1">
      <a href="nfc.php">📲 Escanear</a>
      <a href="canje.php">🎁 Canje</a>
      <a href="buscar_alumno.php">🔎 Buscar alumno</a>
      <a href="reporte.php">📊 Reportes</a>
    </div>
    <?php if ($nombreMaestro !== ''): ?>
      <div class="pie-sesion mt-1">Sesión: <strong><?= $esc($nombreMaestro) ?></strong> · <a href="salir.php">Cerrar sesión</a></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (cfg('instagram') !== '' || cfg('whatsapp') !== ''): ?>
  <div class="mt-1">
    <?php if (cfg('instagram') !== ''): ?><a href="https://instagram.com/<?= h(cfg('instagram')) ?>" target="_blank" rel="noopener">📷 Instagram</a><?php endif; ?>
    <?php if (cfg('whatsapp') !== ''): ?><a href="https://wa.me/<?= h(cfg('whatsapp')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
  </div>
  <?php endif; ?>
</footer>

<?php if ($mostrarModalDemo): ?>
<div id="demoOverlay" role="dialog" aria-modal="true" aria-labelledby="demoTitulo">
  <div class="demo-box">
    <div style="font-size:2.4rem">🧪</div>
    <h4 id="demoTitulo" class="fw-bold" style="color:var(--forest-primary-dark)">Versión de prueba</h4>
    <p class="mb-2">Estás usando una versión demo de <strong><?= h(cfg('nombre_colegio')) ?></strong>.</p>
    <?php if ($dd === null): ?>
      <p class="mb-0">Esta prueba no tiene fecha de vencimiento definida.</p>
    <?php elseif ($dd <= 0): ?>
      <div class="demo-dias" style="font-size:2rem">Vence hoy</div>
      <p class="mb-0 mt-1">Hasta el <?= h($demoHasta) ?></p>
    <?php else: ?>
      <div class="demo-dias"><?= (int)$dd ?></div>
      <div class="fw-bold">día<?= $dd === 1 ? '' : 's' ?> de vigencia restante<?= $dd === 1 ? '' : 's' ?></div>
      <p class="small mt-1 mb-0">Vence el <?= h($demoHasta) ?></p>
    <?php endif; ?>
    <button type="button" onclick="document.getElementById('demoOverlay').remove()">Entendido</button>
  </div>
</div>
<script>
(function(){var o=document.getElementById('demoOverlay');
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&o&&o.parentNode)o.remove();});
  o.addEventListener('click',function(e){if(e.target===o)o.remove();});})();
</script>
<?php endif; ?>
<button type="button" id="btnArriba" aria-label="Volver arriba">⬆️ Volver arriba</button>
<script>
(function(){
  var b=document.getElementById('btnArriba');
  if(!b) return;
  window.addEventListener('scroll',function(){
    b.classList.toggle('visible',window.scrollY>300);
  },{passive:true});
  b.addEventListener('click',function(){
    window.scrollTo({top:0,behavior:'smooth'});
  });
})();
</script>
<?php if (!empty($_SESSION['maestro']) && cfg('chatbot_activo', '1') === '1') include __DIR__ . '/chat_widget.php'; // asistente de ayuda (solo con sesión) ?>
