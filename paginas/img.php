<?php
// Sirve las imágenes de marca del colegio (logo, icono, mascota) guardadas en su BD.
// Si no se subió una, usa la imagen genérica de assets/default/. Es pública (la usa el login).
$r = $_GET['r'] ?? '';
if (!isset(BRAND_RECURSOS[$r])) { http_response_code(404); exit; }
$s = $conn->prepare("SELECT mime, datos FROM recursos WHERE clave=?");
$s->bind_param("s", $r); $s->execute();
$f = $s->get_result()->fetch_assoc();
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
if ($f) { header('Content-Type: ' . $f['mime']); echo $f['datos']; exit; }
header('Content-Type: image/svg+xml');
readfile(__DIR__ . '/../assets/default/' . $r . '.svg');
