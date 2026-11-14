<?php
/**
 * Tests for WC_MPesa_STK_Push::format_phone()
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_MPesa_STK_Push;

/**
 * @covers WC_MPesa_STK_Push::format_phone
 */
class PhoneFormatTest extends WCMPesaTestCase {

	// â”€â”€ Valid formats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_format_07xx_returns_254(): void {
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '0712345678' ) );
	}

	public function test_format_7xx_9digits_returns_254(): void {
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '712345678' ) );
	}

	public function test_format_254xx_passthrough(): void {
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '254712345678' ) );
	}

	public function test_format_plus254_strips_plus(): void {
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '+254712345678' ) );
	}

	public function test_format_00254_prefix_normalised(): void {
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '00254712345678' ) );
	}

	public function test_format_spaces_and_hyphens_stripped(): void {
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '0712 345 678' ) );
		$this->assertSame( '254712345678', WC_MPesa_STK_Push::format_phone( '0712-345-678' ) );
	}

	/** Safaricom 011x range */
	public function test_format_0111_safaricom(): void {
		$this->assertSame( '254111345678', WC_MPesa_STK_Push::format_phone( '0111345678' ) );
	}

	public function test_format_0110_safaricom(): void {
		$this->assertSame( '254110345678', WC_MPesa_STK_Push::format_phone( '0110345678' ) );
	}

	// â”€â”€ Invalid formats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_empty_string_returns_null(): void {
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '' ) );
	}

	public function test_letters_only_returns_null(): void {
		$this->assertNull( WC_MPesa_STK_Push::format_phone( 'abcdefghij' ) );
	}

	public function test_too_short_returns_null(): void {
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '0712' ) );
	}

	public function test_landline_nairobi_returns_null(): void {
		// Nairobi landline: 020XXXXXXX (10 digits but starts with 020, not 07/01)
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '0201234567' ) );
	}

	public function test_airtel_0733_returns_null(): void {
		// Airtel: 073x, 074x, 075x, 076x â€” wait, 074x IS Safaricom.
		// Airtel: 073x
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '0733123456' ) );
	}

	public function test_airtel_0100_returns_null(): void {
		// Airtel 010x
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '0100123456' ) );
	}

	public function test_international_non_kenya_returns_null(): void {
		// UK number: +447911123456 â†’ digits 447911123456 (12 chars starting with 44)
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '+447911123456' ) );
	}

	public function test_random_13_digit_number_returns_null(): void {
		$this->assertNull( WC_MPesa_STK_Push::format_phone( '1234567890123' ) );
	}
}


