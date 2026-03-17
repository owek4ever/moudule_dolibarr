<?php
/* Copyright (C) 2024 Your Company
 * Expense card — create / edit / view individual expense entries
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) { $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php"; }
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php"))          { $res = @include substr($tmp, 0, ($i+1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) { $res = @include dirname(substr($tmp, 0, ($i+1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array("flotte@flotte", "other"));
restrictedArea($user, 'flotte');

$form = new Form($db);

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ENSURE TABLE EXISTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."flotte_expense (
  rowid              INT AUTO_INCREMENT PRIMARY KEY,
  ref                VARCHAR(30)      DEFAULT NULL,
  fk_booking         INT              DEFAULT NULL,
  expense_date       DATE             DEFAULT NULL,
  category           VARCHAR(30)      DEFAULT 'other',
  amount             DECIMAL(15,2)    DEFAULT NULL,
  notes              TEXT,
  fuel_vendor        INT              DEFAULT NULL,
  fuel_type          VARCHAR(50)      DEFAULT NULL,
  fuel_qty           DECIMAL(15,4)    DEFAULT NULL,
  fuel_price         DECIMAL(15,4)    DEFAULT NULL,
  road_toll          DECIMAL(15,2)    DEFAULT NULL,
  road_parking       DECIMAL(15,2)    DEFAULT NULL,
  road_other         DECIMAL(15,2)    DEFAULT NULL,
  driver_salary      DECIMAL(15,2)    DEFAULT NULL,
  driver_overnight   DECIMAL(15,2)    DEFAULT NULL,
  driver_bonus       DECIMAL(15,2)    DEFAULT NULL,
  commission_agent   DECIMAL(15,2)    DEFAULT NULL,
  commission_tax     DECIMAL(5,2)     DEFAULT NULL,
  commission_other   DECIMAL(15,2)    DEFAULT NULL,
  other_label        VARCHAR(255)     DEFAULT NULL,
  source             VARCHAR(20)      DEFAULT 'manual',
  entity             INT              DEFAULT 1,
  date_creation      DATETIME         DEFAULT NULL,
  fk_user_creat      INT              DEFAULT NULL,
  tms                TIMESTAMP        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   HELPERS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
function getNextExpenseRef($db, $entity) {
    $year = date('Y');
    $sql  = "SELECT MAX(CAST(SUBSTRING(ref, 9) AS UNSIGNED)) AS mx FROM ".MAIN_DB_PREFIX."flotte_expense WHERE ref LIKE 'EXP-".$year."-%' AND entity = ".((int)$entity);
    $res  = $db->query($sql);
    $mx   = 0;
    if ($res) { $obj = $db->fetch_object($res); $mx = (int)$obj->mx; }
    return 'EXP-'.$year.'-'.str_pad($mx + 1, 4, '0', STR_PAD_LEFT);
}

function calcExpenseAmount($category, $data) {
    switch ($category) {
        case 'fuel':
            $qty   = (float)($data['fuel_qty']   ?? 0);
            $price = (float)($data['fuel_price']  ?? 0);
            return $qty > 0 && $price > 0 ? round($qty * $price, 2) : (float)($data['amount'] ?? 0);
        case 'road':
            return round((float)($data['road_toll'] ?? 0) + (float)($data['road_parking'] ?? 0) + (float)($data['road_other'] ?? 0), 2);
        case 'driver':
            return round((float)($data['driver_salary'] ?? 0) + (float)($data['driver_overnight'] ?? 0) + (float)($data['driver_bonus'] ?? 0), 2);
        case 'commission':
            $agent = (float)($data['commission_agent'] ?? 0);
            $tax   = (float)($data['commission_tax']   ?? 0);
            $other = (float)($data['commission_other'] ?? 0);
            return round($agent + ($agent * $tax / 100) + $other, 2);
        default:
            return round((float)($data['amount'] ?? 0), 2);
    }
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   LOAD REFERENCE DATA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$vendors_list  = array();
$_vres = $db->query("SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity IN (".getEntity('flotte').") ORDER BY name");
if ($_vres) { while ($_vobj = $db->fetch_object($_vres)) { $vendors_list[$_vobj->rowid] = dol_escape_htmltag($_vobj->name); } }

$bookings_list = array();
$_bres = $db->query("SELECT rowid, ref FROM ".MAIN_DB_PREFIX."flotte_booking WHERE entity IN (".getEntity('flotte').") ORDER BY booking_date DESC LIMIT 200");
if ($_bres) { while ($_bobj = $db->fetch_object($_bres)) { $bookings_list[$_bobj->rowid] = dol_escape_htmltag($_bobj->ref); } }

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PARAMS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$action     = GETPOST('action', 'aZ09') ?: 'view';
$id         = GETPOST('id', 'int');
$fk_booking = GETPOST('fk_booking', 'int');
$error      = 0;
$errors     = array();

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ACTION: ADD
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($action == 'add') {
    $db->begin();

    $ref          = getNextExpenseRef($db, $conf->entity);
    $fk_booking_v = GETPOST('fk_booking', 'int');
    $expense_date = GETPOST('expense_dateyear','int') ? sprintf('%04d-%02d-%02d', GETPOST('expense_dateyear','int'), GETPOST('expense_datemonth','int'), GETPOST('expense_dateday','int')) : '';
    $category     = GETPOST('category', 'alphanohtml') ?: 'other';
    $notes        = GETPOST('notes', 'restricthtml');

    $data = array(
        'amount'           => GETPOST('amount', 'alpha'),
        'fuel_qty'         => GETPOST('fuel_qty', 'alpha'),
        'fuel_price'       => GETPOST('fuel_price', 'alpha'),
        'fuel_type'        => GETPOST('fuel_type', 'alphanohtml'),
        'fuel_vendor'      => GETPOST('fuel_vendor', 'int'),
        'road_toll'        => GETPOST('road_toll', 'alpha'),
        'road_parking'     => GETPOST('road_parking', 'alpha'),
        'road_other'       => GETPOST('road_other', 'alpha'),
        'driver_salary'    => GETPOST('driver_salary', 'alpha'),
        'driver_overnight' => GETPOST('driver_overnight', 'alpha'),
        'driver_bonus'     => GETPOST('driver_bonus', 'alpha'),
        'commission_agent' => GETPOST('commission_agent', 'alpha'),
        'commission_tax'   => GETPOST('commission_tax', 'alpha'),
        'commission_other' => GETPOST('commission_other', 'alpha'),
        'other_label'      => GETPOST('other_label', 'alphanohtml'),
    );
    $amount = calcExpenseAmount($category, $data);

    if (empty($expense_date)) { $error++; $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Date")); }

    if (!$error) {
        $sql  = "INSERT INTO ".MAIN_DB_PREFIX."flotte_expense ";
        $sql .= "(ref, fk_booking, expense_date, category, amount, notes, ";
        $sql .= "fuel_vendor, fuel_type, fuel_qty, fuel_price, ";
        $sql .= "road_toll, road_parking, road_other, ";
        $sql .= "driver_salary, driver_overnight, driver_bonus, ";
        $sql .= "commission_agent, commission_tax, commission_other, ";
        $sql .= "other_label, source, entity, date_creation, fk_user_creat) VALUES (";
        $sql .= "'".$db->escape($ref)."', ";
        $sql .= ($fk_booking_v > 0 ? ((int)$fk_booking_v) : "NULL").", ";
        $sql .= "'".$db->escape($expense_date)."', ";
        $sql .= "'".$db->escape($category)."', ";
        $sql .= ($amount ? ((float)$amount) : "NULL").", ";
        $sql .= (!empty($notes) ? "'".$db->escape($notes)."'" : "NULL").", ";
        $sql .= ($data['fuel_vendor'] > 0 ? ((int)$data['fuel_vendor']) : "NULL").", ";
        $sql .= (!empty($data['fuel_type'])        ? "'".$db->escape($data['fuel_type'])."'"        : "NULL").", ";
        $sql .= (!empty($data['fuel_qty'])          ? ((float)$data['fuel_qty'])          : "NULL").", ";
        $sql .= (!empty($data['fuel_price'])        ? ((float)$data['fuel_price'])        : "NULL").", ";
        $sql .= (!empty($data['road_toll'])         ? ((float)$data['road_toll'])         : "NULL").", ";
        $sql .= (!empty($data['road_parking'])      ? ((float)$data['road_parking'])      : "NULL").", ";
        $sql .= (!empty($data['road_other'])        ? ((float)$data['road_other'])        : "NULL").", ";
        $sql .= (!empty($data['driver_salary'])     ? ((float)$data['driver_salary'])     : "NULL").", ";
        $sql .= (!empty($data['driver_overnight'])  ? ((float)$data['driver_overnight'])  : "NULL").", ";
        $sql .= (!empty($data['driver_bonus'])      ? ((float)$data['driver_bonus'])      : "NULL").", ";
        $sql .= (!empty($data['commission_agent'])  ? ((float)$data['commission_agent'])  : "NULL").", ";
        $sql .= (!empty($data['commission_tax'])    ? ((float)$data['commission_tax'])    : "NULL").", ";
        $sql .= (!empty($data['commission_other'])  ? ((float)$data['commission_other'])  : "NULL").", ";
        $sql .= (!empty($data['other_label'])       ? "'".$db->escape($data['other_label'])."'"     : "NULL").", ";
        $sql .= "'manual', ".((int)$conf->entity).", NOW(), ".((int)$user->id).")";

        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_expense");
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("ExpenseCreatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $db->lasterror();
        }
    }
    if ($error) $db->rollback();
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ACTION: UPDATE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($action == 'update' && $id > 0) {
    $db->begin();

    $fk_booking_v = GETPOST('fk_booking', 'int');
    $expense_date = GETPOST('expense_dateyear','int') ? sprintf('%04d-%02d-%02d', GETPOST('expense_dateyear','int'), GETPOST('expense_datemonth','int'), GETPOST('expense_dateday','int')) : '';
    $category     = GETPOST('category', 'alphanohtml') ?: 'other';
    $notes        = GETPOST('notes', 'restricthtml');

    $data = array(
        'amount'           => GETPOST('amount', 'alpha'),
        'fuel_qty'         => GETPOST('fuel_qty', 'alpha'),
        'fuel_price'       => GETPOST('fuel_price', 'alpha'),
        'fuel_type'        => GETPOST('fuel_type', 'alphanohtml'),
        'fuel_vendor'      => GETPOST('fuel_vendor', 'int'),
        'road_toll'        => GETPOST('road_toll', 'alpha'),
        'road_parking'     => GETPOST('road_parking', 'alpha'),
        'road_other'       => GETPOST('road_other', 'alpha'),
        'driver_salary'    => GETPOST('driver_salary', 'alpha'),
        'driver_overnight' => GETPOST('driver_overnight', 'alpha'),
        'driver_bonus'     => GETPOST('driver_bonus', 'alpha'),
        'commission_agent' => GETPOST('commission_agent', 'alpha'),
        'commission_tax'   => GETPOST('commission_tax', 'alpha'),
        'commission_other' => GETPOST('commission_other', 'alpha'),
        'other_label'      => GETPOST('other_label', 'alphanohtml'),
    );
    $amount = calcExpenseAmount($category, $data);

    if (empty($expense_date)) { $error++; $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Date")); }

    if (!$error) {
        $sql  = "UPDATE ".MAIN_DB_PREFIX."flotte_expense SET ";
        $sql .= "fk_booking = ".($fk_booking_v > 0 ? ((int)$fk_booking_v) : "NULL").", ";
        $sql .= "expense_date = '".$db->escape($expense_date)."', ";
        $sql .= "category = '".$db->escape($category)."', ";
        $sql .= "amount = ".($amount ? ((float)$amount) : "NULL").", ";
        $sql .= "notes = ".(!empty($notes) ? "'".$db->escape($notes)."'" : "NULL").", ";
        $sql .= "fuel_vendor = ".($data['fuel_vendor'] > 0 ? ((int)$data['fuel_vendor']) : "NULL").", ";
        $sql .= "fuel_type = ".(!empty($data['fuel_type'])       ? "'".$db->escape($data['fuel_type'])."'"    : "NULL").", ";
        $sql .= "fuel_qty = ".(!empty($data['fuel_qty'])         ? ((float)$data['fuel_qty'])         : "NULL").", ";
        $sql .= "fuel_price = ".(!empty($data['fuel_price'])     ? ((float)$data['fuel_price'])       : "NULL").", ";
        $sql .= "road_toll = ".(!empty($data['road_toll'])       ? ((float)$data['road_toll'])        : "NULL").", ";
        $sql .= "road_parking = ".(!empty($data['road_parking']) ? ((float)$data['road_parking'])     : "NULL").", ";
        $sql .= "road_other = ".(!empty($data['road_other'])     ? ((float)$data['road_other'])       : "NULL").", ";
        $sql .= "driver_salary = ".(!empty($data['driver_salary'])    ? ((float)$data['driver_salary'])    : "NULL").", ";
        $sql .= "driver_overnight = ".(!empty($data['driver_overnight']) ? ((float)$data['driver_overnight']) : "NULL").", ";
        $sql .= "driver_bonus = ".(!empty($data['driver_bonus'])      ? ((float)$data['driver_bonus'])      : "NULL").", ";
        $sql .= "commission_agent = ".(!empty($data['commission_agent']) ? ((float)$data['commission_agent']) : "NULL").", ";
        $sql .= "commission_tax = ".(!empty($data['commission_tax'])   ? ((float)$data['commission_tax'])   : "NULL").", ";
        $sql .= "commission_other = ".(!empty($data['commission_other']) ? ((float)$data['commission_other']) : "NULL").", ";
        $sql .= "other_label = ".(!empty($data['other_label']) ? "'".$db->escape($data['other_label'])."'" : "NULL")." ";
        $sql .= "WHERE rowid = ".((int)$id);

        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("ExpenseUpdatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $db->lasterror();
        }
    }
    if ($error) $db->rollback();
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ACTION: DELETE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($action == 'confirm_delete' && $id > 0) {
    $db->query("DELETE FROM ".MAIN_DB_PREFIX."flotte_expense WHERE rowid = ".((int)$id)." AND source = 'manual'");
    setEventMessages($langs->trans("ExpenseDeletedSuccessfully"), null, 'mesgs');
    header('Location: '.dol_buildpath('/flotte/expenses_list.php', 1).($fk_booking > 0 ? '?fk_booking='.$fk_booking : ''));
    exit;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   LOAD OBJECT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$object = null;
if ($id > 0 && in_array($action, array('view','edit','delete'))) {
    $res = $db->query("SELECT e.*, b.ref AS booking_ref FROM ".MAIN_DB_PREFIX."flotte_expense e LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b ON b.rowid = e.fk_booking WHERE e.rowid = ".((int)$id));
    if ($res) $object = $db->fetch_object($res);
}
// Pre-fill fk_booking for create
if ($action == 'create' && $fk_booking > 0 && !$object) {
    $object = new stdClass();
    $object->fk_booking = $fk_booking;
    $object->expense_date = date('Y-m-d');
    $object->category = 'fuel';
}

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   HTML OUTPUT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$pageTitle = $isCreate ? $langs->trans('NewExpense') : ($isEdit ? $langs->trans('EditExpense') : $langs->trans('Expense'));
llxHeader('', $pageTitle);
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');
.dc-page * { box-sizing:border-box; }
.dc-page { font-family:'DM Sans',sans-serif; max-width:900px; margin:0 auto; padding:0 2px 64px; color:#1a1f2e; }
/* Header */
.dc-header { display:flex;align-items:center;justify-content:space-between;padding:26px 0 22px;border-bottom:1px solid #e8eaf0;margin-bottom:28px;gap:16px;flex-wrap:wrap; }
.dc-header-left { display:flex;align-items:center;gap:14px; }
.dc-header-icon { width:46px;height:46px;border-radius:12px;background:rgba(217,119,6,0.1);display:flex;align-items:center;justify-content:center;color:#d97706;font-size:20px;flex-shrink:0; }
.dc-header-title { font-size:21px;font-weight:700;color:#1a1f2e;margin:0 0 3px;letter-spacing:-0.3px; }
.dc-header-sub { font-size:12.5px;color:#8b92a9; }
.dc-header-actions { display:flex;gap:8px;align-items:center;flex-wrap:wrap; }
/* Buttons */
.dc-btn { display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none!important;cursor:pointer;font-family:'DM Sans',sans-serif;white-space:nowrap;transition:all 0.15s;border:none; }
.dc-btn-primary { background:#3c4758!important;color:#fff!important; }
.dc-btn-primary:hover { background:#2a3346!important; }
.dc-btn-ghost { background:#fff!important;color:#5a6482!important;border:1.5px solid #d1d5e0!important; }
.dc-btn-ghost:hover { background:#f5f6fa!important;color:#2d3748!important; }
.dc-btn-danger { background:#fff!important;color:#dc2626!important;border:1.5px solid #fca5a5!important; }
.dc-btn-danger:hover { background:#fef2f2!important; }
/* Cards */
.dc-card { background:#fff;border:1px solid #e8eaf0;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,0.04);margin-bottom:16px; }
.dc-card-header { display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #f0f2f8;background:#f7f8fc;border-radius:12px 12px 0 0; }
.dc-card-header-icon { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0; }
.dc-card-header-icon.amber  { background:rgba(217,119,6,0.1);color:#d97706; }
.dc-card-header-icon.blue   { background:rgba(59,130,246,0.1);color:#3b82f6; }
.dc-card-header-icon.green  { background:rgba(22,163,74,0.1);color:#16a34a; }
.dc-card-header-icon.purple { background:rgba(109,40,217,0.1);color:#6d28d9; }
.dc-card-header-icon.slate  { background:rgba(60,71,88,0.1);color:#3c4758; }
.dc-card-title { font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.7px;color:#8b92a9; }
.dc-card-body { padding:0; }
/* Fields */
.dc-field { display:flex;align-items:center;gap:16px;padding:13px 20px;border-bottom:1px solid #f5f6fb; }
.dc-field:last-child { border-bottom:none; }
.dc-field-label { flex:0 0 180px;font-size:12px;font-weight:600;color:#8b92a9;text-transform:uppercase;letter-spacing:0.5px; }
.dc-field-label.required::after { content:' *';color:#ef4444; }
.dc-field-value { flex:1;font-size:13.5px;color:#2d3748; }
.dc-field-value input[type="number"],
.dc-field-value input[type="text"],
.dc-field-value textarea,
.dc-field-value select { width:100%;border:1.5px solid #e2e5f0!important;border-radius:8px!important;padding:8px 12px!important;font-size:13px!important;font-weight:400!important;color:#2d3748!important;background:#fafbfe!important;outline:none;font-family:'DM Sans',sans-serif!important;transition:border-color 0.15s,box-shadow 0.15s!important; }
.dc-field-value input:focus, .dc-field-value select:focus, .dc-field-value textarea:focus { border-color:#3c4758!important;box-shadow:0 0 0 3px rgba(60,71,88,0.1)!important;background:#fff!important; }
.dc-field-value .dc-total-input { background:#f7f8fc!important;border-color:#d1d5e0!important;font-weight:600!important;color:#1a1f2e!important;font-family:'DM Mono',monospace!important; }
.dc-chip { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#f0f2f8;color:#3c4758;border:1px solid #e2e5f0; }
.dc-amount { font-family:'DM Mono',monospace;font-size:13.5px;font-weight:600;color:#2d3748; }
/* Category badge */
.dc-cat-badge { display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600; }
.dc-cat-badge.fuel       { background:rgba(245,158,11,0.12);color:#b45309; }
.dc-cat-badge.road       { background:rgba(59,130,246,0.12);color:#1d4ed8; }
.dc-cat-badge.driver     { background:rgba(109,40,217,0.12);color:#6d28d9; }
.dc-cat-badge.commission { background:rgba(22,163,74,0.12);color:#166534; }
.dc-cat-badge.other      { background:rgba(60,71,88,0.1);color:#3c4758; }
/* Source badge */
.dc-source-badge { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#fef3c7;color:#92400e; }
.dc-source-badge.manual { background:#eff6ff;color:#1d4ed8; }
/* Action bar */
.dc-action-bar { display:flex;align-items:center;gap:10px;padding:18px 0 0;border-top:1px solid #e8eaf0;margin-top:8px; }
.dc-action-bar-left { margin-right:auto; }
/* Booking-sourced notice */
.dc-notice { display:flex;align-items:flex-start;gap:10px;padding:12px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12.5px;color:#92400e;margin-bottom:16px; }
.dc-notice i { margin-top:1px;flex-shrink:0; }
</style>
<?php

if (!empty($errors)) {
    foreach ($errors as $em) setEventMessage($em, 'errors');
}

print '<div class="dc-page">';

/* ── Header ── */
$catLabel = '';
if ($object && !empty($object->category)) {
    $catMap = array('fuel'=>$langs->trans('FuelExpenses'),'road'=>$langs->trans('RoadExpenses'),'driver'=>$langs->trans('DriverExpenses'),'commission'=>$langs->trans('CommissionExpenses'),'other'=>$langs->trans('OtherExpenses'));
    $catLabel = $catMap[$object->category] ?? ucfirst($object->category);
}

print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-receipt"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.($isCreate ? $langs->trans('NewExpense') : (isset($object->ref) ? dol_escape_htmltag($object->ref) : $langs->trans('Expense'))).'</div>';
print '      <div class="dc-header-sub">'.($isCreate ? $langs->trans('FillInExpenseDetails') : $catLabel).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
print '    <a href="'.dol_buildpath('/flotte/expenses_list.php',1).($fk_booking > 0 ? '?fk_booking='.$fk_booking : '').'" class="dc-btn dc-btn-ghost"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
if ($isView && $id > 0 && !empty($user->rights->flotte->write)) {
    print '    <a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit" class="dc-btn dc-btn-ghost"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
}
if ($isView && $id > 0 && isset($object->source) && $object->source === 'manual') {
    print '    <a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete" class="dc-btn dc-btn-danger"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

// Delete confirmation
if ($action == 'delete' && $id > 0) {
    $formconfirm = $form->formconfirm(
        $_SERVER['PHP_SELF'].'?id='.$id.($fk_booking > 0 ? '&fk_booking='.$fk_booking : ''),
        $langs->trans('DeleteExpense'),
        $langs->trans('ConfirmDeleteExpense'),
        'confirm_delete', '', 0, 1
    );
    print $formconfirm;
}

// Booking-sourced notice
if ($isView && isset($object->source) && $object->source === 'booking') {
    print '<div class="dc-notice"><i class="fa fa-info-circle"></i><span>'.$langs->trans('ExpenseFromBookingNotice').' <a href="'.dol_buildpath('/flotte/booking_card.php',1).'?id='.((int)$object->fk_booking).'">'.$langs->trans('EditInBooking').'</a></span></div>';
}

/* ── Form ── */
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    if ($fk_booking > 0) print '<input type="hidden" name="fk_booking_ctx" value="'.$fk_booking.'">';
}

$cat = ($object && !empty($object->category)) ? $object->category : 'fuel';

/* ── GENERAL INFO CARD ── */
print '<div class="dc-card">';
print '<div class="dc-card-header"><div class="dc-card-header-icon slate"><i class="fa fa-receipt"></i></div><span class="dc-card-title">'.$langs->trans('ExpenseDetails').'</span></div>';
print '<div class="dc-card-body">';

// Category
print '<div class="dc-field"><div class="dc-field-label required">'.$langs->trans('Category').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $cats = array('fuel'=>$langs->trans('FuelExpenses'),'road'=>$langs->trans('RoadExpenses'),'driver'=>$langs->trans('DriverExpenses'),'commission'=>$langs->trans('CommissionExpenses'),'other'=>$langs->trans('Other'));
    print '<select name="category" id="expense_category" onchange="switchCategory(this.value)">';
    foreach ($cats as $cv => $cl) {
        print '<option value="'.dol_escape_htmltag($cv).'"'.($cat==$cv?' selected':'').'>'.dol_escape_htmltag($cl).'</option>';
    }
    print '</select>';
} else {
    $catIcons = array('fuel'=>'fa-gas-pump amber','road'=>'fa-road blue','driver'=>'fa-user-tie purple','commission'=>'fa-coins green','other'=>'fa-tag slate');
    $ic = $catIcons[$cat] ?? 'fa-tag slate';
    print '<span class="dc-cat-badge '.dol_escape_htmltag($cat).'"><i class="fa '.explode(' ',$ic)[0].'"></i>'.dol_escape_htmltag($catLabel).'</span>';
}
print '</div></div>';

// Date
$dv = $object && !empty($object->expense_date) ? $db->jdate($object->expense_date) : '';
print '<div class="dc-field"><div class="dc-field-label required">'.$langs->trans('Date').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate($dv, 'expense_date', 0, 0, 0, '', 1, 1);
} else {
    print (!empty($object->expense_date) ? '<span class="dc-chip"><i class="fa fa-calendar" style="font-size:11px;opacity:0.6;"></i>'.dol_print_date($db->jdate($object->expense_date), 'day').'</span>' : '&mdash;');
}
print '</div></div>';

// Booking
print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('Booking').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $bkv = $object && !empty($object->fk_booking) ? $object->fk_booking : ($fk_booking > 0 ? $fk_booking : '');
    print $form->selectarray('fk_booking', $bookings_list, $bkv, 1);
} else {
    if (!empty($object->fk_booking)) {
        print '<a href="'.dol_buildpath('/flotte/booking_card.php',1).'?id='.((int)$object->fk_booking).'" class="dc-chip"><i class="fa fa-link" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($object->booking_ref).'</a>';
    } else { print '&mdash;'; }
}
print '</div></div>';

// Notes
print '<div class="dc-field" style="align-items:flex-start;"><div class="dc-field-label" style="padding-top:10px;">'.$langs->trans('Notes').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<textarea name="notes" rows="2" placeholder="'.$langs->trans('OptionalNotes').'">'.dol_escape_htmltag($object->notes ?? '').'</textarea>';
} else {
    print (!empty($object->notes) ? nl2br(dol_escape_htmltag($object->notes)) : '<span style="color:#c4c9d8;">—</span>');
}
print '</div></div>';

print '</div></div>'; // body + card

/* ── FUEL FIELDS ── */
print '<div class="dc-card" id="section_fuel" style="'.($cat!='fuel'&&($isCreate||$isEdit)?'display:none;':'').'">';
print '<div class="dc-card-header"><div class="dc-card-header-icon amber"><i class="fa fa-gas-pump"></i></div><span class="dc-card-title">'.$langs->trans('FuelDetails').'</span></div>';
print '<div class="dc-card-body">';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('Vendor').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectarray('fuel_vendor', $vendors_list, $object->fuel_vendor ?? '', 1);
} else {
    $fvn = '&mdash;';
    if (!empty($object->fuel_vendor)) { foreach ($vendors_list as $vi => $vn) { if ($vi == $object->fuel_vendor) { $fvn = '<span class="dc-chip">'.dol_escape_htmltag($vn).'</span>'; break; } } }
    print $fvn;
}
print '</div></div>';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('FuelType').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $ftypes = array('gasoline'=>$langs->trans('Gasoline'),'diesel'=>$langs->trans('Diesel'),'lpg'=>'LPG','electric'=>$langs->trans('Electric'),'hybrid'=>$langs->trans('Hybrid'),'other'=>$langs->trans('Other'));
    print $form->selectarray('fuel_type', $ftypes, $object->fuel_type ?? '', 1);
} else {
    print (!empty($object->fuel_type) ? '<span class="dc-chip">'.dol_escape_htmltag(ucfirst($object->fuel_type)).'</span>' : '&mdash;');
}
print '</div></div>';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('Liters').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="fuel_qty" id="fuel_qty" value="'.dol_escape_htmltag($object->fuel_qty??'').'" min="0" step="any" placeholder="0.00" oninput="calcTotal()">';
} else {
    print (!empty($object->fuel_qty) ? '<span class="dc-amount">'.dol_escape_htmltag($object->fuel_qty).' L</span>' : '&mdash;');
}
print '</div></div>';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('PricePerLiter').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="fuel_price" id="fuel_price" value="'.dol_escape_htmltag($object->fuel_price??'').'" min="0" step="any" placeholder="0.00" oninput="calcTotal()">';
} else {
    print (!empty($object->fuel_price) ? '<span class="dc-amount">'.price($object->fuel_price).'</span>' : '&mdash;');
}
print '</div></div>';

print '<div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="amount_display" class="dc-total-input" value="'.dol_escape_htmltag($object->amount??'').'" min="0" step="any" placeholder="0.00" readonly style="font-family:\'DM Mono\',monospace;font-weight:600;">';
    print '<input type="hidden" name="amount" id="amount_hidden" value="'.dol_escape_htmltag($object->amount??'').'">';
} else {
    print (!empty($object->amount) ? '<span class="dc-amount" style="font-size:15px;font-weight:700;">'.price($object->amount).'</span>' : '&mdash;');
}
print '</div></div>';

print '</div></div>';

/* ── ROAD FIELDS ── */
print '<div class="dc-card" id="section_road" style="'.($cat!='road'&&($isCreate||$isEdit)?'display:none;':'').'">';
print '<div class="dc-card-header"><div class="dc-card-header-icon blue"><i class="fa fa-road"></i></div><span class="dc-card-title">'.$langs->trans('RoadDetails').'</span></div>';
print '<div class="dc-card-body">';

foreach (array('road_toll'=>$langs->trans('TollFees'),'road_parking'=>$langs->trans('ParkingFees'),'road_other'=>$langs->trans('OtherFees')) as $rfld => $rlabel) {
    $rval = $object->$rfld ?? '';
    print '<div class="dc-field"><div class="dc-field-label">'.dol_escape_htmltag($rlabel).'</div><div class="dc-field-value">';
    if ($isCreate || $isEdit) {
        print '<input type="number" name="'.$rfld.'" id="'.$rfld.'" value="'.dol_escape_htmltag($rval).'" min="0" step="any" placeholder="0.00" oninput="calcTotal()">';
    } else {
        print (!empty($rval) ? '<span class="dc-amount">'.price($rval).'</span>' : '&mdash;');
    }
    print '</div></div>';
}

print '<div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="road_total_display" class="dc-total-input" value="'.dol_escape_htmltag($object->amount??'').'" min="0" step="any" placeholder="0.00" readonly style="font-family:\'DM Mono\',monospace;font-weight:600;">';
    print '<input type="hidden" name="amount" id="road_amount_hidden" value="'.dol_escape_htmltag($object->amount??'').'">';
} else {
    print (!empty($object->amount) ? '<span class="dc-amount" style="font-size:15px;font-weight:700;">'.price($object->amount).'</span>' : '&mdash;');
}
print '</div></div>';

print '</div></div>';

/* ── DRIVER FIELDS ── */
print '<div class="dc-card" id="section_driver" style="'.($cat!='driver'&&($isCreate||$isEdit)?'display:none;':'').'">';
print '<div class="dc-card-header"><div class="dc-card-header-icon purple"><i class="fa fa-user-tie"></i></div><span class="dc-card-title">'.$langs->trans('DriverDetails').'</span></div>';
print '<div class="dc-card-body">';

foreach (array('driver_salary'=>$langs->trans('SalaryDayRate'),'driver_overnight'=>$langs->trans('OvernightFee'),'driver_bonus'=>$langs->trans('Bonus')) as $dfld => $dlabel) {
    $dval = $object->$dfld ?? '';
    print '<div class="dc-field"><div class="dc-field-label">'.dol_escape_htmltag($dlabel).'</div><div class="dc-field-value">';
    if ($isCreate || $isEdit) {
        print '<input type="number" name="'.$dfld.'" id="'.$dfld.'" value="'.dol_escape_htmltag($dval).'" min="0" step="any" placeholder="0.00" oninput="calcTotal()">';
    } else {
        print (!empty($dval) ? '<span class="dc-amount">'.price($dval).'</span>' : '&mdash;');
    }
    print '</div></div>';
}

print '<div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="driver_total_display" class="dc-total-input" value="'.dol_escape_htmltag($object->amount??'').'" min="0" step="any" placeholder="0.00" readonly style="font-family:\'DM Mono\',monospace;font-weight:600;">';
    print '<input type="hidden" name="amount" id="driver_amount_hidden" value="'.dol_escape_htmltag($object->amount??'').'">';
} else {
    print (!empty($object->amount) ? '<span class="dc-amount" style="font-size:15px;font-weight:700;">'.price($object->amount).'</span>' : '&mdash;');
}
print '</div></div>';

print '</div></div>';

/* ── COMMISSION FIELDS ── */
print '<div class="dc-card" id="section_commission" style="'.($cat!='commission'&&($isCreate||$isEdit)?'display:none;':'').'">';
print '<div class="dc-card-header"><div class="dc-card-header-icon green"><i class="fa fa-coins"></i></div><span class="dc-card-title">'.$langs->trans('CommissionDetails').'</span></div>';
print '<div class="dc-card-body">';

$cval_agent = $object->commission_agent ?? '';
$cval_tax   = $object->commission_tax   ?? '';
$cval_other = $object->commission_other ?? '';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('AgentCommission').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="commission_agent" id="commission_agent" value="'.dol_escape_htmltag($cval_agent).'" min="0" step="any" placeholder="0.00" oninput="calcTotal()">';
} else {
    print (!empty($cval_agent) ? '<span class="dc-amount">'.price($cval_agent).'</span>' : '&mdash;');
}
print '</div></div>';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('TaxRate').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div style="display:flex;align-items:center;gap:8px;">';
    print '<input type="number" name="commission_tax" id="commission_tax" value="'.dol_escape_htmltag($cval_tax).'" min="0" max="100" step="any" placeholder="0" oninput="calcTotal()" style="max-width:90px!important;">';
    print '<span style="font-size:13px;font-weight:600;color:#8b92a9;">%</span>';
    print '<span style="font-size:12px;color:#8b92a9;">→ <span id="comm_tax_amount" style="color:#2d3748;font-weight:600;">0.00</span></span>';
    print '</div>';
} else {
    if (!empty($cval_tax)) {
        $ta = round((float)$cval_agent * (float)$cval_tax / 100, 2);
        print '<span class="dc-chip">'.dol_escape_htmltag($cval_tax).'%</span>&nbsp;<span class="dc-amount">= '.price($ta).'</span>';
    } else { print '&mdash;'; }
}
print '</div></div>';

print '<div class="dc-field"><div class="dc-field-label">'.$langs->trans('OtherFees').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="commission_other" id="commission_other" value="'.dol_escape_htmltag($cval_other).'" min="0" step="any" placeholder="0.00" oninput="calcTotal()">';
} else {
    print (!empty($cval_other) ? '<span class="dc-amount">'.price($cval_other).'</span>' : '&mdash;');
}
print '</div></div>';

print '<div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="comm_total_display" class="dc-total-input" value="'.dol_escape_htmltag($object->amount??'').'" min="0" step="any" placeholder="0.00" readonly style="font-family:\'DM Mono\',monospace;font-weight:600;">';
    print '<input type="hidden" name="amount" id="comm_amount_hidden" value="'.dol_escape_htmltag($object->amount??'').'">';
} else {
    print (!empty($object->amount) ? '<span class="dc-amount" style="font-size:15px;font-weight:700;">'.price($object->amount).'</span>' : '&mdash;');
}
print '</div></div>';

print '</div></div>';

/* ── OTHER FIELDS ── */
print '<div class="dc-card" id="section_other" style="'.($cat!='other'&&($isCreate||$isEdit)?'display:none;':'').'">';
print '<div class="dc-card-header"><div class="dc-card-header-icon slate"><i class="fa fa-tag"></i></div><span class="dc-card-title">'.$langs->trans('OtherExpenseDetails').'</span></div>';
print '<div class="dc-card-body">';

print '<div class="dc-field"><div class="dc-field-label required">'.$langs->trans('Label').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="other_label" id="other_label" value="'.dol_escape_htmltag($object->other_label??'').'" placeholder="'.$langs->trans('ExpenseLabel').'">';
} else {
    print (!empty($object->other_label) ? '<span class="dc-chip">'.dol_escape_htmltag($object->other_label).'</span>' : '&mdash;');
}
print '</div></div>';

print '<div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label required" style="color:#3c4758;font-weight:700;">'.$langs->trans('Amount').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="amount" id="other_amount" value="'.dol_escape_htmltag($object->amount??'').'" min="0" step="any" placeholder="0.00">';
} else {
    print (!empty($object->amount) ? '<span class="dc-amount" style="font-size:15px;font-weight:700;">'.price($object->amount).'</span>' : '&mdash;');
}
print '</div></div>';

print '</div></div>';

/* ── ACTION BAR ── */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/expenses_list.php',1).($fk_booking > 0 ? '?fk_booking='.$fk_booking : '').'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/expenses_list.php',1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
}

print '</div>'; // dc-page

/* ── JAVASCRIPT ── */
?>
<script>
var currentCategory = '<?php echo dol_escape_js($cat); ?>';

function switchCategory(cat) {
    currentCategory = cat;
    var sections = ['fuel','road','driver','commission','other'];
    sections.forEach(function(s) {
        var el = document.getElementById('section_' + s);
        if (el) el.style.display = (s === cat) ? '' : 'none';
    });
    calcTotal();
}

function v(id) { var el = document.getElementById(id); return el ? (parseFloat(el.value) || 0) : 0; }
function setDisplay(id, val) { var el = document.getElementById(id); if (el) { el.value = val > 0 ? val.toFixed(2) : ''; } }
function setHidden(id, val) { var el = document.getElementById(id); if (el) { el.value = val > 0 ? val.toFixed(2) : ''; } }

function calcTotal() {
    var total = 0;
    if (currentCategory === 'fuel') {
        var qty = v('fuel_qty'), price = v('fuel_price');
        total = (qty > 0 && price > 0) ? Math.round(qty * price * 100) / 100 : 0;
        setDisplay('amount_display', total);
        setHidden('amount_hidden', total);
    } else if (currentCategory === 'road') {
        total = Math.round((v('road_toll') + v('road_parking') + v('road_other')) * 100) / 100;
        setDisplay('road_total_display', total);
        setHidden('road_amount_hidden', total);
    } else if (currentCategory === 'driver') {
        total = Math.round((v('driver_salary') + v('driver_overnight') + v('driver_bonus')) * 100) / 100;
        setDisplay('driver_total_display', total);
        setHidden('driver_amount_hidden', total);
    } else if (currentCategory === 'commission') {
        var agent = v('commission_agent'), rate = v('commission_tax'), other = v('commission_other');
        var taxAmt = Math.round(agent * rate / 100 * 100) / 100;
        total = Math.round((agent + taxAmt + other) * 100) / 100;
        var taxEl = document.getElementById('comm_tax_amount');
        if (taxEl) taxEl.textContent = taxAmt.toFixed(2);
        setDisplay('comm_total_display', total);
        setHidden('comm_amount_hidden', total);
    }
}

document.addEventListener('DOMContentLoaded', function() { calcTotal(); });
</script>
<?php

llxFooter();
$db->close();
?>
