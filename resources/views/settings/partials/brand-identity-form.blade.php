<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Brand Identity') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Make this workspace look like your own business. The logo and name replace the Cultiv One branding in the sidebar and header.') }}
        </p>
    </header>

    @if ($brandTenant === null)
        {{-- Branding belongs to a workspace, so there is nothing to brand yet. --}}
        <p class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
            {{ __('Select or create a workspace first — branding is set per workspace, not per account.') }}

            <a href="{{ route('tenants.index') }}" class="ml-1 font-medium text-indigo-600 hover:underline">
                {{ __('Go to workspaces') }}
            </a>
        </p>
    @elseif (! $canManageBranding)
        {{-- Read-only for everyone below Admin: the values are shown so members know
             what the workspace presents itself as, without an editable form. --}}
        <dl class="mt-6 space-y-3 text-sm">
            <div>
                <dt class="font-medium text-gray-700">{{ __('Brand Name') }}</dt>
                <dd class="text-gray-600">{{ $brandTenant->brandName() }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700">{{ __('Brand Description') }}</dt>
                <dd class="text-gray-600">{{ $brandTenant->brandTagline() }}</dd>
            </div>
        </dl>
        <p class="mt-4 text-xs text-gray-500">
            {{ __('Only the workspace owner or an admin can change the branding.') }}
        </p>
    @else
        <form method="post" action="{{ route('branding.update') }}" enctype="multipart/form-data"
              class="mt-6 space-y-6" x-data="brandIdentityForm">
            @csrf
            @method('patch')

            {{-- Company Logo: shows the saved logo (or the default) plus a live preview
                 of the file being chosen, so the effect is visible before saving. --}}
            <div>
                <x-input-label for="brand_logo" :value="__('Company Logo')" />

                <div class="mt-2 flex items-start gap-4">
                    <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-200">
                        @if ($brandTenant->brand_logo_path)
                            <img id="brand-logo-preview"
                                 src="{{ $brandTenant->brandLogoUrl() }}"
                                 alt="{{ __('Current logo') }}"
                                 class="h-full w-full object-contain" />
                        @else
                            {{-- No logo yet: preview the current identity, which is the
                                 Cultiv mark, so the empty state is not a blank box. --}}
                            <x-application-logo id="brand-logo-preview" class="h-10 w-10 text-indigo-600" />
                        @endif
                    </span>

                    <div class="min-w-0 flex-1">
                        <input id="brand_logo" name="brand_logo" type="file"
                               accept="image/png,image/jpeg,image/webp"
                               class="block w-full text-sm text-gray-600 file:me-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
                               x-on:change="previewLogo($event)" />

                        <p class="mt-1 text-xs text-gray-500">
                            {{ __('PNG, JPG or WEBP, up to 2 MB. A square image of at least 256 × 256 px looks best.') }}
                        </p>

                        @if ($brandTenant->brand_logo_path)
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="remove_logo" value="1"
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                {{ __('Remove the current logo') }}
                            </label>
                        @endif
                    </div>
                </div>

                <x-input-error class="mt-2" :messages="$errors->get('brand_logo')" />
            </div>

            <div>
                <x-input-label for="brand_name" :value="__('Brand Name')" />
                <x-text-input id="brand_name" name="brand_name" type="text" maxlength="30"
                              class="mt-1 block w-full"
                              :value="old('brand_name', $brandTenant->brand_name)"
                              placeholder="{{ __('Cultiv One') }}" />
                <p class="mt-1 text-xs text-gray-500">{{ __('Up to 30 characters. Leave empty to use the default name.') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('brand_name')" />
            </div>

            <div>
                <x-input-label for="brand_tagline" :value="__('Brand Description')" />
                <x-text-input id="brand_tagline" name="brand_tagline" type="text" maxlength="60"
                              class="mt-1 block w-full"
                              :value="old('brand_tagline', $brandTenant->brand_tagline)"
                              placeholder="{{ __('The smarter way to manage your business') }}" />
                <p class="mt-1 text-xs text-gray-500">{{ __('Up to 60 characters. Leave empty to use the default description.') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('brand_tagline')" />
            </div>

            <div class="flex items-center gap-4">
                <x-primary-button data-busy-label="{{ __('Saving…') }}">{{ __('Save branding') }}</x-primary-button>

                @if (session('success'))
                    <p class="text-sm text-gray-600" data-testid="brand-saved">{{ session('success') }}</p>
                @endif
            </div>
        </form>
    @endif
</section>
