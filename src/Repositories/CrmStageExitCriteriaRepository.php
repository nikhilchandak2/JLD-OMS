<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Reads the Director-editable exit criteria configuration. Nothing about which fields are
 * mandatory is hardcoded in PHP: changing a row here changes behaviour with no deploy.
 */
class CrmStageExitCriteriaRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findByStage(int $stage): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_stage_exit_criteria')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT id, stage, field_key, is_mandatory, label, help_text, sort_order
             FROM crm_stage_exit_criteria
             WHERE stage = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC",
            [$stage]
        );
    }

    public function findAllActive(): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_stage_exit_criteria')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT id, stage, field_key, is_mandatory, label, help_text, sort_order
             FROM crm_stage_exit_criteria
             WHERE is_active = 1
             ORDER BY stage ASC, sort_order ASC, id ASC"
        );
    }
}
