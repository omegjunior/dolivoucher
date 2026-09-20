<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DoliVoucherMoneyTest extends TestCase
{
	/** @dataProvider validAmounts */
	public function testExactNormalization(string $input, string $expected): void
	{
		self::assertSame($expected, DoliVoucherMoney::normalize($input));
	}

	/** @return array<string,array{string,string}> */
	public static function validAmounts(): array
	{
		return array('integer' => array('1000', '1000.00000000'), 'scale' => array('12.3456', '12.34560000'), 'maximum' => array('9999999999999999.99999999', '9999999999999999.99999999'));
	}

	/** @dataProvider invalidAmounts */
	public function testInvalidOrNonPositiveAmountIsRejected(string $input): void
	{
		$this->expectException(InvalidArgumentException::class);
		DoliVoucherMoney::normalize($input, true);
	}

	/** @return array<string,array{string}> */
	public static function invalidAmounts(): array
	{
		return array('zero' => array('0'), 'negative' => array('-1'), 'too precise' => array('1.000000001'), 'not numeric' => array('XOF 10'));
	}

	public function testComparisonNeverUsesFloat(): void
	{
		self::assertSame(1, DoliVoucherMoney::compare('9007199254740993.00000000', '9007199254740992.99999999'));
		self::assertSame(-1, DoliVoucherMoney::compare('0.00000001', '0.00000002'));
		self::assertSame(0, DoliVoucherMoney::compare('100', '100.00000000'));
	}
}
