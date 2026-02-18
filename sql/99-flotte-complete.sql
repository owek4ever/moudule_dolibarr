-- dolibarr.llx_flotte_booking definition

CREATE TABLE `llx_flotte_booking` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `fk_vehicle` int DEFAULT NULL,
  `fk_driver` int DEFAULT NULL,
  `fk_customer` int DEFAULT NULL,
  `booking_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `distance` int DEFAULT NULL,
  `arriving_address` varchar(255) DEFAULT NULL,
  `departure_address` varchar(255) DEFAULT NULL,
  `buying_amount` decimal(10,2) DEFAULT NULL,
  `selling_amount` decimal(10,2) DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_customer definition

CREATE TABLE `llx_flotte_customer` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `firstname` varchar(128) DEFAULT NULL,
  `lastname` varchar(128) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(128) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `tax_no` varchar(50) DEFAULT NULL,
  `payment_delay` int DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_driver definition

CREATE TABLE `llx_flotte_driver` (
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
  CONSTRAINT `fk_flotte_driver_user` FOREIGN KEY (`fk_user`) REFERENCES `llx_user` (`rowid`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_fuel definition

CREATE TABLE `llx_flotte_fuel` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `fk_vehicle` int DEFAULT NULL,
  `date` date DEFAULT NULL,
  `start_meter` int DEFAULT NULL,
  `reference` varchar(128) DEFAULT NULL,
  `state` varchar(128) DEFAULT NULL,
  `note` text,
  `complete_fillup` tinyint DEFAULT NULL,
  `fuel_source` varchar(50) DEFAULT NULL,
  `qty` decimal(10,2) DEFAULT NULL,
  `cost_unit` decimal(10,2) DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_inspection definition

CREATE TABLE `llx_flotte_inspection` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `fk_vehicle` int DEFAULT NULL,
  `registration_number` varchar(128) DEFAULT NULL,
  `meter_out` int DEFAULT NULL,
  `meter_in` int DEFAULT NULL,
  `fuel_out` varchar(10) DEFAULT NULL,
  `fuel_in` varchar(10) DEFAULT NULL,
  `datetime_out` datetime DEFAULT NULL,
  `datetime_in` datetime DEFAULT NULL,
  `petrol_card` tinyint DEFAULT NULL,
  `lights_indicators` tinyint DEFAULT NULL,
  `inverter_cigarette` tinyint DEFAULT NULL,
  `mats_seats` tinyint DEFAULT NULL,
  `interior_damage` tinyint DEFAULT NULL,
  `interior_lights` tinyint DEFAULT NULL,
  `exterior_damage` tinyint DEFAULT NULL,
  `tyres_condition` tinyint DEFAULT NULL,
  `ladders` tinyint DEFAULT NULL,
  `extension_leeds` tinyint DEFAULT NULL,
  `power_tools` tinyint DEFAULT NULL,
  `ac_working` tinyint DEFAULT NULL,
  `headlights_working` tinyint DEFAULT NULL,
  `locks_alarms` tinyint DEFAULT NULL,
  `windows_condition` tinyint DEFAULT NULL,
  `seats_condition` tinyint DEFAULT NULL,
  `oil_check` tinyint DEFAULT NULL,
  `suspension` tinyint DEFAULT NULL,
  `toolboxes_condition` tinyint DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_part definition

CREATE TABLE `llx_flotte_part` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `barcode` varchar(128) DEFAULT NULL,
  `title` varchar(128) DEFAULT NULL,
  `number` varchar(128) DEFAULT NULL,
  `description` text,
  `status` varchar(50) DEFAULT NULL,
  `availability` tinyint DEFAULT NULL,
  `fk_vendor` int DEFAULT NULL,
  `fk_category` int DEFAULT NULL,
  `manufacturer` varchar(128) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `model` varchar(128) DEFAULT NULL,
  `qty_on_hand` int DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `note` text,
  `picture` varchar(255) DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- dolibarr.llx_flotte_vehicle definition

CREATE TABLE `llx_flotte_vehicle` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `maker` varchar(128) DEFAULT NULL,
  `model` varchar(128) DEFAULT NULL,
  `type` varchar(128) DEFAULT NULL,
  `year` int DEFAULT NULL,
  `initial_mileage` int DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `length_cm` decimal(10,2) DEFAULT NULL,
  `width_cm` decimal(10,2) DEFAULT NULL,
  `height_cm` decimal(10,2) DEFAULT NULL,
  `max_weight_kg` decimal(10,2) DEFAULT NULL,
  `ground_height_cm` decimal(10,2) DEFAULT NULL,
  `insurance_expiry` date DEFAULT NULL,
  `vehicle_photo` varchar(255) DEFAULT NULL,
  `registration_card` varchar(255) DEFAULT NULL,
  `platform_registration_card` varchar(255) DEFAULT NULL,
  `insurance_document` varchar(255) DEFAULT NULL,
  `registration_expiry` date DEFAULT NULL,
  `in_service` tinyint DEFAULT '1',
  `department` varchar(128) DEFAULT NULL,
  `engine_type` varchar(128) DEFAULT NULL,
  `horsepower` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `license_plate` varchar(50) DEFAULT NULL,
  `license_expiry` date DEFAULT NULL,
  `fk_group` int DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_vendor definition

CREATE TABLE `llx_flotte_vendor` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `fk_soc` int DEFAULT NULL,
  `name` varchar(128) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `type` varchar(128) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `note` text,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `city` varchar(128) DEFAULT NULL,
  `state` varchar(128) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `fk_user_author` int DEFAULT NULL,
  `datec` datetime DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_flotte_vendor_soc_entity` (`fk_soc`,`entity`),
  KEY `idx_flotte_vendor_fk_soc` (`fk_soc`),
  CONSTRAINT `fk_flotte_vendor_soc` FOREIGN KEY (`fk_soc`) REFERENCES `llx_societe` (`rowid`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- dolibarr.llx_flotte_workorder definition

CREATE TABLE `llx_flotte_workorder` (
  `rowid` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(128) NOT NULL,
  `entity` int DEFAULT '1',
  `fk_vehicle` int DEFAULT NULL,
  `required_by` date DEFAULT NULL,
  `reading` int DEFAULT NULL,
  `note` text,
  `fk_vendor` int DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `description` text,
  `fk_user_author` int DEFAULT NULL,
  `fk_user_modif` int DEFAULT NULL,
  `tms` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rowid`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;