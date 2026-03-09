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
 */

/**
 * \file       ebsimprint/class/ebsimprint.class.php
 * \ingroup    ebsimprint
 * \brief      Classe principale du module EBS Impression
 *
 * Génère les fichiers .prj (projet XML EBS) et .prv (aperçu PNG)
 * à partir des données d'une commande Dolibarr et du module Colisage.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Classe principale EBS Impression
 *
 * Logique de génération des fichiers d'impression EBS depuis une commande.
 *
 * Correspondance des champs .prj :
 * - Text 1 = Contact de livraison : NOM / VILLE (DEPT)
 * - Text 2 = ref_chantier de la ligne de commande (extrafield Colisage)
 * - Text 3 = {quantité}x{référence_produit}  (fichiers produit uniquement)
 */
class EbsImprint
{
	/** @var DoliDB */
	public $db;

	/** @var string Message d'erreur */
	public $error;

	/** @var array Messages d'erreur multiples */
	public $errors = array();

	// ─── Paramètres EBS ───────────────────────────────────────────────────────

	/** @var string Nom de la tête d'impression (PrintHeadName dans le XML) */
	public $printHeadName;

	/** @var int Résolution (dpi) */
	public $resolution;

	/** @var int Pression */
	public $pressure;

	/** @var int Taille des points */
	public $dotSize;

	/** @var int[] IDs produits à ignorer (lignes titre/header) */
	public $skipProductIds = array();

	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Gestionnaire de base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->loadConfig();
	}

	// ─── Configuration ────────────────────────────────────────────────────────

	/**
	 * Charge la configuration depuis les constantes Dolibarr
	 */
	private function loadConfig()
	{
		$this->printHeadName = getDolGlobalString('EBSIMPRINT_PRINT_HEAD_NAME', 'EBS-Electromagnetic-32');
		$this->resolution    = getDolGlobalInt('EBSIMPRINT_RESOLUTION', 550);
		$this->pressure      = getDolGlobalInt('EBSIMPRINT_PRESSURE', 35);
		$this->dotSize       = getDolGlobalInt('EBSIMPRINT_DOT_SIZE', 3);

		$skipStr = getDolGlobalString('EBSIMPRINT_SKIP_PRODUCT_ID', '');
		if (!empty($skipStr)) {
			foreach (explode(',', $skipStr) as $id) {
				$id = (int) trim($id);
				if ($id > 0) {
					$this->skipProductIds[] = $id;
				}
			}
		}
	}

	// ─── Nommage ──────────────────────────────────────────────────────────────

	/**
	 * Calcule le nom du dossier projet pour une commande.
	 *
	 * Format : YY_MM_ID (ID de la commande sur 3 chiffres minimum)
	 * Exemple : commande ID=11, date=2025-02 → "25_02_011"
	 *
	 * @param Commande $order Objet commande Dolibarr
	 * @return string Nom du dossier
	 */
	public function getProjectFolderName($order)
	{
		$date = !empty($order->date_commande) ? $order->date_commande : dol_now();
		$year  = dol_print_date($date, '%y');
		$month = dol_print_date($date, '%m');
		$id    = sprintf('%03d', (int) $order->id);
		return $year . '_' . $month . '_' . $id;
	}

	/**
	 * Retourne le chemin absolu du dossier de sortie pour une commande.
	 *
	 * @param Commande $order Objet commande
	 * @return string Chemin absolu
	 */
	public function getOutputDir($order)
	{
		global $conf;
		$baseDir = DOL_DATA_ROOT . '/ebsimprint/projects';
		return $baseDir . '/' . $this->getProjectFolderName($order);
	}

	// ─── Extraction des données Dolibarr ──────────────────────────────────────

	/**
	 * Construit le texte "NOM / VILLE (DEPT)" pour Text 1 des fichiers .prj.
	 *
	 * La valeur est issue du tiers (client) lié à la commande.
	 * Format français : DUPONT / NIORT (79)
	 *
	 * @param Commande $order Commande avec thirdparty chargé
	 * @return string Texte formaté
	 */
	public function getDeliveryText($order)
	{
		$tp = $order->thirdparty;

		$name = !empty($tp->name) ? strtoupper(trim($tp->name)) : '';
		$town = !empty($tp->town) ? strtoupper(trim($tp->town)) : '';
		$zip  = !empty($tp->zip)  ? trim($tp->zip)  : '';

		// Code département : 2 premiers chiffres du code postal
		// Cas Corse : 2A/2B restent tels quels
		if (preg_match('/^(\d{2}|\d{3}|2[AB])/i', $zip, $m)) {
			$dept = strtoupper($m[1]);
			// Départements métropolitains sur 2 chiffres
			if (is_numeric($dept) && strlen($dept) > 2) {
				$dept = substr($dept, 0, 2);
			}
		} else {
			$dept = $zip;
		}

		$result = $name;
		if (!empty($town)) {
			$result .= ' / ' . $town;
		}
		if (!empty($dept)) {
			$result .= ' (' . $dept . ')';
		}
		return $result;
	}

	/**
	 * Récupère la valeur de l'extrafield ref_chantier pour une ligne de commande.
	 *
	 * ref_chantier est stocké dans llx_commandedet_extrafields par le module Colisage.
	 * Il contient le nom du magasin/chantier de destination (ex : "Hyper U ST AVÉ (56)").
	 *
	 * @param int $lineRowid rowid de la ligne de commande (commandedet)
	 * @return string Valeur du ref_chantier, chaîne vide si absent
	 */
	public function getRefChantier($lineRowid)
	{
		$sql  = "SELECT ref_chantier FROM " . MAIN_DB_PREFIX . "commandedet_extrafields";
		$sql .= " WHERE fk_object = " . ((int) $lineRowid);

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			return (string) $obj->ref_chantier;
		}
		if ($resql) {
			$this->db->free($resql);
		}
		return '';
	}

	/**
	 * Indique si une ligne de commande doit être ignorée.
	 *
	 * Sont ignorées :
	 * - Les lignes sans produit (fk_product vide)
	 * - Les lignes dont le produit est dans $this->skipProductIds
	 * - Les lignes sans ref_chantier dans Colisage
	 *
	 * @param object $line Ligne de commande Dolibarr
	 * @return bool true si la ligne doit être ignorée
	 */
	public function shouldSkipLine($line)
	{
		if (empty($line->fk_product)) {
			return true;
		}
		if (!empty($this->skipProductIds) && in_array((int) $line->fk_product, $this->skipProductIds)) {
			return true;
		}
		return false;
	}

	// ─── Génération XML (.prj) ────────────────────────────────────────────────

	/**
	 * Génère le contenu XML d'un fichier .prj EBS.
	 *
	 * Deux types de fichiers :
	 * - ADRESSE ($text3 === null) : 2 objets texte (client + destinations)
	 * - Produit  ($text3 défini)  : 5 objets (client, destination, qtyxref, vide, séparateur)
	 *
	 * @param string      $text1 Text 1 : "NOM / VILLE (DEPT)"
	 * @param string      $text2 Text 2 : ref_chantier de la ligne
	 * @param string|null $text3 Text 3 : "{qty}x{ref}" ou null pour ADRESSE
	 * @return string Contenu XML UTF-8
	 */
	public function generatePrjXml($text1, $text2, $text3 = null)
	{
		$isAddress    = ($text3 === null);
		$objectsCount = $isAddress ? 2 : 5;
		$minW         = $isAddress ? 300 : 660;

		$t1 = htmlspecialchars($text1, ENT_XML1, 'UTF-8');
		$t2 = htmlspecialchars($text2, ENT_XML1, 'UTF-8');
		$ph = htmlspecialchars($this->printHeadName, ENT_XML1, 'UTF-8');

		$editorData = 'Field0="" Field1="" Field2="" Field3="" Field4="" Field5=""'
			. ' Field6="" Field7="" Field8="" Field9="" Field10="" Field11="" Field12=""'
			. ' Field13=""';

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<EBS_PrinterProject>' . "\n";

		// ProjectSettings
		$xml .= '  <ProjectSettings DataFormat="10" PrintHeadName="' . $ph . '"'
			. ' FriendlyName="Undefined" w="1000" h="32" ObjectsCount="' . $objectsCount . '"'
			. ' ManagerInfo="1" min_w="' . $minW . '"/>' . "\n";

		// PrintingParams
		$xml .= '  <PrintingParams ExternParamsFile="" UseExternParamsFile="0"'
			. ' ImpulseGeneratorSource="1" TriggerType="0" TriggerSignalMode="0"'
			. ' PhotocellSource="0" Resolution="' . $this->resolution . '" PrintDistance="0"'
			. ' TxtRepetitions="1" RepetitionDistance="0" RowMultiply="0" UpsideDownPrint="0"'
			. ' ReversePrint="0" ShaftDirection="0" TextHeight="0" CleaningRows="0"'
			. ' Pressure="' . $this->pressure . '" DotSize="' . $this->dotSize . '"/>' . "\n";

		if ($isAddress) {
			// ── Fichier ADRESSE : 2 textes ──────────────────────────────────
			$xml .= $this->xmlTextObject(
				'Text 1', 0, 1, 169, 16,
				$t1,
				'fonts/Default/Font_16x10.xml', 20, 20,
				$editorData . ' Field14="1"'
			);
			$xml .= $this->xmlTextObject(
				'Text 2', 0, 19, 300, 12,
				$t2,
				'fonts/Default/Font_12x7.xml', 10, 10,
				$editorData . ' Field14="1"'
			);
		} else {
			// ── Fichier produit : 5 objets ───────────────────────────────────
			$t3 = htmlspecialchars((string) $text3, ENT_XML1, 'UTF-8');

			// Text 1 – client
			$xml .= $this->xmlTextObject(
				'Text 1', 0, 0, 100, 16,
				$t1,
				'fonts/Default/Font_16x10.xml', 25, 25,
				$editorData . ' Field14="1"'
			);
			// Text 2 – destination (ref_chantier)
			$xml .= $this->xmlTextObject(
				'Text 2', 0, 17, 40, 12,
				$t2,
				'fonts/Default/Font_12x7.xml', 7, 7,
				$editorData . ' Field14="1"'
			);
			// Text 3 – qty × ref
			$xml .= $this->xmlTextObject(
				'Text 3', 200, 0, 110, 16,
				$t3,
				'fonts/Default/Font_16x10.xml', 25, 25,
				$editorData . ' Field14="1"'
			);
			// Text 4 – vide (réservé)
			$xml .= $this->xmlTextObject(
				'Text 4', 200, 17, 60, 7,
				'',
				'fonts/Default/Font_16x10.xml', 25, 25,
				$editorData . ' Field14="1"'
			);
			// LineDivider
			$xml .= '  <Object ObjectType="SpecialObject" Type="0" ObjectName="LineDivider 1"'
				. ' x="190" y="0" w="5" h="32">' . "\n";
			$xml .= '    <EditorData ' . $editorData . ' Field14=""/>' . "\n";
			$xml .= '  </Object>' . "\n";
		}

		$xml .= '</EBS_PrinterProject>' . "\n";
		return $xml;
	}

	/**
	 * Génère le fragment XML pour un objet TextObject EBS.
	 *
	 * @param string $name       Nom de l'objet (ex: "Text 1")
	 * @param int    $x          Position X
	 * @param int    $y          Position Y
	 * @param int    $w          Largeur
	 * @param int    $h          Hauteur
	 * @param string $text       Contenu (déjà échappé XML)
	 * @param string $fontName   Chemin de la police
	 * @param int    $fontSize   Taille police X
	 * @param int    $fontSizeY  Taille police Y
	 * @param string $editorData Attributs EditorData
	 * @return string Fragment XML
	 */
	private function xmlTextObject($name, $x, $y, $w, $h, $text, $fontName, $fontSize, $fontSizeY, $editorData)
	{
		$xml  = '  <Object ObjectType="TextObject" ObjectName="' . htmlspecialchars($name, ENT_XML1) . '"'
			. ' x="' . $x . '" y="' . $y . '" w="' . $w . '" h="' . $h . '"'
			. ' AutoSize="1" Transparent="1"'
			. ' Text="' . $text . '"'
			. ' FontName="' . htmlspecialchars($fontName, ENT_XML1) . '"'
			. ' FontSize="' . $fontSize . '" FontSizeY="' . $fontSizeY . '"'
			. ' FontBold="0" FontItalic="0" FontRotate="0"'
			. ' LineSpacing="1" LetterSpacing="1" RowMultiply="1"'
			. ' ObjectRotate="0" IsLinked="0" LinkedToObject="" ExternalScript=""'
			. ' MustEdit="0" Printable="1">' . "\n";
		$xml .= '    <EditorData ' . $editorData . '/>' . "\n";
		$xml .= '  </Object>' . "\n";
		return $xml;
	}

	// ─── Génération PNG (.prv) ────────────────────────────────────────────────

	/**
	 * Génère le contenu binaire PNG d'un fichier .prv (aperçu d'impression).
	 *
	 * Dimensions : 1000 × 32 px (largeur projet × hauteur tête 32 buses).
	 * Rendu : fond blanc, texte noir avec les polices GD intégrées.
	 * Remarque : EBS régénère l'aperçu lors de l'ouverture du projet ;
	 *            ce fichier est un placeholder fonctionnel.
	 *
	 * @param string      $text1 Ligne haute (NOM / VILLE)
	 * @param string      $text2 Ligne basse (ref_chantier ou destinations)
	 * @param string|null $text3 Texte droit (qty×ref) ou null pour ADRESSE
	 * @return string Données binaires PNG
	 */
	public function generatePrvPng($text1, $text2, $text3 = null)
	{
		if (!function_exists('imagecreate')) {
			// GD non disponible : retourner un PNG minimal 1×1 pixel transparent
			return base64_decode(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
			);
		}

		$width  = 1000;
		$height = 32;
		$isAddress = ($text3 === null);

		// Palette 8 bits (format natif des fichiers .prv EBS)
		$im = imagecreate($width, $height);

		// Couleur 0 = fond (blanc)
		$white = imagecolorallocate($im, 255, 255, 255);
		// Couleur 1 = texte (noir)
		$black = imagecolorallocate($im, 0, 0, 0);

		// Nettoyer les chaînes pour les polices GD (ASCII uniquement)
		$t1 = $this->toAsciiForGd($text1);
		$t2 = $this->toAsciiForGd($text2);

		if ($isAddress) {
			// ── ADRESSE ──────────────────────────────────────────────────────
			// Text 1 : police 4 (8px large, 16px haut) en y=0
			imagestring($im, 4, 2, 0, $t1, $black);
			// Text 2 : police 2 (6px large, 11px haut) en y=20
			imagestring($im, 2, 2, 20, $t2, $black);
		} else {
			// ── Produit ──────────────────────────────────────────────────────
			$t3 = $this->toAsciiForGd((string) $text3);

			// Côté gauche (0–189 px) : client + destination
			imagestring($im, 4, 2, 0, $t1, $black);
			imagestring($im, 2, 2, 20, $t2, $black);

			// Séparateur vertical à x=192
			imageline($im, 192, 0, 192, $height - 1, $black);

			// Côté droit (202+ px) : qty×ref
			imagestring($im, 4, 202, 0, $t3, $black);
		}

		// Capture en buffer
		ob_start();
		imagepng($im, null, 6); // compression 6 : bon compromis taille/vitesse
		$data = ob_get_clean();
		imagedestroy($im);

		return $data;
	}

	/**
	 * Convertit une chaîne UTF-8 vers ASCII pour les polices GD intégrées.
	 *
	 * Les caractères accentués sont translittérés (é→e, è→e, etc.).
	 * Les caractères non-ASCII restants sont supprimés.
	 *
	 * @param string $str Chaîne UTF-8
	 * @return string Chaîne ASCII
	 */
	private function toAsciiForGd($str)
	{
		if (function_exists('transliterator_transliterate')) {
			$str = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
		} else {
			// Translittération manuelle des caractères courants
			$map = array(
				'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
				'Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
				'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ð'=>'D',
				'Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
				'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Þ'=>'TH','ß'=>'ss',
				'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
				'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
				'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'d',
				'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
				'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y',
				'Œ'=>'OE','œ'=>'oe','Š'=>'S','š'=>'s','Ž'=>'Z','ž'=>'z',
			);
			$str = strtr($str, $map);
		}
		// Supprimer les caractères non imprimables et non-ASCII
		$str = preg_replace('/[^\x20-\x7E]/', '', $str);
		return $str;
	}

	// ─── Génération complète ──────────────────────────────────────────────────

	/**
	 * Génère l'ensemble des fichiers .prj et .prv pour une commande.
	 *
	 * Fichiers produits :
	 * - ADRESSE.prj / ADRESSE.prv (toujours généré)
	 * - {rowid}_{qty}x{ref}.prj / .prv pour chaque ligne avec ref_chantier
	 *
	 * @param Commande $order Commande Dolibarr avec thirdparty et lines chargés
	 * @return array|false Tableau 'folder','files','dir' ou false en cas d'erreur
	 */
	public function generateProjectFiles($order)
	{
		global $conf;

		// Chargement du tiers si nécessaire
		if (empty($order->thirdparty) || empty($order->thirdparty->id)) {
			$order->fetch_thirdparty();
		}

		// Chargement des lignes si nécessaire
		if (empty($order->lines)) {
			$order->fetch_lines();
		}

		// Dossier de sortie
		$outputDir = $this->getOutputDir($order);
		if (!dol_mkdir($outputDir)) {
			$this->error = 'Impossible de créer le dossier : ' . $outputDir;
			return false;
		}

		// Text 1 commun à tous les fichiers
		$text1 = $this->getDeliveryText($order);

		$generatedFiles  = array();
		$allRefChantiers = array();

		// ── Parcours des lignes de commande ───────────────────────────────────
		foreach ($order->lines as $line) {
			if ($this->shouldSkipLine($line)) {
				continue;
			}

			$refChantier = $this->getRefChantier($line->rowid);
			if (empty($refChantier)) {
				continue; // Pas de destination définie → on ignore
			}

			if (!in_array($refChantier, $allRefChantiers)) {
				$allRefChantiers[] = $refChantier;
			}

			// Référence produit (préfère product_ref, sinon ref de ligne)
			$productRef = !empty($line->product_ref) ? $line->product_ref : (string) $line->ref;
			$productRef = $this->sanitizeFilename($productRef);

			$qty   = (int) $line->qty;
			$text3 = $qty . 'x' . $productRef;

			// Nom de base du fichier : {rowid}_{qty}x{ref}
			$baseName = $line->rowid . '_' . $text3;

			// .prj
			$prjPath = $outputDir . '/' . $baseName . '.prj';
			$prjOk   = file_put_contents($prjPath, $this->generatePrjXml($text1, $refChantier, $text3));

			// .prv
			$prvPath = $outputDir . '/' . $baseName . '.prv';
			$prvOk   = file_put_contents($prvPath, $this->generatePrvPng($text1, $refChantier, $text3));

			if ($prjOk !== false && $prvOk !== false) {
				$generatedFiles[] = $baseName . '.prj';
				$generatedFiles[] = $baseName . '.prv';
			} else {
				$this->errors[] = 'Erreur écriture fichier pour ligne ' . $line->rowid;
			}
		}

		// ── Fichier ADRESSE ───────────────────────────────────────────────────
		$text2Address = implode(' + ', $allRefChantiers);

		$adressePrjPath = $outputDir . '/ADRESSE.prj';
		$adressePrvPath = $outputDir . '/ADRESSE.prv';

		file_put_contents($adressePrjPath, $this->generatePrjXml($text1, $text2Address));
		file_put_contents($adressePrvPath, $this->generatePrvPng($text1, $text2Address));

		$generatedFiles[] = 'ADRESSE.prj';
		$generatedFiles[] = 'ADRESSE.prv';

		return array(
			'folder' => $this->getProjectFolderName($order),
			'dir'    => $outputDir,
			'files'  => $generatedFiles,
			'count'  => count($generatedFiles),
		);
	}

	/**
	 * Liste les fichiers déjà générés pour une commande.
	 *
	 * @param Commande $order Commande Dolibarr
	 * @return array Liste des noms de fichiers (vide si aucun)
	 */
	public function listGeneratedFiles($order)
	{
		$dir = $this->getOutputDir($order);
		if (!is_dir($dir)) {
			return array();
		}

		$files = array();
		foreach (scandir($dir) as $f) {
			if ($f === '.' || $f === '..') {
				continue;
			}
			$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
			if (in_array($ext, array('prj', 'prv'))) {
				$files[] = $f;
			}
		}
		sort($files);
		return $files;
	}

	/**
	 * Supprime tous les fichiers générés pour une commande.
	 *
	 * @param Commande $order Commande Dolibarr
	 * @return int Nombre de fichiers supprimés
	 */
	public function deleteGeneratedFiles($order)
	{
		$dir   = $this->getOutputDir($order);
		$count = 0;
		if (!is_dir($dir)) {
			return 0;
		}
		foreach (scandir($dir) as $f) {
			if ($f === '.' || $f === '..') {
				continue;
			}
			$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
			if (in_array($ext, array('prj', 'prv'))) {
				if (unlink($dir . '/' . $f)) {
					$count++;
				}
			}
		}
		// Supprimer le dossier s'il est vide
		@rmdir($dir);
		return $count;
	}

	// ─── Utilitaires ──────────────────────────────────────────────────────────

	/**
	 * Nettoie une chaîne pour l'utiliser comme nom de fichier.
	 *
	 * @param string $name Nom brut
	 * @return string Nom sécurisé
	 */
	private function sanitizeFilename($name)
	{
		// Conserver uniquement lettres, chiffres, tirets, underscores, points
		$name = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $name);
		return trim($name, '_.');
	}
}
