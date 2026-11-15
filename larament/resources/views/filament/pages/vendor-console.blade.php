<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex gap-3 pt-6 border-t border-gray-200 dark:border-gray-700">
                @foreach ($this->getFormActions() as $action)
                    {{ $action }}
                @endforeach
            </div>
        </form>

        {{ $this->table }}
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
