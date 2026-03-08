<?php
/**
 * Membership: Paid Memberships Pro integration + Pricing Table shortcode.
 *
 * Shortcode: [sas_pricing_table]
 *
 * PMPro Level ID → SAS Tier mapping (create these in WP Admin → PMPro → Levels):
 *   1  Seeker        Free, never expires          → SAS_TIER_FREE
 *   2  Sadhak        ₹299/month recurring         → SAS_TIER_CORE
 *   3  Sadhak        ₹2,499/year recurring        → SAS_TIER_CORE
 *   4  Guru          ₹599/month recurring         → SAS_TIER_FULL
 *   5  Guru          ₹4,999/year recurring        → SAS_TIER_FULL
 *
 * Paid members (Core/Full) bypass the 1-free-edit restriction on birth details.
 *
 * Multi-currency: Auto-detects visitor country via Cloudflare header (CF-IPCountry)
 * or ipapi.co fallback. IN → INR, GB/IE → GBP, all others → USD.
 * PMPro currency is overridden in-memory per request via option_pmpro_options filter.
 * USD/GBP prices are stored in sas_settings and admin-configurable.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Membership {

    // ── PMPro Level ID → SAS Tier ────────────────────────────────────────────

    const LEVEL_TIERS = [
        1 => SAS_TIER_FREE,
        2 => SAS_TIER_CORE,   // Sadhak Monthly
        3 => SAS_TIER_CORE,   // Sadhak Annual
        4 => SAS_TIER_FULL,   // Guru Monthly
        5 => SAS_TIER_FULL,   // Guru Annual
    ];

    // Default checkout level for each paid tier (monthly plan)
    const CHECKOUT_LEVEL = [
        SAS_TIER_CORE => 2,
        SAS_TIER_FULL => 4,
    ];

    // ── Boot ─────────────────────────────────────────────────────────────────

    public static function init(): void {
        add_shortcode( 'sas_pricing_table', [ __CLASS__, 'render_pricing_table' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        self::register_pmpro_hooks();
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public static function enqueue_assets(): void {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'sas_pricing_table' ) ) {
            return;
        }

        wp_enqueue_style(
            'sas-pricing',
            SAS_PLUGIN_URL . 'public/css/sas-pricing.css',
            [],
            SAS_VERSION
        );

        wp_enqueue_script(
            'sas-pricing',
            SAS_PLUGIN_URL . 'public/js/sas-pricing.js',
            [],
            SAS_VERSION,
            true
        );

        $user_id  = get_current_user_id();
        $currency = $user_id ? self::get_user_currency() : self::get_user_currency();
        wp_localize_script( 'sas-pricing', 'sasPricing', [
            'loginUrl'       => wp_login_url( get_permalink() ),
            'accountUrl'     => function_exists( 'pmpro_url' ) ? pmpro_url( 'account' ) : home_url( '/membership-account/' ),
            'checkoutUrl'    => function_exists( 'pmpro_url' ) ? pmpro_url( 'checkout' ) : home_url( '/membership-checkout/' ),
            'isLoggedIn'     => is_user_logged_in() ? 'yes' : 'no',
            'currentTier'    => $user_id ? self::get_user_tier( $user_id ) : SAS_TIER_FREE,
            'currency'       => $currency,
            'currencySymbol' => self::get_currency_symbol( $currency ),
        ] );
    }

    // ── Tier resolution ──────────────────────────────────────────────────────

    /**
     * Return the highest SAS tier for a user based on their active PMPro levels.
     * Returns SAS_TIER_FREE if PMPro is not active or user has no levels.
     */
    public static function get_user_tier( int $user_id ): string {
        if ( ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            return SAS_TIER_FREE;
        }

        $levels = pmpro_getMembershipLevelsForUser( $user_id );
        if ( empty( $levels ) ) {
            return SAS_TIER_FREE;
        }

        $tier = SAS_TIER_FREE;
        foreach ( $levels as $level ) {
            $level_tier = self::LEVEL_TIERS[ (int) $level->id ] ?? SAS_TIER_FREE;
            if ( $level_tier === SAS_TIER_FULL ) {
                return SAS_TIER_FULL; // Highest possible — exit early
            }
            if ( $level_tier === SAS_TIER_CORE ) {
                $tier = SAS_TIER_CORE;
            }
        }

        return $tier;
    }

    /** Check if user has at least Core tier (Sadhak or Guru). */
    public static function has_core( int $user_id ): bool {
        return in_array( self::get_user_tier( $user_id ), [ SAS_TIER_CORE, SAS_TIER_FULL ], true );
    }

    /** Check if user has Full tier (Guru). */
    public static function has_full( int $user_id ): bool {
        return self::get_user_tier( $user_id ) === SAS_TIER_FULL;
    }

    /** Build PMPro checkout URL for a given level ID. */
    public static function checkout_url( int $level_id ): string {
        if ( function_exists( 'pmpro_url' ) ) {
            return pmpro_url( 'checkout', '?level=' . $level_id );
        }
        return home_url( '/membership-checkout/?level=' . $level_id );
    }

    // ── Geo + Currency ───────────────────────────────────────────────────────

    /**
     * Detect visitor ISO country code.
     * Priority: Cloudflare CF-IPCountry header → ipapi.co fallback → transient cache (24h).
     */
    public static function get_user_country(): string {
        // Cloudflare header — set automatically by Cloudflare (zero cost)
        $cf = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
        if ( $cf && preg_match( '/^[A-Z]{2}$/', $cf ) ) {
            return strtoupper( $cf );
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Transient cache keyed by IP (24h TTL)
        $cache_key = 'sas_country_' . md5( $ip );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return (string) $cached;
        }

        // ipapi.co fallback (1,000 req/day free tier)
        if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
            $response = wp_remote_get(
                'https://ipapi.co/' . rawurlencode( $ip ) . '/country/',
                [ 'timeout' => 3 ]
            );
            if ( ! is_wp_error( $response ) ) {
                $country = strtoupper( trim( wp_remote_retrieve_body( $response ) ) );
                if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
                    set_transient( $cache_key, $country, DAY_IN_SECONDS );
                    return $country;
                }
            }
        }

        return 'US'; // safe default → USD
    }

    /**
     * Map visitor country to currency code.
     * IN → INR | GB/IE → GBP | all others → USD
     */
    public static function get_user_currency(): string {
        $country = self::get_user_country();
        if ( $country === 'IN' ) {
            return 'INR';
        }
        if ( in_array( $country, [ 'GB', 'IE' ], true ) ) {
            return 'GBP';
        }
        return 'USD';
    }

    /** Currency code → display symbol. */
    public static function get_currency_symbol( string $currency ): string {
        return match ( $currency ) {
            'INR' => '₹',
            'GBP' => '£',
            default => '$',
        };
    }

    /**
     * Return [ level_id => billing_amount ] for a given currency.
     * INR → always live from PMPro (pmpro_getLevel) as source of truth.
     * USD/GBP → from sas_settings with hard-coded fallbacks.
     */
    public static function get_prices_for_currency( string $currency ): array {
        if ( $currency === 'INR' && function_exists( 'pmpro_getLevel' ) ) {
            return [
                2 => (float) ( pmpro_getLevel( 2 )->billing_amount ?? 299 ),
                3 => (float) ( pmpro_getLevel( 3 )->billing_amount ?? 2499 ),
                4 => (float) ( pmpro_getLevel( 4 )->billing_amount ?? 599 ),
                5 => (float) ( pmpro_getLevel( 5 )->billing_amount ?? 4999 ),
            ];
        }

        $key    = strtolower( $currency ) . '_prices';
        $s      = sas_get_settings();
        $prices = $s[ $key ] ?? [];

        return [
            2 => (float) ( $prices['sadhak_monthly'] ?? ( $currency === 'GBP' ? 3.49  : 3.99  ) ),
            3 => (float) ( $prices['sadhak_annual']  ?? ( $currency === 'GBP' ? 34.99 : 39.99 ) ),
            4 => (float) ( $prices['guru_monthly']   ?? ( $currency === 'GBP' ? 6.99  : 7.99  ) ),
            5 => (float) ( $prices['guru_annual']    ?? ( $currency === 'GBP' ? 69.99 : 79.99 ) ),
        ];
    }

    /**
     * Register PMPro filters to override currency and billing amounts per request.
     * Uses only free WordPress hooks — no PMPro paid add-ons required.
     */
    public static function register_pmpro_hooks(): void {
        // Override PMPro's stored global currency in-memory (no DB modification)
        add_filter( 'option_pmpro_options', function ( $options ) {
            $currency = self::get_user_currency();
            if ( $currency !== 'INR' ) {
                $options['currency']        = $currency;
                $options['currency_symbol'] = self::get_currency_symbol( $currency );
            }
            return $options;
        } );

        // Override billing_amount and initial_payment at checkout time
        add_filter( 'pmpro_checkout_level', function ( $level ) {
            if ( empty( $level->id ) ) {
                return $level;
            }
            $currency = self::get_user_currency();
            if ( $currency === 'INR' ) {
                return $level;
            }
            $prices = self::get_prices_for_currency( $currency );
            $amount = $prices[ (int) $level->id ] ?? null;
            if ( $amount !== null ) {
                $level->billing_amount  = $amount;
                $level->initial_payment = $amount;
                $level->trial_amount    = 0;
            }
            return $level;
        } );
    }

    // ── Pricing table shortcode ──────────────────────────────────────────────

    public static function render_pricing_table(): string {
        $user_id      = get_current_user_id();
        $current_tier = $user_id ? self::get_user_tier( $user_id ) : SAS_TIER_FREE;

        // Currency + prices for this visitor
        $currency = self::get_user_currency();
        $symbol   = self::get_currency_symbol( $currency );
        $prices   = self::get_prices_for_currency( $currency );
        $decimals = ( $currency === 'INR' ) ? 0 : 2;

        $p_sadhak_mo    = number_format( $prices[2], $decimals );
        $p_sadhak_yr    = number_format( $prices[3], $decimals );
        $p_guru_mo      = number_format( $prices[4], $decimals );
        $p_guru_yr      = number_format( $prices[5], $decimals );
        $p_sadhak_mo_eq = number_format( $prices[3] / 12, $decimals );
        $p_guru_mo_eq   = number_format( $prices[5] / 12, $decimals );

        // CTA destinations
        $login_url   = wp_login_url( get_permalink() );
        $account_url = function_exists( 'pmpro_url' ) ? pmpro_url( 'account' ) : home_url( '/membership-account/' );
        $seeker_url  = $user_id ? $account_url : $login_url;
        $sadhak_mo   = self::checkout_url( 2 );
        $sadhak_yr   = self::checkout_url( 3 );
        $guru_mo     = self::checkout_url( 4 );
        $guru_yr     = self::checkout_url( 5 );

        $seeker_active = ( $current_tier === SAS_TIER_FREE );
        $sadhak_active = ( $current_tier === SAS_TIER_CORE );
        $guru_active   = ( $current_tier === SAS_TIER_FULL );

        ob_start();
        ?>
        <div class="sas-pricing-wrap">

            <!-- ── Header ──────────────────────────────────────────── -->
            <div class="sas-pricing-header">
                <p class="sas-pricing-eyebrow">&#10024; Membership Plans</p>
                <h2 class="sas-pricing-title">Choose Your Vedic Path</h2>
                <p class="sas-pricing-subtitle">Unlock your complete cosmic blueprint with personalised Vedic guidance in 9 languages</p>

                <!-- Monthly / Annual toggle -->
                <div class="sas-pricing-toggle">
                    <span class="sas-pt-toggle-label" id="sas-pt-monthly-lbl">Monthly</span>
                    <button
                        class="sas-pt-toggle-btn"
                        id="sas-pt-toggle"
                        role="switch"
                        aria-checked="false"
                        title="Switch billing period"
                    ><span class="sas-pt-toggle-knob"></span></button>
                    <span class="sas-pt-toggle-label" id="sas-pt-annual-lbl">
                        Annual <span class="sas-pt-save-pill">Save 30%</span>
                    </span>
                </div>
            </div>

            <!-- ── Cards grid ────────────────────────────────────── -->
            <div class="sas-pricing-grid">

                <!-- ────────────────── Seeker (Free) ─────────────── -->
                <div class="sas-pt-card sas-pt-card--seeker<?php echo $seeker_active ? ' sas-pt-card--current' : ''; ?>">
                    <?php if ( $seeker_active ) : ?>
                        <div class="sas-pt-current-badge">&#9989; Your Current Plan</div>
                    <?php endif; ?>

                    <div class="sas-pt-card-head">
                        <span class="sas-pt-emoji">&#127775;</span>
                        <h3 class="sas-pt-plan-name">Seeker</h3>
                        <p class="sas-pt-tagline">Begin your Vedic journey</p>
                    </div>

                    <div class="sas-pt-price-box">
                        <div class="sas-pt-price-row">
                            <span class="sas-pt-currency"><?php echo esc_html( $symbol ); ?></span>
                            <span class="sas-pt-amount">0</span>
                        </div>
                        <p class="sas-pt-period">Free forever</p>
                    </div>

                    <a href="<?php echo esc_url( $seeker_url ); ?>"
                       class="sas-pt-cta sas-pt-cta--ghost">
                        <?php
                        if ( $seeker_active ) {
                            echo 'Your Current Plan';
                        } elseif ( $user_id ) {
                            echo 'Go to Account';
                        } else {
                            echo 'Start for Free';
                        }
                        ?>
                    </a>

                    <ul class="sas-pt-features">
                        <li class="sas-pt-feat sas-pt-feat--yes">Daily &amp; Weekly horoscope</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">All 12 zodiac signs</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">9 Indian languages</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">110+ Hindu AI Tools</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Community access</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Yearly predictions</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Kundali birth chart</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Guruji AI chat</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Push alerts</li>
                    </ul>
                </div>

                <!-- ────────────────── Sadhak (Core) ─────────────── -->
                <div class="sas-pt-card sas-pt-card--sadhak sas-pt-card--featured<?php echo $sadhak_active ? ' sas-pt-card--current' : ''; ?>">
                    <?php if ( $sadhak_active ) : ?>
                        <div class="sas-pt-current-badge">&#9989; Your Current Plan</div>
                    <?php else : ?>
                        <div class="sas-pt-popular-badge">&#11088; Most Popular</div>
                    <?php endif; ?>

                    <div class="sas-pt-card-head">
                        <span class="sas-pt-emoji">&#127760;</span>
                        <h3 class="sas-pt-plan-name">Sadhak</h3>
                        <p class="sas-pt-tagline">For the dedicated seeker</p>
                    </div>

                    <div class="sas-pt-price-box">
                        <div class="sas-pt-price-row">
                            <span class="sas-pt-currency"><?php echo esc_html( $symbol ); ?></span>
                            <span class="sas-pt-amount sas-pt-monthly-price"><?php echo esc_html( $p_sadhak_mo ); ?></span>
                            <span class="sas-pt-amount sas-pt-annual-price" hidden><?php echo esc_html( $p_sadhak_mo_eq ); ?></span>
                        </div>
                        <p class="sas-pt-period sas-pt-monthly-period">/month</p>
                        <p class="sas-pt-period sas-pt-annual-period" hidden>
                            /month &middot; <?php echo esc_html( $symbol . $p_sadhak_yr ); ?> billed annually
                        </p>
                    </div>

                    <a href="<?php echo esc_url( $sadhak_mo ); ?>"
                       class="sas-pt-cta sas-pt-cta--primary"
                       data-monthly="<?php echo esc_url( $sadhak_mo ); ?>"
                       data-annual="<?php echo esc_url( $sadhak_yr ); ?>">
                        <?php echo $sadhak_active ? 'Manage Subscription' : 'Join Sadhak &#127760;'; ?>
                    </a>

                    <ul class="sas-pt-features">
                        <li class="sas-pt-feat sas-pt-feat--inherit">Everything in Seeker, plus:</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Yearly predictions</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Kundali birth chart (core)</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">&#129302; Guruji AI personal chat</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Unlimited birth detail edits</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Priority Vedic guidance</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Full dosha analysis</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Daily push alerts</li>
                        <li class="sas-pt-feat sas-pt-feat--no">Guru badge</li>
                    </ul>
                </div>

                <!-- ────────────────── Guru (Full) ────────────────── -->
                <div class="sas-pt-card sas-pt-card--guru<?php echo $guru_active ? ' sas-pt-card--current' : ''; ?>">
                    <?php if ( $guru_active ) : ?>
                        <div class="sas-pt-current-badge">&#9989; Your Current Plan</div>
                    <?php endif; ?>

                    <div class="sas-pt-card-head">
                        <span class="sas-pt-emoji">&#128591;</span>
                        <h3 class="sas-pt-plan-name">Guru</h3>
                        <p class="sas-pt-tagline">Full Vedic cosmic mastery</p>
                    </div>

                    <div class="sas-pt-price-box">
                        <div class="sas-pt-price-row">
                            <span class="sas-pt-currency"><?php echo esc_html( $symbol ); ?></span>
                            <span class="sas-pt-amount sas-pt-monthly-price"><?php echo esc_html( $p_guru_mo ); ?></span>
                            <span class="sas-pt-amount sas-pt-annual-price" hidden><?php echo esc_html( $p_guru_mo_eq ); ?></span>
                        </div>
                        <p class="sas-pt-period sas-pt-monthly-period">/month</p>
                        <p class="sas-pt-period sas-pt-annual-period" hidden>
                            /month &middot; <?php echo esc_html( $symbol . $p_guru_yr ); ?> billed annually
                        </p>
                    </div>

                    <a href="<?php echo esc_url( $guru_mo ); ?>"
                       class="sas-pt-cta sas-pt-cta--gold"
                       data-monthly="<?php echo esc_url( $guru_mo ); ?>"
                       data-annual="<?php echo esc_url( $guru_yr ); ?>">
                        <?php echo $guru_active ? 'Manage Subscription' : 'Become Guru &#128591;'; ?>
                    </a>

                    <ul class="sas-pt-features">
                        <li class="sas-pt-feat sas-pt-feat--inherit">Everything in Sadhak, plus:</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Full dosha analysis &amp; remedies</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Daily push alerts</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Fastest Guruji responses</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">&#127775; Guru badge in community</li>
                        <li class="sas-pt-feat sas-pt-feat--yes">Early access to new features</li>
                    </ul>
                </div>

            </div><!-- .sas-pricing-grid -->

            <!-- ── Trust bar ──────────────────────────────────────── -->
            <div class="sas-pricing-trust">
                <span>&#128274; Secure Payments via Stripe</span>
                <span>&#128241; Works on Web &amp; App</span>
                <span>&#8617;&#65039; Cancel anytime</span>
                <span>&#128179; <?php echo esc_html( $currency ); ?> pricing</span>
            </div>

        </div><!-- .sas-pricing-wrap -->
        <?php
        return ob_get_clean();
    }
}
