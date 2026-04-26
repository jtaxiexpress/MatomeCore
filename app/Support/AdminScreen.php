<?php

namespace App\Support;

enum AdminScreen: string
{
    case Dashboard = 'dashboard';

    case AppManagement = 'app_management';

    case SystemSettings = 'system_settings';

    case UserManagement = 'user_management';

    case LogViewer = 'log_viewer';

    case JobManagement = 'job_management';

    case NotificationRuleManagement = 'notification_rule_management';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'ダッシュボード',
            self::AppManagement => 'アプリ管理',
            self::SystemSettings => 'システム設定',
            self::UserManagement => 'ユーザー管理',
            self::LogViewer => 'ログビューア',
            self::JobManagement => 'ジョブ管理',
            self::NotificationRuleManagement => '通知ルール管理',
        };
    }

    public function isSelectable(): bool
    {
        return $this !== self::Dashboard;
    }

    /**
     * @return array<string, string>
     */
    public static function selectableOptions(): array
    {
        return array_reduce(
            array_filter(self::cases(), static fn (self $screen): bool => $screen->isSelectable()),
            static function (array $carry, self $screen): array {
                $carry[$screen->value] = $screen->label();

                return $carry;
            },
            [],
        );
    }

    /**
     * @return array<int, string>
     */
    public static function selectableValues(): array
    {
        return array_keys(self::selectableOptions());
    }
}
