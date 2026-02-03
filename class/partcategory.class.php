<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlottePartCategory extends CommonObject {
    public $element = 'flotte_partcategory';
    public $table_element = 'flotte_partcategory';
    public $picto = 'fa-tags';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'category_name' => array('type'=>'string', 'label'=>'category_name', 'enabled'=>1, 'position'=>11),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
