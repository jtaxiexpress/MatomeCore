<div style="padding: 12px; border-top: 1px solid rgba(255,255,255,0.1); margin: 8px; display: flex; flex-direction: column; gap: 10px;">
    {{-- User Info & Logout --}}
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.04);">
        <div style="display: flex; align-items: center; gap: 10px; overflow: hidden; min-width: 0;">
            <div style="width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%; background: var(--color-primary-500, #6366f1); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 13px;">
                {{ mb_substr(auth()->user()->name, 0, 1) }}
            </div>
            <div style="overflow: hidden; min-width: 0;">
                <div style="font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.9); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ auth()->user()->name }}</div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.4);">Admin</div>
            </div>
        </div>
        <form action="{{ filament()->getLogoutUrl() }}" method="post" style="flex-shrink: 0; margin-left: 8px;">
            @csrf
            <button type="submit" title="ログアウト" style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: transparent; border: none; cursor: pointer; color: rgba(255,255,255,0.45); transition: background 0.2s, color 0.2s;"
                onmouseover="this.style.background='rgba(239,68,68,0.15)'; this.style.color='#ef4444';"
                onmouseout="this.style.background='transparent'; this.style.color='rgba(255,255,255,0.45)';">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 18px; height: 18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                </svg>
            </button>
        </form>
    </div>

    {{-- Filament Info Links --}}
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 0 4px;">
        <a href="https://filamentphp.com/docs" target="_blank" style="display: flex; align-items: center; gap: 5px; font-size: 11px; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s;"
            onmouseover="this.style.color='rgba(255,255,255,0.7)';"
            onmouseout="this.style.color='rgba(255,255,255,0.35)';">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span>Filament v{{ \Composer\InstalledVersions::getPrettyVersion('filament/filament') }}</span>
        </a>
        <a href="https://github.com/filamentphp/filament" target="_blank" style="display: flex; align-items: center; gap: 5px; font-size: 11px; color: rgba(255,255,255,0.35); text-decoration: none; transition: color 0.2s;"
            onmouseover="this.style.color='rgba(255,255,255,0.7)';"
            onmouseout="this.style.color='rgba(255,255,255,0.35)';">
            <svg style="width: 14px; height: 14px; fill: currentColor;" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
            <span>GitHub</span>
        </a>
    </div>
</div>
