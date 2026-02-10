-- ============================================================
-- Dolibarr Flotte Module Database Structure
-- Tables for fleet management system
-- Generated: 2026-02-10 - UPDATED VERSION with HRM Integration
-- ============================================================

-- Table structure for llx_flotte_vehicle
-- Stores vehicle information including make, model, year, and maintenance details
CREATE TABLE IF NOT EXISTS llx_flotte_vehicle (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  make VARCHAR(255) DEFAULT NULL,
  model VARCHAR(255) DEFAULT NULL,
  type VARCHAR(50) DEFAULT NULL,
  year INT(4) DEFAULT NULL,
  mileage INT(11) DEFAULT NULL,
  hours_used INT(11) DEFAULT NULL,
  purchase_price DECIMAL(10,2) DEFAULT NULL,
  current_value DECIMAL(10,2) DEFAULT NULL,
  license_fee DECIMAL(10,2) DEFAULT NULL,
  insurance_cost DECIMAL(10,2) DEFAULT NULL,
  maintenance_cost DECIMAL(10,2) DEFAULT NULL,
  date_created DATE DEFAULT NULL,
  vehicle_image1 VARCHAR(255) DEFAULT NULL,
  vehicle_image2 VARCHAR(255) DEFAULT NULL,
  vehicle_image3 VARCHAR(255) DEFAULT NULL,
  vehicle_image4 VARCHAR(255) DEFAULT NULL,
  insurance_exp_date DATE DEFAULT NULL,
  vendor_id INT(11) DEFAULT NULL,
  ownership_status VARCHAR(50) DEFAULT NULL,
  fuel_type VARCHAR(50) DEFAULT NULL,
  fuel_capacity VARCHAR(50) DEFAULT NULL,
  color VARCHAR(50) DEFAULT NULL,
  vin VARCHAR(100) DEFAULT NULL,
  license_plate VARCHAR(50) DEFAULT NULL,
  registration_exp_date DATE DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  fk_user_creat INT(11) DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_vehicle_ref (ref, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_booking
-- Stores vehicle booking/rental information
CREATE TABLE IF NOT EXISTS llx_flotte_booking (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  vehicle_id INT(11) DEFAULT NULL,
  customer_id INT(11) DEFAULT NULL,
  driver_id INT(11) DEFAULT NULL,
  booking_date DATE DEFAULT NULL,
  status VARCHAR(50) DEFAULT NULL,
  start_mileage INT(11) DEFAULT NULL,
  start_location VARCHAR(255) DEFAULT NULL,
  end_location VARCHAR(255) DEFAULT NULL,
  rental_amount DECIMAL(10,2) DEFAULT NULL,
  total_amount DECIMAL(10,2) DEFAULT NULL,
  fk_user_creat INT(11) DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_booking_ref (ref, entity),
  KEY idx_flotte_booking_vehicle (vehicle_id),
  KEY idx_flotte_booking_customer (customer_id),
  KEY idx_flotte_booking_driver (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_customer
-- Stores customer information for fleet services
CREATE TABLE IF NOT EXISTS llx_flotte_customer (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  firstname VARCHAR(255) DEFAULT NULL,
  lastname VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  mobile VARCHAR(50) DEFAULT NULL,
  company VARCHAR(255) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  gender VARCHAR(20) DEFAULT NULL,
  fk_user_creat INT(11) DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_customer_ref (ref, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_driver
-- Stores driver information including licenses and documents
-- UPDATED: Now integrates with HRM module via fk_user field
CREATE TABLE IF NOT EXISTS llx_flotte_driver (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `fk_user` int DEFAULT NULL,
  `firstname` varchar(128) DEFAULT NULL,
  `middlename` varchar(128) DEFAULT NULL,
  `lastname` varchar(128) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `contract_number` varchar(50) DEFAULT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `license_issue_date` date DEFAULT NULL,
  `license_expiry_date` date DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `leave_date` date DEFAULT NULL,
  `password` varchar(128) DEFAULT NULL,
  `department` varchar(128) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `driver_image` varchar(255) DEFAULT NULL,
  `documents` varchar(255) DEFAULT NULL,
  `license_image` varchar(255) DEFAULT NULL,
  `emergency_contact` varchar(255) DEFAULT NULL,
  `fk_vehicle` int DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `datec` datetime DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_flotte_driver_user_entity` (`fk_user`,`entity`),
  KEY `idx_flotte_driver_fk_user` (`fk_user`),
  CONSTRAINT `fk_flotte_driver_user` FOREIGN KEY (`fk_user`) REFERENCES `llx_user` (`rowid`) ON DELETE RESTRICT RESTRICT  -- NEW: Data integrity
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for llx_flotte_fuel
-- Stores fuel consumption and refueling records
CREATE TABLE IF NOT EXISTS llx_flotte_fuel (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  vehicle_id INT(11) DEFAULT NULL,
  fuel_date DATE DEFAULT NULL,
  odometer_reading INT(11) DEFAULT NULL,
  reference VARCHAR(100) DEFAULT NULL,
  status VARCHAR(50) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  qty_liters DECIMAL(10,2) DEFAULT NULL,
  fuel_station VARCHAR(255) DEFAULT NULL,
  quantity DECIMAL(10,2) DEFAULT NULL,
  price_per_liter DECIMAL(10,2) DEFAULT NULL,
  fk_user_creat INT(11) DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_fuel_ref (ref, entity),
  KEY idx_flotte_fuel_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_inspection
-- Stores vehicle inspection records and checklist results
CREATE TABLE IF NOT EXISTS llx_flotte_inspection (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  vehicle_id INT(11) DEFAULT NULL,
  reference VARCHAR(100) DEFAULT NULL,
  odometer_reading INT(11) DEFAULT NULL,
  next_reading INT(11) DEFAULT NULL,
  fuel_level VARCHAR(10) DEFAULT NULL,
  fuel_level_end VARCHAR(10) DEFAULT NULL,
  inspection_date DATETIME DEFAULT NULL,
  next_inspection_date DATETIME DEFAULT NULL,
  check_exterior TINYINT(1) DEFAULT 0,
  check_interior TINYINT(1) DEFAULT 0,
  check_engine TINYINT(1) DEFAULT 0,
  check_fluid_levels TINYINT(1) DEFAULT 0,
  check_brakes TINYINT(1) DEFAULT 0,
  check_tires TINYINT(1) DEFAULT 0,
  check_lights TINYINT(1) DEFAULT 0,
  check_steering TINYINT(1) DEFAULT 0,
  check_suspension TINYINT(1) DEFAULT 0,
  check_battery TINYINT(1) DEFAULT 0,
  check_exhaust TINYINT(1) DEFAULT 0,
  check_wipers TINYINT(1) DEFAULT 0,
  check_horn TINYINT(1) DEFAULT 0,
  check_mirrors TINYINT(1) DEFAULT 0,
  check_seatbelts TINYINT(1) DEFAULT 0,
  check_airbags TINYINT(1) DEFAULT 0,
  check_ac_heating TINYINT(1) DEFAULT 0,
  check_windows TINYINT(1) DEFAULT 0,
  check_doors TINYINT(1) DEFAULT 0,
  check_trunk TINYINT(1) DEFAULT 0,
  fk_user_creat INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_inspection_ref (ref, entity),
  KEY idx_flotte_inspection_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_part
-- Stores spare parts inventory for fleet maintenance
CREATE TABLE IF NOT EXISTS llx_flotte_part (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  part_number VARCHAR(100) DEFAULT NULL,
  part_name VARCHAR(255) DEFAULT NULL,
  quantity VARCHAR(50) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  status VARCHAR(50) DEFAULT NULL,
  fk_product INT(11) DEFAULT NULL,
  vendor_id INT(11) DEFAULT NULL,
  vehicle_id INT(11) DEFAULT NULL,
  make VARCHAR(255) DEFAULT NULL,
  year INT(4) DEFAULT NULL,
  model VARCHAR(255) DEFAULT NULL,
  category_id INT(11) DEFAULT NULL,
  unit_cost DECIMAL(10,2) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  fk_user_creat INT(11) DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_part_ref (ref, entity),
  KEY idx_flotte_part_vendor (vendor_id),
  KEY idx_flotte_part_vehicle (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_vendor
-- Stores vendor/supplier information for fleet services and parts
-- CORRECTED: Column names match PHP code
CREATE TABLE IF NOT EXISTS llx_flotte_vendor (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  fk_soc INT(11) DEFAULT NULL,
  name VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  type VARCHAR(100) DEFAULT NULL,
  website VARCHAR(255) DEFAULT NULL,
  address1 VARCHAR(255) DEFAULT NULL,
  address2 VARCHAR(255) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  state VARCHAR(100) DEFAULT NULL,
  country VARCHAR(100) DEFAULT NULL,
  zip VARCHAR(20) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  picture VARCHAR(255) DEFAULT NULL,
  fk_user_author INT(11) DEFAULT NULL,
  datec DATETIME DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_vendor_ref (ref, entity),
  KEY idx_flotte_vendor_soc (fk_soc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for llx_flotte_workorder
-- Stores work orders for fleet maintenance and repairs
CREATE TABLE IF NOT EXISTS llx_flotte_workorder (
  rowid INT(11) NOT NULL AUTO_INCREMENT,
  ref VARCHAR(128) DEFAULT NULL,
  entity INT(11) DEFAULT 1,
  vehicle_id INT(11) DEFAULT NULL,
  workorder_date DATE DEFAULT NULL,
  odometer_reading INT(11) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  vendor_id INT(11) DEFAULT NULL,
  status VARCHAR(50) DEFAULT NULL,
  total_cost DECIMAL(10,2) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  fk_user_creat INT(11) DEFAULT NULL,
  fk_user_modif INT(11) DEFAULT NULL,
  tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_flotte_workorder_ref (ref, entity),
  KEY idx_flotte_workorder_vehicle (vehicle_id),
  KEY idx_flotte_workorder_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- IMPORTANT NOTES - HRM Integration for Drivers
-- ============================================================
-- 
-- The llx_flotte_driver table now integrates with Dolibarr's HRM module:
--
-- 1. fk_user field: Links driver to an employee in llx_user table
--    - When creating a driver, select from existing employees
--    - Employee must have employee=1 flag set in llx_user
--
-- 2. Contact fields (firstname, lastname, email, phone):
--    - Auto-filled from selected employee on creation
--    - Can be modified independently for driver-specific contact info
--    - Allows flexibility for different work vs. driver contact details
--
-- 3. Data integrity:
--    - Foreign key constraint prevents invalid employee references
--    - Unique constraint prevents duplicate drivers per employee
--    - ON DELETE RESTRICT protects against accidental data loss
--
-- 4. Migration from existing data:
--    - Existing drivers without fk_user continue to work normally
--    - New drivers should always link to an employee
--    - Can manually update existing drivers to link to employees
--
-- ============================================================
-- End of Flotte Module Database Structure
-- ============================================================