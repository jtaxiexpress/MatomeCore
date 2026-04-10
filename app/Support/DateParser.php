<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Exception;

class DateParser
{
    /**
     * あらゆる形式の文字列から日時をパースし、安全にCarbonインスタンスを返します。
     * 解析失敗時は null を返します。
     */
    public static function parse(?string $rawDate): ?Carbon
    {
        if (empty($rawDate)) {
            return null;
        }

        $cleanedDate = preg_replace('/[（\(][日月火水木金土祝][）\)]/u', '', $rawDate);
        $cleanedDate = trim((string) $cleanedDate);

        if (empty($cleanedDate)) {
            return null;
        }

        try {
            return Carbon::parse($cleanedDate);
        } catch (Exception) {
            if (preg_match('/(20\d{2})[年\/\-\s]+(\d{1,2})[月\/\-\s]+(\d{1,2})日?\s*(?:(\d{1,2})[:時](\d{1,2})(?::(\d{1,2}))?)?/', $cleanedDate, $matches)) {
                try {
                    return Carbon::create(
                        (int) $matches[1],
                        (int) $matches[2],
                        (int) $matches[3],
                        (int) ($matches[4] ?? 0),
                        (int) ($matches[5] ?? 0),
                        (int) ($matches[6] ?? 0)
                    );
                } catch (Exception) {
                    return null;
                }
            }
        }

        return null;
    }
}
