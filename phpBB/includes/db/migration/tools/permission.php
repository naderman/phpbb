<?php
/**
*
* @package migration
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License v2
*
*/

class phpbb_db_migration_tools_permission extends phpbb_db_migration_tools_base
{
	/** @var phpbb_auth */
	protected $auth = null;

	/** @var phpbb_cache_service */
	protected $cache = null;

	/** @var dbal */
	protected $db = null;

	/** @var string */
	protected $phpbb_root_path = null;

	/** @var string */
	protected $php_ext = null;

	public function __construct(dbal $db, phpbb_cache_driver_interface $cache, phpbb_auth $auth, $phpbb_root_path, $php_ext)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->auth = $auth;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
	}

	/**
	* Permission Exists
	*
	* Check if a permission (auth) setting exists
	*
	* @param string $auth_option The name of the permission (auth) option
	* @param bool $global True for checking a global permission setting, False for a local permission setting
	*
	* @return bool true if it exists, false if not
	*/
	public function exists($auth_option, $global = true)
	{
		if ($global)
		{
			$type_sql = ' AND is_global = 1';
		}
		else
		{
			$type_sql = ' AND is_local = 1';
		}

		$sql = 'SELECT auth_option_id
			FROM ' . ACL_OPTIONS_TABLE . "
			WHERE auth_option = '" . $this->db->sql_escape($auth_option) . "'"
				. $type_sql;
		$result = $this->db->sql_query($sql);

		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			return true;
		}

		return false;
	}

	/**
	* Permission Add
	*
	* Add a permission (auth) option
	*
	* @param string $auth_option The name of the permission (auth) option
	* @param bool $global True for checking a global permission setting, False for a local permission setting
	*
	* @return result
	*/
	public function add($auth_option, $global = true)
	{
		if ($this->exists($auth_option, $global))
		{
			throw new phpbb_db_migration_exception('PERMISSION_ALREADY_EXISTS', $auth_option);
		}

		// We've added permissions, so set to true to notify the user.
		$this->permissions_added = true;

		if (!class_exists('auth_admin'))
		{
			include($this->phpbb_root_path . 'includes/acp/auth.' . $this->php_ext);
		}
		$auth_admin = new auth_admin();

		// We have to add a check to see if the !$global (if global, local, and if local, global) permission already exists.  If it does, acl_add_option currently has a bug which would break the ACL system, so we are having a work-around here.
		if ($this->exists($auth_option, !$global))
		{
			$sql_ary = array(
				'is_global'	=> 1,
				'is_local'	=> 1,
			);
			$sql = 'UPDATE ' . ACL_OPTIONS_TABLE . '
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE auth_option = \'' . $this->db->sql_escape($auth_option) . "'";
			$this->db->sql_query($sql);
		}
		else
		{
			if ($global)
			{
				$auth_admin->acl_add_option(array('global' => array($auth_option)));
			}
			else
			{
				$auth_admin->acl_add_option(array('local' => array($auth_option)));
			}
		}

		return false;
	}

	/**
	* Permission Remove
	*
	* Remove a permission (auth) option
	*
	* @param string $auth_option The name of the permission (auth) option
	* @param bool $global True for checking a global permission setting, False for a local permission setting
	*
	* @return result
	*/
	public function remove($auth_option, $global = true)
	{
		if (!$this->exists($auth_option, $global))
		{
			throw new phpbb_db_migration_exception('PERMISSION_NOT_EXIST', $auth_option);
		}

		if ($global)
		{
			$type_sql = ' AND is_global = 1';
		}
		else
		{
			$type_sql = ' AND is_local = 1';
		}
		$sql = 'SELECT auth_option_id, is_global, is_local FROM ' . ACL_OPTIONS_TABLE . "
			WHERE auth_option = '" . $this->db->sql_escape($auth_option) . "'" .
				$type_sql;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$id = $row['auth_option_id'];

		// If it is a local and global permission, do not remove the row! :P
		if ($row['is_global'] && $row['is_local'])
		{
			$sql = 'UPDATE ' . ACL_OPTIONS_TABLE . '
				SET ' . (($global) ? 'is_global = 0' : 'is_local = 0') . '
				WHERE auth_option_id = ' . $id;
			$this->db->sql_query($sql);
		}
		else
		{
			// Delete time
			$this->db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . ' WHERE auth_option_id = ' . $id);
			$this->db->sql_query('DELETE FROM ' . ACL_ROLES_DATA_TABLE . ' WHERE auth_option_id = ' . $id);
			$this->db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . ' WHERE auth_option_id = ' . $id);
			$this->db->sql_query('DELETE FROM ' . ACL_OPTIONS_TABLE . ' WHERE auth_option_id = ' . $id);
		}

		// Purge the auth cache
		$this->cache->destroy('_acl_options');
		$this->auth->acl_clear_prefetch();

		return false;
	}

	/**
	* Add a new permission role
	*
	* @param string $role_name The new role name
	* @param sting $role_type The type (u_, m_, a_)
	*/
	public function role_add($role_name, $role_type = '', $role_description = '')
	{
		$sql = 'SELECT role_id FROM ' . ACL_ROLES_TABLE . '
			WHERE role_name = \'' . $this->db->sql_escape($role_name) . '\'';
		$this->db->sql_query($sql);
		$role_id = $this->db->sql_fetchfield('role_id');

		if ($role_id)
		{
			throw new phpbb_db_migration_exception('ROLE_ALREADY_EXISTS', $old_role_name);
		}

		$sql = 'SELECT MAX(role_order) AS max FROM ' . ACL_ROLES_TABLE . '
			WHERE role_type = \'' . $this->db->sql_escape($role_type) . '\'';
		$this->db->sql_query($sql);
		$role_order = $this->db->sql_fetchfield('max');
		$role_order = (!$role_order) ? 1 : $role_order + 1;

		$sql_ary = array(
			'role_name'			=> $role_name,
			'role_description'	=> $role_description,
			'role_type'			=> $role_type,
			'role_order'		=> $role_order,
		);

		$sql = 'INSERT INTO ' . ACL_ROLES_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
		$this->db->sql_query($sql);

		return false;
	}

	/**
	* Update the name on a permission role
	*
	* @param string $old_role_name The old role name
	* @param string $new_role_name The new role name
	*/
	public function role_update($old_role_name, $new_role_name = '')
	{
		$sql = 'SELECT role_id FROM ' . ACL_ROLES_TABLE . '
			WHERE role_name = \'' . $this->db->sql_escape($old_role_name) . '\'';
		$this->db->sql_query($sql);
		$role_id = $this->db->sql_fetchfield('role_id');

		if (!$role_id)
		{
			throw new phpbb_db_migration_exception('ROLE_NOT_EXISTS', $old_role_name);
		}

		$sql = 'UPDATE ' . ACL_ROLES_TABLE . '
			SET role_name = \'' . $this->db->sql_escape($new_role_name) . '\'
			WHERE role_name = \'' . $this->db->sql_escape($old_role_name) . '\'';
		$this->db->sql_query($sql);

		return false;
	}

	/**
	* Remove a permission role
	*
	* @param string $role_name The role name to remove
	*/
	public function role_remove($role_name)
	{
		$sql = 'SELECT role_id FROM ' . ACL_ROLES_TABLE . '
			WHERE role_name = \'' . $this->db->sql_escape($role_name) . '\'';
		$this->db->sql_query($sql);
		$role_id = $this->db->sql_fetchfield('role_id');

		if (!$role_id)
		{
			throw new phpbb_db_migration_exception('ROLE_NOT_EXIST', $role_name);
		}

		$sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
			WHERE role_id = ' . $role_id;
		$this->db->sql_query($sql);

		$sql = 'DELETE FROM ' . ACL_ROLES_TABLE . '
			WHERE role_id = ' . $role_id;
		$this->db->sql_query($sql);

		$this->auth->acl_clear_prefetch();

		return false;
	}

	/**
	* Permission Set
	*
	* Allows you to set permissions for a certain group/role
	*
	* @param string $name The name of the role/group
	* @param string|array $auth_option The auth_option or array of auth_options you would like to set
	* @param string $type The type (role|group)
	* @param bool $has_permission True if you want to give them permission, false if you want to deny them permission
	*/
	public function permission_set($name, $auth_option = array(), $type = 'role', $has_permission = true)
	{
		if (!is_array($auth_option))
		{
			$auth_option = array($auth_option);
		}

		$new_auth = array();
		$sql = 'SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . '
			WHERE ' . $this->db->sql_in_set('auth_option', $auth_option);
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$new_auth[] = $row['auth_option_id'];
		}
		$this->db->sql_freeresult($result);

		if (!sizeof($new_auth))
		{
			return false;
		}

		$current_auth = array();

		$type = (string) $type; // Prevent PHP bug.

		switch ($type)
		{
			case 'role' :
				$sql = 'SELECT role_id FROM ' . ACL_ROLES_TABLE . '
					WHERE role_name = \'' . $this->db->sql_escape($name) . '\'';
				$this->db->sql_query($sql);
				$role_id = $this->db->sql_fetchfield('role_id');

				if (!$role_id)
				{
					throw new phpbb_db_migration_exception('ROLE_NOT_EXIST', $name);
				}

				$sql = 'SELECT auth_option_id, auth_setting FROM ' . ACL_ROLES_DATA_TABLE . '
					WHERE role_id = ' . $role_id;
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					$current_auth[$row['auth_option_id']] = $row['auth_setting'];
				}
				$this->db->sql_freeresult($result);
			break;

			case 'group' :
				$sql = 'SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_name = \'' . $this->db->sql_escape($name) . '\'';
				$this->db->sql_query($sql);
				$group_id = $this->db->sql_fetchfield('group_id');

				if (!$group_id)
				{
					throw new phpbb_db_migration_exception('GROUP_NOT_EXIST', $name);
				}

				// If the group has a role set for them we will add the requested permissions to that role.
				$sql = 'SELECT auth_role_id FROM ' . ACL_GROUPS_TABLE . '
					WHERE group_id = ' . $group_id . '
						AND auth_role_id <> 0
						AND forum_id = 0';
				$this->db->sql_query($sql);
				$role_id = $this->db->sql_fetchfield('auth_role_id');
				if ($role_id)
				{
					$sql = 'SELECT role_name FROM ' . ACL_ROLES_TABLE . '
						WHERE role_id = ' . $role_id;
					$this->db->sql_query($sql);
					$role_name = $this->db->sql_fetchfield('role_name');

					return $this->set($role_name, $auth_option, 'role', $has_permission);
				}

				$sql = 'SELECT auth_option_id, auth_setting FROM ' . ACL_GROUPS_TABLE . '
					WHERE group_id = ' . $group_id;
				$result = $this->db->sql_query($sql);
				while ($row = $this->db->sql_fetchrow($result))
				{
					$current_auth[$row['auth_option_id']] = $row['auth_setting'];
				}
				$this->db->sql_freeresult($result);
			break;
		}

		$sql_ary = array();
		switch ($type)
		{
			case 'role' :
				foreach ($new_auth as $auth_option_id)
				{
					if (!isset($current_auth[$auth_option_id]))
					{
						$sql_ary[] = array(
							'role_id'			=> $role_id,
							'auth_option_id'	=> $auth_option_id,
							'auth_setting'		=> $has_permission,
				        );
					}
				}

				$this->db->sql_multi_insert(ACL_ROLES_DATA_TABLE, $sql_ary);
			break;

			case 'group' :
				foreach ($new_auth as $auth_option_id)
				{
					if (!isset($current_auth[$auth_option_id]))
					{
						$sql_ary[] = array(
							'group_id'			=> $group_id,
							'auth_option_id'	=> $auth_option_id,
							'auth_setting'		=> $has_permission,
				        );
					}
				}

				$this->db->sql_multi_insert(ACL_GROUPS_TABLE, $sql_ary);
			break;
		}

		$this->auth->acl_clear_prefetch();

		return false;
	}

	/**
	* Permission Unset
	*
	* Allows you to unset (remove) permissions for a certain group/role
	*
	* @param string $name The name of the role/group
	* @param string|array $auth_option The auth_option or array of auth_options you would like to set
	* @param string $type The type (role|group)
	*/
	public function permission_unset($name, $auth_option = array(), $type = 'role')
	{
		if (!is_array($auth_option))
		{
			$auth_option = array($auth_option);
		}

		$to_remove = array();
		$sql = 'SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . '
			WHERE ' . $this->db->sql_in_set('auth_option', $auth_option);
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$to_remove[] = $row['auth_option_id'];
		}
		$this->db->sql_freeresult($result);

		if (!sizeof($to_remove))
		{
			return false;
		}

		$type = (string) $type; // Prevent PHP bug.

		switch ($type)
		{
			case 'role' :
				$sql = 'SELECT role_id FROM ' . ACL_ROLES_TABLE . '
					WHERE role_name = \'' . $this->db->sql_escape($name) . '\'';
				$this->db->sql_query($sql);
				$role_id = $this->db->sql_fetchfield('role_id');

				if (!$role_id)
				{
				throw new phpbb_db_migration_exception('ROLE_NOT_EXIST', $name);
				}

				$sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
					WHERE ' . $this->db->sql_in_set('auth_option_id', $to_remove);
				$this->db->sql_query($sql);
			break;

			case 'group' :
				$sql = 'SELECT group_id FROM ' . GROUPS_TABLE . '
					WHERE group_name = \'' . $this->db->sql_escape($name) . '\'';
				$this->db->sql_query($sql);
				$group_id = $this->db->sql_fetchfield('group_id');

				if (!$group_id)
				{
					throw new phpbb_db_migration_exception('GROUP_NOT_EXIST', $name);
				}

				// If the group has a role set for them we will remove the requested permissions from that role.
				$sql = 'SELECT auth_role_id FROM ' . ACL_GROUPS_TABLE . '
					WHERE group_id = ' . $group_id . '
						AND auth_role_id <> 0';
				$this->db->sql_query($sql);
				$role_id = $this->db->sql_fetchfield('auth_role_id');
				if ($role_id)
				{
					$sql = 'SELECT role_name FROM ' . ACL_ROLES_TABLE . '
						WHERE role_id = ' . $role_id;
					$this->db->sql_query($sql);
					$role_name = $this->db->sql_fetchfield('role_name');

					return $this->permission_unset($role_name, $auth_option, 'role');
				}

				$sql = 'DELETE FROM ' . ACL_GROUPS_TABLE . '
					WHERE ' . $this->db->sql_in_set('auth_option_id', $to_remove);
				$this->db->sql_query($sql);
			break;
		}

		$this->auth->acl_clear_prefetch();

		return false;
	}
}