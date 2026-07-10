-- Add J L daga Mines & Minerals as third active company in header switcher
INSERT INTO companies (name, code, address, phone, email, contact_person, gst_number, pan_number, status)
SELECT 'J L daga Mines & Minerals', 'JL_DAGA_MINES_MINERALS', '', '', '', '', '', '', 'active'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM companies
    WHERE name = 'J L daga Mines & Minerals' OR code = 'JL_DAGA_MINES_MINERALS'
);

UPDATE companies
SET status = 'active', code = 'JL_DAGA_MINES_MINERALS'
WHERE name = 'J L daga Mines & Minerals';
