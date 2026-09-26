// Full class names (not built dynamically) so Tailwind picks them up.
const costBorder: Record<number, string> = {
    1: 'border-cost-1',
    2: 'border-cost-2',
    3: 'border-cost-3',
    4: 'border-cost-4',
    5: 'border-cost-5',
};

const costText: Record<number, string> = {
    1: 'text-cost-1',
    2: 'text-cost-2',
    3: 'text-cost-3',
    4: 'text-cost-4',
    5: 'text-cost-5',
};

export function costBorderClass(cost: number): string {
    return costBorder[cost] ?? 'border-muted-foreground/40';
}

export function costTextClass(cost: number): string {
    return costText[cost] ?? 'text-muted-foreground';
}

export function placementClass(placement: number): string {
    if (placement === 1) {
        return 'bg-cost-5 text-black';
    }

    if (placement <= 4) {
        return 'bg-cost-3 text-white';
    }

    return 'bg-muted text-muted-foreground';
}

// Riot's trait "style": 1 bronze, 2 silver, 3 unique, 4 gold, 5 prismatic.
export function traitStyleClass(style: number): string {
    switch (style) {
        case 1:
            return 'bg-[#8c5a37] text-white';
        case 2:
            return 'bg-[#8b9aa6] text-black';
        case 3:
            return 'bg-[#d4553e] text-white';
        case 4:
            return 'bg-[#d8a93a] text-black';
        default:
            return 'bg-gradient-to-br from-[#9be7ff] via-[#e3b5ff] to-[#ffe38c] text-black';
    }
}

export function ordinal(n: number): string {
    const suffix = ['th', 'st', 'nd', 'rd'][n] ?? 'th';

    return `${n}${suffix}`;
}

export function formatDuration(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

// "3-2" style stage from Riot's last_round counter.
export function roundToStage(round: number): string {
    if (round <= 3) {
        return `1-${round}`;
    }

    const stage = Math.floor((round - 4) / 7) + 2;
    const sub = ((round - 4) % 7) + 1;

    return `${stage}-${sub}`;
}

export function timeAgo(iso: string): string {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (seconds >= size) {
            return format.format(-Math.floor(seconds / size), unit);
        }
    }

    return 'just now';
}

type RankLike = {
    tier: string | null;
    division: string | null;
    lp: number;
} | null;

export function rankLabel(rank: RankLike): string {
    if (!rank?.tier) {
        return 'Unranked';
    }

    const tier = rank.tier.charAt(0) + rank.tier.slice(1).toLowerCase();
    // Master and above have no division.
    const division = ['MASTER', 'GRANDMASTER', 'CHALLENGER'].includes(rank.tier)
        ? ''
        : ` ${rank.division}`;

    return `${tier}${division} · ${rank.lp} LP`;
}

export function percent(value: number | null, digits = 0): string {
    return value === null ? '–' : `${(value * 100).toFixed(digits)}%`;
}
