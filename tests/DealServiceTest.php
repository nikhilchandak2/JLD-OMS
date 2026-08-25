<?php

namespace Tests;

use App\Services\PipelineAuthorizationException;
use App\Services\PipelineException;

/**
 * Capture, listing, grades, RBAC serialization and soft delete.
 */
class DealServiceTest extends CrmPipelineTestCase
{
    public function testCaptureCreatesAStageOneActiveDealWithGradesAndAnOpeningEvent(): void
    {
        $deal = $this->captureDeal();

        self::assertSame(1, (int)$deal['stage']);
        self::assertSame('active', $deal['status']);
        self::assertSame('whatsapp', $deal['source']);
        self::assertSame('2026-01-05', $deal['inquiry_date']);
        self::assertEqualsCanonicalizing(['J-11', 'JJN-1'], array_column($deal['grades'], 'grade_code'));
        self::assertSame(1, $this->eventCount((int)$deal['id']), 'Capture writes one opening event.');
        self::assertSame(1, $this->auditCount('crm_deals', (int)$deal['id']));
    }

    public function testCaptureDefaultsTheEnquiryDateToTodayInIndianTime(): void
    {
        $deal = $this->captureDeal(['inquiry_date' => '']);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

        self::assertSame($today, $deal['inquiry_date']);
    }

    public function testCaptureSumsGradeQuantitiesWhenNoTotalIsGiven(): void
    {
        $deal = $this->captureDeal([
            'indicative_quantity_tonnes' => '',
            'grades' => [
                ['grade_code' => 'j-11', 'indicative_qty_tonnes' => 30],
                ['grade_code' => 'BNT-31', 'indicative_qty_tonnes' => 12.5],
            ],
        ]);

        self::assertSame('42.500', $deal['indicative_quantity_tonnes']);
        self::assertEqualsCanonicalizing(['J-11', 'BNT-31'], array_column($deal['grades'], 'grade_code'));
    }

    public function testCaptureRejectsAnUnknownCustomer(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('A valid customer is required.');
        $this->deals->captureInquiry([
            'party_id' => 99999999,
            'source' => 'call',
            'grades' => 'J-11',
        ], $this->admin);
    }

    public function testCaptureRejectsAnUnknownSourceAndAnEmptyGradeList(): void
    {
        $partyId = $this->createParty();

        try {
            $this->deals->captureInquiry([
                'party_id' => $partyId,
                'source' => 'carrier_pigeon',
                'grades' => 'J-11',
            ], $this->admin);
            self::fail('An unknown enquiry source should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('enquiry source', $e->getMessage());
        }

        try {
            $this->deals->captureInquiry([
                'party_id' => $partyId,
                'source' => 'call',
                'grades' => '  ,  ',
            ], $this->admin);
            self::fail('A deal with no grade should be refused.');
        } catch (PipelineException $e) {
            self::assertStringContainsString('grade', $e->getMessage());
        }
    }

    public function testCaptureIsRolledBackEntirelyWhenItFails(): void
    {
        $partyId = $this->createParty();
        $before = $this->database->fetch("SELECT COUNT(*) AS c FROM crm_deals WHERE party_id = ?", [$partyId]);

        try {
            // 300 characters is longer than the grade_code column allows, so the grade insert
            // fails after the deal row was written.
            $this->deals->captureInquiry([
                'party_id' => $partyId,
                'source' => 'call',
                'grades' => str_repeat('X', 300),
            ], $this->admin);
            self::fail('An oversized grade code should fail the capture.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            // expected: grade insert fails after the deal row is written
        }

        $after = $this->database->fetch("SELECT COUNT(*) AS c FROM crm_deals WHERE party_id = ?", [$partyId]);
        self::assertSame((int)$before['c'], (int)$after['c'], 'A failed capture leaves no half-written deal.');
    }

    // ------------------------------------------------------------------
    // RBAC at the serializer (I10)
    // ------------------------------------------------------------------

    public function testCommercialValueIsAbsentFromTheResponseForRolesThatMustNotSeeIt(): void
    {
        $deal = $this->captureDeal(['value' => 250000, 'expected_close_date' => '2026-03-31']);
        $dealId = (int)$deal['id'];

        self::assertArrayHasKey('value', $deal, 'Admin sees the commercial value.');

        foreach (['marketing', 'entry'] as $role) {
            $payload = $this->deals->show($dealId, $this->actor($role));
            self::assertArrayNotHasKey('value', $payload, "{$role} must not receive the deal value.");
            self::assertArrayNotHasKey('expected_close_date', $payload, "{$role} must not receive the close date.");

            $list = $this->deals->list(['party_id' => (int)$deal['party_id']], $this->actor($role));
            self::assertNotEmpty($list);
            foreach ($list as $row) {
                self::assertArrayNotHasKey('value', $row, "{$role} must not receive the deal value in a list.");
                self::assertArrayNotHasKey('expected_close_date', $row);
            }
        }

        foreach (['sales', 'crm'] as $role) {
            $payload = $this->deals->show($dealId, $this->actor($role));
            self::assertArrayHasKey('value', $payload, "{$role} is allowed the deal value.");
        }
    }

    public function testARoleWithNoPipelineAccessCannotListDeals(): void
    {
        $this->expectException(PipelineAuthorizationException::class);
        $this->deals->list([], $this->actor('technical'));
    }

    public function testOnlyAdminCanDeleteADealAndDeletionIsSoft(): void
    {
        $deal = $this->captureDeal();
        $dealId = (int)$deal['id'];

        try {
            $this->deals->softDelete($dealId, $this->actor('sales'));
            self::fail('Sales must not be able to delete a deal.');
        } catch (PipelineAuthorizationException $e) {
            // expected
        }

        $this->deals->softDelete($dealId, $this->admin);

        $row = $this->database->fetch("SELECT deleted_at FROM crm_deals WHERE id = ?", [$dealId]);
        self::assertNotNull($row, 'The row itself stays (I12).');
        self::assertNotNull($row['deleted_at']);

        $ids = array_column($this->deals->list(['party_id' => (int)$deal['party_id']], $this->admin), 'id');
        self::assertNotContains($dealId, array_map('intval', $ids));
    }

    // ------------------------------------------------------------------
    // Grades
    // ------------------------------------------------------------------

    public function testGradesCanBeAddedAndRemovedAndAreUppercasedAndDeduplicated(): void
    {
        $deal = $this->captureDeal(['grades' => 'j-11, J-11, jjn-1']);
        $dealId = (int)$deal['id'];
        self::assertEqualsCanonicalizing(['J-11', 'JJN-1'], array_column($deal['grades'], 'grade_code'));

        $updated = $this->deals->addGrade($dealId, ' crystal-2 ', 15.0, $this->admin);
        self::assertContains('CRYSTAL-2', array_column($updated['grades'], 'grade_code'));

        $updated = $this->deals->removeGrade($dealId, 'crystal-2', $this->admin);
        self::assertNotContains('CRYSTAL-2', array_column($updated['grades'], 'grade_code'));
        self::assertGreaterThan(0, $this->auditCount('crm_deal_grades', $dealId));
    }

    // ------------------------------------------------------------------
    // N+1 (Definition of Done item 4)
    // ------------------------------------------------------------------

    public function testTheDealListQueryCountDoesNotGrowWithTheNumberOfDeals(): void
    {
        $partyId = $this->createParty();
        for ($i = 0; $i < 3; $i++) {
            $this->captureDeal(['party_id' => $partyId]);
        }
        $withThree = $this->countQueries(fn() => $this->deals->list(['party_id' => $partyId], $this->admin));

        for ($i = 0; $i < 7; $i++) {
            $this->captureDeal(['party_id' => $partyId]);
        }
        $withTen = $this->countQueries(fn() => $this->deals->list(['party_id' => $partyId], $this->admin));

        self::assertSame($withThree, $withTen, "List query count grew from {$withThree} to {$withTen}.");
        self::assertLessThanOrEqual(3, $withTen, 'The list view should be a small fixed number of queries.');
    }

    public function testPipelineSummaryIsOneQuery(): void
    {
        $this->captureDeal();
        self::assertLessThanOrEqual(1, $this->countQueries(fn() => $this->deals->pipelineSummary($this->admin)));
    }

    /** Counts statements actually sent to MySQL on the shared connection. */
    private function countQueries(callable $work): int
    {
        $before = $this->sessionSelectCount();
        $work();

        return $this->sessionSelectCount() - $before - 1; // -1 for the status read itself
    }

    private function sessionSelectCount(): int
    {
        $row = $this->database->fetch("SHOW SESSION STATUS LIKE 'Com_select'");

        return (int)($row['Value'] ?? 0);
    }
}
