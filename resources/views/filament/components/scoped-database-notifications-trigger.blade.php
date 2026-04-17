<div class="flex items-center gap-2">
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
