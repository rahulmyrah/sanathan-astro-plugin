<?php
/**
 * Vedic Astrology Knowledge Base.
 *
 * Manages the sanathan_knowledge Qdrant collection.
 * Contains pre-written Vedic astrology content covering:
 *   - 12 Zodiac signs  - 9 Planets  - 12 Houses (key ones)
 *   - Major Doshas  - Remedies  - Core concepts
 *
 * @package SAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Knowledge {

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Index ALL knowledge documents into Qdrant in batches of 10.
     * Skips nothing — always re-indexes (upsert replaces existing).
     *
     * @return array { success, indexed, errors, total }
     */
    public static function index_all(): array {
        $aip    = new SAS_AIP_Client();
        $qdrant = new SAS_Qdrant();

        if ( ! $aip->has_api_key() ) {
            return [ 'success' => false, 'error' => 'AIP API key not configured. Add it in Settings → Personal Guruji.' ];
        }

        if ( ! $qdrant->is_configured() ) {
            return [ 'success' => false, 'error' => 'Qdrant URL / API key not configured. Add them in Settings → Qdrant.' ];
        }

        $documents = self::get_documents();
        $indexed   = 0;
        $errors    = 0;
        $batch     = [];

        set_time_limit( 300 ); // Allow up to 5 minutes for large knowledge base

        foreach ( $documents as $doc ) {
            $text      = $doc['title'] . '. ' . $doc['content'];
            $embedding = $aip->get_embedding( $text );

            if ( ! $embedding ) {
                $errors++;
                error_log( '[SAS Knowledge] Failed to embed: ' . $doc['title'] );
                continue;
            }

            $batch[] = [
                'id'      => $doc['id'],
                'vector'  => $embedding,
                'payload' => [
                    'title'    => $doc['title'],
                    'content'  => $doc['content'],
                    'category' => $doc['category'],
                    'tags'     => $doc['tags'] ?? [],
                ],
            ];

            if ( count( $batch ) >= 10 ) {
                if ( $qdrant->upsert( SAS_Qdrant::KNOWLEDGE_COLLECTION, $batch ) ) {
                    $indexed += count( $batch );
                } else {
                    $errors += count( $batch );
                }
                $batch = [];
            }
        }

        // Flush remaining batch
        if ( ! empty( $batch ) ) {
            if ( $qdrant->upsert( SAS_Qdrant::KNOWLEDGE_COLLECTION, $batch ) ) {
                $indexed += count( $batch );
            } else {
                $errors += count( $batch );
            }
        }

        return [
            'success' => $errors === 0,
            'indexed' => $indexed,
            'errors'  => $errors,
            'total'   => count( $documents ),
        ];
    }

    /**
     * Search the knowledge base for context relevant to a user's question.
     * Called inside Guruji's chat() before sending to AIP.
     *
     * @param string $query     User's message.
     * @param int    $limit     Max knowledge snippets to inject.
     * @return string  Formatted context block (empty string if unavailable).
     */
    public static function search_context( string $query, int $limit = 3 ): string {
        $aip    = new SAS_AIP_Client();
        $qdrant = new SAS_Qdrant();

        if ( ! $aip->has_api_key() || ! $qdrant->is_configured() ) {
            return '';
        }

        $embedding = $aip->get_embedding( $query );
        if ( ! $embedding ) {
            return '';
        }

        $hits = $qdrant->search(
            SAS_Qdrant::KNOWLEDGE_COLLECTION,
            $embedding,
            $limit,
            [],
            0.72
        );

        if ( empty( $hits ) ) {
            return '';
        }

        $lines = [ 'VEDIC KNOWLEDGE REFERENCE (cite this in your answer where relevant):' ];
        foreach ( $hits as $hit ) {
            $payload = $hit['payload'] ?? [];
            if ( ! empty( $payload['content'] ) ) {
                $lines[] = '• [' . ( $payload['title'] ?? '' ) . '] ' . $payload['content'];
            }
        }

        return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
    }

    /**
     * Index a single user's Kundali summary into user_kundali collection.
     * Called after Kundali is created or upgraded.
     *
     * @param int $user_id
     * @return bool
     */
    public static function index_kundali( int $user_id ): bool {
        global $wpdb;

        $aip    = new SAS_AIP_Client();
        $qdrant = new SAS_Qdrant();

        if ( ! $aip->has_api_key() || ! $qdrant->is_configured() ) {
            return false;
        }

        // Always use English Kundali for AI indexing
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}sanathan_kundali` WHERE user_id = %d AND lang = 'en' LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['core_data'] ) ) {
            return false;
        }

        $core    = json_decode( $row['core_data'], true );
        $planets = $core['planet_details']['response']['planets'] ?? [];

        $planet_lines = [];
        foreach ( $planets as $p ) {
            if ( isset( $p['name'], $p['sign'] ) ) {
                $planet_lines[] = $p['name'] . ' in ' . $p['sign'] .
                    ( isset( $p['house'] ) ? ' (House ' . $p['house'] . ')' : '' );
            }
        }

        $text = sprintf(
            'Kundali for user %d. Born: %s at %s, Location: %s. Planetary positions: %s.',
            $user_id,
            $row['dob'] ?? 'unknown',
            $row['tob'] ?? 'unknown',
            $row['location_name'] ?? 'unknown',
            implode( '. ', $planet_lines )
        );

        $embedding = $aip->get_embedding( $text );
        if ( ! $embedding ) {
            return false;
        }

        // Delete old entry for this user (upsert by user_id filter)
        $qdrant->delete_by_filter( SAS_Qdrant::KUNDALI_COLLECTION, [
            'must' => [
                [ 'key' => 'user_id', 'match' => [ 'value' => (string) $user_id ] ],
            ],
        ] );

        // Use a deterministic integer ID: CRC32 of "kundali_{user_id}"
        $point_id = abs( crc32( 'kundali_' . $user_id ) );

        return $qdrant->upsert( SAS_Qdrant::KUNDALI_COLLECTION, [
            [
                'id'      => $point_id,
                'vector'  => $embedding,
                'payload' => [
                    'user_id'        => (string) $user_id,
                    'dob'            => $row['dob'] ?? '',
                    'location'       => $row['location_name'] ?? '',
                    'planet_summary' => implode( '; ', $planet_lines ),
                    'indexed_at'     => current_time( 'Y-m-d H:i:s' ),
                ],
            ],
        ] );
    }

    /**
     * Total number of documents in the built-in knowledge base.
     */
    public static function document_count(): int {
        return count( self::get_documents() );
    }

    // ── Knowledge Documents ───────────────────────────────────────────────────

    /**
     * All built-in Vedic astrology documents.
     * Format: { id (int), category, tags[], title, content }
     */
    public static function get_documents(): array {
        return array_merge(
            self::zodiac_signs(),
            self::planets(),
            self::houses(),
            self::doshas(),
            self::remedies(),
            self::concepts()
        );
    }

    // ── Zodiac Signs (IDs 1-12) ───────────────────────────────────────────────

    private static function zodiac_signs(): array {
        return [
            [
                'id' => 1, 'category' => 'zodiac', 'tags' => [ 'aries', 'mesh' ],
                'title'   => 'Aries (Mesh Rashi)',
                'content' => 'Aries, the first zodiac sign ruled by Mars (Mangal), represents fire energy, courage, and new beginnings. Aries natives are bold, dynamic, and natural leaders with immense energy. They can be impulsive. Mars in Aries gives strong leadership. Aries rules the head and face. Lucky colors: red and orange. Day: Tuesday. It is a movable (Chara) sign. They excel in entrepreneurship, military, sports. Spiritual lesson: patience and channeling aggression constructively. Aries corresponds to Mesh Rashi in Sanskrit.',
            ],
            [
                'id' => 2, 'category' => 'zodiac', 'tags' => [ 'taurus', 'vrishabha' ],
                'title'   => 'Taurus (Vrishabha Rashi)',
                'content' => 'Taurus is the second zodiac sign ruled by Venus (Shukra), representing earth energy, stability, luxury, and sensory pleasures. Taurus natives are patient, reliable, determined, and love material comforts. Taurus rules the throat and neck. Venus in Taurus brings artistic talents. Lucky colors: white and pink. Day: Friday. Fixed (Sthira) sign. Excel in banking, arts, music, agriculture. Spiritual lesson: detachment from material possessions. Sanskrit: Vrishabha Rashi.',
            ],
            [
                'id' => 3, 'category' => 'zodiac', 'tags' => [ 'gemini', 'mithuna' ],
                'title'   => 'Gemini (Mithuna Rashi)',
                'content' => 'Gemini, third zodiac sign ruled by Mercury (Budha), represents air energy, communication, and intellect. Gemini natives are quick-witted, versatile, curious, and social. Gemini rules the shoulders, arms, and lungs. Mercury enhances intelligence and eloquence. Lucky color: green. Day: Wednesday. Dual (Dwiswabhava) sign. Excel in writing, journalism, teaching, business. Spiritual lesson: developing depth and consistency. Sanskrit: Mithuna Rashi.',
            ],
            [
                'id' => 4, 'category' => 'zodiac', 'tags' => [ 'cancer', 'karka' ],
                'title'   => 'Cancer (Karka Rashi)',
                'content' => 'Cancer, fourth zodiac sign ruled by the Moon (Chandra), represents water energy, emotions, nurturing, and home. Cancer natives are intuitive, empathetic, protective, and deeply emotional with strong family connections. Cancers rule the chest. Moon gives strong intuition. Lucky colors: white and silver. Day: Monday. Movable sign. Excel in healthcare, teaching, hospitality, real estate. Spiritual lesson: emotional balance, releasing past. Sanskrit: Karka Rashi.',
            ],
            [
                'id' => 5, 'category' => 'zodiac', 'tags' => [ 'leo', 'simha' ],
                'title'   => 'Leo (Simha Rashi)',
                'content' => 'Leo, fifth zodiac sign ruled by the Sun (Surya), represents fire energy, royalty, creativity, and leadership. Leo natives are generous, confident, dramatic, and love being in the spotlight with natural authority. Leo rules the heart and spine. Sun gives strong leadership and vitality. Lucky colors: gold and orange. Day: Sunday. Fixed sign. Excel in politics, entertainment, management. Spiritual lesson: balancing ego with humility. Sanskrit: Simha Rashi.',
            ],
            [
                'id' => 6, 'category' => 'zodiac', 'tags' => [ 'virgo', 'kanya' ],
                'title'   => 'Virgo (Kanya Rashi)',
                'content' => 'Virgo, sixth zodiac sign ruled by Mercury (Budha), represents earth energy, analysis, service, and perfection. Virgo natives are methodical, practical, detail-oriented, and health-conscious. Virgo rules the digestive system. Mercury gives exceptional analytical skills. Lucky colors: green and navy. Day: Wednesday. Dual sign. Excel in medicine, accounting, research, engineering. Spiritual lesson: accepting imperfection, practicing compassion. Sanskrit: Kanya Rashi.',
            ],
            [
                'id' => 7, 'category' => 'zodiac', 'tags' => [ 'libra', 'tula' ],
                'title'   => 'Libra (Tula Rashi)',
                'content' => 'Libra, seventh zodiac sign ruled by Venus (Shukra), represents air energy, balance, justice, and relationships. Libra natives are diplomatic, charming, fair-minded, and seek harmony in all areas of life. Libra rules the kidneys and lower back. Venus enhances beauty and diplomatic skills. Lucky colors: white and light blue. Day: Friday. Movable sign. Excel in law, diplomacy, art, design, counseling. Spiritual lesson: finding inner balance. Sanskrit: Tula Rashi.',
            ],
            [
                'id' => 8, 'category' => 'zodiac', 'tags' => [ 'scorpio', 'vrishchika' ],
                'title'   => 'Scorpio (Vrishchika Rashi)',
                'content' => 'Scorpio, eighth zodiac sign co-ruled by Mars (Mangal) and Ketu, represents water energy, transformation, depth, and mysticism. Scorpio natives are intense, perceptive, secretive, and investigative with powerful intuition. Scorpio rules the reproductive organs. Lucky color: deep red. Day: Tuesday. Fixed sign. Excel in research, psychology, healing, investigation. Spiritual lesson: forgiveness and embracing transformation. Sanskrit: Vrishchika Rashi.',
            ],
            [
                'id' => 9, 'category' => 'zodiac', 'tags' => [ 'sagittarius', 'dhanu' ],
                'title'   => 'Sagittarius (Dhanu Rashi)',
                'content' => 'Sagittarius, ninth zodiac sign ruled by Jupiter (Guru), represents fire energy, wisdom, philosophy, and expansion. Sagittarius natives are optimistic, freedom-loving, philosophical, and adventurous, seeking higher knowledge. Jupiter amplifies wisdom, spirituality, and teaching. Lucky colors: yellow and purple. Day: Thursday. Dual sign. Excel in teaching, philosophy, travel, law, spiritual guidance. Spiritual lesson: remaining grounded. Sanskrit: Dhanu Rashi.',
            ],
            [
                'id' => 10, 'category' => 'zodiac', 'tags' => [ 'capricorn', 'makara' ],
                'title'   => 'Capricorn (Makara Rashi)',
                'content' => 'Capricorn, tenth zodiac sign ruled by Saturn (Shani), represents earth energy, discipline, ambition, and karma. Capricorn natives are hardworking, disciplined, responsible, and goal-oriented, building through persistent effort. Saturn brings discipline and karmic lessons. Lucky colors: black and dark blue. Day: Saturday. Movable sign. Excel in administration, engineering, politics. Spiritual lesson: balancing ambition with joy. Sanskrit: Makara Rashi.',
            ],
            [
                'id' => 11, 'category' => 'zodiac', 'tags' => [ 'aquarius', 'kumbha' ],
                'title'   => 'Aquarius (Kumbha Rashi)',
                'content' => 'Aquarius, eleventh zodiac sign ruled by Saturn (Shani) and Rahu, represents air energy, innovation, humanitarianism, and detachment. Aquarius natives are progressive, independent, intellectual, and community-oriented, thinking ahead of their time. Lucky colors: blue and turquoise. Day: Saturday. Fixed sign. Excel in science, technology, social work, innovation. Spiritual lesson: balancing detachment with emotional connection. Sanskrit: Kumbha Rashi.',
            ],
            [
                'id' => 12, 'category' => 'zodiac', 'tags' => [ 'pisces', 'meena' ],
                'title'   => 'Pisces (Meena Rashi)',
                'content' => 'Pisces, twelfth zodiac sign ruled by Jupiter (Guru) and Ketu, represents water energy, spirituality, compassion, and dissolution. Pisces natives are intuitive, empathetic, creative, and deeply spiritual with connection to unseen realms. Jupiter brings spiritual wisdom and healing. Lucky colors: sea green and yellow. Day: Thursday. Dual sign. Excel in arts, healing, spirituality, charity. Spiritual lesson: maintaining boundaries while remaining compassionate. Sanskrit: Meena Rashi.',
            ],
        ];
    }

    // ── Planets (IDs 101-109) ─────────────────────────────────────────────────

    private static function planets(): array {
        return [
            [
                'id' => 101, 'category' => 'planet', 'tags' => [ 'sun', 'surya' ],
                'title'   => 'Sun (Surya) — Soul, Authority, Father',
                'content' => 'The Sun (Surya) is the king of planets in Vedic astrology, representing the soul (Atma), self-expression, authority, father, government, and vitality. A strong Sun gives leadership, confidence, and good health. A weak Sun causes ego issues, heart problems, conflicts with authority. Surya mantra: "Om Hraam Hreem Hraum Sah Suryaya Namah." Gemstone: Ruby (Manikya). Day: Sunday. Remedy: Red flowers and jaggery offerings, Sunday fasting. Sun in 1st, 9th, 10th, or 11th house gives good results.',
            ],
            [
                'id' => 102, 'category' => 'planet', 'tags' => [ 'moon', 'chandra' ],
                'title'   => 'Moon (Chandra) — Mind, Emotions, Mother',
                'content' => 'The Moon (Chandra) represents the mind, emotions, mother, intuition, and subconscious. Moon changes signs every 2.5 days. A strong Moon gives emotional stability and creativity. A weak Moon causes anxiety, depression, mood swings. Moon is critical in Vedic astrology — birth Nakshatra determines Dasha periods. Chandra mantra: "Om Shraam Shreem Shraum Sah Chandraya Namah." Gemstone: Pearl (Moti). Day: Monday. Remedy: White flowers, milk offerings, Shiva worship, Monday fasting.',
            ],
            [
                'id' => 103, 'category' => 'planet', 'tags' => [ 'mars', 'mangal', 'kuja' ],
                'title'   => 'Mars (Mangal/Kuja) — Energy, Courage, Siblings',
                'content' => 'Mars (Mangal) represents energy, courage, siblings, property, physical strength, and aggression. Rules Aries and Scorpio. Strong Mars gives determination and leadership; afflicted Mars causes anger, accidents, and Mangal Dosha. Exalted in Capricorn. Mangal mantra: "Om Kraam Kreem Kraum Sah Bhaumaya Namah." Gemstone: Red Coral (Moonga). Day: Tuesday. Remedy: Donate red lentils on Tuesdays, Hanuman worship is most effective Mars remedy.',
            ],
            [
                'id' => 104, 'category' => 'planet', 'tags' => [ 'mercury', 'budha' ],
                'title'   => 'Mercury (Budha) — Intelligence, Communication, Business',
                'content' => 'Mercury (Budha) represents intelligence, communication, business, education, writing, and analytical thinking. Rules Gemini and Virgo. A strong Mercury gives excellent communication and business success. Weak Mercury causes speech problems, poor judgment. Mercury is combust when too close to Sun. Budha mantra: "Om Braam Breem Braum Sah Budhaya Namah." Gemstone: Emerald (Panna). Day: Wednesday. Remedy: Feed cows green grass on Wednesdays, donate green moong dal.',
            ],
            [
                'id' => 105, 'category' => 'planet', 'tags' => [ 'jupiter', 'guru', 'brihaspati' ],
                'title'   => 'Jupiter (Guru) — Wisdom, Expansion, Blessings',
                'content' => 'Jupiter (Guru/Brihaspati) is the most benefic planet, representing wisdom, expansion, divine grace, higher education, spirituality, children, and marriage. Rules Sagittarius and Pisces. A strong Jupiter brings prosperity and spiritual growth. Jupiter transit (Guru Peyarchi) is highly significant. Brihaspati mantra: "Om Graam Greem Graum Sah Gurave Namah." Gemstone: Yellow Sapphire (Pukhraj). Day: Thursday. Remedy: Feed priests, recite Guru Stotram, donate yellow items on Thursdays.',
            ],
            [
                'id' => 106, 'category' => 'planet', 'tags' => [ 'venus', 'shukra' ],
                'title'   => 'Venus (Shukra) — Love, Luxury, Marriage, Arts',
                'content' => 'Venus (Shukra) represents love, marriage, beauty, luxury, arts, music, pleasure, and material comforts. Rules Taurus and Libra. A strong Venus gives charming personality and happy marriage. Weak Venus causes relationship problems and lack of creativity. Shukra mantra: "Om Draam Dreem Draum Sah Shukraya Namah." Gemstone: Diamond or White Sapphire (Heera). Day: Friday. Remedy: Worship Goddess Lakshmi, donate white items and perfumes on Fridays.',
            ],
            [
                'id' => 107, 'category' => 'planet', 'tags' => [ 'saturn', 'shani' ],
                'title'   => 'Saturn (Shani) — Karma, Discipline, Justice, Longevity',
                'content' => 'Saturn (Shani) is the planet of karma, justice, discipline, hard work, and longevity. Rules Capricorn and Aquarius. Takes 2.5 years per sign. Sade Sati (7.5-year transit over Moon) is a major karmic period. Saturn rewards hard work and punishes shortcuts. Shani mantra: "Om Praam Preem Praum Sah Shanaischaraya Namah." Gemstone: Blue Sapphire (Neelam) — only with expert advice. Day: Saturday. Remedy: Offer mustard oil to Shani, feed crows, chant Shani Chalisa, worship Hanuman.',
            ],
            [
                'id' => 108, 'category' => 'planet', 'tags' => [ 'rahu', 'north node' ],
                'title'   => 'Rahu — Illusion, Ambition, Foreign Lands, Technology',
                'content' => 'Rahu (North Node of Moon) is a shadow planet representing worldly desires, illusions, foreign lands, technology, and sudden gains or losses. Rahu amplifies whatever it touches — both good and bad. Can give tremendous material success in its Mahadasha. Rahu in 3rd, 6th, or 11th house generally benefits. Rahu mantra: "Om Bhraam Bhreem Bhraum Sah Rahave Namah." Remedy: Donate blue/black items on Saturdays, worship Durga or Kali, chant "Om Raam Rahave Namah" 108 times.',
            ],
            [
                'id' => 109, 'category' => 'planet', 'tags' => [ 'ketu', 'south node' ],
                'title'   => 'Ketu — Spirituality, Liberation, Past Life Karma',
                'content' => 'Ketu (South Node of Moon) represents spirituality, liberation (Moksha), past-life karma, psychic abilities, and detachment. Ketu gives sudden unexpected results and dissolves ego. Ketu in the 12th house gives powerful spiritual experiences. Ketu Mahadasha brings spiritual awakening and separation from material desires. Ketu mantra: "Om Shraam Shreem Shraum Sah Ketave Namah." Remedy: Worship Ganesha and Bhairava, donate spotted items, chant "Om Ketave Namah." Exalted in Scorpio.',
            ],
        ];
    }

    // ── Key Houses (IDs 201-210) ──────────────────────────────────────────────

    private static function houses(): array {
        return [
            [
                'id' => 201, 'category' => 'house', 'tags' => [ '1st house', 'lagna', 'ascendant', 'self' ],
                'title'   => '1st House (Lagna/Ascendant) — Self, Personality, Health',
                'content' => 'The 1st House (Lagna/Ascendant) is the most important house, representing self, physical appearance, personality, health, and overall life direction. The rising sign shapes how you present yourself to the world. A strong 1st house with benefics gives vitality, confidence, and magnetic personality. Malefics here can cause health issues. The 1st house also indicates early life conditions and overall life quality.',
            ],
            [
                'id' => 202, 'category' => 'house', 'tags' => [ '2nd house', 'wealth', 'family', 'speech' ],
                'title'   => '2nd House — Wealth, Speech, Family, Food',
                'content' => 'The 2nd House represents accumulated wealth, speech quality, family values, face, food preferences, early education, and assets. Jupiter or Venus here enhances financial stability and eloquent speech. Mercury gives communication skills. Malefics can cause financial difficulties or harsh speech. This house also reveals relationship with material possessions and eating habits.',
            ],
            [
                'id' => 203, 'category' => 'house', 'tags' => [ '4th house', 'home', 'mother', 'property' ],
                'title'   => '4th House — Home, Mother, Property, Happiness',
                'content' => 'The 4th House represents home, mother, domestic happiness, property, vehicles, and inner contentment. A strong 4th house gives a happy home life and good relationship with mother. Moon or Venus here brings domestic bliss. Malefics (especially Saturn or Rahu) can indicate troubled home life or separation from mother. This house also shows real estate and vehicle ownership.',
            ],
            [
                'id' => 204, 'category' => 'house', 'tags' => [ '5th house', 'children', 'creativity', 'romance' ],
                'title'   => '5th House — Children, Intelligence, Creativity, Romance',
                'content' => 'The 5th House represents children, intelligence, creativity, romance, speculative gains, and past-life merit (Purva Punya). Jupiter in the 5th is excellent for children and creative pursuits. Malefics can delay childbirth. Strong 5th house gives sharp intellect and artistic talent. The 5th house lord and Jupiter\'s condition are key factors for childbirth and creative success.',
            ],
            [
                'id' => 205, 'category' => 'house', 'tags' => [ '7th house', 'marriage', 'partnership', 'spouse' ],
                'title'   => '7th House — Marriage, Life Partner, Partnerships',
                'content' => 'The 7th House governs marriage, life partner, business partnerships, and open relationships. Venus, Jupiter, or Moon here generally indicates good marriage. Malefics like Saturn, Mars, or Rahu can bring delays or challenges. The 7th lord\'s placement is crucial for marriage timing and spouse characteristics. Multiple planets in the 7th can indicate multiple partnerships.',
            ],
            [
                'id' => 206, 'category' => 'house', 'tags' => [ '8th house', 'transformation', 'longevity', 'occult' ],
                'title'   => '8th House — Longevity, Transformation, Occult, Inheritance',
                'content' => 'The 8th House represents longevity, sudden changes, transformation, occult knowledge, inheritance, and deep secrets. Though feared, benefics here can give longevity and occult abilities. Saturn in the 8th house often gives long life. This house also governs spouse\'s wealth (through inheritance) and indicates how one faces major life transformations.',
            ],
            [
                'id' => 207, 'category' => 'house', 'tags' => [ '9th house', 'dharma', 'luck', 'father', 'guru' ],
                'title'   => '9th House — Fortune, Dharma, Father, Spirituality, Guru',
                'content' => 'The 9th House (Bhagya Sthana) represents fortune, dharma, higher wisdom, father, religion, and spirituality. A strong 9th house brings divine blessings and good karma. Jupiter or Sun here is highly auspicious. This house indicates philosophical outlook, spiritual evolution, and relationship with one\'s guru or guide. 9th house strength is key to overall life fortune.',
            ],
            [
                'id' => 208, 'category' => 'house', 'tags' => [ '10th house', 'career', 'profession', 'status', 'fame' ],
                'title'   => '10th House — Career, Status, Fame, Authority',
                'content' => 'The 10th House (Karma Bhava) governs career, profession, social status, fame, and authority. Planets in the 10th significantly shape career path. Sun, Saturn, or Mars here often gives a prominent career. Jupiter brings success in education, law, or spiritual fields. The 10th house lord\'s placement indicates primary career area and professional success timing.',
            ],
            [
                'id' => 209, 'category' => 'house', 'tags' => [ '11th house', 'gains', 'income', 'friends', 'fulfillment' ],
                'title'   => '11th House — Income, Gains, Friends, Fulfillment of Desires',
                'content' => 'The 11th House (Labha Sthana) represents income, gains, friends, social networks, elder siblings, and fulfillment of desires. A strong 11th house brings continuous income and achievement of goals. Even malefics like Saturn or Rahu can do well here. Jupiter in the 11th is extremely beneficial for wealth accumulation.',
            ],
            [
                'id' => 210, 'category' => 'house', 'tags' => [ '12th house', 'liberation', 'expenses', 'foreign', 'moksha' ],
                'title'   => '12th House — Liberation, Expenditure, Foreign Lands, Moksha',
                'content' => 'The 12th House represents liberation (Moksha), expenditure, losses, foreign settlements, spiritual retreat, and bed pleasures. A strong 12th with benefics indicates deep spirituality or success abroad. Ketu in the 12th is auspicious for spiritual liberation. Saturn or Rahu here can indicate periods of isolation or foreign residence. The 12th house also governs sleep quality and hospital stays.',
            ],
        ];
    }

    // ── Doshas (IDs 301-305) ──────────────────────────────────────────────────

    private static function doshas(): array {
        return [
            [
                'id' => 301, 'category' => 'dosha', 'tags' => [ 'mangal dosha', 'manglik', 'mars', 'marriage' ],
                'title'   => 'Mangal Dosha (Kuja Dosha) — Mars Affliction',
                'content' => 'Mangal Dosha occurs when Mars (Mangal) is placed in the 1st, 2nd, 4th, 7th, 8th, or 12th house from Lagna, Moon, or Venus. It can create challenges in marital harmony or delays in marriage. Two Mangalik partners together neutralizes the dosha. Exceptions: Mars in Aries, Scorpio, or Capricorn, or with benefic planets. Remedies: Kumbha Vivah (symbolic marriage to a peepal tree or Vishnu idol before the real wedding), Hanuman puja, Mars mantra, donate red lentils on Tuesdays, Mangal Shanti puja.',
            ],
            [
                'id' => 302, 'category' => 'dosha', 'tags' => [ 'kaal sarp dosh', 'rahu ketu', 'serpent' ],
                'title'   => 'Kaal Sarp Dosha — All Planets Between Rahu and Ketu',
                'content' => 'Kaal Sarp Dosha forms when all seven planets are placed between Rahu and Ketu. Effects: obstacles, delays in success, health challenges, troubled relationships, ancestral karma issues. Partial Kaal Sarp (some planets outside) has milder effects. It typically reduces after age 48. Remedies: Nag Panchami puja, visiting Trimbakeshwar or Nasik (holy Shiva temples by rivers), Maha Mrityunjaya mantra recitation, Kaal Sarp Shanti puja at holy rivers, wearing Gomed after consultation.',
            ],
            [
                'id' => 303, 'category' => 'dosha', 'tags' => [ 'pitra dosha', 'ancestral karma', 'pitru' ],
                'title'   => 'Pitra Dosha — Unresolved Ancestral Karma',
                'content' => 'Pitra Dosha arises from unresolved ancestral karma, manifesting as afflictions to the Sun, 9th house, or its lord. Signs: repeated failures despite effort, relationship troubles, no progeny, health issues. Remedies: Pitru Tarpan (water offerings to ancestors) especially during Pitru Paksha (15-day period), reciting Pitra Stotram, feeding Brahmins, crows and cows, performing Shraddha rituals, planting peepal trees, Sunday Surya Arghya (offering water to Sun) regularly.',
            ],
            [
                'id' => 304, 'category' => 'dosha', 'tags' => [ 'sade sati', 'shani dosha', 'saturn transit', '7.5 years' ],
                'title'   => 'Sade Sati — 7.5 Years Saturn Transit',
                'content' => 'Sade Sati is a 7.5-year period when Saturn transits through the sign before, on, and after the natal Moon sign (three 2.5-year phases). It is a period of karmic cleansing, hard work, and life lessons — not always negative. First phase affects finances/family, second affects health/career, third affects profession/relationships. Remedies: Saturday fasting, offering mustard oil to Shani, reciting Shani Chalisa, feeding crows and black sesame, visiting Shani temples, worshipping Hanuman (most effective remedy for Shani).',
            ],
            [
                'id' => 305, 'category' => 'dosha', 'tags' => [ 'grahan yoga', 'eclipse dosha', 'sun moon eclipse' ],
                'title'   => 'Grahan Yoga — Eclipse Combination',
                'content' => 'Grahan Yoga forms when the Sun or Moon is conjunct Rahu or Ketu in the birth chart (within about 10 degrees), creating an eclipse effect. This can cause confusion, identity issues, health problems related to the eclipsed planet, and spiritual challenges. Sun-Rahu Grahan: ego confusion, father issues. Moon-Rahu Grahan: mental anxiety, addiction tendencies. Sun/Moon-Ketu Grahan: spiritual seeking, past-life influences. Remedies: Regular meditation, worship of Surya (for Sun Grahan) or Shiva (for Moon Grahan), wearing appropriate gemstone after consultation.',
            ],
        ];
    }

    // ── Remedies (IDs 401-406) ────────────────────────────────────────────────

    private static function remedies(): array {
        return [
            [
                'id' => 401, 'category' => 'remedy', 'tags' => [ 'mantra', 'japa', 'chanting' ],
                'title'   => 'Mantra Japa — Planetary Mantra Recitation',
                'content' => 'Mantra recitation (Japa) is one of the most powerful Vedic remedies. Planetary mantras are chanted 108 times (one mala) daily or on the planet\'s specific day. Rules: Recite after bathing in the morning, facing east, with concentration and devotion. Beej (seed) mantras carry concentrated planetary energy. Navagraha Stotra recited daily protects from all planetary afflictions. Maha Mrityunjaya Mantra (Om Tryambakam Yajamahe) is universal for health and longevity. Gayatri Mantra is the supreme mantra for spiritual progress.',
            ],
            [
                'id' => 402, 'category' => 'remedy', 'tags' => [ 'gemstone', 'ratna', 'crystal healing' ],
                'title'   => 'Gemstone Therapy (Ratna Chikitsa)',
                'content' => 'Gemstones amplify their ruling planet\'s energy when worn correctly. Key gemstones: Ruby (Sun), Pearl (Moon), Red Coral (Mars), Emerald (Mercury), Yellow Sapphire (Jupiter), Diamond/White Sapphire (Venus), Blue Sapphire (Saturn), Gomed/Hessonite (Rahu), Cat\'s Eye (Ketu). Important warnings: Blue Sapphire and Cat\'s Eye can be harmful if not suited — test for 3 days before permanent use. Only wear after qualified astrologer recommendation. Minimum 2-3 carats, natural, untreated gemstones in gold or silver. Never combine Saturn and Sun gemstones.',
            ],
            [
                'id' => 403, 'category' => 'remedy', 'tags' => [ 'fasting', 'vrat', 'upvas' ],
                'title'   => 'Fasting Remedies (Vrat) for Planetary Strengthening',
                'content' => 'Fasting on specific days strengthens planets and clears karmic debts. Sunday fast: Sun — confidence, government success, father relations. Monday fast: Moon — emotional balance, peace, mother relations. Tuesday fast: Mars — reduces anger, helps property and legal matters. Wednesday fast: Mercury — improves business, communication, education. Thursday fast: Jupiter — wisdom, financial blessings, progeny. Friday fast: Venus — better relationships, creativity, material comforts. Saturday fast: Saturn — karma clearing, reduces Sade Sati effects.',
            ],
            [
                'id' => 404, 'category' => 'remedy', 'tags' => [ 'charity', 'daan', 'donation' ],
                'title'   => 'Charity (Daan) Remedies for Planets',
                'content' => 'Charity (Daan) is one of the most effective Vedic remedies. Sun: donate wheat, jaggery, and copper on Sundays to one without a father. Moon: donate white rice, silver, and milk on Mondays. Mars: donate red lentils (masoor dal), red cloth, and copper on Tuesdays. Mercury: donate green moong dal, green clothing on Wednesdays. Jupiter: donate yellow items, turmeric, and books to Brahmins on Thursdays. Venus: donate white items, perfumes, and sugar on Fridays. Saturn: donate black sesame, iron, and oil on Saturdays to laborers. Rahu: donate blue/black items. Ketu: donate spotted/multi-colored items.',
            ],
            [
                'id' => 405, 'category' => 'remedy', 'tags' => [ 'puja', 'worship', 'ritual', 'temple' ],
                'title'   => 'Puja and Temple Worship for Planetary Relief',
                'content' => 'Regular temple worship and home pujas are powerful remedies. Key deity-planet associations: Sun — Surya, Ram, Shiva; Moon — Shiva, Parvati, Goddess Gauri; Mars — Hanuman, Skanda/Murugan, Subramanya; Mercury — Vishnu, Ganesha; Jupiter — Brihaspati, Dattatreya, Vishnu; Venus — Lakshmi, Annapurna; Saturn — Shani, Hanuman (most effective); Rahu — Durga, Kali; Ketu — Ganesha, Bhairava. Navagraha puja at temples covers all nine planets simultaneously. Abhishekam (ritual bathing of deities) with milk, honey, and water is highly auspicious.',
            ],
            [
                'id' => 406, 'category' => 'remedy', 'tags' => [ 'yantra', 'kavach', 'talisman' ],
                'title'   => 'Yantras and Kavachs — Sacred Geometric Talismans',
                'content' => 'Yantras are sacred geometric diagrams representing planetary energies. They act as focal points for planetary energy when properly energized (prana pratishtha). Key Yantras: Surya Yantra (Sun), Chandra Yantra (Moon), Mangal Yantra (Mars), Budha Yantra (Mercury), Guru Yantra (Jupiter), Shukra Yantra (Venus), Shani Yantra (Saturn). Navagraha Yantra covers all nine planets. Yantras should be placed in the puja room, facing east, on copper or silver plates. Sri Yantra is the most powerful yantra for overall prosperity. Kavachs (planetary amulets) are worn on the body.',
            ],
        ];
    }

    // ── Core Concepts (IDs 501-510) ───────────────────────────────────────────

    private static function concepts(): array {
        return [
            [
                'id' => 501, 'category' => 'concept', 'tags' => [ 'dasha', 'mahadasha', 'vimshottari', 'timing' ],
                'title'   => 'Dasha System (Vimshottari) — Planetary Timing Periods',
                'content' => 'The Vimshottari Dasha system is the primary timing tool in Vedic astrology (total cycle: 120 years). Based on the Moon\'s Nakshatra at birth. Mahadasha durations: Sun 6 yrs, Moon 10 yrs, Mars 7 yrs, Rahu 18 yrs, Jupiter 16 yrs, Saturn 19 yrs, Mercury 17 yrs, Ketu 7 yrs, Venus 20 yrs. Each Mahadasha has Antardasha (sub-periods). The ruling planet\'s natal strength determines whether the period brings positive or challenging results. Current Dasha can be calculated from birth Nakshatra and date of birth.',
            ],
            [
                'id' => 502, 'category' => 'concept', 'tags' => [ 'nakshatra', 'lunar mansion', 'birth star' ],
                'title'   => 'Nakshatras — The 27 Lunar Mansions',
                'content' => 'The 27 Nakshatras (lunar mansions) span 13°20\' each. The Moon\'s birth Nakshatra determines Dasha period and personality. Key Nakshatras: Ashwini (healing, speed), Rohini (beauty, Moon\'s favorite), Punarvasu (renewal), Pushya (most auspicious — best for new ventures), Magha (ancestral power), Chitra (creativity), Vishakha (ambition), Anuradha (devotion), Jyeshtha (power), Mula (liberation, transformation), Uttara Ashadha (lasting victory), Shravana (learning), Dhanishtha (wealth, music). Each Nakshatra has a ruling deity, planet, and animal symbol influencing personality.',
            ],
            [
                'id' => 503, 'category' => 'concept', 'tags' => [ 'muhurta', 'auspicious timing', 'election astrology' ],
                'title'   => 'Muhurta — Auspicious Timing for Important Events',
                'content' => 'Muhurta is Vedic electional astrology — selecting auspicious moments for important events. Key events requiring good Muhurta: marriage, business launch, property purchase, travel, medical procedures, new ventures. Key factors: Tithi (lunar day), Nakshatra, Vara (day of week), Yoga, Karana. Avoid: Rahu Kala (1.5-hour malefic period daily), Gulika Kala, and Yamaghanda for new starts. Pushya Nakshatra on Thursday is the most auspicious for gold purchase, business, and learning. Abhijit Muhurta (midday period) is generally auspicious.',
            ],
            [
                'id' => 504, 'category' => 'concept', 'tags' => [ 'yoga', 'raj yoga', 'dhana yoga', 'planetary combination' ],
                'title'   => 'Yogas — Powerful Auspicious Planetary Combinations',
                'content' => 'Yogas are special planetary combinations indicating talents, wealth, fame, or challenges. Raj Yoga: Lords of Kendra (1,4,7,10) and Trikona (1,5,9) houses conjoining gives power and success. Dhana Yoga: 2nd and 11th house lords with benefics gives wealth. Gajakesari Yoga: Jupiter and Moon in mutual Kendra gives wisdom and prosperity. Budha-Aditya Yoga: Sun and Mercury together gives intelligence. Pancha Mahapurusha Yogas: Hamsa (Jupiter), Malavya (Venus), Shasha (Saturn), Ruchaka (Mars), Bhadra (Mercury) in Kendra in own/exaltation sign — extraordinarily powerful.',
            ],
            [
                'id' => 505, 'category' => 'concept', 'tags' => [ 'kundali matching', 'compatibility', 'marriage matching', 'guna milan' ],
                'title'   => 'Kundali Matching (Guna Milan) — Marriage Compatibility',
                'content' => 'Kundali matching (Guna Milan or Horoscope Matching) is done before marriage in Vedic tradition. The Ashtakoot system checks 8 factors: Varna (1 point), Vashya (2), Tara (3), Yoni (4), Graha Maitri (5), Gana (6), Bhakoot (7), Nadi (8) — totaling 36 points. 18+ points: acceptable match; 24+ points: good match; 32+ points: excellent. Nadi (8 points) is most important for health and progeny. Bhakoot (7 points) affects financial and emotional compatibility. Mangal Dosha compatibility must be checked separately.',
            ],
            [
                'id' => 506, 'category' => 'concept', 'tags' => [ 'transit', 'gochara', 'planetary movement' ],
                'title'   => 'Gochara (Transits) — Current Planetary Positions',
                'content' => 'Gochara refers to current planetary transits and their effects on the natal chart. Results are measured from natal Moon sign (not ascendant). Key transit durations: Sun (1 month/sign), Moon (2.5 days), Mars (45 days), Mercury (3 weeks), Jupiter (1 year), Venus (1 month), Saturn (2.5 years). Saturn and Jupiter transits are most significant. Jupiter transit from Moon\'s 2nd, 5th, 7th, 9th, 11th positions is auspicious. Saturn transit from Moon\'s 1st, 2nd, 12th (Sade Sati), 4th, and 8th positions can be challenging.',
            ],
            [
                'id' => 507, 'category' => 'concept', 'tags' => [ 'lagna', 'rising sign', 'ascendant', 'birth chart' ],
                'title'   => 'Lagna (Ascendant) — The Rising Sign at Birth',
                'content' => 'The Lagna (Ascendant or rising sign) is the zodiac sign rising on the eastern horizon at the time and place of birth. It changes approximately every 2 hours. The Lagna is the foundation of the entire birth chart — it determines the house system, which planets are beneficial (Yoga Karakas), and which are harmful (Functional Malefics). The Lagna lord\'s strength and placement is critical for overall life quality. The Lagna also determines one\'s physical appearance, constitution (Prakriti), and general life approach.',
            ],
            [
                'id' => 508, 'category' => 'concept', 'tags' => [ 'ayurveda', 'constitution', 'vata pitta kapha', 'health astrology' ],
                'title'   => 'Ayurvedic Constitution and Medical Astrology',
                'content' => 'Vedic astrology and Ayurveda are sister sciences. Each zodiac sign corresponds to an Ayurvedic Dosha (body-mind constitution). Vata (air): Gemini, Virgo, Libra, Aquarius, Capricorn. Pitta (fire): Aries, Leo, Sagittarius, Scorpio. Kapha (water/earth): Taurus, Cancer, Pisces. Mars and Sun govern Pitta. Moon and Venus govern Kapha. Mercury and Rahu govern Vata. Malefic planets in the 1st, 6th, 8th, or 12th houses can indicate health vulnerabilities. The 6th house shows disease, the 8th house shows surgery and longevity.',
            ],
        ];
    }
}
