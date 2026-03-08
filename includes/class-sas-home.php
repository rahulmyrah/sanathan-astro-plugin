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
		add_action( 'wp_enqueue_scripts',            [ __CLASS__, 'enqueue_assets' ] );

		// Fix Directorist single-category page: replace "Single Category" with real term name
		add_filter( 'the_title',              [ __CLASS__, 'fix_directorist_category_title' ], 20, 2 );
		add_filter( 'document_title_parts',   [ __CLASS__, 'fix_directorist_document_title' ], 20 );
	}

	/* ─────────────────────────────────────────
	 * DIRECTORIST CATEGORY TITLE FIX
	 * Replaces the generic "Single Category" page title with the real
	 * Directorist category term name when browsing /single-category*/…/
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
		if ( preg_match( '#/single-category[^/]*/([^/?#\s]+)#i', $uri, $m ) ) {
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
	 * Enqueue home CSS/JS on the front page.
	 */
	public static function enqueue_assets() {
		if ( ! is_front_page() && ! is_page( 'home' ) ) return;
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
