<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AppResource;
use App\Filament\Resources\AppResource\Pages\ManageApps;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Tests\TestCase;

class AppResourceFormLayoutTest extends TestCase
{
    public function test_ai_section_includes_custom_scrape_rules_repeater(): void
    {
        $schema = AppResource::form(Schema::make()->livewire(app(ManageApps::class)));
        $topLevelComponents = array_values($schema->getComponents());

        $this->assertCount(5, $topLevelComponents);

        $this->assertSectionHeading($topLevelComponents[0], 'アプリ基本情報');
        $this->assertSectionHeading($topLevelComponents[1], '外観設定');
        $this->assertSectionHeading($topLevelComponents[2], '公開設定');
        $this->assertSectionHeading($topLevelComponents[3], 'スケジュール設定');
        $this->assertSectionHeading($topLevelComponents[4], 'AI設定（アプリ別オーバーライド）');

        $aiSection = $topLevelComponents[4];
        $aiChildComponents = array_values($aiSection->getChildSchema()->getComponents());

        $this->assertCount(2, $aiChildComponents);
        $this->assertInstanceOf(Textarea::class, $aiChildComponents[0]);
        $this->assertSame('ai_prompt_template', $aiChildComponents[0]->getName());

        $this->assertInstanceOf(Repeater::class, $aiChildComponents[1]);
        $this->assertSame('custom_scrape_rules', $aiChildComponents[1]->getName());

        $ruleFieldNames = array_values(array_map(
            static fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null,
            array_values($aiChildComponents[1]->getChildComponents()),
        ));

        $this->assertSame([
            'domain',
            'list_item_selector',
            'link_selector',
        ], $ruleFieldNames);
    }

    private function assertSectionHeading(object $component, string $expectedHeading): void
    {
        $this->assertInstanceOf(Section::class, $component);
        $this->assertSame($expectedHeading, $component->getHeading());
    }
}
