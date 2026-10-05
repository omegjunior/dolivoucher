<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once __DIR__.'/modules_dolivoucherseries.php';

/** Default immutable DVS-{YYYY}-{000001} formatter. Counter allocation stays in the transactional service. */
class mod_dolivoucherseries_standard extends ModeleNumRefDoliVoucherSeries
{
	public $name = 'Standard';
	public $description = 'DoliVoucherSeriesNumberingStandard';
	public $version = 'dolibarr';
	public $error = '';

	public function getExample()
	{
		return 'DVS-'.dol_print_date(dol_now(), '%Y').'-000001';
	}

	public function getNextValue($object)
	{
		$this->error = 'ErrorSeriesCounterServiceRequired';
		return -1;
	}

	public function formatValue(int $year, int $sequence): string
	{
		if ($year < 2000 || $year > 9999 || $sequence < 1 || $sequence > 999999) {
			throw new InvalidArgumentException('ErrorInvalidSeriesSequence');
		}
		return sprintf('DVS-%04d-%06d', $year, $sequence);
	}
}
