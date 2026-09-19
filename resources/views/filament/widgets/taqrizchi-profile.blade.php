@php
    $taqrizchi = $this->getTaqrizchi();
    $pending = $this->getPendingCount();
    $published = $this->getPublishedCount();
@endphp

<x-filament-widgets::widget class="fi-taqrizchi-profile-widget">
    <x-filament::section>
        <div class="flex flex-col gap-y-4 sm:flex-row sm:items-start sm:justify-between sm:gap-x-6">
            <div class="flex items-start gap-x-4">
                <x-filament::avatar
                    :src="$taqrizchi->avatar_url"
                    :alt="$taqrizchi->name"
                    size="h-16 w-16"
                />

                <div>
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ $taqrizchi->name }}
                    </h2>

                    @if ($taqrizchi->location)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $taqrizchi->location }}
                        </p>
                    @endif

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <x-filament::badge color="success">
                            {{ $published }} published
                        </x-filament::badge>

                        @if ($pending > 0)
                            <x-filament::badge color="warning">
                                {{ $pending }} awaiting review
                            </x-filament::badge>
                        @endif
                    </div>

                    @if ($taqrizchi->bio)
                        <p class="mt-3 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                            {{ \Illuminate\Support\Str::limit($taqrizchi->bio, 220) }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                <x-filament::button
                    :href="$this->getWriteUrl()"
                    tag="a"
                    color="primary"
                    icon="heroicon-m-pencil"
                >
                    Write a taqriz
                </x-filament::button>

                <x-filament::button
                    :href="$this->getTaqrizlarUrl()"
                    tag="a"
                    color="gray"
                    icon="heroicon-m-star"
                >
                    My taqrizlar
                </x-filament::button>

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
