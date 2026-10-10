<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/** Invalid CSV data or CSV serialization options for S3 Select. */
final class SelectCsvException extends \InvalidArgumentException {}
