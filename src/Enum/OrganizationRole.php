<?php

namespace App\Enum;

enum OrganizationRole: string
{
    case OWNER = 'OWNER';
    case ADMIN = 'ADMIN';
    case MEMBER = 'MEMBER';
    case GUEST = 'GUEST';

    public function consumesSeat(): bool
    {
        return $this !== self::GUEST;
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN], true);
    }
}
