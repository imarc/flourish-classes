<?php
/**
 * Represents a monetary value - USD are supported by default and others can be added via ::defineCurrency().
 *
 * @copyright  Copyright (c) 2008-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fMoney
 *
 * @version    1.0.0b3
 * @changes    1.0.0b3  Added the `$remove_zero_fraction` parameter to ::format() [wb, 2010-06-09]
 * @changes    1.0.0b2  Fixed a bug with calling ::format() when a format callback is set, fixed `NULL` `$element` handling in ::getCurrencyInfo() [wb, 2009-03-24]
 * @changes    1.0.0b   The initial implementation [wb, 2008-08-10]
 */
class fMoney
{
    // The following constants allow for nice looking callbacks to static methods
    public const defineCurrency = 'fMoney::defineCurrency';

    public const getCurrencies = 'fMoney::getCurrencies';

    public const getCurrencyInfo = 'fMoney::getCurrencyInfo';

    public const getDefaultCurrency = 'fMoney::getDefaultCurrency';

    public const registerFormatCallback = 'fMoney::registerFormatCallback';

    public const registerUnformatCallback = 'fMoney::registerUnformatCallback';

    public const reset = 'fMoney::reset';

    public const setDefaultCurrency = 'fMoney::setDefaultCurrency';

    /**
     * The number of decimal places to use for values.
     *
     * @var int
     */
    private static $currencies = [
        'USD' => [
            'name' => 'United States Dollar',
            'symbol' => '$',
            'precision' => 2,
            'value' => '1.00000000',
        ],
    ];

    /**
     * The ISO code (three letters, e.g. 'USD') for the default currency.
     *
     * @var string
     */
    private static $default_currency;

    /**
     * A callback to process all money values through.
     *
     * @var callable
     */
    private static $format_callback;

    /**
     * A callback to remove money formatting and return a decimal number.
     *
     * @var callable
     */
    private static $unformat_callback;

    /**
     * The raw monetary value.
     *
     * @var fNumber
     */
    private $amount;

    /**
     * The ISO code or the currency of this value.
     *
     * @var string
     */
    private $currency;

    /**
     * Creates the monetary to represent, with an optional currency.
     *
     * @param fNumber|string $amount   The monetary value to represent, should never be a float since those are imprecise
     * @param string         $currency The currency ISO code (three letters, e.g. `'USD'`) for this value
     *
     * @throws fValidationException When `$amount` is not a valid number/monetary value
     *
     * @return fMoney
     */
    public function __construct($amount, $currency = null)
    {
        if ($currency !== null && ! isset(self::$currencies[$currency])) {
            throw new fProgrammerException(
                'The currency specified, %1$s, is not a valid currency. Must be one of: %2$s.',
                $abbreviation,
                implode(', ', array_keys(self::$currencies))
            );
        }

        if ($currency === null && self::$default_currency === null) {
            throw new fProgrammerException(
                'No currency was specified and no default currency has been set'
            );
        }

        $this->currency = ($currency !== null) ? $currency : self::$default_currency;

        $precision = self::getCurrencyInfo($this->currency, 'precision');

        // Unformat any money value
        if (self::$unformat_callback !== null) {
            $amount = call_user_func(self::$unformat_callback, $amount, $this->currency);
        } else {
            $amount = str_replace(
                [
                    self::getCurrencyInfo($this->currency, 'symbol'),
                    ',',
                ],
                '',
                ($amount instanceof fNumber) ? $amount->__toString() : $amount
            );
        }

        $this->amount = new fNumber($amount, $precision);
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
     * Returns the monetary value without a currency symbol or thousand separator (e.g. `2000.12`).
     *
     * @return string The monetary value without currency symbol or thousands separator
     */
    public function __toString()
    {
        return $this->amount->__toString();
    }

    /**
     * Allows adding a new currency, or modifying an existing one.
     *
     * @param string $iso_code  The ISO code (three letters, e.g. `'USD'`) for the currency
     * @param string $name      The name of the currency
     * @param string $symbol    The symbol for the currency
     * @param int    $precision The number of digits after the decimal separator to store
     * @param string $value     The value of the currency relative to some common standard between all currencies
     */
    public static function defineCurrency($iso_code, $name, $symbol, $precision, $value): void
    {
        self::$currencies[$iso_code] = [
            'name' => $name,
            'symbol' => $symbol,
            'precision' => $precision,
            'value' => $value,
        ];
    }

    /**
     * Lists all of the defined currencies.
     *
     * @return array The 3 letter ISO codes for all of the defined currencies
     */
    public static function getCurrencies()
    {
        return array_keys(self::$currencies);
    }

    /**
     * Allows retrieving information about a currency.
     *
     * @param string $iso_code The ISO code (three letters, e.g. `'USD'`) for the currency
     * @param string $element  The element to retrieve: `'name'`, `'symbol'`, `'precision'`, `'value'`
     *
     * @return mixed An associative array of the currency info, or the element specified
     */
    public static function getCurrencyInfo($iso_code, $element = null)
    {
        if (! isset(self::$currencies[$iso_code])) {
            throw new fProgrammerException(
                'The currency specified, %1$s, is not a valid currency. Must be one of: %2$s.',
                $iso_code,
                implode(', ', array_keys(self::$currencies))
            );
        }

        if ($element === null) {
            return self::$currencies[$iso_code];
        }

        if (! isset(self::$currencies[$iso_code][$element])) {
            throw new fProgrammerException(
                'The element specified, %1$s, is not valid. Must be one of: %2$s.',
                $element,
                implode(', ', array_keys(self::$currencies[$iso_code]))
            );
        }

        return self::$currencies[$iso_code][$element];
    }

    /**
     * Gets the default currency.
     *
     * @return string The ISO code of the default currency
     */
    public static function getDefaultCurrency()
    {
        return self::$default_currency;
    }

    /**
     * Allows setting a callback to translate or modify any return values from ::format().
     *
     * @param callable $callback The callback to pass all fNumber objects to. Should accept an fNumber object, a string currency abbreviation and a boolean indicating if a zero-fraction should be removed - it should return a formatted string.
     */
    public static function registerFormatCallback($callback): void
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        self::$format_callback = $callback;
    }

    /**
     * Allows setting a callback to clean any formatted values so they can be passed to fNumber.
     *
     * @param callable $callback The callback to pass formatted strings to. Should accept a formatted string and a currency code and return a string suitable to passing to the fNumber constructor.
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
        self::$currencies = [
            'USD' => [
                'name' => 'United States Dollar',
                'symbol' => '$',
                'precision' => 2,
                'value' => '1.00000000',
            ],
        ];
        self::$default_currency = null;
        self::$format_callback = null;
        self::$unformat_callback = null;
    }

    /**
     * Sets the default currency to use when creating fMoney objects.
     *
     * @param string $iso_code The ISO code (three letters, e.g. `'USD'`) for the new default currency
     */
    public static function setDefaultCurrency($iso_code): void
    {
        if (! isset(self::$currencies[$iso_code])) {
            throw new fProgrammerException(
                'The currency specified, %1$s, is not a valid currency. Must be one of: %2$s.',
                $iso_code,
                implode(', ', array_keys(self::$currencies))
            );
        }

        self::$default_currency = $iso_code;
    }

    /**
     * Adds the passed monetary value to the current one.
     *
     * @param fMoney|int|string $addend The money object to add - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$addend` is not a valid number/monetary value
     *
     * @return fMoney The sum of the monetary values in this currency
     */
    public function add($addend)
    {
        $addend = $this->makeMoney($addend);
        $converted_addend = $addend->convert($this->currency)->amount;
        $precision = self::getCurrencyInfo($this->currency, 'precision');
        $new_amount = $this->amount->add($converted_addend, $precision + 1)->round($precision);

        return new self($new_amount, $this->currency);
    }

    /**
     * Splits the current value into multiple parts ensuring that the sum of the results is exactly equal to this amount.
     *
     * This method takes two or more parameters. The parameters should each be
     * fractions that when added together equal 1.
     *
     * @param fNumber|string $ratio1 The ratio of the first amount to this amount
     * @param fNumber|string $ratio2 The ratio of the second amount to this amount
     * @param  fNumber|string ...
     *
     * @throws fValidationException When one of the ratios is not a number
     *
     * @return array fMoney objects each with the appropriate ratio of the current amount
     */
    public function allocate($ratio1, $ratio2)
    {
        $ratios = func_get_args();

        $total = new fNumber('0', 10);
        foreach ($ratios as $ratio) {
            $total = $total->add($ratio);
        }

        if (! $total->eq('1.0')) {
            $ratio_values = [];
            foreach ($ratios as $ratio) {
                $ratio_values[] = ($ratio instanceof fNumber) ? $ratio->__toString() : (string) $ratio;
            }

            throw new fProgrammerException(
                'The ratios specified (%s) combined are not equal to 1',
                implode(', ', $ratio_values)
            );
        }

        $precision = self::getCurrencyInfo($this->currency, 'precision');

        if ($precision == 0) {
            $smallest_amount = new fNumber('1');
        } else {
            $smallest_amount = new fNumber('0.'.str_pad('', $precision - 1, '0').'1');
        }
        $smallest_money = new self($smallest_amount, $this->currency);

        $monies = [];
        $sum = new fNumber('0', $precision);

        foreach ($ratios as $ratio) {
            $new_amount = $this->amount->mul($ratio)->trunc($precision);
            $sum = $sum->add($new_amount, $precision + 1)->round($precision);
            $monies[] = new self($new_amount, $this->currency);
        }

        while ($sum->lt($this->amount)) {
            foreach ($monies as &$money) {
                if ($sum->eq($this->amount)) {
                    break 2;
                }
                $money = $money->add($smallest_money);
                $sum = $sum->add($smallest_amount, $precision + 1)->round($precision);
            }
        }

        return $monies;
    }

    /**
     * Converts this money amount to another currency.
     *
     * @param string $new_currency The ISO code (three letters, e.g. `'USD'`) for the new currency
     *
     * @return fMoney A new fMoney object representing this amount in the new currency
     */
    public function convert($new_currency)
    {
        if ($new_currency == $this->currency) {
            return $this;
        }

        if (! isset(self::$currencies[$new_currency])) {
            throw new fProgrammerException(
                'The currency specified, %1$s, is not a valid currency. Must be one of: %2$s.',
                $new_currency,
                implode(', ', array_keys(self::$currencies))
            );
        }

        $currency_value = self::getCurrencyInfo($this->currency, 'value');
        $new_currency_value = self::getCurrencyInfo($new_currency, 'value');
        $new_precision = self::getCurrencyInfo($new_currency, 'precision');

        $new_amount = $this->amount->mul($currency_value, 8)->div($new_currency_value, $new_precision + 1)->round($new_precision);

        return new self($new_amount, $new_currency);
    }

    /**
     * Checks to see if two monetary values are equal.
     *
     * @param fMoney|int|string $money The money object to compare to - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$money` is not a valid number/monetary value
     *
     * @return bool If the monetary values are equal
     */
    public function eq($money)
    {
        $money = $this->makeMoney($money);

        return $this->amount->eq($money->convert($this->currency)->amount);
    }

    /**
     * Formats the amount by preceeding the amount with the currency symbol and adding thousands separators.
     *
     * @param bool $remove_zero_fraction If `TRUE` and all digits after the decimal place are `0`, the decimal place and all zeros are removed
     *
     * @return string The formatted (and possibly converted) value
     */
    public function format($remove_zero_fraction = false)
    {
        if (self::$format_callback !== null) {
            return call_user_func(self::$format_callback, $this->amount, $this->currency, $remove_zero_fraction);
        }

        // We can't use number_format() since it takes a float and we have a
        // string that can not be losslessly converted to a float
        $number = $this->__toString();
        $parts = explode('.', $number);

        $integer = $parts[0];
        $fraction = (! isset($parts[1])) ? '' : $parts[1];

        $sign = '';
        if ($integer[0] == '-') {
            $sign = '-';
            $integer = substr($integer, 1);
        }

        $int_sections = [];
        for ($i = strlen($integer) - 3; $i > 0; $i -= 3) {
            array_unshift($int_sections, substr($integer, $i, 3));
        }
        array_unshift($int_sections, substr($integer, 0, $i + 3));

        $symbol = self::getCurrencyInfo($this->currency, 'symbol');
        $integer = implode(',', $int_sections);
        $fraction = (strlen($fraction)) ? '.'.$fraction : '';

        if ($remove_zero_fraction && rtrim($fraction, '.0') === '') {
            $fraction = '';
        }

        return $sign.$symbol.$integer.$fraction;
    }

    /**
     * Returns the fNumber object representing the amount.
     *
     * @return fNumber The amount of this monetary value
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Returns the currency ISO code.
     *
     * @return string The currency ISO code (three letters, e.g. `'USD'`)
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * Checks to see if this value is greater than the one passed.
     *
     * @param fMoney|int|string $money The money object to compare to - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$money` is not a valid number/monetary value
     *
     * @return bool If this value is greater than the one passed
     */
    public function gt($money)
    {
        $money = $this->makeMoney($money);

        return $this->amount->gt($money->convert($this->currency)->amount);
    }

    /**
     * Checks to see if this value is greater than or equal to the one passed.
     *
     * @param fMoney|int|string $money The money object to compare to - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$money` is not a valid number/monetary value
     *
     * @return bool If this value is greater than or equal to the one passed
     */
    public function gte($money)
    {
        $money = $this->makeMoney($money);

        return $this->amount->gte($money->convert($this->currency)->amount);
    }

    /**
     * Checks to see if this value is less than the one passed.
     *
     * @param fMoney|int|string $money The money object to compare to - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$money` is not a valid number/monetary value
     *
     * @return bool If this value is less than the one passed
     */
    public function lt($money)
    {
        $money = $this->makeMoney($money);

        return $this->amount->lt($money->convert($this->currency)->amount);
    }

    /**
     * Checks to see if this value is less than or equal to the one passed.
     *
     * @param fMoney|int|string $money The money object to compare to - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$money` is not a valid number/monetary value
     *
     * @return bool If this value is less than or equal to the one passed
     */
    public function lte($money)
    {
        $money = $this->makeMoney($money);

        return $this->amount->lte($money->convert($this->currency)->amount);
    }

    /**
     * Mupltiplies this monetary value times the number passed.
     *
     * @param fNumber|int|string $multiplicand The number of times to multiply this ammount - don't use a float since they are imprecise
     *
     * @throws fValidationException When `$multiplicand` is not a valid number
     *
     * @return fMoney The product of the monetary value and the multiplicand passed
     */
    public function mul($multiplicand)
    {
        $precision = self::getCurrencyInfo($this->currency, 'precision');
        $new_amount = $this->amount->mul($multiplicand, $precision + 1)->round($precision);

        return new self($new_amount, $this->currency);
    }

    /**
     * Subtracts the passed monetary value from the current one.
     *
     * @param fMoney|int|string $subtrahend The money object to subtract - a string or integer will be converted to the default currency (if defined)
     *
     * @throws fValidationException When `$subtrahend` is not a valid number/monetary value
     *
     * @return fMoney The difference of the monetary values in this currency
     */
    public function sub($subtrahend)
    {
        $subtrahend = $this->makeMoney($subtrahend);
        $converted_subtrahend = $subtrahend->convert($this->currency)->amount;
        $precision = self::getCurrencyInfo($this->currency, 'precision');
        $new_amount = $this->amount->sub($converted_subtrahend, $precision + 1)->round($precision);

        return new self($new_amount, $this->currency);
    }

    /**
     * Turns a string into an fMoney object if a default currency is defined.
     *
     * @param fMoney|int|string $money The value to convert to an fMoney object
     *
     * @throws fValidationException When `$money` is not a valid number/monetary value
     *
     * @return fMoney The converted value
     */
    private function makeMoney($money)
    {
        if ($money instanceof self) {
            return $money;
        }

        if (is_object($money) && is_callable([$money, '__toString'])) {
            $money = $money->__toString();
        } elseif (is_numeric($money) || is_object($money)) {
            $money = (string) $money;
        }

        if (! is_string($money)) {
            throw new fProgrammerException(
                'The money value specified, %s, is not an fMoney object, integer or string and is thus is invalid for this operation',
                $money
            );
        }

        if (! self::$default_currency) {
            throw new fProgrammerException(
                'A default currency must be set in order to convert strings or integers to fMoney objects on the fly'
            );
        }

        return new self($money);
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
