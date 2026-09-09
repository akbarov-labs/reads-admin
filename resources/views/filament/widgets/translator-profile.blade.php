@php
    $translator = $this->getTranslator();
    $newInquiries = $this->getNewInquiriesCount();
@endphp

<x-filament-widgets::widget class="fi-translator-profile-widget">
    <x-filament::section>
        <div class="flex flex-col gap-y-4 sm:flex-row sm:items-start sm:justify-between sm:gap-x-6">
            <div class="flex items-start gap-x-4">
                <x-filament::avatar
                    :src="$translator->avatar_url"
                    :alt="$translator->name"
                    size="h-16 w-16"
                />

                <div>
                    <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        {{ $translator->name }}
                    </h2>

                    @if ($translator->location)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $translator->location }}
                        </p>
                    @endif

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <x-filament::badge color="gray">
                            {{ $translator->books_count }} {{ \Illuminate\Support\Str::plural('book', $translator->books_count) }}
                        </x-filament::badge>

                        <x-filament::badge color="gray">
                            {{ $translator->language_pairs_count }} {{ \Illuminate\Support\Str::plural('language pair', $translator->language_pairs_count) }}
                        </x-filament::badge>
                    </div>

                    @if ($translator->languagePairs->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($translator->languagePairs as $pair)
                                <x-filament::badge color="primary">
                                    {{ $pair->from_language }} &rarr; {{ $pair->to_language }}
                                </x-filament::badge>
                            @endforeach
                        </div>
                    @endif

                    @if ($translator->bio)
                        <p class="mt-3 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                            {{ \Illuminate\Support\Str::limit($translator->bio, 220) }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                <x-filament::button
                    :href="$this->getInquiriesUrl()"
                    tag="a"
                    :color="$newInquiries > 0 ? 'warning' : 'gray'"
                    icon="heroicon-m-envelope"
                    :badge="$newInquiries > 0 ? $newInquiries : null"
                >
                    {{ $newInquiries > 0 ? 'New inquiries' : 'Inquiries' }}
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
