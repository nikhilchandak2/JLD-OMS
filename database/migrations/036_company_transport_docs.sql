-- Company-specific transport documents: JLD Minerals uses E-way bill; others use Rawana

ALTER TABLE companies
  ADD COLUMN transport_doc_type ENUM('rawana', 'eway_bill') NOT NULL DEFAULT 'rawana' AFTER status;

ALTER TABLE dispatches
  ADD COLUMN rawana_no VARCHAR(100) NULL AFTER vehicle_no,
  ADD COLUMN eway_bill_no VARCHAR(100) NULL AFTER rawana_no;

UPDATE companies
SET transport_doc_type = 'eway_bill'
WHERE code = 'JLD_MINERALS_PRIVATE_LIMITED'
   OR name LIKE 'JLD Minerals%';

UPDATE companies
SET transport_doc_type = 'rawana'
WHERE transport_doc_type IS NULL OR transport_doc_type = 'rawana';
