<?php

namespace App\Models;

use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to load and analyse the history of everyone in one lobby.
 *
 * @property string $id
 * @property int $player_id
 * @property Platform $platform
 * @property string $source
 * @property string $source_id
 * @property list<array{puuid: ?string, gameName: ?string, tagLine: ?string}> $participants Manual lobbies start without puuids; the job looks them up.
 * @property CarbonImmutable|null $history_before
 * @property int|null $set_number
 * @property string $status
 * @property int $progress_done
 * @property int $progress_total
 * @property CarbonImmutable|null $waiting_until
 * @property string|null $message
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Player $player
 */
#[Fillable([
    'player_id', 'platform', 'source', 'source_id', 'participants', 'history_before', 'set_number',
    'status', 'progress_done', 'progress_total', 'waiting_until', 'message', 'result',
])]
class LobbyAnalysis extends Model
{
    use HasUuids;

    public const SOURCE_MATCH = 'match';

    public const SOURCE_LIVE = 'live';

    /** Riot IDs entered by hand, for when live game lookups aren't available. */
    public const SOURCE_MANUAL = 'manual';

    /** Opponents that can be entered for a manual lobby. */
    public const MAX_MANUAL_OPPONENTS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'participants' => 'array',
            'history_before' => 'datetime',
            'waiting_until' => 'datetime',
            'result' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    /**
     * Mark one unit of work done and show what's happening now.
     */
    public function advance(string $message): void
    {
        $this->progress_done++;
        $this->progress_total = max($this->progress_total, $this->progress_done);
        $this->status = 'running';
        $this->waiting_until = null;
        $this->message = $message;
        $this->save();
    }
}
