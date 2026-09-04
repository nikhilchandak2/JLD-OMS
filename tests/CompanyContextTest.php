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

    public function testAnInactiveExistingSelectionIsCleared(): void
    {
        $inactive = $this->createCompany();
        $active = $this->createCompany();
        $this->createOrderFor($active, date('Y-m-d', strtotime('+1 day')));
        CompanyContext::setActiveCompanyId($inactive);
        $this->database->execute("UPDATE companies SET status = 'inactive' WHERE id = ?", [$inactive]);

        CompanyContext::initializeForUser();

        $this->assertSame($active, CompanyContext::getActiveCompanyId());
    }

    public function testDemoSeedCompaniesAreDeactivated(): void
    {
        $suffix = $this->uniqueSuffix();
        $this->database->execute(
            "INSERT INTO companies (name, code, status) VALUES ('JLD Logistics Ltd', ?, 'active')",
            ['FAKE' . $suffix]
        );
        $id = (int)$this->database->lastInsertId();
        \App\Support\CrmSchemaEnsure::deactivateDemoCompanies($this->database->getConnection());

        $row = $this->database->fetch("SELECT status FROM companies WHERE id = ?", [$id]);
        $this->assertSame('inactive', $row['status']);
        $active = (new CompanyRepository())->findActive();
        foreach ($active as $company) {
            $this->assertNotSame('JLD Logistics Ltd', $company->name);
        }
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
