<?php

namespace App\Enums;

enum TransferStatus: string
{
    case Requested = 'REQUESTED';
    case Cancelled = 'CANCELLED';
}
