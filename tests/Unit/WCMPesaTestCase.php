<?php
/**
 * Base test case: sets up Brain Monkey and stubs common WP/WC functions.
 *
 * All tests that need WP function stubs should extend this class.
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

abstract class WCMPesaTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_common_functions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Stub WordPress/WooCommerce functions with sensible defaults so tests
	 * only need to override what they care about.
	 */
	private function stub_common_functions(): void {
		// Translation and sanitization helpers are aliased defaults.
		// Tests should only override their behavior when needed, not set call counts.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		// Hook registration — side-effect free in tests.
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( null );
		Functions\when( 'register_rest_route' )->justReturn( true );

		/*
		 * is_wp_error — provide a working alias using the WP_Error stub class.
		 * Tests MUST NOT use expect('is_wp_error') — the alias runs instead of
		 * any Mockery expectation.
		 */
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

		// Sanitization — deterministic, never needs asserting.
		Functions\when( 'sanitize_text_field' )->alias( fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'is_ssl' )->justReturn( true );
	}
}
