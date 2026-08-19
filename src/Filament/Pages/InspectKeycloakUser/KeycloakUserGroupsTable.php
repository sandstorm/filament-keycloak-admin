<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;
use Sandstorm\FilamentKeycloakAdmin\Filament\Helpers\KeycloakRecord;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi\Dto\KeycloakGroup;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function array_map;
use function assert;
use function in_array;
use function sprintf;
use function view;

/**
 * Groups section — the realm groups the user belongs to, as a table. The per-user membership list is
 * small, so it renders whole (no pagination). An "Add to group" header action (picker of groups the
 * user is not yet in) and a per-row "Remove" mutate membership one group per call, then re-read the
 * table and broadcast the shared cross-tab signal (plan §7.2). Every failure propagates (plan §8).
 */
final class KeycloakUserGroupsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $userId;

    protected KeycloakGroupsApi $groupsApi;

    public function boot(KeycloakGroupsApi $groupsApi): void
    {
        $this->groupsApi = $groupsApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
    }

    #[On('keycloak-user-changed')]
    public function refresh(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Group memberships')
            ->records(fn (): Collection => $this->loadGroups())
            ->columns([
                TextColumn::make('name')->state(fn (KeycloakRecord $record): string => self::dto($record)->name),
                TextColumn::make('path')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->path)->placeholder('—'),
            ])
            ->headerActions([
                $this->addGroupsAction(),
            ])
            ->recordActions([
                $this->removeGroupAction(),
            ])
            ->emptyStateHeading('This user is in no groups.');
    }

    /**
     * Add the user to one or more groups. The picker lists **only groups the user is not already in**
     * (labelled by human-readable path/name) — so the admin never re-picks existing memberships, and
     * adding is a single header action → select → confirm, not a read-then-edit round-trip.
     */
    private function addGroupsAction(): Action
    {
        return Action::make('addGroups')
            ->label('Add to group')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->schema([
                Select::make('groupIds')
                    ->label('Groups to add')
                    ->multiple()
                    ->required()
                    ->options(fn (): array => $this->addableGroupOptions())
                    ->native(false)
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                $userId = new KeycloakUserId($this->userId);
                foreach ($data['groupIds'] ?? [] as $groupId) {
                    $this->groupsApi->addUserToGroup($userId, $groupId);
                }

                $this->resetTable();
                $this->dispatch('keycloak-user-changed');

                Notification::make()->title('Added to group(s)')->success()->send();
            });
    }

    private function removeGroupAction(): Action
    {
        return Action::make('removeGroup')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Remove from group?')
            ->modalDescription(fn (KeycloakRecord $record): string => sprintf('Remove this user from "%s"?', self::dto($record)->path ?? self::dto($record)->name))
            ->modalSubmitActionLabel('Remove')
            ->action(function (KeycloakRecord $record): void {
                $this->groupsApi->removeUserFromGroup(new KeycloakUserId($this->userId), self::dto($record)->id);

                $this->resetTable();
                $this->dispatch('keycloak-user-changed');

                Notification::make()->title('Removed from group')->success()->send();
            });
    }

    /**
     * The ids of the groups the user currently belongs to.
     *
     * @return list<string>
     */
    private function currentGroupIds(): array
    {
        return array_map(
            static fn (KeycloakGroup $group): string => $group->id,
            $this->groupsApi->getUserGroups(new KeycloakUserId($this->userId))->all(),
        );
    }

    /**
     * Groups the user is **not** already in, as `id => label` options (label prefers the human-readable
     * hierarchical path, else the name — never the raw id).
     *
     * @return array<string, string>
     */
    private function addableGroupOptions(): array
    {
        $currentIds = $this->currentGroupIds();

        $options = [];
        foreach ($this->groupsApi->listRealmGroups() as $group) {
            if (! in_array($group->id, $currentIds, true)) {
                $options[$group->id] = $group->path ?? $group->name;
            }
        }

        return $options;
    }

    /**
     * @return Collection<int, KeycloakRecord>
     */
    private function loadGroups(): Collection
    {
        $groups = $this->groupsApi->getUserGroups(new KeycloakUserId($this->userId));

        return (new Collection($groups->all()))->map(static fn (KeycloakGroup $group, int $index): KeycloakRecord => KeycloakRecord::for((string) $index, $group));
    }

    private static function dto(KeycloakRecord $record): KeycloakGroup
    {
        $group = $record->dto();
        assert($group instanceof KeycloakGroup);

        return $group;
    }

    public function render(): View
    {
        return view('filament-keycloak-admin::livewire.keycloak-table');
    }
}
