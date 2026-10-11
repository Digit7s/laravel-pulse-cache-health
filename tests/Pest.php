<?php

declare(strict_types=1);

use Digit7s\PulseCacheHealth\Tests\Integration\MySqlIntegrationTestCase;
use Digit7s\PulseCacheHealth\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(MySqlIntegrationTestCase::class)->in('Integration');
