<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
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
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'is_bulk_paused' => Cache::get('is_bulk_paused', false),
            'ollama_model' => Cache::get('ollama_model', 'qwen3.5:9b'),
            'gemini_model' => Cache::get('gemini_model', 'gemini-1.5-flash-lite'),
            'ai_prompt_template' => Cache::get('ai_prompt_template', $this->getDefaultPromptTemplate()),
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
