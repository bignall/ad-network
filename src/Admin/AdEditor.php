<?php
/**
 * The ad edit screen: size, image or HTML creative, link and weight.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Admin;

use AdNetwork\Ads;
use AdNetwork\App;
use AdNetwork\Integrations\LinkCloaker;
use AdNetwork\Settings;
use AdNetwork\Sizes;
use AdNetwork\Tracking\Events;

final class AdEditor {

	private const NONCE = 'adn_ad_nonce';

	public static function init(): void {
		add_action( 'add_meta_boxes_' . Ads::POST_TYPE, array( self::class, 'metaBoxes' ) );
		add_action( 'save_post_' . Ads::POST_TYPE, array( self::class, 'save' ), 5, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'enter_title_here', array( self::class, 'titlePlaceholder' ), 10, 2 );
	}

	public static function titlePlaceholder( string $text, \WP_Post $post ): string {
		return Ads::POST_TYPE === $post->post_type ? __( 'Ad name (only you see this)', 'ad-network' ) : $text;
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || Ads::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		$editor = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		wp_enqueue_style( 'adn-admin', App::url( 'assets/admin.css' ), array(), App::version() );
		wp_enqueue_script( 'adn-admin', App::url( 'assets/admin.js' ), array( 'jquery' ), App::version(), true );
		wp_localize_script(
			'adn-admin',
			'adnAdmin',
			array(
				'codeEditor' => $editor ? $editor : null,
				'sizes'      => Sizes::all(),
				'i18n'       => array(
					'chooseImage' => __( 'Choose ad image', 'ad-network' ),
					'useImage'    => __( 'Use this image', 'ad-network' ),
					'mismatch'    => __( 'This image is %1$s×%2$s; the ad size is %3$s×%4$s. It will be scaled to fit.', 'ad-network' ),
				),
			)
		);
	}

	public static function metaBoxes(): void {
		add_meta_box( 'adn-ad', __( 'Ad', 'ad-network' ), array( self::class, 'render' ), Ads::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'adn-ad-stats', __( 'Performance', 'ad-network' ), array( self::class, 'renderStats' ), Ads::POST_TYPE, 'side' );
	}

	public static function render( \WP_Post $post ): void {
		$id        = $post->ID;
		$size      = Ads::meta( $id, 'size' );
		$type      = Ads::type( $id );
		$image_id  = (int) Ads::meta( $id, 'image_id' );
		$link_id   = (int) Ads::meta( $id, 'link_id' );
		$enabled   = Sizes::enabled();
		$can_code  = Settings::scriptsAllowed() && current_user_can( 'unfiltered_html' );
		$links     = LinkCloaker::choices();
		$image_src = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		$image     = $image_id ? wp_get_attachment_metadata( $image_id ) : array();

		wp_nonce_field( 'adn_save_ad', self::NONCE );
		?>
		<table class="form-table adn-ad-form" role="presentation">
			<tr>
				<th scope="row"><label for="adn-size"><?php esc_html_e( 'Size', 'ad-network' ); ?></label></th>
				<td>
					<select id="adn-size" name="adn[size]" required>
						<option value=""><?php esc_html_e( '— Choose a size —', 'ad-network' ); ?></option>
						<?php foreach ( Sizes::formats() as $format => $format_label ) : ?>
							<?php
							$group = array_filter( Sizes::all(), static fn ( array $s, string $k ): bool => $s['format'] === $format && ( isset( $enabled[ $k ] ) || $k === $size ), ARRAY_FILTER_USE_BOTH );
							if ( ! $group ) {
								continue;
							}
							?>
							<optgroup label="<?php echo esc_attr( $format_label ); ?>">
								<?php foreach ( $group as $key => $s ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $size, $key ); ?>>
										<?php echo esc_html( Sizes::label( $key ) . ( isset( $enabled[ $key ] ) ? '' : ' — ' . __( 'turned off in Settings', 'ad-network' ) ) ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The ad is shown in slots of exactly this size. Turn sizes on or off in Ads → Settings.', 'ad-network' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ad type', 'ad-network' ); ?></th>
				<td>
					<label><input type="radio" name="adn[type]" value="image" <?php checked( $type, Ads::TYPE_IMAGE ); ?>> <?php esc_html_e( 'Image', 'ad-network' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="adn[type]" value="html" <?php checked( $type, Ads::TYPE_HTML ); ?>> <?php esc_html_e( 'HTML', 'ad-network' ); ?></label>
				</td>
			</tr>
			<tr class="adn-type-image">
				<th scope="row"><?php esc_html_e( 'Image', 'ad-network' ); ?></th>
				<td>
					<input type="hidden" id="adn-image-id" name="adn[image_id]" value="<?php echo esc_attr( (string) $image_id ); ?>">
					<div id="adn-image-preview" class="adn-image-preview">
						<?php if ( $image_src ) : ?>
							<img src="<?php echo esc_url( $image_src ); ?>" alt="">
						<?php endif; ?>
					</div>
					<p id="adn-image-warning" class="adn-warning" data-width="<?php echo esc_attr( (string) ( $image['width'] ?? '' ) ); ?>" data-height="<?php echo esc_attr( (string) ( $image['height'] ?? '' ) ); ?>"></p>
					<button type="button" class="button" id="adn-image-choose"><?php esc_html_e( 'Choose image', 'ad-network' ); ?></button>
					<button type="button" class="button-link button-link-delete" id="adn-image-remove" <?php echo $image_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'ad-network' ); ?></button>
					<p>
						<label for="adn-alt"><?php esc_html_e( 'Alt text', 'ad-network' ); ?></label><br>
						<input type="text" class="regular-text" id="adn-alt" name="adn[alt]" value="<?php echo esc_attr( Ads::meta( $id, 'alt' ) ); ?>">
					</p>
				</td>
			</tr>
			<tr class="adn-type-html">
				<th scope="row"><label for="adn-html"><?php esc_html_e( 'HTML', 'ad-network' ); ?></label></th>
				<td>
					<textarea id="adn-html" name="adn[html]" rows="12" class="large-text code"><?php echo esc_textarea( Ads::meta( $id, 'html' ) ); ?></textarea>
					<p class="description">
						<?php
						printf(
							/* translators: 1: {{url}} placeholder, 2: example shortcode */
							esc_html__( 'Use %1$s wherever you want the ad\'s link below, or link to any affiliate link with %2$s.', 'ad-network' ),
							'<code>{{url}}</code>',
							'<code>[alc_link slug="…"]…[/alc_link]</code>'
						);
						?>
					</p>
					<?php if ( $can_code ) : ?>
						<p class="description"><?php esc_html_e( 'Third-party ad code (scripts, iframes) is allowed. Ads containing it are served exactly as pasted and never earn credits.', 'ad-network' ); ?></p>
					<?php elseif ( Settings::scriptsAllowed() ) : ?>
						<p class="adn-warning"><?php esc_html_e( 'Your account can\'t save scripts or iframes; they will be removed when you save.', 'ad-network' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Scripts, iframes and other third-party ad code are removed on save. An administrator can allow them in Ads → Settings.', 'ad-network' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="adn-link"><?php esc_html_e( 'Link', 'ad-network' ); ?></label></th>
				<td>
					<?php if ( LinkCloaker::available() ) : ?>
						<select id="adn-link" name="adn[link_id]">
							<option value="0"><?php esc_html_e( '— Use the URL below —', 'ad-network' ); ?></option>
							<?php foreach ( $links as $lid => $title ) : ?>
								<option value="<?php echo esc_attr( (string) $lid ); ?>" <?php selected( $link_id, $lid ); ?>><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
						<a href="<?php echo esc_url( LinkCloaker::addUrl() ); ?>" target="_blank"><?php esc_html_e( 'Add a link', 'ad-network' ); ?></a>
						<p class="description"><?php esc_html_e( 'Clicks go through your cloaked link, tagged with this ad\'s sub-ID so the link\'s click log shows which ad sent them.', 'ad-network' ); ?></p>
						<?php if ( $link_id && LinkCloaker::isDead( $link_id ) ) : ?>
							<p class="adn-warning"><?php esc_html_e( 'The affiliate network says this link no longer pays, so the ad is not being shown.', 'ad-network' ); ?></p>
						<?php endif; ?>
						<p><label for="adn-url"><?php esc_html_e( 'Or a direct URL', 'ad-network' ); ?></label></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Install Affiliate Link Cloaker to pick from your cloaked, tracked links.', 'ad-network' ); ?></p>
					<?php endif; ?>
					<input type="url" class="regular-text" id="adn-url" name="adn[url]" value="<?php echo esc_attr( Ads::meta( $id, 'url' ) ); ?>" placeholder="https://">
					<p class="description"><?php esc_html_e( 'Image ads need a link. For HTML ads it fills in {{url}}.', 'ad-network' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="adn-weight"><?php esc_html_e( 'Weight', 'ad-network' ); ?></label></th>
				<td>
					<input type="number" id="adn-weight" name="adn[weight]" min="1" max="<?php echo esc_attr( (string) Ads::MAX_WEIGHT ); ?>" value="<?php echo esc_attr( (string) Ads::weight( $id ) ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'How often this ad is picked compared with other ads of the same size. An ad with weight 20 shows twice as often as one with 10.', 'ad-network' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Publish the ad to put it in rotation. Switch it to Draft to pause it.', 'ad-network' ); ?></p>
		<?php
	}

	public static function renderStats( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Stats appear once the ad is running.', 'ad-network' ) . '</p>';
			return;
		}
		$all  = Events::totals( array( $post->ID ) )[ $post->ID ];
		$week = Events::totals( array( $post->ID ), 7 )[ $post->ID ];
		?>
		<table class="widefat striped adn-stats">
			<thead><tr><th></th><th><?php esc_html_e( '7 days', 'ad-network' ); ?></th><th><?php esc_html_e( 'All time', 'ad-network' ); ?></th></tr></thead>
			<tbody>
				<tr><th><?php esc_html_e( 'Served', 'ad-network' ); ?></th><td><?php echo esc_html( number_format_i18n( $week['serve'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $all['serve'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Viewed', 'ad-network' ); ?></th><td><?php echo esc_html( number_format_i18n( $week['view'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $all['view'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Clicks', 'ad-network' ); ?></th><td><?php echo esc_html( number_format_i18n( $week['click'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $all['click'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'CTR', 'ad-network' ); ?></th><td><?php echo esc_html( AdList::ctr( $week ) ); ?></td><td><?php echo esc_html( AdList::ctr( $all ) ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Viewed: at least half the ad was on screen for a second. CTR is clicks per view.', 'ad-network' ); ?></p>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'adn_save_ad' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$in = isset( $_POST['adn'] ) && is_array( $_POST['adn'] ) ? wp_unslash( $_POST['adn'] ) : array();

		$size = sanitize_key( (string) ( $in['size'] ?? '' ) );
		Ads::setMeta( $post_id, 'size', Sizes::exists( $size ) ? $size : '' );

		$type = Ads::TYPE_HTML === ( $in['type'] ?? '' ) ? Ads::TYPE_HTML : Ads::TYPE_IMAGE;
		Ads::setMeta( $post_id, 'type', $type );

		$image_id = absint( $in['image_id'] ?? 0 );
		Ads::setMeta( $post_id, 'image_id', $image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0 );
		Ads::setMeta( $post_id, 'alt', sanitize_text_field( (string) ( $in['alt'] ?? '' ) ) );

		$raw          = (string) ( $in['html'] ?? '' );
		$allow_active = Settings::scriptsAllowed() && current_user_can( 'unfiltered_html' );
		$html         = Ads::sanitizeHtml( $raw, $allow_active );
		Ads::setMeta( $post_id, 'html', $html );
		Ads::setMeta( $post_id, 'third_party', Ads::containsActiveCode( $html ) ? 1 : 0 );
		if ( $html !== $raw && Ads::containsActiveCode( $raw ) ) {
			set_transient( 'adn_stripped_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		}

		$link_id = absint( $in['link_id'] ?? 0 );
		Ads::setMeta( $post_id, 'link_id', $link_id && get_post_type( $link_id ) === LinkCloaker::postType() ? $link_id : 0 );

		$url = esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ), array( 'http', 'https' ) );
		Ads::setMeta( $post_id, 'url', $url );

		$weight = (int) ( $in['weight'] ?? Ads::DEFAULT_WEIGHT );
		Ads::setMeta( $post_id, 'weight', max( 1, min( Ads::MAX_WEIGHT, $weight ) ) );

		if ( 'publish' === $post->post_status && ! Ads::isComplete( $post_id ) ) {
			set_transient( 'adn_incomplete_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		}
	}

	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || Ads::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		$user = get_current_user_id();
		if ( get_transient( 'adn_stripped_' . $user ) ) {
			delete_transient( 'adn_stripped_' . $user );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Scripts, iframes or other active code were removed from the HTML because third-party ad code isn\'t allowed (see Ads → Settings) or your account can\'t save it.', 'ad-network' ) . '</p></div>';
		}
		if ( get_transient( 'adn_incomplete_' . $user ) ) {
			delete_transient( 'adn_incomplete_' . $user );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'This ad is published but won\'t be shown yet: it needs a size and, for image ads, an image and a link.', 'ad-network' ) . '</p></div>';
		}
	}
}
