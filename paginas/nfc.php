<?php
require_once 'conexion.php'; 
requiere_login();

// Lista de alumnos de un curso (JSON) para poder excluir alumnos en la asignación masiva.
if (isset($_GET['alumnos_curso'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)$_GET['alumnos_curso'];
    $q = $conn->prepare("SELECT id, nombre FROM alumnos WHERE curso_id = ? ORDER BY nombre");
    $q->bind_param("i", $cid);
    $q->execute();
    echo json_encode(['alumnos' => $q->get_result()->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

// Consulta de saldo (JSON) para el alumno escaneado. Vive en este mismo archivo
// para no depender de que otro endpoint esté desplegado/ruteado en el servidor.
if (isset($_GET['saldo'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int)$_GET['saldo'];
    $q = $conn->prepare("
        SELECT
          COALESCE((SELECT SUM(puntos) FROM registro_puntos WHERE alumno_id = a.id), 0) AS ganados,
          COALESCE((SELECT SUM(puntos_virtuales) FROM canjes WHERE alumno_id = a.id), 0) AS canjeados
        FROM alumnos a WHERE a.id = ?");
    $q->bind_param("i", $sid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if (!$row) { echo json_encode(['error' => 'Alumno no encontrado']); exit; }
    $g = (int)$row['ganados']; $c = (int)$row['canjeados'];
    echo json_encode(['ganados' => $g, 'canjeados' => $c, 'saldo' => $g - $c]);
    exit;
}

$mensaje = ''; 
$error = '';
$puntos_asignados = 0; // Variable para controlar las repeticiones del sonido en JS

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['asignar_puntos'])) {
    $alumno = (int)($_POST['alumno_id'] ?? 0);
    $cat    = (int)($_POST['categoria_id'] ?? 0);
    $pts    = (int)($_POST['puntos'] ?? 0);

    // Los cursos son compartidos: basta con que el alumno exista.
    $s = $conn->prepare("SELECT id FROM alumnos WHERE id=?");
    $s->bind_param("i", $alumno); 
    $s->execute(); 
    $r = $s->get_result()->fetch_assoc();

    if (!$r) {
        $error = "Alumno no válido.";
    } elseif (!in_array($pts, [1, 2, 3], true)) {
        $error = "Puntaje inválido.";
    } else {
        // Registrar puntos (guardando qué profesor y asignatura los asignó)
        $mid = docente_id(); $asig = asignatura_docente($conn);
        $s = $conn->prepare("INSERT INTO registro_puntos (alumno_id, categoria_id, puntos, maestro_id, asignatura_id, fecha) SELECT a.id, c.id, ?, ?, ?, ? FROM alumnos a, categorias c WHERE a.id=? AND c.id=? AND (c.docente_id IS NULL OR (c.docente_id=? AND c.activa=1))");
        $fecha = ahora_chile();
        $s->bind_param("iiisiii", $pts, $mid, $asig, $fecha, $alumno, $cat, $mid); 
        $s->execute();

        if ($s->affected_rows > 0) {
            $puntos_asignados = $pts; // Guardar los puntos para el audio
            
            // Consultar una frase de refuerzo positivo aleatoria de la base de datos
            $refuerzo = '';
            $s_frase = $conn->prepare("SELECT frase FROM frases_refuerzo WHERE categoria_id = ? ORDER BY RAND() LIMIT 1");
            $s_frase->bind_param("i", $cat);
            $s_frase->execute();
            $res_frase = $s_frase->get_result()->fetch_assoc();

            if ($res_frase && !empty($res_frase['frase'])) {
                $refuerzo = "<br><br>🌟 <em>\"" . h($res_frase['frase']) . "\"</em>";
            }

            $mensaje = "¡Puntos asignados correctamente!" . $refuerzo;
        } else {
            $error = "Motivo no válido.";
        }
    }
}

$puntos_masivos_alumnos = 0; // para el sonido: cuántos alumnos recibieron puntos en la asignación masiva

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['asignar_masivo'])) {
    $curso = (int)($_POST['curso_id_masivo'] ?? 0);
    $cat   = (int)($_POST['categoria_id_masivo'] ?? 0);
    $pts   = (int)($_POST['puntos_masivo'] ?? 0);

    $s = $conn->prepare("SELECT id FROM cursos WHERE id=?");
    $s->bind_param("i", $curso); $s->execute();
    $c = $s->get_result()->fetch_assoc();

    if (!$c) {
        $error = "Curso no válido.";
    } elseif (!in_array($pts, [1, 2, 3], true)) {
        $error = "Puntaje inválido.";
    } else {
        $midc = docente_id();
        $s = $conn->prepare("SELECT id FROM categorias WHERE id=? AND (docente_id IS NULL OR (docente_id=? AND activa=1))");
        $s->bind_param("ii", $cat, $midc); $s->execute();
        if (!$s->get_result()->fetch_assoc()) {
            $error = "Motivo no válido.";
        } else {
            $mid = docente_id(); $asig = asignatura_docente($conn);

            // Alumnos marcados en la lista (los desmarcados quedan excluidos).
            // Si la lista no se cargó (navegador antiguo), se asigna a todo el curso como antes.
            $lista_cargada = isset($_POST['lista_cargada']);
            $incluir = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['incluir'] ?? [])), fn($v) => $v > 0
            )));

            $s = $conn->prepare("SELECT COUNT(*) n FROM alumnos WHERE curso_id=?");
            $s->bind_param("i", $curso); $s->execute();
            $total_curso = (int)$s->get_result()->fetch_assoc()['n'];

            if ($lista_cargada && !$incluir) {
                $error = "Debes dejar al menos un alumno seleccionado.";
            } else {
                $sql = "INSERT INTO registro_puntos (alumno_id, categoria_id, puntos, maestro_id, asignatura_id, masivo, fecha)
                        SELECT a.id, ?, ?, ?, ?, 1, ? FROM alumnos a WHERE a.curso_id = ?";
                $tipos = "iiiisi";
                $params = [$cat, $pts, $mid, $asig, ahora_chile(), $curso];
                if ($lista_cargada) {
                    $sql .= " AND a.id IN (" . implode(',', array_fill(0, count($incluir), '?')) . ")";
                    $tipos .= str_repeat('i', count($incluir));
                    $params = array_merge($params, $incluir);
                }
                $s = $conn->prepare($sql);
                $s->bind_param($tipos, ...$params);
                $s->execute();
                $puntos_masivos_alumnos = $s->affected_rows;
                if ($puntos_masivos_alumnos > 0) {
                    $excluidos = $total_curso - $puntos_masivos_alumnos;
                    $mensaje = "¡Se asignaron $pts pt(s) a $puntos_masivos_alumnos alumno(s) del curso!"
                             . ($excluidos > 0 ? " ($excluidos excluido(s))" : "");
                } else {
                    $error = "Ese curso no tiene alumnos registrados.";
                }
            }
        }
    }
}

// Cursos para el formulario de asignación masiva (solo el curso, sin asignatura)
$cursosMasivo = $conn->query("SELECT c.id, c.nombre, (SELECT COUNT(*) FROM alumnos WHERE curso_id=c.id) total_alumnos FROM cursos c ORDER BY c.nombre")->fetch_all(MYSQLI_ASSOC);

// Obtener categorías usando MySQLi
$catsDisp = categorias_disponibles($conn); // base + mis categorías de meta activas
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Ingreso NFC</title>
    <?php include 'head.php'; ?>
</head>
<body class="bg-light">
<?php include 'menu.php'; ?>
<div class="container mt-2">
    <?php if($mensaje) echo "<div class='alert alert-success'>". $mensaje ."</div>"; ?>
    <div id="avisoAjax"></div>
    <?php if($error) echo "<div class='alert alert-danger'>". h($error) ."</div>"; ?>

    <div class="card p-3 shadow-sm text-center" id="estadoNFC">
        <h4>ACERCAR SU TARJETA</h4>
        <p class="text-muted">Presiona un botón y pide al alumno que acerque su tarjeta o muestre su QR.</p>
        <p id="estado" class="fw-bold text-primary fs-5"></p>
        <!--button class="btn btn-dark w-100 rounded-pill mb-2" onclick="iniciarEscaneo()">🛜 Escanear tarjeta NFC</button-->
        <button class="btn btn-outline-dark w-100 rounded-pill" onclick="iniciarQR()">📷 Escanear código QR</button>
        <div id="lectorQR" class="mt-3" style="display:none; width:100%; max-width:320px; margin:auto"></div>
    </div>

    <div class="card p-4 mt-3 shadow-sm" id="formPuntos" style="display:none">
        <h5 id="nombreAlumnoDisplay" class="text-primary mb-0"></h5>
        <p id="cursoDisplay" class="text-muted mb-2"></p>
        <div id="saldoBox" class="rounded-3 text-center py-2 mb-3" style="background:var(--forest-tint);color:#2e7d32">
            <div class="small fw-bold">Puntos disponibles</div>
            <div id="saldoValor" class="fs-2 fw-bold lh-1">…</div>
            <div id="saldoDetalle" class="small text-muted"></div>
        </div>
        <form method="POST" onsubmit="return enviarAsignacion(event, 'individual')">
            <input type="hidden" name="alumno_id" id="alumno_id_input">
            <label class="form-label">Motivo</label>
            <select name="categoria_id" class="form-select mb-3" required>
                <option value="" disabled selected>Elige un motivo...</option>
                <?php opciones_categorias($catsDisp); ?>
            </select>
            <label class="form-label">Cantidad de puntos:</label>
            <div class="d-flex justify-content-between mb-4">
                <?php foreach([1,2,3] as $n): ?>
                    <button type="button" class="btn-punto" onclick="seleccionarPunto(<?= $n ?>, this)"><?= $n ?> pt<?= $n>1?'s':'' ?></button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="puntos" id="input_puntos">
            <button type="submit" name="asignar_puntos" class="btn btn-primary w-100 rounded-pill fs-5" style="background:#2e44d3">Confirmar Asignación</button>
        </form>
    </div>

    <div class="card p-4 mt-3 shadow-sm">
        <h5 class="text-primary mb-1">👥 Asignar puntaje a todo el curso</h5>
        <p class="text-muted small">Útil para actividades grupales: todos los alumnos del curso reciben el mismo puntaje por el mismo motivo, de una sola vez.</p>
        <?php if(!$cursosMasivo): ?>
          <p class="text-muted">Primero crea un curso con alumnos en Contenido.</p>
        <?php else: ?>
        <form method="POST" onsubmit="return enviarAsignacion(event, 'masivo')">
            <select name="curso_id_masivo" id="cursoMasivo" class="form-select mb-3" required onchange="cargarAlumnosMasivo(this.value)">
                <option value="" disabled selected>Elige un curso...</option>
                <?php foreach($cursosMasivo as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['nombre']) ?> (<?= (int)$c['total_alumnos'] ?> alumnos)</option>
                <?php endforeach; ?>
            </select>
            <div id="listaMasivo" class="border rounded-3 p-2 mb-3" style="display:none">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-bold" id="resumenMasivo"></span>
                    <span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="marcarTodosMasivo(true)">Todos</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="marcarTodosMasivo(false)">Ninguno</button>
                    </span>
                </div>
                <p class="text-muted small mb-2">Desmarca a quienes <b>no</b> deben recibir puntos (ausentes o no aplica).</p>
                <div id="alumnosMasivo" style="max-height:260px;overflow-y:auto"></div>
            </div>
            <select name="categoria_id_masivo" class="form-select mb-3" required>
                <option value="" disabled selected>Elige un motivo...</option>
                <?php opciones_categorias($catsDisp); ?>
            </select>
            <label class="form-label">Cantidad de puntos:</label>
            <div class="d-flex justify-content-between mb-4">
                <?php foreach([1,2,3] as $n): ?>
                    <button type="button" class="btn-punto" onclick="seleccionarPuntoMasivo(<?= $n ?>, this)"><?= $n ?> pt<?= $n>1?'s':'' ?></button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="puntos_masivo" id="input_puntos_masivo">
            <button type="submit" name="asignar_masivo" class="btn w-100 rounded-pill fs-5 text-white" style="background:var(--forest-warn)" onclick="return confirmarMasivo()">Asignar puntaje</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
let ndef = null, html5QrcodeScanner = null, ocupado = false;

// --- AUDIO COMPATIBLE CON iPHONE (Safari) ---
// Safari solo deja sonar el audio si se "desbloquea" dentro de un toque del usuario y la página
// sigue abierta. Por eso la asignación se envía sin recargar y el audio se prepara en los toques.
let audioCtx = null, monedasListo = false;
const audioMonedas = new Audio('/sonidos/monedas.mp3');
audioMonedas.preload = 'auto';

function desbloquearAudio() {
    try {
        // iOS 17+: que el audio suene aunque el interruptor de silencio esté activado
        if (navigator.audioSession) navigator.audioSession.type = 'playback';
        const AC = window.AudioContext || window.webkitAudioContext;
        if (AC) {
            if (!audioCtx) audioCtx = new AC();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const b = audioCtx.createBuffer(1, 1, 22050), src = audioCtx.createBufferSource();
            src.buffer = b; src.connect(audioCtx.destination); src.start(0);
        }
        if (!monedasListo) {
            monedasListo = true;
            audioMonedas.muted = true;
            const p = audioMonedas.play();
            if (p && p.then) p.then(() => { audioMonedas.pause(); audioMonedas.currentTime = 0; audioMonedas.muted = false; })
                              .catch(() => { monedasListo = false; audioMonedas.muted = false; });
        }
    } catch (e) { /* sin audio disponible: se ignora */ }
}

// --- SINTETIZADOR DE SONIDO MONEDA MARIO BROS ---
function reproducirSonidoMoneda(repeticiones = 1) {
    desbloquearAudio();
    const ctx = audioCtx;
    if (!ctx) return;

    for (let i = 0; i < repeticiones; i++) {
        const tiempoInicio = ctx.currentTime + (i * 0.35);

        // Nota 1: B4 (987.77 Hz)
        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.type = 'square';
        osc1.frequency.setValueAtTime(987.77, tiempoInicio);
        gain1.gain.setValueAtTime(0.1, tiempoInicio);
        gain1.gain.exponentialRampToValueAtTime(0.01, tiempoInicio + 0.08);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(tiempoInicio);
        osc1.stop(tiempoInicio + 0.08);

        // Nota 2: E5 (1318.51 Hz)
        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.type = 'square';
        osc2.frequency.setValueAtTime(1318.51, tiempoInicio + 0.08);
        gain2.gain.setValueAtTime(0.1, tiempoInicio + 0.08);
        gain2.gain.exponentialRampToValueAtTime(0.001, tiempoInicio + 0.38);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(tiempoInicio + 0.08);
        osc2.stop(tiempoInicio + 0.38);
    }
}

// --- SONIDO DE MONEDAS (asignación a todo el curso) ---
// Reproduce sonidos/monedas.mp3; si el navegador no puede, usa el sintetizador como respaldo.
function reproducirSonidoMonedas() {
    audioMonedas.muted = false;
    audioMonedas.currentTime = 0;
    audioMonedas.volume = 0.9;
    const p = audioMonedas.play();
    if (p && p.catch) p.catch(() => reproducirSonidoMoneda(3));
}

<?php if ($puntos_asignados > 0): ?>
    document.addEventListener("DOMContentLoaded", () => {
        reproducirSonidoMoneda(<?= $puntos_asignados ?>);
    });
<?php endif; ?>
<?php if ($puntos_masivos_alumnos > 0): ?>
    document.addEventListener("DOMContentLoaded", () => {
        reproducirSonidoMonedas();
    });
<?php endif; ?>

function seleccionarPunto(v, el){
    desbloquearAudio();
    el.closest('form').querySelectorAll('.btn-punto').forEach(b => b.classList.remove('active'));
    el.classList.add('active'); 
    document.getElementById('input_puntos').value = v;
}

// --- Asignación masiva: lista de alumnos con exclusión ---
async function cargarAlumnosMasivo(cursoId){
    const caja = document.getElementById('listaMasivo'), cont = document.getElementById('alumnosMasivo');
    caja.style.display = 'none'; cont.innerHTML = '';
    document.querySelectorAll('#listaMasivo ~ input[name=lista_cargada], input[name=lista_cargada]').forEach(n => n.remove());
    if (!cursoId) return;
    try {
        const r = await fetch("nfc.php?alumnos_curso=" + encodeURIComponent(cursoId), { credentials: 'same-origin' });
        if (!r.ok) throw new Error("HTTP " + r.status);
        const d = await r.json();
        (d.alumnos || []).forEach(a => {
            const l = document.createElement('label');
            l.className = 'd-flex align-items-center gap-2 py-1 border-bottom';
            const c = document.createElement('input');
            c.type = 'checkbox'; c.name = 'incluir[]'; c.value = a.id; c.checked = true;
            c.className = 'form-check-input m-0'; c.onchange = actualizarResumenMasivo;
            const t = document.createElement('span'); t.textContent = a.nombre;
            l.appendChild(c); l.appendChild(t); cont.appendChild(l);
        });
        if (!d.alumnos || !d.alumnos.length) { cont.innerHTML = '<p class="text-muted small m-0">Este curso no tiene alumnos.</p>'; }
        else {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = 'lista_cargada'; h.value = '1';
            document.getElementById('cursoMasivo').form.appendChild(h);
        }
        caja.style.display = 'block';
        actualizarResumenMasivo();
    } catch(e) {
        cont.innerHTML = '<p class="text-danger small m-0">No se pudo cargar la lista de alumnos. Vuelve a elegir el curso.</p>';
        caja.style.display = 'block';
    }
}

function marcarTodosMasivo(v){
    document.querySelectorAll('#alumnosMasivo input[type=checkbox]').forEach(c => c.checked = v);
    actualizarResumenMasivo();
}

function actualizarResumenMasivo(){
    const todos = document.querySelectorAll('#alumnosMasivo input[type=checkbox]');
    const marc = [...todos].filter(c => c.checked).length;
    document.getElementById('resumenMasivo').textContent = marc + ' de ' + todos.length + ' alumnos recibirán puntos';
}

function confirmarMasivo(){
    const todos = document.querySelectorAll('#alumnosMasivo input[type=checkbox]');
    if (!todos.length) return confirm('¿Asignar este puntaje a TODOS los alumnos del curso elegido?');
    const marc = [...todos].filter(c => c.checked).length;
    if (!marc) { alert('Debes dejar al menos un alumno seleccionado.'); return false; }
    const exc = todos.length - marc;
    return confirm('¿Asignar este puntaje a ' + marc + ' alumno(s)' + (exc ? ' (excluyendo a ' + exc + ')' : '') + '?');
}

function seleccionarPuntoMasivo(v, el){
    desbloquearAudio();
    el.closest('form').querySelectorAll('.btn-punto').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('input_puntos_masivo').value = v;
}

// Envía la asignación sin recargar la página, para que el sonido suene en iPhone.
function enviarAsignacion(ev, tipo) {
    const esMasivo = tipo === 'masivo';
    if (!(esMasivo ? validarMasivo() : validar())) return false;
    if (!window.fetch || !window.FormData) return true;   // navegador antiguo: envío normal
    desbloquearAudio();
    procesarAsignacion(ev.target, esMasivo);
    return false;
}

async function procesarAsignacion(form, esMasivo) {
    const btn = form.querySelector('button[type=submit]');
    if (btn) btn.disabled = true;
    const campoPts = document.getElementById(esMasivo ? 'input_puntos_masivo' : 'input_puntos');
    const pts = parseInt(campoPts.value, 10) || 1;
    const aviso = document.getElementById('avisoAjax');
    try {
        const fd = new FormData(form);
        fd.append(esMasivo ? 'asignar_masivo' : 'asignar_puntos', '1');
        const r = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
        const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
        const ok = doc.querySelector('.alert-success'), mal = doc.querySelector('.alert-danger');
        if (!ok && !mal) { location.reload(); return; }   // p. ej. la sesión venció
        aviso.innerHTML = '';
        [ok, mal].forEach(n => {
            if (!n) return;
            const d = document.createElement('div');
            d.className = n.className; d.innerHTML = n.innerHTML;
            aviso.appendChild(d);
        });
        if (ok) {
            if (esMasivo) reproducirSonidoMonedas(); else reproducirSonidoMoneda(pts);
            form.reset();
            campoPts.value = '';
            if (esMasivo) cargarAlumnosMasivo('');
            form.querySelectorAll('.btn-punto').forEach(b => b.classList.remove('active'));
            if (!esMasivo) {   // listo para escanear al siguiente alumno
                document.getElementById('formPuntos').style.display = 'none';
                document.getElementById('estadoNFC').style.display = 'block';
                document.getElementById('estado').innerText = '';
                document.getElementById('lectorQR').style.display = 'none';
            }
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        aviso.innerHTML = "<div class='alert alert-danger'>No se pudo confirmar la asignación. Revisa el Histórico antes de repetirla.</div>";
    } finally {
        if (btn) btn.disabled = false;
    }
}

function validar(){ 
    if(!document.getElementById('input_puntos').value){ 
        alert('Selecciona 1, 2 o 3 puntos'); 
        return false; 
    } 
    return true; 
}

function validarMasivo(){
    if(!document.getElementById('input_puntos_masivo').value){
        alert('Selecciona 1, 2 o 3 puntos');
        return false;
    }
    return true;
}

async function cargarSaldo(id){
    const valor = document.getElementById('saldoValor');
    const detalle = document.getElementById('saldoDetalle');
    valor.innerText = '…'; detalle.innerText = '';
    try {
        const r = await fetch("nfc.php?saldo=" + encodeURIComponent(id), { credentials: 'same-origin' });
        if (!r.ok) throw new Error("HTTP " + r.status);
        const d = await r.json();
        if (d.error) { valor.innerText = '—'; detalle.innerText = d.error; return; }
        valor.innerText = d.saldo;
        detalle.innerText = "Ganados: " + d.ganados + " · Canjeados: " + d.canjeados;
    } catch(e) {
        valor.innerText = '—';
        detalle.innerText = "No se pudo consultar el saldo (" + (e.message || e) + ")";
    }
}

async function cargar(codigo){
    if (ocupado || !codigo) return false; 
    ocupado = true;
    try {
        const r = await fetch("buscar_alumno.php?uid=" + encodeURIComponent(codigo));
        const d = await r.json();
        if (d.error) { 
            document.getElementById('estado').innerText = "⚠️ " + d.error; 
            return false; 
        }
        
        await detenerQR();

        document.getElementById('estadoNFC').style.display = "none";
        document.getElementById('formPuntos').style.display = "block";
        document.getElementById('nombreAlumnoDisplay').innerText = d.nombre;
        document.getElementById('cursoDisplay').innerText = "Curso: " + d.curso;
        document.getElementById('alumno_id_input').value = d.id;
        cargarSaldo(d.id);
        return true;
    } catch(e) {
        document.getElementById('estado').innerText = "⚠️ Error al consultar el alumno";
        return false;
    } finally { 
        ocupado = false; 
    }
}

// Extrae el UID o los datos NDEF grabados en la tarjeta
function extraerCodigoNFC(event) {
    if (event.serialNumber) {
        return event.serialNumber.replace(/:/g, '').toUpperCase();
    }
    
    if (event.message && event.message.records) {
        for (const record of event.message.records) {
            if (record.recordType === "text") {
                const textDecoder = new TextDecoder(record.encoding || "utf-8");
                return textDecoder.decode(record.data).trim();
            } else if (record.recordType === "url") {
                const textDecoder = new TextDecoder();
                return textDecoder.decode(record.data).trim();
            }
        }
    }
    return null;
}

async function iniciarEscaneo(){
    await detenerQR();
    const elemEstado = document.getElementById('estado');

    if (!("NDEFReader" in window)) { 
        elemEstado.innerText = "⚠️ Web NFC requiere Chrome en Android y HTTPS. Usa el lector QR."; 
        return; 
    }
    try {
        if (!ndef) { 
            ndef = new NDEFReader(); 
            ndef.addEventListener("reading", (event) => {
                const codigo = extraerCodigoNFC(event);
                if (codigo) {
                    elemEstado.innerText = "⏳ Procesando tarjeta...";
                    cargar(codigo);
                } else {
                    elemEstado.innerText = "⚠️ Tarjeta no reconocida o sin datos válidos.";
                }
            });
            ndef.addEventListener("readingerror", () => {
                elemEstado.innerText = "⚠️ Error al leer la tarjeta. Inténtalo de nuevo.";
            });
        }
        await ndef.scan();
        elemEstado.innerText = "🛜 Escaneando... Acerca la tarjeta ahora al teléfono.";
    } catch(e){ 
        elemEstado.innerText = "⚠️ Error al iniciar NFC: " + (e.message || e); 
    }
}

async function iniciarQR(){
    desbloquearAudio();
    if (typeof Html5QrcodeScanner === 'undefined') { 
        document.getElementById('estado').innerText = "⚠️ Cargando librería QR..."; 
        return; 
    }

    await detenerQR();

    const box = document.getElementById('lectorQR');
    box.style.display = 'block';

    try {
        html5QrcodeScanner = new Html5QrcodeScanner(
            "lectorQR", 
            { 
                fps: 10, 
                qrbox: { width: 220, height: 220 },
                rememberLastUsedCamera: true,
                supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
            }, 
            false
        );

        html5QrcodeScanner.render(
            async (decodedText) => {
                await cargar(decodedText);
            },
            (error) => {}
        );
    } catch(e) {
        box.style.display = 'none';
        document.getElementById('estado').innerText = "⚠️ Error al iniciar escáner QR";
    }
}

async function detenerQR() {
    const box = document.getElementById('lectorQR');
    if (html5QrcodeScanner) {
        try {
            await html5QrcodeScanner.clear();
        } catch(e) {}
        html5QrcodeScanner = null;
    }
    box.style.display = 'none';
}
</script>
<?php include 'footer.php'; ?>
</body>
</html>