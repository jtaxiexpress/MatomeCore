<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Cache;

class CheckNgKeywordAction
{
    /** @var array<string> */
    private array $ngKeywords;

    public function __construct()
    {
        $ngKeywordsStr = Cache::get('ng_keywords', 'PR,AD,スポンサーリンク');
        $this->ngKeywords = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $ngKeywordsStr)));
    }

    public function execute(?string $title): bool
    {
        if (empty($title)) {
            return false;
        }

        foreach ($this->ngKeywords as $keyword) {
            if ($keyword !== '' && mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
