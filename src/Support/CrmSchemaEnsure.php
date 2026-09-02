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
            self::seedLookups($pdo);
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
            "CREATE TABLE IF NOT EXISTS crm_deal_reason_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(64) NOT NULL,
                label VARCHAR(255) NOT NULL,
                applies_to VARCHAR(20) NOT NULL DEFAULT 'both',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reason_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_stage_exit_criteria (
                id INT AUTO_INCREMENT PRIMARY KEY,
                stage TINYINT NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
                label VARCHAR(255) NOT NULL,
                help_text VARCHAR(255) DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_stage_field (stage, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_technical_queues (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_queue_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_technical_flags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                deal_id INT DEFAULT NULL,
                party_id INT NOT NULL,
                raised_from_stage TINYINT DEFAULT NULL,
                raised_by_user_id INT DEFAULT NULL,
                nature_of_query TEXT NOT NULL,
                routed_to_queue_id INT NOT NULL,
                claimed_by_user_id INT DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL,
                expected_turnaround_at DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                resolution_type VARCHAR(20) DEFAULT NULL,
                resolution_note TEXT DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                resolved_by_user_id INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_deal_grades (
                id INT AUTO_INCREMENT PRIMARY KEY,
                deal_id INT NOT NULL,
                grade_code VARCHAR(64) NOT NULL,
                indicative_qty_tonnes DECIMAL(12,3) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_deal_grade (deal_id, grade_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_deal_stage_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                deal_id INT NOT NULL,
                from_stage TINYINT DEFAULT NULL,
                to_stage TINYINT DEFAULT NULL,
                from_status VARCHAR(20) DEFAULT NULL,
                to_status VARCHAR(20) DEFAULT NULL,
                reason_code_id INT DEFAULT NULL,
                reason_note TEXT DEFAULT NULL,
                exit_criteria_snapshot JSON DEFAULT NULL,
                actor_user_id INT DEFAULT NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_deal_occurred (deal_id, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_deal_criteria_values (
                id INT AUTO_INCREMENT PRIMARY KEY,
                deal_id INT NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                value_text TEXT DEFAULT NULL,
                updated_by_user_id INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_deal_field (deal_id, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                role VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                email VARCHAR(255) NULL,
                is_primary TINYINT(1) DEFAULT 0,
                influence_level VARCHAR(32) NOT NULL DEFAULT 'unknown',
                relationship_strength VARCHAR(32) NOT NULL DEFAULT 'unknown',
                introduced_by_user_id INT NULL,
                introduced_on DATE NULL,
                preferred_channel VARCHAR(32) NULL,
                preferred_language VARCHAR(50) NULL,
                context_notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_party_id (party_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_deals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                company_id INT NULL,
                title VARCHAR(255) NOT NULL,
                value DECIMAL(15,2) NULL,
                stage TINYINT NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                stage_entered_at DATETIME NULL,
                lost_reason_code_id INT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'other',
                indicative_quantity_tonnes DECIMAL(12,3) NULL,
                inquiry_date DATE NULL,
                expected_close_date DATE NULL,
                owner_user_id INT NULL,
                notes TEXT NULL,
                deleted_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_party_id (party_id),
                INDEX idx_stage (stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_activities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                deal_id INT NULL,
                contact_id INT NULL,
                type VARCHAR(50) NOT NULL,
                subject VARCHAR(500) NULL,
                description TEXT NULL,
                activity_date DATETIME NOT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_party_id (party_id),
                INDEX idx_deal_id (deal_id),
                INDEX idx_activity_date (activity_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                party_id INT NULL,
                due_date DATE NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                assigned_to INT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_assigned_to (assigned_to),
                INDEX idx_status (status),
                INDEX idx_due_date (due_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_samples (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                deal_id INT NULL,
                sample_type VARCHAR(100) NULL,
                quantity_sent VARCHAR(100) NULL,
                request_date DATE NULL,
                dispatch_date DATE NULL,
                trial_date DATE NULL,
                status VARCHAR(50) DEFAULT 'sample_sent',
                outcome VARCHAR(255) NULL,
                technical_feedback TEXT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_party_id (party_id),
                INDEX idx_deal_id (deal_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_sample_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sample_id INT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sample_id (sample_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_receivable_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                entry_type VARCHAR(20) NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                entry_date DATE NOT NULL,
                reference VARCHAR(255) NULL,
                description TEXT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_party_id (party_id),
                INDEX idx_entry_date (entry_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_competitor_positions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                competitor_name VARCHAR(255) NOT NULL,
                grade_code VARCHAR(64) NULL,
                application VARCHAR(255) NULL,
                estimated_share_pct TINYINT NULL,
                reason_code VARCHAR(32) NOT NULL DEFAULT 'other',
                reason_note TEXT NULL,
                intelligence_type VARCHAR(32) NOT NULL,
                recorded_by_user_id INT NULL,
                recorded_at DATETIME NOT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_competitor_party_current (party_id, is_current),
                INDEX idx_competitor_party_name (party_id, competitor_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_account_context (
                party_id INT NOT NULL,
                production_capacity_note VARCHAR(255) NULL,
                seasonality_note TEXT NULL,
                updated_by_user_id INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (party_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_account_issues (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                deal_id INT NULL,
                issue_type VARCHAR(32) NOT NULL DEFAULT 'other',
                raised_on DATE NOT NULL,
                description TEXT NOT NULL,
                resolution_window_days INT NOT NULL DEFAULT 7,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                resolved_on DATE NULL,
                resolution_note TEXT NULL,
                raised_by_user_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_issue_party_status (party_id, status),
                INDEX idx_issue_deal (deal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_visits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                deal_id INT NULL,
                visited_by_user_id INT NULL,
                visit_date DATE NOT NULL,
                purpose TEXT NULL,
                outcome TEXT NULL,
                next_planned_touchpoint DATE NULL,
                next_action TEXT NULL,
                no_followup_needed TINYINT(1) NOT NULL DEFAULT 0,
                no_followup_reason TEXT NULL,
                logged_via VARCHAR(20) NOT NULL DEFAULT 'web',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_visits_party_date (party_id, visit_date),
                INDEX idx_visits_owner_date (visited_by_user_id, visit_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_visit_contacts (
                visit_id INT NOT NULL,
                contact_id INT NOT NULL,
                PRIMARY KEY (visit_id, contact_id),
                INDEX idx_visit_contacts_contact (contact_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS dormancy_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NULL,
                account_tier VARCHAR(50) NULL,
                days_no_order INT NOT NULL,
                days_no_order_urgent INT NOT NULL,
                days_no_visit INT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_dormancy_rules_match (company_id, account_tier, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS account_dormancy_signals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                company_id INT NULL,
                computed_on DATE NOT NULL,
                days_since_last_order INT NULL,
                last_order_date DATE NULL,
                days_since_last_visit INT NULL,
                last_visit_date DATE NULL,
                severity VARCHAR(20) NOT NULL,
                reason_summary VARCHAR(512) NOT NULL,
                forecast_gap_flag TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_dormancy_party_day (party_id, computed_on),
                INDEX idx_dormancy_severity_day (severity, computed_on)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS escalation_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NULL,
                trigger_type VARCHAR(32) NOT NULL,
                threshold_days INT NULL,
                escalate_to_role VARCHAR(50) NOT NULL DEFAULT 'admin',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_escalation_rules_type (trigger_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS escalations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NULL,
                party_id INT NOT NULL,
                deal_id INT NULL,
                trigger_type VARCHAR(32) NOT NULL,
                source_table VARCHAR(64) NULL,
                source_id INT NULL,
                episode_key VARCHAR(64) NOT NULL,
                triggered_on DATE NOT NULL,
                triggered_by VARCHAR(20) NOT NULL,
                triggered_by_user_id INT NULL,
                context_snapshot TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                acknowledged_by_user_id INT NULL,
                acknowledged_at DATETIME NULL,
                resolution_note TEXT NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_escalations_inbox (company_id, status, triggered_on),
                INDEX idx_escalations_party (party_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_job_locks (
                job_name VARCHAR(64) PRIMARY KEY,
                locked_at DATETIME NOT NULL,
                locked_by VARCHAR(128) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS crm_job_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(64) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                status VARCHAR(20) NOT NULL,
                summary TEXT NULL,
                error_text TEXT NULL,
                INDEX idx_job_runs_name_started (job_name, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS forecast_periods (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NULL,
                period_month CHAR(7) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                opened_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                opened_by_user_id INT NULL,
                locked_by_user_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_forecast_period_month (period_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS forecast_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                period_id INT NOT NULL,
                party_id INT NOT NULL,
                owner_user_id INT NULL,
                grade_code VARCHAR(64) NOT NULL,
                qty_low_tonnes DECIMAL(10,2) NOT NULL,
                qty_high_tonnes DECIMAL(10,2) NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'prefilled',
                confidence VARCHAR(20) NULL,
                note VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_forecast_line (period_id, party_id, grade_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS forecast_actuals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                period_id INT NOT NULL,
                party_id INT NOT NULL,
                grade_code VARCHAR(64) NOT NULL,
                forecast_low DECIMAL(10,2) NOT NULL,
                forecast_high DECIMAL(10,2) NOT NULL,
                actual_tonnes DECIMAL(12,3) NOT NULL DEFAULT 0,
                variance_vs_midpoint DECIMAL(10,2) NOT NULL DEFAULT 0,
                as_of DATE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_forecast_actual (period_id, party_id, grade_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS handoff_packets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                packet_type VARCHAR(32) NOT NULL,
                deal_id INT NULL,
                order_id INT NULL,
                dispatch_id INT NULL,
                schema_version SMALLINT NOT NULL,
                payload TEXT NOT NULL,
                supersession_reason VARCHAR(500) NULL,
                created_by_user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acknowledged_by_user_id INT NULL,
                acknowledged_at DATETIME NULL,
                superseded_by_packet_id INT NULL,
                INDEX idx_handoff_deal (deal_id),
                INDEX idx_handoff_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS party_handover_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_id INT NOT NULL,
                author_user_id INT NULL,
                note TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_handover_party_active (party_id, is_active, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS pipeline_deal_snapshot (
                as_of DATE NOT NULL,
                deal_id INT NOT NULL,
                stage TINYINT NOT NULL,
                status VARCHAR(20) NOT NULL,
                owner_user_id INT NULL,
                owner_name VARCHAR(255) NULL,
                party_id INT NOT NULL,
                party_name VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                indicative_value DECIMAL(15,2) NULL,
                inquiry_date DATE NULL,
                stage_entered_at DATETIME NULL,
                PRIMARY KEY (as_of, deal_id),
                INDEX idx_pipeline_snapshot_stage (as_of, status, stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS pipeline_deal_snapshot_grades (
                as_of DATE NOT NULL,
                deal_id INT NOT NULL,
                grade_code VARCHAR(50) NOT NULL,
                PRIMARY KEY (as_of, deal_id, grade_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS pipeline_time_in_stage_facts (
                as_of DATE NOT NULL,
                deal_id INT NOT NULL,
                stage TINYINT NOT NULL,
                status VARCHAR(20) NOT NULL,
                owner_user_id INT NULL,
                owner_name VARCHAR(255) NULL,
                party_id INT NOT NULL,
                party_name VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                inquiry_date DATE NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                lifetime_seconds INT NOT NULL DEFAULT 0,
                hold_seconds INT NOT NULL DEFAULT 0,
                current_dwell_seconds INT NULL,
                current_hold_seconds INT NULL,
                PRIMARY KEY (as_of, deal_id, stage),
                INDEX idx_pipeline_tis_current (as_of, is_current, status, stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
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
            "CREATE TABLE IF NOT EXISTS data_feeds (
                id INT AUTO_INCREMENT PRIMARY KEY,
                feed_key VARCHAR(32) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                owner_user_id INT NULL,
                deadline_local_time TIME NOT NULL DEFAULT '09:00:00',
                company_id INT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_feed_company (feed_key, company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS data_feed_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                feed_key VARCHAR(32) NOT NULL,
                company_id INT NOT NULL,
                business_date DATE NOT NULL,
                uploaded_by_user_id INT NULL,
                uploaded_at DATETIME NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                file_hash CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
                rows_total INT NOT NULL DEFAULT 0,
                rows_accepted INT NOT NULL DEFAULT 0,
                rows_rejected INT NOT NULL DEFAULT 0,
                as_of DATETIME NULL,
                error_summary TEXT NULL,
                replaces_run_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_feed_run_hash (feed_key, company_id, business_date, file_hash),
                INDEX idx_feed_company_date (feed_key, company_id, business_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS data_feed_rows (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_id INT NOT NULL,
                row_number INT NOT NULL,
                raw TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                rejection_reason VARCHAR(255) NULL,
                resolved_party_id INT NULL,
                INDEX idx_run_status (run_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS party_source_aliases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_system VARCHAR(20) NOT NULL,
                source_identifier VARCHAR(255) NOT NULL,
                party_id INT NOT NULL,
                confidence VARCHAR(20) NOT NULL DEFAULT 'manual',
                created_by_user_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_source_identifier (source_system, source_identifier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS ledger_outstanding (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_id INT NOT NULL,
                company_id INT NOT NULL,
                party_id INT NOT NULL,
                business_date DATE NOT NULL,
                outstanding_amount DECIMAL(14,2) NOT NULL,
                invoice_no VARCHAR(255) NULL,
                invoice_date DATE NULL,
                as_of DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ledger_company_date (company_id, business_date),
                INDEX idx_ledger_party (party_id, business_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS dispatch_day_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                run_id INT NOT NULL,
                company_id INT NOT NULL,
                party_id INT NOT NULL,
                business_date DATE NOT NULL,
                grade_code VARCHAR(64) NOT NULL,
                quantity_tonnes DECIMAL(12,3) NOT NULL,
                vehicle_no VARCHAR(64) NULL,
                destination VARCHAR(255) NULL,
                invoice_no VARCHAR(255) NULL,
                as_of DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dispatch_company_date (company_id, business_date),
                INDEX idx_dispatch_party (party_id, business_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS data_feed_locks (
                run_id INT PRIMARY KEY,
                locked_by_user_id INT NULL,
                locked_at DATETIME NOT NULL
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

    private static function seedLookups(\PDO $pdo): void
    {
        if (TableSchema::hasTable('crm_deal_reason_codes')) {
            try {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM crm_deal_reason_codes')->fetchColumn();
                if ($count === 0) {
                    $pdo->exec(
                        "INSERT IGNORE INTO crm_deal_reason_codes (code, label, applies_to, sort_order) VALUES
                          ('price_too_high', 'Price too high', 'lost', 10),
                          ('quality_not_suitable', 'Quality / spec not suitable', 'lost', 20),
                          ('competitor_won', 'Competitor won the business', 'lost', 30),
                          ('credit_terms_rejected', 'Credit terms not acceptable', 'lost', 40),
                          ('logistics_freight', 'Freight / logistics not viable', 'lost', 50),
                          ('customer_project_shelved', 'Customer project shelved', 'both', 60),
                          ('no_response', 'No response from customer', 'dropped', 70),
                          ('duplicate_deal', 'Duplicate deal record', 'dropped', 80),
                          ('not_a_fit', 'Not our product range', 'dropped', 90),
                          ('other', 'Other (see note)', 'both', 100)"
                    );
                }
            } catch (Throwable $e) {
                error_log('CrmSchemaEnsure seed reason codes: ' . $e->getMessage());
            }
        }

        if (TableSchema::hasTable('crm_technical_queues')) {
            try {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM crm_technical_queues')->fetchColumn();
                if ($count === 0) {
                    $pdo->exec(
                        "INSERT IGNORE INTO crm_technical_queues (company_id, name, is_active) VALUES
                          (NULL, 'Technical Support', 1),
                          (NULL, 'Lab / Testing', 1)"
                    );
                }
            } catch (Throwable $e) {
                error_log('CrmSchemaEnsure seed technical queues: ' . $e->getMessage());
            }
        }

        if (TableSchema::hasTable('crm_stage_exit_criteria')) {
            try {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM crm_stage_exit_criteria')->fetchColumn();
                if ($count === 0) {
                    $pdo->exec(
                        "INSERT IGNORE INTO crm_stage_exit_criteria (stage, field_key, is_mandatory, label, help_text, sort_order) VALUES
                          (1, 'source', 1, 'Enquiry source', 'How the enquiry reached us', 10),
                          (1, 'party', 1, 'Customer', 'Existing party or a newly created one', 20),
                          (1, 'grades', 1, 'Grade(s) enquired for', 'At least one grade code', 30),
                          (1, 'indicative_quantity', 1, 'Indicative quantity (tonnes)', NULL, 40),
                          (1, 'inquiry_date', 1, 'Enquiry date', NULL, 50),
                          (2, 'application_confirmed', 1, 'Application confirmed', 'What the material will be used for', 10),
                          (2, 'decision_maker_contact', 1, 'Decision-maker contact identified', 'A contact recorded against this customer', 20),
                          (2, 'rough_volume_tonnes_per_month', 1, 'Rough monthly volume (tonnes)', NULL, 30),
                          (2, 'order_frequency', 1, 'Order frequency', 'e.g. weekly, monthly', 40),
                          (3, 'sample_sent', 1, 'Sample sent', 'A sample record exists for this deal', 10),
                          (3, 'sample_grade_batch', 1, 'Grade + batch logged', NULL, 20),
                          (3, 'follow_up_date', 1, 'Follow-up date set', NULL, 30),
                          (4, 'customer_feedback', 1, 'Customer feedback logged', NULL, 10),
                          (4, 'technical_fit', 1, 'Technical fit confirmed or rejected', 'confirmed / rejected', 20),
                          (4, 'technical_fit_reason', 1, 'Reason for the technical fit decision', NULL, 30),
                          (5, 'quote_grade_code', 1, 'Quoted grade code', NULL, 10),
                          (5, 'quote_spec', 1, 'Quoted specification', NULL, 20),
                          (5, 'quote_validity_until', 1, 'Quote validity date', NULL, 30),
                          (5, 'proposed_terms', 1, 'Proposed commercial terms', NULL, 40),
                          (6, 'final_terms_agreed', 1, 'Final terms agreed', NULL, 10),
                          (6, 'credit_gate_cleared', 0, 'Credit gate cleared', 'Seam for TASK 3 - not enforced yet', 20),
                          (6, 'handoff_packet_transferred', 1, 'Handoff packet transferred to Dispatch', 'A valid Sales to Dispatch packet must exist before Dispatch sees the order.', 30)"
                    );
                }
            } catch (Throwable $e) {
                error_log('CrmSchemaEnsure seed exit criteria: ' . $e->getMessage());
            }
        }

        if (TableSchema::hasTable('dormancy_rules')) {
            try {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM dormancy_rules')->fetchColumn();
                if ($count === 0) {
                    $pdo->exec(
                        "INSERT IGNORE INTO dormancy_rules (company_id, account_tier, days_no_order, days_no_order_urgent, days_no_visit, is_active)
                         VALUES (NULL, NULL, 20, 20, 20, 1)"
                    );
                }
            } catch (Throwable $e) {
                error_log('CrmSchemaEnsure seed dormancy rules: ' . $e->getMessage());
            }
        }

        if (TableSchema::hasTable('escalation_rules')) {
            try {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM escalation_rules')->fetchColumn();
                if ($count === 0) {
                    $pdo->exec(
                        "INSERT IGNORE INTO escalation_rules (company_id, trigger_type, threshold_days, escalate_to_role, is_active) VALUES
                          (NULL, 'dormant_account', 20, 'admin', 1),
                          (NULL, 'unresolved_issue', NULL, 'admin', 1),
                          (NULL, 'dispatch_delay', 1, 'admin', 1),
                          (NULL, 'technical_overdue', 0, 'admin', 1),
                          (NULL, 'manual_flag', NULL, 'admin', 1)"
                    );
                }
            } catch (Throwable $e) {
                error_log('CrmSchemaEnsure seed escalation rules: ' . $e->getMessage());
            }
        }

        if (TableSchema::hasTable('data_feeds') && TableSchema::hasTable('companies')) {
            try {
                $pdo->exec(
                    "INSERT INTO data_feeds (feed_key, display_name, owner_user_id, deadline_local_time, company_id, is_active)
                     SELECT 'ledger', CONCAT(c.name, ' — Ledger (Busy outstanding)'), NULL, '09:00:00', c.id, 1
                     FROM companies c
                     WHERE NOT EXISTS (
                       SELECT 1 FROM data_feeds df WHERE df.feed_key = 'ledger' AND df.company_id = c.id
                     )"
                );
                $pdo->exec(
                    "INSERT INTO data_feeds (feed_key, display_name, owner_user_id, deadline_local_time, company_id, is_active)
                     SELECT 'dispatch_day_file', CONCAT(c.name, ' — Dispatch day file'), NULL, '18:00:00', c.id, 1
                     FROM companies c
                     WHERE NOT EXISTS (
                       SELECT 1 FROM data_feeds df WHERE df.feed_key = 'dispatch_day_file' AND df.company_id = c.id
                     )"
                );
            } catch (Throwable $e) {
                error_log('CrmSchemaEnsure seed data feeds: ' . $e->getMessage());
            }
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
