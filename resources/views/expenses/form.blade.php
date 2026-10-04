<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ $pageTitle }}</h2>
            <a href="{{ route('expenses.index') }}" class="text-sm underline">{{ __('Back to expenses') }}</a>
        </div>
    </x-slot>

    @php($amount = fn ($v) => $v ? number_format($v / 100, 2, '.', '') : '')

    <div class="mx-auto max-w-3xl py-12 sm:px-6 lg:px-8">
        {{-- `space-y-6` on the FORM, with the fields in one card and the action row
             outside it — the same shape sales/create and purchases/form use.

             The previous markup put the action row INSIDE the field card, and the card
             contained an unclosed `<div class="grid gap-4 sm:grid-cols-2">`. That single
             missing </div> swallowed the notes block and the action row into the grid,
             so "Save expense" became a grid item sitting level with "Notes" on
             sm-and-up. --}}
        <form method="POST" action="{{ $submitUrl }}" class="space-y-6">
            @csrf
            @if ($expense->exists)
                @method('PUT')
            @endif

            {{-- A rejected submit re-renders this form; without a visible reason the user
                 only saw their own input come back and no indication of what to fix. --}}
            @if (session('status'))
                @php($status = session('status'))
                <div role="{{ ($status['type'] ?? 'success') === 'error' ? 'alert' : 'status' }}"
                     class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                    {{ $status['message'] ?? '' }}
                </div>
            @endif

            @if ($errors->any())
                <div role="alert" class="rounded-lg bg-red-100 p-3 text-red-800">
                    <p class="font-semibold">{{ __('Please complete the required fields.') }}</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="space-y-5 rounded-lg bg-white p-6 shadow">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="description" :value="__('Description *')"/><x-text-input id="description" name="description" class="mt-1 block w-full" :value="old('description',$expense->description)" required/><x-input-error :messages="$errors->get('description')" class="mt-2"/>
            </div>
            <div>
                <x-input-label for="amount" :value="__('Amount (Rp) *')"/><x-text-input id="amount" name="amount" type="number" min="0.01" step="0.01" class="mt-1 block w-full" :value="old('amount',$amount($expense->amount))" required/><x-input-error :messages="$errors->get('amount')" class="mt-2"/>
            </div>
        </div>
        <div>
            <div class="flex items-center justify-between gap-2">
                <x-input-label for="category_id" :value="__('Category')" />
                {{-- The dropdown gave no hint of where categories come from, and nothing
                     else in the UI let a user create one. This is that entry point, and
                     it is only rendered when the user could actually reach the page. --}}
                @if ($canManageCategories)
                    <a href="{{ route('expense-categories.index') }}" class="text-xs text-indigo-600 underline">
                        {{ __('Manage categories') }}
                    </a>
                @endif
            </div>

            <select id="category_id" name="category_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">{{ __('No category') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $expense->category_id) == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>

            {{-- Field is optional, but an empty list should still explain itself rather
                 than looking like a broken select. --}}
            @if ($categories->isEmpty())
                <p class="mt-1 text-xs text-amber-700">
                    {{ __('No expense categories yet.') }}
                    @if ($canManageCategories)
                        <a href="{{ route('expense-categories.index') }}" class="underline">{{ __('Add one') }}</a>.
                    @endif
                </p>
            @endif

            <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="expense_date" :value="__('Date *')"/><x-text-input id="expense_date" name="expense_date" type="date" class="mt-1 block w-full" :value="old('expense_date',$expense->expense_date?->format('Y-m-d'))" required/><x-input-error :messages="$errors->get('expense_date')" class="mt-2"/>
            </div>
            <div>
                <x-input-label for="payment_method" :value="__('Payment method')"/><select id="payment_method" name="payment_method" class="mt-1 block w-full rounded-md border-gray-300">@foreach(['cash','bank','transfer','qris','other'] as $method)<option value="{{ $method }}" @selected(old('payment_method',$expense->payment_method ?: 'cash')===$method)>{{ str($method)->headline() }}</option>@endforeach</select><x-input-error :messages="$errors->get('payment_method')" class="mt-2"/>
            </div>
        </div>
        <div>
            <x-input-label for="notes" :value="__('Notes')" />
            <textarea id="notes" name="notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300">{{ old('notes',$expense->notes) }}</textarea>
            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
        </div>
            </div>

            {{-- Action row: OUTSIDE the field card, matching sales/create and
                 purchases/form. <x-primary-button> is untouched — it is the shared design
                 system control, so it keeps exactly the same height, padding, font-size
                 and radius as every other create form in the app. --}}
            <div class="flex items-center gap-3">
                <x-primary-button>{{ $expense->exists ? __('Update expense') : __('Save expense') }}</x-primary-button>
                <a href="{{ route('expenses.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>