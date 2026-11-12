<?php
declare(strict_types=1);

/**
 * System settings backed by the `settings` table.
 *
 * Defaults are the safe values used when the table has not been migrated yet.
 * Callers should treat every value as a string and cast explicitly.
 */
class Settings
{
    private PDO $conn;

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public const DEFAULTS = [
        'organization_name' => 'Arnis Connect',
        'contact_email' => '',
        'from_email' => '',
        'from_name' => 'Arnis Connect',
        'allow_registration' => '1',
        'email_notifications' => '1',
        'face_match_threshold' => '0.60',
        'default_geofence_m' => '100',
        'privacy_contact' => '',
        'smtp_host' => 'smtp-relay.brevo.com',
        'smtp_port' => '587',
        'smtp_secure' => 'tls',
        'smtp_user' => '',
        'smtp_pass' => '',
    ];

    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;
        try {
            $stmt = $this->conn->query('SELECT setting_key, setting_value FROM settings');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string)($row['setting_key'] ?? '');
                if ($key !== '') {
                    $values[$key] = (string)($row['setting_value'] ?? '');
                }
            }
        } catch (Throwable $e) {
            error_log('Settings::all skipped: ' . $e->getMessage());
        }

        self::$cache = $values;
        return $values;
    }

    public function get(string $key, ?string $default = null): string
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public function enabled(string $key): bool
    {
        return in_array(strtolower($this->get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value, int $updatedBy = 0): bool
    {
        $allowed = array_keys(self::DEFAULTS);
        if (!in_array($key, $allowed, true)) {
            return false;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:k, :v, :by, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                         updated_by = VALUES(updated_by),
                                         updated_at = NOW()'
            );
            $ok = $stmt->execute([
                ':k' => $key,
                ':v' => $value,
                ':by' => $updatedBy > 0 ? $updatedBy : null,
            ]);
            self::$cache = null;
            return $ok;
        } catch (Throwable $e) {
            error_log('Settings::set failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Convenience for pages that only have a PDO handle. */
    public static function value(PDO $db, string $key, ?string $default = null): string
    {
        return (new self($db))->get($key, $default);
    }

    public static function flag(PDO $db, string $key): bool
    {
        return (new self($db))->enabled($key);
    }
}
