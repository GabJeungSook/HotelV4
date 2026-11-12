<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A wrong PIN (screens 3, 14, 25). The try is logged in pin_attempts. On switch user,
 * $mustSignIn is true after the third wrong PIN in a row: the session ends (TRD §4.12).
 */
final class PinInvalid extends DomainException
{
    private function __construct(string $message, public readonly ?int $triesLeft, public readonly bool $mustSignIn)
    {
        parent::__construct($message);
    }

    public static function make(?int $triesLeft = null, bool $mustSignIn = false): self
    {
        $message = 'PIN not recognised.';

        if ($mustSignIn) {
            $message .= ' Sign in again with your username and password.';
        } elseif ($triesLeft !== null) {
            $message .= ' '.$triesLeft.' '.($triesLeft === 1 ? 'try' : 'tries').' left.';
        }

        return new self($message, $triesLeft, $mustSignIn);
    }

    public static function noPinSet(string $name): self
    {
        return new self("{$name} has no PIN yet.", null, false);
    }
}
