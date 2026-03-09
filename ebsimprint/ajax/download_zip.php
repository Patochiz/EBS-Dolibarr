<?php
/* Copyright (C) 2025 Patrice GOURMELEN <pgourmelen@diamant-industrie.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ebsimprint/ajax/download_zip.php
 * \ingroup    ebsimprint
 * \brief      Téléchargement ZIP des fichiers EBS générés pour une commande
 */

// Chargement Dolibarr
$res = 0;
if (!$res && file_exists('../../main.inc.php'))       $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php'))     $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php'))  $res = @include '../../../../main.inc.php';
if (!$res && file_exists('../../../../../main.inc.php')) $res = @include '../../../../../main.inc.php';
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once __DIR__ . '/../class/ebsimprint.class.php';

if (!$user->hasRight('commande', 'read')) {
    accessforbidden();
}

$id = GETPOSTINT('id') ?: GETPOSTINT('id', 'GET') ?: (int) ($_GET['id'] ?? 0);
if (empty($id)) {
    dol_print_error(null, 'Paramètre id manquant.');
    exit;
}

$order = new Commande($db);
if ($order->fetch($id) <= 0) {
    dol_print_error($db, 'Commande introuvable.');
    exit;
}

$ebs   = new EbsImprint($db);
$dir   = $ebs->getOutputDir($order);
$folder = $ebs->getProjectFolderName($order);
$files = $ebs->listGeneratedFiles($order);

if (empty($files)) {
    print '<div class="error">Aucun fichier généré pour cette commande. Veuillez d\'abord générer les projets EBS.</div>';
    exit;
}

if (!class_exists('ZipArchive')) {
    // Fallback : liste les fichiers un par un si ZipArchive non disponible
    print '<div class="error">Extension ZipArchive non disponible. Téléchargez les fichiers individuellement.</div>';
    exit;
}

// Créer le ZIP en mémoire (fichier temporaire)
$tmpZip = tempnam(sys_get_temp_dir(), 'ebs_') . '.zip';
$zip    = new ZipArchive();

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    dol_print_error(null, 'Impossible de créer l\'archive ZIP.');
    exit;
}

foreach ($files as $filename) {
    $filePath = $dir . '/' . $filename;
    if (file_exists($filePath)) {
        $zip->addFile($filePath, $folder . '/' . $filename);
    }
}
$zip->close();

// Envoi du ZIP au navigateur
$zipName = 'EBS_' . $folder . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($tmpZip);
unlink($tmpZip);
exit;
