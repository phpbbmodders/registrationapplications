<?php
/** Registration Applications ACP metadata. GPL-2.0-only. */
namespace phpbbmodders\registrationapplications\acp;

class main_info
{
	public function module()
	{
		return array(
			'filename' => '\phpbbmodders\registrationapplications\acp\main_module',
			'title' => 'ACP_RA_TITLE',
			'modes' => array('settings' => array('title' => 'ACP_RA_SETTINGS', 'auth' => 'ext_phpbbmodders/registrationapplications && acl_a_board', 'cat' => array('ACP_RA_TITLE'))),
		);
	}
}
