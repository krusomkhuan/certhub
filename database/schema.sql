CREATE TABLE IF NOT EXISTS admins (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL,
 display_name VARCHAR(100) NOT NULL DEFAULT '', email VARCHAR(254) NOT NULL DEFAULT '',
 auth_version INT NOT NULL DEFAULT 0, role VARCHAR(32) NOT NULL DEFAULT 'admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS projects (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 title TEXT NOT NULL, date VARCHAR(10) NOT NULL, end_date VARCHAR(10) NOT NULL DEFAULT '',
 location TEXT NULL, description TEXT NULL, published TINYINT NOT NULL DEFAULT 0,
 owner TEXT NULL, objective TEXT NULL, results TEXT NULL, problems TEXT NULL, suggestions TEXT NULL,
 number_pattern VARCHAR(100) NOT NULL DEFAULT 'CH-{project}-{seq}', number_next BIGINT NOT NULL DEFAULT 1,
 number_width INT NOT NULL DEFAULT 4, event_type VARCHAR(32) NOT NULL DEFAULT 'training'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL,
 role VARCHAR(32) NOT NULL, heading TEXT NOT NULL, signer TEXT NULL, position TEXT NULL,
 color VARCHAR(7) NOT NULL DEFAULT '#235d50', canva TEXT NULL, background VARCHAR(64) NULL,
 UNIQUE KEY project_role_unique(project_id,role),
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS recipients (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(200) NOT NULL, organization TEXT NULL, role VARCHAR(32) NOT NULL,
 token VARCHAR(64) NOT NULL UNIQUE, issued TINYINT NOT NULL DEFAULT 0,
 certificate_number VARCHAR(255) NULL, central_sequence BIGINT UNSIGNED NULL, sequence_year INT NULL,
 issued_at VARCHAR(40) NULL, record_only TINYINT NOT NULL DEFAULT 0,
 UNIQUE KEY project_name_role_unique(project_id,name,role),
 UNIQUE KEY certificate_number_unique(certificate_number), UNIQUE KEY year_sequence_unique(sequence_year,central_sequence),
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS photos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL,
 filename VARCHAR(64) NOT NULL, caption TEXT NULL,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS settings (name VARCHAR(100) PRIMARY KEY, value LONGTEXT NOT NULL)
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS certificate_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, token VARCHAR(64) NOT NULL UNIQUE,
 requester TEXT NOT NULL, department TEXT NOT NULL, contact TEXT NOT NULL, title TEXT NOT NULL,
 event_type VARCHAR(32) NOT NULL, event_date VARCHAR(10) NOT NULL, purpose TEXT NOT NULL,
 names LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', submitted_at VARCHAR(40) NOT NULL,
 reviewed_at VARCHAR(40) NULL, reviewed_by BIGINT UNSIGNED NULL, review_note TEXT NULL,
 project_id BIGINT UNSIGNED NULL, quantity INT NOT NULL DEFAULT 1,
 FOREIGN KEY(project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
INSERT INTO settings(name,value) VALUES ('central_pattern','วอ.โพนทอง/{year}/{seq}'),('central_next','1'),('central_width','5'),('schema_version','mysql-2') ON DUPLICATE KEY UPDATE name=VALUES(name);
