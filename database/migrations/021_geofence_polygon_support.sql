-- Migration: 021_geofence_polygon_support.sql
-- Adds polygon support for irregular geofence boundaries

ALTER TABLE geofences
    ADD COLUMN shape_type ENUM('circle', 'polygon') NOT NULL DEFAULT 'circle' AFTER material_type,
    ADD COLUMN polygon_points JSON NULL AFTER radius_meters;
