<?php
/**
 * Training dataset for the CRM (TASK 11).
 * ~30 parties, ~60 deals across 7 stages, contacts, competitors, visits,
 * a forecast period, plus dormant and escalated accounts after the nightly job.
 *
 * Usage: php scripts/seed_crm_demo.php --yes
 * Safe on a copy of the database. It does not truncate existing live data;
 * it only inserts labelled "Demo *" rows.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "Refusing to seed without --yes.\nUsage: php scripts/seed_crm_demo.php --yes\n");
    exit(1);
}

use App\Core\Database;
use App\Services\AccountContextService;
use App\Services\AccountIssueService;
use App\Services\CompetitorPositionService;
use App\Services\CrmNightlyJobService;
use App\Services\DealService;
use App\Services\DealStageService;
use App\Services\ForecastService;
use App\Services\HandoffService;
use App\Services\VisitService;

$db = new Database();
$adminRow = $db->fetch("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
if ($adminRow === null) {
    fwrite(STDERR, "No active admin user. Create one first.\n");
    exit(1);
}
$admin = ['id' => (int)$adminRow['id'], 'role' => 'admin'];

$salesRow = $db->fetch("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'sales' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$sales = $salesRow ? ['id' => (int)$salesRow['id'], 'role' => 'sales'] : $admin;

$company = $db->fetch("SELECT id FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
$companyId = $company ? (int)$company['id'] : null;

$deals = new DealService();
$stages = new DealStageService();
$visits = new VisitService();
$competitors = new CompetitorPositionService();
$issues = new AccountIssueService();
$context = new AccountContextService();
$forecasts = new ForecastService();

$satisfy = static function (int $dealId, array $actor) use ($db, $stages): void {
    $evaluation = $stages->evaluateExitCriteria($dealId);
    $deal = $db->fetch('SELECT * FROM crm_deals WHERE id = ?', [$dealId]);
    $captured = [];
    foreach ($evaluation['criteria'] as $criterion) {
        if ($criterion['satisfied']) {
            continue;
        }
        switch ($criterion['field_key']) {
            case 'decision_maker_contact':
                $db->execute(
                    "INSERT INTO crm_contacts (party_id, name, role, is_primary) VALUES (?, 'Purchase Head', 'purchase_manager', 1)",
                    [(int)$deal['party_id']]
                );
                break;
            case 'sample_sent':
                $db->execute(
                    "INSERT INTO crm_samples (party_id, deal_id, sample_type, status, request_date)
                     VALUES (?, ?, 'J-11', 'sample_sent', CURDATE())",
                    [(int)$deal['party_id'], $dealId]
                );
                break;
            case 'credit_gate_cleared':
                $db->execute('UPDATE parties SET credit_limit = 10000000 WHERE id = ?', [(int)$deal['party_id']]);
                break;
            case 'handoff_packet_transferred':
                (new HandoffService())->create([
                    'packet_type' => HandoffService::TYPE_SALES_TO_DISPATCH,
                    'deal_id' => $dealId,
                    'payload' => [
                        'grades' => [['grade_code' => 'J-11', 'spec' => '12mm body']],
                        'quantity_tonnes' => 40,
                        'packing' => '50 kg bags',
                        'delivery_timeline' => 'Within 7 days of PO',
                        'delivery_terms' => 'ex_works',
                        'special_handling_notes' => 'None',
                    ],
                ], $actor);
                break;
            default:
                $captured[$criterion['field_key']] = 'demo value';
        }
    }
    if ($captured !== []) {
        $stages->saveCriteriaValues($dealId, $captured, $actor);
    }
};

$advanceTo = static function (int $dealId, int $stage, array $actor) use ($stages, $satisfy): void {
    $deal = ['stage' => 1];
    while ((int)$deal['stage'] < $stage) {
        $satisfy($dealId, $actor);
        $deal = $stages->advance($dealId, $actor);
    }
};

$partyIds = [];
for ($i = 1; $i <= 30; $i++) {
    $suffix = bin2hex(random_bytes(3));
    $db->execute(
        "INSERT INTO parties (name, contact_person, phone, email, address, is_active, credit_limit, assigned_sales_owner)
         VALUES (?, 'Demo Contact', ?, ?, 'Demo address', 1, 500000, ?)",
        [
            sprintf('Demo Tile Co %02d', $i),
            '98' . str_pad((string)$i, 8, '0', STR_PAD_LEFT),
            "demo{$suffix}@example.test",
            $sales['id'],
        ]
    );
    $partyIds[] = (int)$db->lastInsertId();
}

$grades = ['J-11', 'JJN-1', 'J-8'];
$targets = [];
for ($stage = 1; $stage <= 7; $stage++) {
    for ($n = 0; $n < ($stage === 1 ? 12 : 8); $n++) {
        $targets[] = $stage;
    }
}
$targets = array_slice($targets, 0, 60);

foreach ($targets as $idx => $stage) {
    $partyId = $partyIds[$idx % 30];
    $deal = $deals->captureInquiry([
        'party_id' => $partyId,
        'company_id' => $companyId,
        'source' => 'whatsapp',
        'grades' => $grades[$idx % 3],
        'indicative_quantity_tonnes' => 20 + ($idx % 15),
        'inquiry_date' => '2026-01-05',
        'value' => 25000 + ($idx * 500),
        'owner_user_id' => $sales['id'],
        'title' => 'Demo deal ' . ($idx + 1),
    ], $admin);
    $dealId = (int)$deal['id'];
    if ($stage > 1) {
        $advanceTo($dealId, $stage, $admin);
    }
}

foreach (array_slice($partyIds, 0, 20) as $i => $partyId) {
    $db->execute(
        "INSERT INTO crm_contacts (party_id, name, role, is_primary, influence_level, relationship_strength)
         VALUES (?, ?, 'purchase_manager', 1, 'decision_maker', 'strong')",
        [$partyId, 'Demo Buyer ' . ($i + 1)]
    );
    $context->upsertContext($partyId, [
        'production_capacity_note' => 'Two lines, ~8,000 m2/day.',
        'seasonality_note' => 'Q1 slow; monsoon slows dispatches.',
    ], $admin);
    $competitors->record($partyId, [
        'competitor_name' => $i % 2 === 0 ? 'Kajaria' : 'Somany',
        'intelligence_type' => 'reported',
        'reason_code' => 'price',
        'estimated_share_pct' => 30 + ($i % 20),
        'grade_code' => 'J-11',
    ], $admin);
    $issues->create($partyId, [
        'issue_type' => 'quality_complaint',
        'description' => 'Demo shade variation on last lot.',
        'raised_on' => '2026-07-01',
    ], $admin);
    $visits->log([
        'party_id' => $partyId,
        'visit_date' => '2026-07-15',
        'next_planned_touchpoint' => '2026-08-20',
        'outcome' => 'Demo visit. Follow up on trial.',
    ], $sales);
}

$ym = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m');
try {
    $period = $forecasts->openPeriod($ym, $admin);
} catch (Throwable $e) {
    $period = null;
}
if ($period) {
    foreach (array_slice($partyIds, 0, 10) as $partyId) {
        $forecasts->savePartyLines((int)$period['id'], $partyId, [
            ['grade_code' => 'J-11', 'qty_low_tonnes' => 10, 'qty_high_tonnes' => 14],
        ], $admin);
    }
}

$asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$nightly = (new CrmNightlyJobService())->run($asOf);

echo json_encode([
    'parties' => count($partyIds),
    'deals' => 60,
    'nightly' => $nightly['status'] ?? null,
    'pipeline_deals' => $nightly['pipeline_deals'] ?? null,
    'dormancy_signals' => $nightly['signals'] ?? null,
], JSON_PRETTY_PRINT) . "\n";
