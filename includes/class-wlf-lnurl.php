<?php
/**
 * LNURL-pay handler — resolves Lightning Addresses and generates invoices.
 */

defined('ABSPATH') || exit;

class WLF_LNURL {

    /**
     * Resolve a Lightning Address or LNURL to a pay endpoint.
     *
     * @param string $address Lightning Address (user@domain) or lnurl1...
     * @return array|false LNURL-pay response or false
     */
    public static function resolve($address) {
        $url = self::address_to_url($address);
        if (!$url) return false;

        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            error_log('[WLF] LNURL resolve error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['callback']) || $body['tag'] !== 'payRequest') {
            error_log('[WLF] Invalid LNURL-pay response');
            return false;
        }

        return $body;
    }

    /**
     * Request an invoice from the LNURL-pay callback.
     *
     * @param string $callback Callback URL
     * @param int    $msats    Amount in millisatoshis
     * @param string $comment  Optional comment/description
     * @return array|false Invoice data or false
     */
    public static function get_invoice($callback, $msats, $comment = '') {
        $params = ['amount' => $msats];
        if ($comment) $params['comment'] = substr($comment, 0, 144);

        $url = $callback . (strpos($callback, '?') !== false ? '&' : '?') . http_build_query($params);

        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            error_log('[WLF] Invoice request error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['pr'])) {
            error_log('[WLF] No invoice in response: ' . print_r($body, true));
            return false;
        }

        return $body;
    }

    /**
     * Convert a Lightning Address to a LNURL-pay URL.
     * user@domain.com → https://domain.com/.well-known/lnurlp/user
     * lnurl1... → decode bech32
     *
     * @param string $address
     * @return string|false
     */
    private static function address_to_url($address) {
        $address = trim($address);

        // Lightning Address format: user@domain
        if (strpos($address, '@') !== false) {
            list($user, $domain) = explode('@', $address, 2);
            return "https://{$domain}/.well-known/lnurlp/{$user}";
        }

        // LNURL format: lnurl1...
        if (stripos($address, 'lnurl') === 0) {
            return self::decode_lnurl($address);
        }

        // Plain URL
        if (filter_var($address, FILTER_VALIDATE_URL)) {
            return $address;
        }

        return false;
    }

    /**
     * Decode a bech32-encoded LNURL to a plain URL.
     *
     * @param string $lnurl
     * @return string|false
     */
    private static function decode_lnurl($lnurl) {
        $lnurl = strtolower($lnurl);
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

        $pos = strrpos($lnurl, '1');
        if ($pos === false) return false;

        $data = substr($lnurl, $pos + 1);

        // Convert characters to 5-bit values
        $values = [];
        for ($i = 0; $i < strlen($data); $i++) {
            $v = strpos($charset, $data[$i]);
            if ($v === false) return false;
            $values[] = $v;
        }

        // Remove 6-byte checksum
        $values = array_slice($values, 0, -6);

        // Convert 5-bit to 8-bit
        $acc = 0;
        $bits = 0;
        $result = [];
        foreach ($values as $v) {
            $acc = ($acc << 5) | $v;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $result[] = ($acc >> $bits) & 0xff;
            }
        }

        $url = '';
        foreach ($result as $byte) {
            $url .= chr($byte);
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
    }
}
