<?php
declare(strict_types=1);

namespace Chat\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class Timestamp
{
    private const OUTGOING_FIELDS = [
        'created_at',
        'updated_at',
        'last_seen_at',
        'expires_at',
        'closed_at',
        'joined_at',
        'banned_at',
        'banned_until',
        'muted_until',
        'responded_at',
    ];

    private static ?DateTimeZone $utc = null;

    public static function isoUtc(null|string|DateTimeInterface|int $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $utc = self::utc();

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone($utc)
                ->format('Y-m-d\TH:i:s.v\Z');
        }

        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))
                ->setTimezone($utc)
                ->format('Y-m-d\TH:i:s.v\Z');
        }

        $raw = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.(\d{1,6}))?$/', $raw, $matches)) {
            $fraction = isset($matches[1]) ? str_pad(substr($matches[1], 0, 6), 6, '0') : '000000';
            $withMicroseconds = preg_replace('/(?:\.\d{1,6})?$/', '.' . $fraction, $raw);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string) $withMicroseconds, $utc);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d\TH:i:s.v\Z');
            }
        }

        $date = new DateTimeImmutable($raw, $utc);
        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function nowIsoUtc(): string
    {
        return (new DateTimeImmutable('now', self::utc()))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function normalizeFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = self::isoUtc($row[$field] === null ? null : (string) $row[$field]);
            }
        }
        return $row;
    }

    public static function normalizeRows(array $rows, array $fields): array
    {
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = self::normalizeFields($row, $fields);
            }
        }
        return $rows;
    }

    public static function normalizeOutgoingPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::normalizeOutgoingPayload($value);
                continue;
            }

            if (in_array((string) $key, self::OUTGOING_FIELDS, true)) {
                $payload[$key] = self::isoUtc($value === null ? null : (string) $value);
            }
        }

        return $payload;
    }

    private static function utc(): DateTimeZone
    {
        if (self::$utc === null) {
            self::$utc = new DateTimeZone('UTC');
        }
        return self::$utc;
    }
}
