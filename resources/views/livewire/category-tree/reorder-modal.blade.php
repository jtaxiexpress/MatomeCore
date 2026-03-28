<div x-data="{
        init() {
            // SortableJSを読み込む（存在しない場合）
            if (typeof Sortable === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
                script.onload = () => this.initSortable();
                document.head.appendChild(script);
            } else {
                this.initSortable();
            }
        },
        initSortable() {
            const els = this.$el.querySelectorAll('.nested-sortable');
            els.forEach(el => {
                new Sortable(el, {
                    group: 'nested', // グループ名を同一にすることで階層間の移動を可能に
                    animation: 150,
                    fallbackOnBody: true,
                    swapThreshold: 0.65,
                    onEnd: (evt) => {
                        const root = this.$el.querySelector('#tree-root');
                        const data = this.serialize(root);
                        // Livewireに新しい配列構造を送信
                        $wire.updateOrder(data);
                    }
                });
            });
        },
        serialize(root) {
            let result = [];
            let items = root.children;
            for (let i = 0; i < items.length; i++) {
                let item = items[i];
                let id = item.dataset.id;
                if (!id) continue;
                let ul = item.querySelector('ul.nested-sortable');
                let children = ul ? this.serialize(ul) : [];
                result.push({ id: id, children: children });
            }
            return result;
        }
    }"
    class="space-y-4"
>
    <!-- ツリーのデザイン -->
    <style>
        .nested-sortable { min-height: 20px; padding-left: 20px; list-style-type: none; }
        #tree-root { padding-left: 0; margin-left: -20px; }
        .tree-item { margin-top: 8px; }
        .tree-handle { cursor: grab; background: #fff; border: 1px solid #e5e7eb; padding: 10px; border-radius: 6px; display: flex; align-items: center; gap: 10px; }
        .dark .tree-handle { background: #1f2937; border-color: #374151; color: white; }
    </style>

    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        カテゴリをドラッグ＆ドロップして階層と並び順を変更できます。ドロップした瞬間に自動でリアルタイム保存されます。
    </div>

    <!-- トップレベルのツリー要素（再帰的なBladeの呼び出し） -->
    <ul id="tree-root" class="nested-sortable">
        @include('livewire.category-tree.tree-nodes', ['nodes' => $tree])
    </ul>

    <!-- 通信中のローダー表示 -->
    <div wire:loading class="text-sm text-primary-500 font-bold mt-2">
        保存中...
    </div>
</div>
