ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `payment_reference` VARCHAR(255) NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `contact_number` VARCHAR(50) NULL AFTER `payment_reference`;
