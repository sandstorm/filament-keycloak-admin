{{-- Rendered directly by KeycloakLoadErrorRenderer / SsoAuthErrorRenderer (not a Livewire component's own
     view) — the panel's own layout, so the topbar/sidebar render exactly as they normally would, with
     one generic notice as the content. --}}

<x-filament-panels::layout.index>
    <x-filament::section
        icon="heroicon-o-exclamation-triangle"
        icon-color="danger"
        class="my-4"
        :heading="$heading"
    >
        {{ $message }}
    </x-filament::section>
</x-filament-panels::layout.index>
