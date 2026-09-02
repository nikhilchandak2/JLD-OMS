<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmAccountContextRepository;
use App\Repositories\CrmAccountIssueRepository;
use App\Repositories\CrmCompetitorPositionRepository;
use App\Repositories\CrmContactRepository;
use App\Repositories\PartyRepository;
use App\Support\TableSchema;

/**
 * Composes the account-context snapshot used by the party record, the deal
 * screen (collapsed panels with filled/empty counts), and later the briefing
 * view (TASK 9). Search is FULLTEXT-backed with a LIKE fallback so a query
 * still matches rows that InnoDB has not yet committed into the FT index.
 */
class AccountContextService
{
    private Database $database;
    private CrmContactRepository $contacts;
    private CrmCompetitorPositionRepository $positions;
    private CrmAccountContextRepository $context;
    private CrmAccountIssueRepository $issues;
    private PartyRepository $parties;
    private AuditLogRepository $audit;
    private AccountContextPolicy $policy;
    private CompetitorPositionService $competitors;
    private AccountIssueService $issueService;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->contacts = new CrmContactRepository();
        $this->positions = new CrmCompetitorPositionRepository();
        $this->context = new CrmAccountContextRepository();
        $this->issues = new CrmAccountIssueRepository();
        $this->parties = new PartyRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new AccountContextPolicy();
        $this->competitors = new CompetitorPositionService();
        $this->issueService = new AccountIssueService();
        $this->config = require dirname(__DIR__, 2) . '/config/account_context.php';
    }

    /** @return array<string,mixed> */
    public function meta(array $actor = []): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::VIEW_CONTEXT);

        return [
            'influence_levels' => $this->config['influence_levels'],
            'relationship_strengths' => $this->config['relationship_strengths'],
            'preferred_channels' => $this->config['preferred_channels'],
            'intelligence_types' => $this->config['intelligence_types'],
            'reason_codes' => $this->config['reason_codes'],
            'issue_types' => $this->config['issue_types'],
            'issue_statuses' => $this->config['issue_statuses'],
            'default_resolution_window_days' => $this->config['default_resolution_window_days'],
        ];
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function snapshotForParty(int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::VIEW_CONTACTS);
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new AccountContextException('Party not found.');
        }

        $contactRows = $this->contacts->findByParty($partyId);
        $contactItems = array_map(fn($c) => $c->toArray(), $contactRows);
        $documentedContacts = 0;
        foreach ($contactItems as $item) {
            if (($item['influence_level'] ?? 'unknown') !== 'unknown'
                || ($item['relationship_strength'] ?? 'unknown') !== 'unknown'
                || trim((string)($item['context_notes'] ?? '')) !== ''
            ) {
                $documentedContacts++;
            }
        }

        $issueItems = array_map([$this->issueService, 'present'], $this->issues->findByParty($partyId));
        $issueCounts = $this->issues->countsByParty($partyId);

        $contextRow = $this->context->findByParty($partyId);
        $contextFilled = $contextRow !== null && (
            trim((string)($contextRow['production_capacity_note'] ?? '')) !== ''
            || trim((string)($contextRow['seasonality_note'] ?? '')) !== ''
        );

        $snapshot = [
            'party_id' => $partyId,
            'contacts' => [
                'count' => count($contactItems),
                'documented_count' => $documentedContacts,
                'empty' => count($contactItems) === 0,
                'items' => $contactItems,
            ],
            'issues' => [
                'open_count' => $issueCounts['open'],
                'escalated_count' => $issueCounts['escalated'],
                'resolved_count' => $issueCounts['resolved'],
                'empty' => array_sum($issueCounts) === 0,
                'items' => $issueItems,
            ],
            'context' => [
                'filled' => $contextFilled,
                'production_capacity_note' => $contextRow['production_capacity_note'] ?? null,
                'seasonality_note' => $contextRow['seasonality_note'] ?? null,
                'updated_at' => $contextRow['updated_at'] ?? null,
                'updated_by_name' => $contextRow['updated_by_name'] ?? null,
            ],
        ];

        if ($this->policy->can($actor['role'] ?? null, AccountContextPolicy::VIEW_COMPETITOR)) {
            $positionRows = array_map([$this->competitors, 'present'], $this->positions->findByParty($partyId));
            $current = array_values(array_filter($positionRows, fn($r) => !empty($r['is_current'])));
            $history = array_values(array_filter($positionRows, fn($r) => empty($r['is_current'])));
            $snapshot['competitors'] = [
                'current_count' => count($current),
                'history_count' => count($history),
                'empty' => count($positionRows) === 0,
                'current' => $current,
                'history' => $history,
            ];
        }

        return $this->policy->serializeSnapshot($snapshot, $actor['role'] ?? null);
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function upsertContext(int $partyId, array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::EDIT_CONTEXT);
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new AccountContextException('Party not found.');
        }

        $capacity = trim((string)($input['production_capacity_note'] ?? ''));
        $seasonality = trim((string)($input['seasonality_note'] ?? ''));
        $old = $this->context->findByParty($partyId);

        $this->context->upsert($partyId, [
            'production_capacity_note' => $capacity === '' ? null : $capacity,
            'seasonality_note' => $seasonality === '' ? null : $seasonality,
            'updated_by_user_id' => $actor['id'] ?? null,
        ]);
        $this->audit->log(
            $actor['id'] ?? null,
            'crm_account_context',
            $partyId,
            $old ? 'UPDATE' : 'CREATE',
            $old ? [
                'production_capacity_note' => $old['production_capacity_note'],
                'seasonality_note' => $old['seasonality_note'],
            ] : null,
            [
                'production_capacity_note' => $capacity === '' ? null : $capacity,
                'seasonality_note' => $seasonality === '' ? null : $seasonality,
            ]
        );

        $snapshot = $this->snapshotForParty($partyId, $actor);

        return $snapshot['context'];
    }

    /**
     * Full-text search across contact names, competitor names, and issue descriptions.
     *
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function search(string $query, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, AccountContextPolicy::SEARCH);
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new AccountContextException('Enter at least two characters to search.');
        }

        $like = '%' . $this->escapeLike($query) . '%';
        $boolean = $this->toBooleanQuery($query);

        $contactMatch = TableSchema::hasIndex('crm_contacts', 'ft_crm_contacts_name')
            ? "MATCH(c.name) AGAINST(? IN BOOLEAN MODE) OR c.name LIKE ? ESCAPE '\\\\'"
            : "c.name LIKE ? ESCAPE '\\\\'";
        $contactParams = TableSchema::hasIndex('crm_contacts', 'ft_crm_contacts_name')
            ? [$boolean, $like]
            : [$like];
        $contactSelect = TableSchema::hasColumn('crm_contacts', 'influence_level')
            ? 'c.id, c.party_id, c.name, c.role, c.influence_level, p.name AS party_name'
            : "c.id, c.party_id, c.name, c.role, 'unknown' AS influence_level, p.name AS party_name";

        $contacts = TableSchema::hasTable('crm_contacts')
            ? $this->database->fetchAll(
                "SELECT {$contactSelect}
             FROM crm_contacts c
             JOIN parties p ON p.id = c.party_id
             WHERE {$contactMatch}
             ORDER BY c.name
             LIMIT 25",
                $contactParams
            )
            : [];

        $issueMatch = TableSchema::hasIndex('crm_account_issues', 'ft_issue_description')
            ? "MATCH(i.description) AGAINST(? IN BOOLEAN MODE) OR i.description LIKE ? ESCAPE '\\\\'"
            : "i.description LIKE ? ESCAPE '\\\\'";
        $issueParams = TableSchema::hasIndex('crm_account_issues', 'ft_issue_description')
            ? [$boolean, $like]
            : [$like];
        $issueStatus = TableSchema::hasColumn('crm_account_issues', 'status')
            ? 'i.status'
            : "'open' AS status";

        $issues = TableSchema::hasTable('crm_account_issues')
            ? $this->database->fetchAll(
                "SELECT i.id, i.party_id, i.issue_type, {$issueStatus}, i.description, i.raised_on, p.name AS party_name
                 FROM crm_account_issues i
                 JOIN parties p ON p.id = i.party_id
                 WHERE {$issueMatch}
                 ORDER BY i.raised_on DESC
                 LIMIT 25",
                $issueParams
            )
            : [];

        $competitors = [];
        if ($this->policy->can($actor['role'] ?? null, AccountContextPolicy::VIEW_COMPETITOR)
            && TableSchema::hasTable('crm_competitor_positions')
        ) {
            $compMatch = TableSchema::hasIndex('crm_competitor_positions', 'ft_competitor_name')
                ? "MATCH(c.competitor_name) AGAINST(? IN BOOLEAN MODE) OR c.competitor_name LIKE ? ESCAPE '\\\\'"
                : "c.competitor_name LIKE ? ESCAPE '\\\\'";
            $compParams = TableSchema::hasIndex('crm_competitor_positions', 'ft_competitor_name')
                ? [$boolean, $like]
                : [$like];
            $competitors = $this->database->fetchAll(
                "SELECT c.id, c.party_id, c.competitor_name, c.grade_code, c.intelligence_type,
                        c.is_current, c.estimated_share_pct, p.name AS party_name
                 FROM crm_competitor_positions c
                 JOIN parties p ON p.id = c.party_id
                 WHERE {$compMatch}
                 ORDER BY c.is_current DESC, c.recorded_at DESC
                 LIMIT 25",
                $compParams
            );
            $competitors = array_map(function (array $row) {
                $row['id'] = (int)$row['id'];
                $row['party_id'] = (int)$row['party_id'];
                $row['is_current'] = (int)$row['is_current'] === 1;
                $row['kind'] = 'competitor';

                return $row;
            }, $competitors);
        }

        return [
            'query' => $query,
            'contacts' => array_map(function (array $row) {
                $row['id'] = (int)$row['id'];
                $row['party_id'] = (int)$row['party_id'];
                $row['kind'] = 'contact';

                return $row;
            }, $contacts),
            'competitors' => $competitors,
            'issues' => array_map(function (array $row) {
                $row['id'] = (int)$row['id'];
                $row['party_id'] = (int)$row['party_id'];
                $row['kind'] = 'issue';
                $row['issue_type_label'] = $this->config['issue_types'][$row['issue_type']] ?? $row['issue_type'];

                return $row;
            }, $issues),
        ];
    }

    private function toBooleanQuery(string $query): string
    {
        $cleaned = preg_replace('/[+\-<>()~*"@]/', ' ', $query) ?? $query;
        $words = preg_split('/\s+/', trim($cleaned)) ?: [];
        $parts = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }
            $parts[] = '+' . $word . '*';
        }

        return $parts === [] ? $query : implode(' ', $parts);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
