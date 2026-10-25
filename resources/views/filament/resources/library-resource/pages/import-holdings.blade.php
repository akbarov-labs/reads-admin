@php
    $import = $this->pendingImport();
    $counts = $import?->counts();
@endphp

<x-filament-panels::page>
    @if ($import === null)
        <x-filament::section>
            <x-slot name="heading">Upload your list</x-slot>
            <x-slot name="description">
                Every book is matched to kitoblarim.uz. You check the ones we are unsure of before anything changes on your list.
            </x-slot>

            <form wire:submit="read" class="grid gap-y-6">
                {{ $this->form }}

                <div class="flex flex-wrap gap-3">
                    <x-filament::button type="submit" icon="heroicon-m-arrow-up-tray">
                        Read the file
                    </x-filament::button>

                    {{ $this->downloadTemplateAction }}
                </div>
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span class="font-medium text-gray-950 dark:text-white">{{ $import->filename }}</span>
                <x-filament::badge color="success">{{ $counts['accepted'] }} will be added</x-filament::badge>
                @if ($counts['pending'] > 0)
                    <x-filament::badge color="warning">{{ $counts['pending'] }} to check</x-filament::badge>
                @endif
                @if ($counts['skipped'] > 0)
                    <x-filament::badge color="gray">{{ $counts['skipped'] }} skipped</x-filament::badge>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament-panels::page>
