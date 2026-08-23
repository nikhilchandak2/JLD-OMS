<?php

namespace App\Services;

/**
 * Raised when a backward move, reopen, or terminal transition is attempted without its reason.
 */
class TransitionReasonRequiredException extends PipelineException
{
}
