-- RPBS v4: document workflow, signers and printable document fields
-- This migration only adds fields/tables and preserves existing data.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS position_name VARCHAR(255) NULL AFTER full_name;

ALTER TABLE budget_transfers
  ADD COLUMN IF NOT EXISTS paying_unit_id INT NULL AFTER transfer_round,
  ADD COLUMN IF NOT EXISTS receive_date DATE NULL AFTER paying_unit_id,
  ADD COLUMN IF NOT EXISTS control_account_no VARCHAR(50) NULL AFTER receive_date,
  ADD COLUMN IF NOT EXISTS budget_code VARCHAR(100) NULL AFTER control_account_no,
  ADD COLUMN IF NOT EXISTS source_fund VARCHAR(100) NULL AFTER budget_code,
  ADD COLUMN IF NOT EXISTS expense_category VARCHAR(100) NULL AFTER source_fund,
  ADD COLUMN IF NOT EXISTS item_type VARCHAR(100) NULL AFTER expense_category,
  ADD COLUMN IF NOT EXISTS item_name TEXT NULL AFTER subject,
  ADD COLUMN IF NOT EXISTS activity_code VARCHAR(50) NULL AFTER gfmis_date,
  ADD COLUMN IF NOT EXISTS budget_types VARCHAR(255) NULL AFTER activity_code;

SET @paying_fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'budget_transfers'
    AND CONSTRAINT_NAME = 'fk_budget_transfers_paying_unit'
);
SET @paying_fk_sql = IF(
  @paying_fk_exists = 0,
  'ALTER TABLE budget_transfers ADD CONSTRAINT fk_budget_transfers_paying_unit FOREIGN KEY (paying_unit_id) REFERENCES units(id)',
  'SELECT 1'
);
PREPARE paying_fk_stmt FROM @paying_fk_sql;
EXECUTE paying_fk_stmt;
DEALLOCATE PREPARE paying_fk_stmt;

ALTER TABLE document_counters
  MODIFY COLUMN doc_type VARCHAR(30) NOT NULL;

CREATE TABLE IF NOT EXISTS document_signers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  document_type VARCHAR(30) NOT NULL,
  signer_key VARCHAR(30) NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  position_name VARCHAR(255) NOT NULL,
  display_order INT NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_document_signer (unit_id, document_type, signer_key),
  KEY idx_signer_lookup (unit_id, document_type, active),
  CONSTRAINT fk_document_signers_unit FOREIGN KEY (unit_id) REFERENCES units(id),
  CONSTRAINT fk_document_signers_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

