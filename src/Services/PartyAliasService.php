<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\PartySourceAliasRepository;

/**
 * Resolves feed party names/codes to parties.id. Never creates a party.
 * A resolved alias is remembered so the same source identifier is not asked twice.
 */
class PartyAliasService
{
    private Database $database;
    private PartySourceAliasRepository $aliases;
    private AuditLogRepository $audit;
    private DataFeedPolicy $policy;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->aliases = new PartySourceAliasRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new DataFeedPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/data_feeds.php';
    }

    public function sourceSystemFor(string $feedKey): string
    {
        return $this->config['feeds'][$feedKey]['source_system'] ?? 'busy';
    }

    public static function normalizeIdentifier(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtoupper($value);
    }

    /**
     * @param array<string,int> $aliasMap preloaded source_identifier => party_id
     * @param array<string,int> $nameMap preloaded normalized name => party_id
     */
    public function resolveFromMaps(
        string $sourceSystem,
        string $partyName,
        string $partyCode,
        array $aliasMap,
        array $nameMap
    ): ?int {
        foreach ([$partyCode, $partyName] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $key = self::normalizeIdentifier($candidate);
            if (isset($aliasMap[$key])) {
                return $aliasMap[$key];
            }
        }

        if ($partyName !== '') {
            $key = self::normalizeIdentifier($partyName);
            if (isset($nameMap[$key])) {
                return $nameMap[$key];
            }
        }

        if ($partyCode !== '') {
            $key = self::normalizeIdentifier($partyCode);
            if (isset($nameMap[$key])) {
                return $nameMap[$key];
            }
        }

        return null;
    }

    /** @return array<string,int> */
    public function aliasMap(string $sourceSystem): array
    {
        return $this->aliases->mapForSystem($sourceSystem);
    }

    /** @return array<string,int> */
    public function partyNameMap(): array
    {
        $rows = $this->database->fetchAll("SELECT id, name FROM parties WHERE is_active = 1");
        $map = [];
        foreach ($rows as $row) {
            $map[self::normalizeIdentifier((string)$row['name'])] = (int)$row['id'];
        }

        return $map;
    }

    /**
     * Manual resolution. Writes the alias so the next validate pass can accept the row.
     */
    public function resolveManually(string $sourceSystem, string $identifier, int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::RESOLVE_ALIAS);

        if (!in_array($sourceSystem, ['busy', 'dispatch'], true)) {
            throw new DataFeedException('source_system must be busy or dispatch.');
        }

        $identifier = self::normalizeIdentifier($identifier);
        if ($identifier === '') {
            throw new DataFeedException('source_identifier is required.');
        }

        $party = $this->database->fetch("SELECT id, name FROM parties WHERE id = ?", [$partyId]);
        if (!$party) {
            throw new DataFeedException('Party not found.');
        }

        $existing = $this->aliases->find($sourceSystem, $identifier);
        if ($existing) {
            return $existing;
        }

        $id = $this->aliases->create([
            'source_system' => $sourceSystem,
            'source_identifier' => $identifier,
            'party_id' => $partyId,
            'confidence' => 'manual',
            'created_by_user_id' => $actor['id'] ?? null,
        ]);

        $this->audit->log(
            $actor['id'] ?? null,
            'party_source_aliases',
            $id,
            'create',
            null,
            ['source_system' => $sourceSystem, 'source_identifier' => $identifier, 'party_id' => $partyId]
        );

        return $this->aliases->find($sourceSystem, $identifier) ?? [
            'id' => $id,
            'source_system' => $sourceSystem,
            'source_identifier' => $identifier,
            'party_id' => $partyId,
        ];
    }

    public function unmatchedQueue(): array
    {
        $rows = (new \App\Repositories\DataFeedRowRepository())->unmatchedPartyRows();
        $seen = [];
        $queued = [];
        foreach ($rows as $row) {
            $raw = $row['raw'] ?? [];
            $identifier = self::normalizeIdentifier((string)(($raw['party_code'] ?? '') !== '' ? $raw['party_code'] : ($raw['party_name'] ?? '')));
            if ($identifier === '' || isset($seen[$identifier])) {
                continue;
            }
            $seen[$identifier] = true;
            $sourceSystem = $this->sourceSystemFor((string)$row['feed_key']);
            $queued[] = [
                'source_system' => $sourceSystem,
                'source_identifier' => $identifier,
                'party_name' => $raw['party_name'] ?? '',
                'party_code' => $raw['party_code'] ?? '',
                'feed_key' => $row['feed_key'],
                'company_id' => (int)$row['company_id'],
                'company_name' => $row['company_name'],
                'business_date' => $row['business_date'],
                'run_id' => (int)$row['run_id'],
                'sample_row_number' => (int)$row['row_number'],
            ];
        }

        return $queued;
    }
}
