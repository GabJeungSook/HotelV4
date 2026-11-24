<?php

declare(strict_types=1);

namespace App\Enums;

/** approvals.action */
enum ApprovalAction: string
{
    case Transfer = 'transfer';
    case VoidLine = 'void_line';
    case CancelStay = 'cancel_stay';
    case VoidCashMovement = 'void_cash_movement';
    case VoidSale = 'void_sale';
    case ForceCloseShift = 'force_close_shift';
    case CloseWithoutHandover = 'close_without_handover';
    case CancelReservation = 'cancel_reservation';
    case RefundNoShow = 'refund_no_show';
    case ChangeShiftPeriod = 'change_shift_period';
    case ApproveVariance = 'approve_variance';
    case ReasonRequiresManager = 'reason_requires_manager';
    case CancelHallBooking = 'cancel_hall_booking';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Room transfer',
            self::VoidLine => 'Void line',
            self::CancelStay => 'Cancel stay',
            self::VoidCashMovement => 'Void cash entry',
            self::VoidSale => 'Void sale',
            self::ForceCloseShift => 'Force close shift',
            self::CloseWithoutHandover => 'Close without handover',
            self::CancelReservation => 'Cancel reservation',
            self::RefundNoShow => 'Settle no-show',
            self::ChangeShiftPeriod => 'Change shift period',
            self::ApproveVariance => 'Approve variance',
            self::ReasonRequiresManager => 'Reason needs a manager',
            self::CancelHallBooking => 'Cancel hall booking',
        };
    }
}
