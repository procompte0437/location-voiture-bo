<?php

namespace App\Enums;

enum VehicleStatus: string
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case Published = 'published';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
