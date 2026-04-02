<?php
/**
 * Exchange rate handler — converts fiat to satoshis using Yadio API.
 */

defined('ABSPATH') || exit;

class WLF_Exchange {

    private static $cache_key = 'wlf_btc_rate';
    private static $cache_ttl = 300; // 5 minutes

    /**
     * Convert a fiat amount to millisatoshis.
     *
     * @param float  $amount   Fiat amount (e.g., 45000.00)
     * @param string $currency ISO currency code (e.g., 'ARS')
     * @return int|false Millisatoshis, or false on failure
     */
    public static function fiat_to_msats($amount, $currency = 'ARS') {
        $rate = self::get_rate($currency);
        if (!$rate) return false;

        // rate = how many units of currency per 1 BTC
        // sats = (amount / rate) * 100_000_000
        // msats = sats * 1000
        $btc = $amount / $rate;
        $sats = $btc * 100000000;
        $msats = (int) round($sats * 1000);

        return $msats;
    }

    /**
     * Convert fiat to sats (for display).
     */
    public static function fiat_to_sats($amount, $currency = 'ARS') {
        $rate = self::get_rate($currency);
        if (!$rate) return false;

        $btc = $amount / $rate;
        return (int) round($btc * 100000000);
    }

    /**
     * Get BTC rate for a currency from Yadio.
     *
     * @param string $currency
     * @return float|false
     */
    public static function get_rate($currency = 'ARS') {
        $cached = get_transient(self::$cache_key . '_' . $currency);
        if ($cached !== false) return (float) $cached;

        $url = "https://api.yadio.io/exrates/{$currency}";
        $response = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            // Try fallback
            return self::get_rate_fallback($currency);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['BTC'])) {
            // Fallback: try /rate/ endpoint
            $url2 = "https://api.yadio.io/rate/{$currency}";
            $response2 = wp_remote_get($url2, ['timeout' => 10]);
            if (!is_wp_error($response2)) {
                $body2 = json_decode(wp_remote_retrieve_body($response2), true);
                if (isset($body2['btc'])) {
                    $rate = (float) $body2['btc'];
                    set_transient(self::$cache_key . '_' . $currency, $rate, self::$cache_ttl);
                    update_option('wlf_last_rate_' . $currency, $rate);
                    return $rate;
                }
            }
            return self::get_rate_fallback($currency);
        }

        $rate = (float) $body['BTC'];
        set_transient(self::$cache_key . '_' . $currency, $rate, self::$cache_ttl);

        // Also save as fallback
        update_option('wlf_last_rate_' . $currency, $rate);

        return $rate;
    }

    /**
     * Fallback to last known rate.
     */
    private static function get_rate_fallback($currency) {
        $last = get_option('wlf_last_rate_' . $currency, false);
        if ($last) {
            error_log("[WLF] Using cached fallback rate for {$currency}: {$last}");
            return (float) $last;
        }
        error_log("[WLF] No rate available for {$currency}");
        return false;
    }
}
