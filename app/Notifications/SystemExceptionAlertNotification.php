<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;
use Throwable;

class SystemExceptionAlertNotification extends Notification
{
    use Queueable;

    public readonly string $exceptionClass;

    public readonly string $message;

    public readonly string $file;

    public readonly int $line;

    public readonly string $occurredAt;

    public function __construct(Throwable $exception)
    {
        $this->exceptionClass = $exception::class;
        $this->message = $exception->getMessage() !== ''
            ? Str::limit($exception->getMessage(), 1000)
            : '(message is empty)';
        $this->file = $exception->getFile();
        $this->line = $exception->getLine();
        $this->occurredAt = now()->toDateTimeString();
    }

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
            ->text('MatomeCore critical system alert')
            ->headerBlock('MatomeCore Critical Alert')
            ->sectionBlock(function (SectionBlock $block): void {
                $block->field("*Exception*\n{$this->exceptionClass}")->markdown();
                $block->field("*Location*\n{$this->file}:{$this->line}")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("*Message*\n>{$this->message}")->markdown();
            })
            ->contextBlock(function (ContextBlock $block): void {
                $block->text('Occurred at: '.$this->occurredAt);
            });
    }
}
