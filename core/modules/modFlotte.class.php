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
		$this->editor_name = 'company';
		$this->editor_url = '';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@flotte'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0';
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