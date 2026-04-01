<?php
/**
 * Central category type mapping for Flotte module.
 */
class FlotteCategoryType
{
    const PARTS = 15;
    const VEHICLES = 16;

    /**
     * Ensure legacy global constants remain available.
     *
     * @return void
     */
    public static function defineGlobals()
    {
        if (!defined('CATEGORIE_TYPE_FLOTTE_PART')) {
            define('CATEGORIE_TYPE_FLOTTE_PART', self::PARTS);
        }
        if (!defined('CATEGORIE_TYPE_FLOTTE_VEHICLE')) {
            define('CATEGORIE_TYPE_FLOTTE_VEHICLE', self::VEHICLES);
        }
    }
}
