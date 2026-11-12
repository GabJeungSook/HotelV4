<?php

declare(strict_types=1);

namespace App\Exceptions;

/** An open shift's Z-report preview cannot be exported (screen 37b). */
final class PreviewNotExportable extends DomainException
{
    public static function make(): self
    {
        return new self('A preview cannot be exported. Close the shift first.');
    }
}
