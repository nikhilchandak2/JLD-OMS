<?php

namespace Tests;

use App\Repositories\CompanyRepository;
use App\Support\CompanyContext;

class CompanyContextTest extends DatabaseTestCase
{
    private CompanyRepository $companies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companies = new CompanyRepository();
        CompanyContext::clear();
    }

    public function testLoginLandsOnTheCompanyWithTheMostRecentOrder(): void
    {
        $trading = $this->createCompany();
        $this->createOrderFor($trading, date('Y-m-d', strtotime('+1 day')));

        CompanyContext::initializeForUser();

        $this->assertSame($trading, CompanyContext::getActiveCompanyId());
    }

    public function testAnAlphabeticallyEarlierCompanyWithoutOrdersIsNotChosen(): void
    {
        $trading = $this->createCompany();
        $this->createOrderFor($trading, date('Y-m-d', strtotime('+1 day')));

        $this->database->execute(
            "INSERT INTO companies (name, code, status) VALUES ('A Dormant Entity', ?, 'active')",
            ['ADE' . $this->uniqueSuffix()]
        );

        CompanyContext::initializeForUser();

        $this->assertSame($trading, CompanyContext::getActiveCompanyId());
    }

    public function testAnExistingSelectionIsKept(): void
    {
        $chosen = $this->createCompany();
        CompanyContext::setActiveCompanyId($chosen);

        CompanyContext::initializeForUser();

        $this->assertSame($chosen, CompanyContext::getActiveCompanyId());
    }

    public function testInactiveCompaniesAreNotChosen(): void
    {
        $inactive = $this->createCompany();
        $this->database->execute("UPDATE companies SET status = 'inactive' WHERE id = ?", [$inactive]);
        $this->createOrderFor($inactive, date('Y-m-d', strtotime('+2 days')));

        CompanyContext::initializeForUser();

        $this->assertNotSame($inactive, CompanyContext::getActiveCompanyId());
    }

    private function createOrderFor(int $companyId, string $orderDate): void
    {
        $this->database->execute(
            "INSERT INTO orders (company_id, order_no, order_date, product_id, order_qty_trucks, party_id, created_by, status)
             VALUES (?, ?, ?, ?, 10, ?, ?, 'pending')",
            [
                $companyId,
                'TST-' . $this->uniqueSuffix(),
                $orderDate,
                $this->createProduct(),
                $this->createParty(),
                $this->createUser('entry')['id'],
            ]
        );
    }
}
