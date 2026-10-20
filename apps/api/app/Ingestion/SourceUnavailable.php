<?php

namespace App\Ingestion;

use RuntimeException;

/** The source could not be read. Nothing is created from a failed read. */
class SourceUnavailable extends RuntimeException {}
