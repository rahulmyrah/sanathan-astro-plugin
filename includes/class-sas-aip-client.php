<?php
/**
 * AIP (AI Power Plugin) REST API Client.
 *
 * Wraps calls to the AIP plugin's internal REST API.
 * AIP is installed on the same WordPress site so we call it via home_url().
 * Auth: Bearer token (set in Sanathan Settings → Personal Guruji → AIP API Key).
 *
 * @package SAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_AIP_Client {

    /** @var string AIP REST API key */
    private string $api_key;

    /** @var string Base URL for AIP REST endpoints */
    private string $base_url;

    /** @var string Model to use for generation */
    private string $model;

    // ── Provider → model prefix map ──────────────────────────────────────────

    /**
     * Known model → provider mappings.
     * Auto-detects provider from model name prefix.
     */
    private const PROVIDER_MAP = [
        'gpt-'          => 'openai',
        'o1'            => 'openai',
        'o3'            => 'openai',
        'o4'            => 'openai',
        'claude-'       => 'anthropic',
        'gemini-'       => 'google',
        'llama'         => 'groq',
        'mixtral'       => 'groq',
        'mistral'       => 'mistral',
        'command'       => 'cohere',
        'titan'         => 'aws',
        'meta-llama'    => 'meta',
    ];

    /**
     * Curated list of popular models grouped by provider.
     * Used as fallback when AIP doesn't expose a models endpoint.
     */
    public const KNOWN_MODELS = [
        'OpenAI' => [
            'gpt-4o'         => 'GPT-4o',
            'gpt-4o-mini'    => 'GPT-4o Mini (Recommended)',
            'gpt-4-turbo'    => 'GPT-4 Turbo',
            'o3-mini'        => 'o3 Mini',
        ],
        'Anthropic' => [
            'claude-haiku-4-5-20251001'     => 'Claude Haiku (Cheapest)',
            'claude-3-5-sonnet-20241022'    => 'Claude Sonnet 3.5',
            'claude-3-opus-20240229'        => 'Claude Opus 3',
        ],
        'Google' => [
            'gemini-2.0-flash'   => 'Gemini 2.0 Flash',
            'gemini-1.5-pro'     => 'Gemini 1.5 Pro',
            'gemini-1.5-flash'   => 'Gemini 1.5 Flash',
        ],
        'Groq (Fast & Free tier)' => [
            'llama3-70b-8192'          => 'LLaMA 3 70B',
            'llama-3.1-8b-instant'     => 'LLaMA 3.1 8B (Fastest)',
            'mixtral-8x7b-32768'       => 'Mixtral 8x7B',
        ],
    ];

    // ── Constructor ──────────────────────────────────────────────────────────

    public function __construct() {
        $settings = sas_get_settings();

        // Allow admin UI to inject a temporary API key (before settings are saved)
        $key_override   = apply_filters( 'sas_aip_api_key_override', '' );
        $this->api_key  = $key_override ?: ( $settings['aip_api_key'] ?? '' );

        // If custom model ID is set, it overrides the dropdown
        $custom         = $settings['aip_model_custom'] ?? '';
        $this->model    = $custom ?: ( $settings['aip_model'] ?? 'gpt-4o-mini' );

        // AIP is on same WP install — use internal URL
        $this->base_url = rtrim( home_url( '/wp-json/aipkit/v1' ), '/' );
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Generate a response from the LLM via AIP.
     *
     * @param string $system_prompt  The Guruji persona + Kundali context.
     * @param array  $messages       Conversation history [ ['role'=>'user','content'=>'...'], ... ]
     * @param array  $ai_params      Optional overrides: temperature, max_tokens etc.
     * @return array { success, content, usage, error }
     */
    public function generate( string $system_prompt, array $messages, array $ai_params = [] ): array {
        if ( empty( $this->api_key ) ) {
            return [ 'success' => false, 'error' => 'AIP API key not configured. Please add it in Settings → Personal Guruji.' ];
        }

        $provider = $this->detect_provider( $this->model );

        $body = [
            'provider'           => $provider,
            'model'              => $this->model,
            'system_instruction' => $system_prompt,
            'messages'           => $messages,
        ];

        if ( ! empty( $ai_params ) ) {
            $body['ai_params'] = $ai_params;
        }

        $response = $this->post( 'generate', $body );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        if ( ! empty( $response['content'] ) ) {
            return [
                'success' => true,
                'content' => $response['content'],
                'usage'   => $response['usage'] ?? [],
                'model'   => $this->model,
            ];
        }

        // AIP may return error details
        $error = $response['message'] ?? $response['error'] ?? 'AIP returned an unexpected response.';
        return [ 'success' => false, 'error' => $error ];
    }

    /**
     * Try to fetch available models from AIP.
     * Falls back to self::KNOWN_MODELS if AIP has no models endpoint.
     *
     * @return array  Grouped [ 'ProviderLabel' => [ 'model_id' => 'Model Name', ... ], ... ]
     *                OR empty array on total failure (use KNOWN_MODELS as fallback).
     */
    public function fetch_models(): array {
        if ( empty( $this->api_key ) ) {
            return [];
        }

        // Try AIP models endpoint (may not exist in all versions)
        $response = $this->get( 'models' );

        if ( ! empty( $response ) && ! isset( $response['code'] ) ) {
            // AIP returned something — try to parse it
            return $this->parse_aip_models( $response );
        }

        // Fallback — return our curated list
        return self::KNOWN_MODELS;
    }

    /**
     * Quick connectivity check — returns true if AIP responds with valid auth.
     */
    public function test_connection(): bool {
        if ( empty( $this->api_key ) ) {
            return false;
        }
        // Minimal generate call to verify key + endpoint work
        $result = $this->generate(
            'You are a test assistant.',
            [ [ 'role' => 'user', 'content' => 'Reply with: OK' ] ],
            [ 'max_tokens' => 5 ]
        );
        return ! empty( $result['success'] );
    }

    /**
     * Whether an API key is configured.
     */
    public function has_api_key(): bool {
        return ! empty( $this->api_key );
    }

    // ── Provider detection ───────────────────────────────────────────────────

    /**
     * Detect the AIP provider string from a model name.
     */
    public function detect_provider( string $model ): string {
        $model_lower = strtolower( $model );
        foreach ( self::PROVIDER_MAP as $prefix => $provider ) {
            if ( str_starts_with( $model_lower, $prefix ) ) {
                return $provider;
            }
        }
        return 'openai'; // safe default
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    /**
     * POST to an AIP endpoint.
     */
    private function post( string $endpoint, array $body ): array {
        $url      = $this->base_url . '/' . ltrim( $endpoint, '/' );
        $response = wp_remote_post( $url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        return $this->parse_response( $response, $endpoint );
    }

    /**
     * GET from an AIP endpoint.
     */
    private function get( string $endpoint ): array {
        $url      = $this->base_url . '/' . ltrim( $endpoint, '/' );
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
        ] );

        return $this->parse_response( $response, $endpoint );
    }

    /**
     * Parse a wp_remote_* response into an array.
     */
    private function parse_response( $response, string $endpoint ): array {
        if ( is_wp_error( $response ) ) {
            error_log( '[SAS AIP] WP_Error on ' . $endpoint . ': ' . $response->get_error_message() );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[SAS AIP] HTTP ' . $code . ' on ' . $endpoint . ': ' . $body );
            return is_array( $data ) ? $data : [ 'code' => $code, 'message' => $body ];
        }

        return is_array( $data ) ? $data : [];
    }

    /**
     * Attempt to normalise an AIP models response into our grouped format.
     */
    private function parse_aip_models( array $raw ): array {
        // AIP may return a flat array of model strings or objects
        $grouped = [];
        foreach ( $raw as $item ) {
            if ( is_string( $item ) ) {
                $provider               = ucfirst( $this->detect_provider( $item ) );
                $grouped[ $provider ][ $item ] = $item;
            } elseif ( is_array( $item ) && ! empty( $item['id'] ) ) {
                $id                             = $item['id'];
                $provider                       = ucfirst( $this->detect_provider( $id ) );
                $grouped[ $provider ][ $id ]    = $item['name'] ?? $id;
            }
        }
        return ! empty( $grouped ) ? $grouped : self::KNOWN_MODELS;
    }
}
