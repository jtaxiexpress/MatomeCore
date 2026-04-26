<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class DailyCategorySummaryNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly CarbonImmutable $windowStart,
        public readonly CarbonImmutable $windowEnd,
        public readonly array $lines,
        public readonly int $totalNewCount,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text('ゆにこーんアンテナ daily category report')
            ->headerBlock('ゆにこーんアンテナ Daily Category Report')
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->buildBody())->markdown();
            });
    }

    public function buildBody(): string
    {
        $range = sprintf(
            '%s - %s',
            $this->windowStart->format('Y-m-d H:i'),
            $this->windowEnd->format('Y-m-d H:i')
        );

        if ($this->lines === []) {
            return implode("\n", [
                '*Target Period*',
                $range,
                '',
                '*New Articles*',
                '0',
                '',
                'No new articles in the last 24 hours.',
            ]);
        }

        $visibleLines = array_slice($this->lines, 0, 30);
        $hiddenCount = count($this->lines) - count($visibleLines);

        if ($hiddenCount > 0) {
            $visibleLines[] = sprintf('Others: %s categories', number_format($hiddenCount));
        }

        $lineText = collect($visibleLines)
            ->map(fn (string $line): string => "- {$line}")
            ->implode("\n");

        return implode("\n", [
            '*Target Period*',
            $range,
            '',
            '*New Articles Total*',
            number_format($this->totalNewCount),
            '',
            $lineText,
        ]);
    }
}
