<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament;

use Illuminate\Database\Eloquent\Model;

/**
 * A table-less Eloquent model whose only job is to satisfy Filament's record contract — Filament's
 * custom-data tables accept `array | Model` and identify rows via `getKey()`, so a plain readonly DTO
 * cannot be a record. This wrapper carries the real Keycloak DTO across that boundary: Filament sees a
 * Model, while every column/action reads the typed domain object back via {@see self::dto()}.
 *
 * Consumers narrow the type at the use site — `$dto = $record->dto(); assert($dto instanceof KeycloakX)`
 * — which gives a runtime guard *and* static (PHPStan) narrowing, since PHP has no generic return types.
 *
 * It is never queried or saved — constructed fresh from live adapter data on every `records()` call, so
 * the absence of a backing table/connection is irrelevant.
 */
final class KeycloakRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'id';

    protected $guarded = [];

    /**
     * The wrapped domain object. Declared (not a magic attribute) so Eloquent's `__set` does not treat
     * it as a database column.
     */
    private object $keycloakDto;

    public static function for(string $key, object $dto): self
    {
        $record = new self();
        $record->setAttribute('id', $key);
        $record->keycloakDto = $dto;

        return $record;
    }

    public function dto(): object
    {
        return $this->keycloakDto;
    }
}
