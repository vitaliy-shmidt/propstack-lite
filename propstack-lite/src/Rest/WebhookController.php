<?php

namespace PropstackLite\Rest;

use PropstackLite\Settings;
use PropstackLite\Sync\Scheduler;

/**
 * POST /wp-json/propstack/v1/webhook
 *
 * Phase 1: Token-geschützter Auslöser, der einen zeitnahen Sync einplant (kein Sync im Request).
 * Geplant (Phase 2): HMAC-Prüfung über `X-Propstack-Signature` und gezielter Einzel-Sync.
 */
final class WebhookController {

	public const NAMESPACE = 'propstack/v1';
	public const ROUTE     = '/webhook';
	public const HOOK_ONCE = 'psl_sync_incremental_once';

	public function __construct( private Settings $settings ) {}

	public function register( callable $runIncremental ): void {
		add_action( 'rest_api_init', [ $this, 'registerRoute' ] );
		add_action( self::HOOK_ONCE, $runIncremental );
	}

	public function registerRoute(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	public function authorize( \WP_REST_Request $request ): bool {
		$expected = $this->settings->webhookToken();
		if ( '' === $expected ) {
			return false;
		}
		$given = $request->get_header( 'x-psl-token' );
		if ( ! is_string( $given ) || '' === $given ) {
			$param = $request->get_param( 'token' );
			$given = is_string( $param ) ? $param : '';
		}
		return '' !== $given && hash_equals( $expected, $given );
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$scheduled = false;
		if ( ! wp_next_scheduled( self::HOOK_ONCE ) ) {
			$scheduled = (bool) wp_schedule_single_event( time(), self::HOOK_ONCE );
		}
		return new \WP_REST_Response( [ 'ok' => true, 'scheduled' => $scheduled ], 202 );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_ONCE );
		Scheduler::unschedule();
	}
}
