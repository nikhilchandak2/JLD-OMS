-- Extra columns for export_orders so form data maps to all placeholders.

ALTER TABLE export_orders ADD COLUMN consignee_address VARCHAR(500) DEFAULT NULL AFTER consignee;
ALTER TABLE export_orders ADD COLUMN notify_address VARCHAR(500) DEFAULT NULL AFTER notify_applicant;
ALTER TABLE export_orders ADD COLUMN product_item VARCHAR(255) DEFAULT NULL AFTER product_description;
ALTER TABLE export_orders ADD COLUMN total_bags VARCHAR(100) DEFAULT NULL AFTER packaging;
ALTER TABLE export_orders ADD COLUMN final_destination VARCHAR(255) DEFAULT NULL AFTER total_bags;
ALTER TABLE export_orders ADD COLUMN our_pi_no VARCHAR(100) DEFAULT NULL AFTER final_destination;
