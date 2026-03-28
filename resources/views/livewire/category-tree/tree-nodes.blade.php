@foreach($nodes as $node)
    <li data-id="{{ $node->id }}" class="tree-item">
        <div class="tree-handle text-sm shadow-sm transition hover:bg-gray-50 dark:hover:bg-gray-800">
            <!-- ドラッグ用のアイコン -->
            <x-heroicon-o-bars-3 class="w-5 h-5 text-gray-400" />
            <span class="font-medium">{{ $node->name }}</span>
        </div>
        
        <!-- 子要素のネストエリア -->
        <ul class="nested-sortable">
            @if(!empty($node->children_list))
                @include('livewire.category-tree.tree-nodes', ['nodes' => $node->children_list])
            @endif
        </ul>
    </li>
@endforeach
