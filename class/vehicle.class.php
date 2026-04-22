<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteVehicle extends CommonObject
{
    public $element       = 'flotte_vehicle';
    public $table_element = 'flotte_vehicle';
    public $picto         = 'fa-car';

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;

    // ── Public properties — one per actual DB column ─────────────────────────
    public $ref;
    public $maker;
    public $model;
    public $type;
    public $year;
    public $initial_mileage;
    public $vehicle_photo;
    public $registration_card;
    public $platform_registration_card;
    public $insurance_document;
    public $registration_expiry;
    public $license_expiry;
    public $insurance_expiry;
    public $in_service;
    public $department;
    public $engine_type;
    public $horsepower;          // column is "horsepower" (not "horse_power")
    public $color;
    public $vin;
    public $license_plate;
    public $length_cm;
    public $width_cm;
    public $height_cm;
    public $max_weight_kg;
    public $ground_height_cm;
    public $fk_group;
    public $fk_user_author;

    public $fields = array(
        'ref'                        => array('type' => 'string',  'label' => 'ref',                        'enabled' => 1, 'position' => 10),
        'maker'                      => array('type' => 'string',  'label' => 'maker',                      'enabled' => 1, 'position' => 20),
        'model'                      => array('type' => 'string',  'label' => 'model',                      'enabled' => 1, 'position' => 30),
        'type'                       => array('type' => 'string',  'label' => 'type',                       'enabled' => 1, 'position' => 40),
        'year'                       => array('type' => 'integer', 'label' => 'year',                       'enabled' => 1, 'position' => 50),
        'initial_mileage'            => array('type' => 'integer', 'label' => 'initial_mileage',            'enabled' => 1, 'position' => 60),
        'vehicle_photo'              => array('type' => 'string',  'label' => 'vehicle_photo',              'enabled' => 1, 'position' => 70),
        'registration_card'          => array('type' => 'string',  'label' => 'registration_card',          'enabled' => 1, 'position' => 80),
        'platform_registration_card' => array('type' => 'string',  'label' => 'platform_registration_card', 'enabled' => 1, 'position' => 90),
        'insurance_document'         => array('type' => 'string',  'label' => 'insurance_document',         'enabled' => 1, 'position' => 100),
        'registration_expiry'        => array('type' => 'date',    'label' => 'registration_expiry',        'enabled' => 1, 'position' => 110),
        'license_expiry'             => array('type' => 'date',    'label' => 'license_expiry',             'enabled' => 1, 'position' => 120),
        'insurance_expiry'           => array('type' => 'date',    'label' => 'insurance_expiry',           'enabled' => 1, 'position' => 130),
        'in_service'                 => array('type' => 'integer', 'label' => 'in_service',                 'enabled' => 1, 'position' => 140),
        'department'                 => array('type' => 'string',  'label' => 'department',                 'enabled' => 1, 'position' => 150),
        'engine_type'                => array('type' => 'string',  'label' => 'engine_type',                'enabled' => 1, 'position' => 160),
        'horsepower'                 => array('type' => 'integer', 'label' => 'horsepower',                 'enabled' => 1, 'position' => 170),
        'color'                      => array('type' => 'string',  'label' => 'color',                      'enabled' => 1, 'position' => 180),
        'vin'                        => array('type' => 'string',  'label' => 'vin',                        'enabled' => 1, 'position' => 190),
        'license_plate'              => array('type' => 'string',  'label' => 'license_plate',              'enabled' => 1, 'position' => 200),
        'length_cm'                  => array('type' => 'double',  'label' => 'length_cm',                  'enabled' => 1, 'position' => 210),
        'width_cm'                   => array('type' => 'double',  'label' => 'width_cm',                   'enabled' => 1, 'position' => 220),
        'height_cm'                  => array('type' => 'double',  'label' => 'height_cm',                  'enabled' => 1, 'position' => 230),
        'max_weight_kg'              => array('type' => 'double',  'label' => 'max_weight_kg',              'enabled' => 1, 'position' => 240),
        'ground_height_cm'           => array('type' => 'double',  'label' => 'ground_height_cm',           'enabled' => 1, 'position' => 250),
        'fk_group'                   => array('type' => 'integer', 'label' => 'fk_group',                   'enabled' => 1, 'position' => 260),
        'fk_user_author'             => array('type' => 'integer', 'label' => 'fk_user_author',             'enabled' => 1, 'position' => 270),
    );

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function fetch($id)
    {
        return $this->fetchCommon($id);
    }

    public function create($user)
    {
        return $this->createCommon($user);
    }

    public function update($user)
    {
        return $this->updateCommon($user);
    }

    public function delete($user)
    {
        return $this->deleteCommon($user);
    }
}