<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class App extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    public static function themeColorOptions(): array
    {
        return [
            '#2563EB' => 'Blue (#2563EB)',
            '#4F46E5' => 'Indigo (#4F46E5)',
            '#7C3AED' => 'Purple (#7C3AED)',
            '#DB2777' => 'Pink (#DB2777)',
            '#DC2626' => 'Red (#DC2626)',
            '#EA580C' => 'Orange (#EA580C)',
            '#16A34A' => 'Green (#16A34A)',
            '#4B5563' => 'Gray (#4B5563)',
        ];
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getIconUrlAttribute(): ?string
    {
        if (blank($this->icon_path)) {
            return null;
        }

        return Storage::disk('public')->url((string) $this->icon_path);
    }

    protected static function booted(): void
    {
        static::saving(function (App $app): void {
            $app->api_slug = static::resolveUniqueApiSlug(
                value: (string) ($app->api_slug ?: $app->name),
                ignoreId: $app->id,
            );
        });
    }

    private static function resolveUniqueApiSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'app';

        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->where('api_slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
