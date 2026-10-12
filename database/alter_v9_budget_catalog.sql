-- Additive catalog. Do not infer or overwrite legacy classifications.
CREATE TABLE IF NOT EXISTS budget_catalog_nodes (
 id INT AUTO_INCREMENT PRIMARY KEY,
 node_kind ENUM('strategy','plan','activity','group','work') NOT NULL,
 node_code VARCHAR(50) NOT NULL,
 node_name VARCHAR(255) NOT NULL,
 parent_id INT NULL,
 scope_parent INT GENERATED ALWAYS AS (IFNULL(parent_id,0)) STORED,
 active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY catalog_code (node_kind,scope_parent,node_code),
 FOREIGN KEY (parent_id) REFERENCES budget_catalog_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS budget_work_assignments (
 work_id INT NOT NULL,
 secondary_unit_id INT NOT NULL,
 program_id INT NOT NULL UNIQUE,
 active TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY (work_id,secondary_unit_id),
 FOREIGN KEY (work_id) REFERENCES budget_catalog_nodes(id),
 FOREIGN KEY (secondary_unit_id) REFERENCES units(id),
 FOREIGN KEY (program_id) REFERENCES budget_programs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
