<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_cbt\domain\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Domain service to generate and validate 6-digit room tokens (TOTP or manual override).
 *
 * @package    mod_cbt
 * @copyright  2026 Aksaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_generator {
    public const TOKEN_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Base32 unambiguous
    public const DEFAULT_WINDOW_SECS = 900; // 15 minutes window

    /**
     * Generate deterministic 6-character token for a given time window using HMAC-SHA256.
     *
     * @param string $secret
     * @param int|null $timestamp
     * @param int $window_secs
     * @return string
     */
    public static function generate_token(string $secret, ?int $timestamp = null, int $window_secs = self::DEFAULT_WINDOW_SECS): string {
        $ts = $timestamp ?? time();
        $window = floor($ts / max(1, $window_secs));

        $hash = hash_hmac('sha256', (string)$window, $secret);
        $offset = hexdec(substr($hash, -1)) * 2;
        $sub = substr($hash, $offset, 8);
        $num = hexdec($sub);

        $len = strlen(self::TOKEN_ALPHABET);
        $token = '';
        for ($i = 0; $i < 6; $i++) {
            $idx = $num % $len;
            $token .= self::TOKEN_ALPHABET[$idx];
            $num = (int)($num / $len);
        }

        return $token;
    }

    /**
     * Validate an entered token against active manual token or TOTP time window.
     *
     * @param string $input_token Entered token by student.
     * @param string $secret Secret key from cbt activity.
     * @param string $active_token Manual override token if set.
     * @param int $token_expiry Manual override expiration timestamp.
     * @param int $window_secs TOTP window length in seconds.
     * @return bool
     */
    public static function validate(
        string $input_token,
        string $secret,
        string $active_token = '',
        int $token_expiry = 0,
        int $window_secs = self::DEFAULT_WINDOW_SECS
    ): bool {
        $clean_input = strtoupper(trim($input_token));
        if ($clean_input === '') {
            return false;
        }

        $now = time();

        // 1. Check manual active token override
        if ($active_token !== '') {
            $clean_active = strtoupper(trim($active_token));
            if ($clean_input === $clean_active) {
                if ($token_expiry <= 0 || $now <= $token_expiry) {
                    return true;
                }
            }
        }

        // 2. Check TOTP window (current window and previous window for boundary tolerance)
        if ($secret !== '') {
            $current_token = self::generate_token($secret, $now, $window_secs);
            if ($clean_input === $current_token) {
                return true;
            }

            $prev_token = self::generate_token($secret, $now - $window_secs, $window_secs);
            if ($clean_input === $prev_token) {
                return true;
            }
        }

        return false;
    }
}

