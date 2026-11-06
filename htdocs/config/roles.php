<?php
declare(strict_types=1);

/*
 * Canonical SIX-ROLE model.
 *
 * Code keys are kept stable so the users.role column and existing rows need
 * no risky rename: the six *displayed* roles map onto these keys as follows:
 *
 *   SuperAdmin     -> Admin
 *   Head Coach     -> Head Coach
 *   Coach/Trainer  -> Coach
 *   Officer        -> Finance
 *   Member         -> Member
 *   Applicant      -> Applicant
 *
 * Committee / Board Member are retired (see permissions.php); any legacy rows
 * must be moved to Member via SQL. config/permissions.php is the single source
 * of truth for what each key may do.
 */
return [
    'SuperAdmin' => [
        'label' => 'Admin',
    ],
    'Head Coach' => [
        'label' => 'Head Coach',
    ],
    'Coach/Trainer' => [
        'label' => 'Coach',
    ],
    'Officer' => [
        'label' => 'Finance',
    ],
    'Member' => [
        'label' => 'Member',
    ],
    'Applicant' => [
        'label' => 'Applicant',
    ],
];
