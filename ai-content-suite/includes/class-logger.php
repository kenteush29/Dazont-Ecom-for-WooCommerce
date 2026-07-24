<?php
defined( 'ABSPATH' ) || exit;

final class AICS_Logger {

	private const OPTION = 'aics_api_log';
	private const MAX    = 200;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function log( array $entry ): void {
		$log   = get_option( self::OPTION, [] );
		$log[] = array_merge( [ 'timestamp' => current_time( 'mysql' ) ], $entry );
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
	}

	public function get_log(): array {
		return array_reverse( get_option( self::OPTION, [] ) );
	}

	public function clear(): void {
		update_option( self::OPTION, [], false );
	}
}
