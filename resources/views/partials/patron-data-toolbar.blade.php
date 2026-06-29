@props([
    'registerRoute' => null,
    'registerLabel' => 'Register',
    'pendingUrl' => '#',
    'importTemplateRoute',
    'importRoute',
    'exportRoute',
    'downloadIdsRoute',
])

<div class="patron-panels">
    <details class="patron-panel patron-panel-main" open>
        <summary class="patron-panel-heading">Records</summary>
        <div class="patron-panel-body">
            <div class="patron-panel-stack">
                @if($registerRoute)
                    <a href="{{ $registerRoute }}" class="btn btn-add btn-sm w-100">{{ $registerLabel }}</a>
                @endif
                <a href="{{ $pendingUrl }}" class="btn btn-warning btn-sm w-100">Pending</a>
            </div>
        </div>
    </details>

    @can('isAdmin')
        <details class="patron-panel patron-panel-import" open>
            <summary class="patron-panel-heading">Import</summary>
            <div class="patron-panel-body">
                <div class="patron-panel-stack">
                    <a href="{{ route($importTemplateRoute) }}" class="btn btn-outline-secondary btn-sm w-100">Download Template</a>
                    <form action="{{ route($importRoute) }}" method="POST" enctype="multipart/form-data" class="patron-import-form w-100">
                        @csrf
                        <div class="patron-import-row">
                            <label class="patron-import-file flex-grow-1">
                                <span class="btn btn-light btn-sm mb-0 w-100">Choose file</span>
                                <input type="file" name="file" accept=".xlsx,.xls,.csv" required>
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm">Import</button>
                        </div>
                    </form>
                </div>
            </div>
        </details>
    @endcan

    <details class="patron-panel patron-panel-export" open>
        <summary class="patron-panel-heading">Export</summary>
        <div class="patron-panel-body">
            <div class="patron-panel-stack">
                <a href="{{ $exportRoute }}" class="btn btn-outline-primary btn-sm w-100">Export</a>
                <a href="{{ $downloadIdsRoute }}" class="btn btn-success btn-sm w-100">Download IDs</a>
            </div>
        </div>
    </details>
</div>

@once
    @push('scripts')
    <script>
    document.querySelectorAll('.patron-import-file input[type="file"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var label = input.closest('.patron-import-file')?.querySelector('span');
            if (label && input.files && input.files[0]) {
                label.textContent = input.files[0].name.length > 22
                    ? input.files[0].name.slice(0, 19) + '…'
                    : input.files[0].name;
            }
        });
    });
    </script>
    @endpush
@endonce
