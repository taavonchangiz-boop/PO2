<?php
namespace WHCM\Core;

/**
 * پیاده‌سازی Web Push بدون وابستگی خارجی
 * پشتیبانی از VAPID (RFC 8292) و رمزنگاری aes128gcm (RFC 8291)
 *
 * @package WHCM\Core
 */
class WebPush {

    /**
     * ارسال اعلان Web Push
     */
    public static function send(
        string $endpoint,
        string $userPublicKey,
        string $userAuthToken,
        string $payload,
        array $vapid,
        int $ttl = 2419200
    ): array {
        $headers = [
            'TTL: ' . $ttl,
            'Content-Type: application/octet-stream',
        ];

        // VAPID Authentication
        if (!empty($vapid['subject']) && !empty($vapid['publicKey']) && !empty($vapid['privateKey'])) {
            $audience = self::getAudience($endpoint);
            $vapidHeader = self::createVapidAuthorization(
                $audience, $vapid['subject'], $vapid['publicKey'], $vapid['privateKey']
            );
            $headers[] = 'Authorization: ' . $vapidHeader;
        }

        // Encrypt payload (aes128gcm)
        $encrypted = self::encryptPayload($payload, $userPublicKey, $userAuthToken);
        $headers[] = 'Content-Encoding: aes128gcm';
        $headers[] = 'Content-Length: ' . strlen($encrypted);

        // HTTP POST to push service
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $encrypted,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'success' => ($statusCode >= 200 && $statusCode < 300),
            'status'  => $statusCode,
            'error'   => $curlError ?: null,
        ];
    }

    /**
     * ارسال اعلان به چندین اشتراک همزمان
     */
    public static function sendBatch(array $subscriptions, string $payload, array $vapid): array {
        $results = [];
        foreach ($subscriptions as $sub) {
            $results[] = self::send(
                $sub['endpoint'],
                $sub['keys_p256dh'],
                $sub['keys_auth'],
                $payload,
                $vapid
            );
        }
        return $results;
    }

    // ─── VAPID (RFC 8292) ──────────────────────────────────────

    private static function createVapidAuthorization(
        string $audience, string $subject, string $publicKey, string $privateKeyPem
    ): string {
        $token = self::buildVapidJwt($audience, $subject, $privateKeyPem);
        return 'vapid t=' . $token . ', k=' . $publicKey;
    }

    private static function buildVapidJwt(string $audience, string $subject, string $privateKeyPem): string {
        $headerB64 = self::b64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $bodyB64   = self::b64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));

        $unsignedToken = $headerB64 . '.' . $bodyB64;

        $pkey = openssl_pkey_get_private($privateKeyPem);
        if (!$pkey) {
            throw new \RuntimeException('VAPID private key load error: ' . openssl_error_string());
        }

        openssl_sign($unsignedToken, $signature, $pkey, OPENSSL_ALGO_SHA256);

        return $unsignedToken . '.' . self::b64UrlEncode($signature);
    }

    // ─── Payload Encryption — aes128gcm (RFC 8291) ────────────

    private static function encryptPayload(string $payload, string $userPubKeyB64, string $userAuthB64): string {
        $userPubKey = self::b64UrlDecode($userPubKeyB64);
        $userAuth   = self::b64UrlDecode($userAuthB64);

        // 1. Ephemeral ECDH key pair
        $ephemKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $ephemPubRaw = self::extractRawPoint(openssl_pkey_get_details($ephemKey));

        // 2. ECDH shared secret
        $sharedSecret = self::ecdh($ephemKey, $userPubKey);

        // 3. HKDF-Extract(salt=userAuth, IKM=sharedSecret)
        $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);

        // 4. HKDF info (RFC 8291 Section 2)
        $info = "Content-Encoding: aes128gcm\x00P-256\x00"
              . self::u16(strlen($ephemPubRaw)) . $ephemPubRaw
              . self::u16(strlen($userPubKey))  . $userPubKey;

        // 5. Derive CEK (16B) and nonce (12B) via HKDF-Expand
        $cek   = substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', $info . "\x02", $prk, true), 0, 12);

        // 6. AES-128-GCM encrypt
        $tag = '';
        $ciphertext = openssl_encrypt($payload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Push encryption failed: ' . openssl_error_string());
        }

        // 7. Build record: [ephem_pubkey 65B][0x00 0x00][ciphertext][tag 16B]
        return $ephemPubRaw . "\x00\x00" . $ciphertext . $tag;
    }

    // ─── ECDH ──────────────────────────────────────────────────

    private static function ecdh($localKey, string $remotePubRaw): string {
        $remotePem = self::rawPointToSpkiPem($remotePubRaw);
        $remoteKey = openssl_pkey_get_public($remotePem);
        if (!$remoteKey) {
            throw new \RuntimeException('Remote public key error: ' . openssl_error_string());
        }

        $result = openssl_dh_compute_key($remoteKey, $localKey);
        if ($result === false) {
            throw new \RuntimeException('ECDH compute error: ' . openssl_error_string());
        }

        return str_pad($result, 32, "\x00", STR_PAD_LEFT);
    }

    // ─── Helpers ───────────────────────────────────────────────

    private static function getAudience(string $endpoint): string {
        $p = parse_url($endpoint);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    private static function b64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $data): string {
        $pad = 4 - (strlen($data) % 4);
        if ($pad !== 4) $data .= str_repeat('=', $pad);
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function u16(int $n): string {
        return chr(($n >> 8) & 0xFF) . chr($n & 0xFF);
    }

    private static function extractRawPoint(array $keyDetails): string {
        $pem = $keyDetails['key'];
        $der = base64_decode(preg_replace('/-----.*?-----/', '', $pem));
        $pos = strrpos($der, "\x04");
        if ($pos === false || $pos + 65 > strlen($der)) {
            throw new \RuntimeException('Cannot extract EC public point.');
        }
        return substr($der, $pos, 65);
    }

    private static function rawPointToSpkiPem(string $point): string {
        // OID 1.2.840.10045.2.1 (EC) + 1.2.840.10045.3.1.7 (P-256)
        $oidBytes = hex2bin('06082a8648ce3d030107');
        $bitString = "\x03" . chr(1 + strlen($point)) . "\x00" . $point;
        $spkiContent = $oidBytes . $bitString;
        $spkiLen = strlen($spkiContent);
        $spki = "\x30" . chr($spkiLen < 128 ? $spkiLen : 0x80 | 1) . ($spkiLen >= 128 ? chr($spkiLen) : '') . $spkiContent;

        return "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($spki), 64) .
               "-----END PUBLIC KEY-----";
    }
}
