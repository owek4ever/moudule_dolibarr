<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteBooking extends CommonObject {
    public $element = 'flotte_booking';
    public $table_element = 'flotte_booking';
    public $picto = 'fa-calendar';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'fk_driver' => array('type'=>'integer', 'label'=>'fk_driver', 'enabled'=>1, 'position'=>11),
        'fk_vehicle' => array('type'=>'integer', 'label'=>'fk_vehicle', 'enabled'=>1, 'position'=>21),
        'date_start' => array('type'=>'date', 'label'=>'date_start', 'enabled'=>1, 'position'=>31),
        'date_end' => array('type'=>'date', 'label'=>'date_end', 'enabled'=>1, 'position'=>41),
        'distance' => array('type'=>'integer', 'label'=>'distance', 'enabled'=>1, 'position'=>51),
        'arriving_address' => array('type'=>'string', 'label'=>'arriving_address', 'enabled'=>1, 'position'=>61),
        'departure_address' => array('type'=>'string', 'label'=>'departure_address', 'enabled'=>1, 'position'=>71),
        'buying_amount' => array('type'=>'double', 'label'=>'buying_amount', 'enabled'=>1, 'position'=>81),
        'selling_amount' => array('type'=>'double', 'label'=>'selling_amount', 'enabled'=>1, 'position'=>91),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
