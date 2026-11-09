<?php
declare(strict_types=1);

/**
 * Read-only aggregate view of a single member for public/member_profile.php.
 *
 * Column names in the SELECTs are the contract consumed by the page, so they
 * are asserted here instead of being duplicated as literal strings in markup.
 */
class MemberProfile
{
    /** Mirrors achievements.achievement_type ENUM('Gold','Silver','Bronze','Recognition'). */
    public const ACHIEVEMENT_TYPES = ['Gold', 'Silver', 'Bronze', 'Recognition'];

    /** Mirrors events.event_type ENUM. */
    public const EVENT_TYPES = ['Performance', 'Tournament', 'Outreach'];

    /** Mirrors attendance.status ENUM('Present','Late','Absent'). */
    public const ATTENDANCE_STATUSES = ['Present', 'Late', 'Absent'];

    private PDO $conn;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    /**
     * @param string $achievementFilter one of self::ACHIEVEMENT_TYPES, or '' for all
     * @param string $eventMonth        'YYYY-MM', or '' for all
     * @return array<string,mixed>|null null when the member does not exist or is voided
     */
    public function getProfileData(int $memberId, string $achievementFilter = '', string $eventMonth = ''): ?array
    {
        if ($memberId <= 0) {
            return null;
        }

        $member = $this->getMemberDetails($memberId);
        if ($member === null) {
            return null;
        }

        return [
            'member' => $member,
            'age' => self::calculateAge(isset($member['dob']) ? (string)$member['dob'] : null),
            'attendance' => $this->getAttendanceSummary($memberId),
            'achievements' => $this->getAchievements($memberId, $achievementFilter),
            'eventParticipations' => $this->getEventParticipations($memberId, $eventMonth),
        ];
    }

    private function getMemberDetails(int $memberId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                member_id,
                full_name,
                dob,
                email,
                rank,
                member_type,
                player_category,
                training_preference,
                affiliated_school,
                guardian_name,
                guardian_contact,
                payment_notes,
                contact_number,
                address,
                height,
                weight,
                profile_img,
                face_descriptor,
                created_at,
                status
            FROM members
            WHERE member_id = :member_id AND is_voided = 0
            LIMIT 1
        ");

        $stmt->execute([':member_id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function calculateAge(?string $dob): ?int
    {
        $normalized = self::extractDate($dob);
        if ($normalized === null) {
            return null;
        }

        try {
            $birthDate = new DateTime($normalized);
            if ($birthDate > new DateTime('today')) {
                return null;
            }
            return (int)$birthDate->diff(new DateTime('today'))->y;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getAttendanceSummary(int $memberId): array
    {
        $stmt = $this->conn->prepare("
            SELECT status, COUNT(*) AS record_count
            FROM attendance
            WHERE member_id = :member_id AND is_voided = 0
            GROUP BY status
        ");
        $stmt->execute([':member_id' => $memberId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)($row['status'] ?? '')] = (int)($row['record_count'] ?? 0);
        }

        $presentCount = $counts['Present'] ?? 0;
        $lateCount = $counts['Late'] ?? 0;
        $absentCount = $counts['Absent'] ?? 0;
        $totalAttendance = $presentCount + $lateCount + $absentCount;
        $attendanceRate = $totalAttendance > 0 ? (($presentCount / $totalAttendance) * 100) : 0.0;

        return [
            'present' => $presentCount,
            'late' => $lateCount,
            'absent' => $absentCount,
            'total' => $totalAttendance,
            'rate' => round($attendanceRate, 2),
        ];
    }

    /**
     * achievement_type is exposed once. The previous query aliased the same
     * column to both "achievement" and "type", and the page rendered both, so
     * every row printed the same medal twice.
     */
    private function getAchievements(int $memberId, string $filter = ''): array
    {
        $sql = "
            SELECT
                a.achievement_id,
                a.achievement_type,
                a.level,
                a.achieved_at AS date_earned,
                a.notes,
                e.event_name
            FROM achievements a
            LEFT JOIN events e
                   ON a.event_id = e.event_id
                  AND e.is_voided = 0
            WHERE a.member_id = :member_id
              AND a.is_voided = 0
        ";

        $params = [':member_id' => $memberId];

        if (in_array($filter, self::ACHIEVEMENT_TYPES, true)) {
            $sql .= " AND a.achievement_type = :achievement_type";
            $params[':achievement_type'] = $filter;
        }

        // A zero date sorts last in DESC, which is the desired behaviour.
        $sql .= " ORDER BY a.achieved_at DESC, a.achievement_id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * event_participants has no medal_result column - the page used to read one
     * and silently fell back to a hardcoded string. Real columns only.
     */
    private function getEventParticipations(int $memberId, string $eventMonth = ''): array
    {
        $sql = "
            SELECT
                ep.participant_id,
                ep.role,
                ep.created_at AS added_at,
                e.event_name,
                e.event_type,
                e.event_date,
                e.location AS event_location
            FROM event_participants ep
            JOIN events e
              ON ep.event_id = e.event_id
             AND e.is_voided = 0
            WHERE ep.member_id = :member_id
              AND ep.is_voided = 0
        ";

        $params = [':member_id' => $memberId];

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $eventMonth) === 1) {
            $sql .= " AND DATE_FORMAT(e.event_date, '%Y-%m') = :event_month";
            $params[':event_month'] = $eventMonth;
        }

        $sql .= " ORDER BY e.event_date DESC, ep.participant_id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns a validated 'Y-m-d' string, or null for null, '', malformed input
     * and MySQL's '0000-00-00' placeholder.
     *
     * date('m/d/Y', strtotime('0000-00-00')) renders 12/31/1969, which is how a
     * placeholder date became a visible "date earned" in the UI.
     */
    private static function extractDate(?string $date): ?string
    {
        $date = trim((string)$date);
        if ($date === '' || preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $matches) !== 1) {
            return null;
        }

        $day = $matches[1];
        if (str_starts_with($day, '0000-00-00')) {
            return null;
        }

        $parsed = DateTime::createFromFormat('!Y-m-d', $day);
        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            return null;
        }

        return $day;
    }

    public static function formatDate(?string $date, string $fallback = 'N/A'): string
    {
        $day = self::extractDate($date);
        if ($day === null) {
            return $fallback;
        }

        $parsed = DateTime::createFromFormat('!Y-m-d', $day);
        return $parsed === false ? $fallback : $parsed->format('m/d/Y');
    }

    /** Long form used for the date of birth, e.g. "February 9, 2026". */
    public static function formatLongDate(?string $date, string $fallback = 'N/A'): string
    {
        $day = self::extractDate($date);
        if ($day === null) {
            return $fallback;
        }

        $parsed = DateTime::createFromFormat('!Y-m-d', $day);
        return $parsed === false ? $fallback : $parsed->format('F j, Y');
    }

    /**
     * A NULL-aware string reader. Every nullable column here (notes, location,
     * role, profile_img, height, weight) reaches htmlspecialchars() somewhere in
     * the page, and passing null is deprecated in PHP 8.1.
     */
    public static function text(?string $value, string $fallback = ''): string
    {
        if ($value === null) {
            return $fallback;
        }
        $value = trim($value);
        return $value === '' ? $fallback : $value;
    }

    public static function getAchievementEmoji(?string $type): string
    {
        $emojis = [
            'Gold' => '🥇',
            'Silver' => '🥈',
            'Bronze' => '🥉',
            'Recognition' => '⭐',
        ];

        $type = trim((string)$type);
        return $emojis[$type] ?? '🏅';
    }

    /** Tailwind class for the achievement medal chip. */
    public static function getAchievementColor(?string $type): string
    {
        $colors = [
            'Gold' => 'bg-yellow-500/10 text-yellow-400',
            'Silver' => 'bg-gray-400/10 text-gray-300',
            'Bronze' => 'bg-orange-700/20 text-orange-400',
            'Recognition' => 'bg-purple-500/10 text-purple-400',
        ];

        $type = trim((string)$type);
        return $colors[$type] ?? 'bg-gray-700/40 text-gray-300';
    }
}