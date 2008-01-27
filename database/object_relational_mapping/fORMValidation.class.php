<?php
/**
 * Handles validation for (@link fActiveRecord} classes
 * 
 * @copyright  Copyright (c) 2007 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @link  http://flourishlib.com/fORMValidation
 * 
 * @uses  fCore
 * @uses  fInflection
 * @uses  fNoResultsException
 * @uses  fORM
 * @uses  fORMDatabase
 * @uses  fORMSchema
 * @uses  fProgrammerException
 * @uses  fURL
 * @uses  fValidationException
 * 
 * @version  1.0.0
 * @changes  1.0.0    The initial implementation [wb, 2007-08-04]
 */
class fORMValidation
{
	/**
	 * Conditional validation rules
	 * 
	 * @var array 
	 */
	static private $conditional_validation_rules = array();
	
	/**
	 * One or more validation rules
	 * 
	 * @var array 
	 */
	static private $one_or_more_validation_rules = array();
	
	/**
	 * Only one validation rules
	 * 
	 * @var array 
	 */
	static private $only_one_validation_rules = array();
	
	/**
	 * Many-to-many validation rules
	 * 
	 * @var array 
	 */
	static private $many_to_many_validation_rules = array();
	
	/**
	 * Column formatting validation rules
	 * 
	 * @var array 
	 */
	static private $formatting_rules = array();
	
	
	/**
	 * Forces use as a static class
	 * 
	 * @return fORMValidation
	 */
	private function __construct() { }
	
	
	/**
	 * Validates values for an fActiveRecord object
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table        The table to validate against
	 * @param  array  &$values      The values to validate
	 * @param  array  &$old_values  The old values for the record
	 * @return void
	 */
	static public function validate($table, &$values, &$old_values)
	{
		$validation_messages = array();
		
		try {
			self::checkPrimaryKeys($table, $values, $old_values);
		} catch (fValidationException $e) {
			$validation_messages[] = $e->getMessage();			
		}
		
		$column_info = fORMSchema::getInstance()->getColumnInfo($table);
		foreach ($column_info as $column => $info) {
			try {
				self::checkAgainstSchema($table, $column, $values);
			} catch (fValidationException $e) {
				$validation_messages[] = $e->getMessage();			
			}
		}
		
		self::$conditional_validation_rules[$table] = (isset(self::$conditional_validation_rules[$table])) ? self::$conditional_validation_rules[$table] : array();
		foreach (self::$conditional_validation_rules[$table] as $rule) {
			try {
				self::checkConditionalRule($table, $values, $rule['main_column'], $rule['conditional_values'], $rule['conditional_columns']);
			} catch (fValidationException $e) {
				$messages = explode("\n", $e->getMessage());
				foreach ($messages as $message) {
					$validation_messages[] = $message;
				}			
			}
		}
		
		self::$one_or_more_validation_rules[$table] = (isset(self::$one_or_more_validation_rules[$table])) ? self::$one_or_more_validation_rules[$table] : array();
		foreach (self::$one_or_more_validation_rules[$table] as $rule) {
			try {
				self::checkOneOrMoreRule($table, $values, $rule['columns']);
			} catch (fValidationException $e) {
				$validation_messages[] = $e->getMessage();			
			}
		}
		
		self::$only_one_validation_rules[$table] = (isset(self::$only_one_validation_rules[$table])) ? self::$only_one_validation_rules[$table] : array();
		foreach (self::$only_one_validation_rules[$table] as $rule) {
			try {
				self::checkOnlyOneRule($table, $values, $rule['columns']);
			} catch (fValidationException $e) {
				$validation_messages[] = $e->getMessage();			
			}
		}
		
		self::$many_to_many_validation_rules[$table] = (isset(self::$many_to_many_validation_rules[$table])) ? self::$many_to_many_validation_rules[$table] : array();
		foreach (self::$many_to_many_validation_rules[$table] as $rule) {
			try {
				self::checkManyToManyRule($values, $rule['plural_related_column']);
			} catch (fValidationException $e) {
				$validation_messages[] = $e->getMessage();			
			}
		}
		
		self::$formatting_rules[$table] = (isset(self::$formatting_rules[$table])) ? self::$formatting_rules[$table] : array();
		foreach (self::$formatting_rules[$table] as $column => $format_type) {
			try {
				self::checkFormattingRule($table, $values, $column, $format_type);
			} catch (fValidationException $e) {
				$validation_messages[] = $e->getMessage();			
			}
		}
		
		if (!empty($validation_messages)) {
			fCore::toss('fValidationException', '<p>The following problems were found:</p><ul><li>' . join('</li><li>', $validation_messages) . '</li></ul>');
		}	
	}
	
	
	/**
	 * Adds a conditional validation rule
	 *
	 * @param  mixed  $table                The database table (or (@link fActiveRecord} class) this validation rule applies to
	 * @param  string $main_column          The column to check for a value
	 * @param  array  $conditional_values   If empty, any value in the main column will trigger the conditional columns, otherwise the value must match one of these
	 * @param  array  $conditional_columns  The columns that are to be required
	 * @return void
	 */
	static public function addConditionalValidationRule($table, $main_column, $conditional_values, $conditional_columns)
	{
		if (is_object($table)) {
			$table = fORM::tablize($table);	
		}
		
		if (!isset(self::$conditional_validation_rules[$table])) {
			self::$conditional_validation_rules[$table] = array();		
		}
		
		$rule = array();
		$rule['main_column']         = $main_column;
		$rule['conditional_values']  = $conditional_values;
		$rule['conditional_columns'] = $conditional_columns;
		
		self::$conditional_validation_rules[$table][] = $rule;
	}
	
	
	/**
	 * Adds a one-or-more validation rule
	 *
	 * @param  mixed $table    The database table (or (@link fActiveRecord} class) the columns exists in
	 * @param  array $columns  The columns to check
	 * @return void
	 */
	static public function addOneOrMoreValidationRule($table, $columns)
	{
		if (is_object($table)) {
			$table = fORM::tablize($table);	
		}
		
		settype($columns, 'array');
		
		if (!isset(self::$one_or_more_validation_rules[$table])) {
			self::$one_or_more_validation_rules[$table] = array();		
		}
		
		$rule = array();
		$rule['columns'] = $columns;
		
		self::$one_or_more_validation_rules[$table][] = $rule;
	}
	
	
	/**
	 * Add an only-one validation rule
	 *
	 * @param  mixed $table     The database table (or (@link fActiveRecord} class) the column exists in
	 * @param  array $columns   The columns to check
	 * @return void
	 */
	static public function addOnlyOneValidationRule($table, $columns)
	{
		if (is_object($table)) {
			$table = fORM::tablize($table);	
		}
		
		settype($columns, 'array');
		
		if (!isset(self::$only_one_validation_rules[$table])) {
			self::$only_one_validation_rules[$table] = array();		
		}
		
		$rule = array();
		$rule['columns'] = $columns;
		
		self::$only_one_validation_rules[$table][] = $rule;		
	}
	
	
	/**
	 * Add a many-to-many validation rule
	 *
	 * @param  mixed  $table                    The database table (or (@link fActiveRecord} class) to add the rule for
	 * @param  string $plural_related_column    The plural form of the related column
	 * @return void
	 */
	static public function addManyToManyValidationRule($table, $plural_related_column)
	{
		if (is_object($table)) {
			$table = fORM::tablize($table);	
		}
		
		if (!isset(self::$many_to_many_validation_rules[$table])) {
			self::$many_to_many_validation_rules[$table] = array();		
		}
		
		$rule = array();
		$rule['plural_related_column'] = $plural_related_column;
		
		self::$many_to_many_validation_rules[$table][] = $rule;		
	}
	
	
	/**
	 * Adds a column format rule
	 *
	 * @param  mixed  $table         The database table (or (@link fActiveRecord} class) this validation rule applies to
	 * @param  string $column        The column to check the format of
	 * @param  string $format_type   The format for the column: email, link
	 * @return void
	 */
	static public function addFormattingRule($table, $column, $format_type)
	{
		if (is_object($table)) {
			$table = fORM::tablize($table);	
		}
		
		if (!isset(self::$formatting_rules[$table])) {
			self::$formatting_rules[$table] = array();		
		}
		
		if (!in_array($format_type, array('email', 'link'))) {
			fCore::toss('fProgrammerException', "The format type specified, '" . $format_type , "', should be either 'email' or 'link'");	
		}
		
		self::$formatting_rules[$table][$column] = $format_type;
	}
	
	
	/**
	 * Makes sure a record with the same primary keys is not already in the database
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table         The database table the column exists in
	 * @param  array  &$values       An associative array of all values going into the row (needs all for multi-field unique constraint checking)
	 * @param  array  &$old_values   The old values for the record
	 * @return void
	 */
	static public function checkPrimaryKeys($table, &$values, &$old_values)
	{
		$primary_keys = fORMSchema::getInstance()->getKeys($table, 'primary');
		
		$exists = TRUE;
		$key_set = FALSE;
		foreach ($primary_keys as $primary_key) {
			if ((array_key_exists($primary_key, $old_values) && $old_values[$primary_key] === NULL) || $values[$primary_key] === NULL) {
				$exists = FALSE;	
			}
			if ($values[$primary_key] !== NULL) {
				$key_set = TRUE;	
			}
		}
		
		// We don't need to check if the record is existing
		if ($exists || !$key_set) {
			return;	
		}
		
		try {
			$sql = "SELECT * FROM " . $table . " WHERE ";
			$key_num = 0;
			$columns = '';
			foreach ($primary_keys as $primary_key) {
				if ($key_num) { $sql .= " AND "; $columns.= ', '; }
				$sql .= $primary_key . fORMDatabase::prepareBySchema($table, $primary_key, (!empty($old_values[$primary_key])) ? $old_values[$primary_key] : $values[$primary_key], '=');
				$columns .= fInflection::humanize($primary_key);
				$key_num++;
			}
			$result = fORMDatabase::getInstance()->translatedQuery($sql);
			$result->tossIfNoResults();	
			
			fCore::toss('fValidationException', 'A ' . fORM::getRecordName(fORM::classize($table)) . ' with the same ' . $columns . ' already exists');
			
		} catch (fNoResultsException $e) {
			return;	
		}
	}
	
	
	/**
	 * Validates a value against the database schema
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table         The database table the column exists in
	 * @param  string $column        The column to check
	 * @param  array  &$values       An associative array of all values going into the row (needs all for multi-field unique constraint checking)
	 * @return void
	 */
	static public function checkAgainstSchema($table, $column, &$values)
	{
		$column_info = fORMSchema::getInstance()->getColumnInfo($table, $column);
		// Make sure a value is provided for required columns
		if ($values[$column] === NULL && $column_info['not_null'] && $column_info['default'] === NULL && $column_info['auto_increment'] === FALSE) {
			fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter a value');		
		}
		
		self::checkDataType($table, $column, $values[$column]);
		
		// Make sure a valid value is chosen
		if (isset($column_info['valid_values']) && $values[$column] !== NULL && !in_array($values[$column], $column_info['valid_values'])) {
			fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please choose from one of the following: ' . join(', ', $column_info['valid_values']));			
		}
		// Make sure the value isn't too long
		if (isset($column_info['max_length']) && $values[$column] !== NULL && is_string($values[$column]) && strlen($values[$column]) > $column_info['max_length']) {
			fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter a value no longer than ' . $column_info['max_length'] . ' characters');			
		}
		
		self::checkUniqueConstraints($table, $column, $values);
		self::checkForeignKeyConstraints($table, $column, $values);		
	}
	
	
	/**
	 * Validates a value against the database data type
	 *
	 * @param  string $table    The database table the column exists in
	 * @param  string $column   The column to check
	 * @param  mixed  $value    The value to check
	 * @return void
	 */
	static private function checkDataType($table, $column, $value)
	{
		$column_info = fORMSchema::getInstance()->getColumnInfo($table, $column);
		if ($value !== NULL) {
			switch ($column_info['type']) {
				case 'varchar':
				case 'char':
				case 'text':
				case 'blob':
					if (!is_string($value) && !is_numeric($value)) {
						fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter a string');	
					}
					break;
				case 'integer':
				case 'float':
					if (!is_numeric($value)) {
						fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter a number');	
					}
					break;
				case 'timestamp':
				case 'date':
				case 'time':
					if (strtotime($value) === FALSE) {
						fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter a date or time');	
					}
					break;
				
			}
		}		
	}
	
	
	/**
	 * Validates values against unique constraints
	 *
	 * @param  string $table     The database table
	 * @param  string $column    The column to check
	 * @param  array  &$values   The values to check
	 * @return void
	 */
	static private function checkUniqueConstraints($table, $column, &$values)
	{
		$key_info = fORMSchema::getInstance()->getKeys($table);
		foreach ($key_info['unique'] AS $unique_columns) {
			if (in_array($column, $unique_columns)) {
				$sql = "SELECT " . join(', ', $key_info['primary']) . " FROM " . $table . " WHERE ";
				$column_num = 0;
				foreach ($unique_columns as $unique_column) {
					if ($column_num) { $sql .= " AND "; }
					$sql .= $unique_column . fORMDatabase::prepareBySchema($table, $unique_column, $values[$unique_column], '=');
					$column_num++;
				}
				
				try {
					$result = fORMDatabase::getInstance()->translatedQuery($sql);
					$result->tossIfNoResults();	
				
					// If an exception was not throw, we have existing values
					$column_names = '';
					$column_num = 0;
					foreach ($unique_columns as $unique_column) {
						if ($column_num) { $column_names .= ', '; }
						$column_names .= fORM::getColumnName($table, $unique_column);
						$column_num++;	
					}
					fCore::toss('fValidationException', $column_names . ': The values specified must be a unique combination, however, the combination specified already exists');
				
				} catch (fNoResultsException $e) { }
			}	
		}	
	}
	
	
	/**
	 * Validates values against foreign key constraints
	 *
	 * @param  string $table     The database table
	 * @param  string $column    The column to check
	 * @param  array  &$values   The values to check
	 * @return void
	 */
	static private function checkForeignKeyConstraints($table, $column, &$values)
	{
		if ($values[$column] === NULL) {
			return;	
		}
		
		$foreign_keys = fORMSchema::getInstance()->getKeys($table, 'foreign');
		foreach ($foreign_keys AS $foreign_key) {
			if ($foreign_key['column'] == $column) {
				try {
					$sql  = "SELECT " . $foreign_key['foreign_column'];
					$sql .= " FROM " . $foreign_key['foreign_table'];
					$sql .= " WHERE ";
					$sql .= $column . fORMDatabase::prepareBySchema($table, $column, $values[$column], '=');
					$sql  = str_replace('WHERE ' . $column, 'WHERE ' . $foreign_key['foreign_column'], $sql);
					
					$result = fORMDatabase::getInstance()->translatedQuery($sql);
					$result->tossIfNoResults();					
				} catch (fNoResultsException $e) {
					fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': The value specified is invalid');
				}	
			}		
		}	
	}
	
	
	/**
	 * Validates against a conditional validation rule
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table                The database table this validation rule applies to
	 * @param  array  &$record_values       An associative array of all values for the record
	 * @param  string $main_column          The column to check for a value
	 * @param  array  $conditional_values   If empty, any value in the main column will trigger the conditional columns, otherwise the value must match one of these
	 * @param  array  $conditional_columns  The columns that are to be required
	 * @return void
	 */
	static public function checkConditionalRule($table, &$record_values, $main_column, $conditional_values, $conditional_columns)
	{
		if (!empty($conditional_values))  {
			settype($conditional_values, 'array');
		}	
		settype($conditional_columns, 'array');		
		
		if ($record_values[$main_column] === NULL) {
			return;	
		}
		
		if ((!empty($conditional_values) && in_array($record_values[$main_column], $conditional_values)) || (empty($conditional_values))) {
			$messages = array();
			foreach ($conditional_columns as $conditional_column) {
				if ($record_values[$conditional_column] === NULL) {
					$messages[] = fORM::getColumnName($table, $conditional_column) . ': Please enter a value';
				}
			}		
			if (!empty($messages)) {
				fCore::toss('fValidationException', join("\n", $messages));			
			}
		}
	}

	
	/**
	 * Validates against a one-or-more validation rule
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table           The database table the columns exists in
	 * @param  array  &$record_values  An associative array of all values for the record
	 * @param  array  $columns         The columns to check
	 * @return void
	 */
	static public function checkOneOrMoreRule($table, &$record_values, $columns)
	{
		settype($columns, 'array');
		
		$found_value = FALSE;
		foreach ($columns as $column) {
			if ($record_values[$column] !== NULL) {
				$found_value = TRUE;	
			}
		}
		
		if (!$found_value) {
			$column_names = '';
			$column_num = 0;
			foreach ($columns as $column) {
				if ($column_num) { $column_names .= ', '; }
				$column_names .= fORM::getColumnName($table, $column);
				$column_num++;	
			}
			fCore::toss('fValidationException', $column_names . ': Please enter a value for at least one');
		}			
	}
	
	
	/**
	 * Validates against an only-one validation rule
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table           The database table the column exists in
	 * @param  array  &$record_values  An associative array of all values for the record
	 * @param  array  $columns         The columns to check
	 * @return void
	 */
	static public function checkOnlyOneRule($table, &$record_values, $columns)
	{
		settype($columns, 'array');
		
		$found_value = FALSE;
		foreach ($columns as $column) {
			if ($record_values[$column] !== NULL) {
				if ($found_value) {
					$column_names = '';
					$column_num = 0;
					foreach ($columns as $column) {
						if ($column_num) { $column_names .= ', '; }
						$column_names .= fORM::getColumnName($table, $column);
						$column_num++;	
					}
					fCore::toss('fValidationException', $column_names . ': Please enter a value for only one');	
				}
				$found_value = TRUE;	
			}
		}		
	}
	
	
	/**
	 * Validates against a many-to-many validation rule
	 *
	 * @throws  fValidationException
	 * 
	 * @param  array  &$values                An associative array of all values for the record
	 * @param  string $plural_related_column  The plural name of the related column
	 * @return void
	 */
	static public function checkManyToManyRule(&$values, $plural_related_column)
	{
		if (!isset($values[$plural_related_column]) || empty($values[$plural_related_column])) {
			fCore::toss('fValidationException', fInflection::humanize($plural_related_column) . ': Please select at least one');	
		}			
	}
	
	
	/**
	 * Checks a value to make sure it is the right format
	 *
	 * @throws  fValidationException
	 * 
	 * @param  string $table        The table the column is located in
	 * @param  array  &$values      An associative array of all values for the record
	 * @param  string $column       The column to check
	 * @param  string $format_type  The type of formatting the column should have
	 * @return void
	 */
	static public function checkFormattingRule($table, &$values, $column, $format_type)
	{
		if ($values[$column] === NULL) {
			return;
		}			
		
		if ($format_type == 'email') {
			if (!preg_match('#^[a-z0-9\\.\\-\'_]+@([a-z0-9\\-]+\.)+[a-z]{2,}$#i', $values[$column])) {
				fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter an email address in the form name@example.com');	
			}	
		
		} elseif ($format_type == 'link') {
			if (!preg_match('#^(http(s)?://|/|([a-z0-9\\-]+\.)+[a-z]{2,})#i', $values[$column])) {
				fCore::toss('fValidationException', fORM::getColumnName($table, $column) . ': Please enter a link in the form http://www.example.com');	
			}
			if (preg_match('#^([a-z0-9\\-]+\.)+[a-z]{2,}#i', $values[$column])) {
				$values[$column] = 'http://' . $values[$column];	
			} elseif (substr($values[$column], 0, 1) == '/') {
				$values[$column] = fURL::getDomain() . $values[$column];	
			}
		}
	}
}


/**
 * Copyright (c) 2007 William Bond <will@flourishlib.com>
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
?>