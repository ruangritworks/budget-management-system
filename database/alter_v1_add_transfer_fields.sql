-- Add new fields for budget transfer form (v1.1)
-- Safe to re-run on existing DB.
ALTER TABLE budget_transfers
  ADD COLUMN IF NOT EXISTS paying_unit_id INT NULL AFTER transfer_round,
  ADD COLUMN IF NOT EXISTS receive_date DATE NULL AFTER paying_unit_id,
  ADD COLUMN IF NOT EXISTS item_name TEXT NULL AFTER subject,
  ADD COLUMN IF NOT EXISTS activity_code VARCHAR(50) NULL AFTER gfmis_date,
  ADD COLUMN IF NOT EXISTS budget_types VARCHAR(255) NULL AFTER activity_code;

SET @fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'budget_transfers'
    AND CONSTRAINT_NAME = 'fk_budget_transfers_paying_unit'
);

SET @fk_sql = IF(
  @fk_exists = 0,
  'ALTER TABLE budget_transfers ADD CONSTRAINT fk_budget_transfers_paying_unit FOREIGN KEY (paying_unit_id) REFERENCES units(id)',
  'SELECT ''fk_budget_transfers_paying_unit exists'''
);

PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
