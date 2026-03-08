<?php
/**
 * Personal Guruji AI — core business logic.
 *
 * Responsibilities:
 *  - Manage Guruji profiles (one per user: name, avatar, personality, language)
 *  - Build personalised system prompts from Guruji profile + user Kundali
 *  - Handle chat: inject context, call AIP, store history
 *
 * @package SAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Guruji {

    // ── Personality prompt fragments ──────────────────────────────────────────

    private const PERSONALITY_PROMPTS = [
        'traditional' => 'Speak with timeless wisdom, ancient Vedic knowledge, and occasional Sanskrit shlokas. Be reverent and deeply spiritual.',
        'friendly'    => 'Be warm, approachable, and encouraging like a loving elder. Use simple language and relatable examples.',
        'mystical'    => 'Speak in poetic, metaphorical language. Reference cosmic energies, divine timing, and spiritual mysteries.',
        'strict'      => 'Be direct, disciplined, and precise. Give clear, actionable guidance without sugar-coating.',
    ];

    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'hi' => 'Hindi (Devanagari script)',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'ka' => 'Kannada',
        'ml' => 'Malayalam',
        'be' => 'Bengali',
        'sp' => 'Spanish',
        'fr' => 'French',
    ];

    // ── Profile management ───────────────────────────────────────────────────

    /**
     * Get the Guruji profile for a user.
     * Returns null if not set up yet.
     */
    public static function get_profile( int $user_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_guruji_profiles';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d LIMIT 1", $user_id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        // Resolve avatar URL
        $row['resolved_avatar_url'] = self::resolve_avatar_url( $row );
        return $row;
    }

    /**
     * Create or update a user's Guruji profile.
     *
     * @param int   $user_id
     * @param array $data {
     *   guruji_name, avatar_type, avatar_preset_id, avatar_url,
     *   gender, personality, reply_language, tts_enabled
     * }
     * @return array { success, profile }
     */
    public static function save_profile( int $user_id, array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_guruji_profiles';
        $now   = current_time( 'mysql', true );

        $clean = [
            'guruji_name'      => sanitize_text_field( $data['guruji_name']      ?? 'Guruji' ),
            'avatar_type'      => in_array( $data['avatar_type'] ?? '', [ 'preset', 'custom' ], true )
                                    ? $data['avatar_type'] : 'preset',
            'avatar_preset_id' => sanitize_text_field( $data['avatar_preset_id'] ?? '' ) ?: null,
            'avatar_url'       => esc_url_raw( $data['avatar_url']               ?? '' ) ?: null,
            'gender'           => in_array( $data['gender'] ?? '', [ 'male', 'female' ], true )
                                    ? $data['gender'] : 'male',
            'personality'      => in_array( $data['personality'] ?? '', [ 'traditional', 'friendly', 'mystical', 'strict' ], true )
                                    ? $data['personality'] : 'traditional',
            'reply_language'   => in_array( $data['reply_language'] ?? '', SAS_SUPPORTED_LANGS, true )
                                    ? $data['reply_language'] : 'en',
            'tts_enabled'      => (int) ( $data['tts_enabled'] ?? 1 ),
        ];

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM `{$table}` WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( $exists ) {
            $clean['updated_at'] = $now;
            $wpdb->update( $table, $clean, [ 'user_id' => $user_id ], null, [ '%d' ] );
        } else {
            $clean['user_id']    = $user_id;
            $clean['created_at'] = $now;
            $clean['updated_at'] = $now;
            $wpdb->insert( $table, $clean );
        }

        return [
            'success' => true,
            'profile' => self::get_profile( $user_id ),
        ];
    }

    /**
     * List all available preset avatars.
     */
    public static function get_presets(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_guruji_presets';
        return $wpdb->get_results(
            "SELECT * FROM `{$table}` ORDER BY sort_order ASC",
            ARRAY_A
        ) ?: [];
    }

    // ── Chat ─────────────────────────────────────────────────────────────────

    /**
     * Handle a user's chat message to their Guruji.
     *
     * @param int    $user_id
     * @param string $message        The user's message.
     * @param string $session_token  Optional — resume existing session.
     * @return array {
     *   success, reply, guruji_name, guruji_avatar,
     *   reply_language, guruji_gender, tts_enabled,
     *   session_token, error?
     * }
     */
    public static function chat( int $user_id, string $message, string $session_token = '' ): array {
        // 1. Load Guruji profile
        $profile = self::get_profile( $user_id );
        if ( ! $profile ) {
            return [
                'success' => false,
                'error'   => 'Guruji not set up yet. Please complete your Guruji setup first.',
                'setup_required' => true,
            ];
        }

        // 2. Get or create session
        $session = self::get_or_create_session( $user_id, $session_token );
        if ( ! $session ) {
            return [ 'success' => false, 'error' => 'Could not create Guruji session.' ];
        }

        // 3. Load user's Kundali for context (from WordPress DB — always fresh)
        $kundali_context = self::build_kundali_context( $user_id );

        // 4. RAG: search Qdrant knowledge base for relevant Vedic content
        $knowledge_context = SAS_Knowledge::search_context( $message, 3 );

        // 5. Build the personalised system prompt (Kundali + RAG knowledge)
        $system_prompt = self::build_system_prompt( $profile, $kundali_context, $user_id, $knowledge_context );

        // 6. Load recent conversation history (last 10 messages)
        $history = self::get_context_messages( $session['id'], 10 );

        // 7. Append the current user message
        $history[] = [ 'role' => 'user', 'content' => $message ];

        // 8. Call AIP (LLM generation)
        $aip    = new SAS_AIP_Client();
        $result = $aip->generate( $system_prompt, $history );

        if ( empty( $result['success'] ) ) {
            return [
                'success' => false,
                'error'   => $result['error'] ?? 'Guruji is unavailable right now. Please try again.',
            ];
        }

        $reply = $result['content'];

        // 9. Store conversation
        self::store_message( $session['id'], 'user',      $message, 'user' );
        self::store_message( $session['id'], 'assistant', $reply,   'llm' );

        // 9. Update session last_active
        self::touch_session( $session['id'] );

        return [
            'success'         => true,
            'reply'           => $reply,
            'guruji_name'     => $profile['guruji_name'],
            'guruji_avatar'   => $profile['resolved_avatar_url'],
            'reply_language'  => $profile['reply_language'],
            'guruji_gender'   => $profile['gender'],
            'tts_enabled'     => (bool) $profile['tts_enabled'],
            'session_token'   => $session['session_token'],
            'usage'           => $result['usage'] ?? [],
        ];
    }

    /**
     * Get conversation history for a session.
     *
     * @param int $user_id
     * @param string $session_token
     * @param int $limit  Max messages to return (most recent first)
     * @return array
     */
    public static function get_history( int $user_id, string $session_token = '', int $limit = 50 ): array {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'sanathan_guruji_sessions';
        $messages_table = $wpdb->prefix . 'sanathan_guruji_messages';

        // Find the session
        if ( $session_token ) {
            $session_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$sessions_table}` WHERE user_id = %d AND session_token = %s",
                    $user_id, $session_token
                )
            );
        } else {
            // Get most recent session
            $session_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$sessions_table}` WHERE user_id = %d ORDER BY last_active DESC LIMIT 1",
                    $user_id
                )
            );
        }

        if ( ! $session_id ) {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT role, message, context_source, created_at
                 FROM `{$messages_table}`
                 WHERE session_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d",
                $session_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Clear all conversation history for a user.
     */
    public static function clear_history( int $user_id ): int {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'sanathan_guruji_sessions';
        $messages_table = $wpdb->prefix . 'sanathan_guruji_messages';

        $session_ids = $wpdb->get_col(
            $wpdb->prepare( "SELECT id FROM `{$sessions_table}` WHERE user_id = %d", $user_id )
        );

        if ( empty( $session_ids ) ) {
            return 0;
        }

        $ids_sql = implode( ',', array_map( 'intval', $session_ids ) );
        $deleted = (int) $wpdb->query( "DELETE FROM `{$messages_table}` WHERE session_id IN ({$ids_sql})" );
        $wpdb->query( $wpdb->prepare( "DELETE FROM `{$sessions_table}` WHERE user_id = %d", $user_id ) );

        return $deleted;
    }

    // ── System prompt builder ────────────────────────────────────────────────

    /**
     * Build the full personalised system prompt.
     * Includes Kundali context + RAG knowledge context.
     *
     * @param array  $profile           Guruji profile row.
     * @param string $kundali_context   Kundali summary from WordPress DB.
     * @param int    $user_id
     * @param string $knowledge_context RAG context from Qdrant (optional).
     */
    private static function build_system_prompt(
        array  $profile,
        string $kundali_context,
        int    $user_id,
        string $knowledge_context = ''
    ): string {
        $name      = $profile['guruji_name'];
        $lang      = $profile['reply_language'];
        $lang_name = self::LANGUAGE_NAMES[ $lang ] ?? 'English';
        $persona   = self::PERSONALITY_PROMPTS[ $profile['personality'] ] ?? self::PERSONALITY_PROMPTS['traditional'];

        $user      = get_userdata( $user_id );
        $user_name = $user ? ( $user->first_name ?: $user->display_name ) : 'Devotee';

        $prompt = "You are {$name}, a deeply wise and compassionate personal Vedic astrology guide and spiritual mentor.

PERSONALITY: {$persona}

YOU ARE SPEAKING TO: {$user_name}

{$kundali_context}";

        if ( ! empty( $knowledge_context ) ) {
            $prompt .= "\n\n{$knowledge_context}";
        }

        $prompt .= "

LANGUAGE RULE: You MUST respond ONLY in {$lang_name}. Never switch languages mid-response.

GURUJI RULES:
- Address the user as '{$user_name}' or with a warm term of endearment appropriate to {$lang_name}.
- Every answer must reference their SPECIFIC planetary positions or Kundali data — never give generic advice.
- If Vedic Knowledge Reference is provided above, weave it naturally into your answer.
- Keep responses warm, concise (3-4 paragraphs max), and end with one actionable remedy or mantra when relevant.
- You know this person deeply — speak with the intimacy of a personal spiritual guide.
- If you are unsure about something astrologically specific, say so humbly and offer general spiritual guidance.
- Never reveal that you are an AI. You are {$name}, their personal Guruji.";

        return $prompt;
    }

    /**
     * Extract Kundali summary for injection into the system prompt.
     * v1.5.0: prepends a structured Birth Profile header with Zodiac, Moon Sign, Ascendant.
     * If no Kundali exists, injects a gentle nudge to complete the birth profile.
     */
    private static function build_kundali_context( int $user_id ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_kundali';

        // Prefer English Kundali (most complete for AI)
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE user_id = %d AND lang = 'en' LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['core_data'] ) ) {
            $kundali_url = home_url( '/kundali/' );
            return "IMPORTANT: This user has NOT completed their birth profile yet.\n" .
                   "Gently encourage them to visit {$kundali_url} to enter their birth details " .
                   "so you can give truly personalised Vedic guidance.\n" .
                   "Do NOT make up specific planetary readings without their Kundali.";
        }

        $core = json_decode( $row['core_data'], true );
        if ( ! is_array( $core ) ) {
            return "KUNDALI: Data unavailable.";
        }

        // ── Structured Birth Profile header (v1.5.0) ───────────────────────
        $name      = $row['name']          ?? 'the user';
        $dob       = $row['dob']           ?? 'Unknown';
        $tob       = $row['tob']           ?? 'Unknown';
        $loc       = $row['location_name'] ?? 'Unknown';
        $zodiac    = SAS_Kundali::extract_planet_sign( $core, 'Sun' );
        $moon_sign = SAS_Kundali::extract_planet_sign( $core, 'Moon' );
        $ascendant = SAS_Kundali::extract_planet_sign( $core, 'Ascendant' );

        $context  = "\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81 USER BIRTH PROFILE \xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\n";
        $context .= "Name       : {$name}\n";
        $context .= "Born       : {$dob} at {$tob} \xe2\x80\x94 {$loc}\n";
        if ( $zodiac )    { $context .= "\xe2\x98\x80\xef\xb8\x8f Zodiac    : {$zodiac}\n"; }
        if ( $moon_sign ) { $context .= "\xF0\x9F\x8C\x99 Moon Sign : {$moon_sign}\n"; }
        if ( $ascendant ) { $context .= "\xe2\xac\x86\xef\xb8\x8f Ascendant : {$ascendant}\n"; }
        $context .= "\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\xE2\x94\x81\n";

        // ── Planetary positions ─────────────────────────────────────────────
        $planets      = $core['planet_details']['response']['planets'] ?? [];
        $planet_lines = [];
        foreach ( $planets as $planet ) {
            if ( isset( $planet['name'], $planet['sign'] ) ) {
                $planet_lines[] = "  {$planet['name']}: {$planet['sign']}" .
                    ( isset( $planet['house'] ) ? " (House {$planet['house']})" : '' );
            }
        }

        if ( ! empty( $planet_lines ) ) {
            $context .= "\nPLANETARY POSITIONS:\n" . implode( "\n", $planet_lines );
        }

        // ── Dosha info if available (full tier) ─────────────────────────────
        if ( ! empty( $row['full_data'] ) ) {
            $full   = json_decode( $row['full_data'], true );
            $doshas = [];
            if ( ! empty( $full['mangal']['response']['is_manglik'] ) ) {
                $doshas[] = 'Mangal Dosh';
            }
            if ( ! empty( $full['kaalsarp']['response']['present'] ) ) {
                $doshas[] = 'Kaal Sarp Dosh';
            }
            if ( ! empty( $doshas ) ) {
                $context .= "\nActive Doshas: " . implode( ', ', $doshas );
            }
        }

        return $context;
    }

    // ── Session management ───────────────────────────────────────────────────

    /**
     * Get existing session by token or create a new one.
     */
    private static function get_or_create_session( int $user_id, string $session_token = '' ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_guruji_sessions';

        if ( $session_token ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE user_id = %d AND session_token = %s LIMIT 1",
                    $user_id, $session_token
                ),
                ARRAY_A
            );
            if ( $row ) {
                return $row;
            }
        }

        // Create new session
        $token = wp_generate_uuid4();
        $now   = current_time( 'mysql', true );

        $wpdb->insert( $table, [
            'user_id'      => $user_id,
            'session_token'=> $token,
            'created_at'   => $now,
            'last_active'  => $now,
        ] );

        return [
            'id'            => $wpdb->insert_id,
            'user_id'       => $user_id,
            'session_token' => $token,
        ];
    }

    private static function touch_session( int $session_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sanathan_guruji_sessions',
            [ 'last_active' => current_time( 'mysql', true ) ],
            [ 'id' => $session_id ]
        );
    }

    /**
     * Get the last N messages for a session as LLM-friendly format.
     */
    private static function get_context_messages( int $session_id, int $limit = 10 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sanathan_guruji_messages';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT role, message FROM `{$table}`
                 WHERE session_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d",
                $session_id, $limit
            ),
            ARRAY_A
        );

        // Reverse so oldest is first (LLM context order)
        $rows = array_reverse( $rows ?: [] );

        return array_map( fn( $r ) => [
            'role'    => $r['role'],
            'content' => $r['message'],
        ], $rows );
    }

    private static function store_message( int $session_id, string $role, string $message, string $source = 'llm' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'sanathan_guruji_messages',
            [
                'session_id'     => $session_id,
                'role'           => $role,
                'message'        => $message,
                'context_source' => in_array( $source, [ 'qdrant', 'llm', 'template', 'user' ], true ) ? $source : 'llm',
                'created_at'     => current_time( 'mysql', true ),
            ]
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function resolve_avatar_url( array $profile ): string {
        if ( $profile['avatar_type'] === 'custom' && ! empty( $profile['avatar_url'] ) ) {
            return $profile['avatar_url'];
        }
        if ( ! empty( $profile['avatar_preset_id'] ) ) {
            // Return plugin bundled image URL
            return SAS_PLUGIN_URL . 'admin/images/guruji/' . $profile['avatar_preset_id'] . '.png';
        }
        return SAS_PLUGIN_URL . 'admin/images/guruji/sage_male.png';
    }
}
