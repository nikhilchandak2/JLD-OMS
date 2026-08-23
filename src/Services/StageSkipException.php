<?php

namespace App\Services;

/**
 * Raised when a transition tries to jump more than one stage forward.
 */
class StageSkipException extends PipelineException
{
}
