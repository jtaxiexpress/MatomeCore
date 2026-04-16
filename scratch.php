<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Update App models
$apps = App\Models\App::all();
foreach ($apps as $model) {
    if (strpos($model->ai_prompt_template, 'リライトされたタイトル1') !== false) {
        $model->ai_prompt_template = preg_replace(
            '/\[\s*\{"article_id": 1, .*\](?=\n)/s',
            '[
  {"article_id": 1, "rewritten_title": "リライトされたタイトル1", "category_id": 1},
  {"article_id": 2, "rewritten_title": "リライトされたタイトル2", "category_id": 2}
]

【絶対厳守】
- 上記のJSON配列のみを出力すること
- `{"results": [...]}` のようなラッパーオブジェクトは絶対に使用しない
- コードブロック（```）やMarkdownも一切含めない
- 説明文・前置き・後書きも一切含めない
- 配列の要素数は入力記事数と完全に一致させること',
            $model->ai_prompt_template
        );
        $model->save();
        echo "Updated DB prompt for App ID: {$model->id}\n";
    }
}

// Update Cache value
$cacheTemplate = Cache::get('ai_prompt_template');
if ($cacheTemplate && strpos($cacheTemplate, 'リライトされたタイトル1') !== false) {
    $newTemplate = preg_replace(
        '/\[\s*\{"article_id": 1, .*\](?=\n)/s',
        '[
  {"article_id": 1, "rewritten_title": "リライトされたタイトル1", "category_id": 1},
  {"article_id": 2, "rewritten_title": "リライトされたタイトル2", "category_id": 2}
]

【絶対厳守】
- 上記のJSON配列のみを出力すること
- `{"results": [...]}` のようなラッパーオブジェクトは絶対に使用しない
- コードブロック（```）やMarkdownも一切含めない
- 説明文・前置き・後書きも一切含めない
- 配列の要素数は入力記事数と完全に一致させること',
        $cacheTemplate
    );
    Cache::put('ai_prompt_template', $newTemplate);
    echo "Updated Cache prompt.\n";
} else {
    echo "No matching string found in Cache.\n";
}
