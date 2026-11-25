<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Smaller refusals of a stay or shift action that have no screen of their own: they show
 * as an inline alert on the form, and nothing is saved.
 */
final class ActionRefused extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function stayNotInHouse(string $code): self
    {
        return new self("Stay {$code} is not checked in.");
    }

    public static function nothingToPay(): self
    {
        return new self('There is nothing to pay on this account.');
    }

    public static function methodNotAccepted(string $method): self
    {
        return new self("{$method} is not accepted at this branch.");
    }

    public static function referenceRequired(string $method): self
    {
        return new self("A reference number is needed for {$method}.");
    }

    public static function notEnoughTendered(): self
    {
        return new self('The amount received is less than the amount to collect.');
    }

    public static function notEnoughDeposit(): self
    {
        return new self('That is more than the guest deposit held.');
    }

    public static function keyDepositLocked(): self
    {
        return new self('The key deposit can only be used at check-out.');
    }

    public static function noteRequired(string $for): self
    {
        return new self("A note is needed for {$for}.");
    }

    public static function lineNotVoidable(string $why): self
    {
        return new self($why);
    }
}
