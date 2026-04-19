<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\AuthorizesAdminScreenPage;
use App\Jobs\CheckDeadLinksJob;
use App\Models\Article;
use App\Support\AdminScreen;
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
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemSettings extends Page implements HasForms
{
    use AuthorizesAdminScreenPage;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.system-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'システム設定';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'システム設定';

    public ?array $data = [];

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::SystemSettings;
    }

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
            'ollama_model' => $this->currentOllamaModel(),
            'gemini_model' => $this->currentGeminiModel(),
            'ai_base_prompt' => Cache::get('ai_base_prompt', self::getDefaultPromptTemplate()),
            'site_analyzer_prompt' => Cache::get('site_analyzer_prompt', self::getDefaultSiteAnalyzerPrompt()),
            'ollama_num_predict' => Cache::get('ollama_num_predict', (int) config('ai.providers.ollama.options.num_predict', 3000)),
            'ollama_num_ctx' => Cache::get('ollama_num_ctx', (int) config('ai.providers.ollama.options.num_ctx', 8192)),
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
                Section::make('AIモデル設定（記事）')
                    ->schema([
                        Select::make('ollama_model')
                            ->label('Ollamaモデル名')
                            ->options(fn (): array => $this->getOllamaModelOptions())
                            ->searchable()
                            ->default(fn (): string => $this->currentOllamaModel())
                            ->helperText('Ollamaサーバーの /api/tags から取得したインストール済みモデル一覧から選択してください。')
                            ->required(),
                        TextInput::make('ollama_num_predict')
                            ->label('Ollama 出力トークン上限 (num_predict)')
                            ->numeric()
                            ->default((int) config('ai.providers.ollama.options.num_predict', 3000)),
                        TextInput::make('ollama_num_ctx')
                            ->label('Ollama コンテキスト長 (num_ctx)')
                            ->numeric()
                            ->default((int) config('ai.providers.ollama.options.num_ctx', 8192)),
                    ])->columns(2),
                Section::make('AIモデル設定（サイト解析）')
                    ->schema([
                        Select::make('gemini_model')
                            ->label('Geminiモデル名')
                            ->options(fn (): array => $this->getGeminiModelOptions())
                            ->searchable()
                            ->default(fn (): string => $this->currentGeminiModel())
                            ->helperText('Gemini APIの /v1beta/models から取得した generateContent 対応モデルを表示します。')
                            ->required(),
                    ]),
                Section::make('AIプロンプト設定')
                    ->schema([
                        Textarea::make('ai_base_prompt')
                            ->label('システム共通ベースプロンプト（役割と基本ルール）')
                            ->rows(15)
                            ->helperText('アプリ全体で共通してAIに与える役割や基本動作を定義します。※Structured Outputsを利用するため、JSONフォーマットや配列に関する指示は絶対に記述しないでください。また、{app_prompt}を配置した場所に個別アプリのプロンプトが展開されます。')
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    if (! str_contains((string) $value, '{categories}') || ! str_contains((string) $value, '{articles_json}') || ! str_contains((string) $value, '{count}') || ! str_contains((string) $value, '{app_prompt}')) {
                                        $fail('必須プレースホルダ（{app_prompt}, {categories}, {articles_json}, {count}）が含まれていません。');
                                    }
                                };
                            })
                            ->required(),
                        Textarea::make('site_analyzer_prompt')
                            ->label('AIサイト解析プロンプト')
                            ->rows(16)
                            ->helperText('サイト登録時の自動推論で、Geminiに与えるシステムプロンプトです。')
                            ->required(),
                    ]),
            ])->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->form->getState();
        Cache::put('is_bulk_paused', $state['is_bulk_paused'] ?? false);
        Cache::put('ollama_model', $state['ollama_model'] ?? $this->currentOllamaModel());
        Cache::put('gemini_model', $state['gemini_model'] ?? $this->currentGeminiModel());
        Cache::put('ai_base_prompt', $state['ai_base_prompt'] ?? self::getDefaultPromptTemplate());
        Cache::put('site_analyzer_prompt', $state['site_analyzer_prompt'] ?? self::getDefaultSiteAnalyzerPrompt());
        Cache::put('ollama_num_predict', $state['ollama_num_predict'] ?? (int) config('ai.providers.ollama.options.num_predict', 3000));
        Cache::put('ollama_num_ctx', $state['ollama_num_ctx'] ?? (int) config('ai.providers.ollama.options.num_ctx', 8192));

        Notification::make()
            ->title('設定を保存しました')
            ->success()
            ->send();
    }

    public static function getDefaultPromptTemplate(): string
    {
        return <<<'PROMPT'
あなたは日本語のアンテナサイト向けに複数記事の分類とリライトを一括で行う優秀な編集者です。
以下の記事を分析し、最適なカテゴリを選び、クリックしたくなる魅力的な日本語タイトルにリライトしてください。

【アプリ個別要件】
{app_prompt}

【カテゴリ一覧】
{categories}

【処理対象記事データ】
{articles_json}

※重要指示※
今回は全部で {count} 件です。出力するJSON配列の要素数は、絶対に {count} 件と完全に一致させなければなりません。1件も省略せず、最後まで出力してください。
PROMPT;
    }

    public static function getDefaultSiteAnalyzerPrompt(): string
    {
        return <<<'PROMPT'
あなたは優秀なスクレイピングエンジニアです。提供されるウェブページの構造を分析し、ブログ記事一覧を抽出するための設定を特定してください。
【重要】PR記事や広告バナー（例: osusume_banner, pr-item等）が含まれている場合、それらを除外するために必ずCSSの :not() 疑似クラスを用いてください。

以下のJSONスキーマで返答してください：
{
  "list_item_selector": "記事ブロックのCSSセレクタ (例: .post:not(.ad))",
  "link_selector": "aタグのセレクタ",
  "pagination_url_template": "次ページURLパターン (数字を {page} に置換)",
  "ng_image_urls": ["除外すべき共通画像のURLリスト"]
}
PROMPT;
    }

    /**
     * @return array<string, string>
     */
    private function getOllamaModelOptions(): array
    {
        return Cache::remember('ollama_available_models', 300, function (): array {
            try {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get($this->ollamaTagsUrl());

                if (! $response->successful()) {
                    return $this->fallbackOllamaModelOptions();
                }

                $models = data_get($response->json(), 'models', []);

                if (! is_array($models)) {
                    return $this->fallbackOllamaModelOptions();
                }

                $options = collect($models)
                    ->pluck('name')
                    ->filter(static fn ($name): bool => is_string($name) && $name !== '')
                    ->unique()
                    ->mapWithKeys(static fn (string $name): array => [$name => $name])
                    ->all();

                if ($options === []) {
                    return $this->fallbackOllamaModelOptions();
                }

                return $this->ensureCurrentModelExists($options);
            } catch (Throwable $e) {
                return $this->fallbackOllamaModelOptions();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function getGeminiModelOptions(): array
    {
        return Cache::remember('gemini_available_models', now()->addDay(), function (): array {
            $apiKey = $this->currentGeminiApiKey();

            if ($apiKey === '') {
                Log::warning('[SystemSettings] Gemini APIキーが未設定のためモデル一覧を取得できません。');

                return $this->fallbackGeminiModelOptions();
            }

            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get('https://generativelanguage.googleapis.com/v1beta/models', [
                        'key' => $apiKey,
                    ]);

                if (! $response->successful()) {
                    Log::warning('[SystemSettings] Geminiモデル一覧の取得に失敗しました', [
                        'status' => $response->status(),
                    ]);

                    return $this->fallbackGeminiModelOptions();
                }

                $models = data_get($response->json(), 'models', []);

                if (! is_array($models)) {
                    Log::warning('[SystemSettings] Geminiモデル一覧のJSON構造が不正です');

                    return $this->fallbackGeminiModelOptions();
                }

                $options = collect($models)
                    ->filter(static function ($model): bool {
                        if (! is_array($model)) {
                            return false;
                        }

                        $name = str_replace('models/', '', (string) ($model['name'] ?? ''));
                        $supportedMethods = $model['supportedGenerationMethods'] ?? [];

                        if ($name === '' || ! is_array($supportedMethods)) {
                            return false;
                        }

                        if (! in_array('generateContent', $supportedMethods, true)) {
                            return false;
                        }

                        return true;
                    })
                    ->map(static fn (array $model): string => str_replace('models/', '', (string) $model['name']))
                    ->filter(static fn (string $name): bool => $name !== '')
                    ->unique()
                    ->sort()
                    ->values()
                    ->mapWithKeys(static fn (string $name): array => [$name => $name])
                    ->all();

                if ($options === []) {
                    Log::warning('[SystemSettings] Geminiモデル一覧が空でした');

                    return $this->fallbackGeminiModelOptions();
                }

                return $this->ensureCurrentGeminiModelExists($options);
            } catch (Throwable $e) {
                Log::warning('[SystemSettings] Geminiモデル一覧の取得で例外が発生しました', [
                    'message' => $e->getMessage(),
                ]);

                return $this->fallbackGeminiModelOptions();
            }
        });
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private function ensureCurrentModelExists(array $options): array
    {
        $currentModel = $this->currentOllamaModel();

        if ($currentModel !== '' && ! array_key_exists($currentModel, $options)) {
            $options = [$currentModel => $currentModel] + $options;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function fallbackOllamaModelOptions(): array
    {
        $currentModel = $this->currentOllamaModel();

        return [$currentModel => $currentModel.' (取得失敗)'];
    }

    private function currentOllamaModel(): string
    {
        return (string) Cache::get('ollama_model', (string) config('ai.providers.ollama.model', 'gemma4:e2b'));
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private function ensureCurrentGeminiModelExists(array $options): array
    {
        $currentModel = $this->currentGeminiModel();

        if ($currentModel !== '' && ! array_key_exists($currentModel, $options)) {
            $options = [$currentModel => $currentModel] + $options;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function fallbackGeminiModelOptions(): array
    {
        $currentModel = $this->currentGeminiModel();

        return [$currentModel => $currentModel.' (取得失敗)'];
    }

    private function currentGeminiModel(): string
    {
        return (string) Cache::get(
            'gemini_model',
            (string) config('ai.providers.gemini.models.text.default', 'gemini-2.0-flash')
        );
    }

    private function currentGeminiApiKey(): string
    {
        $apiKey = (string) config('ai.providers.gemini.key', '');

        if ($apiKey !== '') {
            return $apiKey;
        }

        return (string) ($_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? '');
    }

    private function ollamaTagsUrl(): string
    {
        $baseUrl = rtrim((string) config('services.ollama.url', 'https://ollama.unicorn.tokyo'), '/');

        if (str_ends_with($baseUrl, '/api/tags')) {
            return $baseUrl;
        }

        if (str_ends_with($baseUrl, '/api')) {
            return $baseUrl.'/tags';
        }

        return $baseUrl.'/api/tags';
    }
}
