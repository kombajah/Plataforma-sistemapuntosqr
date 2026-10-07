<?php
require_once 'conexion.php';
iniciar_sesion();

// 1. Limpiar variables de sesión
$_SESSION = [];

// 2. Eliminar la cookie de sesión en el navegador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destruir la sesión en la base de datos
session_destroy();

// 4. Redirigir al login
header("Location: index.php");
exit;