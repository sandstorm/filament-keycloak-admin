{{-- Rendered directly by KeycloakLoadErrorRenderer / SsoAuthErrorRenderer (not a Livewire component's own
     view) — the panel's own layout, so the topbar/sidebar render exactly as they normally would, with
     one generic notice as the content. Filament only reveals `.fi-main-ctn` once Alpine runs a JS-driven
     opacity toggle (`x-bind:style="'opacity:1'"`, see `layout/index.blade.php`), which never happens when
     this view is rendered from inside Laravel's exception handler rather than a normal page request —
     that would otherwise leave the content area blank while the topbar/sidebar still show fine (those
     don't depend on Alpine to be visible). The style override below forces `.fi-main-ctn` visible
     regardless of whether that JS ever boots. --}}
@push('styles')
    <style>
        .fi-main-ctn {
            display: flex !important;
            opacity: 1 !important;
        }
    </style>
@endpush

<x-filament-panels::layout.index>
    <x-filament::section
        icon="heroicon-o-exclamation-triangle"
        icon-color="danger"
        :heading="$heading"
    >
        {{ $message }}
    </x-filament::section>
</x-filament-panels::layout.index>
