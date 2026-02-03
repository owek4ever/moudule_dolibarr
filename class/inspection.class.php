<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteInspection extends CommonObject {
    public $element = 'flotte_inspection';
    public $table_element = 'flotte_inspection';
    public $picto = 'fa-search';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'fk_vehicle' => array('type'=>'integer', 'label'=>'fk_vehicle', 'enabled'=>1, 'position'=>11),
        'registration_number' => array('type'=>'string', 'label'=>'registration_number', 'enabled'=>1, 'position'=>21),
        'meter_out' => array('type'=>'integer', 'label'=>'meter_out', 'enabled'=>1, 'position'=>31),
        'meter_in' => array('type'=>'integer', 'label'=>'meter_in', 'enabled'=>1, 'position'=>41),
        'fuel_out' => array('type'=>'string', 'label'=>'fuel_out', 'enabled'=>1, 'position'=>51),
        'fuel_in' => array('type'=>'string', 'label'=>'fuel_in', 'enabled'=>1, 'position'=>61),
        'date_out' => array('type'=>'date', 'label'=>'date_out', 'enabled'=>1, 'position'=>71),
        'date_in' => array('type'=>'date', 'label'=>'date_in', 'enabled'=>1, 'position'=>81),
        'petrol_card' => array('type'=>'integer', 'label'=>'petrol_card', 'enabled'=>1, 'position'=>91),
        'lights' => array('type'=>'integer', 'label'=>'lights', 'enabled'=>1, 'position'=>101),
        'inverter' => array('type'=>'integer', 'label'=>'inverter', 'enabled'=>1, 'position'=>111),
        'car_mats' => array('type'=>'integer', 'label'=>'car_mats', 'enabled'=>1, 'position'=>121),
        'interior_damage' => array('type'=>'integer', 'label'=>'interior_damage', 'enabled'=>1, 'position'=>131),
        'interior_lights' => array('type'=>'integer', 'label'=>'interior_lights', 'enabled'=>1, 'position'=>141),
        'exterior_damage' => array('type'=>'integer', 'label'=>'exterior_damage', 'enabled'=>1, 'position'=>151),
        'tyres' => array('type'=>'integer', 'label'=>'tyres', 'enabled'=>1, 'position'=>161),
        'ladders' => array('type'=>'integer', 'label'=>'ladders', 'enabled'=>1, 'position'=>171),
        'extension_leads' => array('type'=>'integer', 'label'=>'extension_leads', 'enabled'=>1, 'position'=>181),
        'power_tools' => array('type'=>'integer', 'label'=>'power_tools', 'enabled'=>1, 'position'=>191),
        'ac' => array('type'=>'integer', 'label'=>'ac', 'enabled'=>1, 'position'=>201),
        'headlights' => array('type'=>'integer', 'label'=>'headlights', 'enabled'=>1, 'position'=>211),
        'locks' => array('type'=>'integer', 'label'=>'locks', 'enabled'=>1, 'position'=>221),
        'windows' => array('type'=>'integer', 'label'=>'windows', 'enabled'=>1, 'position'=>231),
        'seats' => array('type'=>'integer', 'label'=>'seats', 'enabled'=>1, 'position'=>241),
        'oil_check' => array('type'=>'integer', 'label'=>'oil_check', 'enabled'=>1, 'position'=>251),
        'suspension' => array('type'=>'integer', 'label'=>'suspension', 'enabled'=>1, 'position'=>261),
        'tool_boxes' => array('type'=>'integer', 'label'=>'tool_boxes', 'enabled'=>1, 'position'=>271),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
