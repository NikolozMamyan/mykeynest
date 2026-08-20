<?php

namespace App\Enum;

enum OrganizationMemberStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';

    public function reservesSeat(): bool
    {
        return in_array($this, [self::PENDING, self::ACTIVE], true);
    }
}
