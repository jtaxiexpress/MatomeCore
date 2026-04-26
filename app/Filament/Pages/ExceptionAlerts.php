<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\AuthorizesAdminScreenPage;
use App\Support\AdminScreen;
use App\Support\Slack\ExceptionAlertClassifier;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

class ExceptionAlerts extends Page implements HasForms
{
    use AuthorizesAdminScreenPage;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|\UnitEnum|null $navigationGroup = '監視';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = '通知ルール管理';

    protected static ?string $title = '通知ルール管理';

    protected string $view = 'filament.pages.exception-alerts';

    public ?array $data = [];

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::NotificationRuleManagement;
    }

    public function mount(): void
    {
        $rules = ExceptionAlertClassifier::currentRules();

        $this->form->fill([
            'database_markers' => $this->rulesToTextarea($rules['database_markers'] ?? []),
            'timeout_markers' => $this->rulesToTextarea($rules['timeout_markers'] ?? []),
            'ollama_markers' => $this->rulesToTextarea($rules['ollama_markers'] ?? []),
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('通知ルール設定')
                    ->description('各行に1つずつキーワードを入力してください。例外メッセージに一致した場合に分類ロジックで使用されます。')
                    ->schema([
                        Textarea::make('database_markers')
                            ->label('DB接続エラー判定キーワード')
                            ->helperText('例: SQLSTATE[HY000] [2002], access denied for user')
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('timeout_markers')
                            ->label('外部APIタイムアウト判定キーワード')
                            ->helperText('例: timed out, curl error 28')
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('ollama_markers')
                            ->label('Ollama除外判定キーワード')
                            ->helperText('例: ollama, localhost:11434, /api/generate')
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->form->getState();

        Cache::put(ExceptionAlertClassifier::CACHE_KEY, [
            'database_markers' => $this->parseMarkers($state['database_markers'] ?? ''),
            'timeout_markers' => $this->parseMarkers($state['timeout_markers'] ?? ''),
            'ollama_markers' => $this->parseMarkers($state['ollama_markers'] ?? ''),
        ]);

        Notification::make()
            ->title('Exception Alerts のルールを保存しました')
            ->success()
            ->send();
    }

    /**
     * @param  array<int, string>  $rules
     */
    private function rulesToTextarea(array $rules): string
    {
        return implode(PHP_EOL, $rules);
    }

    /**
     * @return array<int, string>
     */
    private function parseMarkers(string $value): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $value) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter(static fn (string $line): bool => $line !== '')
            ->unique()
            ->values()
            ->all();
    }
}
