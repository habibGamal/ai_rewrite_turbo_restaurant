<x-filament-panels::page>
    <form wire:submit="finish">
        {{ $this->form }}
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
