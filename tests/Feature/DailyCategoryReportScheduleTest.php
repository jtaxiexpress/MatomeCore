<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class DailyCategoryReportScheduleTest extends TestCase
{
    public function test_daily_category_report_command_is_scheduled_at_nine_am(): void
    {
        $scheduledEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'app:send-daily-category-report'));

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('0 9 * * *', $scheduledEvent->getExpression());
    }
}
