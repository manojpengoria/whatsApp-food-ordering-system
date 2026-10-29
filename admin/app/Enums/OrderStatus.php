<?php

namespace App\Enums;

enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment'; // online payment not done yet: hidden from staff
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::Pending => 'New',
            self::Confirmed => 'Confirmed',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::OutForDelivery => 'Out for delivery',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Statuses the order may move to next. Cancel is allowed until it leaves the kitchen. */
    public function nextStatuses(OrderType $type): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::Cancelled],
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => $type === OrderType::Delivery
                ? [self::OutForDelivery, self::Cancelled]
                : [self::Completed, self::Cancelled],
            self::OutForDelivery => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to, OrderType $type): bool
    {
        return in_array($to, $this->nextStatuses($type), true);
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled, self::AwaitingPayment], true);
    }

    /** Orders that count as real (not cancelled, not waiting for an online payment). */
    public static function hiddenValues(): array
    {
        return [self::Cancelled->value, self::AwaitingPayment->value];
    }

    /** @return list<self> */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isActive()));
    }

    /** Text on the admin button that moves an order INTO this status. */
    public function actionLabel(OrderType $type): string
    {
        return match ($this) {
            self::Confirmed => 'Accept order',
            self::Preparing => 'Start preparing',
            self::Ready => 'Mark ready',
            self::OutForDelivery => 'Send for delivery',
            self::Completed => match ($type) {
                OrderType::Delivery => 'Mark delivered',
                OrderType::DineIn => 'Mark served',
                default => 'Mark picked up',
            },
            self::Cancelled => 'Cancel order',
            self::Pending, self::AwaitingPayment => 'Reopen',
        };
    }

    /** Tailwind classes for the status pill. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'bg-slate-50 text-slate-600 ring-slate-200',
            self::Pending => 'bg-sky-50 text-sky-700 ring-sky-200',
            self::Confirmed => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
            self::Preparing => 'bg-amber-50 text-amber-700 ring-amber-200',
            self::Ready => 'bg-teal-50 text-teal-700 ring-teal-200',
            self::OutForDelivery => 'bg-violet-50 text-violet-700 ring-violet-200',
            self::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Cancelled => 'bg-rose-50 text-rose-700 ring-rose-200',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'bg-slate-400',
            self::Pending => 'bg-sky-500',
            self::Confirmed => 'bg-indigo-500',
            self::Preparing => 'bg-amber-500',
            self::Ready => 'bg-teal-500',
            self::OutForDelivery => 'bg-violet-500',
            self::Completed => 'bg-emerald-500',
            self::Cancelled => 'bg-rose-500',
        };
    }
}
