<?php

namespace App\Models;

use App\Notifications\NewSiteApplicationSlackNotification;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ng_url_keywords' => 'array',
            // JSON型で保存し、PHPでは配列として扱う
            'ng_image_urls' => 'array',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    protected static function booted(): void
    {
        static::created(function (Site $site) {
            // is_active が false の場合（新規申請時など）に通知を送る
            if (! $site->is_active) {
                // Filament Database Notification
                $adminUsers = User::where('is_admin', true)->get();
                $appUsers = User::whereHas('apps', function ($q) use ($site) {
                    $q->where('apps.id', $site->app_id);
                })->get();

                $usersToNotify = $adminUsers->merge($appUsers)->unique('id');

                Notification::make()
                    ->title('新規サイト申請')
                    ->body("{$site->name} からの相互リンク申請が届きました。")
                    ->info()
                    ->sendToDatabase($usersToNotify);

                // Slack Notification
                $webhookUrl = config('services.slack.blog_request_webhook_url');
                if ($webhookUrl) {
                    \Illuminate\Support\Facades\Notification::route('slack', $webhookUrl)
                        ->notify(new NewSiteApplicationSlackNotification($site));
                }
            }
        });
    }
}
