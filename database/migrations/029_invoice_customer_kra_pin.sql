-- Store client KRA PIN directly on the invoice (like customer_name / customer_phone)
-- so it prints even when client_id is not linked.
ALTER TABLE invoices ADD COLUMN customer_kra_pin VARCHAR(20) NULL AFTER customer_email;
