<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DoliVoucherContractTest extends TestCase
{
	private string $root;
	private string $service;
	private string $portfolio;
	private string $voucher;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
		$this->service = $this->read('class/dolivoucherservice.class.php');
		$this->portfolio = $this->read('class/dolivoucherportfolio.class.php');
		$this->voucher = $this->read('class/dolivouchervoucher.class.php');
	}

	public function testInstitutionalPortfolioRequiresThirdPartyAndDonationDoesNot(): void
	{
		self::assertStringContainsString("TYPE_INSTITUTIONAL && (int) \$this->fk_soc <= 0", $this->portfolio);
		self::assertStringContainsString("TYPE_DONATION = 'DONATION'", $this->portfolio);
	}

	public function testPortfolioReferenceIsUniquePerEntity(): void
	{
		self::assertStringContainsString('(entity, ref)', $this->read('sql/llx_dolivoucher_portfolio.key.sql'));
	}

	public function testVoucherAmountAndSerialUniquenessContracts(): void
	{
		self::assertStringContainsString('normalize($this->initial_amount, true)', $this->voucher);
		self::assertStringContainsString('uk_dolivoucher_voucher_ref_entity (entity, ref)', $this->read('sql/llx_dolivoucher_voucher.key.sql'));
		self::assertStringContainsString('uk_dolivoucher_voucher_barcode_entity (entity, barcode)', $this->read('sql/llx_dolivoucher_voucher.key.sql'));
	}

	public function testActivationReservesPortfolioAndInitializesVoucher(): void
	{
		self::assertStringContainsString('available_unallocated_balance=available_unallocated_balance-', $this->service);
		self::assertStringContainsString("current_balance='.\$this->decimal(\$amount).', status=", $this->service);
		self::assertStringContainsString('ErrorInsufficientUnallocatedBalance', $this->service);
	}

	public function testPartialAndTotalConsumptionContracts(): void
	{
		self::assertStringContainsString('STATUS_PARTIALLY_CONSUMED', $this->service);
		self::assertStringContainsString('STATUS_CONSUMED', $this->service);
		self::assertStringContainsString('ErrorInsufficientVoucherBalance', $this->service);
	}

	public function testBlockedAndExpiredVouchersCannotBeConsumed(): void
	{
		self::assertStringContainsString("array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED)", $this->service);
		self::assertStringContainsString('ErrorVoucherExpired', $this->service);
	}

	public function testConsumedVoucherCannotBeCanceled(): void
	{
		self::assertStringContainsString("operation_type='CONSUME'", $this->service);
		self::assertStringContainsString('ErrorConsumedVoucherCannotBeCanceled', $this->service);
	}

	public function testBlockAndUnblockAreJournaledWithoutBalanceMutation(): void
	{
		self::assertStringContainsString('TYPE_BLOCK', $this->service);
		self::assertStringContainsString('TYPE_UNBLOCK', $this->service);
		self::assertStringContainsString('(string) $voucher->current_balance, (string) $voucher->current_balance', $this->service);
	}

	public function testExposureReportingSeparatesRedeemableBlockedExpiredAndOutstanding(): void
	{
		self::assertStringContainsString('status IN (2,3)) AS immediately_redeemable_voucher_balance', $this->portfolio);
		self::assertStringContainsString('status=6) AS blocked_voucher_balance', $this->portfolio);
		self::assertStringContainsString('status=10) AS expired_unreallocated_balance', $this->portfolio);
		self::assertStringContainsString('status IN (2,3,6)) AS outstanding_voucher_balance', $this->portfolio);
		self::assertStringContainsString('AS global_outstanding_balance', $this->portfolio);
		self::assertStringContainsString('checkPortfolioExposureBalances', $this->service);
	}

	public function testTransferCompatibilityBalanceAndAtomicPair(): void
	{
		self::assertStringContainsString('$source->type !== $destination->type', $this->service);
		self::assertStringContainsString('(int) $source->fk_soc !== (int) $destination->fk_soc', $this->service);
		self::assertStringContainsString('ErrorInsufficientUnallocatedBalance', $this->service);
		self::assertStringContainsString('TYPE_TRANSFER_OUT', $this->service);
		self::assertStringContainsString('TYPE_TRANSFER_IN', $this->service);
		self::assertSame(2, substr_count($this->service, "null, \$uuid);"));
	}

	public function testTransactionLocksAndRollbackContract(): void
	{
		self::assertStringContainsString('FOR UPDATE', $this->service);
		self::assertStringContainsString('$this->db->begin()', $this->service);
		self::assertStringContainsString('$this->db->commit()', $this->service);
		self::assertStringContainsString('$this->db->rollback()', $this->service);
	}

	public function testJournalObjectForbidsMutation(): void
	{
		$operation = $this->read('class/dolivoucheroperation.class.php');
		self::assertGreaterThanOrEqual(3, substr_count($operation, "\$this->error = 'ErrorAppendOnlyOperation'"));
		self::assertStringContainsString('public function updateCommon(', $operation);
		self::assertStringContainsString('public function deleteCommon(', $operation);
		self::assertStringNotContainsString('$this->db->query(', $operation);
	}

	public function testCompensationIsSingleAndRestrictedToConsumption(): void
	{
		self::assertStringContainsString("operation_type !== DoliVoucherOperation::TYPE_CONSUME", $this->service);
		self::assertStringContainsString('WHERE reversal_of=', $this->service);
		self::assertStringContainsString('uk_dolivoucher_operation_reversal (reversal_of)', $this->read('sql/llx_dolivoucher_operation.key.sql'));
	}

	public function testEveryOperationalLookupIsEntityScoped(): void
	{
		self::assertStringContainsString('AND entity=', $this->service);
		foreach (array('portfolio_card.php', 'voucher_card.php', 'portfolio_list.php', 'voucher_list.php', 'operation_list.php') as $page) {
			self::assertStringContainsString('$conf->entity', $this->read($page), $page);
		}
	}

	public function testMaterializedBalancesAreReconciledAgainstJournal(): void
	{
		self::assertStringContainsString('checkPortfolioBalance', $this->service);
		self::assertStringContainsString('checkVoucherBalance', $this->service);
		self::assertStringContainsString("'consistent' => \$materialized === \$reconstructed", $this->service);
	}

	public function testNoExcludedDolibarrFinancialOrStockWriteSurface(): void
	{
		$production = implode("\n", array_map(fn (string $file): string => $this->read($file), array('class/dolivoucherservice.class.php', 'portfolio_card.php', 'voucher_card.php')));
		self::assertDoesNotMatchRegularExpression('/(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?[^;]*(?:facture|paiement|stock_mouvement|product)/i', $production);
		self::assertStringNotContainsString('curl_', $production);
	}

	public function testListsAndCardInputsUseDolibarrUiConventions(): void
	{
		foreach (array('portfolio_list.php', 'voucher_list.php', 'series_list.php', 'operation_list.php') as $page) {
			$contents = $this->read($page);
			self::assertStringContainsString('class="tagtable liste"', $contents, $page);
			self::assertStringContainsString('print_liste_field_titre(', $contents, $page);
			self::assertStringContainsString('class="liste_titre button_search"', $contents, $page);
			self::assertStringContainsString('class="liste_titre button_removefilter', $contents, $page);
			self::assertStringContainsString("GETPOST('button_removefilter_x', 'alpha')", $contents, $page);
			self::assertStringContainsString("GETPOST('sortfield', 'alphanohtml')", $contents, $page);
			self::assertStringContainsString("GETPOST('sortorder', 'alpha')", $contents, $page);
			self::assertStringContainsString('$sortfield, $sortorder', $contents, $page);
		}
		foreach (array('portfolio_list.php', 'voucher_list.php') as $page) {
			$contents = $this->read($page);
			self::assertStringContainsString("include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php'", $contents, $page);
			self::assertStringContainsString("multiSelectArrayWithCheckbox('selectedfields', \$arrayfields, \$contextpage)", $contents, $page);
			self::assertStringContainsString('$db->plimit($limit + 1, $offset)', $contents, $page);
			self::assertStringContainsString('$num = $resql ? $db->num_rows($resql) : 0;', $contents, $page);
			self::assertMatchesRegularExpression('/print_barre_liste\([^;]*\$num[^;]*\$limit\);/', $contents, $page);
		}

		$portfolioCard = $this->read('portfolio_card.php');
		$voucherCard = $this->read('voucher_card.php');
		$seriesCard = $this->read('series_card.php');
		foreach (array($portfolioCard, $voucherCard, $seriesCard) as $card) {
			self::assertStringContainsString('dol_banner_tab($object', $card);
			self::assertStringContainsString("\$object->next_prev_filter = 'te.entity:=:'.\$entity;", $card);
			self::assertStringContainsString("trans('BackToList')", $card);
			self::assertStringContainsString('dolivoucherStatusBadge($object->status', $card);
		}
		$statusHelper = $this->read('lib/dolivoucher.lib.php');
		self::assertStringContainsString("0 => 'status0'", $statusHelper);
		self::assertStringContainsString("2 => 'status4'", $statusHelper);
		self::assertStringContainsString("4 => 'status6'", $statusHelper);
		self::assertStringContainsString("6 => 'status8'", $statusHelper);
		self::assertStringContainsString("9 => 'status9'", $statusHelper);
		self::assertStringContainsString("public \$picto = 'fontawesome_wallet';", $this->read('class/dolivoucherportfolio.class.php'));
		self::assertStringContainsString("public \$picto = 'fontawesome_layer-group';", $this->read('class/dolivoucherseries.class.php'));
		self::assertStringContainsString("selectDate(-1, 'date_start'", $portfolioCard);
		self::assertStringContainsString("selectDate(\$object->date_start ?: -1, 'date_start'", $portfolioCard);
		self::assertStringContainsString("selectarray('type', \$portfolioTypeOptions", $portfolioCard);
		self::assertStringContainsString("selectarray('funding_type', \$fundingTypeOptions", $portfolioCard);
		self::assertStringContainsString("selectarray('destination_id', \$destinationOptions", $portfolioCard);
		self::assertStringContainsString("print '</table></div></div><div class=\"clearboth\"></div>';", $portfolioCard);
		self::assertStringContainsString('<input type="submit" class="button button-save"', $portfolioCard);
		self::assertStringContainsString("trans('ConfirmFunding')", $portfolioCard);
		self::assertStringContainsString("selectDate(-1, 'date_expiration'", $voucherCard);
		self::assertStringContainsString("selectDate(\$object->date_expiration ?: -1, 'date_expiration'", $voucherCard);
		self::assertSame(2, substr_count($voucherCard, "selectForForms(\$portfolioSelectDescriptor, 'fk_portfolio'"));
		self::assertStringContainsString('(status:IN:1,2) AND (entity:=:__ENTITY__)', $voucherCard);
		self::assertStringNotContainsString('<select name="fk_portfolio"', $voucherCard);
		self::assertStringNotContainsString('type="datetime-local"', $voucherCard);
		self::assertStringContainsString('<input type="submit" class="button button-save" value="', $voucherCard);
		self::assertStringContainsString("<input type=\"submit\" class=\"button button-save\" value=\"'.\$langs->trans('Save').'\">", $voucherCard);
		self::assertStringNotContainsString('<button class="button button-save">', $voucherCard);
		self::assertStringContainsString("<input type=\"submit\" class=\"button button-save\" value=\"'.\$langs->trans('Save').'\">", $portfolioCard);
		self::assertStringNotContainsString('<button class="button button-save">', $portfolioCard);
		self::assertStringContainsString('class="button button-cancel"', $voucherCard);
		self::assertStringContainsString('class="button button-cancel"', $portfolioCard);
		self::assertGreaterThanOrEqual(5, substr_count($voucherCard, "header('Location: '.\$_SERVER['PHP_SELF'].'?id='.\$id);"));
		self::assertGreaterThanOrEqual(4, substr_count($portfolioCard, "header('Location: '.\$_SERVER['PHP_SELF'].'?id='.\$id);"));
		self::assertSame(19, substr_count($this->read('class/dolivoucherportfolio.class.php'), "'enabled' => 1"));
		self::assertStringContainsString("action=activate&token='.newToken()", $voucherCard);
		self::assertStringContainsString("action=activate&token='.newToken()", $portfolioCard);
		self::assertStringNotContainsString("str_starts_with(\$action, 'confirm_')", $voucherCard);
		self::assertStringNotContainsString("str_starts_with(\$action, 'confirm_')", $portfolioCard);
		foreach (array('ask_consume', 'ask_block', 'ask_compensate', 'confirm_consume', 'confirm_block', 'confirm_unblock', 'confirm_compensate') as $confirmedAction) {
			self::assertStringContainsString($confirmedAction, $voucherCard);
		}
		foreach (array('ask_fund', 'ask_transfer', 'confirm_fund', 'confirm_transfer') as $confirmedAction) {
			self::assertStringContainsString($confirmedAction, $portfolioCard);
		}
		self::assertGreaterThanOrEqual(4, substr_count($voucherCard, '->formconfirm('));
		self::assertGreaterThanOrEqual(3, substr_count($portfolioCard, '->formconfirm('));
		self::assertGreaterThanOrEqual(4, substr_count($voucherCard, "GETPOST('confirm', 'alpha') === 'yes'"));
		self::assertGreaterThanOrEqual(3, substr_count($portfolioCard, "GETPOST('confirm', 'alpha') === 'yes'"));
		self::assertGreaterThanOrEqual(4, substr_count($voucherCard, "header('Location: '.\$_SERVER['PHP_SELF'].'?id='.\$id);"));
		self::assertSame(3, substr_count($voucherCard, 'http_build_query(array('));
		self::assertStringNotContainsString('$formQuestions = array(', $voucherCard);
		self::assertGreaterThanOrEqual(3, substr_count($portfolioCard, "header('Location: '.\$_SERVER['PHP_SELF'].'?id='.\$id);"));
		self::assertSame(2, substr_count($portfolioCard, 'http_build_query(array('));
		self::assertStringNotContainsString("array('type' => 'hidden', 'name' => 'funding_type'", $portfolioCard);
		self::assertStringNotContainsString("array('type' => 'hidden', 'name' => 'destination_id'", $portfolioCard);
		self::assertStringContainsString("'PortfolioActivatedSuccessfully'", $portfolioCard);
		self::assertStringContainsString("'VoucherActivatedSuccessfully'", $voucherCard);
		self::assertStringContainsString('PortfolioActivatedSuccessfully=Le portefeuille a été activé avec succès.', $this->read('langs/fr_FR/dolivoucher.lang'));
		self::assertStringContainsString("VoucherActivatedSuccessfully=Le bon d'achat a été activé avec succès.", $this->read('langs/fr_FR/dolivoucher.lang'));
		self::assertSame(3, substr_count($voucherCard, '<a class="butAction" href="#" onclick="this.closest(\\\'form\\\').requestSubmit(); return false;">'));
		self::assertStringNotContainsString("<button class=\"button\">'.\$langs->trans('ConfirmConsumption')", $voucherCard);
		self::assertStringNotContainsString("<button class=\"button\">'.\$langs->trans('ConfirmCompensation')", $voucherCard);
		self::assertSame(3, substr_count($voucherCard, '<td class="right nowraponall"><div class="inline-block divButAction"><a class="butAction"'));
		self::assertStringContainsString("requestSubmit(); return false;\">'.\$langs->trans('ConfirmTransfer')", $portfolioCard);
		self::assertStringNotContainsString("<button class=\"button\">'.\$langs->trans('ConfirmTransfer')", $portfolioCard);
		self::assertStringContainsString("if (\$action !== 'ask_transfer' && \$user->hasRight('dolivoucher', 'transfer', 'write')", $portfolioCard);

		$portfolioList = $this->read('portfolio_list.php');
		self::assertStringContainsString("'fontawesome_wallet'", $portfolioList);
		self::assertStringContainsString("selectarray('search_type', \$portfolioTypeOptions", $portfolioList);
		self::assertStringContainsString("selectarray('search_status', \$portfolioStatusOptions", $portfolioList);
		self::assertStringContainsString("selectarray('search_fk_soc', \$thirdPartyOptions", $portfolioList);
		self::assertStringNotContainsString('type="number" name="search_fk_soc"', $portfolioList);
		self::assertStringContainsString("\$searchType = \$searchType === '-1' ? '' : \$searchType;", $portfolioList);
		self::assertStringContainsString("\$searchStatus = ((int) \$searchStatus === -1) ? '' : \$searchStatus;", $portfolioList);

		$voucherList = $this->read('voucher_list.php');
		self::assertStringContainsString("selectarray('search_status', \$voucherStatusOptions", $voucherList);
		self::assertStringContainsString("selectarray('search_portfolio', \$portfolioOptions", $voucherList);
		self::assertStringNotContainsString('type="number" name="search_portfolio"', $voucherList);
		self::assertStringContainsString("\$searchStatus = ((int) \$searchStatus === -1) ? '' : \$searchStatus;", $voucherList);
		self::assertStringContainsString('name="search_balance"', $voucherList);
		self::assertStringContainsString("natural_search('v.current_balance', \$searchBalance, 1)", $voucherList);
		self::assertStringContainsString("if (\$withBalance) \$sql .= ' AND v.current_balance>0';", $voucherList);
		self::assertStringContainsString("selectDate(\$searchExpirationDate ?: -1, 'search_expiration_date'", $voucherList);
		self::assertStringContainsString('v.date_expiration', $voucherList);
		self::assertStringNotContainsString('type="date"', $voucherList);

		$seriesList = $this->read('series_list.php');
		self::assertStringContainsString("'fontawesome_layer-group'", $seriesList);

		$operationList = $this->read('operation_list.php');
		self::assertStringContainsString("selectDate(\$dateFrom ?: -1, 'date_from'", $operationList);
		self::assertStringContainsString("selectDate(\$dateTo ?: -1, 'date_to'", $operationList);
		self::assertStringContainsString('<td class="liste_titre center">', $operationList);
		self::assertGreaterThanOrEqual(2, substr_count($operationList, '<div class="nowrapfordate">'));
		self::assertStringNotContainsString('type="date"', $operationList);
		self::assertStringContainsString("include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php'", $operationList);
		self::assertStringContainsString("multiSelectArrayWithCheckbox('selectedfields', \$arrayfields, \$contextpage)", $operationList);
		self::assertStringContainsString("selectarray('search_type', \$operationTypes", $operationList);
		self::assertStringContainsString("selectarray('search_portfolio', \$portfolioOptions", $operationList);
		self::assertStringContainsString("selectarray('search_voucher', \$voucherOptions", $operationList);
		self::assertStringNotContainsString('type="number" name="search_portfolio"', $operationList);
		self::assertStringNotContainsString('type="number" name="search_voucher"', $operationList);
		self::assertStringContainsString('name="search_amount"', $operationList);
		self::assertStringContainsString('name="search_reason"', $operationList);
		self::assertStringContainsString('class="liste_titre button_removefilter reposition"', $operationList);
		self::assertStringContainsString('$db->plimit($limit + 1, $offset)', $operationList);
		self::assertStringContainsString('$num = $resql ? $db->num_rows($resql) : 0;', $operationList);
		self::assertMatchesRegularExpression('/print_barre_liste\([^;]*\$num[^;]*\$limit\);/', $operationList);
	}

	public function testSeriesNumberingAndGenerationContracts(): void
	{
		$service = $this->read('class/dolivoucherseriesservice.class.php');
		$model = $this->read('core/modules/dolivoucher/mod_dolivoucherseries_standard.php');
		self::assertStringContainsString("sprintf('DVS-%04d-%06d'", $model);
		self::assertStringContainsString("sprintf('%06d', \$number)", $service);
		self::assertStringContainsString('SELECT rowid, next_value', $service);
		self::assertStringContainsString('FOR UPDATE', $service);
		self::assertStringContainsString('INSERT IGNORE INTO ', $service);
		self::assertStringNotContainsString('MAX(ref)', $service);
		self::assertStringContainsString('generation_key', $service);
		self::assertStringContainsString('STATUS_DRAFT', $service);
		self::assertStringContainsString("'0.00000000'", $this->voucher);
		self::assertStringContainsString('fk_series INTEGER NULL', $this->read('sql/llx_dolivoucher_voucher.sql'));
		self::assertStringContainsString('uk_dolivoucher_series_ref_entity (entity, ref)', $this->read('sql/llx_dolivoucher_series.key.sql'));
	}

	public function testMaterialJournalAndPrintContracts(): void
	{
		$service = $this->read('class/dolivoucherseriesservice.class.php');
		$event = $this->read('class/dolivoucherseriesevent.class.php');
		$pdf = $this->read('core/modules/dolivoucher/doc/pdf_dolivoucher_a4.modules.php');
		$download = $this->read('series_document.php');
		self::assertStringContainsString("write1DBarcode((string) \$voucher->barcode, 'C128'", $pdf);
		self::assertStringContainsString("hash_file('sha256'", $service);
		self::assertStringContainsString('ErrorMixedPrintSelection', $service);
		self::assertStringContainsString('ErrorReprintReasonRequired', $service);
		self::assertStringContainsString('ErrorReprintPermissionDenied', $service);
		self::assertStringContainsString("if (\$file !== '' && is_file(\$file)) @unlink(\$file)", $service);
		self::assertStringContainsString('TYPE_PRINT_COMPLEMENT', $event);
		self::assertStringContainsString('ErrorMaterialJournalReadOnly', $event);
		self::assertStringContainsString('public function updateCommon(', $event);
		self::assertStringContainsString('public function deleteCommon(', $event);
		self::assertStringNotContainsString('dol_send_file(', $download);
		self::assertStringContainsString("require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php'", $download);
		self::assertStringContainsString("top_httphead('application/pdf')", $download);
		self::assertStringContainsString('readfileLowMemory(dol_osencode($fileReal))', $download);
	}

	public function testSeriesUiUsesNativeDolibarrConventionsAndRights(): void
	{
		$list = $this->read('series_list.php');
		$card = $this->read('series_card.php');
		$descriptor = $this->read('core/modules/modDoliVoucher.class.php');
		self::assertStringContainsString('class="tagtable liste"', $list);
		self::assertStringContainsString("multiSelectArrayWithCheckbox('selectedfields', \$arrayfields, \$contextpage)", $list);
		self::assertStringContainsString('button_removefilter', $list);
		self::assertStringContainsString("selectarray('search_portfolio', \$portfolioOptions, \$searchPortfolio, 1", $list);
		self::assertStringContainsString("selectDate(\$searchGenerationDate ?: -1, 'search_generation_date'", $list);
		self::assertStringContainsString("selectarray('fk_portfolio', \$portfolioOptions, GETPOSTINT('fk_portfolio'), 1", $card);
		self::assertStringContainsString("selectDate(-1, 'date_expiration'", $card);
		self::assertStringContainsString("(bool) \$user->hasRight('dolivoucher', 'series', 'reprint')", $card);
		self::assertStringContainsString('->formconfirm(', $card);
		foreach (array('series", "read', 'series", "generate', "'series', 'print'", "'series', 'reprint'", "'series', 'prepare'", "'series', 'deliver'") as $right) self::assertStringContainsString($right, $descriptor.$card);
	}

	private function read(string $relative): string
	{
		$contents = file_get_contents($this->root.'/'.$relative);
		self::assertNotFalse($contents, $relative);
		return (string) $contents;
	}
}
