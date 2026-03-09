<?php
/* Copyright (C) 2025 Patrice GOURMELEN <pgourmelen@diamant-industrie.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ebsimprint/admin/setup.php
 * \ingroup    ebsimprint
 * \brief      Page de configuration du module EBS Impression
 */

// Chargement de l'environnement Dolibarr
$res = 0;
if (!$res && file_exists('../../../main.inc.php'))     $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php'))  $res = @include '../../../../main.inc.php';
if (!$res && file_exists('../../../../../main.inc.php')) $res = @include '../../../../../main.inc.php';
if (!$res) die("Include of main fails");

// Vérifications sécurité
if (!$user->admin) {
    accessforbidden();
}

if (!isModEnabled('ebsimprint')) {
    accessforbidden();
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

$langs->loadLangs(array('admin', 'ebsimprint@ebsimprint'));

$action = GETPOST('action', 'aZ09');

// ─── Traitement de la sauvegarde ──────────────────────────────────────────────
if ($action === 'update') {
    $error = 0;

    $params = array(
        'EBSIMPRINT_PRINT_HEAD_NAME' => GETPOST('EBSIMPRINT_PRINT_HEAD_NAME', 'alphanohtml'),
        'EBSIMPRINT_RESOLUTION'      => GETPOSTINT('EBSIMPRINT_RESOLUTION'),
        'EBSIMPRINT_PRESSURE'        => GETPOSTINT('EBSIMPRINT_PRESSURE'),
        'EBSIMPRINT_DOT_SIZE'        => GETPOSTINT('EBSIMPRINT_DOT_SIZE'),
        'EBSIMPRINT_SKIP_PRODUCT_ID' => GETPOST('EBSIMPRINT_SKIP_PRODUCT_ID', 'alphanohtml'),
    );

    foreach ($params as $key => $value) {
        $res = dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity);
        if ($res < 0) {
            $error++;
        }
    }

    if (!$error) {
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('Error'), null, 'errors');
    }
}

// ─── Têtes d'impression disponibles (depuis PrintHeads.xml) ───────────────────
$availableHeads = array(
    'EBS-Electromagnetic-16'  => 'EBS Électromagnétique 16 buses',
    'EBS-Electromagnetic-32'  => 'EBS Électromagnétique 32 buses',
    'EBS-Electromagnetic-64'  => 'EBS Électromagnétique 64 buses',
    'EBS-Electromagnetic-128' => 'EBS Électromagnétique 128 buses',
    'Seiko_irh1513d'          => 'Seiko IRH1513D (510 buses)',
    'XJ128_200'               => 'Xaar XJ128/200 (128 buses)',
    'XJ128_360'               => 'Xaar XJ128/360 (128 buses)',
    'XJ500_80'                => 'Xaar XJ500/80 (500 buses)',
);

// ─── Affichage ────────────────────────────────────────────────────────────────
$page_name = 'EbsImprintSetup';
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">'
    . $langs->trans('BackToModuleList') . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'fa-print');

// Navigation
$head = array();
$head[0][0] = DOL_URL_ROOT . '/custom/ebsimprint/admin/setup.php';
$head[0][1] = $langs->trans('Settings');
$head[0][2] = 'setup';

dol_fiche_head($head, 'setup', '', -1);

// ── Formulaire de configuration ───────────────────────────────────────────────
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('Parameter') . '</td>';
print '<td>' . $langs->trans('Value') . '</td>';
print '<td>' . $langs->trans('Description') . '</td>';
print '</tr>';

// Tête d'impression
$currentHead = getDolGlobalString('EBSIMPRINT_PRINT_HEAD_NAME', 'EBS-Electromagnetic-32');
print '<tr class="oddeven">';
print '<td><label for="EBSIMPRINT_PRINT_HEAD_NAME"><strong>' . $langs->trans('EbsPrintHeadName') . '</strong></label></td>';
print '<td>';
print '<select id="EBSIMPRINT_PRINT_HEAD_NAME" name="EBSIMPRINT_PRINT_HEAD_NAME" class="flat">';
foreach ($availableHeads as $val => $label) {
    $sel = ($val === $currentHead) ? ' selected' : '';
    print '<option value="' . dol_escape_htmltag($val) . '"' . $sel . '>' . dol_escape_htmltag($label) . '</option>';
}
print '</select>';
print '</td>';
print '<td class="opacitymedium">' . $langs->trans('EbsPrintHeadNameHelp') . '</td>';
print '</tr>';

// Résolution
print '<tr class="oddeven">';
print '<td><label for="EBSIMPRINT_RESOLUTION"><strong>' . $langs->trans('EbsResolution') . '</strong></label></td>';
print '<td><input type="number" id="EBSIMPRINT_RESOLUTION" name="EBSIMPRINT_RESOLUTION"'
    . ' value="' . getDolGlobalInt('EBSIMPRINT_RESOLUTION', 550) . '"'
    . ' min="50" max="2000" class="flat" style="width:100px"> dpi</td>';
print '<td class="opacitymedium">' . $langs->trans('EbsResolutionHelp') . '</td>';
print '</tr>';

// Pression
print '<tr class="oddeven">';
print '<td><label for="EBSIMPRINT_PRESSURE"><strong>' . $langs->trans('EbsPressure') . '</strong></label></td>';
print '<td><input type="number" id="EBSIMPRINT_PRESSURE" name="EBSIMPRINT_PRESSURE"'
    . ' value="' . getDolGlobalInt('EBSIMPRINT_PRESSURE', 35) . '"'
    . ' min="1" max="100" class="flat" style="width:100px"></td>';
print '<td class="opacitymedium">' . $langs->trans('EbsPressureHelp') . '</td>';
print '</tr>';

// Taille des points
print '<tr class="oddeven">';
print '<td><label for="EBSIMPRINT_DOT_SIZE"><strong>' . $langs->trans('EbsDotSize') . '</strong></label></td>';
print '<td><input type="number" id="EBSIMPRINT_DOT_SIZE" name="EBSIMPRINT_DOT_SIZE"'
    . ' value="' . getDolGlobalInt('EBSIMPRINT_DOT_SIZE', 3) . '"'
    . ' min="1" max="10" class="flat" style="width:100px"></td>';
print '<td class="opacitymedium">' . $langs->trans('EbsDotSizeHelp') . '</td>';
print '</tr>';

// IDs produits à ignorer
print '<tr class="oddeven">';
print '<td><label for="EBSIMPRINT_SKIP_PRODUCT_ID"><strong>' . $langs->trans('EbsSkipProductId') . '</strong></label></td>';
print '<td><input type="text" id="EBSIMPRINT_SKIP_PRODUCT_ID" name="EBSIMPRINT_SKIP_PRODUCT_ID"'
    . ' value="' . dol_escape_htmltag(getDolGlobalString('EBSIMPRINT_SKIP_PRODUCT_ID', '361')) . '"'
    . ' class="flat" style="width:200px" placeholder="361,362"></td>';
print '<td class="opacitymedium">' . $langs->trans('EbsSkipProductIdHelp') . '</td>';
print '</tr>';

print '</table>';
print '<br>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="' . $langs->trans('Save') . '">';
print '</div>';

print '</form>';

// ── Informations sur le stockage ──────────────────────────────────────────────
print '<br>';
print '<div class="info">';
print '<strong>' . $langs->trans('EbsStorageInfo') . '</strong><br>';
$projectsDir = DOL_DATA_ROOT . '/ebsimprint/projects/';
print $langs->trans('EbsStorageDir') . ' : <code>' . dol_escape_htmltag($projectsDir) . '</code><br>';
print $langs->trans('EbsFolderFormat') . ' : <code>YY_MM_ID</code> '
    . $langs->trans('EbsFolderFormatExample') . ' : <code>25_02_011</code>';
print '</div>';

dol_fiche_end();

llxFooter();
$db->close();
