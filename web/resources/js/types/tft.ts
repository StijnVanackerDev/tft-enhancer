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
    // Set when this match's lobby has already been analysed.
    lobbyAnalysisId?: string | null;
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

export type PlaystyleTag = {
    label: string;
    description: string;
};

export type PlaystyleComp = {
    key: string;
    label: string;
    icon: string | null;
    games: number;
    avgPlacement: number;
};

export type Playstyle = {
    games: number;
    avgPlacement: number | null;
    top4Rate: number | null;
    avgLevel: number | null;
    level9Rate: number | null;
    levelTempo: number | null;
    rerollRate: number | null;
    highCostRate: number | null;
    flexibility: number | null;
    tags: PlaystyleTag[];
    comps: PlaystyleComp[];
};

export type ActiveGame =
    | { status: 'none' }
    | { status: 'unavailable'; reason: string }
    | {
          status: 'in_game';
          gameLength: number;
          players: { riotId: string; isSubject: boolean }[];
      };

export type CompUnit = {
    id: string;
    name: string;
    cost: number;
    icon: string | null;
    share: number;
};

export type MetaComp = {
    key: string;
    label: string;
    icon: string | null;
    carryCost: number;
    games: number;
    share: number;
    avgPlacement: number;
    top4Rate: number;
    units: CompUnit[];
};

export type LikelyComp = {
    key: string;
    label: string;
    icon: string | null;
    probability: number;
    playedBefore: number;
};

export type LobbyPlayer = {
    puuid: string;
    gameName: string | null;
    tagLine: string | null;
    isSubject: boolean;
    rank: { tier: string | null; division: string | null; lp: number } | null;
    playstyle: Playstyle;
    historyWeight: number;
    likelyComps: LikelyComp[];
    actual: {
        key: string;
        label: string;
        icon: string | null;
        placement: number;
    } | null;
};

export type ContestedChampion = {
    id: string;
    name: string;
    cost: number;
    icon: string | null;
    expectedPlayers: number;
    usualPlayers: number;
    players: { name: string; weight: number }[];
};

export type OpenComp = {
    key: string;
    label: string;
    icon: string | null;
    games: number;
    avgPlacement: number;
    top4Rate: number;
    unitContest: number;
    usualContest: number;
    units: CompUnit[];
};

export type LobbyResult = {
    set: number | null;
    metaGames: number;
    players: LobbyPlayer[];
    contested: ContestedChampion[];
    openComps: OpenComp[];
};

export type LobbyAnalysis = {
    id: string;
    source: 'match' | 'live';
    sourceId: string;
    status: 'queued' | 'running' | 'waiting' | 'done' | 'failed';
    progressDone: number;
    progressTotal: number;
    waitingUntil: string | null;
    message: string | null;
    createdAt: string | null;
    player: {
        gameName: string;
        tagLine: string;
        platform: string;
        slug: string;
    };
    result: LobbyResult | null;
};
