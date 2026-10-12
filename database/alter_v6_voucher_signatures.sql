CREATE TABLE IF NOT EXISTS voucher_signatures (
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
