<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteFuel extends CommonObject {
    public $element = 'flotte_fuel';
    public $table_element = 'flotte_fuel';
    public $picto = 'fa-gas-pump';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'fk_vehicle' => array('type'=>'integer', 'label'=>'fk_vehicle', 'enabled'=>1, 'position'=>11),
        'date' => array('type'=>'date', 'label'=>'date', 'enabled'=>1, 'position'=>21),
        'start_meter' => array('type'=>'integer', 'label'=>'start_meter', 'enabled'=>1, 'position'=>31),
        'reference' => array('type'=>'string', 'label'=>'reference', 'enabled'=>1, 'position'=>41),
        'state' => array('type'=>'string', 'label'=>'state', 'enabled'=>1, 'position'=>51),
        'note' => array('type'=>'string', 'label'=>'note', 'enabled'=>1, 'position'=>61),
        'complete_fillup' => array('type'=>'integer', 'label'=>'complete_fillup', 'enabled'=>1, 'position'=>71),
        'fuel_source' => array('type'=>'string', 'label'=>'fuel_source', 'enabled'=>1, 'position'=>81),
        'qty' => array('type'=>'double', 'label'=>'qty', 'enabled'=>1, 'position'=>91),
        'cost_per_unit' => array('type'=>'double', 'label'=>'cost_per_unit', 'enabled'=>1, 'position'=>101),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
