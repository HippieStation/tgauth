<?php

define('OAUTH2_CLIENT_ID', '');
define('OAUTH2_CLIENT_SECRET', '');

$authorizeURL = 'https://www.reddit.com/api/v1/authorize';
$tokenURL = 'https://www.reddit.com/api/v1/access_token';
$apiURLBase = 'https://oauth.reddit.com/api/v1/';

//define('FROM_MEDIAWIKI', true); //to hook into the phpbbSSO wiki extension
		
//stuff phpbb wants defined.
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
define('IN_PHPBB', true);
$phpEx = substr(strrchr(__FILE__, '.'), 1);

include_once($phpbb_root_path.'common.'.$phpEx); //we include the phpbb frame work

$user->session_begin(); //now we let phpbb do all the fancy work of figuring out who the fuck this are.
$userid = (int)$user->data['user_id'];
$usertype = $user->data['user_type'];

if($userid <= 1 || $usertype == 1 || $usertype == 2) {
	header("location: ucp.php?mode=login&redirect=".urlencode("reddit_oauth.php?".$_SERVER["QUERY_STRING"]));
	//print_r($user);
	die();
}
		

session_start();

function generate_token() {
	$secure = FALSE;
	$r_bytes = openssl_random_pseudo_bytes(5120, $secure);
	if (!$secure) {
		for ($i = 1; $i > 1024; $i++)
			$r_bytes .= openssl_random_pseudo_bytes(5120);
	}
	return hash('sha512', $r_bytes);
}

// Start the login process by sending the user to Github's authorization page
if($_GET['action'] == 'login') {
	// Generate a random hash and store in the session for security
	$_SESSION['state'] = generate_token();
	unset($_SESSION['access_token']);

	$params = array(
		'client_id' => OAUTH2_CLIENT_ID,
		'redirect_uri' => 'https://tgstation13.org/phpBB/reddit_oauth.php',
		'response_type' => 'code',
		'scope' => 'identity',
		'duration' => 'temporary',
		'state' => $_SESSION['state']
	);

	// Redirect the user to Github's authorization page
	header('Location: ' . $authorizeURL . '?' . http_build_query($params));
	die();
}

// When Github redirects the user back here, there will be a "code" and "state" parameter in the query string
if($_GET['code']) {
	// Verify the state matches our stored state
	if(!$_GET['state'])
		die('No state.');
	if ($_SESSION['state'] != $_GET['state'])
		die('Invalid state.');
	
	// Exchange the auth code for a token
	$res = apisend($tokenURL, 'POST', http_build_query(array(
		'grant_type' => 'authorization_code',
		'redirect_uri' => 'https://tgstation13.org/phpBB/reddit_oauth.php',
		'code' => $_GET['code']
	)), 'basic '.base64_encode(OAUTH2_CLIENT_ID.':'.OAUTH2_CLIENT_SECRET));
	$token = json_decode($res, TRUE);
	$session = $token['access_token'];
	if (!$session)
		die('Could not get auth token from reddit <!--'.$res.'-->');
	$res = apisend($apiURLBase . 'me', 'GET', null, 'bearer '.$session);
	$user = json_decode($res, TRUE);
	if (!$user || !$user['name'])
		die('Auth token error<!--'.$res.'-->');
	
	$username = $user['name'];
	$bannedusernames = array();
	
	$sql = "SELECT u.username AS username FROM `phpbb_banlist` AS b LEFT JOIN `phpbb_profile_fields_data` AS f ON (b.ban_userid = f.user_id) LEFT JOIN `phpbb_users` AS u on (u.user_id = b.ban_userid) WHERE b.ban_userid > 0 AND f.pf_reddit IS NOT NULL AND ban_exclude <= 0 AND (ban_end = 0 OR ban_end > UNIX_TIMESTAMP()) AND f.pf_reddit = '".$db->sql_escape($username)."'";
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result))
		$bannedusernames[] = $row['username'];
	
	if (count($bannedusernames) > 0) {
		print("You can not link this reddit account while it is banned on another forum account.<br>");
		print("The following forum accounts are linked to this reddit account and forum banned:<br>");
		foreach ($bannedusernames as $bannedusername)
			print($bannedusername."<br>");
		die();
	}
	
	$sql = "INSERT INTO phpbb_profile_fields_data (user_id,pf_reddit) VALUES (".$userid.", '".$db->sql_escape($username)."') ON DUPLICATE KEY UPDATE pf_reddit='".$db->sql_escape($username)."'";
	$db->sql_freeresult($db->sql_query($sql));
	
	$sql = "INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES (28, ".$userid.", 0) ON DUPLICATE KEY UPDATE user_pending=0";
	$db->sql_freeresult($db->sql_query($sql));
	
	$auth->acl_clear_prefetch($userid);
	
	
	$redirect = "memberlist.php?mode=viewprofile&u=".$userid;
	header("location: ".$redirect);
}


echo '<p><a href="?action=login">Link reddit account with your forum account.</a></p>';



function apisend($url, $method = 'GET', $content = NULL, $session = NULL) {
	if (is_array($content))
		$content = json_encode($content);

	$scontext = array('http' => array(
		'method'	=> $method,
		'header'	=>
			"Content-type: application/x-www-form-urlencoded"
			/*"Accept: application/json"*/,
		'ignore_errors' => true,
		'user_agent'	=> 'tgstation13.org-reddit-Automation-Tools'
	));

	if ($content)
		$scontext['http']['content'] = $content;
	if($session)
		$scontext['http']['header'] .= "\r\n".'Authorization: ' . $session;
	return file_get_contents($url, false, stream_context_create($scontext));
}

function get($key, $default=NULL) {
	return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
}
