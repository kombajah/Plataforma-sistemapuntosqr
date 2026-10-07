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
</style>
<footer class="app-footer text-center">
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
