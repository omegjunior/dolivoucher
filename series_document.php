<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'series', 'read') || $user->socid > 0) accessforbidden();
$eventId = GETPOSTINT('event_id');
$entity = (int) $conf->entity;
$sql = 'SELECT document_name, document_path FROM '.$db->prefix().'dolivoucher_series_event WHERE rowid='.$eventId.' AND entity='.$entity.' AND document_name IS NOT NULL LIMIT 1';
$resql = $db->query($sql); $event = $resql ? $db->fetch_object($resql) : false;
if (!$event) accessforbidden($langs->trans('ErrorRecordNotFound'));
$baseDir = $conf->dolivoucher->multidir_output[$entity] ?? $conf->dolivoucher->dir_output;
$baseReal = realpath((string) $baseDir); $fileReal = realpath(rtrim((string) $baseDir, '/\\').'/'.str_replace(array('..', '\\'), array('', '/'), (string) $event->document_path));
if ($baseReal === false || $fileReal === false || !is_file($fileReal) || !str_starts_with(str_replace('\\', '/', $fileReal), rtrim(str_replace('\\', '/', $baseReal), '/').'/') || basename($fileReal) !== $event->document_name) accessforbidden();

$downloadName = dol_sanitizeFileName(basename($fileReal));
top_httphead('application/pdf');
header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename="'.$downloadName.'"');
header('Cache-Control: Public, must-revalidate');
header('Pragma: public');
header('Content-Length: '.dol_filesize($fileReal));
if (is_object($db)) $db->close();
readfileLowMemory(dol_osencode($fileReal));
exit;
