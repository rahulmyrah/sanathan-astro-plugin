<?php
/**
 * Qdrant Vector DB Client.
 *
 * Direct REST API calls to Qdrant (Cloud or self-hosted).
 *
 * Collections used:
 *   sanathan_knowledge  — shared Vedic knowledge base (global search)
 *   user_kundali        — per-user birth chart summaries (multitenancy)
 *
 * @package SAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Qdrant {

    const KNOWLEDGE_COLLECTION = 'sanathan_knowledge';
    const KUNDALI_COLLECTION   = 'user_kundali';
    const SCORE_THRESHOLD      = 0.70;

    private string $url;
    private string $api_key;

    public function __construct() {
        $settings = sas_get_settings();

        // Allow admin UI to inject URL/key for connection testing before save
        $this->url     = rtrim( apply_filters( 'sas_qdrant_url_override',     $settings['qdrant_url']     ?? '' ), '/' );
        $this->api_key = apply_filters( 'sas_qdrant_api_key_override', $settings['qdrant_api_key'] ?? '' );
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function is_configured(): bool {
        return ! empty( $this->url ) && ! empty( $this->api_key );
    }

    /**
     * Test connection to Qdrant and return info about both collections.
     *
     * @return array { ok, collections: { knowledge: int, kundali: int }, error? }
     */
    public function health_check(): array {
        if ( ! $this->is_configured() ) {
            return [ 'ok' => false, 'error' => 'Qdrant URL or API key not configured.' ];
        }

        $knowledge = $this->collection_info( self::KNOWLEDGE_COLLECTION );
        $kundali   = $this->collection_info( self::KUNDALI_COLLECTION );

        if ( empty( $knowledge ) ) {
            return [ 'ok' => false, 'error' => 'Cannot connect to Qdrant. Check URL and API key.' ];
        }

        return [
            'ok'          => true,
            'collections' => [
                'knowledge' => (int) ( $knowledge['result']['points_count'] ?? 0 ),
                'kundali'   => (int) ( $kundali['result']['points_count']   ?? 0 ),
            ],
        ];
    }

    /**
     * Get collection info from Qdrant.
     */
    public function collection_info( string $collection ): array {
        return $this->request( 'GET', "/collections/{$collection}" );
    }

    /**
     * Count points matching an optional filter.
     */
    public function count( string $collection, array $filter = [] ): int {
        $body = [ 'exact' => true ];
        if ( ! empty( $filter ) ) {
            $body['filter'] = $filter;
        }
        $result = $this->request( 'POST', "/collections/{$collection}/points/count", $body );
        return (int) ( $result['result']['count'] ?? 0 );
    }

    // ── Search ───────────────────────────────────────────────────────────────

    /**
     * Semantic search in a collection.
     *
     * @param string $collection
     * @param array  $vector      1536-dim embedding vector.
     * @param int    $limit       Max results.
     * @param array  $filter      Qdrant filter object.
     * @param float  $threshold   Min similarity score (0-1).
     * @return array  Array of { id, score, payload } hits.
     */
    public function search(
        string $collection,
        array  $vector,
        int    $limit     = 5,
        array  $filter    = [],
        float  $threshold = self::SCORE_THRESHOLD
    ): array {
        $body = [
            'vector'          => $vector,
            'limit'           => $limit,
            'with_payload'    => true,
            'score_threshold' => $threshold,
        ];
        if ( ! empty( $filter ) ) {
            $body['filter'] = $filter;
        }

        $result = $this->request( 'POST', "/collections/{$collection}/points/search", $body );
        return $result['result'] ?? [];
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Upsert (insert or update) points into a collection.
     *
     * @param string $collection
     * @param array  $points  [ { id, vector, payload }, ... ]
     *                        id must be an integer or UUID string.
     * @return bool
     */
    public function upsert( string $collection, array $points ): bool {
        $result = $this->request( 'PUT', "/collections/{$collection}/points", [
            'points' => $points,
        ] );
        return ( $result['status'] ?? '' ) === 'ok';
    }

    /**
     * Delete all points matching a filter.
     *
     * @param string $collection
     * @param array  $filter  Qdrant filter — e.g. must match user_id.
     * @return bool
     */
    public function delete_by_filter( string $collection, array $filter ): bool {
        $result = $this->request( 'POST', "/collections/{$collection}/points/delete", [
            'filter' => $filter,
        ] );
        return ( $result['status'] ?? '' ) === 'ok';
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private function request( string $method, string $path, array $body = [] ): array {
        if ( ! $this->is_configured() ) {
            return [];
        }

        $url  = $this->url . $path;
        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'api-key'      => $this->api_key,
                'Content-Type' => 'application/json',
            ],
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[SAS Qdrant] ' . $method . ' ' . $path . ': ' . $response->get_error_message() );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[SAS Qdrant] HTTP ' . $code . ' ' . $method . ' ' . $path );
            return [];
        }

        return is_array( $data ) ? $data : [];
    }
}
