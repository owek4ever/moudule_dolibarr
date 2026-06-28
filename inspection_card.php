<?php
/* Copyright (C) 2024 Optimalogistics
 * Add Vehicle Inspection Form
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) { $res = @include substr($tmp, 0, ($i+1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) { $res = @include dirname(substr($tmp, 0, ($i+1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
$langs->loadLangs(array("flotte@flotte", "other"));

restrictedArea($user, 'flotte');

$action     = GETPOST('action', 'aZ09') ?: 'create';
$cancel     = GETPOST('cancel', 'alpha');
if ($cancel) { header("Location: ".dol_buildpath('/flotte/inspection_list.php', 1)); exit; }

/* ── Load Vehicles ──────────────────────────────────────────────────────── */
$vehicles = array();
$sql = "SELECT rowid, ref, registration_number, CONCAT(maker, ' ', model) AS label"
     . " FROM ".MAIN_DB_PREFIX."flotte_vehicle"
     . " WHERE entity = ".((int)$conf->entity)." ORDER BY maker, model";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $vehicles[$obj->rowid] = array(
            'ref'   => $obj->ref,
            'label' => $obj->label,
            'reg'   => $obj->registration_number,
        );
    }
}

/* ── Checklist Items Definition ─────────────────────────────────────────── */
$checklistItems = array(
    /* left column */
    'petrol_card'      => 'PetrolCard',
    'inverter_cig'     => 'InverterCigarette',
    'interior_damages' => 'InteriorDamages',
    'exterior_damages' => 'ExteriorDamages',
    'ladders'          => 'LaddersExtensionLadder',
    'power_tools'      => 'AnyOfOurPowerTools',
    'lights_headlights'=> 'LightsHeadlightsWorking',
    'windows'          => 'WindowsWorking',
    'oil_check'        => 'OilCheck',
    'tool_boxes'       => 'ToolBoxes',
    /* right column */
    'lights_indicators'=> 'LightsIndicators',
    'car_mats'         => 'CarMatsCarSeatCovers',
    'interior_lights'  => 'InteriorLights',
    'tyres'            => 'TyresNewNeedReplacing',
    'extension_leeds'  => 'ExtensionLeeds',
    'air_conditioner'  => 'AirConditioner',
    'auto_locks'       => 'AutomaticLocksAlarmsWorking',
    'car_seats'        => 'ConditionOfCarSeats',
    'suspension'       => 'Suspension',
);

/* Keys in display order: left col = first 10, right col = last 9 */
$leftKeys  = array_slice(array_keys($checklistItems), 0, 10);
$rightKeys = array_slice(array_keys($checklistItems), 10);

/* ── Handle Save ────────────────────────────────────────────────────────── */
if ($action === 'save') {
    $postedToken  = GETPOST('token', 'alpha');
    $sessionToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : (isset($_SESSION['token']) ? $_SESSION['token'] : '');
    if (function_exists('checkToken')) {
        if (!checkToken()) {
            setEventMessages($langs->trans("InvalidToken"), null, 'errors');
            header("Location: ".$_SERVER['PHP_SELF']); exit;
        }
    } elseif (empty($postedToken) || empty($sessionToken) || $postedToken !== $sessionToken) {
        setEventMessages($langs->trans("InvalidToken"), null, 'errors');
        header("Location: ".$_SERVER['PHP_SELF']); exit;
    }

    $fk_vehicle   = (int) GETPOST('fk_vehicle', 'int');
    $reg_number   = GETPOST('registration_number', 'alpha');
    $meter_out    = (int) GETPOST('meter_out', 'int');
    $meter_in     = (int) GETPOST('meter_in', 'int');
    $fuel_out     = GETPOST('fuel_out', 'alpha');
    $fuel_in      = GETPOST('fuel_in', 'alpha');
    $datetime_out = GETPOST('datetime_out', 'alpha');
    $datetime_in  = GETPOST('datetime_in', 'alpha');

    if (empty($fk_vehicle)) {
        setEventMessages($langs->trans('PleaseSelectVehicle'), null, 'errors');
        header("Location: ".$_SERVER['PHP_SELF']); exit;
    }

    $ref = 'INSP-'.date('YmdHis').'-'.$fk_vehicle;

    $db->begin();
    $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_inspection"
         . " (ref, entity, fk_vehicle, meter_out, meter_in, fuel_out, fuel_in,"
         . "  datetime_out, datetime_in, tms)"
         . " VALUES ("
         . "'".$db->escape($ref)."',"
         . ((int)$conf->entity).","
         . ((int)$fk_vehicle).","
         . ((int)$meter_out).","
         . ((int)$meter_in).","
         . "'".$db->escape($fuel_out)."',"
         . "'".$db->escape($fuel_in)."',"
         . "'".$db->escape($datetime_out)."',"
         . "'".$db->escape($datetime_in)."',"
         . "NOW())";

    if ($db->query($sql)) {
        $new_id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_inspection");
        /* Save checklist answers */
        foreach ($checklistItems as $key => $label) {
            $answer = GETPOST('check_'.$key, 'alpha');
            $notes  = GETPOST('notes_'.$key, 'restricthtml');
            if (!empty($answer)) {
                $sql2 = "INSERT INTO ".MAIN_DB_PREFIX."flotte_inspection_checklist"
                      . " (fk_inspection, item_key, answer, notes)"
                      . " VALUES ("
                      . ((int)$new_id).","
                      . "'".$db->escape($key)."',"
                      . "'".$db->escape($answer)."',"
                      . "'".$db->escape($notes)."')";
                $db->query($sql2);
            }
        }
        $db->commit();
        setEventMessages($langs->trans('InspectionSaved'), null, 'mesgs');
        header("Location: ".dol_buildpath('/flotte/inspection_list.php', 1)); exit;
    } else {
        $db->rollback();
        setEventMessages('Save failed: '.$db->lasterror(), null, 'errors');
    }
}

llxHeader('', $langs->trans('AddVehicleInspection'), '');
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

/* ── Reset & base ── */
.insp-page * { box-sizing: border-box; }
.insp-page {
    font-family: 'DM Sans', sans-serif;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 4px 56px;
    color: #1a1f2e;
}

/* ── Page header ── */
.dc-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 24px 0 20px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px;
    gap: 16px; flex-wrap: wrap;
}
.dc-header-left { display: flex; align-items: center; gap: 14px; }
.dc-header-icon {
    width: 44px; height: 44px; border-radius: 11px;
    background: rgba(22,163,74,0.1);
    display: flex; align-items: center; justify-content: center;
    color: #16a34a; font-size: 19px; flex-shrink: 0;
}
.dc-header-title { font-size: 20px; font-weight: 700; color: #1a1f2e; margin: 0 0 2px; letter-spacing: -0.3px; }
.dc-header-sub   { font-size: 12px; color: #8b92a9; }
.dc-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary { background: #16a34a !important; color: #fff !important; }
.dc-btn-primary:hover { background: #15803d !important; color: #fff !important; }
.dc-btn-ghost {
    background: #fff !important; color: #5a6482 !important;
    border: 1.5px solid #d1d5e0 !important;
}
.dc-btn-ghost:hover { background: #f5f6fa !important; color: #2d3748 !important; }

/* ── Card container ── */
.dc-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    overflow: visible;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
    margin-bottom: 20px;
    padding: 0;
}

/* ── Section title bar ── */
.insp-section-bar {
    display: flex; align-items: center; gap: 10px;
    padding: 13px 22px 12px;
    border-bottom: 1px solid #f0f2f8;
    background: #f7f8fc;
    border-radius: 12px 12px 0 0;
}
.insp-section-icon {
    width: 26px; height: 26px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; flex-shrink: 0;
    background: rgba(22,163,74,0.1); color: #16a34a;
}
.insp-section-title {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9;
}

/* ── 2-column grid used for general fields ── */
.insp-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    padding: 0;
}
.insp-grid-2 .insp-field-cell {
    padding: 18px 22px 16px;
    border-bottom: 1px solid #f5f6fb;
    border-right: 1px solid #f0f2f8;
}
.insp-grid-2 .insp-field-cell:nth-child(2n) { border-right: none; }
.insp-grid-2 .insp-field-cell:last-child,
.insp-grid-2 .insp-field-cell:nth-last-child(2):nth-child(odd) {
    border-bottom: none;
}

/* ── Field label ── */
.insp-label {
    display: block;
    font-size: 11px; font-weight: 700;
    letter-spacing: 0.6px; text-transform: uppercase;
    color: #8b92a9; margin-bottom: 8px;
}

/* ── Text / number / datetime inputs ── */
.insp-input {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #e2e5f0;
    border-radius: 7px;
    font-size: 13.5px;
    font-family: 'DM Sans', sans-serif;
    color: #2d3748;
    background: #fafbfe;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    appearance: none;
    -webkit-appearance: none;
}
.insp-input:focus {
    border-color: #16a34a;
    box-shadow: 0 0 0 3px rgba(22,163,74,0.1);
    background: #fff;
}
input[type="number"].insp-input { font-family: 'DM Mono', monospace; }

/* ── Spinners for number inputs ── */
input[type="number"].insp-input-spin {
    padding-right: 0;
    width: 100%;
}

/* ── Select ── */
.insp-select {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #e2e5f0 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: #2d3748 !important;
    background: #fafbfe !important;
    outline: none !important;
    transition: border-color 0.15s !important;
    cursor: pointer;
}
.insp-select:focus { border-color: #16a34a !important; box-shadow: 0 0 0 3px rgba(22,163,74,0.1) !important; }

/* ── Fuel level radio group ── */
.insp-fuel-group {
    display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
    margin-top: 2px;
}
.insp-fuel-opt {
    display: flex; align-items: center; gap: 6px;
    cursor: pointer;
}
.insp-fuel-opt input[type="radio"] {
    accent-color: #16a34a;
    width: 15px; height: 15px; cursor: pointer;
}
.insp-fuel-opt span {
    font-size: 13px; font-weight: 500; color: #2d3748; white-space: nowrap;
}

/* ── Checklist section ── */
.insp-checklist-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    padding: 0;
}
.insp-check-row {
    display: flex;
    flex-direction: column;
    gap: 7px;
    padding: 14px 22px 13px;
    border-bottom: 1px solid #f5f6fb;
    border-right: 1px solid #f0f2f8;
}
.insp-checklist-grid > .insp-check-row:nth-child(2n) { border-right: none; }

/* Remove bottom border from the last row of each column (even/odd) */
.insp-checklist-grid > .insp-check-row:last-child,
.insp-checklist-grid > .insp-check-row:nth-last-child(2):nth-child(odd) {
    border-bottom: none;
}

.insp-check-label {
    font-size: 12.5px; font-weight: 600; color: #2d3748;
}
.insp-check-controls {
    display: flex; align-items: center; gap: 10px;
}
.insp-yn-group {
    display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.insp-yn-opt {
    display: flex; align-items: center; gap: 5px; cursor: pointer;
}
.insp-yn-opt input[type="radio"] {
    accent-color: #16a34a;
    width: 14px; height: 14px; cursor: pointer;
}
.insp-yn-opt span {
    font-size: 12.5px; font-weight: 500; color: #5a6482;
}
.insp-check-notes {
    flex: 1;
    padding: 6px 10px;
    border: 1.5px solid #e2e5f0;
    border-radius: 6px;
    font-size: 12.5px;
    font-family: 'DM Sans', sans-serif;
    color: #2d3748;
    background: #fafbfe;
    outline: none;
    transition: border-color 0.15s;
    min-width: 0;
}
.insp-check-notes:focus {
    border-color: #16a34a;
    box-shadow: 0 0 0 2px rgba(22,163,74,0.08);
    background: #fff;
}

/* ── Form footer ── */
.insp-footer {
    padding: 18px 22px;
    border-top: 1px solid #f0f2f8;
    display: flex; align-items: center; gap: 10px;
    background: #fafbfe;
    border-radius: 0 0 12px 12px;
}

/* ── Responsive ── */
@media (max-width: 760px) {
    .insp-grid-2,
    .insp-checklist-grid { grid-template-columns: 1fr; }
    .insp-grid-2 .insp-field-cell,
    .insp-check-row { border-right: none; }
}
</style>

<div class="insp-page">

<!-- ══ HEADER ════════════════════════════════════════════════════════════════ -->
<div class="dc-header">
    <div class="dc-header-left">
        <div class="dc-header-icon"><i class="fa fa-clipboard-check"></i></div>
        <div>
            <div class="dc-header-title"><?= $langs->trans('AddVehicleInspection') ?></div>
            <div class="dc-header-sub"><?= $langs->trans('FleetManagement') ?> &nbsp;&bull;&nbsp; <?= $langs->trans('NewInspectionRecord') ?></div>
        </div>
    </div>
    <div class="dc-header-actions">
        <a class="dc-btn dc-btn-ghost" href="<?= dol_buildpath('/flotte/inspection_list.php', 1) ?>">
            <i class="fa fa-arrow-left"></i> <?= $langs->trans('BackToList') ?>
        </a>
    </div>
</div>

<form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?= newToken() ?>">

<!-- ══ VEHICLE & GENERAL INFO ════════════════════════════════════════════════ -->
<div class="dc-card">
    <div class="insp-section-bar">
        <div class="insp-section-icon"><i class="fa fa-car"></i></div>
        <span class="insp-section-title"><?= $langs->trans('VehicleInformation') ?></span>
    </div>

    <div class="insp-grid-2">

        <!-- Select Vehicle -->
        <div class="insp-field-cell">
            <label class="insp-label" for="fk_vehicle"><?= $langs->trans('SelectVehicle') ?></label>
            <select id="fk_vehicle" name="fk_vehicle" class="insp-select" onchange="autoFillReg(this)">
                <option value=""><?= $langs->trans('SelectVehicle') ?>…</option>
                <?php foreach ($vehicles as $vid => $v): ?>
                <option value="<?= (int)$vid ?>"
                        data-reg="<?= dol_escape_htmltag($v['reg']) ?>"
                        <?= (GETPOST('fk_vehicle','int') == $vid ? 'selected' : '') ?>>
                    [<?= dol_escape_htmltag($v['ref']) ?>] <?= dol_escape_htmltag($v['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Registration Number -->
        <div class="insp-field-cell">
            <label class="insp-label" for="registration_number"><?= $langs->trans('RegistrationNumber') ?></label>
            <input id="registration_number" name="registration_number" type="text"
                   class="insp-input"
                   value="<?= dol_escape_htmltag(GETPOST('registration_number','alpha')) ?>"
                   placeholder="e.g. 123 TUN 4567">
        </div>

        <!-- Meter Reading Outgoing -->
        <div class="insp-field-cell">
            <label class="insp-label" for="meter_out"><?= $langs->trans('MeterReadingOutgoing') ?> (km)</label>
            <input id="meter_out" name="meter_out" type="number" min="0"
                   class="insp-input"
                   value="<?= (int)GETPOST('meter_out','int') ?: '' ?>">
        </div>

        <!-- Meter Reading Incoming -->
        <div class="insp-field-cell">
            <label class="insp-label" for="meter_in"><?= $langs->trans('MeterReadingIncoming') ?> (km)</label>
            <input id="meter_in" name="meter_in" type="number" min="0"
                   class="insp-input"
                   value="<?= (int)GETPOST('meter_in','int') ?: '' ?>">
        </div>

        <!-- Fuel Level Outgoing -->
        <div class="insp-field-cell">
            <label class="insp-label"><?= $langs->trans('FuelLevelOutgoing') ?></label>
            <div class="insp-fuel-group">
                <?php foreach (array('1/4','1/2','3/4','full') as $fval): ?>
                <?php $flabel = ($fval === 'full') ? $langs->trans('FullTank') : $fval; ?>
                <label class="insp-fuel-opt">
                    <input type="radio" name="fuel_out" value="<?= $fval ?>"
                           <?= (GETPOST('fuel_out','alpha') === $fval || (!GETPOST('fuel_out') && $fval === '1/4') ? 'checked' : '') ?>>
                    <span><?= $flabel ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Fuel Level Incoming -->
        <div class="insp-field-cell">
            <label class="insp-label"><?= $langs->trans('FuelLevelIncoming') ?></label>
            <div class="insp-fuel-group">
                <?php foreach (array('1/4','1/2','3/4','full') as $fval): ?>
                <?php $flabel = ($fval === 'full') ? $langs->trans('FullTank') : $fval; ?>
                <label class="insp-fuel-opt">
                    <input type="radio" name="fuel_in" value="<?= $fval ?>"
                           <?= (GETPOST('fuel_in','alpha') === $fval || (!GETPOST('fuel_in') && $fval === '1/4') ? 'checked' : '') ?>>
                    <span><?= $flabel ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Date & Time Outgoing -->
        <div class="insp-field-cell">
            <label class="insp-label" for="datetime_out"><?= $langs->trans('DateTimeOutgoing') ?></label>
            <input id="datetime_out" name="datetime_out" type="datetime-local"
                   class="insp-input"
                   value="<?= dol_escape_htmltag(GETPOST('datetime_out','alpha')) ?>">
        </div>

        <!-- Date & Time Incoming -->
        <div class="insp-field-cell">
            <label class="insp-label" for="datetime_in"><?= $langs->trans('DateTimeIncoming') ?></label>
            <input id="datetime_in" name="datetime_in" type="datetime-local"
                   class="insp-input"
                   value="<?= dol_escape_htmltag(GETPOST('datetime_in','alpha')) ?>">
        </div>

    </div><!-- /insp-grid-2 -->
</div><!-- /dc-card vehicle info -->

<!-- ══ CHECKLIST ════════════════════════════════════════════════════════════ -->
<div class="dc-card">
    <div class="insp-section-bar">
        <div class="insp-section-icon"><i class="fa fa-tasks"></i></div>
        <span class="insp-section-title"><?= $langs->trans('InspectionChecklist') ?></span>
    </div>

    <?php
    /* Interleave left & right columns row by row */
    $maxRows = max(count($leftKeys), count($rightKeys));
    echo '<div class="insp-checklist-grid">';

    for ($r = 0; $r < $maxRows; $r++):
        foreach (array($leftKeys, $rightKeys) as $colKeys):
            if (!isset($colKeys[$r])) {
                echo '<div class="insp-check-row" style="background:#f7f8fc;"></div>';
                continue;
            }
            $key   = $colKeys[$r];
            $label = $langs->trans($checklistItems[$key]);
            $savedAns   = GETPOST('check_'.$key, 'alpha');
            $savedNotes = GETPOST('notes_'.$key, 'restricthtml');
    ?>
    <div class="insp-check-row">
        <div class="insp-check-label"><?= dol_escape_htmltag($label) ?></div>
        <div class="insp-check-controls">
            <div class="insp-yn-group">
                <label class="insp-yn-opt">
                    <input type="radio" name="check_<?= $key ?>" value="yes"
                           <?= ($savedAns === 'yes' ? 'checked' : '') ?>>
                    <span><?= $langs->trans('Yes') ?></span>
                </label>
                <label class="insp-yn-opt">
                    <input type="radio" name="check_<?= $key ?>" value="no"
                           <?= ($savedAns === 'no' ? 'checked' : '') ?>>
                    <span><?= $langs->trans('No') ?></span>
                </label>
            </div>
            <input type="text" name="notes_<?= $key ?>"
                   class="insp-check-notes"
                   value="<?= dol_escape_htmltag($savedNotes) ?>"
                   placeholder="<?= $langs->trans('Notes') ?>…">
        </div>
    </div>
    <?php
        endforeach;
    endfor;
    echo '</div><!-- /insp-checklist-grid -->';
    ?>

    <!-- ── Footer / Submit ── -->
    <div class="insp-footer">
        <button type="submit" class="dc-btn dc-btn-primary">
            <i class="fa fa-save"></i> <?= $langs->trans('Submit') ?>
        </button>
        <a href="<?= dol_buildpath('/flotte/inspection_list.php', 1) ?>" class="dc-btn dc-btn-ghost">
            <i class="fa fa-times"></i> <?= $langs->trans('Cancel') ?>
        </a>
    </div>

</div><!-- /dc-card checklist -->

</form>
</div><!-- /insp-page -->

<script>
/* Auto-fill registration number when a vehicle is selected */
function autoFillReg(sel) {
    var opt = sel.options[sel.selectedIndex];
    var reg = opt ? (opt.getAttribute('data-reg') || '') : '';
    var regInput = document.getElementById('registration_number');
    if (regInput && reg) regInput.value = reg;
}
</script>

<?php
llxFooter();
$db->close();
?>