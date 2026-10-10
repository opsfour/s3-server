<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/** Distinguishes client SQL syntax errors from worker execution failures. */
final class SelectSqlException extends \RuntimeException {}
