<?php

declare(strict_types=1);

namespace Agung\EventIntegration\Enums;

enum ActivityStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Failed = 'failed';
    case Success = 'success';
    case RetryExceeded = 'retry_exceeded';
}
