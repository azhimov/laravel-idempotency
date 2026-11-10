<?php

declare(strict_types=1);

use Azhimov\Idempotency\Tests\TestCase;

require_once __DIR__.'/Support/StoreContract.php';

pest()->extend(TestCase::class)->in('Feature');
