<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2023, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace privet\ailabs\migrations\v1x;

class release_1_0_7_schema extends \phpbb\db\migration\migration
{
	static public function depends_on()
	{
		return array('\privet\ailabs\migrations\v1x\release_1_0_6_schema');
	}

	public function effectively_installed()
	{
		return isset($this->config['privet_ailabs_version']) && version_compare($this->config['privet_ailabs_version'], '1.0.7', '>=');
	}

	public function update_data()
	{
		return array(
			array('config.update', array('privet_ailabs_version', '1.0.7')),
		);
	}

	public function revert_data()
	{
		return array(
			array('config.update', array('privet_ailabs_version', '1.0.6')),
		);
	}
}
