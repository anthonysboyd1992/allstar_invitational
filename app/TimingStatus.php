<?php

namespace App;

enum TimingStatus: string
{
    case Pending = 'pending';
    case Lineups = 'lineups';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Lineups => 'Lineups',
            self::Complete => 'Complete',
        };
    }
}
