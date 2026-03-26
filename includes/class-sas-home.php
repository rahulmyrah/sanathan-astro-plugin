<?php
/**
 * SAS Home Page Shortcodes
 *
 * Provides 6 shortcodes for the Sanathan home page, assembled in Elementor.
 * Handles logged-in vs logged-out states via PHP, queries AI Tools, Events, Services.
 *
 * @package SananthanAstroServices
 * @since   1.4.8
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SAS_Home {

	/** Category emoji map for Directorist service categories */
	private static $service_emojis = [
		'temples-mandirs'     => '🛕',
		'classical-dance'     => '💃',
		'classical-music'     => '🎵',
		'yoga-meditation'     => '🧘',
		'vedic-education'     => '📚',
		'jyotish-astrology'   => '⭐',
		'ayurveda-healing'    => '🌿',
		'puja-ritual-services'=> '🪔',
		'hindu-arts-crafts'   => '🎨',
	];

	/**
	 * Register shortcodes and enqueue hook.
	 */
	public static function init() {
		add_shortcode( 'sas_home_hero',             [ __CLASS__, 'render_hero' ] );
		add_shortcode( 'sas_home_features',         [ __CLASS__, 'render_features' ] );
		add_shortcode( 'sas_home_tools_preview',    [ __CLASS__, 'render_tools_preview' ] );
		add_shortcode( 'sas_home_events_preview',   [ __CLASS__, 'render_events_preview' ] );
		add_shortcode( 'sas_home_services_preview', [ __CLASS__, 'render_services_preview' ] );
		add_shortcode( 'sas_home_cta',              [ __CLASS__, 'render_cta' ] );
		add_shortcode( 'sas_home_daily_dashboard',  [ __CLASS__, 'render_daily_dashboard' ] );
		add_shortcode( 'sas_guruji_page',           [ __CLASS__, 'render_guruji_page' ] );
		add_shortcode( 'sas_home_chat',             [ __CLASS__, 'render_home_chat' ] );
		add_action( 'wp_enqueue_scripts',            [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_footer',                     [ __CLASS__, 'render_guruji_float' ] );
		// Prevent page-cache plugins from serving a guest-cached page to logged-in users
		add_action( 'template_redirect',             [ __CLASS__, 'maybe_nocache' ] );

		// Fix Directorist single-category page: replace "Single Category" with real term name
		add_filter( 'the_title',              [ __CLASS__, 'fix_directorist_category_title' ], 20, 2 );
		add_filter( 'document_title_parts',   [ __CLASS__, 'fix_directorist_document_title' ], 20 );
	}

	/* ─────────────────────────────────────────
	 * CACHE PREVENTION FOR LOGGED-IN USERS
	 * Sends HTTP no-cache headers and sets the DONOTCACHEPAGE constant
	 * so that full-page caching plugins (WP Rocket, W3TC, LiteSpeed Cache,
	 * WP Super Cache) do not serve a guest-cached page to logged-in users.
	 * This prevents login-state-sensitive content (hero, CTA, Guruji chat)
	 * from appearing in the wrong state.
	 * ───────────────────────────────────────── */
	public static function maybe_nocache(): void {
		if ( is_user_logged_in() && ! is_admin() ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}

	/* ─────────────────────────────────────────
	 * DIRECTORIST CATEGORY TITLE FIX
	 * Replaces the generic "Single Category" page title with the real
	 * Directorist category term name when browsing /single-category.../slug/
	 * ───────────────────────────────────────── */

	/**
	 * Detect the Directorist category slug from REQUEST_URI and return its term name.
	 * Returns null if not on a Directorist category URL.
	 *
	 * @return string|null
	 */
	private static function get_directorist_category_name_from_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		// Matches /single-category[…]/some-slug/ (any variant of the page slug)
		if ( preg_match( '~/single-category[^/]*/([^/?\s]+)~i', $uri, $m ) ) {
			$cat_slug = sanitize_title( $m[1] );
			$term     = get_term_by( 'slug', $cat_slug, 'at_biz_dir-category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		return null;
	}

	/**
	 * Filter: swap "Single Category" in page title/hero/breadcrumb with real category name.
	 *
	 * @param string $title   Current title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function fix_directorist_category_title( $title, $post_id ) {
		if ( is_admin() || wp_doing_ajax() ) return $title;

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'page' ) return $title;
		if ( strpos( $post->post_name, 'single-category' ) === false ) return $title;

		$name = self::get_directorist_category_name_from_url();
		return $name ?: $title;
	}

	/**
	 * Filter: fix browser tab / SEO document title for Directorist category pages.
	 *
	 * @param array $parts Title parts array.
	 * @return array
	 */
	public static function fix_directorist_document_title( $parts ) {
		if ( is_admin() ) return $parts;

		global $post;
		if ( ! $post || strpos( $post->post_name, 'single-category' ) === false ) return $parts;

		$name = self::get_directorist_category_name_from_url();
		if ( $name ) {
			$parts['title'] = $name;
		}
		return $parts;
	}

	/**
	 * Enqueue home CSS/JS on the front page; Guruji float on all frontend pages.
	 */
	public static function enqueue_assets(): void {
		// ── Guruji Float: load on ALL frontend pages ────────────────────────
		if ( ! is_admin() ) {
			$config = self::get_frontend_config(); // compute once, reuse below
			wp_enqueue_style(
				'sas-guruji-float',
				SAS_PLUGIN_URL . 'public/css/sas-guruji-float.css',
				[],
				SAS_VERSION
			);
			wp_enqueue_script(
				'sas-guruji-float',
				SAS_PLUGIN_URL . 'public/js/sas-guruji-float.js',
				[],
				SAS_VERSION,
				true
			);
			// Inject shared config for all pages
			wp_localize_script( 'sas-guruji-float', 'sasConfig', $config );
		}

		// ── Home page only: dashboard CSS/JS ───────────────────────────────
		if ( ! is_front_page() && ! is_page( 'home' ) ) {
			return;
		}
		wp_enqueue_style(
			'sas-home',
			SAS_PLUGIN_URL . 'public/css/sas-home.css',
			[],
			SAS_VERSION
		);
		wp_enqueue_script(
			'sas-home',
			SAS_PLUGIN_URL . 'public/js/sas-home.js',
			[],
			SAS_VERSION,
			true
		);
		wp_enqueue_script(
			'sas-dashboard',
			SAS_PLUGIN_URL . 'public/js/sas-dashboard.js',
			[],
			SAS_VERSION,
			true
		);
		// Override config on the homepage (dashboard JS needs it too)
		wp_localize_script( 'sas-dashboard', 'sasConfig', $config );

		// ── Homepage chat ([sas_home_chat]) ─────────────────────────────────
		wp_enqueue_style(
			'sas-home-chat',
			SAS_PLUGIN_URL . 'public/css/sas-home-chat.css',
			[],
			SAS_VERSION
		);
		wp_enqueue_script(
			'sas-home-chat',
			SAS_PLUGIN_URL . 'public/js/sas-home-chat.js',
			[],
			SAS_VERSION,
			true
		);
		// Chat JS needs sasConfig (it reads restBase, nonce, isLoggedIn, userZodiac, etc.)
		wp_localize_script( 'sas-home-chat', 'sasConfig', $config );

		// Kundali form assets: must be explicitly loaded here because the form is
		// embedded via do_shortcode() from PHP — has_shortcode() won't detect it
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
	}

	/**
	 * Build the sasConfig JS object used by dashboard and Guruji float scripts.
	 *
	 * @return array
	 */
	private static function get_frontend_config(): array {
		$is_logged_in  = is_user_logged_in();
		$has_kundali   = false;
		$user_zodiac   = null;

		if ( $is_logged_in ) {
			$user_id = get_current_user_id();
			// Check if user has an English kundali record (EN is always stored)
			global $wpdb;
			$table  = $wpdb->prefix . 'sanathan_kundali';
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND lang = 'en' LIMIT 1",
				$user_id
			) );
			if ( $exists ) {
				$has_kundali = true;
				// Get the zodiac from birth profile (uses SAS_Kundali::extract_planet_sign)
				if ( class_exists( 'SAS_Kundali' ) ) {
					$profile = SAS_Kundali::get_birth_profile( $user_id );
					if ( ! empty( $profile['zodiac'] ) ) {
						$user_zodiac = strtolower( $profile['zodiac'] );
					}
				}
			}
		}

		return [
			'restBase'    => esc_url_raw( rest_url( 'sanathan/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn'  => $is_logged_in,
			'hasKundali'  => $has_kundali,
			'userZodiac'  => $user_zodiac,
			'defaultLang' => 'en',
			'loginUrl'    => esc_url( wp_login_url( home_url( '/' ) ) ),
			'registerUrl' => esc_url( wp_registration_url() ),
			'kundaliUrl'   => esc_url( home_url( '/kundali/' ) ),
			'gurujiUrl'    => esc_url( home_url( '/guruji/' ) ),
			'isGurujiPage' => is_page( 'guruji' ),
		];
	}

	/* ─────────────────────────────────────────
	 * HERO SECTION
	 * ───────────────────────────────────────── */
	public static function render_hero( $atts ) {
		ob_start();
		if ( is_user_logged_in() ) :
			$user      = wp_get_current_user();
			$name      = esc_html( $user->display_name ?: $user->user_login );
			$guruji    = home_url( '/guruji/' );
			$kundali   = home_url( '/kundali/' );
			$tools_url = home_url( '/ai-tools/' );
			?>
			<div class="sas-hero-home sas-hero-logged-in">
				<div class="sas-hero-inner">
					<div class="sas-hero-greeting-badge">🙏 Jai Shri Ram</div>
					<h1 class="sas-hero-loggedin-title">
						Welcome back, <span class="sas-hero-name"><?php echo $name; ?></span>
					</h1>
					<p class="sas-hero-loggedin-sub">Your Sanathan journey continues. What would you like to explore today?</p>
					<div class="sas-hero-quick-links">
						<a href="<?php echo esc_url( $guruji ); ?>" class="sas-quick-link">
							<span class="sas-ql-icon">🤖</span>
							<span>Chat with Guruji</span>
						</a>
						<a href="<?php echo esc_url( $kundali ); ?>" class="sas-quick-link">
							<span class="sas-ql-icon">📿</span>
							<span>My Kundali</span>
						</a>
						<a href="<?php echo esc_url( $tools_url ); ?>" class="sas-quick-link">
							<span class="sas-ql-icon">🛠️</span>
							<span>Hindu Tools</span>
						</a>
						<a href="<?php echo esc_url( home_url( '/dharma-events/' ) ); ?>" class="sas-quick-link">
							<span class="sas-ql-icon">📅</span>
							<span>Events</span>
						</a>
					</div>
				</div>
			</div>
		<?php else : ?>
			<div class="sas-hero-home sas-hero-logged-out">
				<div class="sas-hero-inner">
					<div class="sas-hero-eyebrow">🇮🇳 Sanathan Hindu Platform</div>
					<h1 class="sas-hero-title">
						Discover the<br><span class="sas-hero-accent">Sanathan Way</span> 🙏
					</h1>
					<p class="sas-hero-subtitle">
						Your complete Vedic companion — AI Guruji, 110+ Hindu Tools, Kundali,
						Events, Dharma Services, and a thriving community. In your own language.
					</p>
					<div class="sas-hero-stats">
						<div class="sas-stat-pill">
							<strong class="sas-stat-num" data-count="110">110</strong>
							<span>+ Hindu Tools</span>
						</div>
						<div class="sas-stat-pill">
							<strong class="sas-stat-num" data-count="9">9</strong>
							<span>Indian Languages</span>
						</div>
						<div class="sas-stat-pill">
							<strong>✨</strong>
							<span>Vedic AI Guruji</span>
						</div>
						<div class="sas-stat-pill">
							<strong>🆓</strong>
							<span>Free to Join</span>
						</div>
					</div>
					<div class="sas-hero-cta-group">
						<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="sas-btn sas-btn-primary">
							Register Free →
						</a>
						<a href="<?php echo esc_url( wp_login_url() ); ?>" class="sas-btn sas-btn-ghost">
							Sign In
						</a>
					</div>
					<p class="sas-hero-fine">No credit card required · Free forever for basic access</p>
				</div>
				<div class="sas-hero-bg-shapes" aria-hidden="true">
					<div class="sas-shape sas-shape-1"></div>
					<div class="sas-shape sas-shape-2"></div>
					<div class="sas-shape sas-shape-3"></div>
				</div>
			</div>
		<?php endif;
		return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * FEATURES GRID
	 * ───────────────────────────────────────── */
	public static function render_features( $atts ) {
		$features = [
			[
				'icon'  => '🤖',
				'title' => 'Guruji AI',
				'desc'  => 'Your personal Vedic AI advisor. Ask anything about dharma, astrology, mantras, and life guidance in your own language.',
				'link'  => home_url( '/guruji/' ),
				'color' => '#7B2D8B',
			],
			[
				'icon'  => '🛠️',
				'title' => 'Hindu Tools',
				'desc'  => '110+ AI-powered tools for Kundali analysis, mantra recommendations, vaastu remedies, festival guides, and more.',
				'link'  => home_url( '/ai-tools/' ),
				'color' => '#E8891A',
			],
			[
				'icon'  => '📿',
				'title' => 'Vedic Kundali',
				'desc'  => 'Generate your complete birth chart with dosha analysis, planetary positions, remedies, and personalised guidance.',
				'link'  => home_url( '/kundali/' ),
				'color' => '#D4A843',
			],
			[
				'icon'  => '📅',
				'title' => 'Dharma Events',
				'desc'  => 'Discover festivals, pujas, satsangs, and community gatherings. Never miss an auspicious occasion again.',
				'link'  => home_url( '/dharma-events/' ),
				'color' => '#E8891A',
			],
			[
				'icon'  => '🏛️',
				'title' => 'Dharma Services',
				'desc'  => 'Find temples, classical dance gurus, yoga centers, pandits, Ayurveda healers, and more in your community.',
				'link'  => home_url( '/dharma-services/' ),
				'color' => '#7B2D8B',
			],
			[
				'icon'  => '👥',
				'title' => 'Sanathan Community',
				'desc'  => 'Connect with Hindus worldwide. Share, discuss, learn, and celebrate our rich cultural heritage together.',
				'link'  => home_url( '/members/' ),
				'color' => '#D4A843',
			],
		];

		ob_start(); ?>
		<div class="sas-home-section sas-features-section">
			<div class="sas-container">
				<div class="sas-section-header">
					<h2 class="sas-section-title">Everything on Your Sanathan Journey</h2>
					<p class="sas-section-subtitle">Six pillars of the Sanathan platform — designed for every Hindu, everywhere.</p>
				</div>
				<div class="sas-features-grid">
					<?php foreach ( $features as $f ) : ?>
					<div class="sas-feature-card sas-animate-up">
						<div class="sas-feature-icon" style="background: <?php echo esc_attr( $f['color'] ); ?>22; color: <?php echo esc_attr( $f['color'] ); ?>">
							<?php echo $f['icon']; ?>
						</div>
						<h3 class="sas-feature-title"><?php echo esc_html( $f['title'] ); ?></h3>
						<p class="sas-feature-desc"><?php echo esc_html( $f['desc'] ); ?></p>
						<a href="<?php echo esc_url( $f['link'] ); ?>" class="sas-feature-link">
							Explore <?php echo esc_html( $f['title'] ); ?> <span>→</span>
						</a>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * HINDU TOOLS PREVIEW
	 * ───────────────────────────────────────── */
	public static function render_tools_preview( $atts ) {
		$tools = new WP_Query( [
			'post_type'      => 'ai-toolai_tool',
			'posts_per_page' => 6,
			'orderby'        => 'rand',
			'post_status'    => 'publish',
		] );

		ob_start(); ?>
		<div class="sas-home-section sas-tools-preview-section sas-alt-bg">
			<div class="sas-container">
				<div class="sas-section-header sas-section-header--split">
					<div>
						<h2 class="sas-section-title">🛠️ Hindu Tools</h2>
						<p class="sas-section-subtitle">AI-powered guides rooted in Vedic shastra — try one now</p>
					</div>
					<a href="<?php echo esc_url( home_url( '/ai-tools/' ) ); ?>" class="sas-section-view-all">
						View All 110+ Tools →
					</a>
				</div>

				<?php if ( $tools->have_posts() ) : ?>
				<div class="sas-preview-grid">
					<?php while ( $tools->have_posts() ) : $tools->the_post();
						$cats      = get_the_terms( get_the_ID(), 'ai_tool_category' );
						$cat_name  = $cats && ! is_wp_error( $cats ) ? esc_html( $cats[0]->name ) : '';
						$thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'medium' );
						$link      = get_permalink();
					?>
					<a href="<?php echo esc_url( $link ); ?>" class="sas-preview-card sas-animate-up">
						<div class="sas-preview-thumb" <?php if ( $thumb_url ) echo 'style="background-image:url(' . esc_url($thumb_url) . ')"'; ?>>
							<?php if ( $cat_name ) : ?>
							<span class="sas-preview-cat-badge"><?php echo $cat_name; ?></span>
							<?php endif; ?>
							<?php if ( ! $thumb_url ) : ?>
							<span class="sas-preview-placeholder">✦</span>
							<?php endif; ?>
						</div>
						<div class="sas-preview-body">
							<h4 class="sas-preview-title"><?php the_title(); ?></h4>
							<span class="sas-preview-cta">Try Now →</span>
						</div>
					</a>
					<?php endwhile; wp_reset_postdata(); ?>
				</div>
				<?php else : ?>
				<div class="sas-preview-empty">
					<p>Hindu Tools coming soon. <a href="<?php echo esc_url( home_url('/ai-tools/') ); ?>">Browse now →</a></p>
				</div>
				<?php endif; ?>

				<div class="sas-section-footer">
					<a href="<?php echo esc_url( home_url( '/ai-tools/' ) ); ?>" class="sas-btn sas-btn-primary">
						Explore All 110+ Hindu Tools →
					</a>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * EVENTS PREVIEW
	 * ───────────────────────────────────────── */
	public static function render_events_preview( $atts ) {
		$events = new WP_Query( [
			'post_type'      => 'tribe_events',
			'posts_per_page' => 3,
			'post_status'    => 'publish',
			'meta_key'       => '_EventStartDate',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [ [
				'key'     => '_EventStartDate',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			] ],
		] );

		ob_start(); ?>
		<div class="sas-home-section sas-events-preview-section">
			<div class="sas-container">
				<div class="sas-section-header sas-section-header--split">
					<div>
						<h2 class="sas-section-title">📅 Upcoming Dharma Events</h2>
						<p class="sas-section-subtitle">Festivals, pujas, and satsangs — stay connected to the sacred calendar</p>
					</div>
					<a href="<?php echo esc_url( home_url( '/dharma-events/' ) ); ?>" class="sas-section-view-all">
						View All Events →
					</a>
				</div>

				<?php if ( $events->have_posts() ) : ?>
				<div class="sas-events-list">
					<?php while ( $events->have_posts() ) : $events->the_post();
						$start_date = get_post_meta( get_the_ID(), '_EventStartDate', true );
						$day        = $start_date ? date( 'd', strtotime( $start_date ) ) : '—';
						$mon        = $start_date ? date( 'M', strtotime( $start_date ) ) : '';
						$cats       = get_the_terms( get_the_ID(), 'tribe_events_cat' );
						$cat_name   = $cats && ! is_wp_error( $cats ) ? esc_html( $cats[0]->name ) : 'Event';
						$venue      = get_post_meta( get_the_ID(), '_EventVenueID', true );
					?>
					<a href="<?php echo esc_url( get_permalink() ); ?>" class="sas-event-card sas-animate-up">
						<div class="sas-event-date-badge">
							<strong class="sas-event-day"><?php echo esc_html( $day ); ?></strong>
							<span class="sas-event-mon"><?php echo esc_html( $mon ); ?></span>
						</div>
						<div class="sas-event-info">
							<h4 class="sas-event-title"><?php the_title(); ?></h4>
							<div class="sas-event-meta">
								<span class="sas-event-cat-chip"><?php echo $cat_name; ?></span>
							</div>
						</div>
						<div class="sas-event-arrow">→</div>
					</a>
					<?php endwhile; wp_reset_postdata(); ?>
				</div>
				<?php else : ?>
				<div class="sas-events-empty">
					<div class="sas-events-empty-icon">📅</div>
					<p>Upcoming events will be listed here.</p>
					<a href="<?php echo esc_url( home_url( '/dharma-events/' ) ); ?>" class="sas-btn sas-btn-outline">
						Browse Events Calendar →
					</a>
				</div>
				<?php endif; ?>

				<div class="sas-section-footer">
					<a href="<?php echo esc_url( home_url( '/dharma-events/' ) ); ?>" class="sas-btn sas-btn-outline">
						View All Dharma Events →
					</a>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * SERVICES PREVIEW
	 * ───────────────────────────────────────── */
	public static function render_services_preview( $atts ) {
		$categories = get_terms( [
			'taxonomy'   => 'at_biz_dir-category',
			'hide_empty' => false,
			'number'     => 9,
		] );

		ob_start(); ?>
		<div class="sas-home-section sas-services-preview-section sas-alt-bg">
			<div class="sas-container">
				<div class="sas-section-header sas-section-header--split">
					<div>
						<h2 class="sas-section-title">🏛️ Dharma Services</h2>
						<p class="sas-section-subtitle">Find temples, gurus, healers, and more in your community</p>
					</div>
					<a href="<?php echo esc_url( home_url( '/dharma-services/' ) ); ?>" class="sas-section-view-all">
						Browse All Services →
					</a>
				</div>

				<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
				<div class="sas-services-grid">
					<?php foreach ( $categories as $cat ) :
						$slug  = $cat->slug;
						$emoji = isset( self::$service_emojis[ $slug ] ) ? self::$service_emojis[ $slug ] : '🕉️';
						$url   = get_term_link( $cat );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="sas-service-tile sas-animate-up">
						<div class="sas-service-icon"><?php echo $emoji; ?></div>
						<h4 class="sas-service-name"><?php echo esc_html( $cat->name ); ?></h4>
						<span class="sas-service-count"><?php echo (int) $cat->count; ?> listings</span>
					</a>
					<?php endforeach; ?>
				</div>
				<?php else : ?>
				<div class="sas-services-grid sas-services-placeholder">
					<?php
					$defaults = [
						['🛕','Temples & Mandirs'],['💃','Classical Dance'],['🎵','Classical Music'],
						['🧘','Yoga & Meditation'],['📚','Vedic Education'],['⭐','Jyotish & Astrology'],
						['🌿','Ayurveda & Healing'],['🪔','Puja & Ritual Services'],['🎨','Hindu Arts & Crafts'],
					];
					foreach ( $defaults as $d ) : ?>
					<a href="<?php echo esc_url( home_url('/dharma-services/') ); ?>" class="sas-service-tile sas-animate-up">
						<div class="sas-service-icon"><?php echo $d[0]; ?></div>
						<h4 class="sas-service-name"><?php echo esc_html( $d[1] ); ?></h4>
						<span class="sas-service-count">Coming soon</span>
					</a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="sas-section-footer">
					<a href="<?php echo esc_url( home_url( '/dharma-services/' ) ); ?>" class="sas-btn sas-btn-primary">
						Browse All Dharma Services →
					</a>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * TODAY'S SACRED DASHBOARD
	 * ───────────────────────────────────────── */
	public static function render_daily_dashboard( $atts ): string {
		ob_start(); ?>
		<div class="sas-home-section sas-dashboard-section">
			<div class="sas-container">

				<!-- Section header -->
				<div class="sas-dashboard-header">
					<div class="sas-dashboard-title-row">
						<h2 class="sas-section-title">🌅 Today's Sacred Dashboard</h2>
						<span class="sas-today-date" id="sas-today-date"></span>
					</div>
					<p class="sas-section-subtitle">Daily Panchang, your zodiac reading, auspicious times and more</p>
				</div>

				<!-- Tab navigation -->
				<div class="sas-dash-tabs" role="tablist" aria-label="Daily dashboard sections">
					<button class="sas-dash-tab sas-dash-tab--active" data-tab="panchang" role="tab" aria-selected="true" aria-controls="sas-tab-panchang">
						📿 Panchang
					</button>
					<button class="sas-dash-tab" data-tab="zodiac" role="tab" aria-selected="false" aria-controls="sas-tab-zodiac">
						⭐ Your Zodiac
					</button>
					<button class="sas-dash-tab" data-tab="festivals" role="tab" aria-selected="false" aria-controls="sas-tab-festivals">
						🕉️ Festivals
					</button>
					<button class="sas-dash-tab" data-tab="times" role="tab" aria-selected="false" aria-controls="sas-tab-times">
						⏰ Good Times
					</button>
				</div>

				<!-- Tab panels -->
				<div class="sas-dash-panel sas-dash-panel--active" id="sas-tab-panchang" role="tabpanel">
					<div class="sas-panchang-loading sas-dash-loading">
						<span class="sas-spinner"></span> Loading today's Panchang…
					</div>
					<div class="sas-panchang-grid" id="sas-panchang-grid" hidden></div>
				</div>

				<div class="sas-dash-panel" id="sas-tab-zodiac" role="tabpanel" hidden>
					<div class="sas-zodiac-controls">
						<div class="sas-zodiac-selectors">
							<select id="sas-zodiac-select" class="sas-select" aria-label="Select zodiac sign">
								<option value="">Select your zodiac…</option>
								<option value="aries">♈ Aries</option>
								<option value="taurus">♉ Taurus</option>
								<option value="gemini">♊ Gemini</option>
								<option value="cancer">♋ Cancer</option>
								<option value="leo">♌ Leo</option>
								<option value="virgo">♍ Virgo</option>
								<option value="libra">♎ Libra</option>
								<option value="scorpio">♏ Scorpio</option>
								<option value="sagittarius">♐ Sagittarius</option>
								<option value="capricorn">♑ Capricorn</option>
								<option value="aquarius">♒ Aquarius</option>
								<option value="pisces">♓ Pisces</option>
							</select>
							<select id="sas-lang-select" class="sas-select" aria-label="Select language">
								<option value="en">English</option>
								<option value="hi">हिन्दी</option>
								<option value="ta">தமிழ்</option>
								<option value="te">తెలుగు</option>
								<option value="ka">ಕನ್ನಡ</option>
								<option value="ml">മലയാളം</option>
								<option value="be">বাংলা</option>
								<option value="sp">Español</option>
								<option value="fr">Français</option>
							</select>
						</div>
						<div class="sas-cycle-tabs" role="group" aria-label="Prediction cycle">
							<button class="sas-cycle-btn sas-cycle-btn--active" data-cycle="daily">Daily</button>
							<button class="sas-cycle-btn" data-cycle="weekly">Weekly</button>
							<button class="sas-cycle-btn" data-cycle="yearly">Yearly</button>
						</div>
					</div>
					<div id="sas-zodiac-result" class="sas-zodiac-result">
						<div class="sas-dash-loading"><span class="sas-spinner"></span> Loading prediction…</div>
					</div>
				</div>

				<div class="sas-dash-panel" id="sas-tab-festivals" role="tabpanel" hidden>
					<div class="sas-panchang-loading sas-dash-loading">
						<span class="sas-spinner"></span> Loading today's festivals…
					</div>
					<div id="sas-festivals-list" hidden></div>
				</div>

				<div class="sas-dash-panel" id="sas-tab-times" role="tabpanel" hidden>
					<div class="sas-muhurat-loading sas-dash-loading">
						<span class="sas-spinner"></span> Loading auspicious times…
					</div>
					<div id="sas-muhurat-list" hidden></div>
				</div>

				<!-- Kundali Panel (always below tabs) -->
				<div class="sas-kundali-panel-wrapper">
					<div class="sas-kundali-panel-header">
						<h3 class="sas-kundali-panel-title">📿 Your Vedic Kundali</h3>
					</div>
					<?php echo do_shortcode( '[sas_kundali_form]' ); ?>
				</div>

			</div><!-- .sas-container -->
		</div><!-- .sas-dashboard-section -->
		<?php return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * HOMEPAGE CHAT  [sas_home_chat]
	 * Chat-first homepage interface (à la ChatGPT / Claude).
	 * Guests get real panchang/prediction data via public API + guided signup funnel.
	 * Logged-in users get full Guruji AI chat with session history.
	 * The Guruji floating bubble is suppressed on the front page.
	 * ───────────────────────────────────────── */
	public static function render_home_chat( $atts ): string {
		$is_logged_in = is_user_logged_in();

		// Resolve Guruji persona for logged-in users.
		$guruji_name = 'Guruji';
		$avatar_html = '<span class="sas-hc-avatar-emoji" aria-hidden="true">🤖</span>';
		$greeting    = '🙏 Jai Shri Ram! Ask me anything about Vedic astrology, dharma, festivals or spirituality.';

		if ( $is_logged_in ) {
			$user     = wp_get_current_user();
			$display  = esc_html( $user->display_name ?: $user->user_login );
			$greeting = '🙏 Jai Shri Ram, ' . $display . '! How may I guide you today?';

			$profile = SAS_Guruji::get_profile( get_current_user_id() );
			if ( $profile ) {
				$guruji_name = esc_html( $profile['guruji_name'] ?? 'Guruji' );
				if ( ! empty( $profile['resolved_avatar_url'] ) ) {
					$avatar_html = '<img src="' . esc_url( $profile['resolved_avatar_url'] ) . '" alt="' . esc_attr( $guruji_name ) . '" class="sas-hc-avatar-img">';
				}
			}
		}

		ob_start();
		?>
		<div id="sas-home-chat" class="sas-home-chat">

			<!-- Welcome header (collapses when conversation begins) -->
			<div id="sas-hc-welcome" class="sas-hc-welcome">
				<div class="sas-hc-avatar-wrap"><?php echo $avatar_html; ?></div>
				<h1 class="sas-hc-name"><?php echo $guruji_name; ?></h1>
				<p class="sas-hc-greeting"><?php echo $greeting; ?></p>
			</div>

			<!-- Message history (flex:1, scrollable) -->
			<div id="sas-hc-messages" class="sas-hc-messages" role="log" aria-label="Chat messages" aria-live="polite"></div>

			<!-- Suggestion chips — hidden once any message is sent -->
			<div id="sas-hc-chips" class="sas-hc-chips" role="list" aria-label="Quick questions">
				<button class="sas-hc-chip" data-prompt="What is today&#39;s Panchang?" type="button">🗓️ Today's Panchang</button>
				<button class="sas-hc-chip" data-prompt="What is my horoscope today?" type="button">🌟 Daily Horoscope</button>
				<button class="sas-hc-chip" data-prompt="What festivals are coming up?" type="button">🪔 Festivals</button>
				<button class="sas-hc-chip" data-prompt="What are the auspicious times today?" type="button">⏰ Muhurat</button>
				<?php if ( $is_logged_in ) : ?>
				<button class="sas-hc-chip" data-prompt="Tell me about my Kundali birth chart" type="button">📿 My Kundali</button>
				<button class="sas-hc-chip" data-prompt="Give me a weekly reading for my zodiac" type="button">⭐ Weekly Reading</button>
				<?php else : ?>
				<button class="sas-hc-chip" data-prompt="How do I create my Kundali?" type="button">📿 Create Kundali</button>
				<button class="sas-hc-chip" data-prompt="What can Guruji do for me?" type="button">✨ What can Guruji do?</button>
				<?php endif; ?>
			</div>

			<!-- Input area (sticky bottom) -->
			<div class="sas-hc-input-area">
				<div class="sas-hc-input-row">
					<textarea
						id="sas-hc-input"
						class="sas-hc-input"
						placeholder="Ask Guruji about dharma, astrology, festivals…"
						rows="1"
						maxlength="500"
						aria-label="Message to Guruji"
						autocomplete="off"
					></textarea>
					<button id="sas-hc-send" class="sas-hc-send" aria-label="Send message" type="button">
						<span aria-hidden="true">↑</span>
					</button>
				</div>
				<?php if ( ! $is_logged_in ) : ?>
				<div class="sas-hc-guest-bar">
					<span class="sas-hc-guest-hint">🙏 Sign up free for Kundali, personalized readings &amp; full chat history</span>
					<div class="sas-hc-guest-actions">
						<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="sas-hc-btn-signup">Create Free Account</a>
						<a href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>" class="sas-hc-btn-signin">Sign In</a>
					</div>
				</div>
				<?php endif; ?>
				<p class="sas-hc-disclaimer">Guruji provides Vedic spiritual guidance · Not a substitute for professional advice</p>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * GURUJI DEDICATED PAGE  [sas_guruji_page]
	 * Renders a full-width landing panel on /guruji/.
	 * The floating chat modal auto-opens via JS (sasConfig.isGurujiPage).
	 * ───────────────────────────────────────── */
	public static function render_guruji_page( $atts ): string {
		$is_logged_in = is_user_logged_in();
		$setup_url    = esc_url( home_url( '/guruji/' ) ); // page itself

		// Fetch profile for logged-in users so we can display their Guruji name/avatar.
		$guruji_name   = 'Guruji';
		$avatar_html   = '<span class="sas-gp-avatar-emoji" aria-hidden="true">🤖</span>';
		$setup_done    = false;

		if ( $is_logged_in ) {
			$profile = SAS_Guruji::get_profile( get_current_user_id() );
			if ( $profile ) {
				$setup_done  = true;
				$guruji_name = esc_html( $profile['guruji_name'] ?? 'Guruji' );
				if ( ! empty( $profile['resolved_avatar_url'] ) ) {
					$avatar_html = '<img src="' . esc_url( $profile['resolved_avatar_url'] ) . '" alt="' . $guruji_name . '" class="sas-gp-avatar-img">';
				}
			}
		}

		ob_start();
		?>
		<div class="sas-guruji-page">
			<div class="sas-guruji-page-inner">

				<!-- Avatar & Name -->
				<div class="sas-gp-avatar-wrap">
					<?php echo $avatar_html; ?>
				</div>

				<?php if ( $is_logged_in && $setup_done ) : ?>
					<h1 class="sas-gp-title">Your Personal <?php echo $guruji_name; ?></h1>
					<p class="sas-gp-subtitle">Your Vedic spiritual guide — available 24/7 for dharma, astrology &amp; life guidance.</p>
					<button
						class="sas-btn sas-btn-gold sas-gp-open-btn"
						onclick="document.getElementById('sas-guruji-bubble').click()"
						type="button"
					>
						💬 Continue Conversation
					</button>

				<?php elseif ( $is_logged_in ) : ?>
					<h1 class="sas-gp-title">Meet Your Personal Guruji</h1>
					<p class="sas-gp-subtitle">You haven't set up your Guruji yet. It only takes a moment — choose a name, personality, and language.</p>
					<button
						class="sas-btn sas-btn-gold sas-gp-open-btn"
						onclick="document.getElementById('sas-guruji-bubble').click()"
						type="button"
					>
						🙏 Begin Setup
					</button>

				<?php else : ?>
					<h1 class="sas-gp-title">Your Personal Vedic Guruji</h1>
					<p class="sas-gp-subtitle">
						A personal Vedic AI guide — your own Guruji available 24/7 for dharma guidance,
						astrology, festivals, and spiritual questions. Responds in 9 Indian &amp; world languages.
					</p>
					<div class="sas-gp-guest-actions">
						<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="sas-btn sas-btn-gold">
							Create Free Account →
						</a>
						<a href="<?php echo esc_url( wp_login_url( home_url( '/guruji/' ) ) ); ?>" class="sas-btn sas-btn-ghost">
							Sign In
						</a>
					</div>
				<?php endif; ?>

				<!-- Feature chips -->
				<div class="sas-gp-features">
					<span class="sas-gp-chip">🔭 Vedic Astrology</span>
					<span class="sas-gp-chip">🪔 Dharma &amp; Rituals</span>
					<span class="sas-gp-chip">📿 Kundali Insights</span>
					<span class="sas-gp-chip">🗓️ Muhurat Guidance</span>
					<span class="sas-gp-chip">🌍 9 Languages</span>
					<span class="sas-gp-chip">⚡ Available 24/7</span>
				</div>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ─────────────────────────────────────────
	 * GURUJI FLOATING CHAT BUBBLE
	 * Injected into wp_footer on all frontend pages.
	 * JS handles open/close and API communication.
	 * ───────────────────────────────────────── */
	public static function render_guruji_float(): void {
		if ( is_admin() ) {
			return;
		}
		// Homepage uses the full-page embedded [sas_home_chat] — no floating bubble needed there.
		if ( is_front_page() || is_home() ) {
			return;
		}
		?>
		<div id="sas-guruji-float" aria-live="polite">
			<button
				id="sas-guruji-bubble"
				class="sas-guruji-bubble"
				aria-label="Chat with your personal Guruji"
				aria-expanded="false"
				aria-controls="sas-guruji-modal"
			>
				<span class="sas-guruji-bubble-icon" aria-hidden="true">🤖</span>
				<span class="sas-guruji-bubble-label">Guruji</span>
			</button>

			<div
				id="sas-guruji-modal"
				class="sas-guruji-modal"
				role="dialog"
				aria-modal="true"
				aria-label="Chat with Guruji"
				hidden
			>
				<div class="sas-guruji-modal-header">
					<div class="sas-guruji-modal-title">
						<span class="sas-guruji-modal-avatar" aria-hidden="true">🤖</span>
						<span>Your Personal Guruji</span>
					</div>
					<button id="sas-guruji-close" class="sas-guruji-close" aria-label="Close chat">✕</button>
				</div>
				<div id="sas-guruji-messages" class="sas-guruji-messages" role="log" aria-label="Chat messages"></div>
				<div class="sas-guruji-input-row">
					<input
						type="text"
						id="sas-guruji-input"
						class="sas-guruji-input"
						placeholder="Ask Guruji anything about dharma, astrology…"
						autocomplete="off"
						aria-label="Message to Guruji"
						maxlength="500"
					>
					<button id="sas-guruji-send" class="sas-guruji-send" aria-label="Send message">
						<span aria-hidden="true">↑</span>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────────────────────
	 * CTA SECTION (logged-out only)
	 * ───────────────────────────────────────── */
	public static function render_cta( $atts ) {
		if ( is_user_logged_in() ) return '';
		ob_start(); ?>
		<div class="sas-home-cta-section">
			<div class="sas-container">
				<div class="sas-cta-inner">
					<div class="sas-cta-badge">🕉️ Join Today — It's Free</div>
					<h2 class="sas-cta-title">Start Your Sanathan Journey</h2>
					<p class="sas-cta-subtitle">
						Guruji AI · Vedic Kundali · 110+ Hindu Tools · Events · Services · Community<br>
						<em>Free forever · No credit card · Available in 9 Indian languages</em>
					</p>
					<div class="sas-cta-buttons">
						<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="sas-btn sas-btn-gold">
							Create Free Account →
						</a>
						<a href="<?php echo esc_url( home_url( '/ai-tools/' ) ); ?>" class="sas-btn sas-btn-ghost-light">
							Explore Hindu Tools First
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}
}
