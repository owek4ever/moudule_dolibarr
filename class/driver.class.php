<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteDriver extends CommonObject {
    public $element = 'flotte_driver';
    public $table_element = 'flotte_driver';
    public $picto = 'fa-user';

    public $fields = array(
        'ref' => array('type'=>'string', 'label'=>'ref', 'enabled'=>1, 'position'=>1),
        'firstname' => array('type'=>'string', 'label'=>'firstname', 'enabled'=>1, 'position'=>11),
        'middlename' => array('type'=>'string', 'label'=>'middlename', 'enabled'=>1, 'position'=>21),
        'lastname' => array('type'=>'string', 'label'=>'lastname', 'enabled'=>1, 'position'=>31),
        'address' => array('type'=>'string', 'label'=>'address', 'enabled'=>1, 'position'=>41),
        'email' => array('type'=>'string', 'label'=>'email', 'enabled'=>1, 'position'=>51),
        'phone' => array('type'=>'string', 'label'=>'phone', 'enabled'=>1, 'position'=>61),
        'employee_id' => array('type'=>'string', 'label'=>'employee_id', 'enabled'=>1, 'position'=>71),
        'contract_number' => array('type'=>'string', 'label'=>'contract_number', 'enabled'=>1, 'position'=>81),
        'license_number' => array('type'=>'string', 'label'=>'license_number', 'enabled'=>1, 'position'=>91),
        'issue_date' => array('type'=>'date', 'label'=>'issue_date', 'enabled'=>1, 'position'=>101),
        'expiration_date' => array('type'=>'date', 'label'=>'expiration_date', 'enabled'=>1, 'position'=>111),
        'join_date' => array('type'=>'date', 'label'=>'join_date', 'enabled'=>1, 'position'=>121),
        'leave_date' => array('type'=>'date', 'label'=>'leave_date', 'enabled'=>1, 'position'=>131),
        'password' => array('type'=>'string', 'label'=>'password', 'enabled'=>1, 'position'=>141),
        'department' => array('type'=>'string', 'label'=>'department', 'enabled'=>1, 'position'=>151),
        'status' => array('type'=>'string', 'label'=>'status', 'enabled'=>1, 'position'=>161),
        'gender' => array('type'=>'string', 'label'=>'gender', 'enabled'=>1, 'position'=>171),
        'driver_image' => array('type'=>'string', 'label'=>'driver_image', 'enabled'=>1, 'position'=>181),
        'documents' => array('type'=>'string', 'label'=>'documents', 'enabled'=>1, 'position'=>191),
        'license_image' => array('type'=>'string', 'label'=>'license_image', 'enabled'=>1, 'position'=>201),
        'fk_vehicle' => array('type'=>'integer', 'label'=>'fk_vehicle', 'enabled'=>1, 'position'=>211),
        'emergency_contact' => array('type'=>'string', 'label'=>'emergency_contact', 'enabled'=>1, 'position'=>221),
    );

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;
}
?>
