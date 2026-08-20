<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

/** Exact fixed-scale monetary helpers. */
final class DoliVoucherMoney
{
	public const SCALE = 8;

	/** @throws InvalidArgumentException */
	public static function normalize($value, bool $strictlyPositive = false): string
	{
		$normalized = function_exists('price2num') ? (string) price2num((string) $value, 'MU') : str_replace(',', '.', trim((string) $value));
		if (!preg_match('/^(\d{1,16})(?:\.(\d{1,8}))?$/', $normalized, $matches)) {
			throw new InvalidArgumentException('Invalid monetary amount');
		}
		$integer = ltrim($matches[1], '0');
		$integer = $integer === '' ? '0' : $integer;
		$fraction = str_pad($matches[2] ?? '', self::SCALE, '0');
		$result = $integer.'.'.$fraction;
		if ($strictlyPositive && self::compare($result, '0.00000000') <= 0) {
			throw new InvalidArgumentException('Amount must be strictly positive');
		}
		return $result;
	}

	public static function compare(string $left, string $right): int
	{
		$left = self::normalize($left);
		$right = self::normalize($right);
		[$li, $lf] = explode('.', $left);
		[$ri, $rf] = explode('.', $right);
		if (strlen($li) !== strlen($ri)) {
			return strlen($li) <=> strlen($ri);
		}
		$integerComparison = strcmp($li, $ri);
		return $integerComparison !== 0 ? ($integerComparison < 0 ? -1 : 1) : (strcmp($lf, $rf) <=> 0);
	}
}
