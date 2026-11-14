<?php
declare(strict_types=1);

/**
 * Self check-in window for a training session or event, in Asia/Manila time.
 *
 * Opens $openBeforeMin minutes before start_time and closes at end_time
 * (or 3 hours after start when no end time is set). Arriving more than
 * $graceMin minutes after start_time is Late. Sessions without a start time
 * are open for the whole day and never Late.
 *
 * Returned state:
 *   'upcoming' - a later date, or today but before the window opens
 *   'open'     - check-in allowed now
 *   'closed'   - the window has passed; roster members who never checked in
 *                stay Absent
 *
 * @return array{state:string, late:bool, opens_at:?int, closes_at:?int}
 */
function arnisCheckinWindow(string $date, ?string $startTime, ?string $endTime, int $openBeforeMin = 30, int $graceMin = 15, ?int $now = null): array
{
    $tz = new DateTimeZone('Asia/Manila');
    $now = $now ?? time();
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d');
    $date = substr(trim($date), 0, 10);

    $toTs = static function (?string $time) use ($date, $tz): ?int {
        $time = trim((string)$time);
        if ($time === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time), $tz);
        return $dt === false ? null : $dt->getTimestamp();
    };

    $startTs = $toTs($startTime);
    $endTs = $toTs($endTime);

    if ($startTs === null) {
        $dayStart = (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
        $opensAt = $dayStart;
        $closesAt = $endTs ?? ($dayStart + 86399);
    } else {
        $opensAt = $startTs - max(0, $openBeforeMin) * 60;
        $closesAt = ($endTs !== null && $endTs > $startTs) ? $endTs : $startTs + 3 * 3600;
    }

    if ($date > $today || $now < $opensAt) {
        $state = 'upcoming';
    } elseif ($date < $today || $now > $closesAt) {
        $state = 'closed';
    } else {
        $state = 'open';
    }

    return [
        'state' => $state,
        'late' => $startTs !== null && $now > $startTs + max(0, $graceMin) * 60,
        'opens_at' => $opensAt,
        'closes_at' => $closesAt,
    ];
}

function arnisFormatManila(string $format, int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('Asia/Manila'))
        ->format($format);
}
