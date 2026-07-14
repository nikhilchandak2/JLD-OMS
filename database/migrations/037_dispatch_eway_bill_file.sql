-- E-way bill PDF/image upload per dispatch (one file per truck dispatch)

ALTER TABLE dispatches
  ADD COLUMN eway_bill_file_path VARCHAR(500) NULL AFTER eway_bill_no;
