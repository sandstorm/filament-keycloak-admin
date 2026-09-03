{{-- Rendered directly by KeycloakLoadErrorRenderer (not a Livewire component's own view) — the panel's
     layout, wrapping one generic notice instead of whichever page/table was mid-render. --}}
<x-filament-panels::layout.index>
    <x-filament::section
        icon="heroicon-o-exclamation-triangle"
        icon-color="danger"
        :heading="__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading')"
    >
        {{ $message }}
    </x-filament::section>
</x-filament-panels::layout.index>
