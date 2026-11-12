<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The chosen approver may not approve: the same person as the requester, not a supervisor or
 * admin of the branch (or the owner), or inactive (TRD §9, APP_FLOW §6 case 24).
 */
final class ApprovalRefused extends DomainException
{
    public static function selfApproval(): self
    {
        return new self('You cannot approve your own request. Choose another approver.');
    }

    public static function notAllowed(string $name): self
    {
        return new self("{$name} cannot approve this. Choose a supervisor or admin of this branch.");
    }
}
