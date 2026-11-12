<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A business rule refused the action (TRD §8). The transaction is rolled back and
 * nothing is half-saved. The message is written for the person at the screen and is
 * shown as a dialog or an inline alert (SYSTEM_DESIGN §8).
 */
abstract class DomainException extends \DomainException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
