<?php
/**
*
* @package db
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* The migrator is responsible for applying new migrations in the correct order.
*
* @package db
*/
class phpbb_db_migration_exception extends \Exception
{
	protected $parameters;

	public function __construct()
	{
		$parameters = func_get_args();
		$message = array_shift($parameters);
		parent::__construct($message);

		$this->parameters = $parameters;
	}

	public function __toString()
	{
		return $this->message . ': ' . var_export($parameters, true);
	}
}
