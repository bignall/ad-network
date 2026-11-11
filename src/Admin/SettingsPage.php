<?php
/**
 * Ads → Settings: which sizes and placements the site uses, and tracking options.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Admin;

use AdNetwork\Ads;
use AdNetwork\App;
use AdNetwork\Placements;
use AdNetwork\Settings;
use AdNetwork\Sizes;
use AdNetwork\Vendor\Bignall\WPFoundation\Plugin;

final class SettingsPage {

	public const SLUG = 'adn-settings';

	public static function init( Plugin $plugin ): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'plugin_action_links_' . $plugin->basename(), array( self::class, 'pluginLinks' ) );
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . Ads::POST_TYPE . '&page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Ads::POST_TYPE, __( 'Ad Network Settings', 'ad-network' ), __( 'Settings', 'ad-network' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			wp_enqueue_style( 'adn-admin', App::url( 'assets/admin.css' ), array(), App::version() );
		}
	}

	/**
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function pluginLinks( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ad-network' ) . '</a>' );
		return $links;
	}

	public static function render(): void {
		$s    = Settings::all();
		$name = Settings::OPTION;
		$flag = static function ( string $key, string $label, bool $disabled = false ) use ( $s, $name ): void {
			printf(
				'<label><input type="checkbox" name="%s[%s]" value="1" %s %s /> %s</label><br>',
				esc_attr( $name ),
				esc_attr( $key ),
				checked( (int) $s[ $key ], 1, false ),
				disabled( $disabled, true, false ),
				esc_html( $label )
			);
		};
		$enabled = array_flip( (array) $s['sizes'] );
		?>
		<div class="wrap adn-settings">
			<h1><?php esc_html_e( 'Ad Network Settings', 'ad-network' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( Settings::GROUP ); ?>

				<h2><?php esc_html_e( 'Ad sizes', 'ad-network' ); ?></h2>
				<p><?php esc_html_e( 'Choose the sizes this site uses. Ads and placements can only use sizes that are on.', 'ad-network' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( Sizes::formats() as $format => $format_label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $format_label ); ?></th>
							<td>
								<?php foreach ( Sizes::all() as $key => $size ) : ?>
									<?php
									if ( $size['format'] !== $format ) {
										continue;
									}
									?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sizes][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $enabled[ $key ] ) ); ?>> <?php echo esc_html( Sizes::label( $key ) ); ?></label><br>
								<?php endforeach; ?>
								<?php if ( Sizes::FORMAT_POPUP === $format ) : ?>
									<p class="description"><?php esc_html_e( 'Popup ads are coming in a later version. You can create them now; they aren\'t shown yet.', 'ad-network' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Placements', 'ad-network' ); ?></h2>
				<p><?php esc_html_e( 'Where ads appear. Phones (narrower than 768px) can use a different size or none. If you just turned a size on, save once before choosing it here.', 'ad-network' ); ?></p>
				<table class="widefat striped adn-placements">
					<thead>
						<tr>
							<th><?php esc_html_e( 'On', 'ad-network' ); ?></th>
							<th><?php esc_html_e( 'Placement', 'ad-network' ); ?></th>
							<th><?php esc_html_e( 'Desktop size', 'ad-network' ); ?></th>
							<th><?php esc_html_e( 'Phone size', 'ad-network' ); ?></th>
							<th><?php esc_html_e( 'Options', 'ad-network' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Placements::all() as $key => $placement ) : ?>
							<?php
							$config  = (array) Placements::config( $key );
							$choices = Placements::sizeChoices( $key );
							$field   = $name . '[placements][' . $key . ']';
							?>
							<tr>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> aria-label="<?php echo esc_attr( $placement['label'] ); ?>"></td>
								<td><strong><?php echo esc_html( $placement['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $placement['description'] ); ?></span></td>
								<td>
									<select name="<?php echo esc_attr( $field ); ?>[desktop]">
										<?php foreach ( $choices as $size_key => $size ) : ?>
											<option value="<?php echo esc_attr( $size_key ); ?>" <?php selected( $config['desktop'], $size_key ); ?>><?php echo esc_html( Sizes::label( $size_key ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr( $field ); ?>[mobile]">
										<option value=""><?php esc_html_e( 'Hide on phones', 'ad-network' ); ?></option>
										<?php foreach ( $choices as $size_key => $size ) : ?>
											<option value="<?php echo esc_attr( $size_key ); ?>" <?php selected( $config['mobile'], $size_key ); ?>><?php echo esc_html( Sizes::label( $size_key ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( array_key_exists( 'paragraph', $config ) ) : ?>
										<label><?php esc_html_e( 'After paragraph', 'ad-network' ); ?>
											<input type="number" min="1" max="50" class="small-text" name="<?php echo esc_attr( $field ); ?>[paragraph]" value="<?php echo esc_attr( (string) $config['paragraph'] ); ?>">
										</label>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'In-content ads on', 'ad-network' ); ?></th>
						<td>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
								<?php
								if ( 'attachment' === $type->name ) {
									continue;
								}
								?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[content_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['content_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adn-label"><?php esc_html_e( 'Label above ads', 'ad-network' ); ?></label></th>
						<td>
							<input type="text" id="adn-label" class="regular-text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( (string) $s['label'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown in small print above each ad, e.g. "Advertisement" or "Sponsored". Leave empty for none.', 'ad-network' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Links', 'ad-network' ); ?></th>
						<td><?php $flag( 'new_tab', __( 'Open image ad links in a new tab', 'ad-network' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Third-party ad code', 'ad-network' ); ?></th>
						<td>
							<?php $flag( 'allow_scripts', __( 'Allow HTML ads to contain scripts and iframes (e.g. AdSense)', 'ad-network' ), ! current_user_can( 'unfiltered_html' ) ); ?>
							<p class="description"><?php esc_html_e( 'Only users who may post unfiltered HTML can save such code. Ads containing it never earn viewers credits: networks like AdSense ban rewarded views and clicks.', 'ad-network' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking', 'ad-network' ); ?></th>
						<td>
							<?php $flag( 'track', __( 'Record ads served, viewed and clicked', 'ad-network' ) ); ?>
							<?php $flag( 'ignore_bots', __( 'Ignore search engines and other bots', 'ad-network' ) ); ?>
							<?php $flag( 'track_admins', __( 'Count editors and administrators too', 'ad-network' ) ); ?>
							<p class="description"><?php esc_html_e( 'IP addresses are never stored; visitors are told apart by a salted hash that changes every day.', 'ad-network' ); ?></p>
							<p>
								<label for="adn-retention"><?php esc_html_e( 'Keep detailed events for', 'ad-network' ); ?></label>
								<input type="number" id="adn-retention" class="small-text" min="<?php echo esc_attr( (string) Settings::MIN_RETENTION_DAYS ); ?>" max="3650" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>">
								<?php esc_html_e( 'days', 'ad-network' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'Daily totals are kept forever. Detailed events (which page, which visitor) are deleted after this, which keeps the database small. Top pages in Stats only cover this period.', 'ad-network' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'ad-network' ); ?></th>
						<td><?php $flag( 'remove_data', __( 'Delete all ads, stats and settings when the plugin is deleted', 'ad-network' ) ); ?></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Placing ads by hand', 'ad-network' ); ?></h2>
			<p><?php esc_html_e( 'Use these in posts, in Etch, in widgets or in theme files:', 'ad-network' ); ?></p>
			<ul class="adn-help">
				<li><code>[adn_slot placement="sidebar"]</code> — <?php esc_html_e( 'a placement, with its sizes from above', 'ad-network' ); ?></li>
				<li><code>[adn_slot size="medium_rectangle"]</code> — <?php esc_html_e( 'any size that is on', 'ad-network' ); ?></li>
				<li><code>[adn_slot size="leaderboard" mobile="mobile_banner"]</code> — <?php esc_html_e( 'a different size on phones ("none" hides it)', 'ad-network' ); ?></li>
				<li><code>&lt;?php adn_slot( 'sidebar' ); ?&gt;</code> — <?php esc_html_e( 'in theme templates', 'ad-network' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
