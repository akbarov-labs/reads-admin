@php
    $library = $this->getLibrary();
@endphp

<x-filament-widgets::widget class="fi-library-profile-widget">
    <x-filament::section>
        <div class="flex flex-col gap-y-4 sm:flex-row sm:items-start sm:justify-between sm:gap-x-6">
            <div class="flex items-start gap-x-4">
                @if ($library->photo_url)
                    <x-filament::avatar
                        :src="$library->photo_url"
                        :alt="$library->name"
                        size="h-16 w-16"
                    />
                @else
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-building-library" class="h-8 w-8 text-gray-400" />
                    </div>
                @endif

                <div>
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ $library->name }}
                    </h2>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ collect([$library->district, $library->region])->filter()->implode(', ') }}
                    </p>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <x-filament::badge color="gray">
                            {{ $library->holdings_count }} {{ \Illuminate\Support\Str::plural('book', $library->holdings_count) }}
                        </x-filament::badge>

                        @if ($library->lent_out_count > 0)
                            <x-filament::badge color="warning">
                                {{ $library->lent_out_count }} with every copy lent out
                            </x-filament::badge>
                        @endif

                        <x-filament::badge :color="$library->is_published ? 'success' : 'gray'">
                            {{ $library->is_published ? 'On the site' : 'Not on the site yet' }}
                        </x-filament::badge>
                    </div>

                    <p class="mt-3 text-sm {{ $this->isStale() ? 'text-warning-600 dark:text-warning-400' : 'text-gray-600 dark:text-gray-300' }}">
                        @if ($library->holdings_updated_at)
                            List last updated {{ $library->holdings_updated_at->diffForHumans() }}.
                        @else
                            No books on the list yet.
                        @endif

                        @if ($this->isStale())
                            Readers are told it may be out of date, so please check it.
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                <x-filament::button
                    :href="$this->getEditUrl()"
                    tag="a"
                    icon="heroicon-m-book-open"
                >
                    My books
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
