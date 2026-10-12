-- Create table for round-2 disbursement reports linked to round-1 transfers
CREATE TABLE IF NOT EXISTS budget_disbursements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_transfer_id INT NOT NULL,
  unit_id INT NOT NULL,
  fiscal_year INT NOT NULL,
  report_no VARCHAR(50) NOT NULL,
  report_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_report_no (report_no),
  KEY idx_disbursement_unit_year (unit_id, fiscal_year),
  FOREIGN KEY (source_transfer_id) REFERENCES budget_transfers(id),
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
