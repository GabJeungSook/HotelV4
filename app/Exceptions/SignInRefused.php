<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Sign-in (1) refused: wrong password, a locked account, or a switched-off account (APP_FLOW §4.1). */
final class SignInRefused extends DomainException
{
    public static function wrong(?int $triesLeft, int $lockMinutes): self
    {
        if ($triesLeft === null) {
            return new self('Wrong username or password.');
        }

        return new self("Wrong username or password. {$triesLeft} ".($triesLeft === 1 ? 'try' : 'tries')." left before a {$lockMinutes}-minute lock.");
    }

    public static function locked(int $minutes): self
    {
        return new self("This account is locked after too many wrong passwords. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.');
    }

    public static function inactive(): self
    {
        return new self('This account is switched off. Ask your branch admin.');
    }
}
