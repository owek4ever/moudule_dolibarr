<?php
/* Copyright (C) 2024 Your Company
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * USAGE: Place this file at htdocs/custom/flotte/flotte_categories.php
 *
 * Central Tags/Categories management page for the Flotte module.
 * Lists all Flotte object types (Parts, Vehicles, ...) with their category
 * count and a pencil that opens Dolibarr's NATIVE /categories/index.php?type=X
 */

// -- Load Dolibarr environment ------------------------------------------------
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php"))           { $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php"))  { $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

// -- Security -----------------------------------------------------------------
restrictedArea($user, 'flotte');

// -- Translations -------------------------------------------------------------
$langs->loadLangs(array("flotte@flotte", "other", "categories"));

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once __DIR__.'/class/flottecategorytype.class.php';

// =============================================================================
//  REGISTRY -- one entry per Flotte object type.
//
//  'type_id'  => integer stored in llx_categorie.type
//                MUST match define() in the matching card file AND the hook.
//                Native: 0=product 1=supplier 2=customer 3=member 4=contact
//                        5=account 6=project 7=user 8=ticket
//                Flotte:  15=parts  16=vehicles
//  'label'    => translation key (add to your flotte.lang file)
//  'icon'     => FontAwesome 5 class
// =============================================================================
$flotte_cat_types = array(
    'parts' => array(
        'type_id' => FlotteCategoryType::PARTS,
        'label'   => 'Parts',
        'icon'    => 'fa fa-cogs',
    ),
    'vehicles' => array(
        'type_id' => FlotteCategoryType::VEHICLES,
        'label'   => 'Vehicles',
        'icon'    => 'fa fa-car',
    ),
    // -- Add more rows as you create new object types -------------------------
    // 'drivers' => array(
    //     'type_id' => 12,
    //     'label'   => 'Drivers',
    //     'icon'    => 'fa fa-id-card',
    // ),
    // 'maintenance' => array(
    //     'type_id' => 13,
    //     'label'   => 'Maintenance',
    //     'icon'    => 'fa fa-wrench',
    // ),
);

// -- Count categories per type from native llx_categorie table ----------------
$counts = array();
foreach ($flotte_cat_types as $key => $type) {
    $res_count = $db->query(
        "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."categorie"
        ." WHERE type = ".(int)$type['type_id']
        ." AND entity = ".$conf->entity
    );
    $counts[$key] = 0;
    if ($res_count) {
        $obj_cnt = $db->fetch_object($res_count);
        if ($obj_cnt) {
            $counts[$key] = (int)$obj_cnt->cnt;
        }
    }
}

$form = new Form($db);

// =============================================================================
//  VIEW
// =============================================================================
llxHeader('', $langs->trans('FlotteTagsCategories'));
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>
.fc-page { max-width: 960px; margin: 0 auto; padding: 0 4px 60px; font-family: inherit; }

.fc-header {
    display: flex; align-items: center; gap: 10px;
    padding: 22px 0 8px;
    color: #00acc1;
    font-size: 18px; font-weight: 700;
}
.fc-header i { font-size: 20px; }

.fc-desc { color: #666; font-size: 13px; margin: 0 0 18px; line-height: 1.6; }

.fc-table-wrap { border: 1px solid #d8dde6; border-radius: 5px; overflow: hidden; }
.fc-table { width: 100%; border-collapse: collapse; background: #fff; }
.fc-table thead tr { background: #f5f6fb; }
.fc-table thead th {
    padding: 10px 16px; text-align: left;
    font-size: 12.5px; font-weight: 600; color: #555;
    border-bottom: 1px solid #dde1eb;
}
.fc-table thead th:last-child { text-align: right; }
.fc-table tbody tr { border-bottom: 1px solid #eceef4; transition: background 0.12s; }
.fc-table tbody tr:last-child { border-bottom: none; }
.fc-table tbody tr:hover { background: #fafbff; }
.fc-table td { padding: 12px 16px; font-size: 13px; color: #333; }
.fc-table td:last-child { text-align: right; width: 40px; }

.fc-type-cell { display: flex; align-items: center; gap: 9px; }
.fc-type-icon { font-size: 15px; color: #7c6fa0; width: 20px; text-align: center; }
.fc-count { font-size: 13px; color: #555; font-weight: 500; }

.fc-edit-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 3px; color: #aaa;
    text-decoration: none; transition: color 0.15s, background 0.15s;
}
.fc-edit-btn:hover { color: #555; background: #f0f0f0; }
</style>

<div class="fc-page">

    <div class="fc-header">
        <i class="fa fa-tag"></i>
        <?php
        $title_txt = $langs->trans('FlotteTagsCategories');
        echo ($title_txt === 'FlotteTagsCategories') ? 'Fleet Tags/Categories' : $title_txt;
        ?>
    </div>

    <p class="fc-desc">
        <?php
        $desc = $langs->trans('FlotteCategoriesDesc');
        echo ($desc === 'FlotteCategoriesDesc')
            ? 'Categories (or tags) can be used to classify the objects managed by the Fleet module. Select the type you want to view or edit by clicking the pencil icon.'
            : $desc;
        ?>
    </p>

    <div class="fc-table-wrap">
        <table class="fc-table">
            <thead>
                <tr>
                    <th><?php echo $langs->trans('Type'); ?></th>
                    <th>
                        <?php
                        $nbcats = $langs->trans('NbOfCategories');
                        echo ($nbcats === 'NbOfCategories') ? 'Number of tags/categories' : $nbcats;
                        ?>
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($flotte_cat_types as $key => $type):
                    $count = isset($counts[$key]) ? $counts[$key] : 0;
                    // Dolibarr's native category management page for this type
                    $native_url = DOL_URL_ROOT.'/categories/index.php?type='.(int)$type['type_id'];
                    $label_txt  = $langs->trans($type['label']);
                    if ($label_txt === $type['label']) {
                        $label_txt = ucfirst($type['label']); // fallback if not in lang file
                    }
                ?>
                <tr>
                    <td>
                        <div class="fc-type-cell">
                            <i class="<?php echo dol_escape_htmltag($type['icon']); ?> fc-type-icon"></i>
                            <?php echo $label_txt; ?>
                        </div>
                    </td>
                    <td class="fc-count"><?php echo $count; ?></td>
                    <td>
                        <a href="<?php echo $native_url; ?>"
                           class="fc-edit-btn"
                           title="<?php echo $langs->trans('EditTagsCategories'); ?>">
                            <i class="fa fa-pencil-alt" style="font-size:14px;"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div><!-- .fc-page -->

<?php
llxFooter();
$db->close();