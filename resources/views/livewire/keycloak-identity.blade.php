{{-- The Identity section — read fields (infolist) plus the write surface: a live enable/disable
     toggle and the Edit action. See KeycloakUserIdentity. --}}
<div class="space-y-4">
    {{ $this->identityInfolist }}

    {{ $this->enabledForm }}

    <x-filament-actions::modals />
</div>
