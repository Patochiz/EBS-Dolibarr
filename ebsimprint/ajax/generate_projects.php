<?php
/* Copyright (C) 2025 Patrice GOURMELEN <pgourmelen@diamant-industrie.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ebsimprint/ajax/generate_projects.php
 * \ingroup    ebsimprint
 * \brief      Endpoint AJAX pour la génération des fichiers EBS (.prj/.prv)
 *
 * Actions supportées (paramètre POST 'action') :
 * - generate  : génère les fichiers pour la commande
 * - delete    : supprime les fichiers générés
 * - list      : liste les fichiers existants
 */

// Désactiver le renouvellement de token et le rendu HTML
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

// Chargement de l'environnement Dolibarr
$res = 0;
if (!$res && file_exists('../../main.inc.php'))       $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php'))     $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php'))  $res = @include '../../../../main.inc.php';
if (!$res && file_exists('../../../../../main.inc.php')) $res = @include '../../../../../main.inc.php';
if (!$res) die(json_encode(array('success' => false, 'error' => 'Impossible de charger main.inc.php')));

require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once __DIR__ . '/../class/ebsimprint.class.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Envoi une réponse JSON et termine le script
 *
 * @param bool        $success Succès ou non
 * @param array|null  $data    Données complémentaires
 * @param string|null $error   Message d'erreur
 */
function jsonResponse($success, $data = null, $error = null)
{
    $r = array('success' => $success);
    if ($data  !== null) $r['data']  = $data;
    if ($error !== null) $r['error'] = $error;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Contrôles ────────────────────────────────────────────────────────────────

if (!$user->hasRight('commande', 'read')) {
    jsonResponse(false, null, 'Permission refusée.');
}

if (!isModEnabled('ebsimprint')) {
    jsonResponse(false, null, 'Module EBS Impression non activé.');
}

$action    = GETPOST('action', 'aZ09');
$commandeId = GETPOSTINT('id');

if (empty($commandeId)) {
    jsonResponse(false, null, 'Paramètre id manquant.');
}

// Chargement de la commande
$order = new Commande($db);
if ($order->fetch($commandeId) <= 0) {
    jsonResponse(false, null, 'Commande introuvable (id=' . $commandeId . ').');
}

$ebs = new EbsImprint($db);

// ─── Actions ──────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Génération des fichiers ────────────────────────────────────────────────
    case 'generate':
        if (!$user->hasRight('commande', 'creer')) {
            jsonResponse(false, null, 'Droits insuffisants pour générer les fichiers.');
        }

        $result = $ebs->generateProjectFiles($order);

        if ($result === false) {
            jsonResponse(false, null, $ebs->error ?: 'Erreur lors de la génération.');
        }

        $warnings = !empty($ebs->errors) ? $ebs->errors : array();

        jsonResponse(true, array(
            'folder'   => $result['folder'],
            'dir'      => $result['dir'],
            'files'    => $result['files'],
            'count'    => $result['count'],
            'warnings' => $warnings,
            'message'  => $result['count'] . ' fichier(s) générés dans ' . $result['folder'],
        ));
        break;

    // ── Suppression des fichiers ───────────────────────────────────────────────
    case 'delete':
        if (!$user->hasRight('commande', 'creer')) {
            jsonResponse(false, null, 'Droits insuffisants pour supprimer les fichiers.');
        }

        $count = $ebs->deleteGeneratedFiles($order);
        jsonResponse(true, array(
            'deleted' => $count,
            'message' => $count . ' fichier(s) supprimé(s).',
        ));
        break;

    // ── Liste des fichiers existants ───────────────────────────────────────────
    case 'list':
        $files  = $ebs->listGeneratedFiles($order);
        $folder = $ebs->getProjectFolderName($order);
        jsonResponse(true, array(
            'folder' => $folder,
            'files'  => $files,
            'count'  => count($files),
        ));
        break;

    default:
        jsonResponse(false, null, 'Action non reconnue : ' . htmlspecialchars($action));
        break;
}
