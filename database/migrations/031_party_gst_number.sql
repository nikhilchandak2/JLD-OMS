-- GST number on parties (required for new party creation; unique when set)
ALTER TABLE parties
  ADD COLUMN gst_number VARCHAR(15) NULL AFTER contact_person,
  ADD UNIQUE INDEX idx_parties_gst_number (gst_number);
