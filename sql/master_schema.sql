-- BD MAESTRA DEL PORTAL (la que indican DB_HOST/DB_NAME en las variables de entorno).
-- Se crea/actualiza sola desde /instalar.php. Es idempotente (CREATE TABLE IF NOT EXISTS).
-- Cada instalación (colegio) vive en SU PROPIA base de datos; aquí solo se registra.

CREATE TABLE IF NOT EXISTS sesiones (
  id VARCHAR(128) PRIMARY KEY,
  datos MEDIUMTEXT NOT NULL,
  expira DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS portal_superadmins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL DEFAULT '',
  password VARCHAR(255) NOT NULL,
  creado DATETIME NOT NULL,
  ultimo_acceso DATETIME NULL
);

CREATE TABLE IF NOT EXISTS portal_admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL DEFAULT '',
  usuario VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL,
  creado_por VARCHAR(50) NULL,
  ultimo_acceso DATETIME NULL
);

CREATE TABLE IF NOT EXISTS portal_instalaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL UNIQUE,
  slug VARCHAR(40) NULL UNIQUE,
  nombre_colegio VARCHAR(150) NULL,
  db_name VARCHAR(64) NULL UNIQUE,
  estado ENUM('pendiente','activa','suspendida') NOT NULL DEFAULT 'pendiente',
  max_cursos INT NOT NULL DEFAULT 10,      -- 0 = sin límite
  max_usuarios INT NOT NULL DEFAULT 20,    -- docentes + administradores; 0 = sin límite
  vence DATETIME NULL,                     -- fin de la vigencia (prueba); NULL = sin vencimiento
  cursos_count INT NOT NULL DEFAULT 0,
  alumnos_count INT NOT NULL DEFAULT 0,
  usuarios_count INT NOT NULL DEFAULT 0,
  tamano_mb DECIMAL(10,2) NOT NULL DEFAULT 0,
  ultimo_acceso_colegio DATETIME NULL,
  stats_actualizadas DATETIME NULL,
  creada DATETIME NOT NULL,
  configurada DATETIME NULL,
  FOREIGN KEY (admin_id) REFERENCES portal_admins(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portal_intentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(190) NOT NULL,
  fecha DATETIME NOT NULL,
  INDEX idx_intentos (clave, fecha)
);

CREATE TABLE IF NOT EXISTS portal_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATETIME NOT NULL,
  actor VARCHAR(50) NOT NULL,
  accion VARCHAR(50) NOT NULL,
  detalle VARCHAR(255) NOT NULL DEFAULT ''
);
