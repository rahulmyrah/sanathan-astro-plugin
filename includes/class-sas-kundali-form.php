<?php
/**
 * Kundali birth profile form shortcode.
 *
 * Shortcode: [sas_kundali_form]
 *
 * Three states:
 *   1 - Guest        : login prompt card
 *   2 - No Kundali   : setup form with amber accuracy warning
 *   3 - Has Kundali  : summary card (Zodiac/Moon/Ascendant) + collapsible edit section
 *
 * Edit policy (user meta key: sas_birth_edits):
 *   0  -> 1 free edit remaining  (initial setup does NOT count)
 *   >=1 -> blocked, upgrade notice shown
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAS_Kundali_Form {

    public static function init(): void {
        add_shortcode( 'sas_kundali_form', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    // ── Asset enqueue ─────────────────────────────────────────────────────────

    public static function enqueue_assets(): void {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'sas_kundali_form' ) ) {
            return;
        }

        wp_enqueue_style(
            'sas-kundali-form',
            SAS_PLUGIN_URL . 'public/css/sas-kundali-form.css',
            [],
            SAS_VERSION
        );

        wp_enqueue_script(
            'sas-kundali-form',
            SAS_PLUGIN_URL . 'public/js/sas-kundali-form.js',
            [],
            SAS_VERSION,
            true
        );

        // Localise data for JS
        $js_data = [
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'restUrl'    => rest_url( 'sanathan/v1/' ),
            'loginUrl'   => wp_login_url( get_permalink() ),
            'upgradeUrl' => home_url( '/membership-upgrade/' ),
            'siteUrl'    => home_url(),
            'exists'     => false,
            'editsUsed'  => 0,
        ];

        if ( is_user_logged_in() ) {
            $profile             = SAS_Kundali::get_birth_profile( get_current_user_id() );
            $js_data['exists']   = ! empty( $profile['exists'] );
            $js_data['editsUsed']= (int) ( $profile['edits_used'] ?? 0 );
        }

        wp_localize_script( 'sas-kundali-form', 'sasKF', $js_data );
    }

    // ── Shortcode renderer ────────────────────────────────────────────────────

    public static function render( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return self::render_guest();
        }

        $profile = SAS_Kundali::get_birth_profile( get_current_user_id() );

        if ( empty( $profile['exists'] ) ) {
            return self::render_setup_form();
        }

        return self::render_kundali_card( $profile );
    }

    // ── State 1: Guest ────────────────────────────────────────────────────────

    private static function render_guest(): string {
        $login_url = wp_login_url( get_permalink() );
        ob_start();
        ?>
        <div class="sas-kf-wrap">
            <div class="sas-kf-card sas-kf-card--center">
                <div class="sas-kf-guest-icon">&#128272;</div>
                <h2 class="sas-kf-title">Set Up Your Personal Kundali</h2>
                <p class="sas-kf-subtitle">
                    Login to enter your birth details and receive your complete Vedic birth chart
                    with personalised Guruji guidance in your language.
                </p>
                <a href="<?php echo esc_url( $login_url ); ?>" class="sas-kf-btn sas-kf-btn--primary">
                    Login to Continue
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── State 2: Setup form ───────────────────────────────────────────────────

    private static function render_setup_form(): string {
        ob_start();
        ?>
        <div class="sas-kf-wrap">
            <div class="sas-kf-card">
                <h2 class="sas-kf-title">&#127756; Set Up Your Birth Profile</h2>

                <div class="sas-kf-warning">
                    <strong>&#9888;&#65039; Please Read Before Proceeding</strong><br>
                    Your birth details are the foundation of your entire Kundali, Zodiac, and Guruji guidance.
                    Please ensure they are <strong>accurate</strong>. You may change these
                    <strong>once for free</strong> &#8212; further changes require a Premium upgrade.
                </div>

                <form id="sas-kf-form" data-mode="create" novalidate>
                    <?php self::render_form_fields( [] ); ?>
                    <button type="submit" class="sas-kf-btn sas-kf-btn--primary sas-kf-btn--full" id="sas-kf-submit">
                        Generate My Kundali &#127756;
                    </button>
                    <div id="sas-kf-msg" class="sas-kf-msg" aria-live="polite" role="status"></div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── State 3: Kundali card + edit section ─────────────────────────────────

    private static function render_kundali_card( array $profile ): string {
        $edits_used = (int) ( $profile['edits_used'] ?? 0 );
        $can_edit   = ( $edits_used < 1 );

        ob_start();
        ?>
        <div class="sas-kf-wrap">
            <div class="sas-kf-card">

                <!-- Summary header -->
                <div class="sas-kf-summary-header">
                    <div class="sas-kf-summary-title">&#127756; Your Birth Profile</div>
                    <div class="sas-kf-summary-name"><?php echo esc_html( $profile['name'] ); ?></div>
                </div>

                <!-- Planet chips -->
                <div class="sas-kf-chips">
                    <?php
                    $chips = [
                        [ '&#9728;&#65039;', 'Zodiac Sign',   $profile['zodiac']        ?? '' ],
                        [ '&#127769;',       'Moon Sign',     $profile['moon_sign']     ?? '' ],
                        [ '&#11014;&#65039;','Ascendant',     $profile['ascendant']     ?? '' ],
                        [ '&#128197;',       'Date of Birth', $profile['dob']           ?? '' ],
                        [ '&#9200;',         'Time of Birth', $profile['tob']           ?? '' ],
                        [ '&#128205;',       'Location',      $profile['location_name'] ?? '' ],
                    ];
                    foreach ( $chips as $chip ) :
                    ?>
                    <div class="sas-kf-chip">
                        <span class="sas-kf-chip-icon"><?php echo $chip[0]; ?></span>
                        <div class="sas-kf-chip-body">
                            <div class="sas-kf-chip-label"><?php echo esc_html( $chip[1] ); ?></div>
                            <div class="sas-kf-chip-value"><?php echo esc_html( $chip[2] ?: '&#8212;' ); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Edit section -->
                <div class="sas-kf-edit-section">
                    <button type="button" class="sas-kf-edit-toggle" id="sas-kf-edit-toggle">
                        &#9998;&#65039; Edit Birth Details
                    </button>

                    <div id="sas-kf-edit-panel" class="sas-kf-edit-panel" hidden>
                        <?php if ( $can_edit ) : ?>
                            <div class="sas-kf-edit-badge sas-kf-edit-badge--ok">
                                &#9989; 1 free edit remaining
                            </div>
                            <form id="sas-kf-form" data-mode="edit" novalidate>
                                <?php self::render_form_fields( $profile ); ?>
                                <button type="submit" class="sas-kf-btn sas-kf-btn--primary sas-kf-btn--full" id="sas-kf-submit">
                                    Update My Birth Profile &#9998;&#65039;
                                </button>
                                <div id="sas-kf-msg" class="sas-kf-msg" aria-live="polite" role="status"></div>
                            </form>
                        <?php else : ?>
                            <div class="sas-kf-edit-badge sas-kf-edit-badge--locked">
                                &#128274; No free edits remaining
                            </div>
                            <p class="sas-kf-upgrade-text">
                                You have used your free edit. Upgrade to Premium to change your birth
                                details again and unlock the full Kundali analysis.
                            </p>
                            <a href="<?php echo esc_url( home_url( '/membership-upgrade/' ) ); ?>"
                               class="sas-kf-btn sas-kf-btn--upgrade sas-kf-btn--full">
                                &#10024; Upgrade to Premium
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shared form fields ────────────────────────────────────────────────────

    private static function render_form_fields( array $prefill ): void {
        $name     = esc_attr( $prefill['name']          ?? '' );
        $dob_raw  = $prefill['dob']                     ?? ''; // stored as d/m/Y
        $tob      = esc_attr( $prefill['tob']           ?? '' );
        $loc_name = esc_attr( $prefill['location_name'] ?? '' );
        $lat      = esc_attr( (string) ( $prefill['lat'] ?? '' ) );
        $lon      = esc_attr( (string) ( $prefill['lon'] ?? '' ) );
        $tz       = esc_attr( (string) ( $prefill['tz']  ?? '' ) );

        // Convert d/m/Y to Y-m-d for the HTML date input
        $dob_input = '';
        if ( $dob_raw ) {
            $dt = DateTime::createFromFormat( 'd/m/Y', $dob_raw );
            if ( $dt ) {
                $dob_input = $dt->format( 'Y-m-d' );
            }
        }
        ?>
        <div class="sas-kf-field">
            <label for="sas-kf-name">
                Full Name <span class="sas-kf-required" aria-label="required">*</span>
            </label>
            <input
                type="text"
                id="sas-kf-name"
                name="name"
                value="<?php echo $name; ?>"
                required
                placeholder="e.g. Rahul Sharma"
                autocomplete="name"
            >
        </div>

        <div class="sas-kf-field-row">
            <div class="sas-kf-field">
                <label for="sas-kf-dob">
                    Date of Birth <span class="sas-kf-required" aria-label="required">*</span>
                </label>
                <input
                    type="date"
                    id="sas-kf-dob"
                    name="dob"
                    value="<?php echo esc_attr( $dob_input ); ?>"
                    required
                    max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
                >
            </div>

            <div class="sas-kf-field">
                <label for="sas-kf-tob">Time of Birth</label>
                <input
                    type="time"
                    id="sas-kf-tob"
                    name="tob"
                    value="<?php echo $tob; ?>"
                    placeholder="14:30"
                >
                <label class="sas-kf-checkbox-label">
                    <input type="checkbox" id="sas-kf-tob-unknown">
                    I don&#39;t know the exact time
                </label>
            </div>
        </div>

        <div class="sas-kf-field sas-kf-field--location">
            <label for="sas-kf-location">
                Place of Birth <span class="sas-kf-required" aria-label="required">*</span>
            </label>
            <div class="sas-kf-location-wrapper">
                <input
                    type="text"
                    id="sas-kf-location"
                    name="location_name"
                    value="<?php echo $loc_name; ?>"
                    required
                    placeholder="e.g. Mumbai, India"
                    autocomplete="off"
                    aria-autocomplete="list"
                    aria-controls="sas-kf-location-dropdown"
                >
                <div
                    id="sas-kf-location-dropdown"
                    class="sas-kf-location-dropdown"
                    role="listbox"
                    aria-label="Location suggestions"
                    style="display:none;"
                ></div>
            </div>
            <input type="hidden" id="sas-kf-lat" name="lat" value="<?php echo $lat; ?>">
            <input type="hidden" id="sas-kf-lon" name="lon" value="<?php echo $lon; ?>">
            <input type="hidden" id="sas-kf-tz"  name="tz"  value="<?php echo $tz;  ?>">
        </div>
        <?php
    }
}
