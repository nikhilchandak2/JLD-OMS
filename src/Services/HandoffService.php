<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmDealRepository;
use App\Repositories\DispatchRepository;
use App\Repositories\HandoffPacketRepository;
use App\Repositories\OrderRepository;

/**
 * Schema-enforced packets at team seams. Receiving teams acknowledge; they never edit payload fields.
 */
class HandoffService
{
    public const TYPE_SALES_TO_DISPATCH = 'sales_to_dispatch';
    public const TYPE_DISPATCH_TO_ACCOUNTS = 'dispatch_to_accounts';

    private Database $database;
    private HandoffPacketRepository $packets;
    private CrmDealRepository $deals;
    private OrderRepository $orders;
    private DispatchRepository $dispatches;
    private AuditLogRepository $audit;
    private HandoffPolicy $policy;
    private HandoffSchemaValidator $schema;

    public function __construct()
    {
        $this->database = new Database();
        $this->packets = new HandoffPacketRepository();
        $this->deals = new CrmDealRepository();
        $this->orders = new OrderRepository();
        $this->dispatches = new DispatchRepository();
        $this->audit = new AuditLogRepository();
        $this->policy = new HandoffPolicy();
        $this->schema = new HandoffSchemaValidator();
    }

    /** @param array{id:?int,role:?string} $actor */
    public function meta(array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, HandoffPolicy::VIEW);

        return [
            'current_schema_version' => $this->schema->currentVersion(),
            'packet_types' => $this->schema->packetTypes(),
            'delivery_terms' => $this->schema->deliveryTerms(),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function create(array $input, array $actor): array
    {
        $type = (string)($input['packet_type'] ?? '');
        $this->policy->assertCan($actor['role'] ?? null, $this->policy->createCapability($type));

        $dealId = $this->optionalId($input['deal_id'] ?? null);
        $orderId = $this->optionalId($input['order_id'] ?? null);
        $dispatchId = $this->optionalId($input['dispatch_id'] ?? null);
        $this->assertLinks($type, $dealId, $orderId, $dispatchId);

        $payload = $input['payload'] ?? null;
        if (!is_array($payload)) {
            throw new HandoffException('payload must be an object.');
        }

        $version = isset($input['schema_version']) && $input['schema_version'] !== ''
            ? (int)$input['schema_version']
            : $this->schema->currentVersion();
        $normalized = $this->schema->validate($type, $version, $payload);

        if ($this->currentForScope($type, $dealId, $orderId) !== null) {
            throw new HandoffException(
                'A current packet already exists for this handoff. Supersede it and record the reason.'
            );
        }

        $this->database->beginTransaction();
        try {
            $id = $this->packets->create([
                'packet_type' => $type,
                'deal_id' => $dealId,
                'order_id' => $orderId,
                'dispatch_id' => $dispatchId,
                'schema_version' => $version,
                'payload' => $normalized,
                'supersession_reason' => null,
                'created_by_user_id' => (int)$actor['id'],
            ]);
            $this->audit->log(
                $actor['id'] ?? null,
                'handoff_packets',
                $id,
                'CREATE',
                null,
                [
                    'packet_type' => $type,
                    'deal_id' => $dealId,
                    'order_id' => $orderId,
                    'dispatch_id' => $dispatchId,
                    'schema_version' => $version,
                    'payload' => $normalized,
                ]
            );
            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->require($id);
    }

    /**
     * In-place mutation is never allowed. Acknowledged packets throw a dedicated immutable error.
     *
     * @param array<string,mixed> $payload
     * @param array{id:?int,role:?string} $actor
     */
    public function updatePayload(int $id, array $payload, array $actor): void
    {
        $this->policy->assertCan($actor['role'] ?? null, HandoffPolicy::VIEW);
        $row = $this->require($id);
        unset($payload);
        if ($row['acknowledged_at'] !== null) {
            throw new HandoffImmutableException(
                'An acknowledged packet cannot be changed. Create a replacement and record the reason.'
            );
        }
        throw new HandoffException('Packets cannot be edited in place. Supersede with a reason instead.');
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function acknowledge(int $id, array $actor): array
    {
        $row = $this->require($id);
        $this->policy->assertCan($actor['role'] ?? null, $this->policy->acknowledgeCapability($row['packet_type']));

        if ($row['superseded_by_packet_id'] !== null) {
            throw new HandoffException('A superseded packet cannot be acknowledged.');
        }
        if ($row['acknowledged_at'] !== null) {
            return $row;
        }

        $at = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

        $this->database->beginTransaction();
        try {
            $this->packets->markAcknowledged($id, (int)$actor['id'], $at);
            $this->audit->log(
                $actor['id'] ?? null,
                'handoff_packets',
                $id,
                'UPDATE',
                ['acknowledged_at' => null, 'acknowledged_by_user_id' => null],
                ['acknowledged_at' => $at, 'acknowledged_by_user_id' => (int)$actor['id'], 'action' => 'acknowledge']
            );
            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->require($id);
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function supersede(int $id, array $input, array $actor): array
    {
        $old = $this->require($id);
        $this->policy->assertCan($actor['role'] ?? null, $this->policy->createCapability($old['packet_type']));

        if ($old['superseded_by_packet_id'] !== null) {
            throw new HandoffException('This packet has already been superseded.');
        }

        $reason = trim((string)($input['reason'] ?? $input['supersession_reason'] ?? ''));
        if ($reason === '') {
            throw new HandoffException('A reason is required when superseding a packet.', ['field' => 'reason']);
        }

        $payload = $input['payload'] ?? null;
        if (!is_array($payload)) {
            throw new HandoffException('payload must be an object.');
        }

        $version = isset($input['schema_version']) && $input['schema_version'] !== ''
            ? (int)$input['schema_version']
            : $this->schema->currentVersion();
        $normalized = $this->schema->validate($old['packet_type'], $version, $payload);

        $this->database->beginTransaction();
        try {
            $newId = $this->packets->create([
                'packet_type' => $old['packet_type'],
                'deal_id' => $old['deal_id'],
                'order_id' => $old['order_id'],
                'dispatch_id' => $old['dispatch_id'],
                'schema_version' => $version,
                'payload' => $normalized,
                'supersession_reason' => $reason,
                'created_by_user_id' => (int)$actor['id'],
            ]);
            $this->packets->markSuperseded($id, $newId);
            $this->audit->log(
                $actor['id'] ?? null,
                'handoff_packets',
                $id,
                'UPDATE',
                ['superseded_by_packet_id' => null],
                ['superseded_by_packet_id' => $newId, 'action' => 'supersede', 'reason' => $reason]
            );
            $this->audit->log(
                $actor['id'] ?? null,
                'handoff_packets',
                $newId,
                'CREATE',
                null,
                [
                    'packet_type' => $old['packet_type'],
                    'supersedes_packet_id' => $id,
                    'supersession_reason' => $reason,
                    'payload' => $normalized,
                ]
            );
            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->require($newId);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     * @return array<string,mixed>
     */
    public function show(int $id, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, HandoffPolicy::VIEW);

        return $this->require($id);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array{id:?int,role:?string} $actor
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, HandoffPolicy::VIEW);

        return $this->packets->findAll($filters);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     */
    public function currentSalesToDispatch(int $dealId, array $actor): ?array
    {
        $this->policy->assertCan($actor['role'] ?? null, HandoffPolicy::VIEW);

        return $this->packets->currentSalesToDispatch($dealId);
    }

    public function currentSalesToDispatchForDeal(int $dealId): ?array
    {
        return $this->packets->currentSalesToDispatch($dealId);
    }

    /**
     * @param array{id:?int,role:?string} $actor
     */
    public function pdfBytes(int $id, array $actor): array
    {
        $row = $this->show($id, $actor);
        $pdf = (new HandoffPdfService())->render($row);

        return [
            'bytes' => $pdf,
            'filename' => 'handoff-' . $row['packet_type'] . '-' . $id . '.pdf',
        ];
    }

    /** @return array<string,mixed> */
    private function require(int $id): array
    {
        $row = $this->packets->findById($id);
        if ($row === null) {
            throw new HandoffException("Handoff packet {$id} not found.");
        }

        return $row;
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int)$value;

        return $id > 0 ? $id : null;
    }

    private function assertLinks(string $type, ?int $dealId, ?int $orderId, ?int $dispatchId): void
    {
        if ($type === self::TYPE_SALES_TO_DISPATCH) {
            if ($dealId === null || $this->deals->findById($dealId) === null) {
                throw new HandoffException('A valid deal is required for a Sales→Dispatch packet.', ['field' => 'deal_id']);
            }
            return;
        }

        if ($type === self::TYPE_DISPATCH_TO_ACCOUNTS) {
            if ($orderId === null || $this->orders->findById($orderId) === null) {
                throw new HandoffException('A valid order is required for a Dispatch→Accounts packet.', ['field' => 'order_id']);
            }
            if ($dispatchId !== null) {
                $dispatch = $this->dispatches->findById($dispatchId);
                if ($dispatch === null || (int)$dispatch->orderId !== $orderId) {
                    throw new HandoffException('dispatch_id must belong to the given order.', ['field' => 'dispatch_id']);
                }
            }
            return;
        }

        throw new HandoffException('Unknown packet type.', ['field' => 'packet_type']);
    }

    private function currentForScope(string $type, ?int $dealId, ?int $orderId): ?array
    {
        $filters = [
            'packet_type' => $type,
            'current_only' => 1,
            'limit' => 1,
        ];
        if ($type === self::TYPE_SALES_TO_DISPATCH && $dealId !== null) {
            $filters['deal_id'] = $dealId;
        } elseif ($type === self::TYPE_DISPATCH_TO_ACCOUNTS && $orderId !== null) {
            $filters['order_id'] = $orderId;
        } else {
            return null;
        }

        $rows = $this->packets->findAll($filters);

        return $rows[0] ?? null;
    }
}
