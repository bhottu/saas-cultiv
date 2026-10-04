{{--
    Platform SEO settings.

    Structure mirrors the way the values are actually consumed: general metadata, then
    Open Graph, then Twitter/X, then the assets, then indexing. Every field falls back to
    config('seo.*') when left blank, and the placeholder on each input shows that default
    so an administrator can see what "empty" actually renders as instead of guessing.

    One form posts to one route. Splitting it into per-section submits would mean several
    half-saved states, and a validation error on section three must not leave section one
    silently applied.
--}}
<x-admin-shell :title="__('SEO Settings')"
               :subtitle="__('Defaults for every public page. A page with its own metadata still wins.')"
               :editable="true">
    <form method="POST" action="{{ route('admin.seo.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- ------------------------------------------------------- General SEO --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('General SEO') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('Used by every page that does not declare its own title or description.') }}
            </p>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <x-input-label for="site_name" :value="__('Website Name')" />
                    <x-text-input id="site_name" name="site_name" type="text" class="mt-1 block w-full"
                                  :value="old('site_name', $settings->site_name)"
                                  :placeholder="config('seo.site_name')" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Appended to the title of every non-home page.') }}</p>
                </div>

                <div>
                    <x-input-label for="default_title" :value="__('Website Title')" />
                    <x-text-input id="default_title" name="default_title" type="text" class="mt-1 block w-full"
                                  :value="old('default_title', $settings->default_title)"
                                  :placeholder="config('seo.default_title')" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('The title of the landing page. Leave blank for the current default.') }}</p>
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="default_description" :value="__('Meta Description')" />
                    <textarea id="default_description" name="default_description" rows="3"
                              maxlength="1000"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                              placeholder="{{ config('seo.default_description') }}">{{ old('default_description', $settings->default_description) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Aim for 120–160 characters. Leave blank for the current default.') }}
                    </p>
                    <x-input-error :messages="$errors->get('default_description')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="canonical_url" :value="__('Canonical URL')" />
                    <x-text-input id="canonical_url" name="canonical_url" type="url" class="mt-1 block w-full"
                                  :value="old('canonical_url', $settings->canonical_url)"
                                  :placeholder="config('seo.url')" />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('The address every canonical link points at. Useful after a domain change.') }}
                    </p>
                </div>

                <div>
                    <x-input-label for="theme_color" :value="__('Theme Colour')" />
                    <x-text-input id="theme_color" name="theme_color" type="text" class="mt-1 block w-full"
                                  :value="old('theme_color', $settings->theme_color)"
                                  :placeholder="config('seo.theme_color')" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('A hex value such as #4f46e5, used by the browser toolbar.') }}</p>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------- Open Graph --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Open Graph') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('How a shared link looks in WhatsApp, Facebook and Slack.') }}
            </p>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <x-input-label for="og_title" :value="__('Open Graph Title')" />
                    <x-text-input id="og_title" name="og_title" type="text" class="mt-1 block w-full"
                                  :value="old('og_title', $settings->og_title)" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Leave blank to reuse each page title.') }}</p>
                </div>

                <div>
                    <x-input-label for="og_site_name" :value="__('Open Graph Site Name')" />
                    <x-text-input id="og_site_name" name="og_site_name" type="text" class="mt-1 block w-full"
                                  :value="old('og_site_name', $settings->og_site_name)"
                                  :placeholder="$settings->site_name ?: config('seo.site_name')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="og_description" :value="__('Open Graph Description')" />
                    <textarea id="og_description" name="og_description" rows="2" maxlength="1000"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('og_description', $settings->og_description) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Leave blank to reuse the page description.') }}</p>
                </div>

                <div>
                    <x-input-label for="og_type" :value="__('Open Graph Type')" />
                    <select id="og_type" name="og_type"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('Use the default (website)') }}</option>
                        @foreach (['website', 'article', 'product', 'profile'] as $type)
                            <option value="{{ $type }}" @selected(old('og_type', $settings->og_type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- --------------------------------------------- Favicon & share images --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Favicon & Images') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('PNG, JPG, ICO or WEBP. Leave a field empty to keep using the built-in file.') }}
            </p>

            <div class="mt-5 grid gap-6 md:grid-cols-3">
                {{-- Each upload is previewed next to its field, because a favicon that
                     was saved but is not the one showing in the tab is the single most
                     common complaint about a settings screen like this one. --}}
                <div>
                    <x-input-label for="favicon" :value="__('Favicon')" />
                    @if ($faviconUrl)
                        <img src="{{ $faviconUrl }}" alt="" class="mt-2 h-12 w-12 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200">
                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="remove_favicon" value="1"
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            {{ __('Remove and use the default') }}
                        </label>
                    @endif
                    <input id="favicon" name="favicon" type="file" accept=".png,.jpg,.jpeg,.ico,.webp,image/png,image/jpeg,image/webp"
                           class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="mt-1 text-xs text-gray-500">{{ __('Up to 512 KB. Square works best.') }}</p>
                    <x-input-error :messages="$errors->get('favicon')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="og_image" :value="__('Open Graph Image')" />
                    @if ($ogImageUrl)
                        <img src="{{ $ogImageUrl }}" alt="" class="mt-2 h-28 w-full rounded-lg bg-gray-50 object-cover ring-1 ring-gray-200">
                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="remove_og_image" value="1"
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            {{ __('Remove and use the default') }}
                        </label>
                    @endif
                    <input id="og_image" name="og_image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                           class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="mt-1 text-xs text-gray-500">{{ __('Up to 2 MB. 1200×630 is the recommended share size.') }}</p>
                    <x-input-error :messages="$errors->get('og_image')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="twitter_image" :value="__('X / Twitter Image')" />
                    @if ($twitterImageUrl)
                        <img src="{{ $twitterImageUrl }}" alt="" class="mt-2 h-28 w-full rounded-lg bg-gray-50 object-cover ring-1 ring-gray-200">
                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="remove_twitter_image" value="1"
                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            {{ __('Remove and use the default') }}
                        </label>
                    @endif
                    <input id="twitter_image" name="twitter_image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                           class="mt-2 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="mt-1 text-xs text-gray-500">{{ __('Leave empty to reuse the Open Graph image.') }}</p>
                    <x-input-error :messages="$errors->get('twitter_image')" class="mt-1" />
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------- Twitter / X --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('X / Twitter Card') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('How a shared link looks on X. Leave blank to reuse the Open Graph text.') }}
            </p>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <x-input-label for="twitter_card_type" :value="__('Card Type')" />
                    <select id="twitter_card_type" name="twitter_card_type"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('Use the default (summary_large_image)') }}</option>
                        @foreach (['summary_large_image', 'summary'] as $card)
                            <option value="{{ $card }}" @selected(old('twitter_card_type', $settings->twitter_card_type) === $card)>
                                {{ $card === 'summary_large_image' ? __('Large image') : __('Small image') }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label for="twitter_handle" :value="__('X / Twitter Username')" />
                    <x-text-input id="twitter_handle" name="twitter_handle" type="text" class="mt-1 block w-full"
                                  :value="old('twitter_handle', $settings->twitter_handle)"
                                  :placeholder="config('seo.twitter_handle')" />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Only set this once the account really exists, otherwise the card is attributed to whoever owns that name.') }}
                    </p>
                    <x-input-error :messages="$errors->get('twitter_handle')" class="mt-1" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="twitter_title" :value="__('X / Twitter Title')" />
                    <x-text-input id="twitter_title" name="twitter_title" type="text" class="mt-1 block w-full"
                                  :value="old('twitter_title', $settings->twitter_title)" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="twitter_description" :value="__('X / Twitter Description')" />
                    <textarea id="twitter_description" name="twitter_description" rows="2" maxlength="1000"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('twitter_description', $settings->twitter_description) }}</textarea>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------ Robots --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Robots / Indexing') }}</h3>

            <label class="mt-4 flex items-start gap-3">
                <input type="checkbox" name="allow_indexing" value="1"
                       @checked(old('allow_indexing', $settings->allow_indexing))
                       class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">{{ __('Allow search engines to index this site') }}</span>
                    <span class="mt-1 block text-sm text-gray-500">
                        {{ __('When off, every page is served noindex. Individual pages are still gated by the route allow list, so turning this on never publishes a workspace, dashboard or admin page.') }}
                    </span>
                </span>
            </label>
        </section>

        {{-- The save button owns its own loading state through the shared Alpine
             submit-button behaviour: a settings page that looks frozen for a second after
             an upload is the usual reason people click Save twice. --}}
        <div class="flex justify-end">
            <x-primary-button type="submit">{{ __('Save changes') }}</x-primary-button>
        </div>
    </form>
</x-admin-shell>