<?php

namespace App\Services\Workflows\Steps;

use RuntimeException;

/**
 * A step couldn't do its job; the message is shown in the run log.
 */
class StepFailed extends RuntimeException {}
