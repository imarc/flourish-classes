<?php
/**
 * Provides large/precise number support.
 *
 * @copyright  Copyright (c) 2008-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fNumber
 *
 * @version    1.0.0b2
 * @changes    1.0.0b2  Fixed a bug with parsing decimal numbers in scientific notation [wb, 2010-04-13]
 * @changes    1.0.0b   The initial implementation [wb, 2008-07-21]
 */
class fNumber
{
    // The following constants allow for nice looking callbacks to static methods
    public const baseConvert = 'fNumber::baseConvert';

    public const pi = 'fNumber::pi';

    public const registerFormatCallback = 'fNumber::registerFormatCallback';

    public const registerUnformatCallback = 'fNumber::registerUnformatCallback';

    public const reset = 'fNumber::reset';

    /**
     * A callback to process all values through.
     *
     * @var callable
     */
    private static $format_callback;

    /**
     * A callback to process all values through.
     *
     * @var callable
     */
    private static $unformat_callback;

    /**
     * The scale (number of digits after the decimal) of the number.
     *
     * @var int
     */
    private $scale;

    /**
     * The value of the number.
     *
     * @var string
     */
    private $value;

    /**
     * Creates a large/precise number.
     *
     * @param string $value The value for the number - any valid PHP integer or float format including values with `e` exponents
     * @param int    $scale The number of digits after the decimal place, defaults to number of digits in `$value`
     *
     * @throws fValidationException When `$value` is not a valid number
     *
     * @return fNumber
     */
    public function __construct($value, $scale = null)
    {
        $value = self::parse($value, 'array');

        if ($scale !== null) {
            if (strlen($value['fraction']) > $scale) {
                $value['fraction'] = substr($value['fraction'], 0, $scale);
            } else {
                $value['fraction'] = str_pad($value['fraction'], $scale, '0', STR_PAD_RIGHT);
            }
        }

        $this->value = (strlen($value['fraction'])) ? implode('.', $value) : $value['integer'];
        $this->scale = strlen($value['fraction']);
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
     * Converts the object to an string.
     *
     * @return string
     */
    public function __toString()
    {
        return ($this->value[0] == '+') ? substr($this->value, 1) : $this->value;
    }

    /**
     * Converts any positive integer between any two bases ranging from `2` to `16`.
     *
     * @param fNumber|string $number    The positive integer to convert
     * @param int            $from_base The base to convert from - must be between `2` and `16`
     * @param int            $to_base   The base to convert to - must be between `2` and `16`
     *
     * @return string The number converted to the new base
     */
    public static function baseConvert($number, $from_base, $to_base)
    {
        if ($number instanceof self && $from_base != 10) {
            throw new fProgrammerException(
                'The from base specified, %s, is not valid for an fNumber object',
                $from_base
            );
        }

        if (strlen($number) && $number[0] == '+') {
            $number = substr($number, 1);
        }

        if (! ctype_xdigit($number)) {
            throw new fProgrammerException(
                'The number specified, %s, does not appear to be a positive integer. Negative numbers and fractions are not supported due the different encoding schemes that can be used.',
                $number
            );
        }

        if (! is_numeric($from_base) || $from_base < 2 || $from_base > 16) {
            throw new fProgrammerException(
                'The from base specified, %1$s, is not valid base between %2$s and %3$s',
                $from_base,
                '2',
                '16'
            );
        }

        if (! is_numeric($to_base) || $to_base < 2 || $to_base > 16) {
            throw new fProgrammerException(
                'The to base specified, %1$s, is not valid base between %2$s and %3$s',
                $from_base,
                '2',
                '16'
            );
        }

        $base_string = '0123456789ABCDEF';
        $base_map = [
            'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14, 'F' => 15,
        ];

        // Convert input number to base 10
        if ($from_base != 10) {
            $length = strlen($number);
            $decimal = new self('0');
            $base_num = new self($from_base);

            for ($i = 0; $i < $length; $i++) {
                $char = strtoupper($number[$i]);
                $value = new self((isset($base_map[$char])) ? $base_map[$char] : $char);
                $decimal = $decimal->add($value->mul($base_num->pow($length - ($i + 1)))->round(0));
            }
        } elseif (! $number instanceof self) {
            $decimal = new self($number);
        } else {
            $decimal = $number;
        }

        $output = '';

        if ($to_base != 10) {
            do {
                $frac = $decimal->div($to_base, 3)->__toString();
                $frac = '0'.substr($frac, strpos($frac, '.'));
                $x = (int) ($frac * $to_base + 1.5);

                $output = $base_string[$x - 1].$output;

                $decimal = $decimal->div($to_base, 0);
            } while ($decimal->gt('0.0'));
        } else {
            $output = $decimal->__toString();
        }

        return $output;
    }

    /**
     * Returns the value for pi with a scale of up to 500.
     *
     * @param int $scale The number of places after the decimal to return
     *
     * @return fNumber Pi
     */
    public static function pi($scale)
    {
        static $pi_places = '14159265358979323846264338327950288419716939937510582097494459230781640628620899862803482534211706798214808651328230664709384460955058223172535940812848111745028410270193852110555964462294895493038196442881097566593344612847564823378678316527120190914564856692346034861045432664821339360726024914127372458700660631558817488152092096282925409171536436789259036001133053054882046652138414695194151160943305727036575959195309218611738193261179310511854807446237996274956735188575272489122793818301194912';

        if (is_numeric($scale)) {
            $scale = (int) $scale;
        }
        if (! is_numeric($scale) || $scale < 0 || $scale > 500) {
            throw new fProgrammerException(
                'The scale specified, %1$s, is outside the valid scale for pi (%2$s to %3$s)',
                $scale,
                '0',
                '500'
            );
        }

        $fraction = substr($pi_places, 0, $scale);
        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        return new self('3'.$fraction);
    }

    /**
     * Allows setting a callback to translate or modify any return values from ::format().
     *
     * @param callable $callback The callback to pass the fNumber value to. Should accept a string value and return a single string.
     */
    public static function registerFormatCallback($callback): void
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        self::$format_callback = $callback;
    }

    /**
     * Allows setting a callback to clean any formatted values so they can be properly parsed - useful for languages where `,` is used as the decimal point.
     *
     * @param callable $callback The callback to pass formatted strings to. Should accept a formatted string and return a string the is a valid number using `.` as the decimal point.
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
        self::$format_callback = null;
        self::$unformat_callback = null;
    }

    /**
     * Returns the absolute value of this number.
     *
     * @param int $scale The number of places after the decimal - overrides the scale for this number
     *
     * @return fNumber The absolute number
     */
    public function abs($scale = null)
    {
        $scale = $this->fixScale($scale);

        return new self(substr($this->value, 1), $scale);
    }

    /**
     * Adds two numbers together.
     *
     * @param fNumber|string $addend The addend
     * @param int            $scale  The number of places after the decimal - overrides the scale for this number
     *
     * @throws fValidationException When `$addend` is not a valid number
     *
     * @return fNumber The sum
     */
    public function add($addend, $scale = null)
    {
        $scale = $this->fixScale($scale);
        $addend = self::parse($addend, 'number');

        if (function_exists('bcadd')) {
            $sum = bcadd($this->value, $addend, $scale);
        } else {
            $sum = self::performAdd($this->value, $addend, $scale);
        }

        return new self($sum, $scale);
    }

    /**
     * Rounds the number to the next highest integer.
     *
     * @return fNumber The next highest integer
     */
    public function ceil()
    {
        if (strpos($this->value, '.') === false) {
            return clone $this;
        }

        $fraction = substr($this->value, strpos($this->value, '.') + 1);
        $integer = substr($this->value, 0, strpos($this->value, '.'));

        // Negative numbers or numbers with only 0 after the decimal can be truncated
        if (trim($fraction, '0') === '' || $this->value[0] == '-') {
            return new self($integer);
        }

        // Positive numbers with a fraction need to be increased
        return new self(self::performAdd($integer, '1'));
    }

    /**
     * Divides this number by the one passed.
     *
     * @param fNumber|string $divisor The divisor
     * @param int            $scale   The number of places after the decimal - overrides the scale for this number
     *
     * @throws fValidationException When `$divisor` is not a valid number
     *
     * @return fNumber The quotient
     */
    public function div($divisor, $scale = null)
    {
        $scale = $this->fixScale($scale);
        $divisor = self::parse($divisor, 'number');

        if (self::isZero($divisor)) {
            throw new fValidationException(
                'The divisor specified, %s, is zero, which is an invalid divisor',
                $divisor
            );
        }

        if (function_exists('bcdiv')) {
            $value = bcdiv($this->value, $divisor, $scale);
        } else {
            $value = self::performDiv($this->value, $divisor, $remainder, $scale);
        }

        return new self($value, $scale);
    }

    /**
     * Indicates if this value is equal to the one passed.
     *
     * @param fNumber|string $number The number to compare to
     * @param int            $scale  The number of decimal places to compare - will use all available if not specified
     *
     * @throws fValidationException When `$number` is not a valid number
     *
     * @return bool If this number is equal to the one passed
     */
    public function eq($number, $scale = null)
    {
        if ($scale !== null) {
            $scale = (int) $scale;
            if ($scale < 0) {
                $scale = 0;
            }
        }
        [$this_number, $number] = self::normalize($this, $number, $scale);

        return (self::cmp($this_number, $number) == 0) ? true : false;
    }

    /**
     * Rounds the number to the next lowest integer.
     *
     * @return fNumber The next lowest integer
     */
    public function floor()
    {
        if (strpos($this->value, '.') === false) {
            return clone $this;
        }

        $fraction = substr($this->value, strpos($this->value, '.') + 1);
        $integer = substr($this->value, 0, strpos($this->value, '.'));

        // Positive numbers or numbers with only 0 after the decimal can just be truncated
        if (trim($fraction, '0') === '' || $this->value[0] == '+') {
            return new self($integer);
        }

        // Negative values with a fraction need to be brought down
        return new self(self::performSub($integer, '1'));
    }

    /**
     * Returns the float remainder of dividing this number by the divisor provided.
     *
     * @param fNumber|string $divisor The divisor
     * @param int            $scale   The number of places after the decimal - overrides the scale for this number
     *
     * @throws fValidationException When `$divisor` is not a valid number
     *
     * @return fNumber The remainder
     */
    public function fmod($divisor, $scale = null)
    {
        $scale = $this->fixScale($scale);

        $div_frac = self::parse($divisor, 'fraction');
        $val_frac = self::parse($this->value, 'fraction');

        $actual_scale = max(strlen($div_frac), strlen($val_frac));

        self::performDiv($this->value, $divisor, $remainder, 0);

        $int_len = (strlen($remainder) - 1 < $actual_scale) ? 1 : strlen($remainder) - $actual_scale;
        $int = substr($remainder, 0, $int_len);
        $int = (strlen($int)) ? $int : '0';

        $frac_start = (strlen($remainder) - 1 < $actual_scale) ? 1 : strlen($remainder) - $actual_scale;
        $frac = substr($remainder, $frac_start);
        $frac = (strlen($frac)) ? '.'.$frac : '';

        if (strlen($int) == 1) {
            $int .= '0';
        }
        $remainder = $int.$frac;
        $remainder = self::setScale($remainder, $scale);

        return new self($remainder);
    }

    /**
     * Formats the number to include thousands separators.
     *
     * @return string The formatted value
     */
    public function format()
    {
        if (self::$format_callback !== null) {
            return call_user_func(self::$format_callback, $this->value);
        }

        // We can't use number_format() since it takes a float and we have a
        // string that can not be losslessly converted to a float
        $parts = explode('.', $this->value);

        $integer = $parts[0];
        $fraction = (! isset($parts[1])) ? '' : $parts[1];

        $sign = ($integer[0] == '-') ? '-' : '';
        $integer = substr($integer, 1);

        $int_sections = [];
        for ($i = strlen($integer) - 3; $i > 0; $i -= 3) {
            array_unshift($int_sections, substr($integer, $i, 3));
        }
        array_unshift($int_sections, substr($integer, 0, $i + 3));

        $integer = implode(',', $int_sections);
        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        return $sign.$integer.$fraction;
    }

    /**
     * Returns the scale of this number.
     *
     * @return int The scale of this number
     */
    public function getScale()
    {
        return $this->scale;
    }

    /**
     * Indicates if this value is greater than the one passed.
     *
     * @param fNumber|string $number The number to compare to
     * @param int            $scale  The number of decimal places to compare - will use all available if not specified
     *
     * @throws fValidationException When `$number` is not a valid number
     *
     * @return bool If this number is less than the one passed
     */
    public function gt($number, $scale = null)
    {
        if ($scale !== null) {
            $scale = (int) $scale;
            if ($scale < 0) {
                $scale = 0;
            }
        }
        [$this_number, $number] = self::normalize($this, $number, $scale);

        return (self::cmp($this_number, $number) > 0) ? true : false;
    }

    /**
     * Indicates if this value is greater than or equal to the one passed.
     *
     * @param fNumber|string $number The number to compare to
     * @param int            $scale  The number of decimal places to compare - will use all available if not specified
     *
     * @throws fValidationException When `$number` is not a valid number
     *
     * @return bool If this number is greater than or equal to the one passed
     */
    public function gte($number, $scale = null)
    {
        if ($scale !== null) {
            $scale = (int) $scale;
            if ($scale < 0) {
                $scale = 0;
            }
        }
        [$this_number, $number] = self::normalize($this, $number, $scale);

        return (self::cmp($this_number, $number) > -1) ? true : false;
    }

    /**
     * Indicates if this value is less than the one passed.
     *
     * @param fNumber|string $number The number to compare to
     * @param int            $scale  The number of decimal places to compare - will use all available if not specified
     *
     * @throws fValidationException When `$number` is not a valid number
     *
     * @return bool If this number is less than the one passed
     */
    public function lt($number, $scale = null)
    {
        if ($scale !== null) {
            $scale = (int) $scale;
            if ($scale < 0) {
                $scale = 0;
            }
        }
        [$this_number, $number] = self::normalize($this, $number, $scale);

        return (self::cmp($this_number, $number) < 0) ? true : false;
    }

    /**
     * Indicates if this value is less than or equal to the one passed.
     *
     * @param fNumber|string $number The number to compare to
     * @param int            $scale  The number of decimal places to compare - will use all available if not specified
     *
     * @throws fValidationException When `$number` is not a valid number
     *
     * @return bool If this number is less than or equal to the one passed
     */
    public function lte($number, $scale = null)
    {
        if ($scale !== null) {
            $scale = (int) $scale;
            if ($scale < 0) {
                $scale = 0;
            }
        }
        [$this_number, $number] = self::normalize($this, $number, $scale);

        return (self::cmp($this_number, $number) < 1) ? true : false;
    }

    /**
     * Returns the remainder of dividing this number by the divisor provided. All floats are converted to integers.
     *
     * @param fNumber|string $divisor The divisor - will be converted to an integer if it is a float
     *
     * @throws fValidationException When `$divisor` is not a valid number
     *
     * @return fNumber The remainder
     */
    public function mod($divisor)
    {
        // Modulus only works on the integer part of the number
        $divisor = self::parse($divisor, 'integer');
        $number = self::parse($this->value, 'integer');

        if (function_exists('bcmod')) {
            $remainder = bcmod($number, $divisor);
        } else {
            self::performDiv($number, $divisor, $remainder);
            $remainder = $number[0].substr($remainder, 1);
        }

        return new self($remainder);
    }

    /**
     * Multiplies two numbers.
     *
     * @param fNumber|string $multiplier The multiplier
     * @param int            $scale      The number of places after the decimal - overrides the scale for this number
     *
     * @throws fValidationException When `$multiplier` is not a valid number
     *
     * @return fNumber The product
     */
    public function mul($multiplier, $scale = null)
    {
        $scale = $this->fixScale($scale);
        $multiplier = self::parse($multiplier, 'number');

        if (function_exists('bcmul')) {
            $value = bcmul($this->value, $multiplier, $scale);
        } else {
            $value = self::performMul($this->value, $multiplier, $scale);
        }

        return new self($value, $scale);
    }

    /**
     * Negates this number.
     *
     * @param int $scale The number of places after the decimal - overrides the scale for this number
     *
     * @return fNumber The negated number
     */
    public function neg($scale = null)
    {
        $scale = $this->fixScale($scale);
        $value = substr($this->value, 1);
        $value = ($this->value[0] == '-') ? '+'.$value : '-'.$value;

        return new self($value, $scale);
    }

    /**
     * Raise this number to the power specified.
     *
     * @param int $exponent The power to raise to - all non integer values will be truncated to integers
     * @param int $scale    The number of places after the decimal - overrides the scale for this number
     *
     * @throws fValidationException When `$exponent` is not a valid number
     *
     * @return fNumber The product
     */
    public function pow($exponent, $scale = null)
    {
        $scale = $this->fixScale($scale);

        if (function_exists('bcpow')) {
            $value = bcpow($this->value, (int) $exponent, $scale);
        } else {
            $value = self::performPow($this->value, (int) $exponent, $scale);
        }

        return new self($value, $scale);
    }

    /**
     * Gets the remainder of this integer number raised to the integer `$exponent`, divided by the integer `$modulus`.
     *
     * This method is faster than doing `$num->pow($exponent)->mod($modulus)`
     * and is primarily useful for cryptographic functionality.
     *
     * @param fNumber|string $exponent The power to raise to - all non integer values will be truncated to integers
     * @param fNumber|string $modulus  The value to divide by - all non integer values will be truncated to integers
     *
     * @throws fValidationException When `$exponent` or `$modulus` is not a valid number
     *
     * @return fNumber The remainder
     */
    public function powmod($exponent, $modulus)
    {
        $exp = self::parse($exponent, 'array');
        $mod = self::parse($modulus, 'array');

        if ($this->value[0] == '-') {
            throw new fProgrammerException(
                'The method %s can only be called for positive number, however this number is negative',
                'powmod()'
            );
        }

        if ($exp['integer'][0] == '-') {
            throw new fProgrammerException(
                'The exponent specified, %s, must be a positive integer, however it is negative',
                $exponent
            );
        }

        if ($mod['integer'][0] == '-') {
            throw new fProgrammerException(
                'The modulus specified, %s, must be a positive integer, however it is negative',
                $modulus
            );
        }

        // All numbers involved in this need to be integers
        $exponent = $exp['integer'];
        $modulus = $mod['integer'];
        $len = (strpos($this->value, '.') !== false) ? strpos($this->value, '.') : strlen($this->value);
        $value = substr($this->value, 0, $len);

        if (function_exists('bcpowmod')) {
            $result = bcpowmod($value, $exponent, $modulus, 0);
        } else {
            $exponent = self::baseConvert($exponent, 10, 2);
            $result = '+1';

            self::performDiv($value, $modulus, $first_modulus);
            for ($i = 0; $i < strlen($exponent); $i++) {
                self::performDiv(self::performMul($result, $result), $modulus, $result);
                if ($exponent[$i] == '1') {
                    self::performDiv(self::performMul($result, $first_modulus), $modulus, $result);
                }
            }
        }

        return new self($result);
    }

    /**
     * Rounds this number to the specified number of digits after the decimal - negative scales round the number by places to the left of the decimal.
     *
     * @param int $scale The number of places after (or before if negative) the decimal to round to
     *
     * @return fNumber The rounded result
     */
    public function round($scale)
    {
        $scale = (int) $scale;
        $number = self::setScale($this->value, ($scale < 0) ? 1 : $scale + 1);

        $length = strlen($number);
        $add = false;

        if ($scale == 0) {
            if ($number[$length - 1] >= 5) {
                $add = '1';
            }
            $number = substr($number, 0, -2);
        } elseif ($scale > 0) {
            if ($number[$length - 1] >= 5) {
                $add = '0.'.str_pad('', $scale - 1, '0').'1';
            }
            $number = substr($number, 0, -1);
        } else {
            $number = substr($number, 0, strpos($number, '.'));

            if (abs($scale) >= strlen($number)) {
                $number = '0';
            } else {
                if ($number[strlen($number) - abs($scale)] >= 5) {
                    $add = '1'.str_pad('', abs($scale), '0');
                }
                $number = substr($number, 0, $scale);
                $number .= str_pad('', abs($scale), '0');
            }
        }

        if ($add) {
            $number = self::performAdd($number, $add, $scale);
        }

        return new self($number);
    }

    /**
     * Returns the sign of the number.
     *
     * @return int `-1` if negative, `0` if zero, `1` if positive
     */
    public function sign()
    {
        if (self::isZero($this->value)) {
            return 0;
        }

        return ($this->value[0] == '-') ? -1 : 1;
    }

    /**
     * Returns the square root of this number.
     *
     * @param int $scale The number of places after the decimal - overrides the scale for this number
     *
     * @return fNumber The square root
     */
    public function sqrt($scale = null)
    {
        $scale = $this->fixScale($scale);

        if ($this->sign() == -1) {
            throw new fProgrammerException(
                'This number, %s, can not have the square root calculated since it is a negative number',
                $this->value
            );
        }

        if (function_exists('bcsqrt')) {
            $value = bcsqrt($this->value, $scale);

            return new self($value, $scale);
        }

        // Pure PHP implementation
        $parts = explode('.', $this->value);

        $integer = substr($parts[0], 1);
        $fraction = (isset($parts[1])) ? $parts[1] : '';

        if (strlen($integer) % 2 == 1) {
            $integer = '0'.$integer;
        }

        if (strlen($fraction) % 2 == 1) {
            $fraction .= '0';
        }

        $after_decimal = strlen($fraction) / 2;

        $number = $integer.$fraction;
        $i = 0;
        $remainder = '0';
        $p = '0';

        $len = strlen($number);
        $len += (($scale * 2) - $after_decimal > 0) ? ($scale * 2) - $after_decimal : 0;

        while ($i < $len) {
            if ($i < strlen($number)) {
                $c = substr($number, $i, 2);
            } else {
                $c = '00';
                $after_decimal++;
            }

            if (! self::isZero($remainder)) {
                $c = $remainder.$c;
            }

            $x = -1;
            $p2 = self::performMul($p, '2');

            do {
                $x++;
            } while (self::cmp(self::performMul($p2.$x, $x), $c) <= 0);

            $x--;
            $y = self::performMul($p2.$x, $x);
            $p = ($p) ? $p.$x : $x;

            $remainder = self::performSub($c, $y);

            $i += 2;
        }

        if (strlen($p) <= $after_decimal) {
            $p = $p[0].str_pad(substr($p, 1), $after_decimal + 1, '0', STR_PAD_LEFT);
        }

        $integer = substr($p, 0, strlen($p) - $after_decimal);
        $fraction = substr($p, strlen($p) - $after_decimal);
        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        $p = $integer.$fraction;

        $p = self::setScale($p, $scale);
        $p = self::stripLeadingZeroes($p);

        return new self($p);
    }

    /**
     * Subtracts two numbers.
     *
     * @param fNumber|string $subtrahend The subtrahend
     * @param int            $scale      The number of places after the decimal - overrides the scale for this number
     *
     * @throws fValidationException When `$subtrahend` is not a valid number
     *
     * @return fNumber The difference
     */
    public function sub($subtrahend, $scale = null)
    {
        $scale = $this->fixScale($scale);
        $subtrahend = self::parse($subtrahend, 'number');

        if (function_exists('bcadd')) {
            $diff = bcsub($this->value, $subtrahend, $scale);
        } else {
            $diff = self::performSub($this->value, $subtrahend, $scale);
        }

        return new self($diff, $scale);
    }

    /**
     * Scales (truncates or expands) the number to the specified number of digits after the decimal - negative scales round the number by places to the left of the decimal.
     *
     * @param int $scale The number of places after (or before if negative) the decimal
     *
     * @return fNumber The square root
     */
    public function trunc($scale)
    {
        $scale = (int) $scale;
        $number = $this->value;

        if ($scale < 0) {
            $number = substr($number, 0, strpos($number, '.'));

            if (abs($scale) >= strlen($number)) {
                $number = '0';
            } else {
                $number = substr($number, 0, $scale);
                $number .= str_pad('', abs($scale), '0');
            }
        }

        return new self($number, $scale);
    }

    /**
     * Compared the two numbers.
     *
     * @param string $number1 The first number to compare
     * @param string $number2 The second number to compare
     *
     * @return int Less than `0` if `$number1` is less than `$number2`, `0` if equal, greater than `0` if `$number1` is greater than `$number2`
     */
    private static function cmp($number1, $number2)
    {
        $number1 = self::fixSign($number1);
        $number2 = self::fixSign($number2);

        if ($number1[0] != $number2[0]) {
            return ($number1[0] == '+') ? 1 : -1;
        }

        if ($number1[0] == '-') {
            return strnatcmp(substr($number2, 1), substr($number1, 1));
        }

        return strnatcmp(substr($number1, 1), substr($number2, 1));
    }

    /**
     * Makes sure a number has a sign.
     *
     * @param string $number The number to check
     *
     * @return string The number with a sign
     */
    private static function fixSign($number)
    {
        $number = (string) $number;
        if (ctype_digit($number[0])) {
            $number = '+'.$number;
        }

        return $number;
    }

    /**
     * Checks to see if a number is equal to zero.
     *
     * @param string $number The number to check, first character should be the sign
     *
     * @return bool If the number is equal to zero
     */
    private static function isZero($number)
    {
        return trim(substr(str_replace('.', '', $number), 1), '0') == '';
    }

    /**
     * Normalizes two numbers to the same number of decimal places.
     *
     * @param fNumber|string $number1 The first number to normalize
     * @param fNumber|string $number2 The second number to normalize
     * @param int            $scale   The number of decimal places to normalize to
     *
     * @throws fValidationException When `$number1` or `$number2` is not a valid number
     *
     * @return array The two normalized numbers as strings
     */
    private static function normalize($number1, $number2, $scale = null)
    {
        $number1 = self::parse($number1, 'array');
        $number2 = self::parse($number2, 'array');

        if ($scale !== null || $number1['fraction'] || $number2['fraction']) {
            if ($scale === null) {
                $frac_len = max(strlen($number1['fraction']), strlen($number2['fraction']));
            } else {
                $frac_len = $scale;
            }

            if ($scale !== null && strlen($number1['fraction']) > $scale) {
                $number1['fraction'] = substr($number1['fraction'], 0, $scale);
            } else {
                $number1['fraction'] = str_pad($number1['fraction'], $frac_len, '0', STR_PAD_RIGHT);
            }
            if ($scale !== null && strlen($number2['fraction']) > $scale) {
                $number2['fraction'] = substr($number2['fraction'], 0, $scale);
            } else {
                $number2['fraction'] = str_pad($number2['fraction'], $frac_len, '0', STR_PAD_RIGHT);
            }

            $number1 = implode('.', $number1);
            $number2 = implode('.', $number2);
        } else {
            $number1 = $number1['integer'];
            $number2 = $number2['integer'];
        }

        $len = max(strlen($number1) - 1, strlen($number2) - 1);

        $number1 = $number1[0].str_pad(substr($number1, 1), $len, '0', STR_PAD_LEFT);
        $number2 = $number2[0].str_pad(substr($number2, 1), $len, '0', STR_PAD_LEFT);

        return [$number1, $number2];
    }

    /**
     * Parses a number to ensure it is valid.
     *
     * @param object|string $number  The number to parse
     * @param string        $element The element to return: `'number'`, `'integer'`, `'fraction'`, `'array'`
     *
     * @throws fValidationException When `$number` is not a valid number
     *
     * @return mixed The requested parsed element
     */
    private static function parse($number, $element)
    {
        if (is_object($number) && is_callable([$number, '__toString'])) {
            $number = $number->__toString();
        } else {
            $number = (string) $number;
        }
        $number = trim($number);

        if (self::$unformat_callback) {
            $number = call_user_func(self::$unformat_callback, $number);
        } else {
            $number = str_replace(',', '', $number);
        }

        $matched = preg_match('#^([+\-]?)((?:\d*\.)?\d+)(?:e([+\-]?)(\d+))?$#iD', $number, $matches);

        if (! $matched) {
            throw new fValidationException(
                'The number specified, %s, is invalid.',
                $number
            );
        }

        // Determine the sign
        $sign = ($matches[1] == '-') ? '-' : '+';

        $number = self::stripLeadingZeroes($matches[2]);

        $exponent = (isset($matches[4])) ? $matches[4] : null;
        $exponent_sign = (isset($matches[3])) ? $matches[3] : null;

        // Adjust the number by the exponent
        if ($exponent) {
            // A negative exponent means bringing the decimal to the left
            if ($exponent_sign == '-') {
                $decimal_pos = strpos($number, '.');

                if ($decimal_pos === false) {
                    $fraction = '';
                    $integer = $number;
                } else {
                    $fraction = substr($number, strpos($number, '.') + 1);
                    $integer = substr($number, 0, strpos($number, '.'));
                }

                if (strlen($integer) < $exponent) {
                    $before_decimal = '0';
                    $after_decimal = str_pad($integer, $exponent, '0', STR_PAD_LEFT);
                } else {
                    $break_point = strlen($integer) - $exponent;
                    $before_decimal = substr($integer, 0, $break_point);
                    $after_decimal = substr($integer, $break_point);
                }

                $number = $before_decimal.'.'.$after_decimal.$fraction;

            // A positive exponent means extending the number to the right
            } else {
                $number .= str_pad('', $exponent, '0', STR_PAD_RIGHT);
            }
        }

        // Strip any leading zeros that may have been added
        $number = self::stripLeadingZeroes($number);

        if (self::isZero($sign.$number)) {
            $sign = '+';
        }

        $parts = explode('.', $sign.$number);

        $output = [];
        $output['integer'] = $parts[0];
        $output['fraction'] = (isset($parts[1])) ? $parts[1] : '';

        if ($element == 'integer') {
            return $output['integer'];
        }
        if ($element == 'fraction') {
            return $output['fraction'];
        }
        if ($element == 'array') {
            return $output;
        }

        return implode('.', $parts);
    }

    /**
     * Adds two numbers together.
     *
     * @param string $number1 The first addend
     * @param string $number2 The second addend
     * @param int    $scale   The number of digits after the decimal
     *
     * @throws fValidationException When `$number1` or `$number2` is not a valid number
     *
     * @return string The sum of the two numbers
     */
    private static function performAdd($number1, $number2, $scale = null)
    {
        [$number1, $number2] = self::normalize($number1, $number2);

        if (self::isZero($number1)) {
            return self::setScale($number2, $scale);
        }
        if (self::isZero($number2)) {
            return self::setScale($number1, $scale);
        }

        // If the two numbers differ in sign, we need to subtract instead
        if ($number1[0] != $number2[0]) {
            if ($number1[0] == '-') {
                return self::performSub($number2, '+'.substr($number1, 1));
            }

            return self::performSub($number1, '+'.substr($number2, 1));
        }

        $carry = 0;
        $output = '';
        for ($i = strlen($number1) - 1; $i > 0; $i--) {
            if ($number1[$i] == '.') {
                $output = '.'.$output;

                continue;
            }
            $sum = $number1[$i] + $number2[$i] + $carry;
            $carry = (strlen($sum) > 1) ? substr($sum, 0, -1) : 0;
            $output = substr($sum, -1).$output;
        }
        if ($carry) {
            $output = $carry.$output;
        }

        $output = self::setScale($number1[0].$output, $scale);

        return self::stripLeadingZeroes($output);
    }

    /**
     * Divides a number by another.
     *
     * @param string $dividend   The number to be divided
     * @param string $divisor    The number to divide by
     * @param int    &$remainder The remainder from the division
     * @param int    $scale      The number of digits to return after the decimal
     *
     * @throws fValidationException When `$dividend` or `$divisor` is not a valid number
     *
     * @return string The quotient
     */
    private static function performDiv($dividend, $divisor, &$remainder = null, $scale = null)
    {
        [$dividend, $divisor] = self::normalize($dividend, $divisor);

        $dividend = self::stripLeadingZeroes($dividend);
        $divisor = self::stripLeadingZeroes($divisor);

        if (self::isZero($dividend)) {
            $remainder = '+0';

            return self::setScale('+0', $scale);
        }

        if (self::isZero($divisor)) {
            throw new fValidationException(
                'The divisor specified, %s, is zero, which is an invalid divisor',
                $divisor
            );
        }

        $sign = ($dividend[0] == $divisor[0]) ? '+' : '-';

        $after_decimal = 0;

        if (strpos($dividend, '.') !== false) {
            $dividend = str_replace('.', '', $dividend);
            $divisor = str_replace('.', '', $divisor);
        }

        // Here we make sure we can divide the dividend at least once
        if ($scale !== null) {
            for ($i = 0; $i < $scale; $i++) {
                $dividend .= '0';
                $after_decimal++;
            }
        }

        if (strlen($dividend) < strlen($divisor)) {
            $remainder = $dividend;

            return self::setScale('+0', $scale);
        }

        // Perform multiplication using Knuth's algorithm from Art of Computer Science Vol 2
        $u = '+'.substr($dividend, 1);
        $v = '+'.substr($divisor, 1);

        $n = strlen($v) - 1;
        $m = (strlen($u) - 1) - $n;

        // This is for single digit divisors
        if ($n == 1) {
            $n = strlen($u) - 1;
            $w = $sign.str_pad('', $n, '0');
            $r = 0;
            $j = 1;
            while ($j <= $n) {
                $w[$j] = floor((($r * 10) + $u[$j]) / $v[1]);
                $r = (($r * 10) + $u[$j]) % $v[1];
                $j++;
            }
            $quotient = $w;
            $remainder = '+'.$r;

        // This is for multi-digit divisors
        } else {
            $quotient = '0'.str_pad('', $m, '0');

            // Step D1
            $d = floor(10 / ($v[1] + 1));

            $u = self::performMul($u, $d);
            $v = self::performMul($v, $d);

            if (strlen($u) == strlen($dividend)) {
                $u = '0'.substr($u, 1);
            } else {
                $u = substr($u, 1);
            }

            // Step D2
            $j = 0;

            while ($j <= $m) {
                // Step D3
                $uj1 = (isset($u[$j + 1])) ? $u[$j + 1] : '0';

                if ($u[$j] == $v[1]) {
                    $q = 9;
                } else {
                    $q = floor((($u[$j] * 10) + $uj1) / $v[1]);
                }

                $uj2 = (isset($u[$j + 2])) ? $u[$j + 2] : '0';
                if ($v[2] * $q > (($u[$j] * 10) + $uj1 - ($q * $v[1])) * 10 + $uj2) {
                    $q--;
                    if ($v[2] * $q > (($u[$j] * 10) + $uj1 - ($q * $v[1])) * 10 + $uj2) {
                        $q--;
                    }
                }

                // Step D4
                $ujn = self::performSub(substr($u, $j, $n + 1), self::performMul($q, $v));
                while (strlen($ujn) - 1 < $n + 1) {
                    $ujn = $ujn[0].'0'.substr($ujn, 1);
                }
                while (strlen($ujn) - 1 > $n + 1) {
                    $ujn = $ujn[0].substr($ujn, 2);
                }
                $borrow = false;
                if ($ujn[0] == '-') {
                    $ujn = self::performAdd(self::performPow('10', $n + 1), $ujn);
                    $borrow = true;
                }
                while (strlen($ujn) - 1 > $n + 1) {
                    $ujn = $ujn[0].substr($ujn, 2);
                }

                // Step D5
                if ($borrow) {
                    // Step D6
                    $q--;
                    $ujn = substr(self::performAdd($v, $ujn), 1);
                }
                $u = substr($u, 0, $j).substr($ujn, 1).substr($u, $j + $n + 1);

                $quotient[$j] = $q;

                // Step D7
                $j++;
            }

            $remainder = self::performDiv(substr($u, 1 + $m, $n), $d);
        }

        if (strlen($quotient) < $after_decimal) {
            $quotient = str_pad($quotient, $after_decimal + 1, '0', STR_PAD_LEFT);
        }

        $integer = substr($quotient, 0, strlen($quotient) - $after_decimal);
        $fraction = substr($quotient, strlen($quotient) - $after_decimal);
        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        $quotient = $integer.$fraction;
        $quotient = self::stripLeadingZeroes($quotient);
        if (ctype_digit($quotient[0])) {
            $quotient = $sign.$quotient;
        }

        return $quotient;
    }

    /**
     * Multiplies two numbers.
     *
     * @param string $multiplicand The number to be multiplied
     * @param string $multiplier   The number of times to multiply the multiplicand
     * @param int    $scale        The number of digits after the decimal
     *
     * @return string The product
     */
    private static function performMul($multiplicand, $multiplier, $scale = null)
    {
        $multiplicand = (string) $multiplicand;
        $multiplier = (string) $multiplier;

        $multiplicand = self::fixSign($multiplicand);
        $multiplier = self::fixSign($multiplier);

        if (self::isZero($multiplicand) || self::isZero($multiplier)) {
            return self::setScale('+0', $scale);
        }

        $after_decimal = 0;

        if (strpos($multiplicand, '.') !== false) {
            $after_decimal += strlen($multiplicand) - (strpos($multiplicand, '.') + 1);
            $multiplicand = str_replace('.', '', $multiplicand);
        }

        if (strpos($multiplier, '.') !== false) {
            $after_decimal += strlen($multiplier) - (strpos($multiplier, '.') + 1);
            $multiplier = str_replace('.', '', $multiplier);
        }

        // Perform multiplication using Knuth's algorithm from Art of Computer Science Vol 2
        $n = strlen($multiplicand) - 1;
        $m = strlen($multiplier) - 1;

        $product = $multiplier.str_pad('', $n, '0');
        $product[0] = ($multiplicand[0] == $multiplier[0]) ? '+' : '-';

        $j = $m;

        while ($j > 0) {
            if ($multiplier[$j] == '0') {
                $product[$j] = '0';
            }

            $i = $n;
            $k = 0;

            while ($i > 0) {
                $t = ($multiplicand[$i] * $multiplier[$j]) + $product[$i + $j] + $k;
                $product[$i + $j] = $t % 10;
                $k = (int) $t / 10;
                $i--;
            }
            $product[$j] = $k;

            $j--;
        }

        $integer = substr($product, 0, strlen($product) - $after_decimal);
        $fraction = substr($product, strlen($product) - $after_decimal);
        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        $product = $integer.$fraction;

        $product = self::setScale($product, $scale);

        return self::stripLeadingZeroes($product);
    }

    /**
     * Calculates the integer power of a number.
     *
     * @param string     $number The number to raise to the power
     * @param string     $power  The power to raise to, must be between `−2,147,483,648` and `2,147,483,647`
     * @param null|mixed $scale
     *
     * @throws fValidationException When `$number` or `$power` is not a valid number or when `$power` is outside of the normal 32-bit integer range
     *
     * @return string The product
     */
    private static function performPow($number, $power, $scale = null)
    {
        $number = self::fixSign($number);
        $power = self::fixSign($power);

        if (self::cmp($power, '-2147483648') < 0 || self::cmp($power, '+2147483647') > 0) {
            throw new fValidationException(
                'The power specified, %1$s, is beyond the range of supported powers. Only powers between %2$s and %3$s are supported.',
                $power,
                '-2147483648',
                '2147483647'
            );
        }

        if (self::isZero($power)) {
            return '+1';
        }

        $negative_power = $power[0] == '-';

        $power = '+'.substr($power, 1);

        if ($power % 2 == 0) {
            $product = self::performPow(self::performMul($number, $number), $power / 2);
        } else {
            $product = self::performMul($number, self::performPow(self::performMul($number, $number), floor($power / 2)));
        }

        if ($negative_power) {
            $product = self::performDiv('+1', $product, $trash, $scale);
        }

        return self::setScale($product, $scale);
    }

    /**
     * Subtracts the second number from the first.
     *
     * @param string     $minuend    The number to subtract from
     * @param string     $subtrahend The number to subtract
     * @param null|mixed $scale
     *
     * @throws fValidationException When `$minuend` or `$subtrahend` is not a valid number
     *
     * @return string The difference
     */
    private static function performSub($minuend, $subtrahend, $scale = null)
    {
        [$minuend, $subtrahend] = self::normalize($minuend, $subtrahend);

        if ($subtrahend[0] == '-') {
            // If both are negative we are really subtracting the minuend from the subtrahend
            if ($minuend[0] == '-') {
                return self::performSub('+'.substr($subtrahend, 1), '+'.substr($minuend, 1), $scale);
            }
            // If the minuend is positive we are just doing an addition
            return self::performAdd('+'.substr($subtrahend, 1), '+'.substr($minuend, 1), $scale);
        }

        // When the minuend is negative and the subtrahend positive, they are added
        if ($minuend[0] == '-') {
            $sum = self::performAdd('+'.substr($minuend, 1), $subtrahend, $scale);

            return '-'.substr($sum, 1);
        }

        // If the subtrahend is bigger than the minuend, we reverse the
        // subtraction and reverse the sign of the minuend
        if (self::cmp($minuend, $subtrahend) < 0) {
            $diff = self::performSub('+'.substr($subtrahend, 1), '+'.substr($minuend, 1), $scale);
            $diff = substr($diff, 1);

            return ($minuend[0] == '+') ? '-'.$diff : '+'.$diff;
        }

        $borrow = 0;
        $output = '';
        for ($i = strlen($minuend) - 1; $i > 0; $i--) {
            if ($minuend[$i] == '.') {
                $output = '.'.$output;

                continue;
            }
            $diff = $minuend[$i] - $subtrahend[$i] - $borrow;
            $borrow = 0;
            while ($diff < 0) {
                $borrow++;
                $diff += 10;
            }
            $output = $diff.$output;
        }

        $output = self::setScale($minuend[0].$output, $scale);

        return self::stripLeadingZeroes($output);
    }

    /**
     * Sets the scale for a number.
     *
     * @param string $number The number to set the scale for
     * @param int    $scale  The number of digits to have after the decimal
     *
     * @return string The scaled number
     */
    private static function setScale($number, $scale)
    {
        if ($scale === null) {
            return $number;
        }

        $parts = explode('.', $number);
        $integer = $parts[0];
        $fraction = (isset($parts[1])) ? $parts[1] : '';

        if (strlen($fraction) > $scale) {
            $fraction = substr($fraction, 0, $scale);
        } else {
            $fraction = str_pad($fraction, $scale, '0', STR_PAD_RIGHT);
        }

        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        return $integer.$fraction;
    }

    /**
     * Strips the leading zeroes off of a number.
     *
     * @param string $number The number to strip
     *
     * @return string The number with leading zeroes stripped
     */
    private static function stripLeadingZeroes($number)
    {
        $number = (string) $number;

        if (ctype_digit($number[0]) || $number[0] == '.') {
            $sign = '';
        } else {
            $sign = $number[0];
            $number = substr($number, 1);
        }

        $number = ltrim($number, '0');
        if (strlen($number) == 0 || $number[0] == '.') {
            $number = '0'.$number;
        }

        return $sign.$number;
    }

    /**
     * Makes sure the scale is an int greater than `-1` - will return the current scale if the one passed is `NULL`.
     *
     * @param int $scale The scale to check
     *
     * @return int The number of digits after the decimal place
     */
    private function fixScale($scale)
    {
        $scale = ($scale !== null) ? (int) $scale : (int) $this->scale;
        if ($scale < 0) {
            $scale = 0;
        }

        return $scale;
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
