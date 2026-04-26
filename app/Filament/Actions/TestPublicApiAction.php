<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Http\Resources\Api\V1\CategoryTabResource;
use App\Http\Resources\Api\V1\PublicArticleListResource;
use App\Models\App;
use App\Services\PublicApiService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class TestPublicApiAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'test_public_api')
            ->label('📱 アプリAPIテスト')
            ->icon('heroicon-o-device-phone-mobile')
            ->color('info')
            ->modalWidth('7xl')
            ->modalSubmitActionLabel('リクエスト送信')
            ->form([
                Select::make('api_type')
                    ->label('API種類')
                    ->options([
                        'config' => '初期設定',
                        'categories' => 'カテゴリ',
                        'feed' => '記事フィード',
                        'search' => '記事検索',
                        'trending' => '人気記事',
                    ])
                    ->default('config')
                    ->live()
                    ->required()
                    ->native(false),
                Select::make('category_slug')
                    ->label('カテゴリ')
                    ->options(function (?App $record): array {
                        if (! $record instanceof App) {
                            return [];
                        }

                        return $record->categories()
                            ->orderBy('sort_order')
                            ->orderBy('id')
                            ->pluck('name', 'api_slug')
                            ->all();
                    })
                    ->visible(fn (Get $get): bool => $get('api_type') === 'feed')
                    ->native(false)
                    ->searchable(),
                TextInput::make('keyword')
                    ->label('キーワード')
                    ->placeholder('例: AI ニュース')
                    ->visible(fn (Get $get): bool => $get('api_type') === 'search')
                    ->required(fn (Get $get): bool => $get('api_type') === 'search')
                    ->maxLength(255),
                Select::make('period')
                    ->label('期間')
                    ->options([
                        'daily' => 'daily',
                        'weekly' => 'weekly',
                        'monthly' => 'monthly',
                        'all' => 'all',
                    ])
                    ->default('daily')
                    ->visible(fn (Get $get): bool => $get('api_type') === 'trending')
                    ->required(fn (Get $get): bool => $get('api_type') === 'trending')
                    ->native(false),
            ])
            ->action(function (App $record, array $data, PublicApiService $publicApiService): void {
                try {
                    $responsePayload = self::resolveResponsePayload($record, $data, $publicApiService);

                    $json = json_encode(
                        $responsePayload,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    );

                    Notification::make()
                        ->success()
                        ->title('APIレスポンスを取得しました')
                        ->body(self::buildJsonPreviewBody($json))
                        ->persistent()
                        ->send();
                } catch (ModelNotFoundException) {
                    Notification::make()
                        ->danger()
                        ->title('APIテストに失敗しました')
                        ->body('指定されたカテゴリが見つかりません。')
                        ->persistent()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('APIテストに失敗しました')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function resolveResponsePayload(App $record, array $data, PublicApiService $publicApiService): array
    {
        $apiType = (string) ($data['api_type'] ?? 'config');

        return match ($apiType) {
            'categories' => [
                'data' => CategoryTabResource::collection($publicApiService->categories($record))->resolve(),
            ],
            'feed' => PublicArticleListResource::collection(
                $publicApiService->feed($record, isset($data['category_slug']) ? (string) $data['category_slug'] : null)
            )->response()->getData(true),
            'search' => PublicArticleListResource::collection(
                $publicApiService->search($record, (string) ($data['keyword'] ?? ''))
            )->response()->getData(true),
            'trending' => [
                'data' => PublicArticleListResource::collection(
                    $publicApiService->trending($record, (string) ($data['period'] ?? 'daily'), 20)
                )->resolve(),
            ],
            default => [
                'data' => $publicApiService->appConfig($record),
            ],
        };
    }

    private static function buildJsonPreviewBody(string $json): string
    {
        $escapedJson = htmlspecialchars($json, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<pre style='max-height: 420px; overflow-y: auto; padding: 12px; border-radius: 8px; background-color: rgba(15, 23, 42, 0.9); color: #e2e8f0; font-size: 12px; line-height: 1.55;'><code>{$escapedJson}</code></pre>";
    }
}
