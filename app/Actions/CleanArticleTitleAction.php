<?php

declare(strict_types=1);

namespace App\Actions;

class CleanArticleTitleAction
{
    /**
     * タイトルの不要な文言や記号を取り除きます。
     */
    public function execute(?string $title, string $siteName): ?string
    {
        if (empty($title)) {
            return null;
        }

        $cleanTitle = str_replace(trim($siteName), '', $title);
        $cleanTitle = str_replace(['まとめ', '速報', 'アンテナ'], '', $cleanTitle);
        $cleanTitle = preg_replace('/[\s\-:|：｜！\!\?？]+$/u', '', (string) $cleanTitle);
        $cleanTitle = preg_replace('/^[\s\-:|：｜！\!\?？]+/u', '', (string) $cleanTitle);
        $cleanTitle = trim((string) $cleanTitle);

        return $cleanTitle !== '' ? $cleanTitle : $title;
    }
}
