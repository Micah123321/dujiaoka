<?php

namespace Tests\Unit\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class ApplicationTimezoneTest extends TestCase
{
    public function testConfiguredTimezoneUsesAStandardIdentifierAndKeepsChinaBusinessTime(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/app.php';
        $timezone = $config['timezone'];
        $this->assertContains($timezone, DateTimeZone::listIdentifiers(), 'The configured zone must work without backward timezone aliases.');
        $zone = new DateTimeZone($timezone);
        foreach (['2026-01-01 00:00:00', '2026-07-01 00:00:00'] as $date) {
            $this->assertSame(28800, (new DateTimeImmutable($date, $zone))->getOffset());
        }
    }
}
