-- ============================================================
-- Flotte Module - Indexes & Foreign Keys
-- Place this file in: htdocs/flotte/sql/llx_flotte.key.sql
-- This file runs AFTER llx_flotte.sql (alphabetical order)
-- ============================================================

-- llx_flotte_driver indexes
ALTER TABLE llx_flotte_driver ADD UNIQUE INDEX uk_flotte_driver_user_entity (fk_user, entity);
ALTER TABLE llx_flotte_driver ADD INDEX idx_flotte_driver_fk_user (fk_user);
ALTER TABLE llx_flotte_driver ADD CONSTRAINT fk_flotte_driver_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid) ON DELETE RESTRICT;

-- llx_flotte_vendor indexes
ALTER TABLE llx_flotte_vendor ADD UNIQUE INDEX uk_flotte_vendor_soc_entity (fk_soc, entity);
ALTER TABLE llx_flotte_vendor ADD INDEX idx_flotte_vendor_fk_soc (fk_soc);
ALTER TABLE llx_flotte_vendor ADD CONSTRAINT fk_flotte_vendor_soc FOREIGN KEY (fk_soc) REFERENCES llx_societe (rowid) ON DELETE RESTRICT;