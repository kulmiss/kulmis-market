<?php

namespace App\Badges;

/**
 * The rules a badge can use. Super Admin picks one per badge and sets the
 * threshold. "lte" rules are positions (1 = first), the rest are counts.
 */
final class BadgeRules
{
    /** @var array<string, array{label:string, unit:string, compare:string, hint:string}> */
    public const RULES = [
        'active_level1' => ['label' => 'Team size (active members you invited)', 'unit' => 'people', 'compare' => 'gte', 'hint' => 'active members to'],
        'total_team' => ['label' => 'Total team (active members, all 3 levels)', 'unit' => 'people', 'compare' => 'gte', 'hint' => 'active team members to'],
        'loyalty_months' => ['label' => 'Loyalty streak (months of membership in a row)', 'unit' => 'months', 'compare' => 'gte', 'hint' => 'months in a row to'],
        'link_sales' => ['label' => 'Sales through my link (paid orders)', 'unit' => 'orders', 'compare' => 'gte', 'hint' => 'sales through your link to'],
        'earnings_total' => ['label' => 'Total team earnings (after money given back)', 'unit' => 'money', 'compare' => 'gte', 'hint' => 'earned to'],
        'top_referrer_month' => ['label' => 'Top referrer of a month (rank or better)', 'unit' => 'rank', 'compare' => 'lte', 'hint' => 'Be in the monthly top'],
        'early_member' => ['label' => 'Early supporter (among the first N members)', 'unit' => 'position', 'compare' => 'lte', 'hint' => 'One of the first'],
        'orders_count' => ['label' => 'Shopper (paid orders)', 'unit' => 'orders', 'compare' => 'gte', 'hint' => 'orders to'],
    ];

    public static function exists(string $rule): bool
    {
        return isset(self::RULES[$rule]);
    }

    public static function lowerIsBetter(string $rule): bool
    {
        return (self::RULES[$rule]['compare'] ?? 'gte') === 'lte';
    }
}
