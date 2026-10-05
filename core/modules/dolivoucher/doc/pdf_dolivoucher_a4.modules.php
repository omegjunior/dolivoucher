<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

/** Native TCPDF generator: four approximately A6 vouchers on each A4 portrait page. */
class pdf_dolivoucher_a4
{
	public string $error = '';
	/** @var array<string,string> */
	public array $result = array();

	/**
	 * @param object $series
	 * @param array<int,object> $vouchers
	 */
	public function write_file($series, array $vouchers, Translate $outputlangs, string $file, string $issuerName, string $portfolioLabel, string $currency, int $revision, ?int $dateStart = null, ?int $dateEnd = null): int
	{
		global $conf, $mysoc, $user;
		if (!$vouchers) {
			$this->error = 'ErrorEmptyVoucherSelection';
			return -1;
		}
		$dir = dirname($file);
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			$this->error = 'ErrorCanNotCreateDir';
			return -1;
		}
		$outputlangs->loadLangs(array('main', 'companies', 'dolivoucher@dolivoucher'));
		$pdf = pdf_getInstance('A4', 'mm', 'P');
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
		$pdf->SetTitle($outputlangs->transnoentities('VoucherSeriesDocument').' '.$series->ref);
		$pdf->SetMargins(8, 8, 8);
		$pdf->SetAutoPageBreak(false);
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 9);
		$logo = '';
		if (!empty($mysoc->logo) && is_readable($conf->mycompany->dir_output.'/logos/'.$mysoc->logo)) {
			$logo = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
		}
		$cellWidth = 97.0;
		$cellHeight = 136.0;
		foreach ($vouchers as $index => $voucher) {
			$slot = $index % 4;
			if ($slot === 0) $pdf->AddPage();
			$x = 8.0 + (($slot % 2) * $cellWidth);
			$y = 8.0 + ((int) floor($slot / 2) * $cellHeight);
			$this->drawVoucher($pdf, $outputlangs, $series, $voucher, $x, $y, $cellWidth, $cellHeight, $issuerName, $portfolioLabel, $currency, $logo, $revision, $dateStart, $dateEnd);
		}
		$pageCount = $pdf->getNumPages();
		for ($page = 1; $page <= $pageCount; $page++) {
			$pdf->setPage($page);
			$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
			$pdf->SetXY(8, 286);
			$pdf->Cell(194, 4, $outputlangs->transnoentities('Page').' '.$page.'/'.$pageCount.' - '.$series->ref.' - R'.$revision, 0, 0, 'C');
		}
		try {
			$pdf->Output($file, 'F');
		} catch (Throwable $e) {
			$this->error = 'ErrorPdfGenerationFailed';
			dol_syslog(__METHOD__.': '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		if (!is_file($file) || filesize($file) <= 0) {
			$this->error = 'ErrorPdfGenerationFailed';
			return -1;
		}
		dolChmod($file);
		$this->result = array('fullpath' => $file);
		return 1;
	}

	private function drawVoucher($pdf, Translate $langs, $series, $voucher, float $x, float $y, float $w, float $h, string $issuerName, string $portfolioLabel, string $currency, string $logo, int $revision, ?int $dateStart, ?int $dateEnd): void
	{
		$pdf->SetDrawColor(150, 150, 150);
		$pdf->SetLineStyle(array('width' => 0.15, 'dash' => '2,2'));
		$pdf->Rect($x, $y, $w, $h);
		$pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));
		if ($logo !== '') $pdf->Image($logo, $x + 4, $y + 4, 18, 0, '', '', '', false, 150);
		$pdf->SetFont(pdf_getPDFFont($langs), 'B', 14);
		$pdf->SetXY($x + 4, $y + 5);
		$pdf->Cell($w - 8, 7, $langs->transnoentities('GiftVoucher'), 0, 1, 'C');
		$pdf->SetFont(pdf_getPDFFont($langs), '', 9);
		$pdf->SetXY($x + 4, $y + 15);
		$pdf->Cell($w - 8, 5, $langs->convToOutputCharset($issuerName), 0, 1, 'C');
		$pdf->SetFont(pdf_getPDFFont($langs), 'B', 18);
		$pdf->SetXY($x + 4, $y + 25);
		$pdf->Cell($w - 8, 10, price($voucher->initial_amount, 0, $langs, 1, -1, -1, $currency), 0, 1, 'C');
		$pdf->SetFont(pdf_getPDFFont($langs), '', 8);
		$pdf->SetXY($x + 5, $y + 39);
		$pdf->Cell($w - 10, 5, $langs->transnoentities('Series').': '.$series->ref, 0, 1, 'L');
		if ($portfolioLabel !== '') {
			$pdf->SetXY($x + 5, $y + 44);
			$pdf->Cell($w - 10, 5, $langs->transnoentities('Portfolio').': '.$langs->convToOutputCharset($portfolioLabel), 0, 1, 'L');
		}
		if ($dateStart !== null || $dateEnd !== null) {
			$period = ($dateStart === null ? '…' : dol_print_date($dateStart, 'day')).' - '.($dateEnd === null ? '…' : dol_print_date($dateEnd, 'day'));
			$pdf->SetXY($x + 5, $y + 49);
			$pdf->Cell($w - 10, 5, $langs->transnoentities('ValidityPeriod').': '.$period, 0, 1, 'L');
		}
		if (!empty($voucher->date_expiration)) {
			$pdf->SetXY($x + 5, $y + 54);
			$pdf->Cell($w - 10, 5, $langs->transnoentities('ExpirationDate').': '.dol_print_date($voucher->date_expiration, 'day'), 0, 1, 'L');
		}
		$style = array('position' => '', 'align' => 'C', 'stretch' => false, 'fitwidth' => true, 'cellfitalign' => '', 'border' => false, 'hpadding' => 'auto', 'vpadding' => 'auto', 'fgcolor' => array(0, 0, 0), 'bgcolor' => false, 'text' => true, 'font' => 'helvetica', 'fontsize' => 8, 'stretchtext' => 4);
		$pdf->write1DBarcode((string) $voucher->barcode, 'C128', $x + 7, $y + 61, $w - 14, 28, 0.35, $style, 'N');
		$pdf->SetFont(pdf_getPDFFont($langs), '', 7);
		$pdf->SetXY($x + 5, $y + $h - 14);
		$pdf->Cell($w - 10, 4, $voucher->ref.' - R'.$revision, 0, 1, 'C');
		// Reserved blank area for future approved regulatory wording.
		$pdf->Rect($x + 5, $y + 96, $w - 10, 22, '');
	}
}
