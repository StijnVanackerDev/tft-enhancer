<?php

namespace App\Services\Riot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the game in progress from the Riot client running on this PC, via
 * its local API (the "LCU" API the client itself uses). The client writes
 * its port and password to a lockfile while it runs. This only works when
 * the site runs on the same PC as the game, so it's a local-only feature.
 */
class LeagueClient
{
    /** Phases in which the session holds the players of a running game. */
    private const IN_GAME_PHASES = ['GameStart', 'InProgress', 'Reconnect'];

    /**
     * @param  list<string>  $lockfiles  Candidate lockfile paths, first match wins (newest first).
     */
    public function __construct(private readonly array $lockfiles) {}

    /**
     * @return array{gameId: string, self: array{puuid: string, gameName: ?string, tagLine: ?string}, players: list<array{puuid: string, gameName: ?string, tagLine: ?string}>}
     */
    public function currentGame(): array
    {
        $session = $this->get('/lol-gameflow/v1/session');
        $phase = is_string($session['phase'] ?? null) ? $session['phase'] : 'None';

        if (! in_array($phase, self::IN_GAME_PHASES, true)) {
            throw new RuntimeException("You're not in a game right now (client status: {$phase}).");
        }

        $gameData = is_array($session['gameData'] ?? null) ? $session['gameData'] : [];
        $players = [];

        foreach (['teamOne', 'teamTwo'] as $team) {
            foreach ((array) ($gameData[$team] ?? []) as $entry) {
                if (! is_array($entry) || ! is_string($entry['puuid'] ?? null) || $entry['puuid'] === '') {
                    continue;
                }

                $players[$entry['puuid']] = [
                    'puuid' => $entry['puuid'],
                    'gameName' => self::string($entry, 'gameName'),
                    'tagLine' => self::string($entry, 'tagLine'),
                ];
            }
        }

        if ($players === []) {
            throw new RuntimeException("The client didn't return the players of this game.");
        }

        $me = $this->get('/lol-summoner/v1/current-summoner');

        return [
            'gameId' => (string) ($gameData['gameId'] ?? md5(implode('|', array_keys($players)))),
            'self' => [
                'puuid' => (string) ($me['puuid'] ?? ''),
                'gameName' => self::string($me, 'gameName'),
                'tagLine' => self::string($me, 'tagLine'),
            ],
            'players' => array_values($players),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path): array
    {
        [$port, $password] = $this->connection();

        try {
            $response = Http::withBasicAuth('riot', $password)
                // The client uses a self-signed certificate on localhost.
                ->withOptions(['verify' => false])
                ->acceptJson()
                ->timeout(5)
                ->get("https://127.0.0.1:{$port}{$path}");
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn't reach the Riot client. Is it running?");
        }

        if ($response->failed()) {
            throw new RuntimeException("The Riot client returned HTTP {$response->status()} for {$path}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Port and password from the most recently written lockfile
     * ("name:pid:port:password:protocol").
     *
     * @return array{int, string}
     */
    private function connection(): array
    {
        $existing = array_filter($this->lockfiles, fn (string $path) => is_file($path));
        usort($existing, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        foreach ($existing as $path) {
            $parts = explode(':', trim((string) @file_get_contents($path)));

            if (count($parts) >= 4 && is_numeric($parts[2]) && $parts[3] !== '') {
                return [(int) $parts[2], $parts[3]];
            }
        }

        throw new RuntimeException('The Riot client is not running on this PC (no lockfile found).');
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
    }
}
