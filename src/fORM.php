<?php
/**
 * Dynamically handles many centralized object-relational mapping tasks.
 *
 * @copyright  Copyright (c) 2007-2010 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 *
 * @see       http://flourishlib.com/fORM
 *
 * @version    1.0.0b25
 * @changes    1.0.0b26  Adds setClassNamesapce method for namespace fActiveRecord classes [sf, 2023-09-19]
 * @changes    1.0.0b25  Checks in registerHookCallback() if the callback has already been registered before registering it [sf, 2023-03-21]
 * @changes    1.0.0b24  Backwards Compatibility Break - Callbacks registered via ::registerRecordSetMethod() should now accept the `$method_name` in the position where the `$pointer` parameter used to be passed [wb, 2010-09-28]
 * @changes    1.0.0b23  Added the `'pre::replicate()'`, `'post::replicate()'` and `'cloned::replicate()'` hooks [wb, 2010-09-07]
 * @changes    1.0.0b22  Internal Backwards Compatibility Break - changed ::parseMethod() to not underscorize the subject of the method [wb, 2010-08-06]
 * @changes    1.0.0b21  Fixed some documentation to reflect the API changes from v1.0.0b9 [wb, 2010-07-14]
 * @changes    1.0.0b20  Added the ability to register a wildcard active record method for all classes [wb, 2010-04-22]
 * @changes    1.0.0b19  Added the method ::isClassMappedToTable() [wb, 2010-03-30]
 * @changes    1.0.0b18  Added the `post::loadFromIdentityMap()` hook [wb, 2010-03-14]
 * @changes    1.0.0b17  Changed ::enableSchemaCaching() to rely on fDatabase::clearCache() instead of explicitly calling fSQLTranslation::clearCache() [wb, 2010-03-09]
 * @changes    1.0.0b16  Backwards Compatibility Break - renamed ::addCustomClassToTableMapping() to ::mapClassToTable(). Added ::getDatabaseName() and ::mapClassToDatabase(). Updated code for new fORMDatabase and fORMSchema APIs [wb, 2009-10-28]
 * @changes    1.0.0b15  Added support for fActiveRecord to ::getRecordName() [wb, 2009-10-06]
 * @changes    1.0.0b14  Updated documentation for ::registerActiveRecordMethod() to include info about prefix method matches [wb, 2009-08-07]
 * @changes    1.0.0b13  Updated documentation for ::registerRecordSetMethod() [wb, 2009-07-14]
 * @changes    1.0.0b12  Updated ::callReflectCallbacks() to accept a class name instead of an object [wb, 2009-07-13]
 * @changes    1.0.0b11  Added ::registerInspectCallback() and ::callInspectCallbacks() [wb, 2009-07-13]
 * @changes    1.0.0b10  Fixed a bug with ::objectify() caching during NULL date/time/timestamp values and breaking further objectification [wb, 2009-06-18]
 * @changes    1.0.0b9   Added caching for performance and changed some method APIs to only allow class names instead of instances [wb, 2009-06-15]
 * @changes    1.0.0b8   Updated documentation to reflect removal of `$associate` parameter for callbacks passed to ::registerRecordSetMethod() [wb, 2009-06-02]
 * @changes    1.0.0b7   Added ::enableSchemaCaching() to replace fORMSchema::enableSmartCaching() [wb, 2009-05-04]
 * @changes    1.0.0b6   Added the ability to pass a class instance to ::addCustomClassTableMapping() [wb, 2009-02-23]
 * @changes    1.0.0b5   Backwards compatibility break - renamed ::addCustomTableClassMapping() to ::addCustomClassTableMapping() and swapped the parameters [wb, 2009-01-26]
 * @changes    1.0.0b4   Fixed a bug with retrieving fActiveRecord methods registered for all classes [wb, 2009-01-14]
 * @changes    1.0.0b3   Fixed a static method callback constant [wb, 2008-12-17]
 * @changes    1.0.0b2   Added ::replicate() and ::registerReplicateCallback() for fActiveRecord::replicate() [wb, 2008-12-12]
 * @changes    1.0.0b    The initial implementation [wb, 2007-08-04]
 */
class fORM
{
    // The following constants allow for nice looking callbacks to static methods
    public const callHookCallbacks = 'fORM::callHookCallbacks';

    public const callInspectCallbacks = 'fORM::callInspectCallbacks';

    public const callReflectCallbacks = 'fORM::callReflectCallbacks';

    public const checkHookCallback = 'fORM::checkHookCallback';

    public const classize = 'fORM::classize';

    public const defineActiveRecordClass = 'fORM::defineActiveRecordClass';

    public const enableSchemaCaching = 'fORM::enableSchemaCaching';

    public const getActiveRecordMethod = 'fORM::getActiveRecordMethod';

    public const getClass = 'fORM::getClass';

    public const getColumnName = 'fORM::getColumnName';

    public const getDatabaseName = 'fORM::getDatabaseName';

    public const getRecordName = 'fORM::getRecordName';

    public const getRecordSetMethod = 'fORM::getRecordSetMethod';

    public const isClassMappedToTable = 'fORM::isClassMappedToTable';

    public const mapClassToDatabase = 'fORM::mapClassToDatabase';

    public const mapClassToTable = 'fORM::mapClassToTable';

    public const setClassNamespace = 'fORM::setClassNamespace';

    public const objectify = 'fORM::objectify';

    public const overrideColumnName = 'fORM::overrideColumnName';

    public const overrideRecordName = 'fORM::overrideRecordName';

    public const parseMethod = 'fORM::parseMethod';

    public const registerActiveRecordMethod = 'fORM::registerActiveRecordMethod';

    public const registerHookCallback = 'fORM::registerHookCallback';

    public const registerInspectCallback = 'fORM::registerInspectCallback';

    public const registerObjectifyCallback = 'fORM::registerObjectifyCallback';

    public const registerRecordSetMethod = 'fORM::registerRecordSetMethod';

    public const registerReflectCallback = 'fORM::registerReflectCallback';

    public const registerReplicateCallback = 'fORM::registerReplicateCallback';

    public const registerScalarizeCallback = 'fORM::registerScalarizeCallback';

    public const replicate = 'fORM::replicate';

    public const reset = 'fORM::reset';

    public const scalarize = 'fORM::scalarize';

    public const tablize = 'fORM::tablize';

    /**
     * An array of `{method} => {callback}` mappings for fActiveRecord.
     *
     * @var array
     */
    private static $active_record_method_callbacks = [];

    /**
     * Cache for repetitive computation.
     *
     * @var array
     */
    private static $cache = [
        'parseMethod' => [],
        'getActiveRecordMethod' => [],
        'objectify' => [],
    ];

    /**
     * Custom mappings for class -> database.
     *
     * @var array
     */
    private static $class_database_map = [
        'fActiveRecord' => 'default',
    ];

    /**
     * Custom mappings for class <-> table.
     *
     * @var array
     */
    private static $class_table_map = [];

    /**
     * Custom column names for columns in fActiveRecord classes.
     *
     * @var array
     */
    private static $column_names = [];

    /**
     * Tracks callbacks registered for various fActiveRecord hooks.
     *
     * @var array
     */
    private static $hook_callbacks = [];

    /**
     * Callbacks for ::callInspectCallbacks().
     *
     * @var array
     */
    private static $inspect_callbacks = [];

    /**
     * Callbacks for ::objectify().
     *
     * @var array
     */
    private static $objectify_callbacks = [];

    /**
     * Custom record names for fActiveRecord classes.
     *
     * @var array
     */
    private static $record_names = [
        'fActiveRecord' => 'Active Record',
    ];

    /**
     * An array of `{method} => {callback}` mappings for fRecordSet.
     *
     * @var array
     */
    private static $record_set_method_callbacks = [];

    /**
     * Callbacks for ::callReflectCallbacks().
     *
     * @var array
     */
    private static $reflect_callbacks = [];

    /**
     * Callbacks for ::replicate().
     *
     * @var array
     */
    private static $replicate_callbacks = [];

    /**
     * Callbacks for ::scalarize().
     *
     * @var array
     */
    private static $scalarize_callbacks = [];

    /**
     * The namespace to prepend to class names when using ::defineActiveRecordClass().
     *
     * @var string
     */
    private static $class_namespace = '';

    /**
     * Forces use as a static class.
     *
     * @return fORM
     */
    private function __construct() {}

    /**
     * Calls the hook callbacks for the class and hook specified.
     *
     * @internal
     *
     * @param fActiveRecord $object           The instance of the class to call the hook for
     * @param string        $hook             The hook to call
     * @param array         &$values          The current values of the record
     * @param array         &$old_values      The old values of the record
     * @param array         &$related_records Records related to the current record
     * @param array         &$cache           The cache array of the record
     * @param mixed         &$parameter       The parameter to send the callback
     *
     * @return void
     */
    public static function callHookCallbacks($object, $hook, &$values, &$old_values, &$related_records, &$cache, &$parameter = null)
    {
        $class = get_class($object);

        if (empty(self::$hook_callbacks[$class][$hook]) && empty(self::$hook_callbacks['*'][$hook])) {
            return;
        }

        // Get all of the callbacks for this hook, both for this class or all classes
        $callbacks = [];

        if (isset(self::$hook_callbacks[$class][$hook])) {
            $callbacks = array_merge($callbacks, self::$hook_callbacks[$class][$hook]);
        }

        if (isset(self::$hook_callbacks['*'][$hook])) {
            $callbacks = array_merge($callbacks, self::$hook_callbacks['*'][$hook]);
        }

        foreach ($callbacks as $callback) {
            call_user_func_array(
                $callback,
                // This is the only way to pass by reference
                [
                    $object,
                    &$values,
                    &$old_values,
                    &$related_records,
                    &$cache,
                    &$parameter,
                ]
            );
        }
    }

    /**
     * Calls all inspect callbacks for the class and column specified.
     *
     * @internal
     *
     * @param string $class     The class to inspect the column of
     * @param string $column    The column to inspect
     * @param array  &$metadata The associative array of data about the column
     *
     * @return void
     */
    public static function callInspectCallbacks($class, $column, &$metadata)
    {
        if (! isset(self::$inspect_callbacks[$class][$column])) {
            return;
        }

        foreach (self::$inspect_callbacks[$class][$column] as $callback) {
            // This is the only way to pass by reference
            $parameters = [
                $class,
                $column,
                &$metadata,
            ];
            call_user_func_array($callback, $parameters);
        }
    }

    /**
     * Calls all reflect callbacks for the class passed.
     *
     * @internal
     *
     * @param string $class                The class to call the callbacks for
     * @param array  &$signatures          The associative array of `{method_name} => {signature}`
     * @param bool   $include_doc_comments If the doc comments should be included in the signature
     *
     * @return void
     */
    public static function callReflectCallbacks($class, &$signatures, $include_doc_comments)
    {
        if (! isset(self::$reflect_callbacks[$class]) && ! isset(self::$reflect_callbacks['*'])) {
            return;
        }

        if (! empty(self::$reflect_callbacks['*'])) {
            foreach (self::$reflect_callbacks['*'] as $callback) {
                // This is the only way to pass by reference
                $parameters = [
                    $class,
                    &$signatures,
                    $include_doc_comments,
                ];
                call_user_func_array($callback, $parameters);
            }
        }

        if (! empty(self::$reflect_callbacks[$class])) {
            foreach (self::$reflect_callbacks[$class] as $callback) {
                // This is the only way to pass by reference
                $parameters = [
                    $class,
                    &$signatures,
                    $include_doc_comments,
                ];
                call_user_func_array($callback, $parameters);
            }
        }
    }

    /**
     * Checks to see if any (or a specific) callback has been registered for a specific hook.
     *
     * @internal
     *
     * @param string $class    The name of the class
     * @param string $hook     The hook to check
     * @param array  $callback The specific callback to check for
     *
     * @return bool If the specified callback exists
     */
    public static function checkHookCallback($class, $hook, $callback = null)
    {
        if (empty(self::$hook_callbacks[$class][$hook]) && empty(self::$hook_callbacks['*'][$hook])) {
            return false;
        }

        if (! $callback) {
            return true;
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        if (! empty(self::$hook_callbacks[$class][$hook]) && in_array($callback, self::$hook_callbacks[$class][$hook])) {
            return true;
        }

        if (! empty(self::$hook_callbacks['*'][$hook]) && in_array($callback, self::$hook_callbacks['*'][$hook])) {
            return true;
        }

        return false;
    }

    /**
     * Takes a table and turns it into a class name - uses custom mapping if set.
     *
     * @param string $table The table name
     *
     * @return string The class name
     */
    public static function classize($table)
    {
        if (! $class = array_search($table, self::$class_table_map)) {
            $class = fGrammar::camelize(fGrammar::singularize($table), true);
            self::$class_table_map[$class] = $table;
        }

        return $class;
    }

    /**
     * Will dynamically create an fActiveRecord-based class for a database table.
     *
     * Normally this would be called from an `__autoload()` function.
     *
     * This method will only create classes for tables in the default ORM
     * database.
     *
     * @param string $class The name of the class to create
     */
    public static function defineActiveRecordClass($class): void
    {
        if (class_exists($class, false)) {
            return;
        }
        $schema = fORMSchema::retrieve();
        $tables = $schema->getTables();
        $table = self::tablize($class);
        if (in_array($table, $tables)) {
            eval('class '.$class.' extends fActiveRecord { };');

            return;
        }

        throw new fProgrammerException(
            'The class specified, %s, does not correspond to a database table',
            $class
        );
    }

    /**
     * Enables caching on the fDatabase, fSQLTranslation and fSchema objects used for the ORM.
     *
     * This method will cache database schema information to the three objects
     * that use it during normal ORM operation: fDatabase, fSQLTranslation and
     * fSchema. To allow for schema changes without having to manually clear
     * the cache, all cached information will be cleared if any
     * fUnexpectedException objects are thrown.
     *
     * This method should be called right after fORMDatabase::attach().
     *
     * @param fCache $cache         The object to cache schema information to
     * @param string $database_name The database to enable caching for
     * @param string $key_token     This is a token that is used in cache keys to prevent conflicts for server-wide caches - when non-NULL the document root is used
     */
    public static function enableSchemaCaching($cache, $database_name = 'default', $key_token = null): void
    {
        if ($key_token === null) {
            $key_token = $_SERVER['DOCUMENT_ROOT'];
        }
        $token = 'fORM::'.$database_name.'::'.$key_token.'::';

        $db = fORMDatabase::retrieve('name:'.$database_name);
        $db->enableCaching($cache, $token);
        fException::registerCallback($db->clearCache, 'fUnexpectedException');

        $sql_translation = $db->getSQLTranslation();
        $sql_translation->enableCaching($cache, $token);

        $schema = fORMSchema::retrieve('name:'.$database_name);
        $schema->enableCaching($cache, $token);
        fException::registerCallback($schema->clearCache, 'fUnexpectedException');
    }

    /**
     * Returns a matching callback for the class and method specified.
     *
     * The callback returned will be determined by the following logic:
     *
     *  1. If an exact callback has been defined for the method, it will be returned
     *  2. If a callback in the form `{prefix}*` has been defined that matches the method, it will be returned
     *  3. `NULL` will be returned
     *
     * @internal
     *
     * @param string $class  The name of the class
     * @param string $method The method to get the callback for
     *
     * @return null|string The callback for the method or `NULL` if none exists - see method description for details
     */
    public static function getActiveRecordMethod($class, $method)
    {
        // This caches method lookups, providing a significant performance
        // boost to pages with lots of method calls that get passed to
        // fActiveRecord::__call()
        if (isset(self::$cache['getActiveRecordMethod'][$class.'::'.$method])) {
            return (! $method = self::$cache['getActiveRecordMethod'][$class.'::'.$method]) ? null : $method;
        }

        $callback = null;

        if (isset(self::$active_record_method_callbacks[$class][$method])) {
            $callback = self::$active_record_method_callbacks[$class][$method];
        } elseif (isset(self::$active_record_method_callbacks['*'][$method])) {
            $callback = self::$active_record_method_callbacks['*'][$method];
        } elseif (preg_match('#[A-Z0-9]#', $method)) {
            [$action, $subject] = self::parseMethod($method);
            if (isset(self::$active_record_method_callbacks[$class][$action.'*'])) {
                $callback = self::$active_record_method_callbacks[$class][$action.'*'];
            } elseif (isset(self::$active_record_method_callbacks['*'][$action.'*'])) {
                $callback = self::$active_record_method_callbacks['*'][$action.'*'];
            }
        }

        self::$cache['getActiveRecordMethod'][$class.'::'.$method] = ($callback === null) ? false : $callback;

        return $callback;
    }

    /**
     * Takes a class name or class and returns the class name.
     *
     * @internal
     *
     * @param mixed $class The object to get the name of, or possibly a string already containing the class
     *
     * @return string The class name
     */
    public static function getClass($class)
    {
        if (is_object($class)) {
            return get_class($class);
        }

        if (strpos($class, '\\') !== false) {
            return $class;
        }

        return self::$class_namespace.$class;
    }

    /**
     * Returns the column name.
     *
     * The default column name is the result of calling fGrammar::humanize()
     * on the column.
     *
     * @internal
     *
     * @param string $class  The class name the column is part of
     * @param string $column The database column
     *
     * @return string The column name for the column specified
     */
    public static function getColumnName($class, $column)
    {
        if (! isset(self::$column_names[$class])) {
            self::$column_names[$class] = [];
        }

        if (! isset(self::$column_names[$class][$column])) {
            self::$column_names[$class][$column] = fGrammar::humanize($column);
        }

        return self::$column_names[$class][$column];
    }

    /**
     * Returns the name for the database used by the class specified.
     *
     * @internal
     *
     * @param string $class The class name to get the database name for
     *
     * @return string The name of the database to use
     */
    public static function getDatabaseName($class)
    {
        if (! isset(self::$class_database_map[$class])) {
            $class = 'fActiveRecord';
        }

        return self::$class_database_map[$class];
    }

    /**
     * Returns the record name for a class.
     *
     * The default record name is the result of calling fGrammar::humanize()
     * on the class.
     *
     * @internal
     *
     * @param string $class The class name to get the record name of
     *
     * @return string The record name for the class specified
     */
    public static function getRecordName($class)
    {
        if (! isset(self::$record_names[$class])) {
            self::$record_names[$class] = fGrammar::humanize($class);
        }

        return self::$record_names[$class];
    }

    /**
     * Returns a matching callback for the method specified.
     *
     * The callback returned will be determined by the following logic:
     *
     *  1. If an exact callback has been defined for the method, it will be returned
     *  2. If a callback in the form `{action}*` has been defined that matches the method, it will be returned
     *  3. `NULL` will be returned
     *
     * @internal
     *
     * @param string $method The method to get the callback for
     *
     * @return null|string The callback for the method or `NULL` if none exists - see method description for details
     */
    public static function getRecordSetMethod($method)
    {
        if (isset(self::$record_set_method_callbacks[$method])) {
            return self::$record_set_method_callbacks[$method];
        }

        if (preg_match('#[A-Z0-9]#', $method)) {
            [$action, $subject] = self::parseMethod($method);
            if (isset(self::$record_set_method_callbacks[$action.'*'])) {
                return self::$record_set_method_callbacks[$action.'*'];
            }
        }
    }

    /**
     * Checks if a class has been mapped to a table.
     *
     * @internal
     *
     * @param mixed $class The name of the class
     *
     * @return bool If the class has been mapped to a table
     */
    public static function isClassMappedToTable($class)
    {
        $class = self::getClass($class);

        return isset(self::$class_table_map[$class]);
    }

    /**
     * Sets a class to use a database other than the "default".
     *
     * Multiple database objects can be attached for the ORM by passing a
     * unique `$name` to the ::attach() method.
     *
     * @param mixed  $class         The name of the class, or an instance of it
     * @param string $database_name The name given to the database when passed to ::attach()
     */
    public static function mapClassToDatabase($class, $database_name): void
    {
        $class = self::getClass($class);

        self::$class_database_map[$class] = $database_name;
    }

    /**
     * Allows non-standard class to table mapping.
     *
     * By default, all database tables are assumed to be plural nouns in
     * `underscore_notation` and all class names are assumed to be singular
     * nouns in `UpperCamelCase`. This method allows arbitrary class to
     * table mapping.
     *
     * @param mixed  $class The name of the class, or an instance of it
     * @param string $table The name of the database table
     */
    public static function mapClassToTable($class, $table): void
    {
        $class = self::getClass($class);

        self::$class_table_map[$class] = $table;
    }

    /**
     * Takes a scalar value and turns it into an object if applicable.
     *
     * @internal
     *
     * @param string $class  The class name of the class the column is part of
     * @param string $column The database column
     * @param mixed  $value  The value to possibly objectify
     *
     * @return mixed The scalar or object version of the value, depending on the column type and column options
     */
    public static function objectify($class, $column, $value)
    {
        // This short-circuits computation for already checked columns, providing
        // a nice little performance boost to pages with lots of records
        if (isset(self::$cache['objectify'][$class.'::'.$column])) {
            return $value;
        }

        if (! empty(self::$objectify_callbacks[$class][$column])) {
            return call_user_func(self::$objectify_callbacks[$class][$column], $class, $column, $value);
        }

        $table = self::tablize($class);
        $schema = fORMSchema::retrieve($class);

        // Turn date/time values into objects
        $column_type = $schema->getColumnInfo($table, $column, 'type');

        if (in_array($column_type, ['date', 'time', 'timestamp'])) {
            if ($value === null) {
                return $value;
            }

            try {
                // Explicit calls to the constructors are used for dependency detection
                switch ($column_type) {
                    case 'date':      $value = new fDate($value);

                        break;

                    case 'time':      $value = new fTime($value);

                        break;

                    case 'timestamp': $value = new fTimestamp($value);

                        break;
                }
            } catch (fValidationException $e) {
                // Validation exception results in the raw value being saved
            }
        } else {
            self::$cache['objectify'][$class.'::'.$column] = true;
        }

        return $value;
    }

    /**
     * Allows overriding of default column names.
     *
     * By default a column name is the result of fGrammar::humanize() called
     * on the column.
     *
     * @param mixed  $class       The class name or instance of the class the column is located in
     * @param string $column      The database column
     * @param string $column_name The name for the column
     */
    public static function overrideColumnName($class, $column, $column_name): void
    {
        $class = self::getClass($class);

        if (! isset(self::$column_names[$class])) {
            self::$column_names[$class] = [];
        }

        self::$column_names[$class][$column] = $column_name;
    }

    /**
     * Allows overriding of default record names.
     *
     * By default a record name is the result of fGrammar::humanize() called
     * on the class.
     *
     * @param mixed  $class       The class name or instance of the class to override the name of
     * @param string $record_name The human version of the record
     */
    public static function overrideRecordName($class, $record_name): void
    {
        $class = self::getClass($class);
        self::$record_names[$class] = $record_name;
    }

    /**
     * Parses a `camelCase` method name for an action and subject in the form `actionSubject()`.
     *
     * @internal
     *
     * @param string $method The method name to parse
     *
     * @return array An array of `0 => {action}, 1 => {subject}`
     */
    public static function parseMethod($method)
    {
        if (isset(self::$cache['parseMethod'][$method])) {
            return self::$cache['parseMethod'][$method];
        }

        if (! preg_match('#^([a-z]+)(.*)$#D', $method, $matches)) {
            throw new fProgrammerException(
                'Invalid method, %s(), called',
                $method
            );
        }
        self::$cache['parseMethod'][$method] = [$matches[1], $matches[2]];

        return self::$cache['parseMethod'][$method];
    }

    /**
     * Registers a callback for an fActiveRecord method that falls through to fActiveRecord::__call() or hits a predefined method hook.
     *
     * The callback should accept the following parameters:
     *
     *  - **`$object`**:           The fActiveRecord instance
     *  - **`&$values`**:          The values array for the record
     *  - **`&$old_values`**:      The old values array for the record
     *  - **`&$related_records`**: The related records array for the record
     *  - **`&$cache`**:           The cache array for the record
     *  - **`$method_name`**:      The method that was called
     *  - **`&$parameters`**:      The parameters passed to the method
     *
     * @param mixed    $class    The class name or instance of the class to register for, `'*'` will register for all classes
     * @param string   $method   The method to hook for - this can be a complete method name or `{prefix}*` where `*` will match any column name
     * @param callable $callback The callback to execute - see method description for parameter list
     */
    public static function registerActiveRecordMethod($class, $method, $callback): void
    {
        $class = self::getClass($class);

        if (! isset(self::$active_record_method_callbacks[$class])) {
            self::$active_record_method_callbacks[$class] = [];
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        self::$active_record_method_callbacks[$class][$method] = $callback;

        self::$cache['getActiveRecordMethod'] = [];
    }

    /**
     * Registers a callback for one of the various fActiveRecord hooks - multiple callbacks can be registered for each hook.
     *
     * The method signature should include the follow parameters:
     *
     *  - **`$object`**:           The fActiveRecord instance
     *  - **`&$values`**:          The values array for the record
     *  - **`&$old_values`**:      The old values array for the record
     *  - **`&$related_records`**: The related records array for the record
     *  - **`&$cache`**:           The cache array for the record
     *
     * The `'pre::validate()'` and `'post::validate()'` hooks have an extra
     * parameter:
     *
     *  - **`&$validation_messages`**: An ordered array of validation errors that will be returned or tossed as an fValidationException
     *
     * The `'pre::replicate()'`, `'post::replicate()'` and
     * `'cloned::replicate()'` hooks have an extra parameter:
     *
     *  - **`$replication_level`**: An integer representing the level of recursion - the object being replicated will be `0`, children will be `1`, grandchildren `2` and so on.
     *
     * Below is a list of all valid hooks:
     *
     *  - `'post::__construct()'`
     *  - `'pre::delete()'`
     *  - `'post-begin::delete()'`
     *  - `'pre-commit::delete()'`
     *  - `'post-commit::delete()'`
     *  - `'post-rollback::delete()'`
     *  - `'post::delete()'`
     *  - `'post::loadFromIdentityMap()'`
     *  - `'post::loadFromResult()'`
     *  - `'pre::populate()'`
     *  - `'post::populate()'`
     *  - `'pre::replicate()'`
     *  - `'post::replicate()'`
     *  - `'cloned::replicate()'`
     *  - `'pre::store()'`
     *  - `'post-begin::store()'`
     *  - `'post-validate::store()'`
     *  - `'pre-commit::store()'`
     *  - `'post-commit::store()'`
     *  - `'post-rollback::store()'`
     *  - `'post::store()'`
     *  - `'pre::validate()'`
     *  - `'post::validate()'`
     *
     * @param mixed    $class    The class name or instance of the class to hook, `'*'` will hook all classes
     * @param string   $hook     The hook to register for
     * @param callable $callback The callback to register - see the method description for details about the method signature
     */
    public static function registerHookCallback($class, $hook, $callback): void
    {
        $class = self::getClass($class);

        static $valid_hooks = [
            'post::__construct()',
            'pre::delete()',
            'post-begin::delete()',
            'pre-commit::delete()',
            'post-commit::delete()',
            'post-rollback::delete()',
            'post::delete()',
            'post::loadFromIdentityMap()',
            'post::loadFromResult()',
            'pre::populate()',
            'post::populate()',
            'pre::replicate()',
            'post::replicate()',
            'cloned::replicate()',
            'pre::store()',
            'post-begin::store()',
            'post-validate::store()',
            'pre-commit::store()',
            'post-commit::store()',
            'post-rollback::store()',
            'post::store()',
            'pre::validate()',
            'post::validate()',
        ];

        if (! in_array($hook, $valid_hooks)) {
            throw new fProgrammerException(
                'The hook specified, %1$s, should be one of: %2$s.',
                $hook,
                implode(', ', $valid_hooks)
            );
        }

        if (! isset(self::$hook_callbacks[$class])) {
            self::$hook_callbacks[$class] = [];
        }

        if (! isset(self::$hook_callbacks[$class][$hook])) {
            self::$hook_callbacks[$class][$hook] = [];
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        if (self::checkHookCallback($class, $hook, $callback)) {
            return;
        }

        self::$hook_callbacks[$class][$hook][] = $callback;
    }

    /**
     * Registers a callback to modify the results of fActiveRecord::inspect() methods.
     *
     * @param mixed    $class    The class name or instance of the class to register for
     * @param string   $column   The column to register for
     * @param callable $callback The callback to register. Callback should accept a single parameter by reference, an associative array of the various metadata about a column.
     */
    public static function registerInspectCallback($class, $column, $callback): void
    {
        $class = self::getClass($class);

        if (! isset(self::$inspect_callbacks[$class])) {
            self::$inspect_callbacks[$class] = [];
        }
        if (! isset(self::$inspect_callbacks[$class][$column])) {
            self::$inspect_callbacks[$class][$column] = [];
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        self::$inspect_callbacks[$class][$column][] = $callback;
    }

    /**
     * Registers a callback for when ::objectify() is called on a specific column.
     *
     * @param mixed    $class    The class name or instance of the class to register for
     * @param string   $column   The column to register for
     * @param callable $callback The callback to register. Callback should accept a single parameter, the value to objectify and should return the objectified value.
     */
    public static function registerObjectifyCallback($class, $column, $callback): void
    {
        $class = self::getClass($class);

        if (! isset(self::$objectify_callbacks[$class])) {
            self::$objectify_callbacks[$class] = [];
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        self::$objectify_callbacks[$class][$column] = $callback;

        self::$cache['objectify'] = [];
    }

    /**
     * Registers a callback for an fRecordSet method that fall through to fRecordSet::__call().
     *
     * The callback should accept the following parameters:
     *
     *  - **`$object`**:      The actual record set
     *  - **`$class`**:       The class of each record
     *  - **`&$records`**:    The ordered array of fActiveRecord objects
     *  - **`$method_name`**: The method name that was called
     *  - **`$parameters`**:  Any parameters passed to the method
     *
     * @param string   $method   The method to hook for
     * @param callable $callback The callback to execute - see method description for parameter list
     */
    public static function registerRecordSetMethod($method, $callback): void
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        self::$record_set_method_callbacks[$method] = $callback;
    }

    /**
     * Registers a callback to modify the results of fActiveRecord::reflect().
     *
     * Callbacks registered here can override default method signatures and add
     * method signatures, however any methods that are defined in the actual class
     * will override these signatures.
     *
     * The callback should accept three parameters:
     *
     *  - **`$class`**: the class name
     *  - **`&$signatures`**: an associative array of `{method_name} => {signature}`
     *  - **`$include_doc_comments`**: a boolean indicating if the signature should include the doc comment for the method, or just the signature
     *
     * @param mixed    $class    The class name or instance of the class to register for, `'*'` will register for all classes
     * @param callable $callback The callback to register. Callback should accept a three parameters - see method description for details.
     *
     * @return void
     */
    public static function registerReflectCallback($class, $callback)
    {
        $class = self::getClass($class);

        if (! isset(self::$reflect_callbacks[$class])) {
            self::$reflect_callbacks[$class] = [];
        } elseif (in_array($callback, self::$reflect_callbacks[$class])) {
            return;
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        self::$reflect_callbacks[$class][] = $callback;
    }

    /**
     * Registers a callback for when a value is replicated for a specific column.
     *
     * @param mixed    $class    The class name or instance of the class to register for
     * @param string   $column   The column to register for
     * @param callable $callback The callback to register. Callback should accept a single parameter, the value to replicate and should return the replicated value.
     */
    public static function registerReplicateCallback($class, $column, $callback): void
    {
        $class = self::getClass($class);

        if (! isset(self::$replicate_callbacks[$class])) {
            self::$replicate_callbacks[$class] = [];
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        self::$replicate_callbacks[$class][$column] = $callback;
    }

    /**
     * Registers a callback for when ::scalarize() is called on a specific column.
     *
     * @param mixed    $class    The class name or instance of the class to register for
     * @param string   $column   The column to register for
     * @param callable $callback The callback to register. Callback should accept a single parameter, the value to scalarize and should return the scalarized value.
     */
    public static function registerScalarizeCallback($class, $column, $callback): void
    {
        $class = self::getClass($class);

        if (! isset(self::$scalarize_callbacks[$class])) {
            self::$scalarize_callbacks[$class] = [];
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        self::$scalarize_callbacks[$class][$column] = $callback;
    }

    /**
     * Takes and value and returns a copy is scalar or a clone if an object.
     *
     * The ::registerReplicateCallback() allows for custom replication code
     *
     * @internal
     *
     * @param string $class  The class the column is part of
     * @param string $column The database column
     * @param mixed  $value  The value to copy/clone
     *
     * @return mixed The copied/cloned value
     */
    public static function replicate($class, $column, $value)
    {
        if (! empty(self::$replicate_callbacks[$class][$column])) {
            return call_user_func(self::$replicate_callbacks[$class][$column], $class, $column, $value);
        }

        if (! is_object($value)) {
            return $value;
        }

        return clone $value;
    }

    /**
     * Resets the configuration of the class.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$active_record_method_callbacks = [];
        self::$cache = [
            'parseMethod' => [],
            'getActiveRecordMethod' => [],
            'objectify' => [],
        ];
        self::$class_database_map = [
            'fActiveRecord' => 'default',
        ];
        self::$class_table_map = [];
        self::$column_names = [];
        self::$hook_callbacks = [];
        self::$inspect_callbacks = [];
        self::$objectify_callbacks = [];
        self::$record_names = [
            'fActiveRecord' => 'Active Record',
        ];
        self::$record_set_method_callbacks = [];
        self::$reflect_callbacks = [];
        self::$replicate_callbacks = [];
        self::$scalarize_callbacks = [];
    }

    /**
     * If the value passed is an object, calls `__toString()` on it.
     *
     * @internal
     *
     * @param mixed  $class  The class name or instance of the class the column is part of
     * @param string $column The database column
     * @param mixed  $value  The value to get the scalar value of
     *
     * @return mixed The scalar value of the value
     */
    public static function scalarize($class, $column, $value)
    {
        $class = self::getClass($class);

        if (! empty(self::$scalarize_callbacks[$class][$column])) {
            return call_user_func(self::$scalarize_callbacks[$class][$column], $class, $column, $value);
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            return $value->__toString();
        }
        if (is_object($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * Takes a class name (or class) and turns it into a table name - Uses custom mapping if set.
     *
     * @param string $class The class name
     *
     * @return string The table name
     */
    public static function tablize($class)
    {
        if (! isset(self::$class_table_map[$class])) {
            self::$class_table_map[$class] = fGrammar::underscorize(fGrammar::pluralize(str_replace(static::$class_namespace, '', $class)));
        }

        return self::$class_table_map[$class];
    }

    public static function setClassNamespace(string $namespace): void
    {
        self::$class_namespace = $namespace;
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
