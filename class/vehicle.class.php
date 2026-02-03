<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteVehicle extends CommonObject {
    public $element = 'flotte_vehicle';
    public $table_element = 'flotte_vehicle';
    public $picto = 'fa-car';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'maker' => array('type'=>'string', 'label'=>'maker', 'enabled'=>1, 'position'=>11),
        'model' => array('type'=>'string', 'label'=>'model', 'enabled'=>1, 'position'=>21),
        'type' => array('type'=>'string', 'label'=>'type', 'enabled'=>1, 'position'=>31),
        'year' => array('type'=>'integer', 'label'=>'year', 'enabled'=>1, 'position'=>41),
        'initial_mileage' => array('type'=>'integer', 'label'=>'initial_mileage', 'enabled'=>1, 'position'=>51),
        'vehicle_image' => array('type'=>'string', 'label'=>'vehicle_image', 'enabled'=>1, 'position'=>61),
        'registration_expiry' => array('type'=>'date', 'label'=>'registration_expiry', 'enabled'=>1, 'position'=>71),
        'in_service' => array('type'=>'integer', 'label'=>'in_service', 'enabled'=>1, 'position'=>81),
        'department' => array('type'=>'string', 'label'=>'department', 'enabled'=>1, 'position'=>91),
        'engine_type' => array('type'=>'string', 'label'=>'engine_type', 'enabled'=>1, 'position'=>101),
        'horse_power' => array('type'=>'integer', 'label'=>'horse_power', 'enabled'=>1, 'position'=>111),
        'color' => array('type'=>'string', 'label'=>'color', 'enabled'=>1, 'position'=>121),
        'vin' => array('type'=>'string', 'label'=>'vin', 'enabled'=>1, 'position'=>131),
        'license_plate' => array('type'=>'string', 'label'=>'license_plate', 'enabled'=>1, 'position'=>141),
        'license_expiry' => array('type'=>'date', 'label'=>'license_expiry', 'enabled'=>1, 'position'=>151),
        'fk_group' => array('type'=>'integer', 'label'=>'fk_group', 'enabled'=>1, 'position'=>161),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
