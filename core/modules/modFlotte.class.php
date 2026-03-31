<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	FrÃ©dÃ©ric France				<frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin
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
 * 	\defgroup   flotte     Module Flotte
 *  \brief      Flotte module descriptor.
 *
 *  \file       htdocs/flotte/core/modules/modFlotte.class.php
 *  \ingroup    flotte
 *  \brief      Description and activation file for module Flotte
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module Flotte
 */
class modFlotte extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 500000; // TODO Go on page https://wiki.dolibarr.org/index.php/List_of_modules_id to reserve an id number for your module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'flotte';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleFlotteName' not found (Flotte is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// DESCRIPTION_FLAG
		// Module description, used if translation string 'ModuleFlotteDesc' not found (Flotte is name of module).
		$this->description = "FlotteDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "FlotteDescription";

		// Author
		$this->editor_name = 'Optimalogistic';
		$this->editor_url = 'https://www.optimalogistic.com';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@flotte'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0.9';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where FLOTTE is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'fa-car'; // Changed from fa-file to fa-car which is more appropriate for a fleet module

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 0,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/flotte/css/flotte.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/flotte/js/flotte.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			'hooks' => array(
				//   'data' => array(
				//       'hookcontext1',
				//       'hookcontext2',
				//   ),
				//   'entity' => '0',
			),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/flotte/temp","/flotte/subdir");
		$this->dirs = array("/flotte/temp");

		// Config pages. Put here list of php page, stored into flotte/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@flotte");

		// Dependencies
		// A condition to hide module
		$this->hidden = false; // Changed from getDolGlobalInt('MODULE_FLOTTE_DISABLED') to false
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("flotte@flotte");

		// Prerequisites
		$this->phpmin = array(7, 1); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(19, -3); // Minimum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array();

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mÃ¨re ou revendeur'
		)*/

		if (!isModEnabled("flotte")) {
			$conf->flotte = new stdClass();
			$conf->flotte->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		
		// Add permissions for flotte module
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Read flotte objects';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';
		$r++;
		
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Create/Update flotte objects';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'write';
		$r++;
		
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Delete flotte objects';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'delete';
		$r++;

		// Main menu entries to add
		$this->menu = array();
		$r = 0;

		// Top-level menu bar entry
		$this->menu[$r] = array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'flotte',
			'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => '',
			'url'      => '/flotte/flotteindex.php',
			'langs'    => 'flotte@flotte',
			'position' => 1000,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// Dashboard
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Dashboard',
			'prefix'   => img_picto('', 'fa-tachometer-alt', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_dashboard',
			'url'      => '/flotte/flotteindex.php',
			'langs'    => 'flotte@flotte',
			'position' => 100,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// ── CATEGORY: Fleet ─────────────────────────────────────────────────────
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Fleet',
			'prefix'   => img_picto('', 'fa-car', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_cat_fleet',
			'url'      => '',
			'langs'    => 'flotte@flotte',
			'position' => 200,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_fleet',
			'type'     => 'left',
			'titre'    => 'Vehicles',
			'prefix'   => img_picto('', 'fa-truck', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_vehicles',
			'url'      => '/flotte/vehicle_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 210,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_fleet',
			'type'     => 'left',
			'titre'    => 'Drivers',
			'prefix'   => img_picto('', 'fa-id-card', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_drivers',
			'url'      => '/flotte/driver_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 220,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// ── CATEGORY: Operations ────────────────────────────────────────────────
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Operations',
			'prefix'   => img_picto('', 'fa-briefcase', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_cat_operations',
			'url'      => '',
			'langs'    => 'flotte@flotte',
			'position' => 300,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_operations',
			'type'     => 'left',
			'titre'    => 'Customers',
			'prefix'   => img_picto('', 'fa-users', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_customers',
			'url'      => '/flotte/customer_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 310,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_operations',
			'type'     => 'left',
			'titre'    => 'Bookings',
			'prefix'   => img_picto('', 'fa-calendar-check', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_bookings',
			'url'      => '/flotte/booking_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 320,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// ── CATEGORY: Expenses ──────────────────────────────────────────────────
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Expenses',
			'prefix'   => img_picto('', 'fa-wallet', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_cat_expenses',
			'url'      => '',
			'langs'    => 'flotte@flotte',
			'position' => 400,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_expenses',
			'type'     => 'left',
			'titre'    => 'Expenses',
			'prefix'   => img_picto('', 'fa-receipt', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_expenses',
			'url'      => '/flotte/expenses_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 410,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_expenses',
			'type'     => 'left',
			'titre'    => 'Fuel',
			'prefix'   => img_picto('', 'fa-gas-pump', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_fuel',
			'url'      => '/flotte/fuel_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 420,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_expenses',
			'type'     => 'left',
			'titre'    => 'Parts',
			'prefix'   => img_picto('', 'fa-cogs', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_parts',
			'url'      => '/flotte/part_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 430,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_expenses',
			'type'     => 'left',
			'titre'    => 'Vendors',
			'prefix'   => img_picto('', 'fa-store', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_vendors',
			'url'      => '/flotte/vendor_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 440,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// ── CATEGORY: Maintenance ───────────────────────────────────────────────
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Maintenance',
			'prefix'   => img_picto('', 'fa-wrench', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_cat_maintenance',
			'url'      => '',
			'langs'    => 'flotte@flotte',
			'position' => 500,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_maintenance',
			'type'     => 'left',
			'titre'    => 'WorkOrders',
			'prefix'   => img_picto('', 'fa-clipboard-list', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_workorders',
			'url'      => '/flotte/workorder_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 510,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_maintenance',
			'type'     => 'left',
			'titre'    => 'Inspections',
			'prefix'   => img_picto('', 'fa-search', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_inspections',
			'url'      => '/flotte/inspection_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 520,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// ── CATEGORY: Monitoring ────────────────────────────────────────────────
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Monitoring',
			'prefix'   => img_picto('', 'fa-chart-line', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_cat_monitoring',
			'url'      => '',
			'langs'    => 'flotte@flotte',
			'position' => 600,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_monitoring',
			'type'     => 'left',
			'titre'    => 'Tracking',
			'prefix'   => img_picto('', 'fa-map-marker-alt', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_tracking',
			'url'      => '/flotte/tracking_list.php',
			'langs'    => 'flotte@flotte',
			'position' => 610,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_monitoring',
			'type'     => 'left',
			'titre'    => 'Reports',
			'prefix'   => img_picto('', 'fa-file-alt', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_reports',
			'url'      => '/flotte/reports.php',
			'langs'    => 'flotte@flotte',
			'position' => 620,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_monitoring',
			'type'     => 'left',
			'titre'    => 'Notifications',
			'prefix'   => img_picto('', 'fa-bell', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_notifications',
			'url'      => '/flotte/notification_center.php',
			'langs'    => 'flotte@flotte',
			'position' => 630,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "read")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		// ── CATEGORY: Admin ─────────────────────────────────────────────────────
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte',
			'type'     => 'left',
			'titre'    => 'Admin',
			'prefix'   => img_picto('', 'fa-shield-alt', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_cat_admin',
			'url'      => '',
			'langs'    => 'flotte@flotte',
			'position' => 700,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "write")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_admin',
			'type'     => 'left',
			'titre'    => 'Notification Settings',
			'prefix'   => img_picto('', 'fa-sliders-h', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_notification_settings',
			'url'      => '/flotte/notification_settings.php',
			'langs'    => 'flotte@flotte',
			'position' => 710,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "write")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=flotte,fk_leftmenu=flotte_cat_admin',
			'type'     => 'left',
			'titre'    => 'Setup',
			'prefix'   => img_picto('', 'fa-cog', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'flotte',
			'leftmenu' => 'flotte_setup',
			'url'      => '/flotte/admin/setup.php',
			'langs'    => 'flotte@flotte',
			'position' => 720,
			'enabled'  => 'isModEnabled("flotte")',
			'perms'    => '$user->hasRight("flotte", "write")',
			'target'   => '',
			'user'     => 2,
		);
		$r++;
	}



	/**
	 * Return long description of module (HTML)
	 *
	 * @return string HTML description
	 */
	public function getDescLong()
	{
		$logo = 'data:image/jpeg;base64,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';

		$html = '<div style="font-family:Arial,sans-serif;max-width:700px;padding:10px;">';

		// Logo
		$html .= '<div style="text-align:center;margin-bottom:20px;">';
		$html .= '<img src="'.$logo.'" alt="Optimalogistic" style="max-width:200px;" />';
		$html .= '</div>';

		// Title
		$html .= '<h2 style="color:#6B2FA0;">Flotte — Fleet Management Module</h2>';

		// About
		$html .= '<h3 style="color:#E05C00;">About</h3>';
		$html .= '<p>The <strong>Flotte</strong> module is a comprehensive <strong>fleet management system</strong> developed by <strong>Optimalogistic</strong> for Dolibarr ERP. ';
		$html .= 'It is designed to help businesses efficiently manage their vehicle fleets, from day-to-day operations to long-term maintenance planning.</p>';

		// Purpose
		$html .= '<h3 style="color:#E05C00;">Purpose</h3>';
		$html .= '<p>Flotte centralises all fleet-related data in one place, giving your team full visibility and control over:</p>';
		$html .= '<ul>';
		$html .= '<li><strong>Vehicle management</strong> — track all vehicles, their status, mileage, and ownership details.</li>';
		$html .= '<li><strong>Driver management</strong> — assign drivers to vehicles and monitor their records.</li>';
		$html .= '<li><strong>Fuel tracking</strong> — log fuel consumption and costs per vehicle.</li>';
		$html .= '<li><strong>Maintenance &amp; work orders</strong> — schedule and track repair and maintenance tasks.</li>';
		$html .= '<li><strong>Inspections</strong> — perform and record periodic vehicle inspections.</li>';
		$html .= '<li><strong>Bookings</strong> — manage vehicle reservations and assignments.</li>';
		$html .= '<li><strong>Monitoring &amp; reporting</strong> — real-time tracking, performance reports, and automated notifications.</li>';
		$html .= '</ul>';

		// Installation
		$html .= '<h3 style="color:#E05C00;">Installation</h3>';
		$html .= '<ol>';
		$html .= '<li>Download the module ZIP file <strong>module_flotte-x.x.x.zip</strong>.</li>';
		$html .= '<li>Log in to your Dolibarr administration panel.</li>';
		$html .= '<li>Go to <strong>Setup → Modules/Applications → Deploy/install external app or module</strong>.</li>';
		$html .= '<li>Upload the ZIP file and click <strong>Install</strong>.</li>';
		$html .= '<li>Once installed, go to <strong>Setup → Modules/Applications</strong>, find <em>Flotte</em> and enable it.</li>';
		$html .= '<li>Configure the module under <strong>Flotte → Admin → Setup</strong>.</li>';
		$html .= '</ol>';

		// Contact
		$html .= '<h3 style="color:#E05C00;">Contact &amp; Support</h3>';
		$html .= '<table style="border-collapse:collapse;">';
		$html .= '<tr><td style="padding:4px 10px 4px 0;"><strong>Developer</strong></td><td>Optimalogistic</td></tr>';
		$html .= '<tr><td style="padding:4px 10px 4px 0;"><strong>Tel / WhatsApp</strong></td><td><a href="tel:+21628716111">+216 28 716 111</a></td></tr>';
		$html .= '<tr><td style="padding:4px 10px 4px 0;"><strong>Tel / WhatsApp</strong></td><td><a href="tel:+21628716111">+216 28 812 236</a></td></tr>';
		$html .= '<tr><td style="padding:4px 10px 4px 0;"><strong>Email</strong></td><td><a href="mailto:info@optimalogistic.com">info@optimalogistic.com</a></td></tr>';
		$html .= '<tr><td style="padding:4px 10px 4px 0;"><strong>Website</strong></td><td><a href="https://www.optimalogistic.com" target="_blank">www.optimalogistic.com</a></td></tr>';
		$html .= '</table>';

		$html .= '</div>';

		return $html;
	}


	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Automatically create all module tables from sql/ directory.
		// Dolibarr reads every *.sql file in alphabetical order:
		//   1. llx_flotte.sql      → CREATE TABLE IF NOT EXISTS (all tables)
		//   2. llx_flotte.key.sql  → ALTER TABLE (indexes & foreign keys)
		$result = $this->_load_tables('/flotte/sql/');
		if ($result < 0) {
			return -1; // Table creation failed — check PHP/SQL error logs
		}

		// Clean up old permissions/menus before re-registering
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted. Tables are intentionally kept to preserve data.
	 *	If you want to DROP tables on deactivation, add DROP TABLE statements to $sql below.
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		// Uncomment the lines below ONLY if you want tables dropped when the module is disabled.
		// WARNING: this will permanently delete all fleet data.
		/*
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_workorder";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_inspection";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_part";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_fuel";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_booking";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_driver";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_vendor";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_customer";
		$sql[] = "DROP TABLE IF EXISTS llx_flotte_vehicle";
		*/

		return $this->_remove($sql, $options);
	}
}