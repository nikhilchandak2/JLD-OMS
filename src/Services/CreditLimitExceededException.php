<?php

namespace App\Services;

/**
 * Thrown when order creation is blocked because the party's outstanding
 * exceeds its credit limit. Carries the party credit status so the API
 * can tell the UI how many credit requests remain this month.
 */
class CreditLimitExceededException extends \Exception
{
    private array $creditStatus;

    public function __construct(string $message, array $creditStatus)
    {
        parent::__construct($message);
        $this->creditStatus = $creditStatus;
    }

    public function getCreditStatus(): array
    {
        return $this->creditStatus;
    }
}
