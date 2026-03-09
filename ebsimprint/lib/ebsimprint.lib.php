<?php
/* Copyright (C) 2025 Patrice GOURMELEN <pgourmelen@diamant-industrie.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ebsimprint/lib/ebsimprint.lib.php
 * \ingroup    ebsimprint
 * \brief      Fonctions utilitaires du module EBS Impression
 */

/**
 * Prépare les onglets de la page de configuration du module.
 *
 * @return array Tableau des onglets pour dol_fiche_head()
 */
function ebsimprint_admin_prepare_head()
{
    global $langs;
    $langs->load('ebsimprint@ebsimprint');

    $h   = 0;
    $head = array();

    $head[$h][0] = DOL_URL_ROOT . '/custom/ebsimprint/admin/setup.php';
    $head[$h][1] = $langs->trans('Settings');
    $head[$h][2] = 'setup';
    $h++;

    return $head;
}
