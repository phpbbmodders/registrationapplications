<?php
/**
 * Registration Applications. Licensed under GPL-2.0-only.
 */
namespace phpbbmodders\registrationapplications;

class ext extends \phpbb\extension\base
{
	public function is_enableable()
	{
		$language = $this->container->get('language');
		$language->add_lang('common', 'phpbbmodders/registrationapplications');
		$config = $this->container->get('config');
		if (!phpbb_version_compare(PHPBB_VERSION, '3.3.0', '>=') ||
			!phpbb_version_compare(PHPBB_VERSION, '4.1.0-dev', '<') ||
			(int) $config['require_activation'] !== USER_ACTIVATION_ADMIN || !$config['email_enable'])
		{
			return $language->lang('RA_REQUIREMENTS');
		}
		return true;
	}
}
