<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteWorkOrder extends CommonObject {
    public $element = 'flotte_workorder';
    public $table_element = 'flotte_workorder';
    public $picto = 'fa-clipboard';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'fk_vehicle' => array('type'=>'integer', 'label'=>'fk_vehicle', 'enabled'=>1, 'position'=>11),
        'required_by' => array('type'=>'date', 'label'=>'required_by', 'enabled'=>1, 'position'=>21),
        'reading' => array('type'=>'integer', 'label'=>'reading', 'enabled'=>1, 'position'=>31),
        'note' => array('type'=>'string', 'label'=>'note', 'enabled'=>1, 'position'=>41),
        'fk_vendor' => array('type'=>'integer', 'label'=>'fk_vendor', 'enabled'=>1, 'position'=>51),
        'status' => array('type'=>'string', 'label'=>'status', 'enabled'=>1, 'position'=>61),
        'price' => array('type'=>'double', 'label'=>'price', 'enabled'=>1, 'position'=>71),
        'description' => array('type'=>'string', 'label'=>'description', 'enabled'=>1, 'position'=>81),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
