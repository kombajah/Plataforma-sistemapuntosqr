<?php
require_once 'conexion.php';
requiere_login();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);

// Mismo cálculo de saldo que canje.php: puntos ganados - puntos virtuales canjeados
$s = $conn->prepare("
  SELECT
    COALESCE((SELECT SUM(puntos) FROM registro_puntos WHERE alumno_id = a.id), 0) AS ganados,
    COALESCE((SELECT SUM(puntos_virtuales) FROM canjes WHERE alumno_id = a.id), 0) AS canjeados
  FROM alumnos a
  WHERE a.id = ?
");
$s->bind_param("i", $id);
$s->execute();
$r = $s->get_result()->fetch_assoc();

if (!$r) {
  http_response_code(404);
  echo json_encode(['error' => 'Alumno no encontrado']);
  exit;
}

$ganados   = (int)$r['ganados'];
$canjeados = (int)$r['canjeados'];
echo json_encode(['ganados' => $ganados, 'canjeados' => $canjeados, 'saldo' => $ganados - $canjeados]);
