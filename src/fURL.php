<?php
/**
 * Provides functionality to retrieve and manipulate URL information.
 *
 * This class uses `$_SERVER['REQUEST_URI']` for all operations, meaning that
 * the original URL entered by the user will be used, or that any rewrites
 * will **not** be reflected by this class.
 *
 * @copyright  Copyright (c) 2007-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fURL
 *
 * @version    1.0.0b6
 * @changes    1.0.0b6  Added the `$max_length` parameter to ::makeFriendly() [wb, 2010-09-19]
 * @changes    1.0.0b5  Updated ::redirect() to not require a URL, using the current URL as the default [wb, 2009-07-29]
 * @changes    1.0.0b4  ::getDomain() now includes the port number if non-standard [wb, 2009-05-02]
 * @changes    1.0.0b3  ::makeFriendly() now changes _-_ to - and multiple _ to a single _ [wb, 2009-03-24]
 * @changes    1.0.0b2  Fixed ::makeFriendly() so that _ doesn't appear at the beginning of URLs [wb, 2009-03-22]
 * @changes    1.0.0b   The initial implementation [wb, 2007-06-14]
 */
class fURL
{
    // The following constants allow for nice looking callbacks to static methods
    public const get = 'fURL::get';

    public const getDomain = 'fURL::getDomain';

    public const getQueryString = 'fURL::getQueryString';

    public const getWithQueryString = 'fURL::getWithQueryString';

    public const makeFriendly = 'fURL::makeFriendly';

    public const redirect = 'fURL::redirect';

    public const removeFromQueryString = 'fURL::removeFromQueryString';

    public const replaceInQueryString = 'fURL::replaceInQueryString';

    /**
     * Forces use as a static class.
     *
     * @return fURL
     */
    private function __construct()
    {
    }

    /**
     * Returns the requested URL, does no include the domain name or query string.
     *
     * This will return the original URL requested by the user - ignores all
     * rewrites.
     *
     * @return string The requested URL without the query string
     */
    public static function get()
    {
        return preg_replace('#\?.*$#D', '', $_SERVER['REQUEST_URI']);
    }

    /**
     * Returns the current domain name, with protcol prefix. Port will be included if not 80 for HTTP or 443 for HTTPS.
     *
     * @return string The current domain name, prefixed by `http://` or `https://`
     */
    public static function getDomain()
    {
        $port = (isset($_SERVER['SERVER_PORT'])) ? $_SERVER['SERVER_PORT'] : null;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            return 'https://'.$_SERVER['SERVER_NAME'].($port && $port != 443 ? ':'.$port : '');
        }

        return 'http://'.$_SERVER['SERVER_NAME'].($port && $port != 80 ? ':'.$port : '');
    }

    /**
     * Returns the current query string, does not include parameters added by rewrites.
     *
     * @return string The query string
     */
    public static function getQueryString()
    {
        return preg_replace('#^[^?]*\??#', '', $_SERVER['REQUEST_URI']);
    }

    /**
     * Returns the current URL including query string, but without domain name - does not include query string parameters from rewrites.
     *
     * @return string The URL with query string
     */
    public static function getWithQueryString()
    {
        return $_SERVER['REQUEST_URI'];
    }

    /**
     * Changes a string into a URL-friendly string.
     *
     * @param string   $string     The string to convert
     * @param interger $max_length The maximum length of the friendly URL
     *
     * @return string The URL-friendly version of the string
     */
    public static function makeFriendly($string, $max_length = null)
    {
        $string = fHTML::decode(fUTF8::ascii($string));
        $string = strtolower(trim($string));
        $string = str_replace("'", '', $string);
        $string = preg_replace('#[^a-z0-9\-]+#', '_', $string);
        $string = preg_replace('#_{2,}#', '_', $string);
        $string = preg_replace('#_-_#', '-', $string);
        $string = preg_replace('#(^_+|_+$)#D', '', $string);

        $length = strlen($string);
        if ($max_length && $length > $max_length) {
            $last_pos = strrpos($string, '_', ($length - $max_length - 1) * -1);
            if ($last_pos < ceil($max_length / 2)) {
                $last_pos = $max_length;
            }
            $string = substr($string, 0, $last_pos);
        }

        return $string;
    }

    /**
     * Redirects to the URL specified, without requiring a full-qualified URL.
     *
     *  - If the URL starts with `/`, it is treated as an absolute path on the current site
     *  - If the URL starts with `http://` or `https://`, it is treated as a fully-qualified URL
     *  - If the URL starts with anything else, including a `?`, it is appended to the current URL
     *  - If the URL is ommitted, it is treated as the current URL
     *
     * @param string $url The url to redirect to
     *
     * @return never
     */
    public static function redirect($url = '')
    {
        if (strpos($url, '/') === 0) {
            $url = self::getDomain().$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $url = self::getDomain().self::get().$url;
        }

        // Strip the ? if there are no query string parameters
        if (substr($url, -1) == '?') {
            $url = substr($url, 0, -1);
        }

        header('Location: '.$url);

        exit($url);
    }

    /**
     * Removes one or more parameters from the query string.
     *
     * This method uses the query string from the original URL and will not
     * contain any parameters that are from rewrites.
     *
     * @param string $parameter A parameter to remove from the query string
     * @param  string ...
     *
     * @return string The query string with the parameter(s) specified removed, first character is `?`
     */
    public static function removeFromQueryString()
    {
        $parameters = func_get_args();

        parse_str(self::getQueryString(), $qs_array);

        foreach ($parameters as $parameter) {
            unset($qs_array[$parameter]);
        }

        return '?'.http_build_query($qs_array, '', '&');
    }

    /**
     * Replaces a value in the query string.
     *
     * This method uses the query string from the original URL and will not
     * contain any parameters that are from rewrites.
     *
     * @param array|string $parameter The query string parameter
     * @param array|string $value     The value to set the parameter to
     *
     * @return string The full query string with the parameter replaced, first char is `?`
     */
    public static function replaceInQueryString($parameter, $value)
    {
        parse_str(self::getQueryString(), $qs_array);

        settype($parameter, 'array');
        settype($value, 'array');

        if (count($parameter) != count($value)) {
            throw new fProgrammerException(
                "There are a different number of parameters and values.\nParameters:\n%1\$s\nValues\n%2\$s",
                $parameter,
                $value
            );
        }

        for ($i = 0; $i < count($parameter); $i++) {
            $qs_array[$parameter[$i]] = $value[$i];
        }

        return '?'.http_build_query($qs_array, '', '&');
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
