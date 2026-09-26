<?php

namespace App\Models;

use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $match_id
 * @property Platform $platform
 * @property CarbonImmutable $played_at
 * @property int $game_length
 * @property string $game_version
 * @property int|null $queue_id
 * @property int|null $set_number
 * @property string|null $game_type
 * @property string|null $sample_tier Rank of the player through whom we found this match.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['match_id', 'platform', 'played_at', 'game_length', 'game_version', 'queue_id', 'set_number', 'game_type', 'sample_tier'])]
class TftMatch extends Model
{
    /** Riot gives every bot in a practice game this puuid. */
    public const BOT_PUUID = 'BOT';

    /** Our own game_type for matches that had bots in them. */
    public const GAME_TYPE_BOTS = 'bots';

    /**
     * Matches against real players only (practice games vs bots say nothing
     * about how someone plays).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function againstPlayers(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('game_type')
            ->orWhere('game_type', '!=', self::GAME_TYPE_BOTS));
    }

    public function isAgainstBots(): bool
    {
        return $this->game_type === self::GAME_TYPE_BOTS;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'played_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * "Version 16.19.123.4567 (Sep 12 2026/...)" -> "16.19".
     */
    public function patch(): ?string
    {
        return preg_match('/(\d+\.\d+)/', $this->game_version, $m) ? $m[1] : null;
    }

    /**
     * Human readable queue name for the queue ids TFT uses.
     */
    public function queueName(): string
    {
        return match (true) {
            $this->isAgainstBots() => 'vs. Bots',
            $this->game_type === 'pairs' => 'Double Up',
            $this->game_type === 'turbo' => 'Hyper Roll',
            $this->queue_id === 1100 => 'Ranked',
            $this->queue_id === 1090 => 'Normal',
            default => 'Other',
        };
    }
}
