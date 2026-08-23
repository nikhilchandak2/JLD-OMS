<?php

namespace App\Services;

/**
 * Raised for a transition the state machine does not allow at all (including same-stage no-ops).
 */
class IllegalTransitionException extends PipelineException
{
}
