<?php
/**
 * VedicAstroAPI HTTP client.
 *
 * Wraps wp_remote_get() calls to the external API.
 * Reads the API key from the existing vedicastroapi plugin option so no
 * duplicate configuration is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Api_Client {

    /** @var string VedicAstroAPI key */
    private string $api_key;

    public function __construct() {
        $this->api_key = sas_get_vedic_api_key();
    }

    // ── Prediction endpoints ───────────────────────────────────────────────────

    /**
     * Fetch a horoscope prediction.
     *
     * @param string $type  'daily-sun' | 'weekly-sun' | 'yearly'
     * @param array  $extra  Additional query params (date | week | year, zodiac, lang)
     * @return array Decoded API response or empty array on failure.
     */
    /**
     * VedicAstroAPI zodiac name → numeric ID map.
     * The API requires integers (1=Aries … 12=Pisces), not string names.
     */
    private const ZODIAC_IDS = [
        'aries'       => 1,
        'taurus'      => 2,
        'gemini'      => 3,
        'cancer'      => 4,
        'leo'         => 5,
        'virgo'       => 6,
        'libra'       => 7,
        'scorpio'     => 8,
        'sagittarius' => 9,
        'capricorn'   => 10,
        'aquarius'    => 11,
        'pisces'      => 12,
    ];

    const DELHI_LAT = 28.6139;
    const DELHI_LON = 77.2090;
    const DELHI_TZ  = 5.5;

    public function fetch_prediction( string $type, array $extra ): array {
        // Convert zodiac name to numeric ID — the API rejects string names
        if ( isset( $extra['zodiac'] ) && ! is_numeric( $extra['zodiac'] ) ) {
            $extra['zodiac'] = self::ZODIAC_IDS[ strtolower( $extra['zodiac'] ) ] ?? $extra['zodiac'];
        }

        $params = array_merge( [
            'show_same' => 1,
            'type'      => 'big',
            'split'     => 1,
            'api_key'   => $this->api_key,
        ], $extra );

        return $this->get( 'prediction/' . $type, $params );
    }

    // ── Kundali / horoscope endpoints ─────────────────────────────────────────

    /**
     * Fetch all Kundali core data (5 endpoints) for a given birth params.
     *
     * @param array $birth  { dob, tob, lat, lon, tz, lang }
     * @return array  { planet_details, personal_chars, chart_image, maha_dasha, antar_dasha }
     */
    public function fetch_kundali_core( array $birth ): array {
        $params = array_merge( $birth, [ 'api_key' => $this->api_key ] );

        return [
            'planet_details'   => $this->get( 'horoscope/planet-details', $params ),
            'personal_chars'   => $this->get( 'horoscope/personal-characteristics', $params ),
            'chart_image'      => $this->get_svg( 'horoscope/chart-image', array_merge( $params, [ 'style' => 'south' ] ) ),
            'maha_dasha'       => $this->get( 'dashas/maha-dasha', $params ),
            'antar_dasha'      => $this->get( 'dashas/antar-dasha', $params ),
        ];
    }

    /**
     * Fetch Kundali full/premium data (6 additional endpoints).
     *
     * @param array $birth  { dob, tob, lat, lon, tz, lang }
     * @return array  { ashtakvarga, kaalsarp, mangal, manglik, pitra, papasamaya }
     */
    public function fetch_kundali_full( array $birth ): array {
        $params = array_merge( $birth, [ 'api_key' => $this->api_key ] );

        return [
            'ashtakvarga' => $this->get( 'horoscope/ashtakvarga', $params ),
            'kaalsarp'    => $this->get( 'dosha/kaalsarp-dosh', $params ),
            'mangal'      => $this->get( 'dosha/mangal-dosh', $params ),
            'manglik'     => $this->get( 'dosha/manglik-dosh', $params ),
            'pitra'       => $this->get( 'dosha/pitra-dosh', $params ),
            'papasamaya'  => $this->get( 'dosha/papasamaya', $params ),
        ];
    }

    // ── Panchang & Muhurat endpoints ──────────────────────────────────────────

    /**
     * Fetch Panchang data (Tithi, Nakshatra, Yoga, Karana, Vara + festivals).
     * Endpoint: panchang/panchang
     *
     * @param string $date_dmy  Date in d/m/Y format e.g. '25/03/2026'
     * @param float  $lat       Latitude  (default: Delhi 28.6139)
     * @param float  $lon       Longitude (default: Delhi 77.2090)
     * @param float  $tz        Timezone offset (default: IST 5.5)
     * @param string $lang      Language code (default: 'en')
     * @return array Decoded API response or [] on failure.
     */
    public function fetch_panchang( string $date_dmy, float $lat = self::DELHI_LAT, float $lon = self::DELHI_LON, float $tz = self::DELHI_TZ, string $lang = 'en' ): array {
        return $this->get( 'panchang/panchang', [
            'date'    => $date_dmy,
            'lat'     => $lat,
            'lon'     => $lon,
            'tz'      => $tz,
            'lang'    => $lang,
            'api_key' => $this->api_key,
        ] );
    }

    /**
     * Fetch Choghadiya muhurat (auspicious / inauspicious time slots).
     * Endpoint: panchang/choghadiya-muhurta
     *
     * @param string $date_dmy  Date in d/m/Y format
     * @param float  $lat       Latitude
     * @param float  $lon       Longitude
     * @param float  $tz        Timezone offset
     * @param string $lang      Language code
     * @return array Decoded API response or [] on failure.
     */
    public function fetch_choghadiya( string $date_dmy, float $lat = self::DELHI_LAT, float $lon = self::DELHI_LON, float $tz = self::DELHI_TZ, string $lang = 'en' ): array {
        return $this->get( 'panchang/choghadiya-muhurta', [
            'date'    => $date_dmy,
            'lat'     => $lat,
            'lon'     => $lon,
            'tz'      => $tz,
            'lang'    => $lang,
            'api_key' => $this->api_key,
        ] );
    }

    // ── Location / geo search ─────────────────────────────────────────────────

    /**
     * Search for a location and return { lat, lon, tz }.
     *
     * @param string $city
     * @return array First matching location or empty array.
     */
    public function geo_search( string $city ): array {
        $result = $this->get( 'utilities/geo-search', [
            'city'    => $city,
            'api_key' => $this->api_key,
        ] );

        if ( isset( $result['response'][0] ) ) {
            $loc = $result['response'][0];
            return [
                'lat'  => (float) ( $loc['coordinates']['latitude']  ?? 28.6139 ),
                'lon'  => (float) ( $loc['coordinates']['longitude'] ?? 77.2090 ),
                'tz'   => (float) ( $loc['timezone'] ?? 5.5 ),
                'name' => (string) ( $loc['name'] ?? $city ),
            ];
        }

        return [];
    }

    // ── Core HTTP helpers ─────────────────────────────────────────────────────

    /**
     * Make a GET request and return decoded JSON as array.
     */
    private function get( string $endpoint, array $params ): array {
        // Restore literal slashes in date values — the API expects dd/mm/yyyy not dd%2Fmm%2Fyyyy
        $url      = VEDIC_ASTRO_API_ROOT_URL . ltrim( $endpoint, '/' ) . '?' . str_replace( '%2F', '/', http_build_query( $params ) );
        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[SAS] API error: ' . $response->get_error_message() );
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return is_array( $data ) ? $data : [];
    }

    /**
     * Make a GET request and return raw body (for SVG chart images).
     */
    private function get_svg( string $endpoint, array $params ): string {
        // Restore literal slashes in date values — the API expects dd/mm/yyyy not dd%2Fmm%2Fyyyy
        $url      = VEDIC_ASTRO_API_ROOT_URL . ltrim( $endpoint, '/' ) . '?' . str_replace( '%2F', '/', http_build_query( $params ) );
        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        return (string) wp_remote_retrieve_body( $response );
    }

    /**
     * Check whether the API key is configured.
     */
    public function has_api_key(): bool {
        return ! empty( $this->api_key );
    }
}
