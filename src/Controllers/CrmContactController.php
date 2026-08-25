<?php

namespace App\Controllers;

use App\Services\AccountContextAuthorizationException;
use App\Services\AccountContextException;
use App\Services\AccountContextPolicy;
use App\Services\AuthService;
use App\Repositories\AuditLogRepository;
use App\Repositories\CrmContactRepository;
use App\Repositories\PartyRepository;
use App\Models\CrmContact;

class CrmContactController
{
    private AuthService $authService;
    private CrmContactRepository $contactRepo;
    private PartyRepository $partyRepo;
    private AccountContextPolicy $policy;
    private AuditLogRepository $audit;
    private array $config;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->contactRepo = new CrmContactRepository();
        $this->partyRepo = new PartyRepository();
        $this->policy = new AccountContextPolicy();
        $this->audit = new AuditLogRepository();
        $this->config = require dirname(__DIR__, 2) . '/config/account_context.php';
    }

    private function actor(): ?array
    {
        $user = $this->authService->getCurrentUser();
        if (!$user) {
            return null;
        }

        return ['id' => (int)$user['id'], 'role' => $user['role'] ?? null];
    }

    private function requireCapability(string $capability): ?array
    {
        $actor = $this->actor();
        if ($actor === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return null;
        }
        try {
            $this->policy->assertCan($actor['role'] ?? null, $capability);
        } catch (AccountContextAuthorizationException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
            return null;
        }

        return $actor;
    }

    public function listByParty(string $partyId): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCapability(AccountContextPolicy::VIEW_CONTACTS)) {
            return;
        }
        $id = (int)$partyId;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid party ID']);
            return;
        }
        if (!$this->partyRepo->findById($id)) {
            http_response_code(404);
            echo json_encode(['error' => 'Party not found']);
            return;
        }
        try {
            $list = $this->contactRepo->findByParty($id);
            echo json_encode(['success' => true, 'data' => array_map(fn($c) => $c->toArray(), $list)]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCapability(AccountContextPolicy::VIEW_CONTACTS)) {
            return;
        }
        $cid = (int)$id;
        $contact = $cid > 0 ? $this->contactRepo->findById($cid) : null;
        if (!$contact) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $contact->toArray()]);
    }

    public function create(string $partyId): void
    {
        header('Content-Type: application/json');
        $actor = $this->requireCapability(AccountContextPolicy::EDIT_CONTACTS);
        if (!$actor) {
            return;
        }
        $pid = (int)$partyId;
        if ($pid <= 0 || !$this->partyRepo->findById($pid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or unknown party']);
            return;
        }
        $input = $this->input();
        try {
            $contact = $this->hydrate(new CrmContact(), $input);
            $contact->partyId = $pid;
            if ($contact->name === '') {
                throw new AccountContextException('Name is required');
            }
            $created = $this->contactRepo->create($contact);
            $this->audit->log(
                $actor['id'] ?? null,
                'crm_contacts',
                (int)$created->id,
                'CREATE',
                null,
                $created->toArray()
            );
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Contact created', 'data' => $created->toArray()]);
        } catch (AccountContextException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function update(string $id): void
    {
        header('Content-Type: application/json');
        $actor = $this->requireCapability(AccountContextPolicy::EDIT_CONTACTS);
        if (!$actor) {
            return;
        }
        $cid = (int)$id;
        $existing = $cid > 0 ? $this->contactRepo->findById($cid) : null;
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            return;
        }
        $input = $this->input();
        try {
            $data = $this->updatePayload($input);
            if (isset($data['name']) && $data['name'] === '') {
                throw new AccountContextException('Name cannot be empty');
            }
            if ($data === []) {
                echo json_encode(['success' => true, 'data' => $existing->toArray()]);
                return;
            }
            $updated = $this->contactRepo->update($cid, $data);
            $this->audit->log(
                $actor['id'] ?? null,
                'crm_contacts',
                $cid,
                'UPDATE',
                $existing->toArray(),
                $updated->toArray()
            );
            echo json_encode(['success' => true, 'message' => 'Contact updated', 'data' => $updated->toArray()]);
        } catch (AccountContextException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function delete(string $id): void
    {
        header('Content-Type: application/json');
        $actor = $this->requireCapability(AccountContextPolicy::EDIT_CONTACTS);
        if (!$actor) {
            return;
        }
        $cid = (int)$id;
        if ($cid <= 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found']);
            return;
        }
        try {
            $existing = $this->contactRepo->findById($cid);
            if ($existing && $this->contactRepo->delete($cid)) {
                $this->audit->log(
                    $actor['id'] ?? null,
                    'crm_contacts',
                    $cid,
                    'DELETE',
                    $existing->toArray(),
                    null
                );
                echo json_encode(['success' => true, 'message' => 'Contact deleted']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Contact not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function hydrate(CrmContact $contact, array $input): CrmContact
    {
        $contact->name = trim((string)($input['name'] ?? ''));
        $contact->role = trim((string)($input['role'] ?? ''));
        $contact->phone = trim((string)($input['phone'] ?? ''));
        $contact->email = trim((string)($input['email'] ?? ''));
        $contact->isPrimary = !empty($input['is_primary']);
        $contact->influenceLevel = $this->enumOrDefault(
            $input['influence_level'] ?? null,
            'influence_levels',
            'unknown'
        );
        $contact->relationshipStrength = $this->enumOrDefault(
            $input['relationship_strength'] ?? null,
            'relationship_strengths',
            'unknown'
        );
        $contact->introducedByUserId = $this->nullableInt($input['introduced_by_user_id'] ?? null);
        $contact->introducedOn = $this->nullableDate($input['introduced_on'] ?? null);
        $contact->preferredChannel = $this->enumOrNull($input['preferred_channel'] ?? null, 'preferred_channels');
        $contact->preferredLanguage = trim((string)($input['preferred_language'] ?? '')) ?: null;
        $contact->contextNotes = trim((string)($input['context_notes'] ?? '')) ?: null;

        return $contact;
    }

    /** @return array<string,mixed> */
    private function updatePayload(array $input): array
    {
        $data = [];
        if (isset($input['name'])) {
            $data['name'] = trim((string)$input['name']);
        }
        if (isset($input['role'])) {
            $data['role'] = trim((string)$input['role']);
        }
        if (isset($input['phone'])) {
            $data['phone'] = trim((string)$input['phone']);
        }
        if (isset($input['email'])) {
            $data['email'] = trim((string)$input['email']);
        }
        if (array_key_exists('is_primary', $input)) {
            $data['is_primary'] = !empty($input['is_primary']) ? 1 : 0;
        }
        if (array_key_exists('influence_level', $input)) {
            $data['influence_level'] = $this->enumOrDefault($input['influence_level'], 'influence_levels', 'unknown');
        }
        if (array_key_exists('relationship_strength', $input)) {
            $data['relationship_strength'] = $this->enumOrDefault($input['relationship_strength'], 'relationship_strengths', 'unknown');
        }
        if (array_key_exists('introduced_by_user_id', $input)) {
            $data['introduced_by_user_id'] = $this->nullableInt($input['introduced_by_user_id']);
        }
        if (array_key_exists('introduced_on', $input)) {
            $data['introduced_on'] = $this->nullableDate($input['introduced_on']);
        }
        if (array_key_exists('preferred_channel', $input)) {
            $data['preferred_channel'] = $this->enumOrNull($input['preferred_channel'], 'preferred_channels');
        }
        if (array_key_exists('preferred_language', $input)) {
            $data['preferred_language'] = trim((string)$input['preferred_language']) ?: null;
        }
        if (array_key_exists('context_notes', $input)) {
            $data['context_notes'] = trim((string)$input['context_notes']) ?: null;
        }

        return $data;
    }

    private function enumOrDefault($value, string $configKey, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $value = (string)$value;
        if (!isset($this->config[$configKey][$value])) {
            throw new AccountContextException("Invalid {$configKey} value.");
        }

        return $value;
    }

    private function enumOrNull($value, string $configKey): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string)$value;
        if (!isset($this->config[$configKey][$value])) {
            throw new AccountContextException("Invalid {$configKey} value.");
        }

        return $value;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function nullableDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string)$value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new AccountContextException('introduced_on must be a date (YYYY-MM-DD).');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = $raw === false || $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : $_POST;
    }
}
