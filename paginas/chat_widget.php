<?php // Asistente de ayuda del colegio (ayuda guiada, SIN IA y SIN costo). Se incluye desde footer.php solo con sesión iniciada. ?>
<style>
#bot-btn{position:fixed;right:18px;bottom:18px;width:64px;height:64px;border-radius:50%;border:0;padding:0;background:#2b3a34;cursor:pointer;box-shadow:0 6px 18px #0004;z-index:1050;transition:transform .15s}
#bot-btn:hover{transform:scale(1.07)}
#bot-btn img{width:100%;height:100%;border-radius:50%;display:block}
#bot-btn .al-tip{position:absolute;right:72px;top:50%;transform:translateY(-50%);background:#fff;color:var(--forest-text);font-size:13px;font-weight:700;padding:6px 12px;border-radius:14px;white-space:nowrap;box-shadow:0 3px 10px #0002;pointer-events:none;animation:al-tip 6s ease 1.5s both}
@keyframes al-tip{0%{opacity:0}10%,80%{opacity:1}100%{opacity:0}}
#bot-panel{position:fixed;right:18px;bottom:94px;width:360px;max-width:calc(100vw - 16px);height:min(540px,calc(100vh - 120px));background:#fff;border:1px solid var(--forest-line);border-radius:20px;box-shadow:0 12px 36px #0003;z-index:1050;display:none;flex-direction:column;overflow:hidden;font-family:'Quicksand',system-ui,sans-serif}
#bot-panel.abierto{display:flex}
.al-head{background:linear-gradient(135deg,var(--forest-primary-light),var(--forest-primary));color:var(--on-primary);padding:10px 14px;display:flex;align-items:center;gap:10px}
.al-head img{width:42px;height:42px;border-radius:50%;background:#2b3a34}
.al-head .al-t{flex:1;line-height:1.15}.al-head b{font-size:16px;display:block}.al-head small{font-size:12px;opacity:.95}
.al-head button{background:none;border:0;color:#fff;font-size:18px;cursor:pointer;padding:4px 7px;border-radius:8px}
.al-head button:hover{background:#ffffff33}
#al-msgs{flex:1;overflow-y:auto;padding:12px;background:var(--forest-bg);display:flex;flex-direction:column;gap:8px}
.al-m{max-width:88%;padding:8px 12px;border-radius:14px;font-size:14px;line-height:1.45;word-wrap:break-word}
.al-m.bot{background:#fff;border:1px solid var(--forest-line);align-self:flex-start;border-bottom-left-radius:4px;color:var(--forest-text)}
.al-m.yo{background:var(--forest-primary);color:var(--on-primary);align-self:flex-end;border-bottom-right-radius:4px}
.al-chips{display:flex;flex-wrap:wrap;gap:6px;align-self:flex-start;max-width:96%}
.al-chips button{background:#fff;border:1px solid var(--forest-primary);color:var(--forest-primary-dark);border-radius:16px;font-size:13px;font-weight:600;padding:4px 11px;cursor:pointer;text-align:left;font-family:inherit}
.al-chips button:hover{background:var(--forest-primary);color:#fff}
.al-chips button.sec{border-color:var(--forest-tint);color:var(--forest-muted)}
.al-typing span{display:inline-block;width:7px;height:7px;margin:0 2px;background:var(--forest-muted);border-radius:50%;animation:al-b 1s infinite}
.al-typing span:nth-child(2){animation-delay:.15s}.al-typing span:nth-child(3){animation-delay:.3s}
@keyframes al-b{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-5px)}}
.al-form{display:flex;gap:8px;padding:10px;border-top:1px solid var(--forest-line);background:#fff}
.al-form input{flex:1;border:1px solid #ced4da;border-radius:20px;padding:7px 14px;font-size:14px;font-family:inherit;min-width:0}
.al-form input:focus{outline:2px solid var(--forest-primary);border-color:transparent}
.al-form button{background:var(--forest-primary);border:0;color:#fff;border-radius:50%;width:38px;height:38px;font-size:17px;cursor:pointer;flex:none}
.al-nota{font-size:11px;color:var(--forest-muted);text-align:center;padding:0 10px 8px;background:#fff}
@media (max-width:480px){#bot-panel{right:8px;left:8px;width:auto;top:8px;bottom:88px;height:auto;max-height:none}#bot-btn{right:12px;bottom:12px}#bot-btn .al-tip{display:none}}
@media print{#bot-btn,#bot-panel{display:none!important}}
</style>

<button id="bot-btn" type="button" aria-label="Abrir ayuda con <?= h(cfg('nombre_chatbot', 'Asistente')) ?>" aria-expanded="false">
  <img src="<?= h(img_url('mascota')) ?>" alt="" width="64" height="64">
  <span class="al-tip">¿Necesitas ayuda?</span>
</button>

<div id="bot-panel" role="dialog" aria-label="Ayuda con <?= h(cfg('nombre_chatbot', 'Asistente')) ?>">
  <div class="al-head">
    <img src="<?= h(img_url('mascota')) ?>" alt="" width="42" height="42">
    <div class="al-t"><b><?= h(cfg('nombre_chatbot', 'Asistente')) ?></b><small>Ayuda para usar el sistema</small></div>
    <button type="button" id="al-limpiar" title="Empezar de nuevo" aria-label="Empezar de nuevo">↺</button>
    <button type="button" id="al-cerrar" title="Cerrar" aria-label="Cerrar">✕</button>
  </div>
  <div id="al-msgs" aria-live="polite"></div>
  <form class="al-form" id="al-form" autocomplete="off">
    <input type="text" id="al-input" maxlength="200" placeholder="Escribe tu pregunta..." aria-label="Tu pregunta">
    <button type="submit" aria-label="Enviar">➤</button>
  </form>
  <div class="al-nota">Respuestas basadas en el manual del sistema.</div>
</div>

<script>
var ES_ADMIN = <?= es_admin() ? 'true' : 'false' ?>;
var NOMBRE_BOT = <?= json_encode(cfg('nombre_chatbot', 'Asistente'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
/* ===== Base de conocimiento del asistente (editable) =====
   id: identificador | tema: grupo del menú | t: pregunta (título) | k: palabras clave (naturales)
   r: respuesta (**negrita**, \n = salto de línea) | a: true = solo administrador */
var TEMAS=[
 {id:'inicio',n:'🔑 Empezar'},{id:'puntos',n:'⭐ Asignar puntos'},{id:'canje',n:'🎁 Canje'},
 {id:'consultas',n:'📊 Consultas'},{id:'alumnos',n:'👥 Alumnos y cursos'},{id:'admin',n:'🛠️ Administración',a:true}
];
var KB=[
 {id:'login',tema:'inicio',t:'¿Cómo inicio sesión?',k:'iniciar sesion ingresar entrar login usuario contraseña clave acceder abrir',
  r:'1. Escribe tu **usuario** y **contraseña** (te los entrega el administrador).\n2. Presiona **Ingresar al Sistema**.\nSi los datos son incorrectos verás un aviso rojo. Al entrar llegas al **Reporte**.'},
 {id:'clave',tema:'inicio',t:'Olvidé mi contraseña',k:'olvide olvido perdi contraseña clave recuperar password restablecer cambiar mi clave',
  r:'Pídele al **administrador** que cambie tu clave (en **Maestros**, escribiendo una **Nueva clave** y presionando **Cambiar**).'},
 {id:'sesion',tema:'inicio',t:'Me pide ingresar de nuevo',k:'sesion expira expiro cierra cerro vence pide ingresar otra vez nuevamente sacó saco',
  r:'La sesión se cierra sola tras **2 horas sin actividad**. Solo vuelve a ingresar con tu usuario y contraseña.'},
 {id:'menu',tema:'inicio',t:'¿Dónde está el menú?',k:'menu donde encuentro opciones tres rayas hamburguesa salir cerrar sesion modulos pantallas',
  r:'La barra superior tiene **Reporte**, **NFC/QR** y **Contenido**.\nEl resto (**Canje, Metas, Histórico, Tarjetas, Carga masiva**) está en el menú **☰** a la derecha. Ahí también está **Salir** para cerrar sesión.'},
 {id:'perfiles',tema:'inicio',t:'Docente vs Administrador',k:'perfil perfiles rol roles docente administrador admin diferencia permisos puede',
  r:'El **docente** asigna y canjea puntos, y gestiona cursos y alumnos.\nEl **administrador** puede además crear maestros y asignaturas (**Maestros**) y eliminar cursos.'},

 {id:'asignar',tema:'puntos',t:'¿Cómo asigno puntos a un alumno?',k:'asignar dar puntos alumno nfc tarjeta acercar sumar poner premiar entregar registrar punto',
  r:'1. Ve a **NFC/QR** (barra superior).\n2. Toca **Escanear tarjeta NFC** y acerca la tarjeta del alumno al teléfono.\n3. Elige el **motivo** y **1, 2 o 3 puntos**.\n4. Presiona **Confirmar Asignación**.\nSonará una moneda y verás una frase motivadora.'},
 {id:'qr',tema:'puntos',t:'¿Cómo uso el código QR?',k:'qr camara escanear codigo lector leer mostrar sin tarjeta',
  r:'1. Ve a **NFC/QR**.\n2. Toca **Escanear código QR** y muestra el QR del alumno a la cámara.\n3. Elige motivo y puntos, y presiona **Confirmar Asignación**.\nSirve si tu teléfono no tiene NFC o no es Android.'},
 {id:'masivo',tema:'puntos',t:'Dar puntos a todo el curso',k:'todo curso completo masiva masivo grupal todos alumnos mismo puntaje entero clase grupo',
  r:'En **NFC/QR**, baja a **Asignar puntaje a todo el curso**:\n1. Elige el **curso** (verás cuántos alumnos tiene).\n2. Elige el **motivo** y **1, 2 o 3 puntos**.\n3. Presiona **Asignar a todo el curso** y confirma el aviso.\nEn el Histórico quedan marcados como «Asignación masiva».'},
 {id:'motivos',tema:'puntos',t:'¿Qué motivos y cuántos puntos puedo dar?',k:'motivo motivos cuantos cuanto puntos cantidad maximo opciones actitud participacion tarea tipos',
  r:'Los motivos son **Excelente actitud**, **Participación** y **Tarea completada**.\nPuedes dar **1, 2 o 3 puntos** en cada asignación.'},
 {id:'nfcfalla',tema:'puntos',t:'El NFC no funciona',k:'nfc no funciona falla error chrome android https iphone celular lee detecta detectar telefono apple no lee',
  r:'El NFC solo funciona en **Chrome para Android** y con conexión segura (https).\nSi usas iPhone o computador, usa **Escanear código QR**. En Android, revisa también que el NFC esté activado en el teléfono.'},
 {id:'noreg',tema:'puntos',t:'«Tarjeta o QR no registrado»',k:'tarjeta qr no registrado registrada desconocida reconoce encuentra aparece alumno no existe',
  r:'Esa tarjeta o QR no está asignada a ningún alumno.\nRegístrala en **Contenido**: agrega al alumno (o usa **Editar**) y asigna la tarjeta con **Escanear NFC**.'},

 {id:'canje',tema:'canje',t:'¿Cómo canjeo puntos?',k:'canjear canje puntos base cambiar evaluacion nota prueba descontar usar',
  r:'1. Menú **☰ → Canje**.\n2. Elige al alumno (verás su saldo).\n3. Indica los **puntos base** a otorgar (1 a 100) y una observación (opcional).\n4. Presiona **Confirmar canje**.\nPor defecto, 10 puntos virtuales = 1 punto base.'},
 {id:'tasa',tema:'canje',t:'¿Cuántos puntos vale 1 punto base? (tasa)',k:'tasa canje cuanto cuesta vale valor equivalencia 10 puntos cambiar modificar punto base',
  r:'La **Tasa de canje** (por defecto 10 puntos = 1 punto base) se cambia en el recuadro del mismo nombre en **Canje**, con **Guardar**.\nEs global para todos los docentes y no altera canjes ya hechos.'},
 {id:'premios',tema:'canje',t:'Crear premios para canjear',k:'premio premios sticker crear creo agregar opcion opciones regalo recompensa entregar canjear premio',
  r:'En **Canje → Mis opciones de canje** escribe el nombre y el costo en puntos (ej. Sticker, 5) y presiona **Agregar**. Puedes **Desactivar** o eliminar (✕) tus premios.\nPara entregar uno usa **Canjear por un premio**: elige alumno y premio, y confirma.'},
 {id:'saldoins',tema:'canje',t:'«Saldo insuficiente»',k:'saldo insuficiente alcanza suficiente error canje no deja rechaza falta puntos',
  r:'El alumno todavía no reúne los puntos que cuesta el canje. No se descuenta nada.\nRevisa su saldo en **Canje** o en **Reporte**.'},

 {id:'reporte',tema:'consultas',t:'¿Cómo leo el Reporte?',k:'reporte ver puntos resumen tabla grafico graficos curso asignatura total ranking dashboard inicio',
  r:'**Reporte** (barra superior) muestra las **metas de la semana**, gráficos de puntos por **curso** y por **asignatura**, y una tabla con los puntos por motivo, **total**, **canjeado** y **saldo** de cada alumno.\nUsa el selector **Todos los cursos** para filtrar.'},
 {id:'saldo',tema:'consultas',t:'¿Cómo veo el saldo de un alumno?',k:'saldo tiene disponible ganados consultar puntaje restante queda',
  r:'Al escanear su tarjeta o QR en **NFC/QR** aparece su saldo. También lo ves en **Reporte** (columnas Total, Canjeado y Saldo) y en **Canje** al elegir al alumno.'},
 {id:'metas',tema:'consultas',t:'Metas semanales',k:'meta metas semanal semana objetivo crear creo fijar curso cumplida avance barra',
  r:'1. Menú **☰ → Metas**.\n2. En **Nueva meta semanal** elige **curso**, **semana** y **puntos meta** (la descripción es opcional).\n3. Presiona **Crear meta**.\nEl avance suma los puntos del curso esa semana (lunes a domingo). Al llegar al objetivo aparece **¡Meta cumplida!**. Cada profesor puede tener una meta por curso y semana.'},
 {id:'historico',tema:'consultas',t:'Ver el historial de movimientos',k:'historico historial movimientos registro filtrar fecha profesor ganados canjes antiguo pasado',
  r:'Menú **☰ → Histórico**. Filtra por **curso, asignatura, alumno y fechas** y presiona **Filtrar**.\nVerás fecha, profesor, tipo (Ganado o Canje), detalle, puntos y el **saldo acumulado** del alumno. Lo más reciente aparece primero.'},
 {id:'apoderado',tema:'consultas',t:'Reporte para apoderados',k:'apoderado apoderados padre madre familia reporte publico qr apoderado ver puntos hijo hija informe',
  r:'Cada alumno tiene un **QR de apoderado**. Al escanearlo, la familia ve el puntaje, la comparación con la semana anterior y el curso, y las últimas actividades, sin usuario ni clave.\nEl QR se ve en **Contenido** y se imprime en **Tarjetas** (**Imprimir tarjetas de apoderado**).'},

 {id:'curso',tema:'alumnos',t:'Crear un curso',k:'crear creo curso agregar nuevo curso clase grupo',
  r:'En **Contenido → Agregar Curso** escribe el nombre (ej. 1ro Básico A) y presiona **Crear Curso**.\nCada curso admite hasta **50 alumnos** y es compartido por todos los docentes.'},
 {id:'alumno',tema:'alumnos',t:'Registrar un alumno',k:'registrar registro agregar crear creo alumno nuevo estudiante ingresar inscribir matricular',
  r:'En **Contenido → Registrar Alumno**: elige el curso, escribe el nombre y, si ya tienes su tarjeta, asígnala con **Escanear NFC** (opcional). Presiona **Guardar Alumno**.\nSe crean solos los QR del alumno y del apoderado.'},
 {id:'asignatarjeta',tema:'alumnos',t:'Asignar una tarjeta NFC a un alumno',k:'asignar tarjeta nfc alumno vincular editar conectar asociar nueva tarjeta',
  r:'En **Contenido**, al registrar al alumno o con **Editar**, usa **Escanear NFC** y acerca la tarjeta.\nCada tarjeta pertenece a un solo alumno.'},
 {id:'tarjdup',tema:'alumnos',t:'«Tarjeta NFC ya asignada»',k:'tarjeta ya asignada otro alumno duplicada conflicto repetida nfc ocupada',
  r:'Esa tarjeta ya pertenece a otro alumno. Cada tarjeta es de un solo alumno: revisa en **Contenido** a quién está asignada y edítalo, o usa otra tarjeta.'},
 {id:'lleno',tema:'alumnos',t:'«El curso ya tiene 50 alumnos»',k:'limite maximo 50 alumnos curso lleno completo capacidad cupo',
  r:'El máximo es de **50 alumnos por curso**. Registra al alumno en otro curso.'},
 {id:'carga',tema:'alumnos',t:'Cargar muchos alumnos (CSV)',k:'carga masiva csv excel plantilla importar subir muchos alumnos archivo cargar lista',
  r:'1. Menú **☰ → Carga masiva**.\n2. Descarga la **plantilla CSV** y complétala: **curso, alumno, nfc_uid** (la última es opcional).\n3. Si usas Excel, guárdalo como **CSV** antes de subirlo.\n4. Elige el archivo y presiona **Cargar alumnos**.\nLos cursos que no existan se crean solos. Al final verás cuántos se cargaron y las líneas con error.'},
 {id:'columnas',tema:'alumnos',t:'«Faltan columnas obligatorias»',k:'faltan columnas obligatorias error csv sep archivo carga no funciona plantilla falla',
  r:'Borra la primera línea del archivo (la que dice **sep=;**) y súbelo de nuevo. Asegúrate de que tenga las columnas **curso, alumno** y (opcional) **nfc_uid**.'},
 {id:'imprimir',tema:'alumnos',t:'Imprimir tarjetas con QR',k:'imprimir tarjetas pdf qr alumno apoderado hoja impresion descargar',
  r:'Menú **☰ → Tarjetas**: filtra por curso y presiona **Imprimir tarjetas de alumno** o **Imprimir tarjetas de apoderado**.\nPara guardar en PDF usa **Ctrl+P** y elige «Guardar como PDF».'},
 {id:'editar',tema:'alumnos',t:'Editar o eliminar un alumno',k:'editar eliminar borrar alumno nombre cambiar corregir quitar sacar',
  r:'En **Contenido**, junto a cada alumno están los botones **Editar** y ✕ (eliminar).'},
 {id:'delcurso',tema:'alumnos',t:'Eliminar un curso',k:'eliminar borrar curso quitar',
  r:'Solo el **administrador** puede eliminar un curso (**Contenido → Eliminar curso**).'},

 {id:'maestro',tema:'admin',a:true,t:'Crear un maestro',k:'crear creo crear maestro docente usuario nuevo profesor agregar cuenta profesora maestra',
  r:'En **Maestros → Nuevo maestro** completa nombre, apellido, usuario, contraseña (mínimo 6), **asignatura** y **rol** (Docente o Administrador). La asignatura es obligatoria para docentes. Presiona **Crear**.'},
 {id:'asignatura',tema:'admin',a:true,t:'Crear asignaturas',k:'asignatura asignaturas crear creo editar materia agregar ramo',
  r:'En **Maestros → Asignaturas** escribe el nombre y presiona **Agregar**. Se pueden editar y eliminar si no tienen maestros ni movimientos asociados.'},
 {id:'clavemaestro',tema:'admin',a:true,t:'Cambiar la clave de un maestro',k:'cambiar clave contraseña maestro docente olvido restablecer nueva clave',
  r:'En **Maestros**, en la fila del usuario escribe la **Nueva clave** y presiona **Cambiar**.'},
 {id:'delmaestro',tema:'admin',a:true,t:'Eliminar un maestro',k:'eliminar borrar maestro usuario docente quitar cuenta',
  r:'En **Maestros** presiona ✕ junto al usuario. Se borran sus premios; el histórico se conserva. No puedes eliminar tu propio usuario.'}
];
var ADMIN_MSG='Eso lo gestiona el **administrador** del sistema (módulo **Maestros**). Consúltale a él o ella.';

/* ===== Buscador (sin IA): coincidencia de palabras con raíces ===== */
var STOP=' el la los las un una unos unas de del en y o a al que como para por con se me mi mis es lo le les su sus puedo puede hago hacer quiero necesito donde cual cuales cuando si no ya hay esta este esto son ser pueden debo tengo hacerlo podria quisiera favor ayuda sobre mas muy tambien pero porque eso ese esa nos te tu quien quienes cuanto cuantos cuantas hoy ahora aqui ahi alla ';
function norm(s){return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9 ]/g,' ');}
function raiz(w){ if(w.length>4&&w.slice(-1)==='s') w=w.slice(0,-1); return w.length>5?w.slice(0,5):w; }
function toks(s){ return norm(s).split(/\s+/).filter(function(w){ return w&&STOP.indexOf(' '+w+' ')<0&&(w.length>2||w==='qr'); }).map(raiz); }
KB.forEach(function(e){ e._k=toks(e.k); e._t=toks(e.t); });
var DF={}; KB.forEach(function(e){ var seen={}; e._k.concat(e._t).forEach(function(w){ if(!seen[w]){ seen[w]=1; DF[w]=(DF[w]||0)+1; } }); });
function peso(w){ return Math.log(1+KB.length/(DF[w]||KB.length)); }
var UMBRAL=2.2;
function buscar(q){
  var qt=toks(q), out=[]; if(!qt.length) return out;
  KB.forEach(function(e){
    var s=0; qt.forEach(function(w){ var p=peso(w); if(e._k.indexOf(w)>=0) s+=2*p; if(e._t.indexOf(w)>=0) s+=p; });
    if(s>=UMBRAL) out.push({e:e,s:s});
  });
  out.sort(function(a,b){ return b.s-a.s; });
  return out;
}
(function(){
  var KEY='asistente_ayuda_v2_<?= h(tenant()['slug']) ?>', MAX=30;
  var btn=document.getElementById('bot-btn'), panel=document.getElementById('bot-panel'),
      msgs=document.getElementById('al-msgs'), form=document.getElementById('al-form'), input=document.getElementById('al-input'), hist=[], ocupado=false;
  try{ hist=JSON.parse(sessionStorage.getItem(KEY)||'[]'); if(!Array.isArray(hist)) hist=[]; }catch(e){ hist=[]; }

  function esc(s){ return String(s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function fmt(t){ return esc(t).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>'); }
  function guardar(){ try{ sessionStorage.setItem(KEY,JSON.stringify(hist.slice(-MAX))); }catch(e){} }
  function bajar(){ msgs.scrollTop=msgs.scrollHeight; }
  function burbuja(clase,texto){ var d=document.createElement('div'); d.className='al-m '+clase; d.innerHTML=fmt(texto); msgs.appendChild(d); bajar(); }
  function bot(t,guardarlo){ burbuja('bot',t); if(guardarlo!==false){ hist.push({rol:'bot',texto:t}); guardar(); } }
  function yo(t){ burbuja('yo',t); hist.push({rol:'yo',texto:t}); guardar(); }
  function quitarChips(){ Array.prototype.forEach.call(msgs.querySelectorAll('.al-chips'),function(c){ c.remove(); }); }
  function chips(items){
    var c=document.createElement('div'); c.className='al-chips';
    items.forEach(function(it){ var b=document.createElement('button'); b.type='button'; b.textContent=it.label; if(it.sec) b.className='sec'; b.onclick=it.fn; c.appendChild(b); });
    msgs.appendChild(c); bajar();
  }
  function limpia(t){ return t.replace(/[¿?]/g,''); }
  function visible(e){ return !e.a || ES_ADMIN; }
  function conRetraso(fn){
    ocupado=true; var t=document.createElement('div'); t.className='al-m bot al-typing'; t.innerHTML='<span></span><span></span><span></span>'; msgs.appendChild(t); bajar();
    setTimeout(function(){ t.remove(); ocupado=false; fn(); },320);
  }
  function verTemas(){ quitarChips(); menuTemas(); }
  function menuTemas(){
    var it=TEMAS.filter(function(t){ return !t.a||ES_ADMIN; }).map(function(t){ return {label:t.n,fn:function(){ if(ocupado) return; quitarChips(); yo(t.n); conRetraso(function(){ mostrarTema(t); }); }}; });
    chips(it);
  }
  function mostrarTema(t){
    bot('**'+t.n+'** — ¿qué quieres saber?');
    var it=KB.filter(function(e){ return e.tema===t.id; }).map(function(e){ return {label:limpia(e.t),fn:function(){ elegir(e); }}; });
    it.push({label:'← Otros temas',sec:true,fn:verTemas}); chips(it);
  }
  function elegir(e){ if(ocupado) return; quitarChips(); yo(e.t); conRetraso(function(){ responder(e,[]); }); }
  function responder(e,rel){
    bot(e.a&&!ES_ADMIN ? ADMIN_MSG : e.r);
    var it=rel.filter(visible).map(function(x){ return {label:limpia(x.t),fn:function(){ elegir(x); }}; });
    it.push({label:'📚 Ver temas',sec:true,fn:verTemas}); chips(it);
  }
  function preguntar(texto){
    texto=(texto||'').trim(); if(!texto||ocupado) return;
    quitarChips(); yo(texto); input.value='';
    var n=norm(texto).trim(), pal=n.split(/\s+/).length;
    conRetraso(function(){
      if(/^(hola|holi|hey|buenas|buen dia|buenos dias)/.test(n)&&pal<=3){ bot('¡Hola! 😊 ¿En qué te puedo ayudar? Elige un tema o escribe tu pregunta.'); menuTemas(); return; }
      if(/gracias|genial|perfecto|excelente/.test(n)&&pal<=4){ bot('¡De nada! 😊 Aquí estoy si necesitas algo más.'); menuTemas(); return; }
      var r=buscar(texto);
      if(!r.length){ bot('No encontré una respuesta a eso 🤔 Prueba con otras palabras (por ejemplo: «canjear», «tarjeta NFC», «carga masiva») o elige un tema.\nSi la duda sigue, consulta al administrador del sistema.'); menuTemas(); return; }
      var top=r[0].s, emp=r.filter(function(x){ return x.s>=0.92*top&&visible(x.e); });
      if(emp.length>=3){ bot('Encontré varios temas relacionados. ¿Cuál te sirve?');
        chips(emp.slice(0,4).map(function(x){ return {label:limpia(x.e.t),fn:function(){ elegir(x.e); }}; }).concat([{label:'📚 Ver temas',sec:true,fn:verTemas}])); return; }
      responder(r[0].e, r.slice(1,3).filter(function(x){ return x.s>=0.5*top; }).map(function(x){ return x.e; }));
    });
  }
  function pintar(){
    msgs.innerHTML='';
    if(!hist.length){ bot('¡Hola! Soy **'+NOMBRE_BOT+'** 👋 Te ayudo a usar el sistema. Elige un tema o escribe tu pregunta.',false); }
    else hist.forEach(function(m){ burbuja(m.rol==='yo'?'yo':'bot',m.texto); });
    menuTemas();
  }
  function ajustar(){
    var vv=window.visualViewport, abierto=panel.classList.contains('abierto');
    if(!vv||window.innerWidth>480||!abierto){ panel.style.top=panel.style.height=panel.style.bottom=''; btn.style.display=''; return; }
    var teclado=vv.height<window.innerHeight*0.75, inf=teclado?8:88;   // con teclado se oculta el botón y el chat usa todo el alto
    panel.style.top=(vv.offsetTop+8)+'px'; panel.style.bottom='auto';
    panel.style.height=Math.max(220,vv.height-8-inf)+'px';
    btn.style.display=teclado?'none':'';
    bajar();
  }
  function abrir(v){
    var ab=(v===undefined)?!panel.classList.contains('abierto'):v;
    panel.classList.toggle('abierto',ab); btn.setAttribute('aria-expanded',ab);
    ajustar();
    if(ab){ bajar(); setTimeout(function(){ input.focus(); },50); }
  }
  if(window.visualViewport){ window.visualViewport.addEventListener('resize',ajustar); window.visualViewport.addEventListener('scroll',ajustar); }
  window.addEventListener('resize',ajustar);
  btn.onclick=function(){ abrir(); };
  document.getElementById('al-cerrar').onclick=function(){ abrir(false); };
  document.getElementById('al-limpiar').onclick=function(){ hist=[]; guardar(); pintar(); };
  form.onsubmit=function(e){ e.preventDefault(); preguntar(input.value); };
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') abrir(false); });
  pintar();
})();
</script>
