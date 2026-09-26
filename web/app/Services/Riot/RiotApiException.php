<?php

namespace App\Services\Riot;

use RuntimeException;

class RiotApiException extends RuntimeException
{
    public static function fromStatus(int $status, string $path): self
    {
        $reason = match ($status) {
            401, 403 => 'the Riot API key is missing, invalid or expired',
            429 => 'the Riot API rate limit was hit',
            default => "Riot API returned HTTP {$status}",
        };

        return new self("Request to {$path} failed: {$reason}.", $status);
    }
}
