-- Budget System Schema (v1)
-- Default charset uses utf8mb4 for Thai/English

CREATE TABLE units (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_code VARCHAR(50) NOT NULL UNIQUE,
  unit_name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  position_name VARCHAR(255) NULL,
  role ENUM('admin','clerk','officer') NOT NULL DEFAULT 'clerk',
  unit_id INT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  fiscal_year INT NOT NULL,
  base_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_unit_year (unit_id, fiscal_year),
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(50) NOT NULL UNIQUE,
  doc_date DATE NOT NULL,
  fiscal_year INT NOT NULL,
  unit_id INT NOT NULL,
  transfer_round TINYINT NOT NULL DEFAULT 1,
  installment_code VARCHAR(20) NOT NULL DEFAULT '1',
  paying_unit_id INT NULL,
  receive_date DATE NULL,
  control_account_no VARCHAR(50) NULL,
  budget_code VARCHAR(100) NULL,
  source_fund VARCHAR(100) NULL,
  expense_category VARCHAR(100) NULL,
  item_type VARCHAR(100) NULL,
  subject VARCHAR(255) NULL,
  item_name TEXT NULL,
  gfmis_code VARCHAR(50) NULL,
  gfmis_date DATE NULL,
  activity_code VARCHAR(50) NULL,
  budget_types VARCHAR(255) NULL,
  total_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (paying_unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (transfer_id) REFERENCES budget_transfers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_disbursements (
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

CREATE TABLE control_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fiscal_year INT NOT NULL,
  unit_id INT NOT NULL,
  source_round TINYINT NULL,
  source_transfer_id INT NULL,
  budget_code VARCHAR(50) NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  army_code VARCHAR(50) NULL,
  gfmis_code VARCHAR(50) NULL,
  activity_code VARCHAR(50) NULL,
  installment_no VARCHAR(20) NULL,
  received_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  disbursed_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  returned_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  status ENUM('draft','ready','exported') NOT NULL DEFAULT 'draft',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_control_unit_year (unit_id, fiscal_year),
  KEY idx_control_status (status),
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (source_transfer_id) REFERENCES budget_transfers(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  fiscal_year INT NOT NULL,
  trans_date DATE NOT NULL,
  trans_type ENUM('base','transfer_in','transfer_out','use','advance','po_reserve','payment') NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  ref_table VARCHAR(50) NULL,
  ref_id INT NULL,
  remark VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_unit_year (unit_id, fiscal_year),
  KEY idx_type (trans_type),
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_vouchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  voucher_no VARCHAR(50) NOT NULL UNIQUE,
  voucher_date DATE NOT NULL,
  unit_id INT NOT NULL,
  fiscal_year INT NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  status ENUM('received','paid') NOT NULL DEFAULT 'received',
  received_by INT NULL,
  paid_by INT NULL,
  paid_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (received_by) REFERENCES users(id),
  FOREIGN KEY (paid_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_advances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(50) NOT NULL UNIQUE,
  doc_date DATE NOT NULL,
  unit_id INT NOT NULL,
  fiscal_year INT NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  purpose VARCHAR(255) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE po_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(50) NOT NULL UNIQUE,
  po_no VARCHAR(50) NULL,
  doc_date DATE NOT NULL,
  unit_id INT NOT NULL,
  fiscal_year INT NOT NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  vendor VARCHAR(255) NULL,
  purpose VARCHAR(255) NULL,
  status ENUM('reserved','released') NOT NULL DEFAULT 'reserved',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_counters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_type VARCHAR(30) NOT NULL,
  fiscal_year INT NOT NULL,
  unit_id INT NOT NULL,
  last_no INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_counter (doc_type, fiscal_year, unit_id),
  FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_signers (
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
  FOREIGN KEY (unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE voucher_signatures (
  voucher_id INT NOT NULL,
  signer_key VARCHAR(30) NOT NULL,
  signer_id INT NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  position_name VARCHAR(255) NOT NULL,
  selected_by INT NULL,
  selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (voucher_id, signer_key),
  FOREIGN KEY (voucher_id) REFERENCES payment_vouchers(id) ON DELETE CASCADE,
  FOREIGN KEY (signer_id) REFERENCES document_signers(id),
  FOREIGN KEY (selected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE control_account_entries (
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
  FOREIGN KEY (control_account_id) REFERENCES control_accounts(id),
  FOREIGN KEY (spending_unit_id) REFERENCES units(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
