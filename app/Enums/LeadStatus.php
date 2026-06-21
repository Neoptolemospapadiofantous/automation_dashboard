<?php

namespace App\Enums;

/**
 * The lifecycle a lead moves through, from capture to outcome.
 *
 * new -> qualified -> assigned -> won | lost
 */
enum LeadStatus: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Assigned = 'assigned';
    case Won = 'won';
    case Lost = 'lost';

    /** Human label for the UI. */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Qualified => 'Qualified',
            self::Assigned => 'Assigned',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /** Tailwind colour token used to tint the kanban columns/badges. */
    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Qualified => 'violet',
            self::Assigned => 'blue',
            self::Won => 'green',
            self::Lost => 'rose',
        };
    }

    /** The ordered set of columns shown on the board. */
    public static function board(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()],
            self::cases(),
        );
    }
}
