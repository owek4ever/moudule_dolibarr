<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteCustomer extends CommonObject {
    public $element = 'flotte_customer';
    public $table_element = 'flotte_customer';
    public $picto = 'fa-user-tie';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'firstname' => array('type'=>'string', 'label'=>'firstname', 'enabled'=>1, 'position'=>11),
        'lastname' => array('type'=>'string', 'label'=>'lastname', 'enabled'=>1, 'position'=>21),
        'phone' => array('type'=>'string', 'label'=>'phone', 'enabled'=>1, 'position'=>31),
        'email' => array('type'=>'string', 'label'=>'email', 'enabled'=>1, 'position'=>41),
        'password' => array('type'=>'string', 'label'=>'password', 'enabled'=>1, 'position'=>51),
        'company_name' => array('type'=>'string', 'label'=>'company_name', 'enabled'=>1, 'position'=>61),
        'tax_no' => array('type'=>'string', 'label'=>'tax_no', 'enabled'=>1, 'position'=>71),
        'payment_delay' => array('type'=>'integer', 'label'=>'payment_delay', 'enabled'=>1, 'position'=>81),
        'gender' => array('type'=>'string', 'label'=>'gender', 'enabled'=>1, 'position'=>91),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
