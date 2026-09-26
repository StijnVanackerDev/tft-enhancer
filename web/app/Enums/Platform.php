<?php

namespace App\Enums;

/**
 * Riot "platform" routing values (the server a player plays on). Match and
 * account endpoints use the broader regional routing value instead.
 */
enum Platform: string
{
    case EUW = 'euw1';
    case EUNE = 'eun1';
    case TR = 'tr1';
    case ME = 'me1';
    case NA = 'na1';
    case BR = 'br1';
    case LAN = 'la1';
    case LAS = 'la2';
    case KR = 'kr';
    case JP = 'jp1';
    case OCE = 'oc1';
    case SG = 'sg2';
    case TW = 'tw2';
    case VN = 'vn2';

    public function region(): string
    {
        return match ($this) {
            self::EUW, self::EUNE, self::TR, self::ME => 'europe',
            self::NA, self::BR, self::LAN, self::LAS => 'americas',
            self::KR, self::JP => 'asia',
            self::OCE, self::SG, self::TW, self::VN => 'sea',
        };
    }

    public function label(): string
    {
        return $this->name;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $platform) => ['value' => $platform->value, 'label' => $platform->label()],
            self::cases(),
        );
    }
}
