<?php
/**
 * Represents a date and time as a value object.
 *
 * @copyright  Copyright (c) 2008-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fTimestamp
 *
 * @version    1.0.0b10
 * @changes    1.0.0b10  Fixed a bug in ::__construct() with specifying a timezone other than the default for a relative time string such as "now" or "+2 hours" [wb, 2010-07-05]
 * @changes    1.0.0b9   Added the `$simple` parameter to ::getFuzzyDifference() [wb, 2010-03-15]
 * @changes    1.0.0b8   Fixed a bug with ::fixISOWeek() not properly parsing some ISO week dates [wb, 2009-10-06]
 * @changes    1.0.0b7   Fixed a translation bug with ::getFuzzyDifference() [wb, 2009-07-11]
 * @changes    1.0.0b6   Added ::registerUnformatCallback() and ::callUnformatCallback() to allow for localization of date/time parsing [wb, 2009-06-01]
 * @changes    1.0.0b5   Backwards compatibility break - Removed ::getSecondsDifference() and ::getSeconds(), added ::eq(), ::gt(), ::gte(), ::lt(), ::lte() [wb, 2009-03-05]
 * @changes    1.0.0b4   Updated for new fCore API [wb, 2009-02-16]
 * @changes    1.0.0b3   Removed a useless double check of the strtotime() return value in ::__construct() [wb, 2009-01-21]
 * @changes    1.0.0b2   Added support for CURRENT_TIMESTAMP, CURRENT_DATE and CURRENT_TIME SQL keywords [wb, 2009-01-11]
 * @changes    1.0.0b    The initial implementation [wb, 2008-02-12]
 */
class fTimestamp
{
    // The following constants allow for nice looking callbacks to static methods
    public const callFormatCallback = 'fTimestamp::callFormatCallback';

    public const callUnformatCallback = 'fTimestamp::callUnformatCallback';

    public const combine = 'fTimestamp::combine';

    public const defineFormat = 'fTimestamp::defineFormat';

    public const fixISOWeek = 'fTimestamp::fixISOWeek';

    public const getDefaultTimezone = 'fTimestamp::getDefaultTimezone';

    public const isValidTimezone = 'fTimestamp::isValidTimezone';

    public const registerFormatCallback = 'fTimestamp::registerFormatCallback';

    public const registerUnformatCallback = 'fTimestamp::registerUnformatCallback';

    public const reset = 'fTimestamp::reset';

    public const setDefaultTimezone = 'fTimestamp::setDefaultTimezone';

    public const translateFormat = 'fTimestamp::translateFormat';

    /**
     * Pre-defined formatting styles.
     *
     * @var array
     */
    private static $formats = [];

    /**
     * A callback to process all formatting strings through.
     *
     * @var callable
     */
    private static $format_callback;

    /**
     * A callback to parse all date string to allow for locale-specific parsing.
     *
     * @var callable
     */
    private static $unformat_callback;

    /**
     * The date/time.
     *
     * @var int
     */
    private $timestamp;

    /**
     * The timezone for this date/time.
     *
     * @var string
     */
    private $timezone;

    /**
     * Creates the date/time to represent.
     *
     * @param fTimestamp|int|object|string $datetime The date/time to represent, `NULL` is interpreted as now
     * @param string                       $timezone The timezone for the date/time. This causes the date/time to be interpretted as being in the specified timezone. If not specified, will default to timezone set by ::setDefaultTimezone().
     *
     * @throws fValidationException When `$datetime` is not a valid date/time, date or time value
     *
     * @return fTimestamp
     */
    public function __construct($datetime = null, $timezone = null)
    {
        self::checkPHPVersion();

        $default_tz = date_default_timezone_get();

        if ($timezone) {
            if (! self::isValidTimezone($timezone)) {
                throw new fValidationException(
                    'The timezone specified, %s, is not a valid timezone',
                    $timezone
                );
            }
        } elseif ($datetime instanceof self) {
            $timezone = $datetime->timezone;
        } else {
            $timezone = $default_tz;
        }

        $this->timezone = $timezone;

        if ($datetime === null) {
            $timestamp = time();
        } elseif (is_numeric($datetime) && ctype_digit((string) $datetime)) {
            $timestamp = (int) $datetime;
        } elseif (is_string($datetime) && in_array(strtoupper($datetime), ['CURRENT_TIMESTAMP', 'CURRENT_TIME'])) {
            $timestamp = time();
        } elseif (is_string($datetime) && strtoupper($datetime) == 'CURRENT_DATE') {
            $timestamp = strtotime(date('Y-m-d'));
        } else {
            if (is_object($datetime) && is_callable([$datetime, '__toString'])) {
                $datetime = $datetime->__toString();
            } elseif (is_numeric($datetime) || is_object($datetime)) {
                $datetime = (string) $datetime;
            }

            $datetime = self::callUnformatCallback($datetime);

            if ($timezone != $default_tz) {
                date_default_timezone_set($timezone);
            }
            $timestamp = strtotime(self::fixISOWeek($datetime));
            if ($timezone != $default_tz) {
                date_default_timezone_set($default_tz);
            }
        }

        if ($timestamp === false) {
            throw new fValidationException(
                'The date/time specified, %s, does not appear to be a valid date/time',
                $datetime
            );
        }

        $this->timestamp = $timestamp;
    }

    /**
     * All requests that hit this method should be requests for callbacks.
     *
     * @internal
     *
     * @param string $method The method to create a callback for
     *
     * @return callable The callback for the method requested
     */
    public function __get($method)
    {
        return [$this, $method];
    }

    /**
     * Returns this date/time.
     *
     * @return string The `'Y-m-d H:i:s'` format of this date/time
     */
    public function __toString()
    {
        return $this->format('Y-m-d H:i:s');
    }

    /**
     * If a format callback is defined, call it.
     *
     * @internal
     *
     * @param string $formatted_string The formatted date/time/timestamp string to be (possibly) modified
     *
     * @return string The (possibly) modified formatted string
     */
    public static function callFormatCallback($formatted_string)
    {
        if (self::$format_callback) {
            return call_user_func(self::$format_callback, $formatted_string);
        }

        return $formatted_string;
    }

    /**
     * If an unformat callback is defined, call it.
     *
     * @internal
     *
     * @param string $date_time_string A raw date/time/timestamp string to be (possibly) parsed/modified
     *
     * @return string The (possibly) parsed or modified date/time/timestamp
     */
    public static function callUnformatCallback($date_time_string)
    {
        if (self::$unformat_callback) {
            return call_user_func(self::$unformat_callback, $date_time_string);
        }

        return $date_time_string;
    }

    /**
     * Creates a reusable format for formatting fDate, fTime, and fTimestamp objects.
     *
     * @param string $name              The name of the format
     * @param string $formatting_string The format string compatible with the [http://php.net/date date()] function
     */
    public static function defineFormat($name, $formatting_string): void
    {
        self::$formats[$name] = $formatting_string;
    }

    /**
     * Fixes an ISO week format into `'Y-m-d'` so [http://php.net/strtotime strtotime()] will accept it.
     *
     * @internal
     *
     * @param string $date The date to fix
     *
     * @return string The fixed date
     */
    public static function fixISOWeek($date)
    {
        if (preg_match('#^(.*)(\d{4})-W(5[0-3]|[1-4][0-9]|0?[1-9])-([1-7])(.*)$#D', $date, $matches)) {
            $before = $matches[1];
            $year = $matches[2];
            $week = $matches[3];
            $day = $matches[4];
            $after = $matches[5];

            $first_of_year = strtotime($year.'-01-01');
            $first_thursday = strtotime('thursday', $first_of_year);
            $iso_year_start = strtotime('last monday', $first_thursday);

            $ymd = date('Y-m-d', strtotime('+'.($week - 1).' weeks +'.($day - 1).' days', $iso_year_start));

            $date = $before.$ymd.$after;
        }

        return $date;
    }

    /**
     * Provides a consistent interface to getting the default timezone. Wraps the [http://php.net/date_default_timezone_get date_default_timezone_get()] function.
     *
     * @return string The default timezone used for all date/time calculations
     */
    public static function getDefaultTimezone()
    {
        self::checkPHPVersion();

        return date_default_timezone_get();
    }

    /**
     * Checks to see if a timezone is valid.
     *
     * @internal
     *
     * @param string $timezone The timezone to check
     *
     * @return bool If the timezone is valid
     */
    public static function isValidTimezone($timezone)
    {
        static $valid_timezones = [
            'UTC' => true,
            'Africa/Abidjan' => true,
            'Africa/Accra' => true,
            'Africa/Addis_Ababa' => true,
            'Africa/Algiers' => true,
            'Africa/Asmara' => true,
            'Africa/Asmera' => true,
            'Africa/Bamako' => true,
            'Africa/Bangui' => true,
            'Africa/Banjul' => true,
            'Africa/Bissau' => true,
            'Africa/Blantyre' => true,
            'Africa/Brazzaville' => true,
            'Africa/Bujumbura' => true,
            'Africa/Cairo' => true,
            'Africa/Casablanca' => true,
            'Africa/Ceuta' => true,
            'Africa/Conakry' => true,
            'Africa/Dakar' => true,
            'Africa/Dar_es_Salaam' => true,
            'Africa/Djibouti' => true,
            'Africa/Douala' => true,
            'Africa/El_Aaiun' => true,
            'Africa/Freetown' => true,
            'Africa/Gaborone' => true,
            'Africa/Harare' => true,
            'Africa/Johannesburg' => true,
            'Africa/Kampala' => true,
            'Africa/Khartoum' => true,
            'Africa/Kigali' => true,
            'Africa/Kinshasa' => true,
            'Africa/Lagos' => true,
            'Africa/Libreville' => true,
            'Africa/Lome' => true,
            'Africa/Luanda' => true,
            'Africa/Lubumbashi' => true,
            'Africa/Lusaka' => true,
            'Africa/Malabo' => true,
            'Africa/Maputo' => true,
            'Africa/Maseru' => true,
            'Africa/Mbabane' => true,
            'Africa/Mogadishu' => true,
            'Africa/Monrovia' => true,
            'Africa/Nairobi' => true,
            'Africa/Ndjamena' => true,
            'Africa/Niamey' => true,
            'Africa/Nouakchott' => true,
            'Africa/Ouagadougou' => true,
            'Africa/Porto-Novo' => true,
            'Africa/Sao_Tome' => true,
            'Africa/Timbuktu' => true,
            'Africa/Tripoli' => true,
            'Africa/Tunis' => true,
            'Africa/Windhoek' => true,
            'America/Adak' => true,
            'America/Anchorage' => true,
            'America/Anguilla' => true,
            'America/Antigua' => true,
            'America/Araguaina' => true,
            'America/Argentina/Buenos_Aires' => true,
            'America/Argentina/Catamarca' => true,
            'America/Argentina/ComodRivadavia' => true,
            'America/Argentina/Cordoba' => true,
            'America/Argentina/Jujuy' => true,
            'America/Argentina/La_Rioja' => true,
            'America/Argentina/Mendoza' => true,
            'America/Argentina/Rio_Gallegos' => true,
            'America/Argentina/San_Juan' => true,
            'America/Argentina/San_Luis' => true,
            'America/Argentina/Tucuman' => true,
            'America/Argentina/Ushuaia' => true,
            'America/Aruba' => true,
            'America/Asuncion' => true,
            'America/Atikokan' => true,
            'America/Atka' => true,
            'America/Bahia' => true,
            'America/Barbados' => true,
            'America/Belem' => true,
            'America/Belize' => true,
            'America/Blanc-Sablon' => true,
            'America/Boa_Vista' => true,
            'America/Bogota' => true,
            'America/Boise' => true,
            'America/Buenos_Aires' => true,
            'America/Cambridge_Bay' => true,
            'America/Campo_Grande' => true,
            'America/Cancun' => true,
            'America/Caracas' => true,
            'America/Catamarca' => true,
            'America/Cayenne' => true,
            'America/Cayman' => true,
            'America/Chicago' => true,
            'America/Chihuahua' => true,
            'America/Coral_Harbour' => true,
            'America/Cordoba' => true,
            'America/Costa_Rica' => true,
            'America/Cuiaba' => true,
            'America/Curacao' => true,
            'America/Danmarkshavn' => true,
            'America/Dawson' => true,
            'America/Dawson_Creek' => true,
            'America/Denver' => true,
            'America/Detroit' => true,
            'America/Dominica' => true,
            'America/Edmonton' => true,
            'America/Eirunepe' => true,
            'America/El_Salvador' => true,
            'America/Ensenada' => true,
            'America/Fort_Wayne' => true,
            'America/Fortaleza' => true,
            'America/Glace_Bay' => true,
            'America/Godthab' => true,
            'America/Goose_Bay' => true,
            'America/Grand_Turk' => true,
            'America/Grenada' => true,
            'America/Guadeloupe' => true,
            'America/Guatemala' => true,
            'America/Guayaquil' => true,
            'America/Guyana' => true,
            'America/Halifax' => true,
            'America/Havana' => true,
            'America/Hermosillo' => true,
            'America/Indiana/Indianapolis' => true,
            'America/Indiana/Knox' => true,
            'America/Indiana/Marengo' => true,
            'America/Indiana/Petersburg' => true,
            'America/Indiana/Tell_City' => true,
            'America/Indiana/Vevay' => true,
            'America/Indiana/Vincennes' => true,
            'America/Indiana/Winamac' => true,
            'America/Indianapolis' => true,
            'America/Inuvik' => true,
            'America/Iqaluit' => true,
            'America/Jamaica' => true,
            'America/Jujuy' => true,
            'America/Juneau' => true,
            'America/Kentucky/Louisville' => true,
            'America/Kentucky/Monticello' => true,
            'America/Knox_IN' => true,
            'America/La_Paz' => true,
            'America/Lima' => true,
            'America/Los_Angeles' => true,
            'America/Louisville' => true,
            'America/Maceio' => true,
            'America/Managua' => true,
            'America/Manaus' => true,
            'America/Marigot' => true,
            'America/Martinique' => true,
            'America/Mazatlan' => true,
            'America/Mendoza' => true,
            'America/Menominee' => true,
            'America/Merida' => true,
            'America/Mexico_City' => true,
            'America/Miquelon' => true,
            'America/Moncton' => true,
            'America/Monterrey' => true,
            'America/Montevideo' => true,
            'America/Montreal' => true,
            'America/Montserrat' => true,
            'America/Nassau' => true,
            'America/New_York' => true,
            'America/Nipigon' => true,
            'America/Nome' => true,
            'America/Noronha' => true,
            'America/North_Dakota/Center' => true,
            'America/North_Dakota/New_Salem' => true,
            'America/Panama' => true,
            'America/Pangnirtung' => true,
            'America/Paramaribo' => true,
            'America/Phoenix' => true,
            'America/Port-au-Prince' => true,
            'America/Port_of_Spain' => true,
            'America/Porto_Acre' => true,
            'America/Porto_Velho' => true,
            'America/Puerto_Rico' => true,
            'America/Rainy_River' => true,
            'America/Rankin_Inlet' => true,
            'America/Recife' => true,
            'America/Regina' => true,
            'America/Resolute' => true,
            'America/Rio_Branco' => true,
            'America/Rosario' => true,
            'America/Santiago' => true,
            'America/Santo_Domingo' => true,
            'America/Sao_Paulo' => true,
            'America/Scoresbysund' => true,
            'America/Shiprock' => true,
            'America/St_Barthelemy' => true,
            'America/St_Johns' => true,
            'America/St_Kitts' => true,
            'America/St_Lucia' => true,
            'America/St_Thomas' => true,
            'America/St_Vincent' => true,
            'America/Swift_Current' => true,
            'America/Tegucigalpa' => true,
            'America/Thule' => true,
            'America/Thunder_Bay' => true,
            'America/Tijuana' => true,
            'America/Toronto' => true,
            'America/Tortola' => true,
            'America/Vancouver' => true,
            'America/Virgin' => true,
            'America/Whitehorse' => true,
            'America/Winnipeg' => true,
            'America/Yakutat' => true,
            'America/Yellowknife' => true,
            'Antarctica/Casey' => true,
            'Antarctica/Davis' => true,
            'Antarctica/DumontDUrville' => true,
            'Antarctica/Mawson' => true,
            'Antarctica/McMurdo' => true,
            'Antarctica/Palmer' => true,
            'Antarctica/Rothera' => true,
            'Antarctica/South_Pole' => true,
            'Antarctica/Syowa' => true,
            'Antarctica/Vostok' => true,
            'Arctic/Longyearbyen' => true,
            'Asia/Aden' => true,
            'Asia/Almaty' => true,
            'Asia/Amman' => true,
            'Asia/Anadyr' => true,
            'Asia/Aqtau' => true,
            'Asia/Aqtobe' => true,
            'Asia/Ashgabat' => true,
            'Asia/Ashkhabad' => true,
            'Asia/Baghdad' => true,
            'Asia/Bahrain' => true,
            'Asia/Baku' => true,
            'Asia/Bangkok' => true,
            'Asia/Beirut' => true,
            'Asia/Bishkek' => true,
            'Asia/Brunei' => true,
            'Asia/Calcutta' => true,
            'Asia/Choibalsan' => true,
            'Asia/Chongqing' => true,
            'Asia/Chungking' => true,
            'Asia/Colombo' => true,
            'Asia/Dacca' => true,
            'Asia/Damascus' => true,
            'Asia/Dhaka' => true,
            'Asia/Dili' => true,
            'Asia/Dubai' => true,
            'Asia/Dushanbe' => true,
            'Asia/Gaza' => true,
            'Asia/Harbin' => true,
            'Asia/Ho_Chi_Minh' => true,
            'Asia/Hong_Kong' => true,
            'Asia/Hovd' => true,
            'Asia/Irkutsk' => true,
            'Asia/Istanbul' => true,
            'Asia/Jakarta' => true,
            'Asia/Jayapura' => true,
            'Asia/Jerusalem' => true,
            'Asia/Kabul' => true,
            'Asia/Kamchatka' => true,
            'Asia/Karachi' => true,
            'Asia/Kashgar' => true,
            'Asia/Katmandu' => true,
            'Asia/Kolkata' => true,
            'Asia/Krasnoyarsk' => true,
            'Asia/Kuala_Lumpur' => true,
            'Asia/Kuching' => true,
            'Asia/Kuwait' => true,
            'Asia/Macao' => true,
            'Asia/Macau' => true,
            'Asia/Magadan' => true,
            'Asia/Makassar' => true,
            'Asia/Manila' => true,
            'Asia/Muscat' => true,
            'Asia/Nicosia' => true,
            'Asia/Novosibirsk' => true,
            'Asia/Omsk' => true,
            'Asia/Oral' => true,
            'Asia/Phnom_Penh' => true,
            'Asia/Pontianak' => true,
            'Asia/Pyongyang' => true,
            'Asia/Qatar' => true,
            'Asia/Qyzylorda' => true,
            'Asia/Rangoon' => true,
            'Asia/Riyadh' => true,
            'Asia/Saigon' => true,
            'Asia/Sakhalin' => true,
            'Asia/Samarkand' => true,
            'Asia/Seoul' => true,
            'Asia/Shanghai' => true,
            'Asia/Singapore' => true,
            'Asia/Taipei' => true,
            'Asia/Tashkent' => true,
            'Asia/Tbilisi' => true,
            'Asia/Tehran' => true,
            'Asia/Tel_Aviv' => true,
            'Asia/Thimbu' => true,
            'Asia/Thimphu' => true,
            'Asia/Tokyo' => true,
            'Asia/Ujung_Pandang' => true,
            'Asia/Ulaanbaatar' => true,
            'Asia/Ulan_Bator' => true,
            'Asia/Urumqi' => true,
            'Asia/Vientiane' => true,
            'Asia/Vladivostok' => true,
            'Asia/Yakutsk' => true,
            'Asia/Yekaterinburg' => true,
            'Asia/Yerevan' => true,
            'Atlantic/Azores' => true,
            'Atlantic/Bermuda' => true,
            'Atlantic/Canary' => true,
            'Atlantic/Cape_Verde' => true,
            'Atlantic/Faeroe' => true,
            'Atlantic/Faroe' => true,
            'Atlantic/Jan_Mayen' => true,
            'Atlantic/Madeira' => true,
            'Atlantic/Reykjavik' => true,
            'Atlantic/South_Georgia' => true,
            'Atlantic/St_Helena' => true,
            'Atlantic/Stanley' => true,
            'Australia/ACT' => true,
            'Australia/Adelaide' => true,
            'Australia/Brisbane' => true,
            'Australia/Broken_Hill' => true,
            'Australia/Canberra' => true,
            'Australia/Currie' => true,
            'Australia/Darwin' => true,
            'Australia/Eucla' => true,
            'Australia/Hobart' => true,
            'Australia/LHI' => true,
            'Australia/Lindeman' => true,
            'Australia/Lord_Howe' => true,
            'Australia/Melbourne' => true,
            'Australia/North' => true,
            'Australia/NSW' => true,
            'Australia/Perth' => true,
            'Australia/Queensland' => true,
            'Australia/South' => true,
            'Australia/Sydney' => true,
            'Australia/Tasmania' => true,
            'Australia/Victoria' => true,
            'Australia/West' => true,
            'Australia/Yancowinna' => true,
            'Europe/Amsterdam' => true,
            'Europe/Andorra' => true,
            'Europe/Athens' => true,
            'Europe/Belfast' => true,
            'Europe/Belgrade' => true,
            'Europe/Berlin' => true,
            'Europe/Bratislava' => true,
            'Europe/Brussels' => true,
            'Europe/Bucharest' => true,
            'Europe/Budapest' => true,
            'Europe/Chisinau' => true,
            'Europe/Copenhagen' => true,
            'Europe/Dublin' => true,
            'Europe/Gibraltar' => true,
            'Europe/Guernsey' => true,
            'Europe/Helsinki' => true,
            'Europe/Isle_of_Man' => true,
            'Europe/Istanbul' => true,
            'Europe/Jersey' => true,
            'Europe/Kaliningrad' => true,
            'Europe/Kiev' => true,
            'Europe/Lisbon' => true,
            'Europe/Ljubljana' => true,
            'Europe/London' => true,
            'Europe/Luxembourg' => true,
            'Europe/Madrid' => true,
            'Europe/Malta' => true,
            'Europe/Mariehamn' => true,
            'Europe/Minsk' => true,
            'Europe/Monaco' => true,
            'Europe/Moscow' => true,
            'Europe/Nicosia' => true,
            'Europe/Oslo' => true,
            'Europe/Paris' => true,
            'Europe/Podgorica' => true,
            'Europe/Prague' => true,
            'Europe/Riga' => true,
            'Europe/Rome' => true,
            'Europe/Samara' => true,
            'Europe/San_Marino' => true,
            'Europe/Sarajevo' => true,
            'Europe/Simferopol' => true,
            'Europe/Skopje' => true,
            'Europe/Sofia' => true,
            'Europe/Stockholm' => true,
            'Europe/Tallinn' => true,
            'Europe/Tirane' => true,
            'Europe/Tiraspol' => true,
            'Europe/Uzhgorod' => true,
            'Europe/Vaduz' => true,
            'Europe/Vatican' => true,
            'Europe/Vienna' => true,
            'Europe/Vilnius' => true,
            'Europe/Volgograd' => true,
            'Europe/Warsaw' => true,
            'Europe/Zagreb' => true,
            'Europe/Zaporozhye' => true,
            'Europe/Zurich' => true,
            'Indian/Antananarivo' => true,
            'Indian/Chagos' => true,
            'Indian/Christmas' => true,
            'Indian/Cocos' => true,
            'Indian/Comoro' => true,
            'Indian/Kerguelen' => true,
            'Indian/Mahe' => true,
            'Indian/Maldives' => true,
            'Indian/Mauritius' => true,
            'Indian/Mayotte' => true,
            'Indian/Reunion' => true,
            'Pacific/Apia' => true,
            'Pacific/Auckland' => true,
            'Pacific/Chatham' => true,
            'Pacific/Easter' => true,
            'Pacific/Efate' => true,
            'Pacific/Enderbury' => true,
            'Pacific/Fakaofo' => true,
            'Pacific/Fiji' => true,
            'Pacific/Funafuti' => true,
            'Pacific/Galapagos' => true,
            'Pacific/Gambier' => true,
            'Pacific/Guadalcanal' => true,
            'Pacific/Guam' => true,
            'Pacific/Honolulu' => true,
            'Pacific/Johnston' => true,
            'Pacific/Kiritimati' => true,
            'Pacific/Kosrae' => true,
            'Pacific/Kwajalein' => true,
            'Pacific/Majuro' => true,
            'Pacific/Marquesas' => true,
            'Pacific/Midway' => true,
            'Pacific/Nauru' => true,
            'Pacific/Niue' => true,
            'Pacific/Norfolk' => true,
            'Pacific/Noumea' => true,
            'Pacific/Pago_Pago' => true,
            'Pacific/Palau' => true,
            'Pacific/Pitcairn' => true,
            'Pacific/Ponape' => true,
            'Pacific/Port_Moresby' => true,
            'Pacific/Rarotonga' => true,
            'Pacific/Saipan' => true,
            'Pacific/Samoa' => true,
            'Pacific/Tahiti' => true,
            'Pacific/Tarawa' => true,
            'Pacific/Tongatapu' => true,
            'Pacific/Truk' => true,
            'Pacific/Wake' => true,
            'Pacific/Wallis' => true,
        ];

        return isset($valid_timezones[$timezone]);
    }

    /**
     * Allows setting a callback to translate or modify any return values from ::format(), fDate::format() and fTime::format().
     *
     * @param callable $callback The callback to pass all formatted dates/times/timestamps through. Should accept a single string and return a single string.
     */
    public static function registerFormatCallback($callback): void
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        self::$format_callback = $callback;
    }

    /**
     * Allows setting a callback to parse any date strings passed into ::__construct(), fDate::__construct() and fTime::__construct().
     *
     * @param callable $callback The callback to pass all date/time/timestamp strings through. Should accept a single string and return a single string that is parsable by [http://php.net/strtotime `strtotime()`].
     */
    public static function registerUnformatCallback($callback): void
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        self::$unformat_callback = $callback;
    }

    /**
     * Resets the configuration of the class.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$formats = [];
        self::$format_callback = null;
    }

    /**
     * Provides a consistent interface to setting the default timezone. Wraps the [http://php.net/date_default_timezone_set date_default_timezone_set()] function.
     *
     * @param string $timezone The default timezone to use for all date/time calculations
     */
    public static function setDefaultTimezone($timezone): void
    {
        self::checkPHPVersion();

        $result = date_default_timezone_set($timezone);
        if (! $result) {
            throw new fProgrammerException(
                'The timezone specified, %s, is not a valid timezone',
                $timezone
            );
        }
    }

    /**
     * Takes a format name set via ::defineFormat() and returns the [http://php.net/date date()] function formatting string.
     *
     * @internal
     *
     * @param string $format The format to translate
     *
     * @return string The formatting string. If no matching format was found, this will be the same as the `$format` parameter.
     */
    public static function translateFormat($format)
    {
        if (isset(self::$formats[$format])) {
            $format = self::$formats[$format];
        }

        return $format;
    }

    /**
     * Changes the date/time by the adjustment specified.
     *
     * @param string $adjustment The adjustment to make - may be a relative adjustment or a different timezone
     *
     * @throws fValidationException When `$adjustment` is not a valid relative date/time measurement or timezone
     *
     * @return fTimestamp The adjusted date/time
     */
    public function adjust($adjustment)
    {
        if (self::isValidTimezone($adjustment)) {
            $timezone = $adjustment;
            $timestamp = $this->timestamp;
        } else {
            $timezone = $this->timezone;
            $timestamp = strtotime($adjustment, $this->timestamp);

            if ($timestamp === false || $timestamp === -1) {
                throw new fValidationException(
                    'The adjustment specified, %s, does not appear to be a valid relative date/time measurement',
                    $adjustment
                );
            }
        }

        return new self($timestamp, $timezone);
    }

    /**
     * If this timestamp is equal to the timestamp passed.
     *
     * @param fTimestamp|int|object|string $other_timestamp The timestamp to compare with, `NULL` is interpreted as today
     *
     * @return bool If this timestamp is equal to the one passed
     */
    public function eq($other_timestamp = null)
    {
        $other_timestamp = new self($other_timestamp);

        return $this->timestamp == $other_timestamp->timestamp;
    }

    /**
     * Formats the date/time.
     *
     * @param string $format The [http://php.net/date date()] function compatible formatting string, or a format name from ::defineFormat()
     *
     * @return string The formatted date/time
     */
    public function format($format)
    {
        $format = self::translateFormat($format);

        $default_tz = date_default_timezone_get();
        date_default_timezone_set($this->timezone);

        $formatted = date($format, $this->timestamp);

        date_default_timezone_set($default_tz);

        return self::callFormatCallback($formatted);
    }

    /**
     * Returns the approximate difference in time, discarding any unit of measure but the least specific.
     *
     * The output will read like:
     *
     *  - "This timestamp is `{return value}` the provided one" when a timestamp it passed
     *  - "This timestamp is `{return value}`" when no timestamp is passed and comparing with the current timestamp
     *
     * Examples of output for a timestamp passed might be:
     *
     *  - `'5 minutes after'`
     *  - `'2 hours before'`
     *  - `'2 days after'`
     *  - `'at the same time'`
     *
     * Examples of output for no timestamp passed might be:
     *
     *  - `'5 minutes ago'`
     *  - `'2 hours ago'`
     *  - `'2 days from now'`
     *  - `'1 year ago'`
     *  - `'right now'`
     *
     * You would never get the following output since it includes more than one unit of time measurement:
     *
     *  - `'5 minutes and 28 seconds'`
     *  - `'3 weeks, 1 day and 4 hours'`
     *
     * Values that are close to the next largest unit of measure will be rounded up:
     *
     *  - `'55 minutes'` would be represented as `'1 hour'`, however `'45 minutes'` would not
     *  - `'29 days'` would be represented as `'1 month'`, but `'21 days'` would be shown as `'3 weeks'`
     *
     * @param fTimestamp|int|object|string $other_timestamp The timestamp to create the difference with, `NULL` is interpreted as now
     * @param bool                         $simple          When `TRUE`, the returned value will only include the difference in the two timestamps, but not `from now`, `ago`, `after` or `before`
     * @param bool                          :$simple
     */
    public function getFuzzyDifference($other_timestamp = null, $simple = false): string
    {
        if (is_bool($other_timestamp)) {
            $simple = $other_timestamp;
            $other_timestamp = null;
        }

        $relative_to_now = false;
        if ($other_timestamp === null) {
            $relative_to_now = true;
        }
        $other_timestamp = new self($other_timestamp);

        $diff = $this->timestamp - $other_timestamp->timestamp;

        if (abs($diff) < 10) {
            if ($relative_to_now) {
                return self::compose('right now');
            }

            return self::compose('at the same time');
        }

        $break_points = [
            // 45 seconds
            45 => [1,        self::compose('second'), self::compose('seconds')],
            // 45 minutes
            2700 => [60,       self::compose('minute'), self::compose('minutes')],
            // 18 hours
            64800 => [3600,     self::compose('hour'),   self::compose('hours')],
            // 5 days
            432000 => [86400,    self::compose('day'),    self::compose('days')],
            // 3 weeks
            1814400 => [604800,   self::compose('week'),   self::compose('weeks')],
            // 9 months
            23328000 => [2592000,  self::compose('month'),  self::compose('months')],
            // largest int
            2147483647 => [31536000, self::compose('year'),   self::compose('years')],
        ];

        foreach ($break_points as $break_point => $unit_info) {
            if (abs($diff) > $break_point) {
                continue;
            }

            $unit_diff = round(abs($diff) / $unit_info[0]);
            $units = fGrammar::inflectOnQuantity($unit_diff, $unit_info[1], $unit_info[2]);

            break;
        }

        if ($simple) {
            return self::compose('%1$s %2$s', $unit_diff, $units);
        }

        if ($relative_to_now) {
            if ($diff > 0) {
                return self::compose('%1$s %2$s from now', $unit_diff, $units);
            }

            return self::compose('%1$s %2$s ago', $unit_diff, $units);
        }

        if ($diff > 0) {
            return self::compose('%1$s %2$s after', $unit_diff, $units);
        }

        return self::compose('%1$s %2$s before', $unit_diff, $units);
    }

    /**
     * If this timestamp is greater than the timestamp passed.
     *
     * @param fTimestamp|int|object|string $other_timestamp The timestamp to compare with, `NULL` is interpreted as now
     *
     * @return bool If this timestamp is greater than the one passed
     */
    public function gt($other_timestamp = null)
    {
        $other_timestamp = new self($other_timestamp);

        return $this->timestamp > $other_timestamp->timestamp;
    }

    /**
     * If this timestamp is greater than or equal to the timestamp passed.
     *
     * @param fTimestamp|int|object|string $other_timestamp The timestamp to compare with, `NULL` is interpreted as now
     *
     * @return bool If this timestamp is greater than or equal to the one passed
     */
    public function gte($other_timestamp = null)
    {
        $other_timestamp = new self($other_timestamp);

        return $this->timestamp >= $other_timestamp->timestamp;
    }

    /**
     * If this timestamp is less than the timestamp passed.
     *
     * @param fTimestamp|int|object|string $other_timestamp The timestamp to compare with, `NULL` is interpreted as today
     *
     * @return bool If this timestamp is less than the one passed
     */
    public function lt($other_timestamp = null)
    {
        $other_timestamp = new self($other_timestamp);

        return $this->timestamp < $other_timestamp->timestamp;
    }

    /**
     * If this timestamp is less than or equal to the timestamp passed.
     *
     * @param fTimestamp|int|object|string $other_timestamp The timestamp to compare with, `NULL` is interpreted as today
     *
     * @return bool If this timestamp is less than or equal to the one passed
     */
    public function lte($other_timestamp = null)
    {
        $other_timestamp = new self($other_timestamp);

        return $this->timestamp <= $other_timestamp->timestamp;
    }

    /**
     * Modifies the current timestamp, creating a new fTimestamp object.
     *
     * The purpose of this method is to allow for easy creation of a timestamp
     * based on this timestamp. Below are some examples of formats to
     * modify the current timestamp:
     *
     *  - `'Y-m-01 H:i:s'` to change the date of the timestamp to the first of the month:
     *  - `'Y-m-t H:i:s'` to change the date of the timestamp to the last of the month:
     *  - `'Y-m-d 17:i:s'` to set the hour of the timestamp to 5 PM:
     *
     * @param string $format   The current timestamp will be formatted with this string, and the output used to create a new object. The format should **not** include the timezone (character `e`).
     * @param string $timezone The timezone for the new object if different from the current timezone
     *
     * @return fTimestamp The new timestamp
     */
    public function modify($format, $timezone = null)
    {
        $timezone = ($timezone !== null) ? $timezone : $this->timezone;

        return new self($this->format($format), $timezone);
    }

    /**
     * Composes text using fText if loaded.
     *
     * @param string $message   The message to compose
     * @param mixed  $component A string or number to insert into the message
     * @param  mixed   ...
     *
     * @return string The composed and possible translated message
     */
    protected static function compose($message)
    {
        $args = array_slice(func_get_args(), 1);

        if (class_exists('fText', false)) {
            return call_user_func_array(
                ['fText', 'compose'],
                [$message, $args]
            );
        }

        return vsprintf($message, $args);
    }

    /**
     * Checks to make sure the current version of PHP is high enough to support timezone features.
     */
    private static function checkPHPVersion(): void
    {
        if (! fCore::checkVersion('5.1')) {
            throw new fEnvironmentException(
                'The %s class takes advantage of the timezone features in PHP 5.1.0 and newer. Unfortunately it appears you are running an older version of PHP.',
                __CLASS__
            );
        }
    }
}

/*
 * Copyright (c) 2008-2010 Will Bond <will@flourishlib.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
