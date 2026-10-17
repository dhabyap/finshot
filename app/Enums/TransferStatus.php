<?php

namespace App\Enums;

enum TransferStatus: string
{
    case Requested = 'REQUESTED';
    case Sending = 'SENDING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
