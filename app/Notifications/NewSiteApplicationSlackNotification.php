<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class NewSiteApplicationSlackNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Site $site) {}

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
            ->text('【ゆにこーんアンテナ】新しい相互リンク申請が届きました')
            ->headerBlock('新規相互リンク申請')
            ->sectionBlock(function (SectionBlock $block): void {
                $appName = $this->site->app?->name ?? '不明なアプリ';
                $notes = $this->site->contact_notes ?: '（なし）';

                $block->field("*登録先アプリ*\n{$appName}")->markdown();
                $block->field("*サイト名*\n{$this->site->name}")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("*サイトURL*\n{$this->site->url}")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $notes = $this->site->contact_notes ?: '（なし）';
                $block->text("*連絡事項*\n{$notes}")->markdown();
            });
    }
}
