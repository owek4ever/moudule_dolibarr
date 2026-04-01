<?php
/**
 * Hook handler for the Flotte module.
 *
 * PLACE THIS FILE AT:
 *   htdocs/custom/flotte/class/actions_flotte.class.php
 *
 * HOW THIS WORKS (source: categorie.class.php lines 326-342):
 *
 *   Dolibarr's Categorie::__construct() calls:
 *     $hookmanager->initHooks(array('category'));
 *     $hookmanager->executeHooks('constructCategory', $parameters, $this);
 *   Then reads $hookmanager->resArray and injects entries into:
 *     MAP_ID, MAP_ID_TO_CODE, MAP_OBJ_CLASS, MAP_OBJ_TABLE
 *
 *   Once injected, our types appear EVERYWHERE natively:
 *     /categories/index.php list, get_full_arbo(), showCategories(), containing()
 *
 * TYPE ID INTEGERS:
 *   Dolibarr core uses 0-14. We use 15 (Parts) and 16 (Vehicles).
 *   UPDATE part_card.php:    define('CATEGORIE_TYPE_FLOTTE_PART',    15);
 *   UPDATE vehicle_card.php: define('CATEGORIE_TYPE_FLOTTE_VEHICLE', 16);
 */

class ActionsFlotte
{
    public $db;
    public $error   = '';
    public $errors  = array();
    public $results = array();

    // Native category type IDs for Flotte objects.
    const CAT_PART    = 15;
    const CAT_VEHICLE = 16;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook: constructCategory  |  Context: category
     *
     * Push our custom types into $hookmanager->resArray.
     * Dolibarr's Categorie class reads this and injects them into its maps.
     */
    public function constructCategory($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        $mapPart = array(
            'id'        => self::CAT_PART,
            'code'      => 'flotte_part',
            'obj_class' => 'FlottePart',
            'obj_table' => 'flotte_part',
        );

        $mapVehicle = array(
            'id'        => self::CAT_VEHICLE,
            'code'      => 'flotte_vehicle',
            'obj_class' => 'FlotteVehicle',
            'obj_table' => 'flotte_vehicle',
        );

        // Dolibarr 22+ collects hook output from $this->results.
        $this->results['flotte_part'] = $mapPart;
        $this->results['flotte_vehicle'] = $mapVehicle;

        // Keep backward compatibility with older behavior.
        $hookmanager->resArray['flotte_part'] = $mapPart;
        $hookmanager->resArray['flotte_vehicle'] = $mapVehicle;

        // Fix category type labels on native Tags/Categories list:
        // - Dolibarr builds the list using Categorie::$MAP_TYPE_TITLE_AREA[code] as translation key.
        // - If missing, Dolibarr calls trans(null) and shows "Err:BadValueForParamNotAString".
        if (!empty($langs)) {
            $langs->loadLangs(array('flotte@flotte'));
        }
        if (class_exists('Categorie')) {
            if (!isset(Categorie::$MAP_TYPE_TITLE_AREA['flotte_part'])) {
                Categorie::$MAP_TYPE_TITLE_AREA['flotte_part'] = 'Parts';
            }
            if (!isset(Categorie::$MAP_TYPE_TITLE_AREA['flotte_vehicle'])) {
                Categorie::$MAP_TYPE_TITLE_AREA['flotte_vehicle'] = 'Vehicles';
            }
        }

        return 0;
    }
}