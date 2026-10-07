-- ESQUEMA DE CADA COLEGIO (una base de datos por instalacion). Se ejecuta al terminar el asistente de configuracion.
-- Cada sentencia termina en punto y coma al final de la linea. No uses punto y coma dentro de textos.

CREATE TABLE asignaturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL
);

CREATE TABLE maestros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL DEFAULT '',
  apellido VARCHAR(100) NOT NULL DEFAULT '',
  usuario VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  clave_texto VARCHAR(100) NULL,
  rol ENUM('admin','docente') NOT NULL DEFAULT 'docente',
  asignatura_id INT NULL,
  FOREIGN KEY (asignatura_id) REFERENCES asignaturas(id) ON DELETE SET NULL
);

CREATE TABLE cursos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL
);

CREATE TABLE alumnos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  curso_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  nfc_uid VARCHAR(100) NULL UNIQUE,
  qr_code VARCHAR(40) NULL UNIQUE,
  qr_apoderado VARCHAR(40) NULL UNIQUE,
  FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
);

CREATE TABLE categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  docente_id INT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_categorias_docente (docente_id)
);

CREATE TABLE config (
  clave VARCHAR(50) PRIMARY KEY,
  valor TEXT NOT NULL
);

CREATE TABLE recursos (
  clave VARCHAR(30) PRIMARY KEY,
  mime VARCHAR(60) NOT NULL,
  datos MEDIUMBLOB NOT NULL,
  actualizado DATETIME NOT NULL
);

CREATE TABLE registro_puntos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alumno_id INT NOT NULL,
  categoria_id INT NOT NULL,
  puntos TINYINT NOT NULL,
  fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  maestro_id INT NULL,
  asignatura_id INT NULL,
  masivo TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT,
  FOREIGN KEY (maestro_id) REFERENCES maestros(id) ON DELETE SET NULL,
  FOREIGN KEY (asignatura_id) REFERENCES asignaturas(id) ON DELETE SET NULL
);

CREATE TABLE opciones_canje (
  id INT AUTO_INCREMENT PRIMARY KEY,
  docente_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  costo_puntos INT NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (docente_id) REFERENCES maestros(id) ON DELETE CASCADE
);

CREATE TABLE canjes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alumno_id INT NOT NULL,
  puntos_virtuales INT NOT NULL,
  puntos_base INT NOT NULL,
  observacion VARCHAR(200) NULL DEFAULT '',
  fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  opcion_id INT NULL,
  nombre_opcion VARCHAR(100) NULL,
  maestro_id INT NULL,
  asignatura_id INT NULL,
  FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
  FOREIGN KEY (opcion_id) REFERENCES opciones_canje(id) ON DELETE SET NULL,
  FOREIGN KEY (maestro_id) REFERENCES maestros(id) ON DELETE SET NULL,
  FOREIGN KEY (asignatura_id) REFERENCES asignaturas(id) ON DELETE SET NULL
);

CREATE TABLE metas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  curso_id INT NOT NULL,
  semana_inicio DATE NOT NULL,
  puntos_objetivo INT NOT NULL,
  creado_por INT NULL,
  fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  descripcion VARCHAR(255) NULL,
  categoria_id INT NULL,
  UNIQUE KEY uq_curso_semana_docente (curso_id, semana_inicio, creado_por),
  FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
  FOREIGN KEY (creado_por) REFERENCES maestros(id) ON DELETE SET NULL,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
);

CREATE TABLE frases_refuerzo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  frase TEXT NOT NULL,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
);

CREATE TABLE log_sesiones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  maestro_id INT NULL,
  usuario VARCHAR(50) NOT NULL,
  fecha DATETIME NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  pais VARCHAR(255) NULL,
  region VARCHAR(255) NULL,
  ciudad VARCHAR(255) NULL,
  INDEX idx_log_fecha (fecha),
  INDEX idx_log_usuario (usuario),
  FOREIGN KEY (maestro_id) REFERENCES maestros(id) ON DELETE SET NULL
);

INSERT INTO categorias (nombre) VALUES ('Excelente actitud'),('Participación'),('Tarea completada');

INSERT INTO frases_refuerzo (categoria_id, frase) VALUES
(1,'¡Tu buena actitud inspira a todo el curso!'),
(1,'¡Así se hace, sigue con esa energía positiva!'),
(1,'Gracias por ser un ejemplo para tus compañeros.'),
(2,'¡Tu opinión enriquece la clase, gracias por participar!'),
(2,'¡Excelente aporte, sigue levantando la mano!'),
(2,'Participar es aprender: ¡muy bien!'),
(3,'¡Tarea cumplida, el esfuerzo constante da frutos!'),
(3,'¡Qué buen trabajo, gracias por tu responsabilidad!'),
(3,'Cada tarea completada te acerca a tus metas.');

INSERT INTO config (clave, valor) VALUES ('tasa_canje','10');
