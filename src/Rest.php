<?php
/**
 * REST API for the front-end loader.
 *
 *   POST /wp-json/adn/v1/serve   {"page":12,"slots":[{"id":"s1","placement":"header","size":"leaderboard"}]}
 *   POST /wp-json/adn/v1/event   t={token}&e=view|click
 *
 * Both are public: pages are cached for logged-out visitors, so there's no nonce to check. Event
 * reports are protected by the signed token handed out with each served ad instead.
 *
 * @package AdNetwork
 */

declare(strict_types=1);

namespace AdNetwork;

use AdNetwork\Serving\Renderer;
use AdNetwork\Serving\Selector;
use AdNetwork\Tracking\Events;
use AdNetwork\Tracking\Tokens;
use AdNetwork\Tracking\Visitor;
use WP_REST_Request;
use WP_REST_Response;

final class Rest {

	public const NAMESPACE = 'adn/v1';
	public const MAX_SLOTS = 20;

	/** How many different ads to try for one slot before leaving it empty. */
	private const ATTEMPTS = 3;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/serve',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'serve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'  => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'slots' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => self::MAX_SLOTS,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'id'        => array( 'type' => 'string', 'required' => true, 'maxLength' => 40 ),
								'placement' => array( 'type' => 'string', 'maxLength' => 40 ),
								'size'      => array( 'type' => 'string', 'required' => true, 'maxLength' => 40 ),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'event' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					't' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 120,
					),
					'e' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'view', 'click' ),
					),
				),
			)
		);
	}

	public static function serve( WP_REST_Request $request ): WP_REST_Response {
		$page     = (int) $request['page'];
		$track    = Visitor::shouldTrack();
		$selector = new Selector( Ads::servable() );
		$used     = array();
		$out      = array();

		foreach ( (array) $request['slots'] as $slot ) {
			$slot_id   = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $slot['id'] ?? '' ) ), 0, 40 );
			$size      = sanitize_key( (string) ( $slot['size'] ?? '' ) );
			$placement = sanitize_key( (string) ( $slot['placement'] ?? '' ) );
			if ( '' === $slot_id || ! Sizes::isEnabled( $size ) ) {
				continue;
			}

			for ( $i = 0; $i < self::ATTEMPTS; $i++ ) {
				$ad_id = $selector->fill( array( $slot_id => $size ), array_keys( $used ) )[ $slot_id ];
				if ( null === $ad_id ) {
					break;
				}
				$used[ $ad_id ] = true;
				$html           = Renderer::render( $ad_id );
				if ( '' === $html ) {
					continue;
				}

				$serve_id        = $track ? Events::recordServe( $ad_id, $placement, $page ) : 0;
				$out[ $slot_id ] = array(
					'ad'         => $ad_id,
					'html'       => $html,
					'token'      => $serve_id ? Tokens::issue( $serve_id, $ad_id ) : '',
					'thirdParty' => Ads::isThirdParty( $ad_id ),
				);
				break;
			}
		}

		$response = new WP_REST_Response( array( 'ads' => (object) $out ) );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	public static function event( WP_REST_Request $request ): WP_REST_Response {
		$data     = Tokens::verify( (string) $request['t'] );
		$recorded = false;
		if ( $data && Visitor::shouldTrack() ) {
			$event    = 'click' === $request['e'] ? Events::CLICK : Events::VIEW;
			$recorded = Events::recordFollowUp( $data['serve_id'], $data['ad_id'], $event );
		}
		$response = new WP_REST_Response( array( 'recorded' => $recorded ) );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}
