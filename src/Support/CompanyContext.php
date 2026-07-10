<?php

namespace App\Support;

use App\Repositories\CompanyRepository;

/**
 * Session-scoped active legal entity (JLD Minerals, Jaichand Lal Daga, etc.).
 * Only orders carry company_id; dispatches and analytics derive scope through orders.
 */
class CompanyContext
{
    public static function getActiveCompanyId(): ?int
    {
        if (!isset($_SESSION['active_company_id'])) {
            return null;
        }
        $id = (int)$_SESSION['active_company_id'];
        return $id > 0 ? $id : null;
    }

    public static function setActiveCompanyId(int $companyId): void
    {
        $_SESSION['active_company_id'] = $companyId;
        unset($_SESSION['active_company_name'], $_SESSION['active_company_code']);
    }

    /** @return array{id:int,name:string,code:string}|null */
    public static function getActiveCompany(): ?array
    {
        $id = self::getActiveCompanyId();
        if (!$id) {
            return null;
        }

        if (!empty($_SESSION['active_company_name'])) {
            return [
                'id' => $id,
                'name' => (string)$_SESSION['active_company_name'],
                'code' => (string)($_SESSION['active_company_code'] ?? ''),
            ];
        }

        $repo = new CompanyRepository();
        $company = $repo->findById($id);
        if (!$company || ($company->status ?? '') !== 'active') {
            self::clear();
            return null;
        }

        $_SESSION['active_company_name'] = $company->name;
        $_SESSION['active_company_code'] = $company->code;

        return [
            'id' => (int)$company->id,
            'name' => (string)$company->name,
            'code' => (string)$company->code,
        ];
    }

    public static function clear(): void
    {
        unset(
            $_SESSION['active_company_id'],
            $_SESSION['active_company_name'],
            $_SESSION['active_company_code']
        );
    }

    /** Default to first active company on login. */
    public static function initializeForUser(): void
    {
        if (self::getActiveCompanyId() !== null) {
            self::getActiveCompany();
            return;
        }

        $repo = new CompanyRepository();
        $companies = $repo->findActive();
        if (empty($companies)) {
            return;
        }

        $first = $companies[0];
        self::setActiveCompanyId((int)$first->id);
        $_SESSION['active_company_name'] = $first->name;
        $_SESSION['active_company_code'] = $first->code;
    }

    /** Merge session company into repository/API filters unless explicitly overridden. */
    public static function mergeFilter(array $filters): array
    {
        $explicit = $filters['company_id'] ?? null;
        if ($explicit !== null && $explicit !== '') {
            $filters['company_id'] = (int)$explicit;
            return $filters;
        }

        $activeId = self::getActiveCompanyId();
        if ($activeId !== null) {
            $filters['company_id'] = $activeId;
        }

        return $filters;
    }

    public static function requireActiveCompanyId(): int
    {
        $id = self::getActiveCompanyId();
        if ($id === null) {
            throw new \RuntimeException('No company selected. Choose a company from the header switcher.');
        }
        return $id;
    }
}
