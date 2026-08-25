<?php

namespace App\Services;

use App\Core\Database;
use App\Models\CrmContact;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmContactRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\CrmVisitRepository;
use App\Repositories\PartyRepository;

/**
 * Sales visit logging. next_planned_touchpoint is required unless the rep
 * actively selects "no follow-up needed" and supplies a reason.
 */
class VisitService
{
    public const VIA_WEB = 'web';
    public const VIA_MOBILE = 'mobile';
    public const VIA_VOICE = 'voice';

    private Database $database;
    private CrmVisitRepository $visits;
    private CrmContactRepository $contacts;
    private PartyRepository $parties;
    private CrmDealRepository $deals;
    private AuditLogRepository $audit;
    private VisitPolicy $policy;

    public function __construct()
    {
        $this->database = new Database();
        $this->visits = new CrmVisitRepository();
        $this->contacts = new CrmContactRepository();
        $this->parties = new PartyRepository();
        $this->deals = new CrmDealRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new VisitPolicy();
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function log(array $input, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, VisitPolicy::LOG);

        $partyId = (int)($input['party_id'] ?? 0);
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new VisitException('A valid customer is required.');
        }

        $visitDate = trim((string)($input['visit_date'] ?? ''));
        if ($visitDate === '') {
            $visitDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }
        $this->assertDate($visitDate, 'visit_date');

        $noFollowup = !empty($input['no_followup_needed']);
        $reason = trim((string)($input['no_followup_reason'] ?? ''));
        $touchpoint = trim((string)($input['next_planned_touchpoint'] ?? ''));
        if ($touchpoint === '') {
            $touchpoint = null;
        }

        if ($noFollowup) {
            if ($reason === '') {
                throw new VisitException('A reason is required when no follow-up is needed.');
            }
            $touchpoint = null;
        } else {
            if ($touchpoint === null) {
                throw new VisitException('Next planned touchpoint is required, or choose “no follow-up needed” and give a reason.');
            }
            $this->assertDate($touchpoint, 'next_planned_touchpoint');
            $reason = null;
        }

        $dealId = isset($input['deal_id']) && $input['deal_id'] !== '' && $input['deal_id'] !== null
            ? (int)$input['deal_id']
            : null;
        if ($dealId !== null && $dealId > 0) {
            $deal = $this->deals->findById($dealId);
            if ($deal === null || (int)$deal['party_id'] !== $partyId) {
                throw new VisitException('Deal not found for this customer.');
            }
        } else {
            $dealId = null;
        }

        $via = (string)($input['logged_via'] ?? self::VIA_WEB);
        if (!in_array($via, [self::VIA_WEB, self::VIA_MOBILE, self::VIA_VOICE], true)) {
            $via = self::VIA_WEB;
        }

        $id = 0;
        $this->database->beginTransaction();
        try {
            $contactIds = $this->resolveContactIds($partyId, $input, $actor);
            $id = $this->visits->create([
                'party_id' => $partyId,
                'deal_id' => $dealId,
                'visited_by_user_id' => $actor['id'] ?? null,
                'visit_date' => $visitDate,
                'purpose' => trim((string)($input['purpose'] ?? '')) ?: null,
                'outcome' => trim((string)($input['outcome'] ?? '')) ?: null,
                'next_planned_touchpoint' => $touchpoint,
                'next_action' => trim((string)($input['next_action'] ?? '')) ?: null,
                'no_followup_needed' => $noFollowup,
                'no_followup_reason' => $reason,
                'logged_via' => $via,
            ]);
            foreach ($contactIds as $contactId) {
                $this->visits->attachContact($id, $contactId);
            }
            $this->database->execute(
                "UPDATE parties SET last_visit_date = ?
                 WHERE id = ? AND (last_visit_date IS NULL OR last_visit_date < ?)",
                [$visitDate, $partyId, $visitDate]
            );
            $this->audit->log($actor['id'] ?? null, 'crm_visits', $id, 'CREATE', null, [
                'party_id' => $partyId,
                'visit_date' => $visitDate,
                'next_planned_touchpoint' => $touchpoint,
                'no_followup_needed' => $noFollowup,
            ]);
            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        $row = $this->visits->findById($id);
        if ($row === null) {
            throw new VisitException('Visit could not be reloaded.');
        }

        return $this->present($row);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<int,array<string,mixed>>
     */
    public function listForParty(int $partyId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, VisitPolicy::VIEW);
        if ($partyId <= 0 || $this->parties->findById($partyId) === null) {
            throw new VisitException('Party not found.');
        }

        return array_map([$this, 'present'], $this->visits->findByParty($partyId));
    }

    /**
     * Reps see their own overdue follow-ups. Admin and CRM see everyone unless
     * scoped_to_self is set.
     *
     * @param array{id:?int,role:?string} $actor
     * @return array<int,array<string,mixed>>
     */
    public function overdue(array $actor, bool $allReps = false): array
    {
        $this->policy->assertCan($actor['role'] ?? null, VisitPolicy::VIEW);
        $role = $actor['role'] ?? null;
        $scopeAll = $allReps && in_array($role, ['admin', 'crm'], true);
        $ownerId = $scopeAll ? null : (int)($actor['id'] ?? 0);
        if (!$scopeAll && $ownerId <= 0) {
            return [];
        }

        return array_map([$this, 'present'], $this->visits->findOverdue($scopeAll ? null : $ownerId));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function explainOverdue(?int $visitedByUserId): array
    {
        return $this->visits->explainOverdue($visitedByUserId);
    }

    /** @param array<string,mixed> $row */
    public function present(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['party_id'] = (int)$row['party_id'];
        $row['deal_id'] = $row['deal_id'] === null ? null : (int)$row['deal_id'];
        $row['visited_by_user_id'] = $row['visited_by_user_id'] === null ? null : (int)$row['visited_by_user_id'];
        $row['no_followup_needed'] = (int)($row['no_followup_needed'] ?? 0) === 1;
        $row['contacts'] = $row['contacts'] ?? [];

        return $row;
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return int[]
     */
    private function resolveContactIds(int $partyId, array $input, array $actor): array
    {
        $ids = [];
        foreach ((array)($input['contact_ids'] ?? []) as $raw) {
            $cid = (int)$raw;
            if ($cid <= 0) {
                continue;
            }
            $contact = $this->contacts->findById($cid);
            if ($contact === null || $contact->partyId !== $partyId) {
                throw new VisitException('A selected contact does not belong to this customer.');
            }
            $ids[$cid] = $cid;
        }

        foreach ((array)($input['new_contacts'] ?? []) as $draft) {
            if (!is_array($draft)) {
                continue;
            }
            $name = trim((string)($draft['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $contact = new CrmContact();
            $contact->partyId = $partyId;
            $contact->name = $name;
            $contact->role = trim((string)($draft['role'] ?? ''));
            $contact->phone = trim((string)($draft['phone'] ?? ''));
            $contact->introducedByUserId = $actor['id'] ?? null;
            $created = $this->contacts->create($contact);
            $ids[$created->id] = $created->id;
        }

        return array_values($ids);
    }

    private function assertDate(string $value, string $field): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new VisitException("{$field} must be a date (YYYY-MM-DD).");
        }
    }
}
