<?php

namespace App\Models;

use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $puuid
 * @property Platform $platform
 * @property string $game_name
 * @property string $tag_line
 * @property list<array<string, mixed>>|null $league
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $sync_started_at
 * @property string|null $sync_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $riot_id
 * @property-read string $slug
 */
#[Fillable(['puuid', 'platform', 'game_name', 'tag_line', 'league', 'synced_at', 'sync_started_at', 'sync_error'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /** A sync that hasn't finished after this long is considered dead. */
    public const SYNC_TIMEOUT_MINUTES = 5;

    /** Minimum time between two syncs of the same player. */
    public const SYNC_COOLDOWN_MINUTES = 2;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'league' => 'array',
            'synced_at' => 'datetime',
            'sync_started_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(Participant::class, 'puuid', 'puuid');
    }

    public function getRiotIdAttribute(): string
    {
        return "{$this->game_name}#{$this->tag_line}";
    }

    /**
     * URL-friendly Riot ID: "Name-TAG". Tag lines never contain a dash.
     */
    public function getSlugAttribute(): string
    {
        return "{$this->game_name}-{$this->tag_line}";
    }

    public function isSyncing(): bool
    {
        return $this->sync_started_at?->isAfter(now()->subMinutes(self::SYNC_TIMEOUT_MINUTES)) ?? false;
    }

    public function needsSync(): bool
    {
        return ! $this->isSyncing()
            && ($this->synced_at === null || $this->synced_at->isBefore(now()->subMinutes(self::SYNC_COOLDOWN_MINUTES)));
    }

    /**
     * Atomically claim the sync, so two requests never sync the same player at once.
     */
    public function claimSync(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where(fn ($q) => $q
                ->whereNull('sync_started_at')
                ->orWhere('sync_started_at', '<', now()->subMinutes(self::SYNC_TIMEOUT_MINUTES)))
            ->update(['sync_started_at' => now()]);

        if ($claimed) {
            $this->refresh();
        }

        return $claimed === 1;
    }
}
