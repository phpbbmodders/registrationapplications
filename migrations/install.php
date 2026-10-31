<?php
/**
 * Registration Applications schema and permissions. GPL-2.0-only.
 */
namespace phpbbmodders\registrationapplications\migrations;

class install extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb\db\migration\data\v330\v330');
	}

	public function update_schema()
	{
		return array('add_tables' => array(
			$this->table_prefix . 'ra_questions' => array(
				'COLUMNS' => array(
					'question_id' => array('UINT', null, 'auto_increment'),
					'question_label' => array('VCHAR_UNI:255', ''),
					'question_required' => array('BOOL', 1),
					'min_chars' => array('UINT', 0),
					'max_chars' => array('UINT', 3000),
					'question_order' => array('UINT', 0),
					'question_enabled' => array('BOOL', 1),
				),
				'PRIMARY_KEY' => 'question_id',
			),
			$this->table_prefix . 'ra_applications' => array(
				'COLUMNS' => array(
					'user_id' => array('UINT', 0),
					'answers' => array('MTEXT_UNI', ''),
					'submitted_at' => array('TIMESTAMP', 0),
					'status' => array('VCHAR:16', 'pending'),
					'reviewed_at' => array('TIMESTAMP', 0),
					'reviewer_id' => array('UINT', 0),
				),
				'PRIMARY_KEY' => 'user_id',
				'KEYS' => array('queue' => array('INDEX', array('status', 'submitted_at'))),
			),
		));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'ra_questions', $this->table_prefix . 'ra_applications'));
	}

	public function update_data()
	{
		return array(
			array('config.add', array('ra_denial_mode', 'retain')),
			array('permission.add', array('m_ra_view', true)),
			array('permission.add', array('m_ra_approve', true)),
			array('permission.add', array('m_ra_deny', true)),
			array('permission.permission_set', array('ADMINISTRATORS', 'm_ra_view', 'group')),
			array('permission.permission_set', array('ADMINISTRATORS', 'm_ra_approve', 'group')),
			array('permission.permission_set', array('ADMINISTRATORS', 'm_ra_deny', 'group')),
			array('module.add', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_RA_TITLE')),
			array('module.add', array('acp', 'ACP_RA_TITLE', array(
				'module_basename' => '\phpbbmodders\registrationapplications\acp\main_module',
				'modes' => array('settings'),
			))),
		);
	}
}
