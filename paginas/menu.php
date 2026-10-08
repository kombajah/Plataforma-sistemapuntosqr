<?php
$act = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
function lk($f,$t,$a){ return "<a href='$f' class='text-white text-decoration-none small ".($a==$f?'fw-bold':'')."'>$t</a>"; }
function lkm($f,$t,$a){ return "<a href='$f' class='menu-mas-item ".($a==$f?'activo':'')."'>$t</a>"; }

// Opciones que viven dentro del menú hamburguesa
$secundarios = [
  'canje.php'        => '🎁 Canje',
  'metas.php'        => '🎯 Metas',
  'historico.php'    => '🕘 Histórico',
  'tarjetas.php'     => '🪪 Tarjetas',
  'carga_masiva.php' => '📥 Carga masiva',
];
if (es_admin()) { $secundarios['maestros.php'] = '🔑 Maestros'; $secundarios['log_sesiones.php'] = '🔐 Inicios de sesión'; $secundarios['configuracion.php'] = '🎨 Configuración'; }
$enMas = isset($secundarios[$act]);
?>
<style>
.menu-mas-wrap{position:relative}
.menu-mas-btn{background:none;border:0;color:var(--on-primary);padding:2px 4px;line-height:0;cursor:pointer;position:relative}
.menu-mas-btn .punto{position:absolute;top:-2px;right:-2px;width:8px;height:8px;border-radius:50%;background:#ffd54f}
.menu-mas-panel{position:absolute;right:0;top:calc(100% + 8px);z-index:2000;min-width:190px;background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:6px}
.menu-mas-panel[hidden]{display:none}
.menu-mas-item{display:block;padding:9px 12px;border-radius:8px;color:var(--forest-text);text-decoration:none;font-size:.9rem}
.menu-mas-item:hover{background:var(--forest-bg);color:var(--forest-text)}
.menu-mas-item.activo{font-weight:700;background:var(--forest-tint)}
.menu-mas-sep{border-top:1px solid var(--forest-line);margin:4px 0}
</style>
<div class="header-app d-flex justify-content-around align-items-center flex-wrap gap-2 pb-2">
  <?= lk('reporte.php','📊 Reporte',$act) ?>
  <?= lk('nfc.php','🛜 QR',$act) ?>
  <?= lk('contenido.php','⚙️ Cursos',$act) ?>
  <div class="menu-mas-wrap">
    <button type="button" id="btnMenuMas" class="menu-mas-btn" aria-label="Más opciones" aria-haspopup="true" aria-expanded="false" aria-controls="menuMas">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      <?php if($enMas): ?><span class="punto"></span><?php endif; ?>
    </button>
    <div id="menuMas" class="menu-mas-panel" hidden>
      <?php foreach($secundarios as $f=>$t) echo lkm($f,$t,$act); ?>
      <div class="menu-mas-sep"></div>
      <a href="salir.php" class="menu-mas-item">🚪 Salir</a>
    </div>
  </div>
</div>
<?php $drm = dias_restantes($GLOBALS['TENANT']); if (es_admin() && $drm !== null && $drm <= 7): ?>
<div class="alert alert-warning text-center py-2 mx-3 mb-2 small">⏳ Tu periodo de prueba <?= $drm <= 0 ? 'vence hoy' : 'vence en ' . $drm . ' día' . ($drm === 1 ? '' : 's') ?>. Contacta al administrador del portal para extenderlo.</div>
<?php endif; ?>
<div class="text-center pb-3">
  <span class="badge rounded-pill" style="background:#ffffff; color:var(--forest-primary-dark); border: 1px solid var(--forest-primary-dark); font-size:.85rem; padding:6px 14px">
    👋 Usuario: <strong><?= h($_SESSION['maestro']) ?></strong> · <?= es_admin()?'Administrador':'Docente' ?>
  </span>
</div>
<script>
(function(){
  const btn = document.getElementById('btnMenuMas'), panel = document.getElementById('menuMas');
  function cerrar(){ panel.hidden = true; btn.setAttribute('aria-expanded','false'); }
  btn.addEventListener('click', e => {
    e.stopPropagation();
    panel.hidden = !panel.hidden;
    btn.setAttribute('aria-expanded', String(!panel.hidden));
  });
  document.addEventListener('click', e => { if (!panel.contains(e.target)) cerrar(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(); });
})();
</script>
