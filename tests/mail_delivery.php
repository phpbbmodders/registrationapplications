<?php
/**
 * Exercise real SMTP delivery on a marked disposable board with a local sink.
 * Usage: php tests/mail_delivery.php BOARD_ROOT
 * Results: BOARD_ROOT/store/ra-mail-expectations.json (synthetic data only).
 * GPL-2.0-only.
 */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || in_array($argv[1], array('-h', '--help'), true))
{
	echo "Usage: php tests/mail_delivery.php BOARD_ROOT\nRequires a marked disposable board and a local SMTP catcher.\n";
	exit(0);
}
$phpbb_root_path = realpath($argv[1]) . '/';
if (!is_file($phpbb_root_path . '.ra-disposable-test'))
{
	fwrite(STDERR, "Refusing an unmarked board.\n");
	exit(2);
}
define('IN_PHPBB', true);
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
require_once $phpbb_root_path . 'includes/functions_user.php';
if (!$config['smtp_delivery'] || !in_array($config['smtp_host'], array('ra-regapps-mail', '127.0.0.1', 'localhost'), true))
{
	fwrite(STDERR, "Refusing delivery outside a local test SMTP sink.\n");
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
$token = bin2hex(random_bytes(4));
$accounts = array();
$private_marker = 'Private answer never included in approval email ' . $token;
foreach (array('approved', 'retained', 'deleted') as $kind)
{
	$name = 'mail_' . substr($kind, 0, 3) . '_' . $token;
	$email = $name . '@example.invalid';
	$id = user_add(array(
		'username' => $name, 'user_email' => $email, 'user_password' => 'unusable-test-password',
		'group_id' => $group_id, 'user_type' => USER_INACTIVE,
		'user_inactive_reason' => INACTIVE_REGISTER, 'user_inactive_time' => time(), 'user_actkey' => 'fixture-key',
	));
	$answers = array();
	foreach ($manager->questions() as $question)
	{
		$answers[$question['question_id']] = $private_marker;
	}
	$manager->submit($id, $answers);
	$accounts[$kind] = array('id' => (int) $id, 'username' => $name, 'email' => $email);
}
$sent = $manager->decide($accounts['approved']['id'], 'approve', $manager->denial_mode());
if ($sent !== true)
{
	throw new \RuntimeException('Approval email was not accepted by the SMTP transport');
}
try
{
	$manager->decide($accounts['approved']['id'], 'approve', $manager->denial_mode());
	throw new \RuntimeException('Replay unexpectedly succeeded');
}
catch (\RuntimeException $exception)
{
	if ($exception->getMessage() !== 'RA_STALE')
	{
		throw $exception;
	}
}
$config->set('ra_denial_mode', 'retain');
$manager->decide($accounts['retained']['id'], 'deny', 'retain');
$config->set('ra_denial_mode', 'delete');
$manager->decide($accounts['deleted']['id'], 'deny', 'delete');
$config->set('ra_denial_mode', 'retain');
$expectations = array('board_version' => PHPBB_VERSION, 'token' => $token, 'private_marker' => $private_marker, 'accounts' => $accounts);
file_put_contents($phpbb_root_path . 'store/ra-mail-expectations.json', json_encode($expectations, JSON_PRETTY_PRINT));
echo "Created one approved and two denied SMTP fixtures; rejected a replay. Verify actual receipts in the catcher.\n";
