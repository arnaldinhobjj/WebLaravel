<?php

namespace App\Ai\Skills;

use RuntimeException;

/**
 * Thrown by a skill to stop with a message the AI and the person can read.
 */
class SkillFailed extends RuntimeException {}
