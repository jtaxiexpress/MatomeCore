<?php

use App\Models\App;
use App\Models\Site;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Layout('layouts::app')]
class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:255|unique:sites,url')]
    public string $url = '';

    #[Validate('required|url|max:255')]
    public string $rss_url = '';

    #[Validate('required|email|max:255')]
    public string $contact_email = '';

    #[Validate('nullable|string|max:1000')]
    public ?string $contact_notes = null;

    #[Validate('required|exists:apps,id')]
    public ?int $app_id = null;

    public bool $isSubmitted = false;

    public function getAppsProperty()
    {
        return App::where('is_active', true)->get();
    }

    public function submit(): void
    {
        $executed = RateLimiter::attempt(
            'site-application:' . request()->ip(),
            $maxAttempts = 3,
            function () {
                $this->validate();

                Site::create([
                    'app_id' => $this->app_id,
                    'name' => $this->name,
                    'url' => $this->url,
                    'rss_url' => $this->rss_url,
                    'contact_email' => $this->contact_email,
                    'contact_notes' => $this->contact_notes,
                    'is_active' => false,
                ]);

                $this->isSubmitted = true;
            },
            $decaySeconds = 3600, // 1 hour
        );

        if (! $executed) {
            $this->addError('general', '送信回数の上限に達しました。しばらく時間をおいてから再度お試しください。');
        }
    }
}; ?>

<div>
    @section('title', '相互リンク登録申請')
    @section('tenant_name', config('app.name'))

    <div class="mx-auto max-w-2xl">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary dark:text-white">相互リンク登録申請</h1>
            <p class="mt-2 text-sm text-text-secondary dark:text-text-tertiary">
                当アンテナサイトへの登録をご希望の方は、以下のフォームよりご申請ください。<br>
                審査の上、問題がなければ登録（クローラーの巡回開始）となります。
            </p>
        </div>

        @if ($isSubmitted)
            <div class="rounded-2xl border border-green-500/20 bg-green-500/10 p-8 text-center">
                <span class="mb-4 inline-flex size-12 items-center justify-center rounded-full bg-green-500/20 text-2xl text-green-600 dark:text-green-400">
                    ✓
                </span>
                <h2 class="mb-2 text-lg font-bold text-green-700 dark:text-green-400">申請を受け付けました</h2>
                <p class="text-sm text-green-600 dark:text-green-500">
                    ご登録いただいた内容を確認し、順次対応いたします。<br>
                    結果のご連絡は差し上げておりませんので、実際の配信状況にてご確認ください。
                </p>
                <div class="mt-6">
                    <a href="{{ url('/') }}" class="inline-flex items-center justify-center rounded-lg bg-surface px-4 py-2 text-sm font-medium text-text-secondary transition-colors hover:bg-black/5 dark:bg-surface-dark dark:text-text-tertiary dark:hover:bg-white/10" wire:navigate>
                        トップページへ戻る
                    </a>
                </div>
            </div>
        @else
            <form wire:submit="submit" class="rounded-xl border border-border/40 bg-surface-elevated/50 backdrop-blur-xl p-6 shadow-sm sm:p-8 dark:border-border-dark/40 dark:bg-surface-elevated-dark/50">
                @error('general')
                    <div class="mb-6 rounded-lg bg-red-500/10 p-4 text-sm text-red-600 dark:text-red-400">
                        {{ $message }}
                    </div>
                @enderror

                <div class="space-y-6">
                    {{-- App Selection --}}
                    <div>
                        <label for="app_id" class="mb-2 block text-sm font-medium text-text-primary dark:text-white">登録先アプリ <span class="text-red-500">*</span></label>
                        <select id="app_id" wire:model="app_id" class="w-full rounded-lg border border-slate-200/50 bg-white/50 px-4 py-2.5 text-sm text-text-primary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent dark:border-white/10 dark:bg-black/40 dark:text-white">
                            <option value="">選択してください</option>
                            @foreach ($this->apps as $app)
                                <option value="{{ $app->id }}">{{ $app->name }}</option>
                            @endforeach
                        </select>
                        @error('app_id') <span class="mt-1 block text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>

                    {{-- Site Name --}}
                    <div>
                        <label for="name" class="mb-2 block text-sm font-medium text-text-primary dark:text-white">サイト名 <span class="text-red-500">*</span></label>
                        <input type="text" id="name" wire:model="name" placeholder="例: まとめコア速報" class="w-full rounded-lg border border-slate-200/50 bg-white/50 px-4 py-2.5 text-sm text-text-primary placeholder-text-tertiary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent dark:border-white/10 dark:bg-black/40 dark:text-white dark:placeholder-text-tertiary/50">
                        @error('name') <span class="mt-1 block text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>

                    {{-- Site URL --}}
                    <div>
                        <label for="url" class="mb-2 block text-sm font-medium text-text-primary dark:text-white">サイトURL <span class="text-red-500">*</span></label>
                        <input type="url" id="url" wire:model="url" placeholder="https://example.com" class="w-full rounded-lg border border-slate-200/50 bg-white/50 px-4 py-2.5 text-sm text-text-primary placeholder-text-tertiary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent dark:border-white/10 dark:bg-black/40 dark:text-white dark:placeholder-text-tertiary/50">
                        @error('url') <span class="mt-1 block text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>

                    {{-- RSS URL --}}
                    <div>
                        <label for="rss_url" class="mb-2 block text-sm font-medium text-text-primary dark:text-white">RSS URL <span class="text-red-500">*</span></label>
                        <input type="url" id="rss_url" wire:model="rss_url" placeholder="https://example.com/feed" class="w-full rounded-lg border border-slate-200/50 bg-white/50 px-4 py-2.5 text-sm text-text-primary placeholder-text-tertiary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent dark:border-white/10 dark:bg-black/40 dark:text-white dark:placeholder-text-tertiary/50">
                        @error('rss_url') <span class="mt-1 block text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>

                    {{-- Contact Email --}}
                    <div>
                        <label for="contact_email" class="mb-2 block text-sm font-medium text-text-primary dark:text-white">連絡先メールアドレス <span class="text-red-500">*</span></label>
                        <input type="email" id="contact_email" wire:model="contact_email" placeholder="admin@example.com" class="w-full rounded-lg border border-slate-200/50 bg-white/50 px-4 py-2.5 text-sm text-text-primary placeholder-text-tertiary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent dark:border-white/10 dark:bg-black/40 dark:text-white dark:placeholder-text-tertiary/50">
                        @error('contact_email') <span class="mt-1 block text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>

                    {{-- Contact Notes --}}
                    <div>
                        <label for="contact_notes" class="mb-2 block text-sm font-medium text-text-primary dark:text-white">連絡事項 <span class="text-xs text-text-tertiary font-normal">(任意)</span></label>
                        <textarea id="contact_notes" wire:model="contact_notes" rows="3" placeholder="サイトの特徴やご要望などがあればご記入ください" class="w-full rounded-lg border border-slate-200/50 bg-white/50 px-4 py-2.5 text-sm text-text-primary placeholder-text-tertiary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent dark:border-white/10 dark:bg-black/40 dark:text-white dark:placeholder-text-tertiary/50"></textarea>
                        @error('contact_notes') <span class="mt-1 block text-xs text-red-500">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mt-8 flex justify-center">
                    <button type="submit" class="inline-flex min-w-[200px] items-center justify-center rounded-lg bg-accent px-6 py-3 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-50" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submit">申請する</span>
                        <span wire:loading wire:target="submit" class="inline-flex items-center gap-2">
                            <svg class="size-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            送信中...
                        </span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
