<?php

namespace App\Services;

use App\Repositories\DataFeedRepository;
use App\Repositories\DataFeedRunRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Authoritative freshness stamps for batch-sourced ledger and dispatch figures.
 *
 * asOf is per legal entity. groupAsOf (B6) is the OLDEST contributing entity's
 * as_of, never the newest, and always names missing or lagging entities.
 */
class DataFreshnessService
{
    public const STATE_FRESH = 'fresh';
    public const STATE_LATE = 'late';
    public const STATE_STALE = 'stale';
    public const STATE_MISSING = 'missing';
    public const STATE_MISSING_ENTITY = 'missing_entity';

    private DataFeedRepository $feeds;
    private DataFeedRunRepository $runs;
    private array $config;
    private DateTimeZone $tz;

    public function __construct()
    {
        $this->feeds = new DataFeedRepository();
        $this->runs = new DataFeedRunRepository();
        $this->config = require dirname(__DIR__, 2) . '/config/data_feeds.php';
        $this->tz = new DateTimeZone($this->config['timezone'] ?? 'Asia/Kolkata');
    }

    /**
     * Latest completed run for one feed + company, plus staleness against the deadline in IST.
     *
     * @return array{
     *   feed_key: string,
     *   company_id: int,
     *   company_name: ?string,
     *   as_of: ?string,
     *   business_date: ?string,
     *   state: string,
     *   deadline_local_time: string,
     *   owner_user_id: ?int,
     *   expected_business_date: string
     * }
     */
    public function asOf(string $feedKey, int $companyId, ?DateTimeInterface $now = null): array
    {
        $this->assertFeedKey($feedKey);
        $this->feeds->ensureForCompany($companyId);

        $now = $this->now($now);
        $feed = $this->feeds->findByKeyAndCompany($feedKey, $companyId);
        $deadline = $feed['deadline_local_time'] ?? ($this->config['feeds'][$feedKey]['deadline_local_time'] ?? '09:00:00');
        $expected = $this->expectedBusinessDate($now, (string)$deadline);

        $run = $this->runs->latestCompleted($feedKey, $companyId);
        $company = $feed['company_name'] ?? null;
        if ($company === null) {
            $listed = $this->feeds->listActiveByKey($feedKey);
            foreach ($listed as $row) {
                if ((int)$row['company_id'] === $companyId) {
                    $company = $row['company_name'];
                    break;
                }
            }
        }

        if (!$run) {
            return [
                'feed_key' => $feedKey,
                'company_id' => $companyId,
                'company_name' => $company,
                'as_of' => null,
                'business_date' => null,
                'state' => self::STATE_MISSING,
                'deadline_local_time' => $deadline,
                'owner_user_id' => $feed['owner_user_id'] ?? null,
                'expected_business_date' => $expected,
            ];
        }

        $daysLate = $this->businessDaysBetween($run['business_date'], $expected);

        if ($daysLate <= 0) {
            $state = self::STATE_FRESH;
        } elseif ($daysLate === 1) {
            $state = self::STATE_LATE;
        } else {
            $state = self::STATE_STALE;
        }

        return [
            'feed_key' => $feedKey,
            'company_id' => $companyId,
            'company_name' => $company,
            'as_of' => $run['as_of'],
            'business_date' => $run['business_date'],
            'state' => $state,
            'deadline_local_time' => $deadline,
            'owner_user_id' => $feed['owner_user_id'] ?? null,
            'expected_business_date' => $expected,
            'run_id' => (int)$run['id'],
        ];
    }

    /**
     * Group-wide freshness (B6). The group as_of is the oldest contributing
     * entity. A missing entity is reported, never omitted.
     *
     * @return array{
     *   feed_key: string,
     *   as_of: ?string,
     *   state: string,
     *   lagging_entity: ?array,
     *   missing_entities: array,
     *   entities: array
     * }
     */
    public function groupAsOf(string $feedKey, ?DateTimeInterface $now = null): array
    {
        $this->assertFeedKey($feedKey);
        $this->feeds->ensureForAllCompanies();
        $now = $this->now($now);

        $feeds = $this->feeds->listActiveByKey($feedKey);
        $entities = [];
        foreach ($feeds as $feed) {
            $entities[] = $this->asOf($feedKey, (int)$feed['company_id'], $now);
        }

        $missing = array_values(array_filter($entities, static fn($e) => $e['state'] === self::STATE_MISSING));
        $contributing = array_values(array_filter($entities, static fn($e) => $e['as_of'] !== null));

        $lagging = null;
        $oldestAsOf = null;
        foreach ($contributing as $entity) {
            if ($oldestAsOf === null || strcmp((string)$entity['as_of'], $oldestAsOf) < 0) {
                $oldestAsOf = $entity['as_of'];
                $lagging = [
                    'company_id' => $entity['company_id'],
                    'company_name' => $entity['company_name'],
                    'as_of' => $entity['as_of'],
                    'state' => $entity['state'],
                    'business_date' => $entity['business_date'],
                ];
            }
        }

        if ($missing !== []) {
            $state = self::STATE_MISSING_ENTITY;
        } else {
            $rank = [self::STATE_FRESH => 0, self::STATE_LATE => 1, self::STATE_STALE => 2];
            $state = self::STATE_FRESH;
            foreach ($entities as $entity) {
                if (($rank[$entity['state']] ?? 0) > ($rank[$state] ?? 0)) {
                    $state = $entity['state'];
                }
            }
        }

        return [
            'feed_key' => $feedKey,
            'as_of' => $oldestAsOf,
            'state' => $state,
            'lagging_entity' => $lagging,
            'missing_entities' => array_map(static fn($e) => [
                'company_id' => $e['company_id'],
                'company_name' => $e['company_name'],
            ], $missing),
            'entities' => $entities,
        ];
    }

    public function bannerPayload(string $feedKey, ?int $companyId, bool $group = true, ?DateTimeInterface $now = null): array
    {
        $freshness = $group || $companyId === null
            ? $this->groupAsOf($feedKey, $now)
            : $this->asOf($feedKey, $companyId, $now);

        return array_merge($freshness, [
            'label' => $feedKey === 'ledger' ? 'Ledger' : 'Dispatch',
            'tone' => $this->tone($freshness['state']),
            'message' => $this->message($feedKey, $freshness),
        ]);
    }

    private function message(string $feedKey, array $freshness): string
    {
        $label = $feedKey === 'ledger' ? 'Ledger' : 'Dispatch';
        $state = $freshness['state'];

        if ($state === self::STATE_MISSING_ENTITY) {
            $names = array_map(static fn($e) => $e['company_name'] ?: ('#' . $e['company_id']), $freshness['missing_entities'] ?? []);
            $named = implode(', ', $names);
            $asOf = $freshness['as_of'] ? $this->formatStamp($freshness['as_of']) : 'no contributing feed';

            return "{$label} is incomplete — {$named} has no uploaded file. "
                . "The group figure is as of {$asOf} and must not be treated as live.";
        }

        if ($state === self::STATE_MISSING) {
            $name = $freshness['company_name'] ?? 'this entity';

            return "{$label} has not been uploaded for {$name}. Figures are not live.";
        }

        $stamp = $this->formatStamp($freshness['as_of'] ?? '');
        $lag = $freshness['lagging_entity']['company_name'] ?? $freshness['company_name'] ?? null;

        if ($state === self::STATE_FRESH) {
            return "{$label} as of {$stamp} IST — fresh. Not live.";
        }

        if ($state === self::STATE_LATE) {
            $who = $lag ? " Lagging entity: {$lag}." : '';

            return "{$label} as of {$stamp} IST — past today's deadline.{$who} Not live.";
        }

        $who = $lag ? " Lagging entity: {$lag}." : '';

        return "{$label} as of {$stamp} IST — more than one business day old.{$who} Not live.";
    }

    private function tone(string $state): string
    {
        return match ($state) {
            self::STATE_FRESH => 'fresh',
            self::STATE_LATE => 'late',
            self::STATE_STALE, self::STATE_MISSING, self::STATE_MISSING_ENTITY => 'stale',
            default => 'stale',
        };
    }

    private function formatStamp(string $asOf): string
    {
        if ($asOf === '') {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($asOf, $this->tz);

            return $dt->format('d M Y, H:i');
        } catch (\Exception $e) {
            return $asOf;
        }
    }

    private function expectedBusinessDate(DateTimeImmutable $now, string $deadline): string
    {
        $today = $now->format('Y-m-d');
        $deadlineAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . $this->normalizeTime($deadline), $this->tz);
        if (!$deadlineAt) {
            $deadlineAt = $now;
        }

        if ($now < $deadlineAt) {
            return $this->previousBusinessDay($now)->format('Y-m-d');
        }

        return $today;
    }

    private function previousBusinessDay(DateTimeImmutable $from): DateTimeImmutable
    {
        $day = $from->sub(new DateInterval('P1D'));
        while (in_array((int)$day->format('N'), [6, 7], true)) {
            $day = $day->sub(new DateInterval('P1D'));
        }

        return $day;
    }

    private function businessDaysBetween(string $fromDate, string $toDate): int
    {
        $from = new DateTimeImmutable($fromDate, $this->tz);
        $to = new DateTimeImmutable($toDate, $this->tz);
        if ($from >= $to) {
            return 0;
        }

        $days = 0;
        $cursor = $from;
        while ($cursor < $to) {
            $cursor = $cursor->add(new DateInterval('P1D'));
            if (!in_array((int)$cursor->format('N'), [6, 7], true)) {
                $days++;
            }
        }

        return $days;
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time . ':00';
        }

        return '09:00:00';
    }

    private function now(?DateTimeInterface $now): DateTimeImmutable
    {
        if ($now instanceof DateTimeImmutable) {
            return $now->setTimezone($this->tz);
        }
        if ($now instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($now)->setTimezone($this->tz);
        }

        return new DateTimeImmutable('now', $this->tz);
    }

    private function assertFeedKey(string $feedKey): void
    {
        if (!isset($this->config['feeds'][$feedKey])) {
            throw new DataFeedException("Unknown feed_key '{$feedKey}'.");
        }
    }
}
