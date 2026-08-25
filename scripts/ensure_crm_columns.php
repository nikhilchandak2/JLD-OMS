<?php
/**
 * Add CRM columns/indexes the live DB is missing, without AFTER.
 */
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

App\Support\CrmSchemaEnsure::apply();
echo "OK\n";
