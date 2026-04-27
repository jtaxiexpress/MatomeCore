<?php

namespace App\Livewire\CategoryTree;

use App\Models\Category;
use Filament\Notifications\Notification;
use Livewire\Component;

class ReorderModal extends Component
{
    public $appId;

    public function mount($appId)
    {
        $this->appId = (int) $appId;
    }

    // Sortable.jsからドロップされた階層データを受け取って保存
    public function updateOrder($tree)
    {
        $this->saveTree($tree, null);

        Notification::make()
            ->title('並び順を保存しました')
            ->success()
            ->send();

        // Filamentのテーブルに変更を反映
        $this->dispatch('refresh');
    }

    private function saveTree($items, $parentId = null)
    {
        foreach ($items as $index => $item) {
            // DBを更新 (階層が変わった場合は parent_id も更新される)
            Category::where('id', $item['id'])->update([
                'parent_id' => $parentId,
                'sort_order' => $index,
            ]);

            // 子カテゴリがある場合は再帰的に処理
            if (! empty($item['children'])) {
                $this->saveTree($item['children'], $item['id']);
            }
        }
    }

    public function render()
    {
        // 該当アプリの全カテゴリを取得し、並び順でソート
        // parent_id が null または指定以外のものについて取得（全取得してメモリ上でツリー化でも可）
        $categories = Category::where('app_id', $this->appId)
            ->orderBy('sort_order')
            ->get();

        // 階層型の配列に再構築してViewに渡す
        $tree = $this->buildTree($categories);

        return view('livewire.category-tree.reorder-modal', [
            'tree' => $tree,
        ]);
    }

    private function buildTree($categories, $parentId = null)
    {
        $branch = [];
        foreach ($categories as $category) {
            if ($category->parent_id == $parentId) {
                // 再帰的に小要素を取得してセット
                $category->children_list = $this->buildTree($categories, $category->id);
                $branch[] = $category;
            }
        }

        return $branch;
    }
}
