<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Partner = 'partner';
    case PartnerAgent = 'partner_agent';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function isAdmin(): bool
    {
        return in_array($this, [self::Admin, self::SuperAdmin], true);
    }
}
