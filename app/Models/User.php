<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\AdminScreen;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'is_admin', 'admin_screen_permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'admin_screen_permissions' => 'array',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(App::class)->withTimestamps();
    }

    public function getTenants(Panel $panel): array|Collection
    {
        if ($panel->getId() === 'admin') {
            return $this->is_admin
                ? App::query()->orderBy('name')->get()
                : collect();
        }

        if ($this->is_admin) {
            return App::query()->orderBy('name')->get();
        }

        return $this->apps()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof App) {
            return false;
        }

        if ($this->is_admin) {
            return true;
        }

        return $this->apps()->whereKey($tenant->getKey())->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_admin,
            'app' => $this->is_admin || $this->apps()->exists(),
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public function adminScreenPermissionValues(): array
    {
        $permissions = $this->admin_screen_permissions;

        if (! is_array($permissions)) {
            return [];
        }

        return array_values(array_intersect(
            array_map(static fn (mixed $permission): string => (string) $permission, $permissions),
            AdminScreen::selectableValues(),
        ));
    }

    public function canAccessAdminScreen(AdminScreen $screen): bool
    {
        if (! $this->is_admin) {
            return false;
        }

        if ($screen === AdminScreen::Dashboard) {
            return true;
        }

        return in_array($screen->value, $this->adminScreenPermissionValues(), true);
    }
}
