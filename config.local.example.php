<?php
// Copia este archivo a config.local.php (NO lo subas a git) para probar en local (XAMPP/MySQL propio)
// sin tocar el código. En Vercel se usan variables de entorno equivalentes.
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_NAME=portal_nfc');          // BD maestra del portal (créala vacía antes: CREATE DATABASE portal_nfc;)
putenv('DB_USER=root');                // debe poder crear bases de datos (una por colegio)
putenv('DB_PASS=');
putenv('DB_SSL=0');                    // 0 = sin SSL (local). En Aiven déjalo en 1 (por defecto) y conserva certs/ca.pem
putenv('INSTALL_KEY=cambia-esta-frase-secreta');
// putenv('TENANT_DB_PREFIX=nfc_');    // prefijo de las bases de datos de cada colegio
// putenv('PORTAL_NOMBRE=Portal Sistema de Puntos');
