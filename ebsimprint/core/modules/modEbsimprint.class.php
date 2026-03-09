<?php
/* Copyright (C) 2025 Patrice GOURMELEN <pgourmelen@diamant-industrie.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \defgroup   ebsimprint   Module EBS Impression
 * \brief      Génère les fichiers de projets d'impression EBS (.prj/.prv) depuis les commandes Dolibarr.
 *
 * \file       htdocs/ebsimprint/core/modules/modEbsimprint.class.php
 * \ingroup    ebsimprint
 * \brief      Descripteur et activation du module EBS Impression
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Classe de description et d'activation du module EBS Impression
 */
class modEbsimprint extends DolibarrModules
{
	/**
	 * Constructeur - définit le module
	 *
	 * @param DoliDB $db Gestionnaire de base de données
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Identifiant unique du module
		$this->numero = 500401;

		// Clé texte pour identifier le module (permissions, menus…)
		$this->rights_class = 'ebsimprint';

		// Famille du module
		$this->family = "other";
		$this->module_position = '91';

		// Nom du module (déduit du nom de la classe)
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Description
		$this->description = "EbsImprintDescription";
		$this->descriptionlong = "EbsImprintDescriptionLong";

		// Auteur / éditeur
		$this->editor_name = 'DIAMANT INDUSTRIE';
		$this->editor_url = 'www.diamant-industrie.com';

		// Version
		$this->version = '1.0';

		// Clé en base pour l'état activé/désactivé
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Icône
		$this->picto = 'fa-print';

		// Fonctionnalités du module
		$this->module_parts = array(
			'triggers'       => 0,
			'login'          => 0,
			'substitutions'  => 0,
			'menus'          => 0,
			'tpl'            => 0,
			'barcode'        => 0,
			'models'         => 0,
			'printing'       => 0,
			'theme'          => 0,
			'css'            => array('/ebsimprint/css/ebsimprint.css'),
			'js'             => array(),
			'hooks'          => array(),
			'moduleforexternal' => 0,
		);

		// Répertoires de données créés lors de l'activation
		$this->dirs = array("/ebsimprint/temp", "/ebsimprint/projects");

		// Page de configuration
		$this->config_page_url = array("setup.php@ebsimprint");

		// Dépendances
		$this->hidden = getDolGlobalInt('MODULE_EBSIMPRINT_DISABLED');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// Fichier de langue
		$this->langfiles = array("ebsimprint@ebsimprint");

		// Prérequis
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(20, 0);
		$this->need_javascript_ajax = 0;

		// Constantes de configuration
		$this->const = array(
			1 => array(
				'EBSIMPRINT_PRINT_HEAD_NAME', 'chaine', 'EBS-Electromagnetic-32',
				'Nom de la tête d\'impression EBS (voir PrintHeads.xml)', 1
			),
			2 => array(
				'EBSIMPRINT_RESOLUTION', 'chaine', '550',
				'Résolution d\'impression (dpi)', 1
			),
			3 => array(
				'EBSIMPRINT_PRESSURE', 'chaine', '35',
				'Pression d\'impression', 1
			),
			4 => array(
				'EBSIMPRINT_DOT_SIZE', 'chaine', '3',
				'Taille des points d\'impression', 1
			),
			5 => array(
				'EBSIMPRINT_SKIP_PRODUCT_ID', 'chaine', '361',
				'ID produit des lignes de titre à ignorer (séparés par virgule)', 1
			),
		);

		if (!isModEnabled("ebsimprint")) {
			$conf->ebsimprint = new stdClass();
			$conf->ebsimprint->enabled = 0;
		}

		// Onglet dans les commandes clients
		$this->tabs = array();
		$this->tabs[] = array(
			'data' => 'order:+ebsimprint:EBS Impression:ebsimprint@ebsimprint:$user->hasRight("commande", "read"):/custom/ebsimprint/ebsimprint_tab.php?id=__ID__'
		);

		// Pas de menus, permissions ou dictionnaires supplémentaires
		$this->menu = array();
		$this->rights = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
	}

	/**
	 * Fonction appelée lors de l'activation du module
	 *
	 * @param string $options Options ('', 'noboxes')
	 * @return int 1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		global $conf;

		// Création des répertoires de données
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$projectsDir = DOL_DATA_ROOT.'/ebsimprint/projects';
		dol_mkdir($projectsDir);

		$this->remove($options);
		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Fonction appelée lors de la désactivation du module
	 *
	 * @param string $options Options ('', 'noboxes')
	 * @return int 1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
