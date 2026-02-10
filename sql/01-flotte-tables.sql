-- ============================================================================
-- FLOTTE MODULE - COMPLETE DATABASE STRUCTURE
-- Fleet Management System for Dolibarr
-- ============================================================================

-- Table prefix: llx_flotte_
-- Module: Custom Fleet Management (flotte)
-- ============================================================================

-- ============================================================================
-- TABLE: llx_flotte_vehicle
-- Description: Stores vehicle information (cars, trucks, etc.)
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_vehicle (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    maker VARCHAR(128),                      -- Vehicle manufacturer (e.g., Mercedes, Toyota)
    model VARCHAR(128),                      -- Vehicle model (e.g., GT, Corolla)
    type VARCHAR(64),                        -- Vehicle type (Car, Truck, Van, etc.)
    year INTEGER,                            -- Manufacturing year
    odometer INTEGER,                        -- Current odometer reading (mileage)
    last_inspection_date DATETIME,           -- Last inspection date
    last_inspection_mileage DECIMAL(10,2),   -- Mileage at last inspection
    next_inspection_mileage DECIMAL(10,2),   -- Mileage for next inspection
    initial_mileage DECIMAL(10,2),           -- Starting mileage
    current_mileage DECIMAL(10,2),           -- Current mileage
    purchase_price DECIMAL(10,2),            -- Purchase price
    registration_expiry DATE,                -- Registration expiration date
    front_image VARCHAR(255),                -- Front view image filename
    back_image VARCHAR(255),                 -- Back view image filename
    left_image VARCHAR(255),                 -- Left side image filename
    right_image VARCHAR(255),                -- Right side image filename
    license_expiry DATE,                     -- License expiration date
    in_service TINYINT DEFAULT 1,            -- 1=In Service, 0=Out of Service
    status VARCHAR(32),                      -- Status code
    fuel_type VARCHAR(64),                   -- Fuel type (Petrol, Diesel, Electric, Hybrid)
    capacity VARCHAR(64),                    -- Tank/Battery capacity
    color VARCHAR(64),                       -- Vehicle color
    vin VARCHAR(128),                        -- Vehicle Identification Number
    license_plate VARCHAR(128),              -- License plate number
    insurance_expiry DATE,                   -- Insurance expiration date
    notes TEXT,                              -- Additional notes
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_vehicle_ref (ref),
    INDEX idx_flotte_vehicle_entity (entity),
    INDEX idx_flotte_vehicle_in_service (in_service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_driver
-- Description: Stores driver information
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_driver (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_user INTEGER,                         -- Link to HRM employee (llx_user)
    firstname VARCHAR(128),
    middlename VARCHAR(128),
    lastname VARCHAR(128),
    address TEXT,
    email VARCHAR(255),
    phone VARCHAR(64),
    employee_id VARCHAR(64),                 -- Employee ID number
    contract_number VARCHAR(64),             -- Contract number
    license_number VARCHAR(128),             -- Driver's license number
    license_issue_date DATE,                 -- License issue date
    license_expiry_date DATE,                -- License expiry date
    join_date DATE,                          -- Employment start date
    leave_date DATE,                         -- Employment end date
    password VARCHAR(255),                   -- Driver password (if needed)
    department VARCHAR(128),                 -- Department/Division
    status VARCHAR(32),                      -- Active, Inactive, On Leave
    gender VARCHAR(16),                      -- Male, Female
    driver_image VARCHAR(255),               -- Driver photo filename
    license_image VARCHAR(255),              -- License scan filename
    documents VARCHAR(255),                  -- Other documents filename
    emergency_contact TEXT,                  -- Emergency contact information
    fk_vehicle INTEGER,                      -- Currently assigned vehicle
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    fk_user_modif INTEGER,                   -- User who last modified
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_driver_ref (ref),
    INDEX idx_flotte_driver_entity (entity),
    INDEX idx_flotte_driver_fk_user (fk_user),
    INDEX idx_flotte_driver_status (status),
    UNIQUE INDEX uk_flotte_driver_user_entity (fk_user, entity),
    FOREIGN KEY (fk_user) REFERENCES llx_user(rowid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_vendor
-- Description: Stores vendor/supplier information for fleet services
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_vendor (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_soc INTEGER,                          -- Link to Third Party (llx_societe)
    name VARCHAR(255),
    phone VARCHAR(64),
    email VARCHAR(255),
    type VARCHAR(64),                        -- Parts, Fuel, Maintenance, Insurance, Service, Other
    website VARCHAR(255),
    note TEXT,
    address1 VARCHAR(255),
    address2 VARCHAR(255),
    city VARCHAR(128),
    state VARCHAR(128),
    picture VARCHAR(255),
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    fk_user_modif INTEGER,                   -- User who last modified
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_vendor_ref (ref),
    INDEX idx_flotte_vendor_entity (entity),
    INDEX idx_flotte_vendor_fk_soc (fk_soc),
    INDEX idx_flotte_vendor_type (type),
    UNIQUE INDEX uk_flotte_vendor_soc_entity (fk_soc, entity),
    FOREIGN KEY (fk_soc) REFERENCES llx_societe(rowid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_customer
-- Description: Stores customer information for bookings
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_customer (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    firstname VARCHAR(128),
    lastname VARCHAR(128),
    phone VARCHAR(64),
    email VARCHAR(255),
    address TEXT,
    company VARCHAR(255),
    id_card VARCHAR(64),                     -- ID card number
    notes TEXT,
    gender VARCHAR(16),                      -- male, female
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_customer_ref (ref),
    INDEX idx_flotte_customer_entity (entity),
    INDEX idx_flotte_customer_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_booking
-- Description: Stores vehicle rental/booking information
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_booking (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_vehicle INTEGER,                      -- Booked vehicle
    fk_customer INTEGER,                     -- Customer making booking
    fk_driver INTEGER,                       -- Assigned driver (if any)
    booking_date DATE,                       -- Date of booking
    status VARCHAR(32),                      -- pending, confirmed, completed, cancelled
    start_mileage INTEGER,                   -- Odometer at start
    pickup_location VARCHAR(255),            -- Pickup location
    dropoff_location VARCHAR(255),           -- Drop-off location
    estimated_cost DECIMAL(10,2),            -- Estimated rental cost
    actual_cost DECIMAL(10,2),               -- Actual cost charged
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_booking_ref (ref),
    INDEX idx_flotte_booking_entity (entity),
    INDEX idx_flotte_booking_vehicle (fk_vehicle),
    INDEX idx_flotte_booking_customer (fk_customer),
    INDEX idx_flotte_booking_status (status),
    INDEX idx_flotte_booking_date (booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_fuel
-- Description: Stores fuel/refueling records
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_fuel (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_vehicle INTEGER,                      -- Vehicle refueled
    fuel_date DATE,                          -- Date of refueling
    odometer INTEGER,                        -- Odometer reading at refueling
    invoice_number VARCHAR(128),             -- Fuel invoice/receipt number
    status VARCHAR(32),                      -- pending, approved, rejected
    notes TEXT,
    fuel_type TINYINT DEFAULT 0,             -- 0=Fuel, 1=Other
    vendor VARCHAR(255),                     -- Fuel station/vendor name
    quantity DECIMAL(10,2),                  -- Quantity of fuel (liters/gallons)
    price_per_unit DECIMAL(10,2),            -- Price per liter/gallon
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_fuel_ref (ref),
    INDEX idx_flotte_fuel_entity (entity),
    INDEX idx_flotte_fuel_vehicle (fk_vehicle),
    INDEX idx_flotte_fuel_date (fuel_date),
    INDEX idx_flotte_fuel_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_inspection
-- Description: Stores vehicle inspection records
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_inspection (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_vehicle INTEGER,                      -- Vehicle inspected
    inspector VARCHAR(255),                  -- Inspector name
    odometer_start INTEGER,                  -- Start odometer reading
    odometer_end INTEGER,                    -- End odometer reading
    fuel_start VARCHAR(32),                  -- Fuel level at start (Full, 3/4, 1/2, 1/4, Empty)
    fuel_end VARCHAR(32),                    -- Fuel level at end
    inspection_start DATETIME,               -- Inspection start time
    inspection_end DATETIME,                 -- Inspection end time
    
    -- Inspection checklist items (1=OK, 0=NOT OK)
    check_brakes TINYINT DEFAULT 0,
    check_lights TINYINT DEFAULT 0,
    check_tires TINYINT DEFAULT 0,
    check_horn TINYINT DEFAULT 0,
    check_wipers TINYINT DEFAULT 0,
    check_mirrors TINYINT DEFAULT 0,
    check_seatbelts TINYINT DEFAULT 0,
    check_ac TINYINT DEFAULT 0,
    check_oil TINYINT DEFAULT 0,
    check_coolant TINYINT DEFAULT 0,
    check_battery TINYINT DEFAULT 0,
    check_transmission TINYINT DEFAULT 0,
    check_steering TINYINT DEFAULT 0,
    check_suspension TINYINT DEFAULT 0,
    check_exhaust TINYINT DEFAULT 0,
    check_body TINYINT DEFAULT 0,
    check_interior TINYINT DEFAULT 0,
    check_emergency_kit TINYINT DEFAULT 0,
    check_documents TINYINT DEFAULT 0,
    check_spare_tire TINYINT DEFAULT 0,
    
    notes TEXT,
    datec DATETIME,                          -- Creation date
    
    INDEX idx_flotte_inspection_ref (ref),
    INDEX idx_flotte_inspection_entity (entity),
    INDEX idx_flotte_inspection_vehicle (fk_vehicle),
    INDEX idx_flotte_inspection_date (inspection_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_part
-- Description: Stores spare parts inventory
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_part (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    part_number VARCHAR(128),                -- Part number/SKU
    name VARCHAR(255),                       -- Part name
    quantity VARCHAR(64),                    -- Quantity in stock
    location VARCHAR(255),                   -- Storage location
    status VARCHAR(32),                      -- Active, Discontinued, Out of Stock
    fk_vehicle INTEGER,                      -- Compatible vehicle (if specific)
    fk_vendor INTEGER,                       -- Supplier/vendor
    image VARCHAR(255),                      -- Part image
    maker VARCHAR(128),                      -- Part manufacturer
    year INTEGER,                            -- Compatible year
    model VARCHAR(128),                      -- Compatible model
    min_quantity INTEGER,                    -- Minimum stock level
    unit_price DECIMAL(10,2),                -- Price per unit
    notes TEXT,
    last_reorder_date DATE,                  -- Last reorder date
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_part_ref (ref),
    INDEX idx_flotte_part_entity (entity),
    INDEX idx_flotte_part_number (part_number),
    INDEX idx_flotte_part_status (status),
    INDEX idx_flotte_part_vehicle (fk_vehicle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- TABLE: llx_flotte_workorder
-- Description: Stores maintenance work orders
-- ============================================================================
CREATE TABLE IF NOT EXISTS llx_flotte_workorder (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(128) NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    fk_vehicle INTEGER,                      -- Vehicle being serviced
    work_date DATE,                          -- Date of work
    odometer INTEGER,                        -- Odometer reading
    description TEXT,                        -- Work description
    fk_vendor INTEGER,                       -- Service vendor/mechanic
    status VARCHAR(32),                      -- Pending, In Progress, Completed, Cancelled
    cost DECIMAL(10,2),                      -- Total cost
    notes TEXT,
    fk_user_author INTEGER,                  -- User who created the record
    datec DATETIME,                          -- Creation date
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Last modification timestamp
    
    INDEX idx_flotte_workorder_ref (ref),
    INDEX idx_flotte_workorder_entity (entity),
    INDEX idx_flotte_workorder_vehicle (fk_vehicle),
    INDEX idx_flotte_workorder_status (status),
    INDEX idx_flotte_workorder_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ============================================================================
-- SAMPLE DATA STRUCTURE (for reference only - do not execute)
-- ============================================================================

/*
-- Vehicle example:
INSERT INTO llx_flotte_vehicle VALUES(
    3, 'VEH-0001', 1, 'Mercedes', 'GT', 'Car', 2025, 2222, NULL, 
    600.00, 300.00, 4000.00, 5000.00, 4000.00, '2026-02-06', 
    'front.jpg', 'back.jpg', 'left.jpg', 'right.jpg', '2026-05-02', 
    1, '1', 'Petrol', '700', 'green', '123456', '112237', '2026-05-02', 
    NULL, 1, '2026-02-06 14:55:41', NULL
);

-- Driver example:
INSERT INTO llx_flotte_driver VALUES(
    4, 'DRV0002', 1, 2, 'John', '', 'Doe', 'Main St 123', 
    'john.doe@email.com', '+1234567890', 'EMP001', 'CNT001', 
    'DL123456', '2020-01-01', '2030-01-01', '2025-01-01', NULL, 
    '', 'Fleet', 'Active', 'Male', NULL, NULL, NULL, 
    'Jane Doe: +0987654321', 4, 1, '2026-02-10 09:15:38', 1, NULL
);

-- Vendor example:
INSERT INTO llx_flotte_vendor VALUES(
    2, 'VEND-0001', 1, 3, 'ABC Auto Parts', '+1234567890', 
    'info@abcparts.com', 'Parts', 'www.abcparts.com', '', 
    '123 Industrial Ave', '', 'New York', 'NY', NULL, 
    1, '2026-02-09 09:40:41', 1, NULL
);

-- Customer example:
INSERT INTO llx_flotte_customer VALUES(
    2, 'CUST-0001', 1, 'Jane', 'Smith', '+1234567890', 
    'jane.smith@email.com', '456 Oak St', 'ABC Company', 
    'ID123456', NULL, 'female', 1, '2026-02-02 16:41:11', NULL
);

-- Booking example:
INSERT INTO llx_flotte_booking VALUES(
    3, 'BOOK-0001', 1, 4, 2, 2, '2025-09-11', 'completed', 
    1000, 'New York', 'Boston', 800.00, 1000.00, 1, 
    '2026-02-05 11:02:09', NULL
);

-- Fuel example:
INSERT INTO llx_flotte_fuel VALUES(
    2, 'FUEL-0001', 1, 3, '2025-09-11', 123456, 'INV-12345', 
    'approved', '', 0, 'Shell Station', 20.00, 3.50, 1, 
    '2026-02-05 11:00:01', NULL
);

-- Inspection example:
INSERT INTO llx_flotte_inspection VALUES(
    1, 'INSP-0001', 1, 1, 'John Inspector', 12345, 12350, 
    'Full', 'Full', '2025-08-08 14:49:00', '2025-08-08 15:49:00', 
    1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 
    '', '2025-08-08 14:50:11'
);

-- Part example:
INSERT INTO llx_flotte_part VALUES(
    2, 'PART-0001', 1, 'BR-12345', 'Brake Pads', '50', 
    'Warehouse A', 'Active', 1, 1, NULL, 'Bosch', 2025, 
    'Universal', 10, 45.00, '', NULL, 1, '2025-09-12 09:57:10', NULL
);

-- Work Order example:
INSERT INTO llx_flotte_workorder VALUES(
    2, 'WO-0001', 1, 4, '2025-09-11', 123456, 'Oil change and brake service', 
    1, 'Completed', 250.00, '', 1, '2026-02-05 12:43:09', NULL
);
*/

-- ============================================================================
-- END OF FLOTTE MODULE DATABASE STRUCTURE
-- ============================================================================