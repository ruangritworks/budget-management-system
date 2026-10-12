-- Additive migration. Apply after v7; never reload schema.sql over existing data.
-- Existing units remain unchanged and are treated as sub-units until classified.
CREATE TABLE IF NOT EXISTS unit_structure (
 unit_id INT PRIMARY KEY,
 parent_unit_id INT NULL,
 unit_level ENUM('main','sub','secondary') NOT NULL DEFAULT 'sub',
 cost_center VARCHAR(50) NOT NULL DEFAULT '',
 active TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY (unit_id) REFERENCES units(id),
 FOREIGN KEY (parent_unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS expense_categories (
 code CHAR(3) PRIMARY KEY,
 category_name VARCHAR(255) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS budget_programs (
 id INT AUTO_INCREMENT PRIMARY KEY,
 program_code VARCHAR(50) NOT NULL UNIQUE,
 responsible_unit_id INT NOT NULL,
 strategy_no VARCHAR(20) NOT NULL,
 strategy_name VARCHAR(255) NOT NULL DEFAULT '',
 plan_name VARCHAR(255) NOT NULL,
 activity_name VARCHAR(255) NOT NULL,
 work_group VARCHAR(255) NOT NULL,
 project_name VARCHAR(255) NOT NULL DEFAULT '',
 active TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY (responsible_unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS budget_classifications (
 control_account_id INT PRIMARY KEY,
 main_unit_id INT NOT NULL,
 sub_unit_id INT NOT NULL,
 secondary_unit_id INT NOT NULL,
 program_id INT NOT NULL,
 expense_code CHAR(3) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 created_by INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (control_account_id) REFERENCES control_accounts(id),
 FOREIGN KEY (main_unit_id) REFERENCES units(id),
 FOREIGN KEY (sub_unit_id) REFERENCES units(id),
 FOREIGN KEY (secondary_unit_id) REFERENCES units(id),
 FOREIGN KEY (program_id) REFERENCES budget_programs(id),
 FOREIGN KEY (expense_code) REFERENCES expense_categories(code),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS budget_quarter_plans (
 control_account_id INT NOT NULL,
 quarter_no TINYINT NOT NULL,
 obligation_plan DECIMAL(16,2) NULL,
 payment_plan DECIMAL(16,2) NULL,
 output_unit VARCHAR(50) NOT NULL DEFAULT '',
 output_plan DECIMAL(16,2) NULL,
 output_actual DECIMAL(16,2) NULL,
 PRIMARY KEY (control_account_id,quarter_no),
 FOREIGN KEY (control_account_id) REFERENCES control_accounts(id),
 CHECK (quarter_no BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gfmis_payment_confirmations (
 ledger_entry_id INT PRIMARY KEY,
 confirmed_date DATE NOT NULL,
 confirmed_amount DECIMAL(16,2) NOT NULL,
 reference_no VARCHAR(100) NOT NULL,
 created_by INT NOT NULL,
 FOREIGN KEY (ledger_entry_id) REFERENCES control_account_entries(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
