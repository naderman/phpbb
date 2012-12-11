<?php
/**
*
* @package migration
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License v2
*
*/

class phpbb_db_migration_data_extensions extends phpbb_db_migration
{
	public function depends_on()
	{
		return array('phpbb_db_migration_data_3_0_11');
	}

	public function update_schema()
	{
		return array(
			'add_tables'		=> array(
				EXT_TABLE				=> array(
					'COLUMNS'			=> array(
						'ext_name'		=> array('VCHAR', ''),
						'ext_active'	=> array('BOOL', 0),
						'ext_state'		=> array('TEXT', ''),
					),
					'KEYS'				=> array(
						'ext_name'		=> array('UNIQUE', 'ext_name'),
					),
				),
			),
		);
	}

	public function update_data()
	{
		return array(
			array('module.add', array(
				'acp',
				'ACP_GENERAL_TASKS',
				array(
					'module_basename'	=> 'extensions',
					'modes'				=> array('main'),
				),
			)),
		);
	}
}
