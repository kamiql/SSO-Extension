<?php

declare(strict_types=1);

namespace Sso;

final class Pkce
{
    /**
     * @return string
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * @param string
     * @return string
     */
    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /**
     * @param string
     * @return bool
     */
    public static function isValidVerifier(string $verifier): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) === 1;
    }

    /**
     * @param string
     * @return string
     */
    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
