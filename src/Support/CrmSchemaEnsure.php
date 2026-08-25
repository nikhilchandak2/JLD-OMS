<?php

namespace App\Support;

use App\Core\Database;
use Throwable;

/**
 * Idempotent ADD COLUMN / ADD INDEX for CRM tables on a live DB that never
 * finished numbered migrations. After clauses are omitted so a missing
 * neighbour column cannot abort the ALTER.
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
            foreach (self::columns() as [$table, $column, $ddl]) {
                self::addColumn($db, $pdo, $table, $column, $ddl);
            }
            foreach (self::indexes() as [$table, $name, $ddl]) {
                self::addIndex($db, $pdo, $table, $name, $ddl);
            }
            TableSchema::forget();
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
