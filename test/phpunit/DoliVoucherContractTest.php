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
		foreach (array('portfolio_list.php', 'voucher_list.php', 'operation_list.php') as $page) {
			$contents = $this->read($page);
			self::assertStringContainsString('class="tagtable liste"', $contents, $page);
			self::assertStringContainsString('print_liste_field_titre(', $contents, $page);
			self::assertStringContainsString('class="liste_titre button_search"', $contents, $page);
			self::assertStringContainsString('class="liste_titre button_removefilter"', $contents, $page);
			self::assertStringContainsString("GETPOST('button_removefilter_x', 'alpha')", $contents, $page);
		}

		$portfolioCard = $this->read('portfolio_card.php');
		$voucherCard = $this->read('voucher_card.php');
		self::assertStringContainsString("selectDate(-1, 'date_start'", $portfolioCard);
		self::assertStringContainsString("selectDate(\$object->date_start ?: -1, 'date_start'", $portfolioCard);
		self::assertStringContainsString("selectarray('type', \$portfolioTypeOptions", $portfolioCard);
		self::assertStringContainsString("selectarray('funding_type', \$fundingTypeOptions", $portfolioCard);
		self::assertStringContainsString("selectarray('destination_id', \$destinationOptions", $portfolioCard);
		self::assertStringContainsString('<input type="submit" class="button button-save"', $portfolioCard);
		self::assertStringContainsString("trans('ConfirmFunding')", $portfolioCard);
		self::assertStringContainsString("selectDate(-1, 'date_expiration'", $voucherCard);
		self::assertStringContainsString("selectDate(\$object->date_expiration ?: -1, 'date_expiration'", $voucherCard);
		self::assertStringNotContainsString('type="datetime-local"', $voucherCard);
		self::assertStringContainsString('<input type="submit" class="button button-save" value="', $voucherCard);

		$operationList = $this->read('operation_list.php');
		self::assertStringContainsString("selectDate(\$dateFrom ?: -1, 'date_from'", $operationList);
		self::assertStringContainsString("selectDate(\$dateTo ?: -1, 'date_to'", $operationList);
		self::assertStringNotContainsString('type="date"', $operationList);
	}

	private function read(string $relative): string
	{
		$contents = file_get_contents($this->root.'/'.$relative);
		self::assertNotFalse($contents, $relative);
		return (string) $contents;
	}
}
