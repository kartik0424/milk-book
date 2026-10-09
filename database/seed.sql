-- MilkBook Seed Data
-- 8 sample customers, standard milk rate history, and default settings

USE `milkbook`;

-- 1. Default Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('business_name', 'Krishna Dairy Farm'),
('business_phone', '9876543210'),
('business_address', 'Plot 12, Gokul Dairy Road, Anand, Gujarat - 388001'),
('upi_id', 'krishnadairy@okaxis'),
('invoice_prefix', 'MB-'),
('invoice_footer', 'Thank you for choosing pure & fresh milk! Kindly clear dues by the 5th of each month.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 2. Initial Milk Rates
-- Historical rate and current standard rate
INSERT INTO `milk_rates` (`rate_per_litre`, `effective_from`) VALUES
(62.00, '2025-07-01'),
(66.00, '2026-01-01');

-- 3. 8 Sample Customers for Milkman's Delivery Route
INSERT INTO `customers` (`name`, `address`, `mobile`, `committed_qty`, `custom_rate`, `route_order`, `start_date`, `is_active`, `notes`) VALUES
('Ramesh Sharma', 'Flat 101, Gokul Dham Society, Near Water Tank', '9823011223', 1.50, NULL, 1, '2026-01-01', 1, 'Prefers early delivery before 6:30 AM'),
('Sunita Patel', 'B-12, Shanti Niketan Apartments, 2nd Floor', '9879022334', 1.00, NULL, 2, '2026-01-01', 1, 'Keep milk packet in the wire basket outside door'),
('Rajesh Verma', 'House 45, Green Park Colony, Main Road', '9898033445', 2.00, 70.00, 3, '2026-01-05', 1, 'Custom rate for Pure Cow Milk, 2L daily'),
('Anita Rao', 'Flat 304, Krishna Heights, Sector 4', '9822044556', 0.75, NULL, 4, '2026-01-10', 1, 'Ring bell twice'),
('Vikram Singh', 'Plot 8, Royal Enclave, Near Park Gate', '9811055667', 2.50, NULL, 5, '2026-01-15', 1, 'Large joint family, morning & evening split'),
('Priya Deshmukh', 'Flat 202, Sai Vihar, Station Road', '9845066778', 1.25, NULL, 6, '2026-02-01', 1, 'Monthly payment via UPI'),
('Mohammed Farooq', 'House 17, Gulshan Colony, Lane 3', '9833077889', 1.50, 72.00, 7, '2026-02-10', 1, 'Custom rate for Buffalo Gold Rich milk'),
('Meena Joshi', 'A-3, Silver Oak Residency, Behind Bank', '9867088990', 0.50, NULL, 8, '2026-02-15', 1, 'Senior citizen, leave on doorstep table');
