<?php
/* Copyright (C) 2025 Patrice GOURMELEN <pgourmelen@diamant-industrie.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ebsimprint/ebsimprint_tab.php
 * \ingroup    ebsimprint
 * \brief      Onglet "EBS Impression" dans les commandes clients
 */

// ─── Environnement Dolibarr ────────────────────────────────────────────────────
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res && file_exists("../../../../../main.inc.php")) $res = @include "../../../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/lib/commande.lib.php';

// Chargement conditionnel de la classe EbsImprint
$classPath = __DIR__.'/class/ebsimprint.class.php';
if (file_exists($classPath)) {
	require_once $classPath;
} else {
	die('Fichier introuvable : '.$classPath);
}

$langs->loadLangs(array('orders', 'companies', 'ebsimprint@ebsimprint'));

// ─── Paramètres ────────────────────────────────────────────────────────────────
$id     = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (empty($id)) {
	accessforbidden();
}

// ─── Chargement de la commande ─────────────────────────────────────────────────
$object = new Commande($db);
$result = $object->fetch($id);
if ($result <= 0) {
	dol_print_error($db, $object->error);
	exit;
}
$object->fetch_thirdparty();
$object->fetch_lines();

// Sécurité
if (!$user->hasRight('commande', 'read')) {
	accessforbidden();
}

$ebs = new EbsImprint($db);

// ─── En-tête de page ──────────────────────────────────────────────────────────
llxHeader('', 'EBS Impression - '.$object->ref);

// CSS du module
$cssFile = dol_buildpath('/ebsimprint/css/ebsimprint.css', 1);
print '<link rel="stylesheet" type="text/css" href="'.$cssFile.'?v='.time().'">';

// ─── Onglets standard de la commande ──────────────────────────────────────────
$head = commande_prepare_head($object);
print dol_get_fiche_head($head, 'ebsimprint', $langs->trans('CustomerOrder'), -1, 'order');

// ─── Bandeau de la commande ────────────────────────────────────────────────────
$linkback = '<a href="'.DOL_URL_ROOT.'/commande/list.php?restore_lastsearch_values=1">'
	.$langs->trans('BackToList').'</a>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

// ─── Résumé des données utilisées ─────────────────────────────────────────────
print '<table class="border centpercent tableforfield">';

// Text 1 : contact de livraison
$text1 = $ebs->getDeliveryText($object);
print '<tr>';
print '<td class="titlefield">'.$langs->trans('EbsText1Label').'</td>';
print '<td><code>'.dol_escape_htmltag($text1).'</code></td>';
print '</tr>';

// Dossier de sortie
$folderName = $ebs->getProjectFolderName($object);
print '<tr>';
print '<td>'.$langs->trans('EbsProjectFolder').'</td>';
print '<td><code>'.dol_escape_htmltag($folderName).'</code></td>';
print '</tr>';

// Lignes avec ref_chantier
print '<tr>';
print '<td>'.$langs->trans('EbsOrderLinesWithDest').'</td>';
print '<td>';
$linesWithRef = 0;
if (!empty($object->lines)) {
	foreach ($object->lines as $line) {
		if ($ebs->shouldSkipLine($line)) {
			continue;
		}
		$refChantier = $ebs->getRefChantier($line->rowid);
		if (!empty($refChantier)) {
			$productRef = !empty($line->product_ref) ? $line->product_ref : $line->ref;
			print '<span class="ebs-badge">'
				.dol_escape_htmltag((int) $line->qty.'x'.$productRef)
				.' &rarr; '
				.dol_escape_htmltag($refChantier)
				.'</span> ';
			$linesWithRef++;
		}
	}
}
if ($linesWithRef === 0) {
	print '<em class="opacitymedium">'.$langs->trans('EbsNoLinesWithRef').'</em>';
}
print '</td>';
print '</tr>';

print '</table>';
print '</div>'; // fichecenter

// ─── Zone de fichiers générés ─────────────────────────────────────────────────
print '<div class="ebsimprint-section">';

$existingFiles = $ebs->listGeneratedFiles($object);
$hasFiles      = !empty($existingFiles);

print '<div class="ebsimprint-toolbar">';

// Bouton générer / regénérer
$btnLabel = $hasFiles ? $langs->trans('EbsRegenerate') : $langs->trans('EbsGenerate');
$btnClass = $hasFiles ? 'button button-edit' : 'button';
print '<button type="button" class="'.$btnClass.'" id="btn-ebs-generate"'
	.' data-id="'.$id.'" data-action="generate">'
	.'<span class="fa fa-print paddingright"></span>'
	.dol_escape_htmltag($btnLabel)
	.'</button>';

// Bouton supprimer (si fichiers existants)
if ($hasFiles) {
	print ' <button type="button" class="button button-delete" id="btn-ebs-delete"'
		.' data-id="'.$id.'" data-action="delete">'
		.'<span class="fa fa-trash paddingright"></span>'
		.$langs->trans('EbsDeleteFiles')
		.'</button>';
}

print '</div>'; // ebsimprint-toolbar

// Zone de statut (messages AJAX)
print '<div id="ebs-status" class="ebsimprint-status" style="display:none;"></div>';

// ─── Tableau des fichiers générés ──────────────────────────────────────────────
if ($hasFiles) {
	$outputDir = $ebs->getOutputDir($object);

	print '<h3 class="ebs-section-title">';
	print $langs->trans('EbsGeneratedFiles').' - <code>'.dol_escape_htmltag($folderName).'</code>';
	print ' <span class="badge badge-status4 badge-status">'.count($existingFiles).'</span>';
	print '</h3>';

	print '<div id="ebs-file-list">';
	print '<table class="noborder centpercent" id="ebs-files-table">';
	print '<tr class="liste_titre">';
	print '<td style="width:40px"></td>';
	print '<td>'.$langs->trans('FileName').'</td>';
	print '<td style="width:100px">'.$langs->trans('Type').'</td>';
	print '<td style="width:120px">'.$langs->trans('Size').'</td>';
	print '<td style="width:120px" class="center">'.$langs->trans('Action').'</td>';
	print '</tr>';

	foreach ($existingFiles as $filename) {
		$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$filePath = $outputDir.'/'.$filename;
		$fileSize = '-';
		if (file_exists($filePath)) {
			$size = filesize($filePath);
			$fileSize = ($size > 1024) ? round($size / 1024, 1).' Ko' : $size.' o';
		}

		// Icône selon le type
		if ($ext === 'prj') {
			$icon = '<span class="fa fa-file-code-o" style="color:#0070bf" title="Projet XML EBS"></span>';
			$type = 'Projet EBS';
		} else {
			$icon = '<span class="fa fa-file-image-o" style="color:#28a745" title="Apercu PNG"></span>';
			$type = 'Apercu PNG';
		}

		// URL de téléchargement via document.php Dolibarr
		$downloadUrl = DOL_URL_ROOT.'/document.php'
			.'?modulepart=ebsimprint'
			.'&file='.urlencode('projects/'.$folderName.'/'.$filename)
			.'&entity='.$conf->entity;

		print '<tr class="oddeven">';
		print '<td class="center">'.$icon.'</td>';
		print '<td>'.dol_escape_htmltag($filename).'</td>';
		print '<td>'.$type.'</td>';
		print '<td>'.$fileSize.'</td>';
		print '<td class="center">';
		print '<a href="'.$downloadUrl.'" target="_blank" class="butActionSmall" title="'.$langs->trans('Download').'">';
		print '<span class="fa fa-download"></span>';
		print '</a>';
		print '</td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>'; // ebs-file-list

	// ── Bouton de téléchargement ZIP ──────────────────────────────────────────
	$zipUrl = dol_buildpath('/ebsimprint/ajax/download_zip.php', 1).'?id='.$id;
	print '<div class="center" style="margin-top:15px;">';
	print '<a href="'.$zipUrl.'" class="button" id="btn-download-zip">';
	print '<span class="fa fa-file-archive-o paddingright"></span>';
	print $langs->trans('EbsDownloadZip');
	print '</a>';
	print '</div>';
} else {
	// Aucun fichier généré
	print '<div class="info" style="margin-top:15px;">';
	print '<span class="fa fa-info-circle paddingright"></span>';
	print $langs->trans('EbsNoFilesYet');
	print '</div>';
}

print '</div>'; // ebsimprint-section

// ─── JavaScript AJAX ──────────────────────────────────────────────────────────
$ajaxUrl = dol_buildpath('/ebsimprint/ajax/generate_projects.php', 1);
$tokenValue = newToken();
?>
<script type="text/javascript">
jQuery(document).ready(function($) {
	var ajaxUrl = <?php echo json_encode($ajaxUrl); ?>;
	var token   = <?php echo json_encode($tokenValue); ?>;

	// Génération
	$('#btn-ebs-generate').on('click', function() {
		var $btn = $(this);
		var commandeId = $btn.data('id');

		$btn.prop('disabled', true).html('<span class="fa fa-spinner fa-spin paddingright"></span><?php echo dol_escape_js($langs->trans("EbsGenerating")); ?>');

		$('#ebs-status')
			.removeClass('error ok')
			.addClass('loading')
			.html('<span class="fa fa-spinner fa-spin paddingright"></span><?php echo dol_escape_js($langs->trans("EbsGenerating")); ?>...')
			.show();

		$.ajax({
			url: ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				token:  token,
				action: 'generate',
				id:     commandeId
			},
			success: function(resp) {
				if (resp.success) {
					$('#ebs-status')
						.removeClass('loading error')
						.addClass('ok')
						.html('<span class="fa fa-check paddingright"></span>' + resp.data.message);
					setTimeout(function() { location.reload(); }, 1200);
				} else {
					$('#ebs-status')
						.removeClass('loading ok')
						.addClass('error')
						.html('<span class="fa fa-times paddingright"></span>' + (resp.error || 'Erreur inconnue'));
					$btn.prop('disabled', false).html('<span class="fa fa-print paddingright"></span><?php echo dol_escape_js($langs->trans("EbsGenerate")); ?>');
				}
			},
			error: function(xhr) {
				$('#ebs-status')
					.removeClass('loading ok')
					.addClass('error')
					.html('<span class="fa fa-times paddingright"></span>Erreur reseau : ' + xhr.statusText)
					.show();
				$btn.prop('disabled', false).html('<span class="fa fa-print paddingright"></span><?php echo dol_escape_js($langs->trans("EbsGenerate")); ?>');
			}
		});
	});

	// Suppression
	$('#btn-ebs-delete').on('click', function() {
		if (!confirm(<?php echo json_encode($langs->trans('EbsConfirmDelete')); ?>)) { return; }

		var $btn = $(this);
		var commandeId = $btn.data('id');
		$btn.prop('disabled', true);

		$.ajax({
			url: ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				token:  token,
				action: 'delete',
				id:     commandeId
			},
			success: function(resp) {
				if (resp.success) {
					$('#ebs-status')
						.removeClass('loading error')
						.addClass('ok')
						.html('<span class="fa fa-check paddingright"></span>' + resp.data.message)
						.show();
					setTimeout(function() { location.reload(); }, 1200);
				} else {
					alert(resp.error || 'Erreur lors de la suppression.');
					$btn.prop('disabled', false);
				}
			},
			error: function() {
				alert('Erreur reseau.');
				$btn.prop('disabled', false);
			}
		});
	});
});
</script>
<?php

// ─── Fin de l'onglet ──────────────────────────────────────────────────────────
print dol_get_fiche_end();

llxFooter();
$db->close();
