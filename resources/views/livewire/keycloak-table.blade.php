{{-- Shared by every detail-tab table component (groups, credentials, sessions, both event logs) —
     each binds a single Filament table, so one blade renders them all. --}}
<div>
    {{ $this->table }}
</div>
