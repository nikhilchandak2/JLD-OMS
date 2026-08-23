<?php

namespace App\Services;

/**
 * Base class for every refusal coming out of the deal pipeline, so controllers can answer
 * with one catch block and a 422 instead of guessing at error strings.
 */
class PipelineException extends \Exception
{
    private array $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
