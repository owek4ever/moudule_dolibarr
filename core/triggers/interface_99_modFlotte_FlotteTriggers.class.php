<?php
/* ─────────────────────────────────────────────────────────────
   interface_99_modFlotte_FlotteTriggers.class.php
   Dolibarr trigger for the Flotte module.

   Listens for invoice validation events and marks the related
   fleet bookings as invoiced.

   Deploy to:
   /htdocs/custom/flotte/core/triggers/interface_99_modFlotte_FlotteTriggers.class.php
─────────────────────────────────────────────────────────────── */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceFlortteTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db          = $db;
        $this->name        = preg_replace('/^Interface/i', '', __CLASS__);
        $this->family      = 'flotte';
        $this->description = 'Marks fleet bookings as invoiced when a Dolibarr invoice is validated.';
        $this->version     = '1.0';
        $this->picto       = 'truck';
    }

    /**
     * Run trigger on a Dolibarr event.
     *
     * @param string        $action  Event code
     * @param CommonObject  $object  The object the event fired on
     * @param User          $user    Current user
     * @param Translate     $langs   Languages
     * @param Conf          $conf    Configuration
     * @return int  0 = nothing done, 1 = ok, < 0 = error
     */
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        // ── Customer invoice validated ───────────────────────
        if ($action === 'BILL_VALIDATE') {
            $note = isset($object->note_private) ? $object->note_private : '';
            if (preg_match('/FLOTTE_BOOKING_IDS:([\d,]+)/', $note, $m)) {
                $this->_markBookings($m[1], 'invoiced_customer');
            }
            return 1;
        }

        // ── Supplier / vendor invoice validated ─────────────
        if ($action === 'BILL_SUPPLIER_VALIDATE') {
            $note = isset($object->note_private) ? $object->note_private : '';
            if (preg_match('/FLOTTE_VENDOR_BOOKING_IDS:([\d,]+)/', $note, $m)) {
                $this->_markBookings($m[1], 'invoiced_vendor');
            }
            return 1;
        }

        return 0;
    }

    /**
     * Set the given flag column to 1 for the supplied booking IDs.
     *
     * @param string $ids_raw   Comma-separated booking rowids
     * @param string $column    Column name: 'invoiced_customer' or 'invoiced_vendor'
     */
    private function _markBookings($ids_raw, $column)
    {
        // Sanitize: keep only integers
        $ids = array();
        foreach (explode(',', $ids_raw) as $id) {
            $id = (int) trim($id);
            if ($id > 0) $ids[] = $id;
        }
        if (empty($ids)) return;

        // Ensure column exists (safe no-op if already present)
        $this->db->query(
            "ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking
             ADD COLUMN IF NOT EXISTS invoiced_customer TINYINT(1) NOT NULL DEFAULT 0"
        );
        $this->db->query(
            "ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking
             ADD COLUMN IF NOT EXISTS invoiced_vendor TINYINT(1) NOT NULL DEFAULT 0"
        );

        $col     = ($column === 'invoiced_vendor') ? 'invoiced_vendor' : 'invoiced_customer';
        $ids_str = implode(',', $ids);

        $this->db->query(
            "UPDATE ".MAIN_DB_PREFIX."flotte_booking
             SET ".$col." = 1
             WHERE rowid IN (".$ids_str.")"
        );
    }
}
