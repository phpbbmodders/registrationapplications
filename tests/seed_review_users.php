<?php
/**
 * Seed ordinary accounts and pending applications for HTTP access tests.
 * Usage: php tests/seed_review_users.php BOARD_ROOT
 * Synthetic accounts use the public test password RaFixture2026!.
 * GPL-2.0-only.
 */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || in_array($argv[1], array('-h', '--help'), true))
{
	echo "Usage: php tests/seed_review_users.php BOARD_ROOT\nRequires .ra-disposable-test.\n";
	exit(0);
}
$phpbb_root_path = realpath($argv[1]) . '/';
if (!is_file($phpbb_root_path . '.ra-disposable-test'))
{
	fwrite(STDERR, "Refusing to seed an unmarked board.\n");
	exit(2);
}
define('IN_PHPBB', true);
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
require_once $phpbb_root_path . 'includes/functions_user.php';
require_once $phpbb_root_path . 'includes/acp/auth.php';
$user->session_begin();
$auth->acl($user->data);
$user->setup();
$permissions = new \auth_admin();
$result = $db->sql_query("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'REGISTERED' AND group_type = " . GROUP_SPECIAL);
$group_id = (int) $db->sql_fetchfield('group_id');
$db->sql_freeresult($result);
$roles = array(
	'ra_member' => array(),
	'ra_viewer' => array('view'),
	'ra_approver' => array('view', 'approve'),
	'ra_denier' => array('view', 'deny'),
	'ra_reviewer' => array('view', 'approve', 'deny'),
);
foreach ($roles as $name => $grants)
{
	$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean = '" . $db->sql_escape($name) . "'");
	$id = (int) $db->sql_fetchfield('user_id');
	$db->sql_freeresult($result);
	if (!$id)
	{
		$id = user_add(array(
			'username' => $name, 'user_email' => $name . '@example.invalid',
			'user_password' => $phpbb_container->get('passwords.manager')->hash('RaFixture2026!'),
			'group_id' => $group_id, 'user_type' => USER_NORMAL,
		));
	}
	$acl = array();
	foreach (array('view', 'approve', 'deny') as $action)
	{
		$acl['m_ra_' . $action] = in_array($action, $grants, true) ? ACL_YES : ACL_NO;
	}
	$permissions->acl_set('user', 0, $id, $acl);
	echo $name . ': user_id=' . $id . "; USER_NORMAL; " . implode(', ', $grants) . "\n";
}
$manager = $phpbb_container->get('phpbbmodders.registrationapplications.manager');
if (!$manager->questions())
{
	$table = $phpbb_container->getParameter('phpbbmodders.registrationapplications.questions_table');
	$db->sql_query('INSERT INTO ' . $table . ' ' . $db->sql_build_array('INSERT', array('question_label' => 'Why would you like to join?')));
}
if ($manager->count_pending() < 3)
{
	$token = bin2hex(random_bytes(4));
	for ($i = 0; $i < 4; $i++)
	{
		$name = 'ra_http_' . $token . '_' . $i;
		$id = user_add(array(
			'username' => $name, 'user_email' => $name . '@example.invalid',
			'user_password' => 'unusable-test-password', 'group_id' => $group_id,
			'user_type' => USER_INACTIVE, 'user_inactive_reason' => INACTIVE_REGISTER,
			'user_inactive_time' => time(), 'user_actkey' => 'fixture-key',
		));
		$answers = array();
		foreach ($manager->questions() as $question)
		{
			$answers[$question['question_id']] = str_repeat('A', min(max(3, (int) $question['min_chars']), (int) $question['max_chars']));
		}
		$manager->submit($id, $answers);
	}
	echo "Created four pending synthetic applications for link tests.\n";
}
