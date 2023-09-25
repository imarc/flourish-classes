<?php
/**
 * Provides cryptography functionality, including hashing, symmetric-key encryption and public-key encryption.
 *
 * @copyright  Copyright (c) 2007-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fCryptography
 *
 * @version    1.0.0b11
 * @changes    1.0.0b11  Updated class to use fCore::startErrorCapture() instead of `error_reporting()` [wb, 2010-08-09]
 * @changes    1.0.0b10  Added a missing parameter to an fProgrammerException in ::randomString() [wb, 2010-07-29]
 * @changes    1.0.0b9   Added ::hashHMAC() [wb, 2010-04-20]
 * @changes    1.0.0b8   Fixed ::seedRandom() to pass a directory instead of a file to [http://php.net/disk_free_space `disk_free_space()`] [wb, 2010-03-09]
 * @changes    1.0.0b7   SECURITY FIX: fixed issue with ::random() and ::randomString() not producing random output on OSX, made ::seedRandom() more robust [wb, 2009-10-06]
 * @changes    1.0.0b6   Changed ::symmetricKeyEncrypt() to throw an fValidationException when the $secret_key is less than 8 characters [wb, 2009-09-30]
 * @changes    1.0.0b5   Fixed a bug where some windows machines would throw an exception when generating random strings or numbers [wb, 2009-06-09]
 * @changes    1.0.0b4   Updated for new fCore API [wb, 2009-02-16]
 * @changes    1.0.0b3   Changed @ error suppression operator to `error_reporting()` calls [wb, 2009-01-26]
 * @changes    1.0.0b2   Backwards compatibility break - changed ::symmetricKeyEncrypt() to not encrypt the IV since we are using HMAC on it [wb, 2009-01-26]
 * @changes    1.0.0b    The initial implementation [wb, 2007-11-27]
 */
class fCryptography
{
    // The following constants allow for nice looking callbacks to static methods
    public const checkPasswordHash = 'fCryptography::checkPasswordHash';

    public const hashHMAC = 'fCryptography::hashHMAC';

    public const hashPassword = 'fCryptography::hashPassword';

    public const publicKeyDecrypt = 'fCryptography::publicKeyDecrypt';

    public const publicKeyEncrypt = 'fCryptography::publicKeyEncrypt';

    public const publicKeySign = 'fCryptography::publicKeySign';

    public const publicKeyVerify = 'fCryptography::publicKeyVerify';

    public const random = 'fCryptography::random';

    public const randomString = 'fCryptography::randomString';

    public const symmetricKeyDecrypt = 'fCryptography::symmetricKeyDecrypt';

    public const symmetricKeyEncrypt = 'fCryptography::symmetricKeyEncrypt';

    public const CIPHER_ALGO = 'RC4';

    /**
     * Forces use as a static class.
     *
     * @return fSecurity
     */
    private function __construct()
    {
    }

    /**
     * Checks a password against a hash created with ::hashPassword().
     *
     * @param string $password The password to check
     * @param string $hash     The hash to check against
     *
     * @return bool If the password matches the hash
     */
    public static function checkPasswordHash($password, $hash)
    {
        $salt = substr($hash, 29, 10);

        if (self::hashWithSalt($password, $salt) == $hash) {
            return true;
        }

        return false;
    }

    /**
     * Provides a pure PHP implementation of `hash_hmac()` for when the hash extension is not installed.
     *
     * @internal
     *
     * @param string $algorithm The hashing algorithm to use: `'md5'` or `'sha1'`
     * @param string $data      The data to create an HMAC for
     * @param string $key       The key to generate the HMAC with
     *
     * @return string The HMAC
     */
    public static function hashHMAC($algorithm, $data, $key)
    {
        if (function_exists('hash_hmac')) {
            return hash_hmac($algorithm, $data, $key);
        }

        // Algorithm from http://www.ietf.org/rfc/rfc2104.txt
        if (strlen($key) > 64) {
            $key = pack('H*', $algorithm($key));
        }
        $key = str_pad($key, 64, "\x0");

        $ipad = str_repeat("\x36", 64);
        $opad = str_repeat("\x5C", 64);

        return $algorithm(($key ^ $opad).pack('H*', $algorithm(($key ^ $ipad).$data)));
    }

    /**
     * Hashes a password using a loop of sha1 hashes and a salt, making rainbow table attacks infeasible.
     *
     * @param string $password The password to hash
     *
     * @return string An 80 character string of the Flourish fingerprint, salt and hashed password
     */
    public static function hashPassword($password)
    {
        $salt = self::randomString(10);

        return self::hashWithSalt($password, $salt);
    }

    /**
     * Decrypts ciphertext encrypted using public-key encryption via ::publicKeyEncrypt().
     *
     * A public key (X.509 certificate) is required for encryption and a
     * private key (PEM) is required for decryption.
     *
     * @param string $ciphertext       The content to be decrypted
     * @param string $private_key_file The path to a PEM-encoded private key
     * @param string $password         The password for the private key
     *
     * @throws fValidationException When the ciphertext appears to be corrupted
     *
     * @return string The decrypted plaintext
     */
    public static function publicKeyDecrypt($ciphertext, $private_key_file, $password)
    {
        self::verifyPublicKeyEnvironment();

        $private_key_resource = self::createPrivateKeyResource($private_key_file, $password);

        $elements = explode('#', $ciphertext);

        // We need to make sure this ciphertext came from here, otherwise we are gonna have issues decrypting it
        if (count($elements) != 4 || $elements[0] != 'fCryptography::public') {
            throw new fProgrammerException(
                'The ciphertext provided does not appear to have been encrypted using %s',
                __CLASS__.'::publicKeyEncrypt()'
            );
        }

        $encrypted_key = base64_decode($elements[1]);
        $ciphertext = base64_decode($elements[2]);
        $provided_hmac = $elements[3];

        $plaintext = '';
        $result = openssl_open(
            $ciphertext,
            $plaintext,
            $encrypted_key,
            $private_key_resource,
            static::CIPHER_ALGO
        );

        if ($result === false) {
            throw new fEnvironmentException(
                'There was an unknown error decrypting the ciphertext provided'
            );
        }

        $hmac = self::hashHMAC('sha1', $encrypted_key.$ciphertext, $plaintext);

        // By verifying the HMAC we ensure the integrity of the data
        if ($hmac != $provided_hmac) {
            throw new fValidationException(
                'The ciphertext provided appears to have been tampered with or corrupted'
            );
        }

        return $plaintext;
    }

    /**
     * Encrypts the passed data using public key encryption via OpenSSL.
     *
     * A public key (X.509 certificate) is required for encryption and a
     * private key (PEM) is required for decryption.
     *
     * @param string $plaintext       The content to be encrypted
     * @param string $public_key_file The path to an X.509 public key certificate
     *
     * @return string A base-64 encoded result containing a Flourish fingerprint and suitable for decryption using ::publicKeyDecrypt()
     */
    public static function publicKeyEncrypt($plaintext, $public_key_file)
    {
        self::verifyPublicKeyEnvironment();

        $public_key_resource = self::createPublicKeyResource($public_key_file);

        $ciphertext = '';
        $encrypted_keys = [];
        $result = openssl_seal(
            $plaintext,
            $ciphertext,
            $encrypted_keys,
            [$public_key_resource],
            static::CIPHER_ALGO,
        );

        if ($result === false) {
            throw new fEnvironmentException(
                'There was an unknown error encrypting the plaintext provided'
            );
        }

        $hmac = self::hashHMAC('sha1', $encrypted_keys[0].$ciphertext, $plaintext);

        return 'fCryptography::public#'.base64_encode($encrypted_keys[0]).'#'.base64_encode($ciphertext).'#'.$hmac;
    }

    /**
     * Creates a signature for plaintext to allow verification of the creator.
     *
     * A private key (PEM) is required for signing and a public key
     * (X.509 certificate) is required for verification.
     *
     * @param string $plaintext        The content to be signed
     * @param string $private_key_file The path to a PEM-encoded private key
     * @param string $password         The password for the private key
     *
     * @throws fValidationException When the private key is invalid
     *
     * @return string The base64-encoded signature suitable for verification using ::publicKeyVerify()
     */
    public static function publicKeySign($plaintext, $private_key_file, $password)
    {
        self::verifyPublicKeyEnvironment();

        $private_key_resource = self::createPrivateKeyResource($private_key_file, $password);

        $result = openssl_sign($plaintext, $signature, $private_key_resource);

        if (! $result) {
            throw new fEnvironmentException(
                'There was an unknown error signing the plaintext'
            );
        }

        return base64_encode($signature);
    }

    /**
     * Checks a signature for plaintext to verify the creator - works with ::publicKeySign().
     *
     * A private key (PEM) is required for signing and a public key
     * (X.509 certificate) is required for verification.
     *
     * @param string $plaintext       The content to check
     * @param string $signature       The base64-encoded signature for the plaintext
     * @param string $public_key_file The path to an X.509 public key certificate
     *
     * @return bool If the public key file is the public key of the user who signed the plaintext
     */
    public static function publicKeyVerify($plaintext, $signature, $public_key_file)
    {
        self::verifyPublicKeyEnvironment();

        $public_key_resource = self::createPublicKeyResource($public_key_file);

        $result = openssl_verify($plaintext, base64_decode($signature), $public_key_resource);

        if ($result === -1) {
            throw new fEnvironmentException(
                'There was an unknown error verifying the plaintext and signature against the public key specified'
            );
        }

        return ($result === 1) ? true : false;
    }

    /**
     * Generates a random number using [http://php.net/mt_rand mt_rand()] after ensuring a good PRNG seed.
     *
     * @param int $min The minimum number to return
     * @param int $max The maximum number to return
     *
     * @return int The psuedo-random number
     */
    public static function random($min = null, $max = null)
    {
        self::seedRandom();
        if ($min !== null || $max !== null) {
            return mt_rand($min, $max);
        }

        return mt_rand();
    }

    /**
     * Returns a random string of the type and length specified.
     *
     * @param int    $length The length of string to return
     * @param string $type   The type of string to return: `'alphanumeric'`, `'alpha'`, `'numeric'`, or `'hexadecimal'`
     *
     * @return string A random string of the type and length specified
     */
    public static function randomString($length, $type = 'alphanumeric')
    {
        if ($length < 1) {
            throw new fProgrammerException(
                'The length specified, %1$s, is less than the minimum of %2$s',
                $length,
                1
            );
        }

        switch ($type) {
            case 'alphanumeric':
                $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

                break;

            case 'alpha':
                $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

                break;

            case 'numeric':
                $alphabet = '0123456789';

                break;

            case 'hexadecimal':
                $alphabet = 'abcdef0123456789';

                break;

            default:
                throw new fProgrammerException(
                    'The type specified, %1$s, is invalid. Must be one of: %2$s.',
                    $type,
                    implode(', ', ['alphanumeric', 'alpha', 'numeric', 'hexadecimal'])
                );
        }

        $alphabet_length = strlen($alphabet);
        $output = '';

        for ($i = 0; $i < $length; $i++) {
            $output .= $alphabet[self::random(0, $alphabet_length - 1)];
        }

        return $output;
    }

    /**
     * Decrypts ciphertext encrypted using symmetric-key encryption via ::symmetricKeyEncrypt().
     *
     * Since this is symmetric-key cryptography, the same key is used for
     * encryption and decryption.
     *
     * @param string $ciphertext The content to be decrypted
     * @param string $secret_key The secret key to use for decryption
     *
     * @throws fValidationException When the ciphertext appears to be corrupted
     *
     * @return string The decrypted plaintext
     */
    public static function symmetricKeyDecrypt($ciphertext, $secret_key)
    {
        self::verifySymmetricKeyEnvironment();

        $elements = explode('#', $ciphertext);

        // We need to make sure this ciphertext came from here, otherwise we are gonna have issues decrypting it
        if (count($elements) != 4 || $elements[0] != 'fCryptography::symmetric') {
            throw new fProgrammerException(
                'The ciphertext provided does not appear to have been encrypted using %s',
                __CLASS__.'::symmetricKeyEncrypt()'
            );
        }

        $iv = base64_decode($elements[1]);
        $ciphertext = base64_decode($elements[2]);
        $provided_hmac = $elements[3];

        $hmac = self::hashHMAC('sha1', $iv.'#'.$ciphertext, $secret_key);

        // By verifying the HMAC we ensure the integrity of the data
        if ($hmac != $provided_hmac) {
            throw new fValidationException(
                'The ciphertext provided appears to have been tampered with or corrupted'
            );
        }

        // Set up the main encryption, we are gonna use AES-256 (also know as rijndael-256) in cipher feedback mode
        $module = mcrypt_module_open('rijndael-192', '', 'cfb', '');
        $key = substr(sha1($secret_key), 0, mcrypt_enc_get_key_size($module));
        mcrypt_generic_init($module, $key, $iv);

        fCore::startErrorCapture(E_WARNING);
        $plaintext = mdecrypt_generic($module, $ciphertext);
        fCore::stopErrorCapture();

        mcrypt_generic_deinit($module);
        mcrypt_module_close($module);

        return $plaintext;
    }

    /**
     * Encrypts the passed data using symmetric-key encryption.
     *
     * Since this is symmetric-key cryptography, the same key is used for
     * encryption and decryption.
     *
     * @param string $plaintext  The content to be encrypted
     * @param string $secret_key The secret key to use for encryption - must be at least 8 characters
     *
     * @throws fValidationException When the $secret_key is less than 8 characters long
     *
     * @return string An encrypted and base-64 encoded result containing a Flourish fingerprint and suitable for decryption using ::symmetricKeyDecrypt()
     */
    public static function symmetricKeyEncrypt($plaintext, $secret_key)
    {
        if (strlen($secret_key) < 8) {
            throw new fValidationException(
                'The secret key specified does not meet the minimum requirement of being at least %s characters long',
                8
            );
        }

        self::verifySymmetricKeyEnvironment();

        // Set up the main encryption, we are gonna use AES-192 (also know as rijndael-192)
        // in cipher feedback mode. Cipher feedback mode is chosen because no extra padding
        // is added, ensuring we always get the exact same plaintext out of the decrypt method
        $module = mcrypt_module_open('rijndael-192', '', 'cfb', '');
        $key = substr(sha1($secret_key), 0, mcrypt_enc_get_key_size($module));
        srand();
        $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($module), MCRYPT_RAND);

        // Finish the main encryption
        mcrypt_generic_init($module, $key, $iv);

        fCore::startErrorCapture(E_WARNING);
        $ciphertext = mcrypt_generic($module, $plaintext);
        fCore::stopErrorCapture();

        // Clean up the main encryption
        mcrypt_generic_deinit($module);
        mcrypt_module_close($module);

        // Here we are generating the HMAC for the encrypted data to ensure data integrity
        $hmac = self::hashHMAC('sha1', $iv.'#'.$ciphertext, $secret_key);

        // All of the data is then encoded using base64 to prevent issues with character sets
        $encoded_iv = base64_encode($iv);
        $encoded_ciphertext = base64_encode($ciphertext);

        // Indicate in the resulting encrypted data what the encryption tool was
        return 'fCryptography::symmetric#'.$encoded_iv.'#'.$encoded_ciphertext.'#'.$hmac;
    }

    /**
     * Create a private key resource based on a filename and password.
     *
     * @param string $private_key_file The path to a PEM-encoded private key
     * @param string $password         The password for the private key
     *
     * @throws fValidationException When the private key is invalid
     *
     * @return OpenSSLAsymmetricKey|resource The private key resource
     */
    private static function createPrivateKeyResource($private_key_file, $password)
    {
        if (! file_exists($private_key_file)) {
            throw new fProgrammerException(
                'The path to the PEM-encoded private key specified, %s, is not valid',
                $private_key_file
            );
        }
        if (! is_readable($private_key_file)) {
            throw new fEnvironmentException(
                'The PEM-encoded private key specified, %s, is not readable',
                $private_key_file
            );
        }

        $private_key = file_get_contents($private_key_file);
        $private_key_resource = openssl_pkey_get_private($private_key, $password);

        if ($private_key_resource === false) {
            throw new fValidationException(
                'The private key file specified, %s, does not appear to be a valid private key or the password provided is incorrect',
                $private_key_file
            );
        }

        return $private_key_resource;
    }

    /**
     * Create a public key resource based on a filename.
     *
     * @param string $public_key_file The path to an X.509 public key certificate
     *
     * @return OpenSSLAsymmetricKey The public key resource
     */
    private static function createPublicKeyResource($public_key_file)
    {
        if (! file_exists($public_key_file)) {
            throw new fProgrammerException(
                'The path to the X.509 certificate specified, %s, is not valid',
                $public_key_file
            );
        }
        if (! is_readable($public_key_file)) {
            throw new fEnvironmentException(
                'The X.509 certificate specified, %s, can not be read',
                $public_key_file
            );
        }

        $public_key = file_get_contents($public_key_file);
        $public_key_resource = openssl_pkey_get_public($public_key);

        if ($public_key_resource === false) {
            throw new fProgrammerException(
                'The public key certificate specified, %s, does not appear to be a valid certificate',
                $public_key_file
            );
        }

        return $public_key_resource;
    }

    /**
     * Performs a large iteration of hashing a string with a salt.
     *
     * @param string $source The string to hash
     * @param string $salt   The salt for the hash
     *
     * @return string An 80 character string of the Flourish fingerprint, salt and hashed password
     */
    private static function hashWithSalt($source, $salt)
    {
        $sha1 = sha1($salt.$source);
        for ($i = 0; $i < 1000; $i++) {
            $sha1 = sha1($sha1.(($i % 2 == 0) ? $source : $salt));
        }

        return 'fCryptography::password_hash#'.$salt.'#'.$sha1;
    }

    /**
     * Makes sure that the PRNG has been seeded with a fairly secure value.
     *
     * @return void
     */
    private static function seedRandom()
    {
        static $seeded = false;

        if ($seeded) {
            return;
        }

        fCore::startErrorCapture(E_WARNING);

        $bytes = null;

        // On linux/unix/solaris we should be able to use /dev/urandom
        if (! fCore::checkOS('windows') && $handle = fopen('/dev/urandom', 'rb')) {
            $bytes = fread($handle, 4);
            fclose($handle);

        // On windows we should be able to use the Cryptographic Application Programming Interface COM object
        } elseif (fCore::checkOS('windows') && class_exists('COM', false)) {
            try {
                // This COM object no longer seems to work on PHP 5.2.9+, no response on the bug report yet
                $capi = new COM('CAPICOM.Utilities.1');
                $bytes = base64_decode($capi->getrandom(4, 0));
                unset($capi);
            } catch (Exception $e) {
            }
        }

        // If we could not use the OS random number generators we get some of the most unique info we can
        if (! $bytes) {
            $string = microtime(true).uniqid('', true).implode('', stat(__FILE__)).disk_free_space(dirname(__FILE__));
            $bytes = substr(pack('H*', md5($string)), 0, 4);
        }

        fCore::stopErrorCapture();

        $seed = (int) (base_convert(bin2hex($bytes), 16, 10) - 2147483647);

        mt_srand($seed);

        $seeded = true;
    }

    /**
     * Makes sure the required PHP extensions and library versions are all correct.
     */
    private static function verifyPublicKeyEnvironment(): void
    {
        if (! extension_loaded('openssl')) {
            throw new fEnvironmentException(
                'The PHP %s extension is required, however is does not appear to be loaded',
                'openssl'
            );
        }
    }

    /**
     * Makes sure the required PHP extensions and library versions are all correct.
     */
    private static function verifySymmetricKeyEnvironment(): void
    {
        if (! extension_loaded('mcrypt')) {
            throw new fEnvironmentException(
                'The PHP %s extension is required, however is does not appear to be loaded',
                'mcrypt'
            );
        }
        if (! function_exists('mcrypt_module_open')) {
            throw new fEnvironmentException(
                'The cipher used, %1$s (also known as %2$s), requires libmcrypt version 2.4.x or newer. The version installed does not appear to meet this requirement.',
                'AES-192',
                'rijndael-192'
            );
        }
        if (! in_array('rijndael-192', mcrypt_list_algorithms())) {
            throw new fEnvironmentException(
                'The cipher used, %1$s (also known as %2$s), does not appear to be supported by the installed version of libmcrypt',
                'AES-192',
                'rijndael-192'
            );
        }
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
