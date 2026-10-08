<<<<<<< HEAD
# Portal Sistema de Puntos NFC/QR — multi-colegio

Versión genérica (sin marca de ningún colegio) del sistema de puntos por asignatura/curso con NFC o QR.
Funciona como **portal**: un **superadmin** crea administradores; cada administrador configura su colegio la
primera vez que ingresa y se le crea **una base de datos exclusiva**.

## Cómo funciona

| Rol | Entra por | Qué hace |
|---|---|---|
| Superadmin | `/` (usuario + contraseña) → `/superadmin.php` | Crea administradores (genera su clave), ve todas las instalaciones, sus BD, cursos/usuarios/alumnos, fija límites, suspende, regenera claves, elimina |
| Administrador de colegio | `/` con la clave recibida | 1ª vez: asistente `/setup.php` (nombre, colores, logo, ícono, mascota, nombre del chatbot, código del colegio…). Luego: su colegio + **Configuración** para editar todo |
| Docentes | `/e/{codigo-colegio}/` (o escribiendo el código en la portada) | Usan el sistema normal (puntos, canje, metas, reportes) |

Aislamiento: cada colegio vive en `/e/{codigo}/…` con su propia BD (`nfc_{codigo}`); la sesión solo vale en el colegio donde se inició.
Imágenes y configuración se guardan **en la BD del colegio** (tablas `config` y `recursos`), no en archivos.

## Arranque rápido (Vercel + Aiven/MySQL)
1. Crea una BD MySQL vacía para el portal (ej. `defaultdb` en Aiven). El usuario debe poder **crear bases de datos** (Aiven `avnadmin` puede).
2. Variables de entorno en Vercel: `DB_HOST`, `DB_PORT`, `DB_NAME` (BD maestra), `DB_USER`, `DB_PASS`, **`INSTALL_KEY`** (frase secreta larga).
   Opcionales: `TENANT_DB_PREFIX` (def. `nfc_`), `PORTAL_NOMBRE`, `DB_SSL=0` (solo MySQL local sin SSL).
3. Despliega y abre `https://TU-DOMINIO/instalar.php`: escribe `INSTALL_KEY` y crea el **primer superadmin**. Luego puedes quitar `INSTALL_KEY`.
4. Entra en `/`, ve al panel, crea un administrador y entrégale la dirección, el usuario y la clave que se muestra (solo se ve una vez).

Local: copia `config.local.example.php` a `config.local.php`.

## Límites por instalación
Los fija el superadmin al crear al administrador y puede cambiarlos cuando quiera: **máx. cursos** y **máx. usuarios** (docentes + admins); `0` = sin límite.
Se validan en el servidor (crear curso, carga masiva CSV, crear maestro). Los contadores del panel se actualizan solos y con «Actualizar».

## Estructura
- `api/index.php` — router único (portal + `/e/{colegio}/`), compatible con el límite de funciones de Vercel Hobby.
- `portal/` — login del portal, asistente `setup.php`, `superadmin.php`, `instalar.php`, aprovisionamiento (`lib.php`).
- `paginas/` — aplicación de cada colegio; `branding.php` (temas, imágenes, formulario), `configuracion.php`.
- `sql/master_schema.sql` (BD del portal) y `sql/tenant_schema.sql` (esquema de cada colegio; se aplica al crear la instalación).
- `assets/default/` — logo, ícono y mascota genéricos que se usan hasta que el colegio suba los suyos.
- `vercel.json` bloquea `/paginas`, `/portal` y `/sql` desde el navegador.

## Notas
- Si la creación del colegio falla a medias, la BD recién creada se elimina automáticamente.
- Eliminar una instalación desde el panel ejecuta `DROP DATABASE` (pide escribir el código para confirmar).
- Los QR de apoderados llevan el código del colegio en la URL (`/e/{codigo}/reporte_apoderado.php?token=…`).
- Intentos de login: bloqueo de 15 min tras 8 fallos (por usuario + IP) en portal y colegios.
=======
# Plataforma-sistemapuntosqr
Portal multiescuela con configuración dinámica
>>>>>>> 45f7da7f616261605fdda9258f6f8149732bb046
