<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class FlotteBooking extends CommonObject
{
    public $element       = 'flotte_booking';
    public $table_element = 'flotte_booking';
    public $picto         = 'fa-calendar';

    public $isextrafieldmanaged = 1;
    public $ismultientitymanaged = 1;

    // ── Public properties — one per actual DB column ─────────────────────────
    public $ref;
    public $fk_vehicle;
    public $fk_driver;
    public $fk_vendor;
    public $fk_customer;
    public $booking_date;
    public $status;
    public $distance;
    public $arriving_address;
    public $departure_address;
    public $dep_lat;
    public $dep_lon;
    public $arr_lat;
    public $arr_lon;
    public $stops;
    public $eta;
    public $pickup_datetime;
    public $dropoff_datetime;
    public $bl_number;
    // Buying
    public $buying_amount;
    public $buying_tax_rate;
    public $buying_qty;
    public $buying_price;
    public $buying_unit;
    public $buying_amount_ttc;
    // Selling
    public $selling_amount;
    public $selling_tax_rate;
    public $selling_qty;
    public $selling_price;
    public $selling_unit;
    public $selling_amount_ttc;
    // Expenses — fuel
    public $expense_fuel;
    public $expense_fuel_qty;
    public $expense_fuel_price;
    public $expense_fuel_type;
    public $expense_fuel_vendor;
    // Expenses — road
    public $expense_road;
    public $expense_road_toll;
    public $expense_road_parking;
    public $expense_road_other;
    // Expenses — driver
    public $expense_driver;
    public $expense_driver_salary;
    public $expense_driver_overnight;
    public $expense_driver_bonus;
    // Expenses — commission
    public $expense_commission;
    public $expense_commission_agent;
    public $expense_commission_tax;
    public $expense_commission_other;
    // Meta
    public $fk_user_author;

    public $fields = array(
        'ref'                      => array('type' => 'string',   'label' => 'ref',                      'enabled' => 1, 'position' => 10),
        'fk_vehicle'               => array('type' => 'integer',  'label' => 'fk_vehicle',               'enabled' => 1, 'position' => 20),
        'fk_driver'                => array('type' => 'integer',  'label' => 'fk_driver',                'enabled' => 1, 'position' => 30),
        'fk_vendor'                => array('type' => 'integer',  'label' => 'fk_vendor',                'enabled' => 1, 'position' => 40),
        'fk_customer'              => array('type' => 'integer',  'label' => 'fk_customer',              'enabled' => 1, 'position' => 50),
        'booking_date'             => array('type' => 'date',     'label' => 'booking_date',             'enabled' => 1, 'position' => 60),
        'status'                   => array('type' => 'string',   'label' => 'status',                   'enabled' => 1, 'position' => 70),
        'distance'                 => array('type' => 'integer',  'label' => 'distance',                 'enabled' => 1, 'position' => 80),
        'arriving_address'         => array('type' => 'string',   'label' => 'arriving_address',         'enabled' => 1, 'position' => 90),
        'departure_address'        => array('type' => 'string',   'label' => 'departure_address',        'enabled' => 1, 'position' => 100),
        'dep_lat'                  => array('type' => 'string',   'label' => 'dep_lat',                  'enabled' => 1, 'position' => 110),
        'dep_lon'                  => array('type' => 'string',   'label' => 'dep_lon',                  'enabled' => 1, 'position' => 120),
        'arr_lat'                  => array('type' => 'string',   'label' => 'arr_lat',                  'enabled' => 1, 'position' => 130),
        'arr_lon'                  => array('type' => 'string',   'label' => 'arr_lon',                  'enabled' => 1, 'position' => 140),
        'stops'                    => array('type' => 'text',     'label' => 'stops',                    'enabled' => 1, 'position' => 150),
        'eta'                      => array('type' => 'string',   'label' => 'eta',                      'enabled' => 1, 'position' => 160),
        'pickup_datetime'          => array('type' => 'datetime', 'label' => 'pickup_datetime',          'enabled' => 1, 'position' => 170),
        'dropoff_datetime'         => array('type' => 'datetime', 'label' => 'dropoff_datetime',         'enabled' => 1, 'position' => 180),
        'bl_number'                => array('type' => 'string',   'label' => 'bl_number',                'enabled' => 1, 'position' => 190),
        'buying_amount'            => array('type' => 'double',   'label' => 'buying_amount',            'enabled' => 1, 'position' => 200),
        'buying_tax_rate'          => array('type' => 'double',   'label' => 'buying_tax_rate',          'enabled' => 1, 'position' => 210),
        'buying_qty'               => array('type' => 'double',   'label' => 'buying_qty',               'enabled' => 1, 'position' => 220),
        'buying_price'             => array('type' => 'double',   'label' => 'buying_price',             'enabled' => 1, 'position' => 230),
        'buying_unit'              => array('type' => 'string',   'label' => 'buying_unit',              'enabled' => 1, 'position' => 240),
        'buying_amount_ttc'        => array('type' => 'double',   'label' => 'buying_amount_ttc',        'enabled' => 1, 'position' => 250),
        'selling_amount'           => array('type' => 'double',   'label' => 'selling_amount',           'enabled' => 1, 'position' => 260),
        'selling_tax_rate'         => array('type' => 'double',   'label' => 'selling_tax_rate',         'enabled' => 1, 'position' => 270),
        'selling_qty'              => array('type' => 'double',   'label' => 'selling_qty',              'enabled' => 1, 'position' => 280),
        'selling_price'            => array('type' => 'double',   'label' => 'selling_price',            'enabled' => 1, 'position' => 290),
        'selling_unit'             => array('type' => 'string',   'label' => 'selling_unit',             'enabled' => 1, 'position' => 300),
        'selling_amount_ttc'       => array('type' => 'double',   'label' => 'selling_amount_ttc',       'enabled' => 1, 'position' => 310),
        'expense_fuel'             => array('type' => 'double',   'label' => 'expense_fuel',             'enabled' => 1, 'position' => 320),
        'expense_fuel_qty'         => array('type' => 'double',   'label' => 'expense_fuel_qty',         'enabled' => 1, 'position' => 330),
        'expense_fuel_price'       => array('type' => 'double',   'label' => 'expense_fuel_price',       'enabled' => 1, 'position' => 340),
        'expense_fuel_type'        => array('type' => 'string',   'label' => 'expense_fuel_type',        'enabled' => 1, 'position' => 350),
        'expense_fuel_vendor'      => array('type' => 'integer',  'label' => 'expense_fuel_vendor',      'enabled' => 1, 'position' => 360),
        'expense_road'             => array('type' => 'double',   'label' => 'expense_road',             'enabled' => 1, 'position' => 370),
        'expense_road_toll'        => array('type' => 'double',   'label' => 'expense_road_toll',        'enabled' => 1, 'position' => 380),
        'expense_road_parking'     => array('type' => 'double',   'label' => 'expense_road_parking',     'enabled' => 1, 'position' => 390),
        'expense_road_other'       => array('type' => 'double',   'label' => 'expense_road_other',       'enabled' => 1, 'position' => 400),
        'expense_driver'           => array('type' => 'double',   'label' => 'expense_driver',           'enabled' => 1, 'position' => 410),
        'expense_driver_salary'    => array('type' => 'double',   'label' => 'expense_driver_salary',    'enabled' => 1, 'position' => 420),
        'expense_driver_overnight' => array('type' => 'double',   'label' => 'expense_driver_overnight', 'enabled' => 1, 'position' => 430),
        'expense_driver_bonus'     => array('type' => 'double',   'label' => 'expense_driver_bonus',     'enabled' => 1, 'position' => 440),
        'expense_commission'       => array('type' => 'double',   'label' => 'expense_commission',       'enabled' => 1, 'position' => 450),
        'expense_commission_agent' => array('type' => 'double',   'label' => 'expense_commission_agent', 'enabled' => 1, 'position' => 460),
        'expense_commission_tax'   => array('type' => 'double',   'label' => 'expense_commission_tax',   'enabled' => 1, 'position' => 470),
        'expense_commission_other' => array('type' => 'double',   'label' => 'expense_commission_other', 'enabled' => 1, 'position' => 480),
        'fk_user_author'           => array('type' => 'integer',  'label' => 'fk_user_author',           'enabled' => 1, 'position' => 490),
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