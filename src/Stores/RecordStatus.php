<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores;

enum RecordStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
}
