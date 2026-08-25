<?php

namespace Tests;

use App\Services\AccountContextAuthorizationException;
use App\Services\AccountContextException;
use App\Services\AccountContextPolicy;
use App\Services\AccountContextService;
use App\Services\AccountIssueService;
use App\Services\CompetitorPositionService;
use App\Services\DealService;

class AccountContextTest extends DatabaseTestCase
{
    private CompetitorPositionService $competitors;
    private AccountContextService $context;
    private AccountIssueService $issues;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->competitors = new CompetitorPositionService();
        $this->context = new AccountContextService();
        $this->issues = new AccountIssueService();
        $this->admin = $this->actor('admin');
    }

    public function testRecordingANewCurrentPositionClearsTheSupersededRowInOneTransaction(): void
    {
        $partyId = $this->createParty();
        $first = $this->competitors->record($partyId, [
            'competitor_name' => 'Ashapura',
            'grade_code' => 'J-11',
            'estimated_share_pct' => 70,
            'reason_code' => 'price',
            'reason_note' => 'Undercutting on Morbi freight',
            'intelligence_type' => 'factual',
        ], $this->admin);

        self::assertTrue($first['is_current']);
        self::assertSame(70, $first['estimated_share_pct']);

        $second = $this->competitors->record($partyId, [
            'competitor_name' => 'Ashapura',
            'grade_code' => 'J-11',
            'estimated_share_pct' => 40,
            'reason_code' => 'relationship',
            'reason_note' => 'Share slipped over eight months',
            'intelligence_type' => 'reported',
        ], $this->admin);

        self::assertTrue($second['is_current']);
        self::assertSame(40, $second['estimated_share_pct']);

        $old = $this->database->fetch(
            "SELECT * FROM crm_competitor_positions WHERE id = ?",
            [$first['id']]
        );
        self::assertSame(0, (int)$old['is_current'], 'The superseded row must keep its data and only lose is_current.');
        self::assertSame('70', (string)$old['estimated_share_pct']);
        self::assertSame('factual', $old['intelligence_type']);
        self::assertSame('Undercutting on Morbi freight', $old['reason_note']);

        $rows = $this->database->fetchAll(
            "SELECT id FROM crm_competitor_positions WHERE party_id = ?",
            [$partyId]
        );
        self::assertCount(2, $rows, 'History is append-only: both rows remain.');
    }

    public function testADifferentCompetitorDoesNotSupersede(): void
    {
        $partyId = $this->createParty();
        $a = $this->competitors->record($partyId, [
            'competitor_name' => 'Ashapura',
            'intelligence_type' => 'estimated',
            'reason_code' => 'other',
        ], $this->admin);
        $b = $this->competitors->record($partyId, [
            'competitor_name' => 'Goclay',
            'intelligence_type' => 'factual',
            'reason_code' => 'spec_fit',
        ], $this->admin);

        $aRow = $this->database->fetch("SELECT is_current FROM crm_competitor_positions WHERE id = ?", [$a['id']]);
        $bRow = $this->database->fetch("SELECT is_current FROM crm_competitor_positions WHERE id = ?", [$b['id']]);
        self::assertSame(1, (int)$aRow['is_current']);
        self::assertSame(1, (int)$bRow['is_current']);
    }

    public function testIntelligenceTypeIsRequired(): void
    {
        $this->expectException(AccountContextException::class);
        $this->competitors->record($this->createParty(), [
            'competitor_name' => 'Ashapura',
        ], $this->admin);
    }

    public function testIssueCreateAndResolve(): void
    {
        $partyId = $this->createParty();
        $created = $this->issues->create($partyId, [
            'issue_type' => 'quality_complaint',
            'description' => 'Black specks in the last two loads of J-11.',
            'raised_on' => '2026-08-01',
            'resolution_window_days' => 10,
        ], $this->admin);

        self::assertSame('open', $created['status']);
        self::assertSame(10, $created['resolution_window_days']);

        $resolved = $this->issues->resolve($created['id'], [
            'resolution_note' => 'Replaced with screened lot. Customer accepted.',
            'resolved_on' => '2026-08-08',
        ], $this->admin);
        self::assertSame('resolved', $resolved['status']);
        self::assertSame('2026-08-08', $resolved['resolved_on']);
    }

    public function testAccountContextUpsertIsOneToOneWithParty(): void
    {
        $partyId = $this->createParty();
        $this->context->upsertContext($partyId, [
            'production_capacity_note' => '80,000 sqm/day',
            'seasonality_note' => 'Slow in monsoon',
        ], $this->admin);
        $this->context->upsertContext($partyId, [
            'production_capacity_note' => '90,000 sqm/day',
            'seasonality_note' => 'Slow in monsoon',
        ], $this->admin);

        $count = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM crm_account_context WHERE party_id = ?",
            [$partyId]
        );
        self::assertSame(1, (int)$count['c']);

        $snap = $this->context->snapshotForParty($partyId, $this->admin);
        self::assertTrue($snap['context']['filled']);
        self::assertSame('90,000 sqm/day', $snap['context']['production_capacity_note']);
    }

    public function testExistingContactsKeepWorkingWithUnknownDefaults(): void
    {
        $partyId = $this->createParty();
        $this->database->execute(
            "INSERT INTO crm_contacts (party_id, name, role, is_primary) VALUES (?, 'Purchase Head', 'purchase_manager', 1)",
            [$partyId]
        );
        $row = $this->database->fetch(
            "SELECT influence_level, relationship_strength FROM crm_contacts WHERE party_id = ?",
            [$partyId]
        );
        self::assertSame('unknown', $row['influence_level']);
        self::assertSame('unknown', $row['relationship_strength']);
    }

    public function testDealShowIncludesCollapsedAccountContextCounts(): void
    {
        $deals = new DealService();
        $partyId = $this->createParty();
        $deal = $deals->captureInquiry([
            'party_id' => $partyId,
            'source' => 'call',
            'grades' => 'J-11',
            'indicative_quantity_tonnes' => 20,
            'inquiry_date' => '2026-08-01',
        ], $this->admin);

        $this->issues->create($partyId, [
            'issue_type' => 'delivery_failure',
            'description' => 'Truck arrived two days late.',
            'deal_id' => $deal['id'],
        ], $this->admin);

        $shown = $deals->show((int)$deal['id'], $this->admin);
        self::assertArrayHasKey('account_context', $shown);
        self::assertFalse($shown['account_context']['issues']['empty']);
        self::assertSame(1, $shown['account_context']['issues']['open_count']);
        self::assertTrue($shown['account_context']['contacts']['empty']);
        self::assertArrayHasKey('competitors', $shown['account_context']);
        self::assertTrue($shown['account_context']['competitors']['empty']);
    }

    public function testSearchFindsContactsCompetitorsAndIssues(): void
    {
        $partyId = $this->createParty();
        $this->database->execute(
            "INSERT INTO crm_contacts (party_id, name, role) VALUES (?, 'Rameshwar Purchase', 'buyer')",
            [$partyId]
        );
        $this->competitors->record($partyId, [
            'competitor_name' => 'Ashapuraclayworks',
            'intelligence_type' => 'factual',
            'reason_code' => 'price',
        ], $this->admin);
        $this->issues->create($partyId, [
            'description' => 'Baggingfailurequality on the last dispatch.',
            'issue_type' => 'quality_complaint',
        ], $this->admin);

        $byContact = $this->context->search('Rameshwar', $this->admin);
        self::assertNotEmpty($byContact['contacts']);
        self::assertSame('Rameshwar Purchase', $byContact['contacts'][0]['name']);

        $byCompetitor = $this->context->search('Ashapuraclayworks', $this->admin);
        self::assertNotEmpty($byCompetitor['competitors']);
        self::assertSame('Ashapuraclayworks', $byCompetitor['competitors'][0]['competitor_name']);

        $byIssue = $this->context->search('Baggingfailurequality', $this->admin);
        self::assertNotEmpty($byIssue['issues']);
    }

    public function testFulltextIndexesExist(): void
    {
        foreach (['ft_crm_contacts_name', 'ft_competitor_name', 'ft_issue_description'] as $index) {
            $row = $this->database->fetch(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ?
                 LIMIT 1",
                [$index]
            );
            self::assertNotNull($row, "FULLTEXT index {$index} must exist.");
        }
    }

    public function testDispatchCannotLoadTheAccountSnapshot(): void
    {
        $this->expectException(AccountContextAuthorizationException::class);
        $this->context->snapshotForParty($this->createParty(), $this->actor('dispatch'));
    }

    public function testSalesSeesCompetitorsAndEntryDoesNot(): void
    {
        $partyId = $this->createParty();
        $this->competitors->record($partyId, [
            'competitor_name' => 'Ashapura',
            'intelligence_type' => 'reported',
            'reason_code' => 'price',
        ], $this->admin);

        $forSales = $this->context->snapshotForParty($partyId, $this->actor('sales'));
        self::assertArrayHasKey('competitors', $forSales);
        self::assertSame(1, $forSales['competitors']['current_count']);
        self::assertTrue($forSales['capabilities']['view_competitors']);

        $forEntry = $this->context->snapshotForParty($partyId, $this->actor('entry'));
        self::assertArrayNotHasKey('competitors', $forEntry, 'Entry must not receive competitor intelligence.');
        self::assertFalse($forEntry['capabilities']['view_competitors']);
    }

    public function testAccountsCannotRecordACompetitor(): void
    {
        $this->expectException(AccountContextAuthorizationException::class);
        $this->competitors->record($this->createParty(), [
            'competitor_name' => 'Ashapura',
            'intelligence_type' => 'factual',
            'reason_code' => 'price',
        ], $this->actor('accounts'));
    }

    public function testPolicyStripsCompetitorsForDispatchEvenIfPresent(): void
    {
        $policy = new AccountContextPolicy();
        $stripped = $policy->serializeSnapshot([
            'contacts' => ['count' => 1],
            'competitors' => ['current_count' => 2],
        ], 'dispatch');
        self::assertArrayNotHasKey('competitors', $stripped);
        self::assertFalse($stripped['capabilities']['view_competitors']);
    }

    public function testSearchOmitsCompetitorsForRolesWithoutAccess(): void
    {
        $partyId = $this->createParty();
        $this->competitors->record($partyId, [
            'competitor_name' => 'Ashapuraclayworks',
            'intelligence_type' => 'factual',
            'reason_code' => 'price',
        ], $this->admin);

        $forEntry = $this->context->search('Ashapuraclayworks', $this->actor('entry'));
        self::assertSame([], $forEntry['competitors']);
    }

    public function testPartyAndDealScreensSurfaceThePanels(): void
    {
        $root = dirname(__DIR__);
        $party = file_get_contents($root . '/templates/crm/party-detail.php');
        self::assertStringContainsString('contactEditor', $party);
        self::assertStringContainsString('competitorPanel', $party);
        self::assertStringContainsString('issuesPanel', $party);
        self::assertStringNotContainsString('contactModal', $party, 'Contacts must be inline, not one modal per contact.');

        $deal = file_get_contents($root . '/templates/crm/deal-detail.php');
        self::assertStringContainsString('dealAccountContext', $deal);

        $js = file_get_contents($root . '/public/js/account-context.js');
        self::assertStringContainsString('intel-marker', $js);
        self::assertStringContainsString('intelligence_type', $js);
    }

    private function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }
}
