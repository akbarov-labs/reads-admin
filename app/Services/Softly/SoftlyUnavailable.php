<?php

namespace App\Services\Softly;

use RuntimeException;

/** Softly cannot be asked right now: paused after an error, or today's cap is spent. */
class SoftlyUnavailable extends RuntimeException {}
