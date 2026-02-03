<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteVendor extends CommonObject {
    public $element = 'flotte_vendor';
    public $table_element = 'flotte_vendor';
    public $picto = 'fa-industry';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'picture' => array('type'=>'string', 'label'=>'picture', 'enabled'=>1, 'position'=>11),
        'name' => array('type'=>'string', 'label'=>'name', 'enabled'=>1, 'position'=>21),
        'phone' => array('type'=>'string', 'label'=>'phone', 'enabled'=>1, 'position'=>31),
        'email' => array('type'=>'string', 'label'=>'email', 'enabled'=>1, 'position'=>41),
        'type' => array('type'=>'string', 'label'=>'type', 'enabled'=>1, 'position'=>51),
        'website' => array('type'=>'string', 'label'=>'website', 'enabled'=>1, 'position'=>61),
        'note' => array('type'=>'string', 'label'=>'note', 'enabled'=>1, 'position'=>71),
        'address1' => array('type'=>'string', 'label'=>'address1', 'enabled'=>1, 'position'=>81),
        'address2' => array('type'=>'string', 'label'=>'address2', 'enabled'=>1, 'position'=>91),
        'city' => array('type'=>'string', 'label'=>'city', 'enabled'=>1, 'position'=>101),
        'state' => array('type'=>'string', 'label'=>'state', 'enabled'=>1, 'position'=>111),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
