<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Files') }}</h2>
    </x-slot>

    <div class="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        {{-- Storage quota --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex justify-between text-sm mb-2">
                <span class="font-semibold">Storage</span>
                <span>{{ $storageUsedMb }} MB / {{ $storageLimitMb ?? '∞' }} MB</span>
            </div>
            @if ($storageLimitMb)
                @php $pct = min(100, (int) ($storageUsedMb / $storageLimitMb * 100)); @endphp
                <div class="h-2 bg-gray-200 rounded"><div class="h-2 {{ $pct >= 90 ? 'bg-red-500' : 'bg-indigo-600' }} rounded" style="width: {{ $pct }}%"></div></div>
                @if ($pct >= 90)
                    <p class="text-xs text-red-600 mt-2">Storage almost full — <a class="underline" href="{{ route('billing.index') }}">upgrade your plan</a> to add more.</p>
                @endif
            @endif
        </div>

        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
        @endif

        {{-- Upload form --}}
        <form method="POST" action="{{ route('files.store') }}" enctype="multipart/form-data"
              class="bg-white shadow rounded-lg p-6 flex gap-3 items-center">
            @csrf
            <input type="file" name="file" required
                   class="flex-1 text-sm file:mr-3 file:px-3 file:py-1.5 file:rounded file:border-0 file:bg-indigo-50 file:text-indigo-700">
            @error('file') <p class="text-xs text-red-600 w-48 flex flex-col gap-1">{{ $message }}</p> @enderror
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">Upload</button>
        </form>
        <p class="text-xs text-gray-400">Allowed: {{ implode(', ', array_map(fn ($m) => str_replace('*', 'any', $m), config('saas.uploads.allowed_mimes'))) }}.</p>

        {{-- File list --}}
        <div class="bg-white shadow rounded-lg divide-y">
            @forelse ($files as $file)
                <div class="p-4 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="font-medium truncate">{{ $file->original_name }}</div>
                        <div class="text-xs text-gray-500">
                            {{ number_format($file->size / 1024, 1) }} KB · {{ $file->mime_type }} · {{ $file->created_at->diffForHumans() }}
                        </div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <a href="{{ route('files.download', $file) }}" class="text-indigo-600 text-sm underline">Download</a>
                        <form method="POST" action="{{ route('files.destroy', $file) }}"
                              onsubmit="return confirm('Delete this file?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 text-sm underline">Delete</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="p-6 text-gray-500">No files yet — upload one above.</div>
            @endforelse
        </div>
    </div>
</x-app-layout>
