<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DoliVoucherSettlementDiagnosticTest extends TestCase
{
	private DoliVoucherSettlementDiagnosticService $service;

	protected function setUp(): void
	{
		$reflection = new ReflectionClass(DoliVoucherSettlementDiagnosticService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
	}

	public function testActiveSettlementIsConsistent(): void
	{
		$result = $this->service->diagnoseRow($this->activeRow(), 1);
		self::assertSame('OK', $result['level']);
		self::assertSame(array('SETTLEMENT_OK'), $result['codes']);
	}

	public function testControlledReversalIsConsistent(): void
	{
		$row = $this->activeRow();
		$row->linked_reversal_count = 1;
		$row->linked_reversal_id = 2;
		$row->linked_reversal_entity = 1;
		$row->linked_reversal_operation_id = 202;
		$row->linked_reversal_voucher_id = 10;
		$row->linked_reversal_invoice_id = 20;
		$row->linked_reversal_amount = '1000.00000000';
		$row->linked_reversal_operation_type = 'CORRECTION';
		$row->linked_reversal_operation_reversal_of = 101;
		$row->linked_reversal_operation_amount = '1000.00000000';
		$row->linked_reversal_balance_delta = '1000.00000000';
		$row->generic_compensation_count = 1;
		$row->generic_compensation_id = 202;
		$row->payment_exists = null;
		$row->payment_entity = null;
		$row->allocation_count = 0;
		$result = $this->service->diagnoseRow($row, 1);
		self::assertSame('OK', $result['level']);

		$reversal = $this->activeRow();
		$reversal->event_type = 'REVERSAL';
		$reversal->operation_type = 'CORRECTION';
		$reversal->operation_reversal_of = 101;
		$reversal->parent_settlement_exists = 1;
		$reversal->parent_entity = 1;
		$reversal->parent_event_type = 'APPLY';
		$reversal->parent_operation_id = 101;
		$reversal->parent_voucher_id = 10;
		$reversal->parent_invoice_id = 20;
		$reversal->parent_amount = '1000.00000000';
		$reversal->payment_exists = null;
		$reversal->payment_entity = null;
		$reversal->allocation_count = 0;
		$result = $this->service->diagnoseRow($reversal, 1);
		self::assertSame('OK', $result['level']);
	}

	/** @dataProvider missingObjectProvider */
	public function testMissingObjectsAreErrors(string $field, string $code): void
	{
		$row = $this->activeRow();
		$row->{$field} = null;
		$result = $this->service->diagnoseRow($row, 1);
		self::assertSame('ERROR', $result['level']);
		self::assertContains($code, $result['codes']);
	}

	public static function missingObjectProvider(): array
	{
		return array(
			'voucher' => array('voucher_exists', 'VOUCHER_MISSING'),
			'operation' => array('operation_exists', 'OPERATION_MISSING'),
			'invoice' => array('invoice_exists', 'INVOICE_MISSING'),
			'payment' => array('payment_exists', 'PAYMENT_MISSING'),
		);
	}

	public function testAllocationAmountModeBankSourceAndEntityDivergences(): void
	{
		$cases = array(
			array('allocation_count', 0, 'ALLOCATION_MISSING'),
			array('allocation_amount', '999.00000000', 'AMOUNT_MISMATCH'),
			array('allocation_invoice_min', 99, 'ALLOCATION_INVOICE_MISMATCH'),
			array('operation_amount', '999.00000000', 'AMOUNT_MISMATCH'),
			array('operation_voucher', 99, 'OPERATION_LINK_MISMATCH'),
			array('payment_mode_code', 'CHQ', 'PAYMENT_MODE_MISMATCH'),
			array('payment_bank_id', 8, 'UNEXPECTED_BANK_LINE'),
			array('request_source', 'UNKNOWN', 'INVALID_REQUEST_SOURCE'),
			array('voucher_entity', 2, 'ENTITY_MISMATCH'),
		);
		foreach ($cases as [$field, $value, $code]) {
			$row = $this->activeRow();
			$row->{$field} = $value;
			$result = $this->service->diagnoseRow($row, 1);
			self::assertSame('ERROR', $result['level'], $field);
			self::assertContains($code, $result['codes'], $field);
		}
	}

	public function testWarningsDoNotHideOtherwiseCoherentSettlement(): void
	{
		$row = $this->activeRow();
		$row->payment_export_id = 12;
		$row->invoice_ref = 'NEW-REF';
		$result = $this->service->diagnoseRow($row, 1);
		self::assertSame('WARNING', $result['level']);
		self::assertContains('PAYMENT_EXPORTED', $result['codes']);
		self::assertContains('INVOICE_REFERENCE_CHANGED', $result['codes']);
	}

	public function testOrphanMultipleAndGenericReversalsAreDetected(): void
	{
		$row = $this->activeRow();
		$row->linked_reversal_count = 2;
		self::assertContains('MULTIPLE_REVERSALS', $this->service->diagnoseRow($row, 1)['codes']);

		$row = $this->activeRow();
		$row->generic_compensation_count = 1;
		$row->generic_compensation_id = 303;
		$result = $this->service->diagnoseRow($row, 1);
		self::assertContains('GENERIC_COMPENSATION_DETECTED', $result['codes']);
		self::assertContains('REVERSAL_MISSING', $result['codes']);

		$row = $this->activeRow();
		$row->event_type = 'REVERSAL';
		$row->operation_type = 'CORRECTION';
		$row->payment_exists = null;
		$row->allocation_count = 0;
		$row->parent_settlement_exists = null;
		self::assertContains('ORPHAN_REVERSAL', $this->service->diagnoseRow($row, 1)['codes']);
	}

	public function testTakePosIsRecognizedButCreatesNoIntegration(): void
	{
		$row = $this->activeRow();
		$row->request_source = 'TAKEPOS';
		self::assertSame('OK', $this->service->diagnoseRow($row, 1)['level']);
	}

	public function testCsvFormulaNeutralizationAndLimitValidation(): void
	{
		foreach (array('=1+1', '+SUM(A1)', '-2+3', '@cmd') as $value) self::assertStringStartsWith("'", DoliVoucherSettlementDiagnosticService::neutralizeCsvValue($value));
		self::assertSame('safe', DoliVoucherSettlementDiagnosticService::neutralizeCsvValue('safe'));
		self::assertSame(1000, DoliVoucherSettlementDiagnosticService::normalizeMaxRows(0));
		self::assertSame(10000, DoliVoucherSettlementDiagnosticService::normalizeMaxRows(99999));
	}

	private function activeRow(): object
	{
		return (object) array(
			'rowid' => 1, 'entity' => 1, 'event_type' => 'APPLY', 'request_source' => 'INVOICE_CARD', 'amount' => '1000.00000000',
			'fk_voucher' => 10, 'fk_operation' => 101, 'fk_facture' => 20, 'fk_paiement' => 30,
			'voucher_exists' => 10, 'voucher_entity' => 1,
			'operation_exists' => 101, 'operation_entity' => 1, 'operation_type' => 'CONSUME', 'operation_amount' => '1000.00000000',
			'operation_voucher' => 10, 'operation_object_type' => 'facture', 'operation_object_id' => 20, 'operation_reversal_of' => null,
			'invoice_exists' => 20, 'invoice_entity' => 1, 'invoice_ref' => 'INV-1', 'invoice_ref_snapshot' => 'INV-1',
			'payment_exists' => 30, 'payment_entity' => 1, 'payment_ref' => 'PAY-1', 'payment_ref_snapshot' => 'PAY-1',
			'payment_mode_code' => 'DVOUCH', 'payment_bank_id' => 0, 'payment_export_id' => 0, 'payment_reconciled' => 0,
			'allocation_count' => 1, 'allocation_amount' => '1000.00000000', 'allocation_invoice_min' => 20, 'allocation_invoice_max' => 20,
			'linked_reversal_count' => 0, 'generic_compensation_count' => 0, 'parent_settlement_exists' => null,
		);
	}
}
