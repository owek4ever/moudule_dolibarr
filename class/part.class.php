<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlottePart extends CommonObject {
    public $element = 'flotte_part';
    public $table_element = 'flotte_part';
    public $picto = 'fa-cogs';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'picture' => array('type'=>'string', 'label'=>'picture', 'enabled'=>1, 'position'=>11),
        'barcode' => array('type'=>'string', 'label'=>'barcode', 'enabled'=>1, 'position'=>21),
        'title' => array('type'=>'string', 'label'=>'title', 'enabled'=>1, 'position'=>31),
        'number' => array('type'=>'string', 'label'=>'number', 'enabled'=>1, 'position'=>41),
        'description' => array('type'=>'string', 'label'=>'description', 'enabled'=>1, 'position'=>51),
        'status' => array('type'=>'string', 'label'=>'status', 'enabled'=>1, 'position'=>61),
        'availability' => array('type'=>'string', 'label'=>'availability', 'enabled'=>1, 'position'=>71),
        'fk_vendor' => array('type'=>'integer', 'label'=>'fk_vendor', 'enabled'=>1, 'position'=>81),
        'fk_category' => array('type'=>'integer', 'label'=>'fk_category', 'enabled'=>1, 'position'=>91),
        'manufacturer' => array('type'=>'string', 'label'=>'manufacturer', 'enabled'=>1, 'position'=>101),
        'year' => array('type'=>'integer', 'label'=>'year', 'enabled'=>1, 'position'=>111),
        'model' => array('type'=>'string', 'label'=>'model', 'enabled'=>1, 'position'=>121),
        'qty_on_hand' => array('type'=>'integer', 'label'=>'qty_on_hand', 'enabled'=>1, 'position'=>131),
        'unit_cost' => array('type'=>'double', 'label'=>'unit_cost', 'enabled'=>1, 'position'=>141),
        'note' => array('type'=>'string', 'label'=>'note', 'enabled'=>1, 'position'=>151),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
