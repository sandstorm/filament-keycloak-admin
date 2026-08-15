<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Livewire;

use Sandstorm\FilamentKeycloakAdmin\Filament\KeycloakRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi\Dto\KeycloakGroup;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function array_map;
use function assert;
use function in_array;
use function sprintf;
use function view;

/**
 * Groups tab — the realm groups the user belongs to, as a table (consistent with every other section).
 * The per-user membership list is small, so it is rendered whole (no pagination). The catchable auth
 * failure (§6.3) is handled by the `@keycloakboundary` wrapping the table in this component's view, so
 * `records()` simply lets it propagate; every other failure propagates past the boundary to the framework.
 *
 * An "Add to group" header action (picker of groups the user is not yet in) and a per-row "Remove"
 * action mutate membership directly, one group per call, then re-read the table (invariant #6). A failed
 * call is left to surface as an error — the admin simply retries; there is no partial-failure bookkeeping.
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

    public function table(Table $table): Table
    {
        return $table
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

                Notification::make()->title('Added to group(s)')->success()->send();
            });
    }

    /**
     * Remove the user from a single group directly from its row — one click + confirm, no edit modal.
     */
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
     * hierarchical path, else the name — never the raw id). Current memberships are excluded so the
     * add-picker only offers new groups.
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
        return view('keycloak-filament-admin::livewire.keycloak-user-groups-table');
    }
}
