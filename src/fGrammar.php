<?php
/**
 * Provides english word inflection, notation conversion, grammar helpers and internationlization support.
 *
 * @copyright  Copyright (c) 2007-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fGrammar
 *
 * @version    1.0.0b13
 * @changes    1.0.0b13  Fixed the pluralization of video [wb, 2010-08-10]
 * @changes    1.0.0b12  Updated ::singularize() and ::pluralize() to be able to handle underscore_CamelCase [wb, 2010-08-06]
 * @changes    1.0.0b11  Fixed custom camelCase to underscore_notation rules [wb, 2010-06-23]
 * @changes    1.0.0b10  Removed `e` flag from preg_replace() calls [wb, 2010-06-08]
 * @changes    1.0.0b9   Fixed a bug with ::camelize() and human-friendly strings [wb, 2010-06-08]
 * @changes    1.0.0b8   Added the ::stem() method [wb, 2010-05-27]
 * @changes    1.0.0b7   Added the `$return_error` parameter to ::pluralize() and ::singularize() [wb, 2010-03-30]
 * @changes    1.0.0b6   Added missing ::compose() method [wb, 2010-03-03]
 * @changes    1.0.0b5   Fixed ::reset() to properly reset the singularization and pluralization rules [wb, 2009-10-28]
 * @changes    1.0.0b4   Added caching for various methods - provided significant performance boost to ORM [wb, 2009-06-15]
 * @changes    1.0.0b3   Changed replacement values in preg_replace() calls to be properly escaped [wb, 2009-06-11]
 * @changes    1.0.0b2   Fixed a bug where some words would lose capitalization with ::pluralize() and ::singularize() [wb, 2009-01-25]
 * @changes    1.0.0b    The initial implementation [wb, 2007-09-25]
 */
class fGrammar
{
    // The following constants allow for nice looking callbacks to static methods
    public const addCamelUnderscoreRule = 'fGrammar::addCamelUnderscoreRule';

    public const addHumanizeRule = 'fGrammar::addHumanizeRule';

    public const addSingularPluralRule = 'fGrammar::addSingularPluralRule';

    public const camelize = 'fGrammar::camelize';

    public const humanize = 'fGrammar::humanize';

    public const inflectOnQuantity = 'fGrammar::inflectOnQuantity';

    public const joinArray = 'fGrammar::joinArray';

    public const pluralize = 'fGrammar::pluralize';

    public const registerJoinArrayCallback = 'fGrammar::registerJoinArrayCallback';

    public const reset = 'fGrammar::reset';

    public const singularize = 'fGrammar::singularize';

    public const stem = 'fGrammar::stem';

    public const underscorize = 'fGrammar::underscorize';

    /**
     * Cache for plural <-> singular and underscore <-> camelcase.
     *
     * @var array
     */
    private static $cache = [
        'camelize' => [0 => [], 1 => []],
        'humanize' => [],
        'pluralize' => [],
        'singularize' => [],
        'underscorize' => [],
    ];

    /**
     * Custom rules for camelizing a string.
     *
     * @var array
     */
    private static $camelize_rules = [];

    /**
     * Custom rules for humanizing a string.
     *
     * @var array
     */
    private static $humanize_rules = [];

    /**
     * The callback to replace ::joinArray().
     *
     * @var callable
     */
    private static $join_array_callback;

    /**
     * Rules for plural to singular inflection of nouns.
     *
     * @var array
     */
    private static $plural_to_singular_rules = [
        '([ml])ice' => '\1ouse',
        '(media|info(rmation)?|news)$' => '\1',
        '(q)uizzes$' => '\1uiz',
        '(c)hildren$' => '\1hild',
        '(p)eople$' => '\1erson',
        '(m)en$' => '\1an',
        '((?!sh).)oes$' => '\1o',
        '((?<!o)[ieu]s|[ieuo]x)es$' => '\1',
        '([cs]h)es$' => '\1',
        '(ss)es$' => '\1',
        '([aeo]l)ves$' => '\1f',
        '([^d]ea)ves$' => '\1f',
        '(ar)ves$' => '\1f',
        '([nlw]i)ves$' => '\1fe',
        '([aeiou]y)s$' => '\1',
        '([^aeiou])ies$' => '\1y',
        '(la)ses$' => '\1s',
        '(.)s$' => '\1',
    ];

    /**
     * Rules for singular to plural inflection of nouns.
     *
     * @var array
     */
    private static $singular_to_plural_rules = [
        '([ml])ouse$' => '\1ice',
        '(media|info(rmation)?|news)$' => '\1',
        '(phot|log|vide)o$' => '\1os',
        '^(q)uiz$' => '\1uizzes',
        '(c)hild$' => '\1hildren',
        '(p)erson$' => '\1eople',
        '(m)an$' => '\1en',
        '([ieu]s|[ieuo]x)$' => '\1es',
        '([cs]h)$' => '\1es',
        '(ss)$' => '\1es',
        '([aeo]l)f$' => '\1ves',
        '([^d]ea)f$' => '\1ves',
        '(ar)f$' => '\1ves',
        '([nlw]i)fe$' => '\1ves',
        '([aeiou]y)$' => '\1s',
        '([^aeiou])y$' => '\1ies',
        '([^o])o$' => '\1oes',
        's$' => 'ses',
        '(.)$' => '\1s',
    ];

    /**
     * Custom rules for underscorizing a string.
     *
     * @var array
     */
    private static $underscorize_rules = [];

    /**
     * Forces use as a static class.
     *
     * @return fGrammar
     */
    private function __construct()
    {
    }

    /**
     * Adds a custom mapping of a non-humanized string to a humanized string for ::humanize().
     *
     * @param string $non_humanized_string The non-humanized string
     * @param string $humanized_string     The humanized string
     */
    public static function addHumanizeRule($non_humanized_string, $humanized_string): void
    {
        self::$humanize_rules[$non_humanized_string] = $humanized_string;

        self::$cache['humanize'] = [];
    }

    /**
     * Adds a custom `camelCase` to `underscore_notation` and `underscore_notation` to `camelCase` rule.
     *
     * @param string $camel_case          The lower `camelCase` version of the string
     * @param string $underscore_notation The `underscore_notation` version of the string
     */
    public static function addCamelUnderscoreRule($camel_case, $underscore_notation): void
    {
        $camel_case = strtolower($camel_case[0]).substr($camel_case, 1);
        self::$underscorize_rules[$camel_case] = $underscore_notation;
        self::$camelize_rules[$underscore_notation] = $camel_case;

        self::$cache['camelize'] = [0 => [], 1 => []];
        self::$cache['underscorize'] = [];
    }

    /**
     * Adds a custom singular to plural and plural to singular rule for ::pluralize() and ::singularize().
     *
     * @param string $singular The singular version of the noun
     * @param string $plural   The plural version of the noun
     */
    public static function addSingularPluralRule($singular, $plural): void
    {
        self::$singular_to_plural_rules = array_merge(
            [
                '^('.preg_quote($singular[0], '#').')'.preg_quote(substr($singular, 1), '#').'$' => '\1'.strtr(substr($plural, 1), ['\\' => '\\\\', '$' => '\\$']),
            ],
            self::$singular_to_plural_rules
        );
        self::$plural_to_singular_rules = array_merge(
            [
                '^('.preg_quote($plural[0], '#').')'.preg_quote(substr($plural, 1), '#').'$' => '\1'.strtr(substr($singular, 1), ['\\' => '\\\\', '$' => '\\$']),
            ],
            self::$plural_to_singular_rules
        );

        self::$cache['pluralize'] = [];
        self::$cache['singularize'] = [];
    }

    /**
     * Converts an `underscore_notation`, human-friendly or `camelCase` string to `camelCase`.
     *
     * @param string $string The string to convert
     * @param bool   $upper  If the camel case should be `UpperCamelCase`
     *
     * @return string The converted string
     */
    public static function camelize($string, $upper)
    {
        $upper = (int) $upper;
        if (isset(self::$cache['camelize'][$upper][$string])) {
            return self::$cache['camelize'][$upper][$string];
        }

        $original = $string;

        // Handle custom rules
        if (isset(self::$camelize_rules[$string])) {
            $string = self::$camelize_rules[$string];
            if ($upper) {
                $string = ucfirst($string);
            }
        } else {
            // Make a humanized string like underscore notation
            if (strpos($string, ' ') !== false) {
                $string = strtolower(preg_replace('#\s+#', '_', $string));
            }

            // Check to make sure this is not already camel case
            if (strpos($string, '_') === false) {
                if ($upper) {
                    $string = ucfirst($string);
                }

            // Handle underscore notation
            } else {
                $string[0] = strtolower($string[0]);
                if ($upper) {
                    $string = ucfirst($string);
                }
                $string = preg_replace_callback('#_([a-z0-9])#i', ['self', 'camelizeCallback'], $string);
            }
        }

        self::$cache['camelize'][$upper][$original] = $string;

        return $string;
    }

    /**
     * Makes an `underscore_notation`, `camelCase`, or human-friendly string into a human-friendly string.
     *
     * @param string $string The string to humanize
     *
     * @return string The converted string
     */
    public static function humanize($string)
    {
        if (isset(self::$cache['humanize'][$string])) {
            return self::$cache['humanize'][$string];
        }

        $original = $string;

        if (isset(self::$humanize_rules[$string])) {
            $string = self::$humanize_rules[$string];

        // If there is no space, it isn't already humanized
        } elseif (strpos($string, ' ') === false) {
            // If we don't have an underscore we probably have camelCase
            if (strpos($string, '_') === false) {
                $string = self::underscorize($string);
            }

            $string = preg_replace_callback(
                '/(\b(api|css|gif|html|id|jpg|js|mp3|pdf|php|png|sql|swf|url|xhtml|xml)\b|\b\w)/',
                ['self', 'camelizeCallback'],
                str_replace('_', ' ', $string)
            );
        }

        self::$cache['humanize'][$original] = $string;

        return $string;
    }

    /**
     * Returns the singular or plural form of the word or based on the quantity specified.
     *
     * @param mixed  $quantity                    The quantity (integer) or an array of objects to count
     * @param string $singular_form               The string to be returned for when `$quantity = 1`
     * @param string $plural_form                 The string to be returned for when `$quantity != 1`, use `%d` to place the quantity in the string
     * @param bool   $use_words_for_single_digits If the numbers 0 to 9 should be written out as words
     *
     * @return string
     */
    public static function inflectOnQuantity($quantity, $singular_form, $plural_form = null, $use_words_for_single_digits = false)
    {
        if ($plural_form === null) {
            $plural_form = self::pluralize($singular_form);
        }

        if (is_array($quantity)) {
            $quantity = count($quantity);
        }

        if ($quantity == 1) {
            return $singular_form;
        }
        $output = $plural_form;

        // Handle placement of the quantity into the output
        if (strpos($output, '%d') !== false) {
            if ($use_words_for_single_digits && $quantity < 10) {
                static $replacements = [];
                if (! $replacements) {
                    $replacements = [
                        0 => self::compose('zero'),
                        1 => self::compose('one'),
                        2 => self::compose('two'),
                        3 => self::compose('three'),
                        4 => self::compose('four'),
                        5 => self::compose('five'),
                        6 => self::compose('six'),
                        7 => self::compose('seven'),
                        8 => self::compose('eight'),
                        9 => self::compose('nine'),
                    ];
                }
                $quantity = $replacements[$quantity];
            }

            $output = str_replace('%d', $quantity, $output);
        }

        return $output;
    }

    /**
     * Returns the passed terms joined together using rule 2 from Strunk & White's 'The Elements of Style'.
     *
     * @param array  $strings An array of strings to be joined together
     * @param string $type    The type of join to perform, `'and'` or `'or'`
     *
     * @return string The terms joined together
     */
    public static function joinArray($strings, $type)
    {
        $valid_types = ['and', 'or'];
        if (! in_array($type, $valid_types)) {
            throw new fProgrammerException(
                'The type specified, %1$s, is invalid. Must be one of: %2$s.',
                $type,
                implode(', ', $valid_types)
            );
        }

        if (self::$join_array_callback) {
            return call_user_func(self::$join_array_callback, $strings, $type);
        }

        settype($strings, 'array');
        $strings = array_values($strings);

        switch (count($strings)) {
            case 0:
                return '';

                break;

            case 1:
                return $strings[0];

                break;

            case 2:
                return $strings[0].' '.$type.' '.$strings[1];

                break;

            default:
                $last_string = array_pop($strings);

                return implode(', ', $strings).' '.$type.' '.$last_string;

                break;
        }
    }

    /**
     * Returns the plural version of a singular noun.
     *
     * @param string $singular_noun The singular noun to pluralize
     * @param bool   $return_error  If this is `TRUE` and the noun can't be pluralized, `FALSE` will be returned instead
     *
     * @return string The pluralized noun
     */
    public static function pluralize($singular_noun, $return_error = false)
    {
        if (isset(self::$cache['pluralize'][$singular_noun])) {
            return self::$cache['pluralize'][$singular_noun];
        }

        $original = $singular_noun;
        $plural_noun = null;

        [$beginning, $singular_noun] = self::splitLastWord($singular_noun);
        foreach (self::$singular_to_plural_rules as $from => $to) {
            if (preg_match('#'.$from.'#iD', $singular_noun)) {
                $plural_noun = $beginning.preg_replace('#'.$from.'#iD', $to, $singular_noun);

                break;
            }
        }

        if (! $plural_noun) {
            if ($return_error) {
                self::$cache['pluralize'][$singular_noun] = false;

                return false;
            }

            throw new fProgrammerException('The noun specified could not be pluralized');
        }

        self::$cache['pluralize'][$original] = $plural_noun;

        return $plural_noun;
    }

    /**
     * Allows replacing the ::joinArray() function with a user defined function.
     *
     * This would be most useful for changing ::joinArray() to work with
     * languages other than English.
     *
     * @param callable $callback The function to replace ::joinArray() with - should accept the same parameters and return the same type
     */
    public static function registerJoinArrayCallback($callback): void
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        self::$join_array_callback = $callback;
    }

    /**
     * Resets the configuration of the class.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$cache = [
            'camelize' => [0 => [], 1 => []],
            'humanize' => [],
            'pluralize' => [],
            'singularize' => [],
            'underscorize' => [],
        ];
        self::$camelize_rules = [];
        self::$humanize_rules = [];
        self::$join_array_callback = null;
        self::$plural_to_singular_rules = [
            '([ml])ice' => '\1ouse',
            '(media|info(rmation)?|news)$' => '\1',
            '(q)uizzes$' => '\1uiz',
            '(c)hildren$' => '\1hild',
            '(p)eople$' => '\1erson',
            '(m)en$' => '\1an',
            '((?!sh).)oes$' => '\1o',
            '((?<!o)[ieu]s|[ieuo]x)es$' => '\1',
            '([cs]h)es$' => '\1',
            '(ss)es$' => '\1',
            '([aeo]l)ves$' => '\1f',
            '([^d]ea)ves$' => '\1f',
            '(ar)ves$' => '\1f',
            '([nlw]i)ves$' => '\1fe',
            '([aeiou]y)s$' => '\1',
            '([^aeiou])ies$' => '\1y',
            '(la)ses$' => '\1s',
            '(.)s$' => '\1',
        ];
        self::$singular_to_plural_rules = [
            '([ml])ouse$' => '\1ice',
            '(media|info(rmation)?|news)$' => '\1',
            '(phot|log|vide)o$' => '\1os',
            '^(q)uiz$' => '\1uizzes',
            '(c)hild$' => '\1hildren',
            '(p)erson$' => '\1eople',
            '(m)an$' => '\1en',
            '([ieu]s|[ieuo]x)$' => '\1es',
            '([cs]h)$' => '\1es',
            '(ss)$' => '\1es',
            '([aeo]l)f$' => '\1ves',
            '([^d]ea)f$' => '\1ves',
            '(ar)f$' => '\1ves',
            '([nlw]i)fe$' => '\1ves',
            '([aeiou]y)$' => '\1s',
            '([^aeiou])y$' => '\1ies',
            '([^o])o$' => '\1oes',
            's$' => 'ses',
            '(.)$' => '\1s',
        ];
    }

    /**
     * Returns the singular version of a plural noun.
     *
     * @param string $plural_noun  The plural noun to singularize
     * @param bool   $return_error If this is `TRUE` and the noun can't be pluralized, `FALSE` will be returned instead
     *
     * @return string The singularized noun
     */
    public static function singularize($plural_noun, $return_error = false)
    {
        if (isset(self::$cache['singularize'][$plural_noun])) {
            return self::$cache['singularize'][$plural_noun];
        }

        $original = $plural_noun;
        $singular_noun = null;

        [$beginning, $plural_noun] = self::splitLastWord($plural_noun);
        foreach (self::$plural_to_singular_rules as $from => $to) {
            if (preg_match('#'.$from.'#iD', $plural_noun)) {
                $singular_noun = $beginning.preg_replace('#'.$from.'#iD', $to, $plural_noun);

                break;
            }
        }

        if (! $singular_noun) {
            if ($return_error) {
                self::$cache['singularize'][$plural_noun] = false;

                return false;
            }

            throw new fProgrammerException('The noun specified could not be singularized');
        }

        self::$cache['singularize'][$original] = $singular_noun;

        return $singular_noun;
    }

    /**
     * Uses the Porter Stemming algorithm to create the stem of a word, which is useful for searching.
     *
     * See http://tartarus.org/~martin/PorterStemmer/ for details about the
     * algorithm.
     *
     * @param string $word The word to get the stem of
     *
     * @return string The stem of the word
     */
    public static function stem($word)
    {
        $s_v = '^([^aeiou][^aeiouy]*)?[aeiouy]';
        $mgr0 = $s_v.'[aeiou]*[^aeiou][^aeiouy]*';

        $s_v_regex = '#'.$s_v.'#';
        $mgr0_regex = '#'.$mgr0.'#';
        $meq1_regex = '#'.$mgr0.'([aeiouy][aeiou]*)?$#';
        $mgr1_regex = '#'.$mgr0.'[aeiouy][aeiou]*[^aeiou][^aeiouy]*#';

        $word = fUTF8::ascii($word);
        $word = strtolower($word);

        if (strlen($word) < 3) {
            return $word;
        }

        if ($word[0] == 'y') {
            $word = 'Y'.substr($word, 1);
        }

        // Step 1a
        $word = preg_replace('#^(.+?)(?:(ss|i)es|([^s])s)$#', '\1\2\3', $word);

        // Step 1b
        if (preg_match('#^(.+?)eed$#', $word, $match)) {
            if (preg_match($mgr0_regex, $match[1])) {
                $word = substr($word, 0, -1);
            }
        } elseif (preg_match('#^(.+?)(ed|ing)$#', $word, $match)) {
            if (preg_match($s_v_regex, $match[1])) {
                $word = $match[1];
                if (preg_match('#(at|bl|iz)$#', $word)) {
                    $word .= 'e';
                } elseif (preg_match('#([^aeiouylsz])\1$#', $word)) {
                    $word = substr($word, 0, -1);
                } elseif (preg_match('#^[^aeiou][^aeiouy]*[aeiouy][^aeiouwxy]$#', $word)) {
                    $word .= 'e';
                }
            }
        }

        // Step 1c
        if (substr($word, -1) == 'y') {
            $stem = substr($word, 0, -1);
            if (preg_match($s_v_regex, $stem)) {
                $word = $stem.'i';
            }
        }

        // Step 2
        if (preg_match('#^(.+?)(ational|tional|enci|anci|izer|bli|alli|entli|eli|ousli|ization|ation|ator|alism|iveness|fulness|ousness|aliti|iviti|biliti|logi)$#', $word, $match)) {
            if (preg_match($mgr0_regex, $match[1])) {
                $word = $match[1].strtr(
                    $match[2],
                    [
                        'ational' => 'ate',  'tional' => 'tion', 'enci' => 'ence',
                        'anci' => 'ance', 'izer' => 'ize',  'bli' => 'ble',
                        'alli' => 'al',   'entli' => 'ent',  'eli' => 'e',
                        'ousli' => 'ous',  'ization' => 'ize',  'ation' => 'ate',
                        'ator' => 'ate',  'alism' => 'al',   'iveness' => 'ive',
                        'fulness' => 'ful',  'ousness' => 'ous',  'aliti' => 'al',
                        'iviti' => 'ive',  'biliti' => 'ble',  'logi' => 'log',
                    ]
                );
            }
        }

        // Step 3
        if (preg_match('#^(.+?)(icate|ative|alize|iciti|ical|ful|ness)$#', $word, $match)) {
            if (preg_match($mgr0_regex, $match[1])) {
                $word = $match[1].strtr(
                    $match[2],
                    [
                        'icate' => 'ic', 'ative' => '', 'alize' => 'al', 'iciti' => 'ic',
                        'ical' => 'ic', 'ful' => '', 'ness' => '',
                    ]
                );
            }
        }

        // Step 4
        if (preg_match('#^(.+?)(al|ance|ence|er|ic|able|ible|ant|ement|ment|ent|ou|ism|ate|iti|ous|ive|ize|(?<=[st])ion)$#', $word, $match) && preg_match($mgr1_regex, $match[1])) {
            $word = $match[1];
        }

        // Step 5
        if (substr($word, -1) == 'e') {
            $stem = substr($word, 0, -1);
            if (preg_match($mgr1_regex, $stem)) {
                $word = $stem;
            } elseif (preg_match($meq1_regex, $stem) && ! preg_match('#^[^aeiou][^aeiouy]*[aeiouy][^aeiouwxy]$#', $stem)) {
                $word = $stem;
            }
        }

        if (preg_match('#ll$#', $word) && preg_match($mgr1_regex, $word)) {
            $word = substr($word, 0, -1);
        }

        if ($word[0] == 'Y') {
            $word = 'y'.substr($word, 1);
        }

        return $word;
    }

    /**
     * Converts a `camelCase`, human-friendly or `underscore_notation` string to `underscore_notation`.
     *
     * @param string $string The string to convert
     *
     * @return string The converted string
     */
    public static function underscorize($string)
    {
        if (isset(self::$cache['underscorize'][$string])) {
            return self::$cache['underscorize'][$string];
        }

        $original = $string;
        $string = strtolower($string[0]).substr($string, 1);

        // Handle custom rules
        if (isset(self::$underscorize_rules[$string])) {
            $string = self::$underscorize_rules[$string];

        // If the string is already underscore notation then leave it
        } elseif (strpos($string, '_') !== false && strtolower($string) == $string) {
        // Allow humanized string to be passed in
        } elseif (strpos($string, ' ') !== false) {
            $string = strtolower(preg_replace('#\s+#', '_', $string));
        } else {
            do {
                $old_string = $string;
                $string = preg_replace('/([a-zA-Z])([0-9])/', '\1_\2', $string);
                $string = preg_replace('/([a-z0-9A-Z])([A-Z])/', '\1_\2', $string);
            } while ($old_string != $string);

            $string = strtolower($string);
        }

        self::$cache['underscorize'][$original] = $string;

        return $string;
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
     * A callback used by ::camelize() to handle converting underscore to camelCase.
     *
     * @param array $match The regular expression match
     *
     * @return string The value to replace the string with
     */
    private static function camelizeCallback($match)
    {
        return strtoupper($match[1]);
    }

    /**
     * Splits the last word off of a `camelCase` or `underscore_notation` string.
     *
     * @param string $string The string to split the word from
     *
     * @return array The first element is the beginning part of the string, the second element is the last word
     */
    private static function splitLastWord($string)
    {
        // Handle strings with spaces in them
        if (strpos($string, ' ') !== false) {
            return [substr($string, 0, strrpos($string, ' ') + 1), substr($string, strrpos($string, ' ') + 1)];
        }

        // Handle underscore notation
        if ($string == self::underscorize($string)) {
            if (strpos($string, '_') === false) {
                return ['', $string];
            }

            return [substr($string, 0, strrpos($string, '_') + 1), substr($string, strrpos($string, '_') + 1)];
        }

        // Handle camel case
        if (preg_match('#(.*)((?<=[a-zA-Z_]|^)(?:[0-9]+|[A-Z][a-z]*)|(?<=[0-9A-Z_]|^)(?:[A-Z][a-z]*))$#D', $string, $match)) {
            return [$match[1], $match[2]];
        }

        return ['', $string];
    }
}

/*
 * Copyright (c) 2007-2010 Will Bond <will@flourishlib.com>
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
