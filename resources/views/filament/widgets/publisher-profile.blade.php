@php
    $publisher = $this->getPublisher();
@endphp

<x-filament-widgets::widget class="fi-publisher-profile-widget">
    <x-filament::section>
        <div class="flex flex-col gap-y-4 sm:flex-row sm:items-start sm:justify-between sm:gap-x-6">
            <div class="flex items-start gap-x-4">
                <x-filament::avatar
                    :src="$publisher->logo_url"
                    :alt="$publisher->name"
                    size="h-16 w-16"
                />

                <div>
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ $publisher->name }}
                    </h2>

                    @if ($publisher->country)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $publisher->country }}
                        </p>
                    @endif

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <x-filament::badge color="gray">
                            {{ $publisher->editions_count }} {{ \Illuminate\Support\Str::plural('edition', $publisher->editions_count) }}
                        </x-filament::badge>
                    </div>

                    @if ($publisher->bio)
                        <p class="mt-3 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                            {{ \Illuminate\Support\Str::limit($publisher->bio, 220) }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                <x-filament::button
                    :href="$this->getEditUrl()"
                    tag="a"
                    color="gray"
                    icon="heroicon-m-pencil-square"
                >
                    Edit my profile
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
