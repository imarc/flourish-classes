<?php
/**
 * Provides encoding and decoding for JSON.
 *
 * This class is a compatibility class for the
 * [http://php.net/json json extension] on servers with PHP 5.0 or 5.1, or
 * servers with the json extension compiled out.
 *
 * This class will handle JSON values that are not contained in an array or
 * object - such values are not valid according to the JSON spec, but the
 * functionality is included for compatiblity with the json extension.
 *
 * @copyright  Copyright (c) 2008-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fJSON
 *
 * @version    1.0.0b6
 * @changes    1.0.0b6  Removed `e` flag from preg_replace() calls [wb, 2010-06-08]
 * @changes    1.0.0b5  Added the ::output() method [wb, 2010-03-15]
 * @changes    1.0.0b4  Fixed a bug with ::decode() where JSON objects could lose all but the first key: value pair [wb, 2009-05-06]
 * @changes    1.0.0b3  Updated the class to be consistent with PHP 5.2.9+ for encoding and decoding invalid data [wb, 2009-05-04]
 * @changes    1.0.0b2  Changed @ error suppression operator to `error_reporting()` calls [wb, 2009-01-26]
 * @changes    1.0.0b   The initial implementation [wb, 2008-07-12]
 */
class fJSON
{
    // The following constants allow for nice looking callbacks to static methods
    public const decode = 'fJSON::decode';

    public const encode = 'fJSON::encode';

    public const output = 'fJSON::output';

    public const sendHeader = 'fJSON::sendHeader';

    /**
     * An abstract representation of [.
     *
     * @internal
     *
     * @var int
     */
    public const J_ARRAY_OPEN = 0;

    /**
     * An abstract representation of , in a JSON array.
     *
     * @internal
     *
     * @var int
     */
    public const J_ARRAY_COMMA = 1;

    /**
     * An abstract representation of ].
     *
     * @internal
     *
     * @var int
     */
    public const J_ARRAY_CLOSE = 2;

    /**
     * An abstract representation of {.
     *
     * @internal
     *
     * @var int
     */
    public const J_OBJ_OPEN = 3;

    /**
     * An abstract representation of a JSON object key.
     *
     * @internal
     *
     * @var int
     */
    public const J_KEY = 4;

    /**
     * An abstract representation of :.
     *
     * @internal
     *
     * @var int
     */
    public const J_COLON = 5;

    /**
     * An abstract representation of , in a JSON object.
     *
     * @internal
     *
     * @var int
     */
    public const J_OBJ_COMMA = 6;

    /**
     * An abstract representation of }.
     *
     * @internal
     *
     * @var int
     */
    public const J_OBJ_CLOSE = 7;

    /**
     * An abstract representation of an integer.
     *
     * @internal
     *
     * @var int
     */
    public const J_INTEGER = 8;

    /**
     * An abstract representation of a floating value.
     *
     * @internal
     *
     * @var int
     */
    public const J_FLOAT = 9;

    /**
     * An abstract representation of a boolean true.
     *
     * @internal
     *
     * @var int
     */
    public const J_TRUE = 10;

    /**
     * An abstract representation of a boolean false.
     *
     * @internal
     *
     * @var int
     */
    public const J_FALSE = 11;

    /**
     * An abstract representation of null.
     *
     * @internal
     *
     * @var int
     */
    public const J_NULL = 12;

    /**
     * An abstract representation of a string.
     *
     * @internal
     *
     * @var int
     */
    public const J_STRING = 13;

    /**
     * An array of special characters in JSON strings.
     *
     * @var array
     */
    private static $control_character_map = [
        '"' => '\"', '\\' => '\\\\', '/' => '\/', "\x8" => '\b',
        "\xC" => '\f', "\n" => '\n',   "\r" => '\r', "\t" => '\t',
    ];

    /**
     * An array of what values are allowed after other values.
     *
     * @internal
     *
     * @var array
     */
    private static $next_values = [
        self::J_ARRAY_OPEN => [
            self::J_ARRAY_OPEN => true,
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_OPEN => true,
            self::J_INTEGER => true,
            self::J_FLOAT => true,
            self::J_TRUE => true,
            self::J_FALSE => true,
            self::J_NULL => true,
            self::J_STRING => true,
        ],
        self::J_ARRAY_COMMA => [
            self::J_ARRAY_OPEN => true,
            self::J_OBJ_OPEN => true,
            self::J_INTEGER => true,
            self::J_FLOAT => true,
            self::J_TRUE => true,
            self::J_FALSE => true,
            self::J_NULL => true,
            self::J_STRING => true,
        ],
        self::J_ARRAY_CLOSE => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_OBJ_OPEN => [
            self::J_OBJ_CLOSE => true,
            self::J_KEY => true,
        ],
        self::J_KEY => [
            self::J_COLON => true,
        ],
        self::J_OBJ_COMMA => [
            self::J_KEY => true,
        ],
        self::J_COLON => [
            self::J_ARRAY_OPEN => true,
            self::J_OBJ_OPEN => true,
            self::J_INTEGER => true,
            self::J_FLOAT => true,
            self::J_TRUE => true,
            self::J_FALSE => true,
            self::J_NULL => true,
            self::J_STRING => true,
        ],
        self::J_OBJ_CLOSE => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_INTEGER => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_FLOAT => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_TRUE => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_FALSE => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_NULL => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
        self::J_STRING => [
            self::J_ARRAY_CLOSE => true,
            self::J_OBJ_CLOSE => true,
            self::J_ARRAY_COMMA => true,
            self::J_OBJ_COMMA => true,
        ],
    ];

    /**
     * Forces use as a static class.
     *
     * @return fJSON
     */
    private function __construct()
    {
    }

    /**
     * Decodes a JSON string into native PHP data types.
     *
     * This function is very strict about the format of JSON. If the string is
     * not a valid JSON string, `NULL` will be returned.
     *
     * @param string $json  This should be the name of a related class
     * @param bool   $assoc If this is TRUE, JSON objects will be represented as an assocative array instead of a `stdClass` object
     *
     * @return array|stdClass A PHP equivalent of the JSON string
     */
    public static function decode($json, $assoc = false)
    {
        if (! is_string($json) && ! is_numeric($json)) {
            return;
        }

        $json = trim($json);

        if ($json === '') {
            return;
        }

        // If the json is an array or object, we can rely on the php function
        if (function_exists('json_decode') && ($json[0] == '[' || $json[0] == '{' || version_compare(PHP_VERSION, '5.2.9', '>='))) {
            return json_decode($json, $assoc);
        }

        preg_match_all('~\[|                                     # Array begin
                         \]|                                     # Array end
                         {|                                      # Object begin
                         }|                                      # Object end
                         -?(?:0|[1-9]\d*)                        # Float
                             (?:\.\d*(?:[eE][+\-]?\d+)?|
                             (?:[eE][+\-]?\d+))|
                         -?(?:0|[1-9]\d*)|                       # Integer
                         true|                                   # True
                         false|                                  # False
                         null|                                   # Null
                         ,|                                      # Member separator for arrays and objects
                         :|                                      # Value separator for objects
                         "(?:(?:(?!\\\\u)[^\\\\"\n\b\f\r\t]+)|   # String
                             \\\\\\\\|
                             \\\\/|
                             \\\\"|
                             \\\\b|
                             \\\\f|
                             \\\\n|
                             \\\\r|
                             \\\\t|
                             \\\\u[0-9a-fA-F]{4})*"|
                         \s+                                     # Whitespace
                         ~x', $json, $matches);

        $matched_length = 0;
        $stack = [];
        $last = null;
        $last_key = null;
        $output = null;
        $container = null;

        if (count($matches) == 1 && strlen($matches[0][0]) == strlen($json)) {
            $match = $matches[0][0];
            $stack = [];
            $type = self::getElementType($stack, self::J_ARRAY_OPEN, $match);
            $element = self::scalarize($type, $match);
            if ($match !== $element) {
                return $element;
            }
        }

        if ($json[0] != '[' && $json[0] != '{') {
            return;
        }

        foreach ($matches[0] as $match) {
            if ($matched_length == 0) {
                if ($match == '[') {
                    $output = [];
                    $last = self::J_ARRAY_OPEN;
                } else {
                    $output = ($assoc) ? [] : new stdClass();
                    $last = self::J_OBJ_OPEN;
                }
                $stack[] = [$last, &$output];
                $container = &$output;

                $matched_length = 1;

                continue;
            }

            $matched_length += strlen($match);

            // Whitespace can be skipped over
            if (ctype_space($match)) {
                continue;
            }

            $type = self::getElementType($stack, $last, $match);

            // An invalid sequence will cause parsing to stop
            if (! isset(self::$next_values[$last][$type])) {
                break;
            }

            // Decode the data values
            $match = self::scalarize($type, $match);

            // If the element is not a value, record some info and continue
            if ($type == self::J_COLON
                  || $type == self::J_OBJ_COMMA
                  || $type == self::J_ARRAY_COMMA
                  || $type == self::J_KEY) {
                $last = $type;
                if ($type == self::J_KEY) {
                    $last_key = $match;
                }

                continue;
            }

            // This flag is used to indicate if an array or object is being added and thus
            // if the container reference needs to be changed to the current match
            $ref_match = false;

            // Closing an object or array
            if ($type == self::J_OBJ_CLOSE || $type == self::J_ARRAY_CLOSE) {
                array_pop($stack);
                if (count($stack) == 0) {
                    break;
                }
                $new_container = end($stack);
                $container = &$new_container[1];
                $last = $type;

                continue;
            }

            // Opening a new object or array requires some references to keep
            // track of what the current container is
            if ($type == self::J_OBJ_OPEN) {
                $match = ($assoc) ? [] : new stdClass();
                $ref_match = true;
            }
            if ($type == self::J_ARRAY_OPEN) {
                $match = [];
                $ref_match = true;
            }

            if ($ref_match) {
                $stack[] = [$type, &$match];
                $stack_end = end($stack);
            }

            // Here we assign the value. This code is kind of crazy because
            // we have to keep track of the current container by references
            // so we can traverse back down the stack as we move out of
            // nested arrays and objects
            if ($last == self::J_COLON && ! $assoc) {
                if ($last_key == '') {
                    $last_key = '_empty_';
                }
                if ($ref_match) {
                    $container->{$last_key} = &$stack_end[1];
                    $container = &$stack_end[1];
                } else {
                    $container->{$last_key} = $match;
                }
            } elseif ($last == self::J_COLON) {
                if ($ref_match) {
                    $container[$last_key] = &$stack_end[1];
                    $container = &$stack_end[1];
                } else {
                    $container[$last_key] = $match;
                }
            } else {
                if ($ref_match) {
                    $container[] = &$stack_end[1];
                    $container = &$stack_end[1];
                } else {
                    $container[] = $match;
                }
            }

            if ($last == self::J_COLON) {
                $last_key = null;
            }
            $last = $type;
            unset($match);
        }

        if ($matched_length != strlen($json) || count($stack) > 0) {
            return;
        }

        return $output;
    }

    /**
     * Encodes a PHP value into a JSON string.
     *
     * @param mixed $value The PHP value to encode
     *
     * @return false|null|string The JSON string that is equivalent to the PHP value
     */
    public static function encode($value): string|false|null
    {
        if (is_resource($value)) {
            return 'null';
        }

        if (function_exists('json_encode')) {
            return json_encode($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return ($value) ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_string($value)) {
            if (! preg_match('#^.*$#usD', $value)) {
                return 'null';
            }

            $char_array = fUTF8::explode($value);

            $output = '"';
            foreach ($char_array as $char) {
                if (isset(self::$control_character_map[$char])) {
                    $output .= self::$control_character_map[$char];
                } elseif (strlen($char) < 2) {
                    $output .= $char;
                } else {
                    $output .= '\u'.substr(strtolower(fUTF8::ord($char)), 2);
                }
            }
            $output .= '"';

            return $output;
        }

        // Detect if an array is associative, which would mean it needs to be encoded as an object
        $is_assoc_array = false;
        if (is_array($value) && $value) {
            $looking_for = 0;
            foreach ($value as $key => $val) {
                if (! is_numeric($key) || $key != $looking_for) {
                    $is_assoc_array = true;

                    break;
                }
                $looking_for++;
            }
        }

        if (is_object($value) || $is_assoc_array) {
            $output = '{';
            $members = [];

            foreach ($value as $key => $val) {
                $members[] = self::encode((string) $key).':'.self::encode($val);
            }

            $output .= implode(',', $members);
            $output .= '}';

            return $output;
        }

        if (is_array($value)) {
            $output = '[';
            $members = [];

            foreach ($value as $key => $val) {
                $members[] = self::encode($val);
            }

            $output .= implode(',', $members);
            $output .= ']';

            return $output;
        }
    }

    /**
     * Sets the proper `Content-Type` header and outputs the value, encoded as JSON.
     *
     * @param mixed $value The PHP value to output as JSON
     */
    public static function output($value): void
    {
        self::sendHeader();
        echo self::encode($value);
    }

    /**
     * Sets the proper `Content-Type` header for UTF-8 encoded JSON.
     */
    public static function sendHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * Determines the type of a parser JSON element.
     *
     * @param array  &$stack  The stack of arrays/objects being parsed
     * @param int    $last    The type of the last element parsed
     * @param string $element The element being detected
     *
     * @return int The element type
     */
    private static function getElementType(&$stack, $last, $element)
    {
        if ($element == '[') {
            return self::J_ARRAY_OPEN;
        }

        if ($element == ']') {
            return self::J_ARRAY_CLOSE;
        }

        if ($element == '{') {
            return self::J_OBJ_OPEN;
        }

        if ($element == '}') {
            return self::J_OBJ_CLOSE;
        }

        if (ctype_digit($element)) {
            return self::J_INTEGER;
        }

        if (is_numeric($element)) {
            return self::J_FLOAT;
        }

        if ($element == 'true') {
            return self::J_TRUE;
        }

        if ($element == 'false') {
            return self::J_FALSE;
        }

        if ($element == 'null') {
            return self::J_NULL;
        }

        $last_stack = end($stack);
        if ($element == ',' && $last_stack[0] == self::J_ARRAY_OPEN) {
            return self::J_ARRAY_COMMA;
        }

        if ($element == ',') {
            return self::J_OBJ_COMMA;
        }

        if ($element == ':') {
            return self::J_COLON;
        }

        if ($last == self::J_OBJ_OPEN || $last == self::J_OBJ_COMMA) {
            return self::J_KEY;
        }

        return self::J_STRING;
    }

    /**
     * Created a unicode code point from a JS escaped unicode character.
     *
     * @param array $match A regex match containing the 4 digit code referenced by the key `1`
     *
     * @return string The U+{digits} unicode code point
     */
    private static function makeUnicodeCodePoint($match)
    {
        return fUTF8::chr('U+'.$match[1]);
    }

    /**
     * Decodes a scalar value.
     *
     * @param int    $type    The type of the element
     * @param string $element The element to be converted to a scalar
     *
     * @return mixed The scalar value or the original string of the element
     */
    private static function scalarize($type, $element)
    {
        if ($type == self::J_INTEGER) {
            $element = (int) $element;
        }
        if ($type == self::J_FLOAT) {
            $element = (float) $element;
        }
        if ($type == self::J_FALSE) {
            $element = false;
        }
        if ($type == self::J_TRUE) {
            $element = true;
        }
        if ($type == self::J_NULL) {
            $element = null;
        }
        if ($type == self::J_STRING || $type == self::J_KEY) {
            $element = substr($element, 1, -1);
            $element = strtr($element, array_flip(self::$control_character_map));
            $element = preg_replace_callback('#\\\\u([0-9a-fA-F]{4})#', ['self', 'makeUnicodeCodePoint'], $element);
        }

        return $element;
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
