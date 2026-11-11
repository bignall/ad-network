<?php
/**
 * "Ad Slot" widget for classic widget areas.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork\Serving;

use AdNetwork\Placements;
use AdNetwork\Sizes;

final class Widget extends \WP_Widget {

	public static function init(): void {
		add_action( 'widgets_init', static fn () => register_widget( self::class ) );
	}

	public function __construct() {
		parent::__construct(
			'adn_slot',
			__( 'Ad Slot', 'ad-network' ),
			array( 'description' => __( 'Shows a rotating ad from Ad Network.', 'ad-network' ) )
		);
	}

	/**
	 * @param array<string, mixed> $args     Widget area arguments.
	 * @param array<string, mixed> $instance Settings.
	 */
	public function widget( $args, $instance ): void {
		if ( ! Frontend::active() ) {
			return;
		}
		$size   = (string) ( $instance['size'] ?? '' );
		$mobile = (string) ( $instance['mobile'] ?? '' );
		$markup = '' === $size
			? Slot::forPlacement( 'sidebar' )
			: Slot::fromArgs( array( 'placement' => 'sidebar', 'size' => $size, 'mobile' => '' === $mobile ? $size : $mobile ) );
		if ( '' === $markup ) {
			return;
		}
		echo $args['before_widget'] . $markup . $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string, mixed> $instance Settings.
	 */
	public function form( $instance ): string {
		$size   = (string) ( $instance['size'] ?? '' );
		$mobile = (string) ( $instance['mobile'] ?? '' );
		$sizes  = Sizes::enabled();
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'size' ) ); ?>"><?php esc_html_e( 'Size', 'ad-network' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'size' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'size' ) ); ?>">
				<option value=""><?php esc_html_e( 'Use the Sidebar placement settings', 'ad-network' ); ?></option>
				<?php foreach ( $sizes as $key => $s ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $size, $key ); ?>><?php echo esc_html( Sizes::label( $key ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'mobile' ) ); ?>"><?php esc_html_e( 'On phones', 'ad-network' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'mobile' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'mobile' ) ); ?>">
				<option value=""><?php esc_html_e( 'Same size', 'ad-network' ); ?></option>
				<option value="none" <?php selected( $mobile, 'none' ); ?>><?php esc_html_e( 'Hide on phones', 'ad-network' ); ?></option>
				<?php foreach ( $sizes as $key => $s ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mobile, $key ); ?>><?php echo esc_html( Sizes::label( $key ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( ! Placements::isEnabled( 'sidebar' ) ) : ?>
			<p class="description"><?php esc_html_e( 'The Sidebar placement is off in Ads → Settings, so "Use the Sidebar placement settings" shows nothing.', 'ad-network' ); ?></p>
		<?php endif; ?>
		<?php
		return '';
	}

	/**
	 * @param array<string, mixed> $new_instance New settings.
	 * @param array<string, mixed> $old_instance Old settings.
	 * @return array<string, string>
	 */
	public function update( $new_instance, $old_instance ): array {
		$size   = sanitize_key( (string) ( $new_instance['size'] ?? '' ) );
		$mobile = sanitize_key( (string) ( $new_instance['mobile'] ?? '' ) );
		return array(
			'size'   => Sizes::exists( $size ) ? $size : '',
			'mobile' => 'none' === $mobile || Sizes::exists( $mobile ) ? $mobile : '',
		);
	}
}
