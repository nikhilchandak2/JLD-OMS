<?php
/**
 * Backfill the 7-stage deal pipeline from the legacy 5-stage party funnel.
 *
 * The old funnel was stored on parties.funnel_stage (one stage per company). The new pipeline
 * belongs to crm_deals, so each party carrying a legacy funnel stage becomes one deal.
 *
 * DRY RUN IS THE DEFAULT. Nothing is written unless --execute AND --confirm are both passed.
 * Every run writes a CSV of proposed changes for review before anything is executed.
 *
 * Usage:
 *   php scripts/backfill_crm_pipeline.php                      # dry run, writes CSV
 *   php scripts/backfill_crm_pipeline.php --out=/tmp/plan.csv  # dry run to a chosen path
 *   php scripts/backfill_crm_pipeline.php --execute --confirm=I-HAVE-A-BACKUP
 *
 * Mapping (from the task brief):
 *   sampling          -> stage 3 (Sample / Trial Dispatched)
 *   technical_support -> stage 3 + an open technical flag
 *   re_sampling       -> stage 3 (the fact that it is a re-sample lives on the sample record)
 *   trial_order       -> stage 4 (Trial Feedback & Fit)          AMBIGUOUS
 *   closed            -> terminal, needs a human to say won or lost   AMBIGUOUS - never guessed
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$options = parseArgs($argv);
$execute = isset($options['execute']);
$confirmation = $options['confirm'] ?? null;
$csvPath = $options['out'] ?? sys_get_temp_dir() . '/crm_pipeline_backfill_' . date('Ymd_His') . '.csv';

const CONFIRM_PHRASE = 'I-HAVE-A-BACKUP';

const STAGE_MAP = [
    'sampling' => ['stage' => 3, 'status' => 'active', 'flag' => false, 'ambiguous' => false,
        'note' => 'Sample or trial dispatched.'],
    'technical_support' => ['stage' => 3, 'status' => 'active', 'flag' => true, 'ambiguous' => false,
        'note' => 'Technical support is a hold, not a stage: deal sits at stage 3 with an open technical flag.'],
    're_sampling' => ['stage' => 3, 'status' => 'active', 'flag' => false, 'ambiguous' => false,
        'note' => 'Re-sampling is still stage 3; the re-sample itself is recorded on the sample record.'],
    'trial_order' => ['stage' => 4, 'status' => 'active', 'flag' => false, 'ambiguous' => true,
        'note' => 'AMBIGUOUS: a trial order could mean trial feedback pending (stage 4) or an order already placed (stage 7). Assumed stage 4 - confirm per customer.'],
    'closed' => ['stage' => 7, 'status' => 'REVIEW', 'flag' => false, 'ambiguous' => true,
        'note' => 'AMBIGUOUS: closed does not say won or lost. Left for a human decision; nothing is written for these rows.'],
];

if ($execute && $confirmation !== CONFIRM_PHRASE) {
    fwrite(STDERR, "Refusing to write.\n");
    fwrite(STDERR, "--execute also requires --confirm=" . CONFIRM_PHRASE . ", and a reviewed dry-run CSV.\n");
    exit(1);
}

try {
    $database = new Database();
    $pdo = $database->getConnection();

    $parties = $database->fetchAll(
        "SELECT p.id, p.name, p.funnel_stage, p.assigned_sales_owner,
                (SELECT COUNT(*) FROM crm_deals d WHERE d.party_id = p.id AND d.deleted_at IS NULL) AS existing_deals
         FROM parties p
         WHERE p.funnel_stage IS NOT NULL AND p.funnel_stage <> ''
         ORDER BY p.id ASC"
    );

    $legacyDeals = $database->fetchAll(
        "SELECT id, party_id, legacy_funnel_stage, stage, status
         FROM crm_deals
         WHERE legacy_funnel_stage IS NOT NULL AND deleted_at IS NULL"
    );

    $archivedLeads = tableExists($pdo, '_archived_crm_leads')
        ? (int)($database->fetch("SELECT COUNT(*) AS c FROM _archived_crm_leads")['c'] ?? 0)
        : null;

    $rows = [];
    $counts = ['plan' => 0, 'skip_existing' => 0, 'review' => 0, 'unmapped' => 0, 'flags' => 0];

    foreach ($parties as $party) {
        $legacyStage = (string)$party['funnel_stage'];
        $mapping = STAGE_MAP[$legacyStage] ?? null;

        if ($mapping === null) {
            $counts['unmapped']++;
            $rows[] = backfillRow($party, $legacyStage, '', '', 'yes', 'no',
                'UNMAPPED legacy stage - not written. Decide the target stage manually.');
            continue;
        }

        if ((int)$party['existing_deals'] > 0) {
            $counts['skip_existing']++;
            $rows[] = backfillRow($party, $legacyStage, (string)$mapping['stage'], $mapping['status'], 'no', 'no',
                'Party already has a deal - skipped so the job stays re-runnable without duplicating.');
            continue;
        }

        if ($mapping['status'] === 'REVIEW') {
            $counts['review']++;
            $rows[] = backfillRow($party, $legacyStage, (string)$mapping['stage'], 'won-or-lost?', 'yes', 'no',
                $mapping['note']);
            continue;
        }

        $counts['plan']++;
        if ($mapping['flag']) {
            $counts['flags']++;
        }
        $rows[] = backfillRow($party, $legacyStage, (string)$mapping['stage'], $mapping['status'],
            $mapping['ambiguous'] ? 'yes' : 'no', $mapping['flag'] ? 'yes' : 'no', $mapping['note']);
    }

    writeCsv($csvPath, $rows);

    echo "Legacy party funnel rows found: " . count($parties) . "\n";
    echo "  will create a deal:            {$counts['plan']}\n";
    echo "  will also raise a technical flag: {$counts['flags']}\n";
    echo "  needs a human decision (closed): {$counts['review']}\n";
    echo "  skipped, party already has a deal: {$counts['skip_existing']}\n";
    echo "  unmapped legacy stage:          {$counts['unmapped']}\n";
    echo "Legacy crm_deals rows carrying a legacy funnel stage: " . count($legacyDeals) . "\n";
    echo '_archived_crm_leads rows: ' . ($archivedLeads === null ? 'table not present' : $archivedLeads) . "\n";
    echo "Plan CSV: {$csvPath}\n";

    if (!$execute) {
        echo "\nDRY RUN - nothing was written. Review the CSV, then re-run with:\n";
        echo "  php scripts/backfill_crm_pipeline.php --execute --confirm=" . CONFIRM_PHRASE . "\n";
        exit(0);
    }

    $queueId = (int)($database->fetch(
        "SELECT id FROM crm_technical_queues WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
    )['id'] ?? 0);
    if ($counts['flags'] > 0 && $queueId === 0) {
        fwrite(STDERR, "No active technical queue exists; cannot raise the technical flags this plan needs.\n");
        exit(1);
    }

    $created = 0;
    $flagsRaised = 0;
    $failures = [];

    foreach ($parties as $party) {
        $legacyStage = (string)$party['funnel_stage'];
        $mapping = STAGE_MAP[$legacyStage] ?? null;
        if ($mapping === null || $mapping['status'] === 'REVIEW' || (int)$party['existing_deals'] > 0) {
            continue;
        }

        // Per-party transaction: one bad row is reported, it does not abort the whole run,
        // and it never leaves a deal without its opening stage event.
        try {
            $pdo->beginTransaction();

            $database->query(
                // company_id stays NULL: parties are not company-scoped in this schema, and the
                // commercial view is shared group-wide anyway.
                "INSERT INTO crm_deals
                    (party_id, owner_user_id, title, stage, status, source, stage_entered_at,
                     inquiry_date, legacy_funnel_stage, notes, created_at)
                 VALUES (?, ?, ?, ?, 'active', 'other', NOW(), CURDATE(), ?, ?, NOW())",
                [
                    (int)$party['id'],
                    $party['assigned_sales_owner'] !== null ? (int)$party['assigned_sales_owner'] : null,
                    $party['name'] . ' - migrated from legacy funnel',
                    (int)$mapping['stage'],
                    $legacyStage,
                    'Created by scripts/backfill_crm_pipeline.php from legacy funnel stage "' . $legacyStage . '". ' . $mapping['note'],
                ]
            );
            $dealId = (int)$pdo->lastInsertId();

            $database->query(
                "INSERT INTO crm_deal_stage_events
                    (deal_id, from_stage, to_stage, from_status, to_status, reason_note, occurred_at)
                 VALUES (?, NULL, ?, NULL, 'active', ?, NOW())",
                [
                    $dealId,
                    (int)$mapping['stage'],
                    'Backfilled from legacy funnel stage "' . $legacyStage . '".',
                ]
            );

            if ($mapping['flag']) {
                $database->query(
                    "INSERT INTO crm_technical_flags
                        (deal_id, party_id, raised_from_stage, nature_of_query, routed_to_queue_id,
                         expected_turnaround_at, status, created_at)
                     VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), 'open', NOW())",
                    [
                        $dealId,
                        (int)$party['id'],
                        (int)$mapping['stage'],
                        'Carried over from the legacy "Technical Support" funnel stage. The original query was not recorded - confirm with the customer.',
                        $queueId,
                    ]
                );
                $flagsRaised++;
            }

            $pdo->commit();
            $created++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failures[] = 'party ' . $party['id'] . ' (' . $party['name'] . '): ' . $e->getMessage();
        }
    }

    echo "\nEXECUTED\n";
    echo "  deals created:  {$created}\n";
    echo "  flags raised:   {$flagsRaised}\n";
    echo '  failures:       ' . count($failures) . "\n";
    foreach ($failures as $failure) {
        echo "    - {$failure}\n";
    }

    exit(empty($failures) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}

function parseArgs(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }

    return $options;
}

function backfillRow(
    array $party,
    string $legacyStage,
    string $newStage,
    string $newStatus,
    string $ambiguous,
    string $flag,
    string $note
): array {
    return [
        'party_id' => $party['id'],
        'party_name' => $party['name'],
        'legacy_funnel_stage' => $legacyStage,
        'proposed_stage' => $newStage,
        'proposed_status' => $newStatus,
        'ambiguous' => $ambiguous,
        'raises_technical_flag' => $flag,
        'existing_deals' => $party['existing_deals'],
        'note' => $note,
    ];
}

function writeCsv(string $path, array $rows): void
{
    $handle = fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException("Cannot write the plan CSV to {$path}");
    }

    $headers = ['party_id', 'party_name', 'legacy_funnel_stage', 'proposed_stage', 'proposed_status',
        'ambiguous', 'raises_technical_flag', 'existing_deals', 'note'];
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn($header) => $row[$header] ?? '', $headers));
    }
    fclose($handle);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}
