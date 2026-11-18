-- Script de actualización de usuarios para entorno remoto (Nube)
ALTER TABLE t_usuarios MODIFY COLUMN rol ENUM('SUPER', 'ADMINISTRADOR', 'SUPERVISOR', 'OPERADOR', 'REMOTO') NOT NULL DEFAULT 'OPERADOR';

INSERT INTO t_usuarios (id, empresa_id, nombre, usuario, email, clave_hash, rol, activo) VALUES (1, 1, 'super', 'super', 'super@super.local', '$argon2id$v=19$m=65536,t=4,p=1$bjZIL1JoOENCbkg3R05hLw$TM46jCOtS8ccsvfZni0jZdG+LzzvNMAJIxpKLbBq6OU', 'SUPER', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email), clave_hash = VALUES(clave_hash), rol = VALUES(rol), activo = VALUES(activo);

INSERT INTO t_usuarios (id, empresa_id, nombre, usuario, email, clave_hash, rol, activo) VALUES (2, 1, 'María José', 'maria.jose', 'maria.jose@bascula.local', '$2y$12$d12QvYNWMKcFKDpdTz7BgugwkflZsMrjQC8i040RLpic/qFWHH1jm', 'REMOTO', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email), clave_hash = VALUES(clave_hash), rol = VALUES(rol), activo = VALUES(activo);

