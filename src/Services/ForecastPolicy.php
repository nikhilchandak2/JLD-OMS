<?php

namespace App\Services;

/**
 * Period open/lock is Director (admin) only. Reps edit lines on open periods
 * for their own accounts. Actuals are production-planning views — not a
 * per-rep scorecard.
 */
class ForecastPolicy
{
    public const VIEW = 'view_forecast';
    public const EDIT = 'edit_forecast';
    public const VIEW_ALL = 'view_all_forecasts';
    public const MANAGE_PERIOD = 'manage_forecast_period';
    public const VIEW_ACTUALS = 'view_forecast_actuals';

    /** @var array<string,string[]> */
    private const CAPABILITIES = [
        self::VIEW => ['admin', 'crm', 'sales', 'marketing', 'entry'],
        self::EDIT => ['admin', 'crm', 'sales'],
        self::VIEW_ALL => ['admin', 'crm'],
        self::MANAGE_PERIOD => ['admin'],
        self::VIEW_ACTUALS => ['admin', 'crm', 'sales', 'dispatch', 'entry'],
    ];

    public function can(?string $role, string $capability): bool
    {
        if ($role === null || $role === '') {
            return false;
        }

        return in_array($role, self::CAPABILITIES[$capability] ?? [], true);
    }

    public function assertCan(?string $role, string $capability): void
    {
        if (!$this->can($role, $capability)) {
            throw new ForecastAuthorizationException(
                "Role '" . ($role ?? 'none') . "' is not permitted to {$capability}."
            );
        }
    }
}
