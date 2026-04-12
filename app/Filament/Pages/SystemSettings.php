<?php

namespace App\Filament\Pages;

use App\Jobs\CheckDeadLinksJob;
use App\Models\Article;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.system-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'システム管理';

    protected static ?string $title = 'システム設定';

    public ?array $data = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear_pending_jobs')
                ->label('Pendingキューの全消去')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('待機中キューの削除')
                ->modalDescription('本当に待機中のキューをすべて削除しますか？（※現在実行中のジョブは停止されません）')
                ->modalSubmitActionLabel('全消去する')
                ->action(function () {
                    Artisan::call('queue:clear');
                    Notification::make()
                        ->title('待機中のキューをクリアしました')
                        ->success()
                        ->send();
                }),

            ActionGroup::make([
                // 1. Queue Monitor等のクリーンアップ
                Action::make('pruneModels')
                    ->label('ログの自動掃除を実行 (Prune)')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalDescription('成功した古いジョブ履歴などを即座に削除します。よろしいですか？')
                    ->action(function () {
                        Artisan::call('model:prune');
                        Notification::make()
                            ->title('ログの掃除が完了しました')
                            ->success()
                            ->send();
                    }),

                // 2. 失敗したジョブのクリーンアップ
                Action::make('pruneFailedJobs')
                    ->label('古いエラー履歴を削除')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->requiresConfirmation()
                    ->modalDescription('30日以上経過したエラー履歴を強制削除します。')
                    ->action(function () {
                        Artisan::call('queue:prune-failed', ['--hours' => 720]);
                        Notification::make()
                            ->title('古いエラー履歴を削除しました')
                            ->success()
                            ->send();
                    }),

                // 3. リンク切れチェックの強制実行
                Action::make('runDeadLinkChecker')
                    ->label('リンク切れ巡回チェックを今すぐ実行')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->requiresConfirmation()
                    ->modalDescription('リンク切れチェックジョブ（100件分）をバックグラウンドのキューに投入します。')
                    ->action(function () {
                        CheckDeadLinksJob::dispatch();
                        Notification::make()
                            ->title('リンク切れチェックをバックグラウンドで開始しました')
                            ->success()
                            ->send();
                    }),

                // 4. チェック履歴のフルリセット
                Action::make('resetDeadLinkHistory')
                    ->label('チェック履歴をフルリセット')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('チェック履歴のリセット')
                    ->modalDescription('すべての記事の「リンク確認日時」をnullに戻します。次回チェック実行時に全記事が再スキャン対象になります。よろしいですか？')
                    ->modalSubmitActionLabel('リセットする')
                    ->action(function () {
                        Article::query()->update(['last_checked_at' => null]);
                        Notification::make()
                            ->title('チェック履歴をリセットしました')
                            ->body('すべての記事が次回の巡回チェック対象になりました。')
                            ->success()
                            ->send();
                    }),
            ])
                ->label('メンテナンス実行')
                ->icon('heroicon-m-wrench-screwdriver')
                ->button(),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'is_bulk_paused' => Cache::get('is_bulk_paused', false),
            'ollama_model' => Cache::get('ollama_model', 'qwen3.5:9b'),
            'gemini_model' => Cache::get('gemini_model', 'gemini-1.5-flash-lite'),
            'ai_prompt_template' => Cache::get('ai_prompt_template', $this->getDefaultPromptTemplate()),
            'ollama_num_predict' => Cache::get('ollama_num_predict', 3000),
            'ollama_num_ctx' => Cache::get('ollama_num_ctx', 8192),
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('システム制御')
                    ->schema([
                        Toggle::make('is_bulk_paused')
                            ->label('バルク処理一時停止 (キルスイッチ)')
                            ->helperText('オンにするとキューに入っている記事詳細取得・AI推論のジョブが処理されずに1分後に再スケジュールされ、一時停止状態になります。'),
                    ]),
                Section::make('AIモデル設定')
                    ->schema([
                        TextInput::make('ollama_model')
                            ->label('Ollamaモデル名')
                            ->default('qwen3.5:9b')
                            ->helperText('ローカルOllama環境のモデル名。')
                            ->required(),
                        TextInput::make('ollama_num_predict')
                            ->label('Ollama 出力トークン上限 (num_predict)')
                            ->numeric()
                            ->default(3000),
                        TextInput::make('ollama_num_ctx')
                            ->label('Ollama コンテキスト長 (num_ctx)')
                            ->numeric()
                            ->default(8192),
                        Select::make('gemini_model')
                            ->label('Geminiモデル名（デフォルト）')
                            ->helperText('Google Gemini APIのモデル名。アプリ個別設定がない場合はこちらが使用されます。')
                            ->searchable()
                            ->required()
                            ->options(function (): array {
                                return Cache::remember('gemini_model_list', 86400, function (): array {
                                    $apiKey = config('ai.providers.gemini.key', '');
                                    if (empty($apiKey)) {
                                        return [];
                                    }

                                    try {
                                        $response = Http::timeout(10)
                                            ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");

                                        if (! $response->successful()) {
                                            return [];
                                        }

                                        return collect($response->json('models', []))
                                            ->filter(fn ($m) => str_contains($m['name'] ?? '', 'gemini'))
                                            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? []))
                                            ->mapWithKeys(function ($m) {
                                                $name = str_replace('models/', '', $m['name']);
                                                $label = $m['displayName'] ?? $name;

                                                return [$name => $label];
                                            })
                                            ->sortKeys()
                                            ->toArray();
                                    } catch (\Exception $e) {
                                        return [];
                                    }
                                });
                            }),
                    ])->columns(2),
                Section::make('AIプロンプト設定')
                    ->schema([
                        Textarea::make('ai_prompt_template')
                            ->label('プロンプトテンプレート')
                            ->rows(15)
                            ->helperText('{categories} と {title} という文字列を入れると、実行時に動的に置換されます。フォーマット逸脱を防ぐため、JSONのみを出力する指示を含める事を強く推奨します。')
                            ->required(),
                    ]),
            ])->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->form->getState();
        Cache::put('is_bulk_paused', $state['is_bulk_paused'] ?? false);
        Cache::put('ollama_model', $state['ollama_model'] ?? 'qwen3.5:9b');
        Cache::put('gemini_model', $state['gemini_model'] ?? 'gemini-1.5-flash-lite');
        Cache::put('ai_prompt_template', $state['ai_prompt_template'] ?? $this->getDefaultPromptTemplate());
        Cache::put('ollama_num_predict', $state['ollama_num_predict'] ?? 3000);
        Cache::put('ollama_num_ctx', $state['ollama_num_ctx'] ?? 8192);

        Notification::make()
            ->title('設定を保存しました')
            ->success()
            ->send();
    }

    private function getDefaultPromptTemplate(): string
    {
        return <<<'PROMPT'
あなたは優秀な編集者です。以下の情報を見て推論を行ってください。

## 利用可能なカテゴリ一覧
{categories}

## 元のタイトル
{title}

要件:
1. タイトルをキャッチーで分かりやすくリライトしてください。
2. 最も適切なカテゴリのIDを1つ選んでください。
3. 出力は必ず以下のJSON形式とし、マークダウンや解説は一切含めないでください:
{"rewritten_title": "新しいタイトル", "category_id": 1}
PROMPT;
    }
}
