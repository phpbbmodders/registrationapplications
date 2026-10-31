<?php
/** Verify SMTP failure preserves approval and is reported. GPL-2.0-only. */
if (PHP_SAPI !== 'cli' || empty($argv[1]))
{
	echo "Usage: php tests/mail_failure.php BOARD_ROOT\nRequires a marked board and ra-regapps-mail SMTP catcher.\n";
	exit(0);
}
$phpbb_root_path = realpath($argv[1]) . '/';
if (!is_file($phpbb_root_path . '.ra-disposable-test'))
{
	exit(2);
}
define('IN_PHPBB', true);
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
require_once $phpbb_root_path . 'includes/functions_user.php';
if (!$config['smtp_delivery'] || $config['smtp_host'] !== 'ra-regapps-mail')
{
	fwrite(STDERR, "Only the isolated SMTP test catcher is supported.\n");
	exit(2);
}
$user->session_begin();
$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id = 2');
$user->data = array_merge($user->data, $db->sql_fetchrow($result));
$db->sql_freeresult($result);
$auth->acl($user->data);
$user->setup();
$manager = $phpbb_container->get('phpbbmodders.registrationapplications.manager');
$result = $db->sql_query("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'REGISTERED' AND group_type = " . GROUP_SPECIAL);
$group_id = (int) $db->sql_fetchfield('group_id');
$db->sql_freeresult($result);
$name = 'mail_fail_' . bin2hex(random_bytes(4));
$email = $name . '@example.invalid';
$id = user_add(array(
	'username' => $name, 'user_email' => $email, 'user_password' => 'unusable-test-password',
	'group_id' => $group_id, 'user_type' => USER_INACTIVE,
	'user_inactive_reason' => INACTIVE_REGISTER, 'user_inactive_time' => time(), 'user_actkey' => 'fixture-key',
));
$manager->submit($id, array());
$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . LOG_TABLE . " WHERE log_type = " . LOG_CRITICAL);
$before = (int) $db->sql_fetchfield('total');
$db->sql_freeresult($result);
$original_port = $config['smtp_port'];
try
{
	// The owned catcher has no listener at this port: force a real refusal.
	$config->set('smtp_port', 65534);
	$sent = $manager->decide($id, 'approve', $manager->denial_mode());
}
finally
{
	$config->set('smtp_port', $original_port);
}
if ($sent !== false)
{
	throw new \RuntimeException('Failed SMTP delivery was not reported to the caller');
}
$result = $db->sql_query('SELECT user_type, user_actkey FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $id);
$account = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if ((int) $account['user_type'] !== USER_NORMAL || $account['user_actkey'] !== '')
{
	throw new \RuntimeException('SMTP failure changed the saved activation');
}
$table = $table_prefix . 'ra_applications';
$result = $db->sql_query('SELECT status, answers FROM ' . $table . ' WHERE user_id = ' . (int) $id);
$application = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if ($application['status'] !== 'approved' || $application['answers'] !== '[]')
{
	throw new \RuntimeException('SMTP failure changed the saved review or answer cleanup');
}
$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . LOG_TABLE . " WHERE log_type = " . LOG_CRITICAL);
$after = (int) $db->sql_fetchfield('total');
$db->sql_freeresult($result);
if ($after <= $before)
{
	throw new \RuntimeException('SMTP failure was not recorded in the board error log');
}
file_put_contents($phpbb_root_path . 'store/ra-mail-failure.json', json_encode(array('mode' => 'failure', 'email' => $email, 'user_id' => (int) $id)));
echo "PASS: real SMTP refusal reported; approval and answer cleanup remain committed; board error log records failure.\n";
