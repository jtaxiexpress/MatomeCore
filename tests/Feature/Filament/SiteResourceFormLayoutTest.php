<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\SiteResource;
use App\Filament\Resources\SiteResource\Pages\ManageSites;
use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Tests\TestCase;

class SiteResourceFormLayoutTest extends TestCase
{
    public function test_common_exclusion_settings_are_grouped_between_basic_and_crawl_sections(): void
    {
        $schema = SiteResource::form(Schema::make()->livewire(app(ManageSites::class)));
        $topLevelComponents = array_values($schema->getComponents());

        $this->assertCount(4, $topLevelComponents);

        $this->assertSectionHeading($topLevelComponents[0], '基本情報');
        $this->assertSectionHeading($topLevelComponents[1], '【共通】除外・フィルタリング設定');
        $this->assertSectionHeading($topLevelComponents[2], '【定期更新】日々の最新記事取得');
        $this->assertSectionHeading($topLevelComponents[3], '【一括取得】過去記事の抽出ルール');

        $basicSection = $topLevelComponents[0];
        $this->assertSame(['lg' => 1], $basicSection->getChildSchema()->getColumns());

        $commonSection = $topLevelComponents[1];
        $this->assertSame(
            '日々の自動取得（RSS等）と過去記事の一括取得の両方に共通して適用される除外ルールです。',
            $commonSection->getDescription(),
        );

        $commonChildComponents = array_values($commonSection->getChildSchema()->getComponents());

        $this->assertInstanceOf(TagsInput::class, $commonChildComponents[0]);
        $this->assertSame('ng_url_keywords', $commonChildComponents[0]->getName());
        $this->assertInstanceOf(TagsInput::class, $commonChildComponents[1]);
        $this->assertSame('ng_image_urls', $commonChildComponents[1]->getName());

        $bulkSection = $topLevelComponents[3];
        $bulkFieldNames = array_map(
            static fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null,
            array_values($bulkSection->getChildSchema()->getComponents()),
        );

        $this->assertFalse(in_array('ng_url_keywords', $bulkFieldNames, true));
        $this->assertFalse(in_array('ng_image_urls', $bulkFieldNames, true));
    }

    private function assertSectionHeading(object $component, string $expectedHeading): void
    {
        $this->assertInstanceOf(Section::class, $component);
        $this->assertSame($expectedHeading, $component->getHeading());
    }
}
