<?php
declare(strict_types=1);

/*
 * SIX-ROLE RBAC MODEL — single source of truth.
 *
 * Keys are kept stable so users.role ENUM needs no risky migration:
 *   SuperAdmin    = Admin (full access; only role that manages accounts)
 *   Head Coach    = Head Coach (oversight of members / trainings / events)
 *   Coach/Trainer = Coach (own trainees / own sessions only)
 *   Officer       = Finance (funds + finance reporting; read-only elsewhere)
 *   Member        = Member / Player (own data only)
 *   Applicant     = Applicant (own registration only)
 *
 * Committee / Board Member are RETIRED. Any legacy rows resolve to `false`
 * (fail closed -> 403 + empty nav). Run the SQL below once:
 *   UPDATE users SET role='Member' WHERE role IN ('Committee','Board Member');
 *
 * SECURITY NOTES
 * - `hasPermission()` only answers "is this module granted at all". A scope
 *   string ('own', 'own_trainees', 'own_events') is NOT a full grant: every
 *   page/action must resolve it against the record. See includes/authorize.php
 *   canPerformAction() and the page-level guards.
 * - Only SuperAdmin holds users.write / users.delete === true, so only Admin
 *   can create accounts, change roles, deactivate/archive, or approve
 *   registration requests.
 * - Officer is deliberately limited to finance operations. Finance may READ
 *   members/trainings/events to reconcile payments, but may not create,
 *   edit, void, or take attendance.
 * - Reports and funds are visible to SuperAdmin + Officer only.
 * - Members/Applicants never receive the member directory (members.read=false);
 *   they reach their own profile through member_profile.php's self-service
 *   branch, which is authorized by users.member_id.
 */
return [
    'members' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => 'own_trainees', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Member' => ['read' => false, 'write' => 'own', 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'trainings' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => 'own', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => true, 'write' => 'own', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'attendance' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Officer' => ['read' => false, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => 'own', 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'events' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => 'own', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own', 'write' => 'own', 'delete' => false],
        'Member' => ['read' => true, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'event_participants' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'event_documents' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_events', 'write' => 'own_events', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'achievements' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Officer' => ['read' => false, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => 'own_trainees', 'write' => 'own_trainees', 'delete' => false],
        'Member' => ['read' => 'own', 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'funds' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => false, 'write' => false, 'delete' => false],
        'Officer' => ['read' => true, 'write' => true, 'delete' => true],
        'Coach/Trainer' => ['read' => false, 'write' => false, 'delete' => false],
        'Member' => ['read' => false, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'users' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => false, 'write' => false, 'delete' => false],
        'Officer' => ['read' => false, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => false, 'write' => false, 'delete' => false],
        'Member' => ['read' => false, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'reports' => [
        'SuperAdmin' => ['read' => true, 'write' => false, 'delete' => false],
        'Head Coach' => ['read' => false, 'write' => false, 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => false, 'write' => false, 'delete' => false],
        'Member' => ['read' => false, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
    'announcements' => [
        'SuperAdmin' => ['read' => true, 'write' => true, 'delete' => true],
        'Head Coach' => ['read' => true, 'write' => true, 'delete' => false],
        'Officer' => ['read' => true, 'write' => false, 'delete' => false],
        'Coach/Trainer' => ['read' => true, 'write' => false, 'delete' => false],
        'Member' => ['read' => true, 'write' => false, 'delete' => false],
        'Applicant' => ['read' => false, 'write' => false, 'delete' => false],
    ],
];
