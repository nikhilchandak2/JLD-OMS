<?php

namespace App\Support;

use App\Core\Database;
use Throwable;

/**
 * Idempotent ADD COLUMN / ADD INDEX for a live DB that never finished numbered
 * migrations. After clauses are omitted so a missing neighbour column cannot
 * abort the ALTER. Applied on every request so OMS and CRM share the same heal.
 */
class CrmSchemaEnsure
{
    private static bool $applied = false;

    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }
        self::$applied = true;

        try {
            $db = new Database();
            $pdo = $db->getConnection();
            foreach (self::tables() as $sql) {
                self::createTable($pdo, $sql);
            }
            foreach (self::columns() as [$table, $column, $ddl]) {
                self::addColumn($db, $pdo, $table, $column, $ddl);
            }
            foreach (self::backfills() as [$table, $dest, $source]) {
                self::backfillColumn($pdo, $table, $dest, $source);
            }
            self::backfillFlagStatus($pdo, 'vehicles');
            self::backfillFlagStatus($pdo, 'gps_devices');
            foreach (self::indexes() as [$table, $name, $ddl]) {
                self::addIndex($db, $pdo, $table, $name, $ddl);
            }
            TableSchema::forget();
            OrderSchema::forget();
            DispatchSchema::forget();
        } catch (Throwable $e) {
            error_log('CrmSchemaEnsure: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function columns(): array
    {
        return [
            ['companies', 'status', "ENUM('active','inactive') NOT NULL DEFAULT 'active'"],
            ['companies', 'order_prefix', 'VARCHAR(20) NULL'],
            ['parties', 'region', 'VARCHAR(100) NULL'],
            ['parties', 'product_category', 'VARCHAR(100) NULL'],
            ['parties', 'production_capacity', 'VARCHAR(255) NULL'],
            ['parties', 'factory_locations', 'TEXT NULL'],
            ['parties', 'credit_limit', 'DECIMAL(15,2) NULL'],
            ['parties', 'payment_terms_days', 'INT NULL'],
            ['parties', 'technical_notes', 'TEXT NULL'],
            ['parties', 'products_introduced', 'TEXT NULL'],
            ['parties', 'monthly_consumption', 'VARCHAR(255) NULL'],
            ['parties', 'year_of_association', 'INT NULL'],
            ['parties', 'order_frequency', 'VARCHAR(50) NULL'],
            ['parties', 'last_order_date', 'DATE NULL'],
            ['parties', 'last_visit_date', 'DATE NULL'],
            ['parties', 'payment_track', 'VARCHAR(50) NULL'],
            ['parties', 'target_volume', 'VARCHAR(255) NULL'],
            ['parties', 'next_followup_date', 'DATE NULL'],
            ['parties', 'assigned_sales_owner', 'INT NULL'],
            ['parties', 'number_of_plants', 'INT NULL'],
            ['parties', 'general_notes', 'TEXT NULL'],
            ['parties', 'funnel_stage', 'VARCHAR(50) NULL'],
            ['parties', 'industry_type', 'VARCHAR(50) NULL'],
            ['parties', 'tiles_subtype', 'VARCHAR(100) NULL'],
            ['parties', 'monthly_consumption_ton', 'DECIMAL(12,2) NULL'],
            ['parties', 'avg_price_per_ton', 'DECIMAL(12,2) NULL'],
            ['parties', 'current_supplier_details', 'TEXT NULL'],
            ['parties', 'relation_with_purchase', 'TINYINT NULL'],
            ['parties', 'relation_with_internal_team', 'TINYINT NULL'],
            ['parties', 'probability_of_conversion', 'TINYINT NULL'],
            ['parties', 'visit_description', 'TEXT NULL'],
            ['parties', 'followup_notes', 'TEXT NULL'],
            ['parties', 'visit_samples_provided', 'TEXT NULL'],
            ['parties', 'account_tier', 'VARCHAR(50) NULL'],
            ['crm_account_issues', 'status', "ENUM('open','resolved','escalated') NOT NULL DEFAULT 'open'"],
            ['orders', 'scheduled_dispatch_date', 'DATE NULL'],
            ['orders', 'tons_per_truck', 'DECIMAL(8,2) NOT NULL DEFAULT 40.00'],
            ['orders', 'credit_gate_status', "ENUM('cleared','pending_director','blocked') NOT NULL DEFAULT 'cleared'"],
            ['orders', 'credit_override_request_id', 'INT NULL'],
            ['orders', 'priority', "VARCHAR(20) NOT NULL DEFAULT 'normal'"],
            ['orders', 'is_recurring', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['orders', 'delivery_frequency_days', 'INT NULL'],
            ['orders', 'trucks_per_delivery', 'INT NULL'],
            ['orders', 'total_deliveries', 'INT NULL'],
            ['orders', 'order_qty_mode', "VARCHAR(10) NOT NULL DEFAULT 'trucks'"],
            ['orders', 'order_weight_tons', 'DECIMAL(12,3) NULL'],
            ['orders', 'bill_to_other_party', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['orders', 'billing_party_id', 'INT NULL'],
            ['dispatches', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
            ['dispatches', 'rejection_reason', 'TEXT NULL'],
            ['dispatches', 'transferred_to_dispatch_id', 'INT NULL'],
            ['dispatches', 'source_dispatch_id', 'INT NULL'],
            ['dispatches', 'product_rate', 'DECIMAL(14,2) NULL'],
            ['dispatches', 'loading_weight_tons', 'DECIMAL(10,3) NULL'],
            ['dispatches', 'busy_invoice_no', 'VARCHAR(100) NULL'],
            ['dispatches', 'rawana_no', 'VARCHAR(100) NULL'],
            ['dispatches', 'eway_bill_no', 'VARCHAR(100) NULL'],
            ['dispatches', 'eway_bill_file_path', 'VARCHAR(500) NULL'],
            ['companies', 'transport_doc_type', "VARCHAR(20) NOT NULL DEFAULT 'rawana'"],
            ['products', 'hsn_code', 'VARCHAR(20) NULL'],
            ['parties', 'gst_number', 'VARCHAR(15) NULL'],
            ['geofences', 'shape_type', "VARCHAR(20) NOT NULL DEFAULT 'circle'"],
            ['geofences', 'polygon_points', 'TEXT NULL'],
            ['credit_approval_requests', 'requested_limit_increase', 'DECIMAL(14,2) NULL'],
            ['credit_approval_requests', 'reason', 'VARCHAR(500) NULL'],
            ['credit_approval_requests', 'credit_limit_increase', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
            ['credit_approval_requests', 'new_credit_limit', 'DECIMAL(14,2) NULL'],
            ['crm_deals', 'company_id', 'INT NULL'],
            ['crm_deals', 'status', "ENUM('active','won','lost','dropped') NOT NULL DEFAULT 'active'"],
            ['crm_deals', 'deleted_at', 'DATETIME NULL'],
            ['crm_deals', 'stage_entered_at', 'DATETIME NULL'],
            ['crm_deals', 'lost_reason_code_id', 'INT NULL'],
            ['crm_deals', 'source', "VARCHAR(32) NOT NULL DEFAULT 'other'"],
            ['crm_deals', 'indicative_quantity_tonnes', 'DECIMAL(12,3) NULL'],
            ['crm_deals', 'inquiry_date', 'DATE NULL'],
            ['crm_deals', 'owner_user_id', 'INT NULL'],
            ['crm_contacts', 'influence_level', "VARCHAR(32) NOT NULL DEFAULT 'unknown'"],
            ['crm_contacts', 'relationship_strength', "VARCHAR(32) NOT NULL DEFAULT 'unknown'"],
            ['crm_contacts', 'introduced_by_user_id', 'INT NULL'],
            ['crm_contacts', 'introduced_on', 'DATE NULL'],
            ['crm_contacts', 'preferred_channel', 'VARCHAR(32) NULL'],
            ['crm_contacts', 'preferred_language', 'VARCHAR(50) NULL'],
            ['crm_contacts', 'context_notes', 'TEXT NULL'],
            ['crm_technical_flags', 'status', "ENUM('open','claimed','resolved','cancelled') NOT NULL DEFAULT 'open'"],
            ['crm_tasks', 'status', "ENUM('pending','completed') NOT NULL DEFAULT 'pending'"],
            ['crm_account_context', 'updated_by_user_id', 'INT NULL'],
            ['pipeline_deal_snapshot', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
            ['pipeline_time_in_stage_facts', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
            ['vehicles', 'vehicle_number', 'VARCHAR(100) NULL'],
            ['vehicles', 'registration_number', 'VARCHAR(100) NULL'],
            ['vehicles', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
            ['vehicles', 'notes', 'TEXT NULL'],
            ['gps_devices', 'imei', 'VARCHAR(50) NULL'],
            ['gps_devices', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
            ['vehicle_trips', 'start_time', 'DATETIME NULL'],
            ['vehicle_trips', 'end_time', 'DATETIME NULL'],
            ['vehicle_trips', 'source_geofence_id', 'INT NULL'],
            ['vehicle_trips', 'destination_geofence_id', 'INT NULL'],
            ['vehicle_trips', 'trip_type', 'VARCHAR(50) NULL'],
            ['vehicle_trips', 'distance_km', 'DECIMAL(10,2) NULL'],
            ['vehicle_trips', 'duration_minutes', 'DECIMAL(10,2) NULL'],
            ['vehicle_trips', 'fuel_consumed_liters', 'DECIMAL(10,2) NULL'],
            ['geofences', 'latitude', 'DECIMAL(10,8) NULL'],
            ['geofences', 'longitude', 'DECIMAL(11,8) NULL'],
            ['geofences', 'radius_meters', 'DECIMAL(8,2) NULL'],
            ['geofences', 'geofence_type', 'VARCHAR(50) NULL'],
            ['busy_daily_invoices', 'upload_id', 'INT NULL'],
            ['busy_invoice_uploads', 'invoice_date_from', 'DATE NULL'],
            ['busy_invoice_uploads', 'invoice_date_to', 'DATE NULL'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS visit_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                requested_by INT NOT NULL,
                assigned_to INT NULL,
                purpose VARCHAR(500) NOT NULL,
                preferred_date DATE NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                scheduled_date DATE NULL,
                visit_outcome VARCHAR(1000) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_party (party_id),
                INDEX idx_assigned (assigned_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS dispatch_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_dispatch_id INT NOT NULL,
                target_dispatch_id INT NULL,
                source_order_id INT NOT NULL,
                target_order_id INT NULL,
                source_party_id INT NOT NULL,
                target_party_id INT NULL,
                trucks_transferred INT NOT NULL DEFAULT 1,
                weight_tons DECIMAL(10,3) NULL,
                action_type VARCHAR(20) NOT NULL,
                reason TEXT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS credit_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                dispatch_id INT NULL,
                order_id INT NULL,
                busy_credit_note_no VARCHAR(100) NULL,
                original_invoice_no VARCHAR(100) NULL,
                amount DECIMAL(14,2) NOT NULL,
                weight_tons DECIMAL(10,3) NULL,
                rate_per_ton DECIMAL(14,2) NULL,
                note_date DATE NOT NULL,
                reason TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'posted',
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS busy_daily_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_no VARCHAR(100) NOT NULL,
                invoice_date DATE NOT NULL,
                party_name VARCHAR(255) NOT NULL,
                product_name VARCHAR(255) NULL,
                product_rate DECIMAL(14,2) NULL,
                quantity_trucks INT NOT NULL DEFAULT 1,
                loading_weight_tons DECIMAL(10,3) NULL,
                vehicle_no VARCHAR(50) NULL,
                rawana_no VARCHAR(100) NULL,
                eway_bill_no VARCHAR(100) NULL,
                order_no_from_invoice VARCHAR(100) NULL,
                company_id INT NULL,
                order_id INT NULL,
                dispatch_id INT NULL,
                mapping_status VARCHAR(20) NOT NULL DEFAULT 'unmapped',
                error_message TEXT NULL,
                uploaded_by INT NULL,
                upload_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_busy_daily_invoice_no (invoice_no),
                KEY idx_busy_daily_invoice_date (invoice_date),
                KEY idx_busy_daily_mapping_status (mapping_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS busy_invoice_uploads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                original_filename VARCHAR(255) NOT NULL,
                file_type VARCHAR(20) NOT NULL DEFAULT 'csv',
                stored_path VARCHAR(500) NULL,
                file_size INT NULL,
                company_id INT NULL,
                invoice_date_from DATE NULL,
                invoice_date_to DATE NULL,
                invoice_count INT NOT NULL DEFAULT 0,
                mapped_count INT NOT NULL DEFAULT 0,
                unmapped_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'processed',
                parse_notes TEXT NULL,
                uploaded_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_busy_uploads_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS fuel_report_uploads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(20) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                file_type VARCHAR(20) NOT NULL,
                stored_path VARCHAR(500) NULL,
                report_month DATE NULL,
                uploaded_by INT NULL,
                machines_found INT NOT NULL DEFAULT 0,
                readings_saved INT NOT NULL DEFAULT 0,
                parse_notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fuel_uploads_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS fuel_machines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(20) NOT NULL,
                name VARCHAR(255) NULL,
                serial_no VARCHAR(120) NULL,
                chassis_no VARCHAR(120) NULL,
                identity_key VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fuel_machine_identity (category, identity_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS fuel_daily_readings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                machine_id INT NOT NULL,
                upload_id INT NULL,
                reading_date DATE NULL,
                fuel_consumed_liters DECIMAL(12,2) NULL,
                working_hours DECIMAL(10,2) NULL,
                average_usage DECIMAL(12,4) NULL,
                extra_json JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fuel_readings_machine (machine_id),
                INDEX idx_fuel_readings_date (reading_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS tds_uploads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                original_filename VARCHAR(255) NOT NULL,
                file_type VARCHAR(20) NOT NULL,
                period_label VARCHAR(120) NULL,
                period_from DATE NULL,
                period_to DATE NULL,
                rows_imported INT NOT NULL DEFAULT 0,
                uploaded_by INT NULL,
                parse_notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS tds_voucher_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                upload_id INT NOT NULL,
                voucher_date DATE NULL,
                voucher_date_raw VARCHAR(40) NULL,
                voucher_no VARCHAR(80) NULL,
                particulars VARCHAR(255) NULL,
                item_details VARCHAR(255) NULL,
                material_centre VARCHAR(255) NOT NULL,
                qty DECIMAL(14,3) NOT NULL DEFAULT 0,
                unit VARCHAR(40) NULL,
                price DECIMAL(14,4) NOT NULL DEFAULT 0,
                amount DECIMAL(16,2) NOT NULL DEFAULT 0,
                price_band VARCHAR(20) NOT NULL,
                INDEX idx_tds_lines_upload (upload_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    /**
     * Copy values from a legacy column onto the name the app queries.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function backfills(): array
    {
        return [
            ['vehicles', 'vehicle_number', 'vehicle_no'],
            ['vehicles', 'registration_number', 'registration_no'],
            ['gps_devices', 'imei', 'device_id'],
            ['vehicle_trips', 'start_time', 'trip_start_time'],
            ['vehicle_trips', 'end_time', 'trip_end_time'],
            ['vehicle_trips', 'distance_km', 'total_distance'],
            ['vehicle_trips', 'duration_minutes', 'total_duration'],
            ['vehicle_trips', 'fuel_consumed_liters', 'fuel_consumed'],
            ['geofences', 'latitude', 'center_latitude'],
            ['geofences', 'longitude', 'center_longitude'],
            ['geofences', 'radius_meters', 'radius'],
            ['geofences', 'geofence_type', 'zone_type'],
        ];
    }

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function indexes(): array
    {
        return [
            ['orders', 'idx_orders_party_date', 'INDEX idx_orders_party_date (party_id, order_date)'],
            ['orders', 'idx_orders_scheduled_dispatch_date', 'INDEX idx_orders_scheduled_dispatch_date (scheduled_dispatch_date)'],
            ['crm_visits', 'idx_visits_party_date', 'INDEX idx_visits_party_date (party_id, visit_date)'],
            ['crm_visits', 'idx_visits_overdue', 'INDEX idx_visits_overdue (visited_by_user_id, next_planned_touchpoint)'],
            ['crm_visits', 'idx_visits_touchpoint', 'INDEX idx_visits_touchpoint (next_planned_touchpoint)'],
            ['parties', 'idx_parties_sales_owner', 'INDEX idx_parties_sales_owner (assigned_sales_owner)'],
            ['parties', 'idx_parties_account_tier', 'INDEX idx_parties_account_tier (account_tier)'],
            ['crm_contacts', 'ft_crm_contacts_name', 'FULLTEXT INDEX ft_crm_contacts_name (name)'],
            ['crm_account_issues', 'ft_issue_description', 'FULLTEXT INDEX ft_issue_description (description)'],
            ['crm_competitor_positions', 'ft_competitor_name', 'FULLTEXT INDEX ft_competitor_name (competitor_name)'],
        ];
    }

    private static function createTable(\PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
            TableSchema::forget();
        } catch (Throwable $e) {
            error_log('CrmSchemaEnsure create table: ' . $e->getMessage());
        }
    }

    private static function backfillColumn(\PDO $pdo, string $table, string $dest, string $source): void
    {
        if (!TableSchema::hasTable($table) || !TableSchema::hasColumn($table, $dest) || !TableSchema::hasColumn($table, $source)) {
            return;
        }
        try {
            $pdo->exec(
                "UPDATE `{$table}` SET `{$dest}` = `{$source}`
                 WHERE `{$dest}` IS NULL OR `{$dest}` = ''"
            );
        } catch (Throwable $e) {
            error_log("CrmSchemaEnsure backfill {$table}.{$dest}: " . $e->getMessage());
        }
    }

    private static function backfillFlagStatus(\PDO $pdo, string $table): void
    {
        if (!TableSchema::hasTable($table) || !TableSchema::hasColumn($table, 'status') || !TableSchema::hasColumn($table, 'is_active')) {
            return;
        }
        try {
            $pdo->exec(
                "UPDATE `{$table}` SET `status` = CASE WHEN `is_active` = 1 THEN 'active' ELSE 'inactive' END"
            );
        } catch (Throwable $e) {
            error_log("CrmSchemaEnsure status backfill {$table}: " . $e->getMessage());
        }
    }

    private static function addColumn(Database $db, \PDO $pdo, string $table, string $column, string $ddl): void
    {
        if (!TableSchema::hasTable($table) || TableSchema::hasColumn($table, $column)) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}");
            TableSchema::forget();
        } catch (Throwable $e) {
            error_log("CrmSchemaEnsure add {$table}.{$column}: " . $e->getMessage());
        }
    }

    private static function addIndex(Database $db, \PDO $pdo, string $table, string $name, string $ddl): void
    {
        if (!TableSchema::hasTable($table) || TableSchema::hasIndex($table, $name)) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD {$ddl}");
            TableSchema::forget();
        } catch (Throwable $e) {
            error_log("CrmSchemaEnsure index {$table}.{$name}: " . $e->getMessage());
        }
    }
}
