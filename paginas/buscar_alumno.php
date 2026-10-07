<?php
require_once 'conexion.php';
iniciar_sesion();
header('Content-Type: application/json');
if (!isset($_SESSION['maestro'])) { http_response_code(403); echo json_encode(['error'=>'No autorizado']); exit; }
$code = trim($_GET['uid'] ?? '');
$sql = "SELECT a.id, a.nombre, c.nombre AS curso
        FROM alumnos a JOIN cursos c ON a.curso_id=c.id
        WHERE (a.nfc_uid=? OR a.qr_code=?)";
$s = $conn->prepare($sql); $s->bind_param("ss", $code, $code); $s->execute();
$r = $s->get_result()->fetch_assoc();
if (!$r) { echo json_encode(['error'=>'Tarjeta o QR no registrado']); exit; }
echo json_encode($r);
