<?php

declare(strict_types=1);

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/Members.php';
require_once __DIR__ . '/classes/Trainings.php';
require_once __DIR__ . '/classes/Events.php';
require_once __DIR__ . '/classes/Funds.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function runTest(string $name, callable $callback): array
{
    try {
        $result = $callback();

        return [
            'name' => $name,
            'status' => 'OK',
            'detail' => is_string($result)
                ? $result
                : 'Completed successfully.'
        ];
    } catch (Throwable $e) {
        error_log(
            'Arnis Connect dashboard diagnostic - ' .
            $name .
            ': ' .
            $e->getMessage()
        );

        return [
            'name' => $name,
            'status' => 'FAIL',
            'detail' => get_class($e) . ': ' . $e->getMessage()
        ];
    }
}

$results = [];
$db = null;

/*
|--------------------------------------------------------------------------
| Basic PHP / PDO checks
|--------------------------------------------------------------------------
*/

$results[] = runTest('PHP runtime', function (): string {
    return PHP_VERSION;
});

$results[] = runTest('PDO extension', function (): string {
    if (!extension_loaded('pdo')) {
        throw new RuntimeException('PDO extension is not loaded.');
    }

    return 'PDO extension is available.';
});

$results[] = runTest('PDO MySQL driver', function (): string {
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('pdo_mysql extension is not loaded.');
    }

    return 'pdo_mysql is available.';
});

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$results[] = runTest('Database connection', function () use (&$db): string {
    $db = (new Database())->connect();

    if (!$db instanceof PDO) {
        throw new RuntimeException('Database::connect() did not return a PDO instance.');
    }

    return 'PDO connection succeeded.';
});

if ($db instanceof PDO) {

    /*
    |--------------------------------------------------------------------------
    | Database information
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Database version', function () use ($db): string {
        return (string) $db->query('SELECT VERSION()')->fetchColumn();
    });

    $results[] = runTest('Selected database', function () use ($db): string {
        return (string) $db->query('SELECT DATABASE()')->fetchColumn();
    });

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Users table', function () use ($db): string {
        $tableExists = (int) $db->query("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
        ")->fetchColumn();

        if ($tableExists !== 1) {
            throw new RuntimeException('The users table does not exist.');
        }

        return 'users table exists.';
    });

    $results[] = runTest('Users required columns', function () use ($db): string {
        $required = [
            'user_id',
            'full_name',
            'username',
            'email',
            'password_hash',
            'member_id',
            'email_verified_at',
            'mobile_verified_at',
            'last_login_at',
            'session_version',
            'role',
            'is_active',
            'created_at',
            'updated_at'
        ];

        $stmt = $db->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
            ORDER BY ORDINAL_POSITION
        ");

        $actual = array_map(
            static fn($value): string => (string) $value,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $missing = array_values(array_diff($required, $actual));

        if ($missing) {
            throw new RuntimeException(
                'Missing users columns: ' .
                json_encode(
                    $missing,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        return 'All required users columns are present.';
    });

    $results[] = runTest('SuperAdmin user lookup', function () use ($db): string {
        $stmt = $db->prepare("
            SELECT
                user_id,
                username,
                email,
                password_hash,
                role,
                is_active,
                session_version
            FROM users
            WHERE username = :username
            LIMIT 1
        ");

        $stmt->execute([
            ':username' => 'superadmin'
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new RuntimeException(
                'The superadmin account was not found.'
            );
        }

        return sprintf(
            'Found user_id=%d, username=%s, role=%s, is_active=%d, session_version=%d.',
            (int) $user['user_id'],
            (string) $user['username'],
            (string) $user['role'],
            (int) $user['is_active'],
            (int) $user['session_version']
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Members
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Members table and Members::getAll()', function () use ($db): string {
        $count = (int) $db->query(
            'SELECT COUNT(*) FROM members'
        )->fetchColumn();

        $rows = (new Members($db))->getAll();

        return sprintf(
            'members rows=%d; Members::getAll() rows=%d.',
            $count,
            count($rows)
        );
    });

    $results[] = runTest('Members::getStats()', function () use ($db): string {
        $stats = (new Members($db))->getStats();

        return 'Stats returned: ' .
            json_encode(
                $stats,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
    });

    /*
    |--------------------------------------------------------------------------
    | Trainings
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Trainings table and Trainings::getAll()', function () use ($db): string {
        $count = (int) $db->query(
            'SELECT COUNT(*) FROM trainings'
        )->fetchColumn();

        $rows = (new Trainings($db))->getAll();

        return sprintf(
            'trainings rows=%d; Trainings::getAll() rows=%d.',
            $count,
            count($rows)
        );
    });

    $results[] = runTest('Trainings::getStatsOptimized()', function () use ($db): string {
        $stats = (new Trainings($db))->getStatsOptimized();

        return 'Stats returned: ' .
            json_encode(
                $stats,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
    });

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Events table and Events::getAll()', function () use ($db): string {
        $count = (int) $db->query(
            'SELECT COUNT(*) FROM events WHERE is_voided = 0'
        )->fetchColumn();

        $rows = (new Events($db))->getAll();

        return sprintf(
            'active events=%d; Events::getAll() rows=%d.',
            $count,
            count($rows)
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Funds
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Funds table and Funds::getStats()', function () use ($db): string {
        $count = (int) $db->query(
            'SELECT COUNT(*) FROM funds WHERE is_voided = 0'
        )->fetchColumn();

        $stats = (new Funds($db))->getStats();

        return 'active funds=' .
            $count .
            '; stats=' .
            json_encode(
                $stats,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
    });

    $results[] = runTest('Funds::getVoided()', function () use ($db): string {
        $rows = (new Funds($db))->getVoided();

        return 'Funds::getVoided() rows=' . count($rows) . '.';
    });

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Attendance table', function () use ($db): string {
        $tableExists = (int) $db->query("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'attendance'
        ")->fetchColumn();

        if ($tableExists !== 1) {
            throw new RuntimeException('The attendance table does not exist.');
        }

        $count = (int) $db->query(
            'SELECT COUNT(*) FROM attendance'
        )->fetchColumn();

        return 'attendance table exists; rows=' . $count . '.';
    });

    /*
    |--------------------------------------------------------------------------
    | Expected schema verification
    |--------------------------------------------------------------------------
    */

    $expectedColumns = [
        'users' => [
            'user_id',
            'full_name',
            'username',
            'email',
            'password_hash',
            'member_id',
            'email_verified_at',
            'mobile_verified_at',
            'last_login_at',
            'session_version',
            'role',
            'is_active',
            'created_at',
            'updated_at'
        ],

        'members' => [
            'member_id',
            'full_name',
            'dob',
            'email',
            'height',
            'weight',
            'contact_number',
            'address',
            'affiliated_school',
            'guardian_name',
            'guardian_contact',
            'payment_notes',
            'rank',
            'member_type',
            'player_category',
            'training_preference',
            'status',
            'profile_img',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'is_voided',
            'voided_at',
            'voided_by',
            'void_reason'
        ],

        'trainings' => [
            'training_id',
            'training_date',
            'training_type',
            'target_group',
            'venue_type',
            'start_time',
            'end_time',
            'location',
            'description',
            'fee_amount',
            'coach_id',
            'created_by',
            'created_at',
            'updated_at',
            'updated_by',
            'is_voided',
            'voided_at',
            'voided_by',
            'void_reason'
        ],

        'events' => [
            'event_id',
            'event_name',
            'event_type',
            'event_date',
            'location',
            'description',
            'target_player_category',
            'expected_participants',
            'created_at',
            'updated_at',
            'is_voided',
            'voided_at',
            'voided_by',
            'void_reason',
            'created_by',
            'updated_by'
        ],

        'funds' => [
            'fund_id',
            'transaction_type',
            'source_or_purpose',
            'amount',
            'transaction_date',
            'notes',
            'related_event_id',
            'recorded_by',
            'created_at',
            'is_voided',
            'voided_at',
            'voided_by',
            'void_reason',
            'updated_by',
            'updated_at'
        ],

        'attendance' => [
            'attendance_id',
            'training_id',
            'member_id',
            'status',
            'remarks',
            'recorded_by',
            'recorded_at',
            'updated_by',
            'updated_at',
            'is_voided',
            'voided_at',
            'voided_by',
            'void_reason'
        ],

        'registration_requests' => [
            'request_id',
            'full_name',
            'dob',
            'email',
            'contact_number',
            'address',
            'player_category',
            'training_preference',
            'affiliated_school',
            'guardian_name',
            'guardian_contact',
            'payment_notes',
            'requested_rank',
            'status',
            'submitted_at',
            'reviewed_at',
            'reviewed_by',
            'review_notes',
            'member_id'
        ]
    ];

    $results[] = runTest('Expected schema columns', function () use ($db, $expectedColumns): string {
        $missing = [];

        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
            ORDER BY ORDINAL_POSITION
        ");

        foreach ($expectedColumns as $table => $columns) {
            $stmt->execute([
                ':table_name' => $table
            ]);

            $actual = array_map(
                static fn($value): string => (string) $value,
                $stmt->fetchAll(PDO::FETCH_COLUMN)
            );

            $tableMissing = array_values(
                array_diff($columns, $actual)
            );

            if ($tableMissing) {
                $missing[$table] = $tableMissing;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'Missing columns: ' .
                json_encode(
                    $missing,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        return 'All checked columns are present.';
    });

    /*
    |--------------------------------------------------------------------------
    | Foreign key verification
    |--------------------------------------------------------------------------
    */

    $results[] = runTest('Foreign key integrity', function () use ($db): string {
        $sql = "
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME,
                CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, COLUMN_NAME
        ";

        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            throw new RuntimeException(
                'No foreign keys were found in the current database.'
            );
        }

        $summary = [];

        foreach ($rows as $row) {
            $summary[] = sprintf(
                '%s.%s -> %s.%s',
                $row['TABLE_NAME'],
                $row['COLUMN_NAME'],
                $row['REFERENCED_TABLE_NAME'],
                $row['REFERENCED_COLUMN_NAME']
            );
        }

        return implode("\n", $summary);
    });
}

/*
|--------------------------------------------------------------------------
| Overall result
|--------------------------------------------------------------------------
*/

$failed = array_filter(
    $results,
    static fn(array $result): bool => $result['status'] === 'FAIL'
);

$allOk = count($failed) === 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Arnis Connect Dashboard Diagnostic</title>

    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 28px;
            background: #10131a;
            color: #eef2ff;
            font-family: Arial, Helvetica, sans-serif;
        }

        main {
            width: 100%;
            max-width: 1150px;
            margin: 0 auto;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
            line-height: 1.2;
        }

        .note {
            margin: 0 0 24px;
            color: #aeb7cb;
            line-height: 1.5;
        }

        .summary {
            margin-bottom: 18px;
            padding: 15px 17px;
            border: 1px solid #2b3342;
            border-radius: 10px;
            background: #151a23;
            font-weight: 700;
        }

        .summary.ok {
            color: #4ade80;
        }

        .summary.fail {
            color: #ff7b84;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #2b3342;
            border-radius: 10px;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
            background: #161b25;
        }

        th,
        td {
            padding: 13px 14px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid #2b3342;
        }

        th {
            color: #c58cff;
            font-size: 14px;
            background: #141922;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .ok {
            color: #4ade80;
            font-weight: 700;
        }

        .fail {
            color: #ff7b84;
            font-weight: 700;
        }

        .detail {
            white-space: pre-wrap;
            word-break: break-word;
            color: #d8deea;
        }

        .security {
            margin-top: 18px;
            padding: 14px 16px;
            border: 1px solid #2b3342;
            border-radius: 10px;
            background: #151a23;
            color: #aeb7cb;
            line-height: 1.5;
        }

        code {
            color: #ffd36e;
        }

        @media (max-width: 700px) {
            body {
                padding: 15px;
            }

            h1 {
                font-size: 22px;
            }
        }
    </style>
</head>

<body>

<main>

    <h1>Arnis Connect Dashboard Diagnostic</h1>

    <p class="note">
        Temporary diagnostic. This page does not display database passwords
        or password hashes. Delete this file from the server after testing.
    </p>

    <div class="summary <?= $allOk ? 'ok' : 'fail' ?>">
        <?= $allOk
            ? 'All dashboard checks passed.'
            : 'One or more dashboard checks failed. Check the failed row for the exact backend error.' ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Test</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($results as $result): ?>

                <tr>
                    <td>
                        <?= h((string) $result['name']) ?>
                    </td>

                    <td class="<?= $result['status'] === 'OK' ? 'ok' : 'fail' ?>">
                        <?= h((string) $result['status']) ?>
                    </td>

                    <td class="detail">
                        <?= h((string) $result['detail']) ?>
                    </td>
                </tr>

            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="security">
        After troubleshooting, remove
        <code>dashboard_diagnostic.php</code>
        from your hosting server.
    </div>

</main>

</body>
</html>