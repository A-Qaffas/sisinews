<?php

namespace Database\Seeders\Support;

use RuntimeException;

/** The import cannot start (bad content, wrong publisher, another run in progress). Nothing was written. */
final class LaunchImportException extends RuntimeException {}
