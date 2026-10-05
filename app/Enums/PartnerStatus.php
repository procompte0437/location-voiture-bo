<?php

namespace App\Enums;

enum PartnerStatus: string
{
    case Pending = 'pending';
    case InfoRequested = 'info_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
