<?php

namespace App\Services;

/**
 * Raised when mandatory exit criteria for the current stage are not satisfied. Details carry the unmet field labels so the UI can list them.
 */
class ExitCriteriaNotMetException extends PipelineException
{
}
