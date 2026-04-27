-- ============================================================
-- Flotte Module - Complete Table Creation
-- Place this file in: htdocs/flotte/sql/llx_flotte.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS llx_flotte_vehicle (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  maker               varchar(128) DEFAULT NULL,
  model               varchar(128) DEFAULT NULL,
  type                varchar(128) DEFAULT NULL,
  year                int DEFAULT NULL,
  initial_mileage     int DEFAULT NULL,
  image               varchar(255) DEFAULT NULL,
  length_cm           decimal(10,2) DEFAULT NULL,
  width_cm            decimal(10,2) DEFAULT NULL,
  height_cm           decimal(10,2) DEFAULT NULL,
  max_weight_kg       decimal(10,2) DEFAULT NULL,
  ground_height_cm    decimal(10,2) DEFAULT NULL,
  insurance_expiry    date DEFAULT NULL,
  vehicle_photo       varchar(255) DEFAULT NULL,
  registration_card   varchar(255) DEFAULT NULL,
  platform_registration_card varchar(255) DEFAULT NULL,
  insurance_document  varchar(255) DEFAULT NULL,
  registration_expiry date DEFAULT NULL,
  in_service          tinyint DEFAULT 1,
  department          varchar(128) DEFAULT NULL,
  engine_type         varchar(128) DEFAULT NULL,
  horsepower          varchar(50) DEFAULT NULL,
  color               varchar(50) DEFAULT NULL,
  vin                 varchar(50) DEFAULT NULL,
  license_plate       varchar(50) DEFAULT NULL,
  license_expiry      date DEFAULT NULL,
  fk_group            int DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_flotte_driver (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  fk_user             int DEFAULT NULL,
  firstname           varchar(128) DEFAULT NULL,
  middlename          varchar(128) DEFAULT NULL,
  lastname            varchar(128) DEFAULT NULL,
  address             varchar(255) DEFAULT NULL,
  email               varchar(255) DEFAULT NULL,
  phone               varchar(50) DEFAULT NULL,
  employee_id         varchar(50) DEFAULT NULL,
  contract_number     varchar(50) DEFAULT NULL,
  license_number      varchar(50) DEFAULT NULL,
  license_issue_date  date DEFAULT NULL,
  license_expiry_date date DEFAULT NULL,
  join_date           date DEFAULT NULL,
  leave_date          date DEFAULT NULL,
  password            varchar(128) DEFAULT NULL,
  department          varchar(128) DEFAULT NULL,
  status              varchar(50) DEFAULT NULL,
  gender              varchar(10) DEFAULT NULL,
  driver_image        varchar(255) DEFAULT NULL,
  documents           varchar(255) DEFAULT NULL,
  license_image       varchar(255) DEFAULT NULL,
  emergency_contact   varchar(255) DEFAULT NULL,
  fk_vehicle          int DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  datec               datetime DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_flotte_customer (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  firstname           varchar(128) DEFAULT NULL,
  lastname            varchar(128) DEFAULT NULL,
  phone               varchar(50) DEFAULT NULL,
  email               varchar(255) DEFAULT NULL,
  password            varchar(128) DEFAULT NULL,
  company_name        varchar(255) DEFAULT NULL,
  tax_no              varchar(50) DEFAULT NULL,
  payment_delay       int DEFAULT NULL,
  gender              varchar(10) DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_flotte_vendor (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  fk_soc              int DEFAULT NULL,
  name                varchar(128) DEFAULT NULL,
  phone               varchar(50) DEFAULT NULL,
  email               varchar(255) DEFAULT NULL,
  type                varchar(128) DEFAULT NULL,
  website             varchar(255) DEFAULT NULL,
  note                text,
  address1            varchar(255) DEFAULT NULL,
  address2            varchar(255) DEFAULT NULL,
  city                varchar(128) DEFAULT NULL,
  state               varchar(128) DEFAULT NULL,
  picture             varchar(255) DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  datec               datetime DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_flotte_booking (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  fk_vehicle          int DEFAULT NULL,
  fk_driver           int DEFAULT NULL,
  fk_customer         int DEFAULT NULL,
  booking_date        date DEFAULT NULL,
  status              varchar(50) DEFAULT NULL,
  distance            int DEFAULT NULL,
  arriving_address    varchar(255) DEFAULT NULL,
  departure_address   varchar(255) DEFAULT NULL,
  buying_amount       decimal(10,2) DEFAULT NULL,
  selling_amount      decimal(10,2) DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  eta                 varchar(20) DEFAULT NULL,
  stops               text,
  fk_vendor           int DEFAULT NULL,
  buying_tax_rate     varchar(5) DEFAULT NULL,
  selling_tax_rate    varchar(5) DEFAULT NULL,
  buying_price        decimal(15,4) DEFAULT NULL,
  buying_unit         varchar(50) DEFAULT NULL,
  buying_amount_ttc   decimal(15,4) DEFAULT NULL,
  selling_qty         decimal(10,3) DEFAULT NULL,
  selling_price       decimal(15,4) DEFAULT NULL,
  selling_unit        varchar(50) DEFAULT NULL,
  selling_amount_ttc  decimal(15,4) DEFAULT NULL,
  buying_qty          decimal(10,3) DEFAULT NULL,
  dep_lat             decimal(10,7) DEFAULT NULL,
  dep_lon             decimal(10,7) DEFAULT NULL,
  arr_lat             decimal(10,7) DEFAULT NULL,
  arr_lon             decimal(10,7) DEFAULT NULL,
  pickup_datetime     datetime DEFAULT NULL,
  dropoff_datetime    datetime DEFAULT NULL,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_flotte_fuel (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  fk_vehicle          int DEFAULT NULL,
  date                date DEFAULT NULL,
  start_meter         int DEFAULT NULL,
  reference           varchar(128) DEFAULT NULL,
  state               varchar(128) DEFAULT NULL,
  note                text,
  complete_fillup     tinyint DEFAULT NULL,
  fuel_source         varchar(50) DEFAULT NULL,
  qty                 decimal(10,2) DEFAULT NULL,
  cost_unit           decimal(10,2) DEFAULT NULL,
  fuel_photo          varchar(255) DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llx_flotte_part (
  rowid               int NOT NULL AUTO_INCREMENT,
  ref                 varchar(128) NOT NULL,
  entity              int DEFAULT 1,
  barcode             varchar(128) DEFAULT NULL,
  title               varchar(128) DEFAULT NULL,
  number              varchar(128) DEFAULT NULL,
  description         text,
  status              varchar(50) DEFAULT NULL,
  availability        tinyint DEFAULT NULL,
  fk_vendor           int DEFAULT NULL,
  fk_category         int DEFAULT NULL,
  manufacturer        varchar(128) DEFAULT NULL,
  year                int DEFAULT NULL,
  model               varchar(128) DEFAULT NULL,
  qty_on_hand         int DEFAULT NULL,
  unit_cost           decimal(10,2) DEFAULT NULL,
  note                text,
  picture             varchar(255) DEFAULT NULL,
  fk_user_author      int DEFAULT NULL,
  fk_user_modif       int DEFAULT NULL,
  tms                 timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  `service_type` varchar(64) DEFAULT NULL COMMENT 'Maintenance service key; NULL = regular inspection',
  `next_km` int DEFAULT '0',
  `next_date` date DEFAULT NULL,
  `technician` varchar(255) DEFAULT NULL,
  `service_notes` text,
  PRIMARY KEY (`rowid`),
  KEY `idx_flotte_insp_service_type` (`fk_vehicle`,`service_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================
-- Firebase & Push Notification Tables
-- ============================================================

-- Firebase device tokens per Dolibarr user
CREATE TABLE IF NOT EXISTS llx_flotte_firebase_tokens (
  rowid          int          NOT NULL AUTO_INCREMENT,
  fk_user        int          NOT NULL                COMMENT 'Dolibarr user ID',
  device_token   varchar(512) NOT NULL                COMMENT 'Firebase FCM token or Web Push subscription JSON',
  device_type    varchar(32)  DEFAULT 'web'           COMMENT 'web | android | ios',
  device_label   varchar(128) DEFAULT ''              COMMENT 'Human label e.g. "Office Chrome"',
  entity         int          DEFAULT 1,
  date_creation  datetime     NOT NULL,
  date_last_used datetime     DEFAULT NULL,
  active         tinyint      NOT NULL DEFAULT 1,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_token  (device_token(191)),
  INDEX idx_fbt_user   (fk_user),
  INDEX idx_fbt_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification log — every notification ever attempted
CREATE TABLE IF NOT EXISTS llx_flotte_notification_log (
  rowid             int          NOT NULL AUTO_INCREMENT,
  entity            int          DEFAULT 1,
  type              varchar(64)  NOT NULL               COMMENT 'registration_expiry | license_expiry | insurance_expiry | driver_license_expiry | workorder_due | inspection_due | booking_created | fuel_added | custom',
  priority          tinyint(1)   NOT NULL DEFAULT 2     COMMENT '1=low 2=normal 3=high 4=critical',
  title             varchar(255) NOT NULL,
  body              text         NOT NULL,
  data_json         text         DEFAULT NULL           COMMENT 'Extra JSON payload forwarded to FCM',
  fk_user           int          DEFAULT NULL           COMMENT 'Target Dolibarr user (NULL = broadcast)',
  fk_vehicle        int          DEFAULT NULL           COMMENT 'FK -> llx_flotte_vehicle.rowid',
  fk_driver         int          DEFAULT NULL           COMMENT 'FK -> llx_flotte_driver.rowid',
  fk_booking        int          DEFAULT NULL           COMMENT 'FK -> llx_flotte_booking.rowid',
  fk_workorder      int          DEFAULT NULL           COMMENT 'FK -> llx_flotte_workorder.rowid',
  fk_inspection     int          DEFAULT NULL           COMMENT 'FK -> llx_flotte_inspection.rowid',
  fk_fuel           int          DEFAULT NULL           COMMENT 'FK -> llx_flotte_fuel.rowid',
  fk_object_type    varchar(64)  DEFAULT NULL           COMMENT 'vehicle | driver | booking | workorder | inspection | fuel',
  fk_object_id      int          DEFAULT NULL,
  channel           varchar(32)  DEFAULT 'firebase'    COMMENT 'firebase | email | both',
  status            varchar(16)  DEFAULT 'pending'     COMMENT 'pending | sent | failed | cancelled',
  firebase_response text         DEFAULT NULL          COMMENT 'Raw FCM API response',
  date_creation     datetime     NOT NULL,
  date_sent         datetime     DEFAULT NULL,
  error_message     text         DEFAULT NULL,
  PRIMARY KEY (rowid),
  INDEX idx_fnl_type       (type),
  INDEX idx_fnl_status     (status),
  INDEX idx_fnl_fk_vehicle (fk_vehicle),
  INDEX idx_fnl_fk_driver  (fk_driver),
  INDEX idx_fnl_entity     (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alert rules — configurable triggers mapped to your table date fields
CREATE TABLE IF NOT EXISTS llx_flotte_alert_rules (
  rowid              int          NOT NULL AUTO_INCREMENT,
  entity             int          DEFAULT 1,
  rule_name          varchar(128) NOT NULL,
  alert_type         varchar(64)  NOT NULL,
  days_before        int          DEFAULT 30            COMMENT 'First reminder: days before expiry/due date',
  days_before_second int          DEFAULT 7             COMMENT 'Second reminder: days before expiry/due date',
  is_active          tinyint(1)   DEFAULT 1,
  notify_channel     varchar(32)  DEFAULT 'firebase'   COMMENT 'firebase | email | both',
  notify_users       text         DEFAULT NULL          COMMENT 'Comma-separated Dolibarr user IDs; NULL = all registered devices',
  priority           tinyint(1)   DEFAULT 2,
  date_creation      datetime     NOT NULL,
  fk_user_author     int          DEFAULT NULL,
  PRIMARY KEY (rowid),
  INDEX idx_far_alert_type (alert_type),
  INDEX idx_far_entity     (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Firebase server configuration (one row per Dolibarr entity)
CREATE TABLE IF NOT EXISTS llx_flotte_firebase_config (
  rowid                int          NOT NULL AUTO_INCREMENT,
  entity               int          NOT NULL DEFAULT 1,
  server_key           text         DEFAULT NULL        COMMENT 'Legacy FCM server key (only needed for legacy API)',
  project_id           varchar(256) DEFAULT NULL        COMMENT 'Firebase project ID',
  service_account_json text         DEFAULT NULL        COMMENT 'Full service account JSON for FCM v1 OAuth2',
  vapid_key            varchar(512) DEFAULT NULL        COMMENT 'VAPID public key for web push',
  use_v1_api           tinyint(1)   DEFAULT 1           COMMENT '1 = FCM v1 HTTP API (recommended), 0 = Legacy',
  date_update          datetime     DEFAULT NULL,
  fk_user_update       int          DEFAULT NULL,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_ffc_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Default alert rules (safe to run multiple times)
-- ============================================================
INSERT IGNORE INTO llx_flotte_alert_rules
  (entity, rule_name, alert_type, days_before, days_before_second, is_active, notify_channel, priority, date_creation)
VALUES
  (1, 'Vehicle Registration Expiry',  'registration_expiry',  30, 7, 1, 'firebase', 3, NOW()),
  (1, 'Vehicle License Plate Expiry', 'license_expiry',       30, 7, 1, 'firebase', 3, NOW()),
  (1, 'Vehicle Insurance Expiry',     'insurance_expiry',     30, 7, 1, 'firebase', 4, NOW()),
  (1, 'Driver License Expiry',        'driver_license_expiry',30, 7, 1, 'firebase', 3, NOW()),
  (1, 'Work Order Due Date',          'workorder_due',         7, 2, 1, 'firebase', 2, NOW()),
  (1, 'Inspection Overdue',           'inspection_due',       90, 0, 1, 'firebase', 2, NOW());