-- Add proof of delivery metadata to orders table
ALTER TABLE orders
  ADD COLUMN proof_of_delivery_image VARCHAR(255) NULL,
  ADD COLUMN proof_uploaded_at DATETIME NULL,
  ADD COLUMN proof_uploaded_by CHAR(36) NULL;

-- Optional index for proof upload lookups
CREATE INDEX idx_orders_proof_uploaded_at ON orders (proof_uploaded_at);
