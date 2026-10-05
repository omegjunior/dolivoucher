<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonnumrefgenerator.class.php';

/** Base class for DoliVoucher series reference models. */
abstract class ModeleNumRefDoliVoucherSeries extends CommonNumRefGenerator
{
	abstract public function getExample();
	abstract public function formatValue(int $year, int $sequence): string;
}
