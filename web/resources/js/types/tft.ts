export type PlatformOption = {
    value: string;
    label: string;
};

export type Rank = {
    tier: string | null;
    division: string | null;
    lp: number;
    wins: number;
    losses: number;
};

export type Player = {
    id: number;
    gameName: string;
    tagLine: string;
    platform: string;
    platformLabel: string;
    slug: string;
    rank: Rank | null;
    syncedAt: string | null;
    isSyncing: boolean;
    syncError: string | null;
};

export type Summary = {
    games: number;
    avgPlacement: number | null;
    top4Rate: number | null;
    winRate: number | null;
    avgLevel: number | null;
    placements: number[];
};

export type Item = {
    id: string;
    name: string;
    icon: string | null;
};

export type Unit = {
    id: string;
    name: string;
    cost: number;
    icon: string | null;
    stars: number;
    items: Item[];
};

export type Trait = {
    id: string;
    name: string;
    icon: string | null;
    units: number;
    style: number;
};

export type Game = {
    id: string;
    playedAt: string;
    duration: number;
    queue: string;
    patch: string | null;
    placement: number;
    level: number;
    lastRound: number;
    damage: number;
    traits: Trait[];
    units: Unit[];
};

export type StatRow = {
    id: string;
    name: string;
    icon: string | null;
    cost?: number;
    games: number;
    avgPlacement: number;
    top4Rate: number;
    avgStars?: number;
};
