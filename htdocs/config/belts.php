<?php
declare(strict_types=1);

/*
 * Belt progression helpers — shared between member_profile.php and
 * Members class. Mirrors Members::RANK_ORDER so there is a single source.
 */

const ARNIS_RANK_ORDER = [
    'None',
    'I. White Belt',
    'II. Yellow Belt',
    'III. Green Belt',
    'IV. Blue Belt',
    'V. Brown Belt',
    'VI. Black Belt',
    '1st Degree- Junior Instructor',
    '2nd Degree- Junior Instructor',
    '3rd Degree- Instructor',
    '4th Degree- Instructor',
    '5th Degree- Senior Instructor',
    '6th Degree- Senior Instructor',
    '7th Degree- Junior Master',
    '8th Degree- Master',
    '9th Degree- Senior Master',
    'VII. Red Belt',
    '10th Degree- Grandmaster',
    '11th Degree- Senior Grandmaster',
    '12th Degree- Superior Grandmaster',
];

/**
 * Returns the next rank after the given one, or null at the top.
 */
function arnisNextRank(string $rank): ?string
{
    $i = array_search($rank, ARNIS_RANK_ORDER, true);
    if ($i === false || $i >= count(ARNIS_RANK_ORDER) - 1) {
        return null;
    }
    return ARNIS_RANK_ORDER[$i + 1];
}

/**
 * Returns a 0-100 progress percentage through the entire belt ladder.
 * Used for the thin "overall progress" bar in the Promotion Readiness card.
 */
function arnisRankProgress(string $rank): int
{
    $i = array_search($rank, ARNIS_RANK_ORDER, true);
    if ($i === false) {
        return 0;
    }
    return (int)round(($i / max(1, count(ARNIS_RANK_ORDER) - 1)) * 100);
}