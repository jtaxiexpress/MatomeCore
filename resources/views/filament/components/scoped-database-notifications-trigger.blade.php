<div class="flex items-center gap-2">
    @if ($unreadNotificationsCount)
        <x-filament::button
            type="button"
            color="gray"
            size="sm"
            icon="heroicon-o-check-circle"
            wire:click="markAllNotificationsAsRead"
            class="fi-topbar-database-notifications-mark-all-btn"
        >
            一括チェック
        </x-filament::button>
    @endif

    <x-filament::icon-button
        :badge="$unreadNotificationsCount ?: null"
        color="gray"
        icon="heroicon-o-bell"
        icon-size="lg"
        label="通知を開く"
        x-on:click="$dispatch('open-modal', { id: 'database-notifications' })"
        class="fi-topbar-database-notifications-btn"
    />
</div>
