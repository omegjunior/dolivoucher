<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

if (getenv('DOLIVOUCHER_SQL_SMOKE') !== '1') {
	fwrite(STDERR, "Set DOLIVOUCHER_SQL_SMOKE=1 to create and remove an isolated test database.\n");
	exit(2);
}

require_once dirname(__DIR__, 3).'/master.inc.php';

$originalResult = $db->query('SELECT DATABASE() AS database_name');
$originalRow = $originalResult ? $db->fetch_object($originalResult) : false;
if (!$originalRow || empty($originalRow->database_name)) {
	throw new RuntimeException('Unable to identify the original database');
}
$originalDatabase = (string) $originalRow->database_name;
$testDatabase = 'dolivoucher_schema_test_'.date('YmdHis').'_'.bin2hex(random_bytes(3));
if (!preg_match('/^dolivoucher_schema_test_[0-9]{14}_[a-f0-9]{6}$/', $testDatabase)) {
	throw new RuntimeException('Unsafe test database name');
}

$created = false;
try {
	if (!$db->query('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
		throw new RuntimeException('Unable to create isolated schema: '.$db->lasterror());
	}
	$created = true;
	if (!$db->query('USE `'.$testDatabase.'`')) {
		throw new RuntimeException('Unable to select isolated schema');
	}
	$files = array(
		'llx_dolivoucher_operation.sql',
		'llx_dolivoucher_portfolio.sql',
		'llx_dolivoucher_voucher.sql',
		'llx_dolivoucher_operation.key.sql',
		'llx_dolivoucher_portfolio.key.sql',
		'llx_dolivoucher_voucher.key.sql',
	);
	foreach ($files as $file) {
		$sql = (string) file_get_contents(dirname(__DIR__).'/sql/'.$file);
		foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
			if (!$db->query($statement)) {
				throw new RuntimeException($file.': '.$db->lasterror());
			}
		}
	}
	$result = $db->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema='".$db->escape($testDatabase)."' AND table_name IN ('llx_dolivoucher_portfolio','llx_dolivoucher_voucher','llx_dolivoucher_operation')");
	$row = $result ? $db->fetch_object($result) : false;
	if (!$row || (int) $row->table_count !== 3) {
		throw new RuntimeException('Schema smoke test did not create all three tables');
	}
	echo "DoliVoucher SQL schema smoke test: OK (3 tables).\n";
} finally {
	$db->query('USE `'.$db->escape($originalDatabase).'`');
	if ($created) {
		$db->query('DROP DATABASE `'.$testDatabase.'`');
	}
}
