-- One shared budget signing officer; retain legacy signer IDs and signed snapshots.
-- Making unit_id nullable does not rewrite any existing signer or financial record.
ALTER TABLE document_signers MODIFY COLUMN unit_id INT NULL;
ALTER TABLE document_signers
 ADD COLUMN IF NOT EXISTS shared_signer_slot TINYINT
 GENERATED ALWAYS AS (CASE WHEN unit_id IS NULL THEN 1 ELSE NULL END) STORED;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_shared_budget_signer
 ON document_signers(shared_signer_slot);
