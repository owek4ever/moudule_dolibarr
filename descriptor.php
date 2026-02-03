<?php
// -- LICENCE -------------------------------------------------------------
// Copyright 2024 - Flotte
// License: GPLv3
// -----------------------------------------------------------------------

// -- CHANGELOG -----------------------------------------------------------
// v1.0 - Initial version
// -----------------------------------------------------------------------

/**
 * Class description of module.
 *
 * @class  ModuleFlotte
 * @extends DoliModules
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && !empty($_SERVER['SCRIPT_FILENAME'])) {
    $tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
    $tmp2 = realpath(__FILE__);
    $i = strlen($tmp) - 1;
    while ($i > 0 && substr($tmp, $i) != '/htdocs/' && substr($tmp2, $i) != '/htdocs/') {
        $i--;
    }
    if ($i > 0) {
        $res = @include substr($tmp, 0, $i) . '/htdocs/main.inc.php';
    }
}

// Load the class of the module
dol_include_once('/modules/flotte/flotteindex.php');

/**
 * Description class of module.
 *
 * @class  ModuleFlotte
 * @extends DoliModules
 */
class ModuleFlotte extends DoliModules
{
    public $name = 'Flotte';
    public $version = '1.0';
    public $shortname = 'flotte';
    public $family = 'other';
    public $description = 'Fleet management module for Dolibarr';
    public $descriptionlong = 'Fleet management module';
    public $visible = 1;
    public $ext_descr = '';
    public $author = 'Flotte';
    public $alwaysvisible = 1;
    public $logtomail = 0;
    public $tags = '';

    // Needs
    public $depends = array();
    public $excludedepends = array();
    public $imply = array();
    public $requiredby = array();

    // Menu
    public $menu = array(
        array(
            'mainmenu' => 'flotte',
            'leftmenu' => '',
            'url' => '/modules/flotte/flotteindex.php',
            'lang' => 'flotte',
            'position' => 1000,
            'enabled' => 1,
            'perms' => 1,
            'target' => '',
        ),
    );

    /**
     * Constructor
     *
     * @param   DoliDB  $db     Database object
     */
    public function __construct(DoliDB $db)
    {
        global $conf;
        $this->db = $db;
    }

    /**
     * Function called when module is enabled.
     *
     * @param   DoliDB      $db     Database object
     * @param   string      $name   Name
     * @param   string      $version    Version
     * @return  int         1 if ok
     */
    public function init($db, $name = '', $version = '')
    {
        // Calls SQL with SQL file
        $sql = array();
        return $this->excutescript($db, $sql);
    }

    /**
     * Function called when module is disabled.
     *
     * @param   DoliDB      $db     Database object
     * @param   string      $name   Name
     * @param   string      $version    Version
     * @return  int         1 if ok
     */
    public function disable($db, $name = '', $version = '')
    {
        return 1;
    }
}