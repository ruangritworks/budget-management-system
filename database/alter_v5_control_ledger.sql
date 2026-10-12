-- RPBS v5: installment selection and detailed GFMIS control-account ledger
ALTER TABLE budget_transfers
  ADD COLUMN IF NOT EXISTS installment_code VARCHAR(20) NOT NULL DEFAULT '1' AFTER transfer_round;

CREATE TABLE IF NOT EXISTS control_account_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  control_account_id INT NOT NULL,
  entry_date DATE NOT NULL,
  gfmis_code VARCHAR(50) NULL,
  activity_code VARCHAR(50) NULL,
  disbursement_no INT NOT NULL DEFAULT 1,
  entry_type ENUM('increase','transfer_out','return_army','carry','request','obligation','payment','remittance') NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  spending_unit_id INT NULL,
  expense_category VARCHAR(3) NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_control_entry_account_date (control_account_id, entry_date, id),
  KEY idx_control_entry_type (entry_type),
  CONSTRAINT fk_control_entry_account FOREIGN KEY (control_account_id) REFERENCES control_accounts(id),
  CONSTRAINT fk_control_entry_unit FOREIGN KEY (spending_unit_id) REFERENCES units(id),
  CONSTRAINT fk_control_entry_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

