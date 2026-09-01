<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption for secrets at rest (§SR-1).
 *
 * Envelope format (matches the spec exactly, and is cross-language verifiable):
 *
 *     base64( IV[12] ‖ TAG[16] ‖ CIPHERTEXT )
 *
 * using AES-256-GCM. The key is derived as sha256(binary) of the decoded
 * APP_KEY (a `base64:`-prefixed value) or of the raw string otherwise.
 *
 * Applied to: bank card numbers, IBANs, application secrets, device secrets.
 * Plaintext MUST never be logged or persisted (§SR-1). Rotating APP_KEY makes
 * previously encrypted data unrecoverable (§SR-2).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    /** Derived 32-byte encryption key (raw binary). */
    private readonly string $key;

    public function __construct(#[SensitiveParameter] ?string $appKey = null)
    {
        $appKey ??= (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is not set; cannot derive encryption key.');
        }

        $this->key = self::deriveKey($appKey);
    }

    /**
     * Derive the raw 32-byte AES key from the application key.
     */
    private static function deriveKey(#[SensitiveParameter] string $appKey): string
    {
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $binary = $decoded === false ? $appKey : $decoded;
        } else {
            $binary = $appKey;
        }

        // sha256 in raw (binary) form yields exactly 32 bytes for AES-256.
        return hash('sha256', $binary, true);
    }

    /**
     * Encrypt a plaintext string, returning the base64 envelope.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    /**
     * Decrypt a base64 envelope produced by encrypt().
     *
     * @throws RuntimeException when the payload is malformed or authentication fails.
     */
    public function decrypt(#[SensitiveParameter] string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Malformed ciphertext envelope.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            // Wrong key, corrupted data, or tampering — GCM tag verification failed.
            throw new RuntimeException('Decryption failed: authentication tag mismatch.');
        }

        return $plaintext;
    }

    /**
     * SHA-256 hex fingerprint of a secret, stored alongside ciphertext so a
     * presented secret can be verified in constant time without decryption.
     */
    public static function fingerprint(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }
}
