<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-app-users.php
	Version: See define()s at top of as-include/as-base.php
	Description: User management (application level) for basic user operations


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: http://www.question2answer.org/license.php
*/

	if (!defined('AS_VERSION')) { // don't allow this page to be requested directly from browser
		header('Location: ../');
		exit;
	}

	define('AS_USER_LEVEL_BASIC', 0);
	define('AS_USER_LEVEL_APPROVED', 10);
	define('AS_USER_LEVEL_EXPERT', 20);
	define('AS_USER_LEVEL_EDITOR', 50);
	define('AS_USER_LEVEL_MODERATOR', 80);
	define('AS_USER_LEVEL_ADMIN', 100);
	define('AS_USER_LEVEL_SUPER', 120);
	
	define('AS_USER_FLAGS_EMAIL_CONFIRMED', 1);
	define('AS_USER_FLAGS_USER_BLOCKED', 2);
	define('AS_USER_FLAGS_SHOW_AVATAR', 4);
	define('AS_USER_FLAGS_SHOW_GRAVATAR', 8);
	define('AS_USER_FLAGS_NO_MESSAGES', 16);
	define('AS_USER_FLAGS_NO_MAILINGS', 32);
	define('AS_USER_FLAGS_WELCOME_NOTICE', 64);
	define('AS_USER_FLAGS_MUST_CONFIRM', 128);
	define('AS_USER_FLAGS_NO_WALL_POSTS', 256);
	define('AS_USER_FLAGS_MUST_APPROVE', 512);
	
	define('AS_FIELD_FLAGS_MULTI_LINE', 1);
	define('AS_FIELD_FLAGS_LINK_URL', 2);
	define('AS_FIELD_FLAGS_ON_REGISTER', 4);
	
	@define('AS_FORM_EXPIRY_SECS', 86400); // how many seconds a form is valid for submission
	@define('AS_FORM_KEY_LENGTH', 32);

	
	if (AS_FINAL_EXTERNAL_USERS) {

	//	If we're using single sign-on integration (WordPress or otherwise), load PHP file for that

		if (defined('AS_FINAL_WORDPRESS_INTEGRATE_PATH'))
			require_once AS_INCLUDE_DIR.'as-external-users-wp.php';
		else
			require_once AS_EXTERNAL_DIR.'as-external-users.php';
		

	//	Access functions for user information
	
		function as_get_logged_in_user_cache()
	/*
		Return array of information about the currently logged in user, cache to ensure only one call to external code
	*/
		{
			global $as_cached_logged_in_user;
			
			if (!isset($as_cached_logged_in_user)) {
				$user=as_get_logged_in_user();
				$as_cached_logged_in_user=isset($user) ? $user : false; // to save trying again
			}
			
			return @$as_cached_logged_in_user;
		}
		
		
		function as_get_logged_in_user_field($field)
	/*
		Return $field of the currently logged in user, or null if not available
	*/
		{
			$user=as_get_logged_in_user_cache();
			
			return @$user[$field];
		}


		function as_get_logged_in_userid()
	/*
		Return the userid of the currently logged in user, or null if none
	*/
		{
			return as_get_logged_in_user_field('userid');
		}
		
		
		function as_get_logged_in_points()
	/*
		Return the number of points of the currently logged in user, or null if none is logged in
	*/
		{
			global $as_cached_logged_in_points;
			
			if (!isset($as_cached_logged_in_points)) {
				require_once AS_INCLUDE_DIR.'as-db-selects.php'; 
				
				$as_cached_logged_in_points=as_db_select_with_pending(as_db_user_points_selectspec(as_get_logged_in_userid(), true));
			}
			
			return $as_cached_logged_in_points['points'];
		}
		
		
		function as_get_external_avatar_html($userid, $size, $padding=false)
	/*
		Return HTML to display for the avatar of $userid, constrained to $size pixels, with optional $padding to that size
	*/
		{
			if (function_exists('as_avatar_html_from_userid'))
				return as_avatar_html_from_userid($userid, $size, $padding);
			else
				return null;
		}
		
		
	} else {
		
		function as_start_session()
	/*
		Open a PHP session if one isn't opened already
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			@ini_set('session.gc_maxlifetime', 86400); // worth a try, but won't help in shared hosting environment
			@ini_set('session.use_trans_sid', false); // sessions need cookies to work, since we redirect after login
			@ini_set('session.cookie_domain', AS_COOKIE_DOMAIN);

			if (!isset($_SESSION))
				session_start();
		}
		
		
		function as_session_var_suffix()
	/*
		Returns a suffix to be used for names of session variables to prevent them being shared between multiple Q2A sites on the same server
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
			
			$prefix=defined('AS_MYSQL_USERS_PREFIX') ? AS_MYSQL_USERS_PREFIX : AS_MYSQL_TABLE_PREFIX;
			
			return md5(AS_FINAL_MYSQL_HOSTNAME.'/'.AS_FINAL_MYSQL_USERNAME.'/'.AS_FINAL_MYSQL_PASSWORD.'/'.AS_FINAL_MYSQL_DATABASE.'/'.$prefix);
		}
		
		
		function as_session_verify_code($userid)
	/*
		Returns a verification code used to ensure that a user session can't be generated by another PHP script running on the same server
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			return sha1($userid.'/'.AS_MYSQL_TABLE_PREFIX.'/'.AS_FINAL_MYSQL_DATABASE.'/'.AS_FINAL_MYSQL_PASSWORD.'/'.AS_FINAL_MYSQL_USERNAME.'/'.AS_FINAL_MYSQL_HOSTNAME);
		}

		
		function as_set_session_cookie($handle, $sessioncode, $remember)
	/*
		Set cookie in browser for username $handle with $sessioncode (in database).
		Pass true if user checked 'Remember me' (either now or previously, as learned from cookie).
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			// if $remember is true, store in browser for a month, otherwise store only until browser is closed
			setcookie('as_session', $handle.'/'.$sessioncode.'/'.($remember ? 1 : 0), $remember ? (time()+2592000) : 0, '/', AS_COOKIE_DOMAIN);
		}

		
		function as_clear_session_cookie()
	/*
		Remove session cookie from browser
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			setcookie('as_session', false, 0, '/', AS_COOKIE_DOMAIN);
		}
		
		
		function as_set_session_user($userid, $source)
	/*
		Set the session variables to indicate that $userid is logged in from $source
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			$suffix=as_session_var_suffix();

			$_SESSION['as_session_userid_'.$suffix]=$userid;
			$_SESSION['as_session_source_'.$suffix]=$source;
			$_SESSION['as_session_verify_'.$suffix]=as_session_verify_code($userid);
				// prevents one account on a shared server being able to create a log in a user to Q2A on another account on same server
		}
		
		
		function as_clear_session_user()
	/*
		Clear the session variables indicating that a user is logged in
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			$suffix=as_session_var_suffix();

			unset($_SESSION['as_session_userid_'.$suffix]);
			unset($_SESSION['as_session_source_'.$suffix]);
			unset($_SESSION['as_session_verify_'.$suffix]);
		}

		
		function as_set_logged_in_user($userid, $handle='', $remember=false, $source=null)
	/*
		Call for successful log in by $userid and $handle or successful log out with $userid=null.
		$remember states if 'Remember me' was checked in the login form.
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			require_once AS_INCLUDE_DIR.'as-app-cookies.php';
			
			as_start_session();

			if (isset($userid)) {
				as_set_session_user($userid, $source);
				
				// PHP sessions time out too quickly on the server side, so we also set a cookie as backup.
				// Logging in from a second browser will make the previous browser's 'Remember me' no longer
				// work - I'm not sure if this is the right behavior - could see it either way.

				require_once AS_INCLUDE_DIR.'as-db-selects.php';
				
				$userinfo=as_db_single_select(as_db_user_account_selectspec($userid, true));
				
				// if we have logged in before, and are logging in the same way as before, we don't need to change the sessioncode/source
				// this means it will be possible to automatically log in (via cookies) to the same account from more than one browser
				
				if (empty($userinfo['sessioncode']) || ($source!==$userinfo['sessionsource'])) {
					$sessioncode=as_db_user_rand_sessioncode();
					as_db_user_set($userid, 'sessioncode', $sessioncode);
					as_db_user_set($userid, 'sessionsource', $source);
				} else
					$sessioncode=$userinfo['sessioncode'];
				
				as_db_user_logged_in($userid, as_remote_ip_address());
				as_set_session_cookie($handle, $sessioncode, $remember);
				
				as_report_event('u_login', $userid, $userinfo['handle'], as_cookie_get());

			} else {
				$olduserid=as_get_logged_in_userid();
				$oldhandle=as_get_logged_in_handle();

				as_clear_session_cookie();
				as_clear_session_user();

				as_report_event('u_logout', $olduserid, $oldhandle, as_cookie_get());
			}
		}
		
		
		function as_log_in_external_user($source, $identifier, $fields)
	/*
		Call to log in a user based on an external identity provider $source with external $identifier
		A new user is created based on $fields if it's a new combination of $source and $identifier
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			require_once AS_INCLUDE_DIR.'as-db-users.php';
			
			$users=as_db_user_login_find($source, $identifier);
			$countusers=count($users);
			
			if ($countusers>1)
				as_fatal_error('External login mapped to more than one user'); // should never happen
			
			if ($countusers) // user exists so log them in
				as_set_logged_in_user($users[0]['userid'], $users[0]['handle'], false, $source);
			
			else { // create and log in user
				require_once AS_INCLUDE_DIR.'as-app-users-edit.php';
				
				as_db_user_login_sync(true);
				
				$users=as_db_user_login_find($source, $identifier); // check again after table is locked
				
				if (count($users)==1) {
					as_db_user_login_sync(false);
					as_set_logged_in_user($users[0]['userid'], $users[0]['handle'], false, $source);
				
				} else {
					$handle=as_handle_make_valid(@$fields['handle']);
				
					if (strlen(@$fields['email'])) { // remove email address if it will cause a duplicate
						$emailusers=as_db_user_find_by_email($fields['email']);
						if (count($emailusers)) {
							as_redirect('login', array('e' => $fields['email'], 'ee' => '1'));
							unset($fields['email']);
							unset($fields['confirmed']);
						}
					}
					
					$userid=as_create_new_user((string)@$fields['email'], null /* no password */, $handle,
						isset($fields['level']) ? $fields['level'] : AS_USER_LEVEL_BASIC, @$fields['confirmed']);
					
					as_db_user_login_add($userid, $source, $identifier);
					as_db_user_login_sync(false);
					
					$profilefields=array('name', 'location', 'website', 'about');
					
					foreach ($profilefields as $fieldname)
						if (strlen(@$fields[$fieldname]))
							as_db_user_profile_set($userid, $fieldname, $fields[$fieldname]);
							
					if (strlen(@$fields['avatar']))
						as_set_user_avatar($userid, $fields['avatar']);
							
					as_set_logged_in_user($userid, $handle, false, $source);
				}
			}
		}

		
		function as_get_logged_in_userid()
	/*
		Return the userid of the currently logged in user, or null if none logged in
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			global $as_logged_in_userid_checked;
			
			$suffix=as_session_var_suffix();
			
			if (!$as_logged_in_userid_checked) { // only check once
				as_start_session(); // this will load logged in userid from the native PHP session, but that's not enough
				
				$sessionuserid=@$_SESSION['as_session_userid_'.$suffix];
				
				if (isset($sessionuserid)) // check verify code matches
					if (@$_SESSION['as_session_verify_'.$suffix] != as_session_verify_code($sessionuserid))
						as_clear_session_user();
				
				if (!empty($_COOKIE['as_session'])) {
					@list($handle, $sessioncode, $remember)=explode('/', $_COOKIE['as_session']);
					
					if ($remember)
						as_set_session_cookie($handle, $sessioncode, $remember); // extend 'remember me' cookies each time
	
					$sessioncode=trim($sessioncode); // trim to prevent passing in blank values to match uninitiated DB rows
	
					// Try to recover session from the database if PHP session has timed out
					if ( (!isset($_SESSION['as_session_userid_'.$suffix])) && (!empty($handle)) && (!empty($sessioncode)) ) {
						require_once AS_INCLUDE_DIR.'as-db-selects.php';
						
						$userinfo=as_db_single_select(as_db_user_account_selectspec($handle, false)); // don't get any pending
						
						if (strtolower(trim($userinfo['sessioncode'])) == strtolower($sessioncode))
							as_set_session_user($userinfo['userid'], $userinfo['sessionsource']);
						else
							as_clear_session_cookie(); // if cookie not valid, remove it to save future checks
					}
				}
				
				$as_logged_in_userid_checked=true;
			}
			
			return @$_SESSION['as_session_userid_'.$suffix];
		}
		
		
		function as_get_logged_in_source()
	/*
		Get the source of the currently logged in user, from call to as_log_in_external_user() or null if logged in normally
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			$userid=as_get_logged_in_userid();
			$suffix=as_session_var_suffix();
			
			if (isset($userid))
				return @$_SESSION['as_session_source_'.$suffix];
		}
		
		
		function as_get_logged_in_user_field($field)
	/*
		Return $field of the currently logged in user, cache to ensure only one call to external code
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			global $as_cached_logged_in_user;
			
			$userid=as_get_logged_in_userid();
			
			if (isset($userid) && !isset($as_cached_logged_in_user)) {
				require_once AS_INCLUDE_DIR.'as-db-selects.php';
				$as_cached_logged_in_user=as_db_get_pending_result('loggedinuser', as_db_user_account_selectspec($userid, true));
				
				if (!isset($as_cached_logged_in_user)) { // the user can no longer be found (should be because they're deleted)
					as_clear_session_user();
					as_fatal_error('The logged in user cannot be found');
						// it's too late here to proceed because the caller may already be branching based on whether someone is logged in
				}					
			}
			
			return @$as_cached_logged_in_user[$field];
		}
		
		
		function as_get_logged_in_points()
	/*
		Return the number of points of the currently logged in user, or null if none is logged in
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			return as_get_logged_in_user_field('points');
		}

		
		function as_get_mysql_user_column_type()
	/*
		Return column type to use for users (if not using single sign-on integration)
	*/
		{
			return 'INT UNSIGNED';
		}


		function as_get_one_user_html($handle, $microformats=false, $favorited=false)
	/*
		Return HTML to display for user with username $handle, with microformats if $microformats is true. Set $favorited to true to show the user as favorited.
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			return strlen($handle) ? ('<a href="'.as_path_html('user/'.$handle).'" class="as-user-link'
				.($favorited ? ' as-user-favorited' : '').($microformats ? ' url nickname' : '').'">'.as_html($handle).'</a>') : '';
		}
		
		
		function as_get_user_avatar_html($flags, $email, $handle, $blobid, $width, $height, $size, $padding=false)
	/*
		Return HTML to display for the user's avatar, constrained to $size pixels, with optional $padding to that size
		Pass the user's fields $flags, $email, $handle, and avatar $blobid, $width and $height
	*/	
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			require_once AS_INCLUDE_DIR.'as-app-format.php';
			
			if (as_opt('avatar_allow_gravatar') && ($flags & AS_USER_FLAGS_SHOW_GRAVATAR))
				$html=as_get_gravatar_html($email, $size);
			elseif (as_opt('avatar_allow_upload') && (($flags & AS_USER_FLAGS_SHOW_AVATAR)) && isset($blobid))
				$html=as_get_avatar_blob_html($blobid, $width, $height, $size, $padding);
			elseif ( (as_opt('avatar_allow_gravatar')||as_opt('avatar_allow_upload')) && as_opt('avatar_default_show') && strlen(as_opt('avatar_default_blobid')) )
				$html=as_get_avatar_blob_html(as_opt('avatar_default_blobid'), as_opt('avatar_default_width'), as_opt('avatar_default_height'), $size, $padding);
			else
				$html=null;
				
			return (isset($html) && strlen($handle)) ? ('<a href="'.as_path_html('user/'.$handle).'" class="as-avatar-link">'.$html.'</a>') : $html;
		}
		

		function as_get_user_email($userid)
	/*
		Return email address for user $userid (if not using single sign-on integration)
	*/
		{
			$userinfo=as_db_select_with_pending(as_db_user_account_selectspec($userid, true));

			return $userinfo['email'];
		}
		

		function as_user_report_action($userid, $action)
	/*
		Called after a database write $action performed by a user $userid
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			require_once AS_INCLUDE_DIR.'as-db-users.php';
			
			as_db_user_written($userid, as_remote_ip_address());
		}

		
		function as_user_level_string($level)
	/*
		Return textual representation of the user $level
	*/
		{
			if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
			if ($level>=AS_USER_LEVEL_SUPER)
				$string='users/level_super';
			elseif ($level>=AS_USER_LEVEL_ADMIN)
				$string='users/level_admin';
			elseif ($level>=AS_USER_LEVEL_MODERATOR)
				$string='users/level_moderator';
			elseif ($level>=AS_USER_LEVEL_EDITOR)
				$string='users/level_editor';
			elseif ($level>=AS_USER_LEVEL_EXPERT)
				$string='users/level_expert';
			elseif ($level>=AS_USER_LEVEL_APPROVED)
				$string='users/approved_user';
			else
				$string='users/registered_user';
			
			return as_lang($string);
		}

		
		function as_get_login_links($rooturl, $tourl)
	/*
		Return an array of links to login, register, email confirm and logout pages (if not using single sign-on integration)
	*/
		{
			return array(
				'login' => as_path('login', isset($tourl) ? array('to' => $tourl) : null, $rooturl),
				'register' => as_path('register', isset($tourl) ? array('to' => $tourl) : null, $rooturl),
				'confirm' => as_path('confirm', null, $rooturl),
				'logout' => as_path('logout', null, $rooturl),
			);
		}

	} // end of: if (AS_FINAL_EXTERNAL_USERS) { ... } else { ... }


	function as_is_logged_in()
/*
	Return whether someone is logged in at the moment
*/
	{	
		$userid=as_get_logged_in_userid();
		return isset($userid);
	}
	
	
	function as_get_logged_in_handle()
/*
	Return displayable handle/username of currently logged in user, or null if none
*/
	{
		return as_get_logged_in_user_field(AS_FINAL_EXTERNAL_USERS ? 'publicusername' : 'handle');
	}


	function as_get_logged_in_email()
/*
	Return email of currently logged in user, or null if none
*/
	{
		return as_get_logged_in_user_field('email');
	}


	function as_get_logged_in_level()
/*
	Return level of currently logged in user, or null if none
*/
	{
		return as_get_logged_in_user_field('level');
	}

	
	function as_get_logged_in_flags()
/*
	Return flags (see AS_USER_FLAGS_*) of currently logged in user, or null if none
*/
	{
		if (AS_FINAL_EXTERNAL_USERS)
			return as_get_logged_in_user_field('blocked') ? AS_USER_FLAGS_USER_BLOCKED : 0;
		else
			return as_get_logged_in_user_field('flags');
	}

	
	function as_get_logged_in_levels()
/*
	Return an array of all the specific (e.g. per category) level privileges for the logged in user, retrieving from the database if necessary
*/
	{
		require_once AS_INCLUDE_DIR.'as-db-selects.php';
		
		return as_db_get_pending_result('userlevels', as_db_user_levels_selectspec(as_get_logged_in_userid(), true));
	}
	
	
	function as_userids_to_handles($userids)
/*
	Return an array mapping each userid in $userids to that user's handle (public username), or to null if not found
*/
	{
		if (AS_FINAL_EXTERNAL_USERS)
			$rawuseridhandles=as_get_public_from_userids($userids);
		
		else {
			require_once AS_INCLUDE_DIR.'as-db-users.php';
			$rawuseridhandles=as_db_user_get_userid_handles($userids);
		}
		
		$gotuseridhandles=array();
		foreach ($userids as $userid)
			$gotuseridhandles[$userid]=@$rawuseridhandles[$userid];
			
		return $gotuseridhandles;
	}
	
	
	function as_handles_to_userids($handles, $exactonly=false)
/*
	Return an array mapping each handle in $handles the user's userid, or null if not found. If $exactonly is true then
	$handles must have the correct case and accents. Otherwise, handles are case- and accent-insensitive, and the keys
	of the returned array will match the $handles provided, not necessary those in the DB.
*/
	{
		require_once AS_INCLUDE_DIR.'as-util-string.php';
		
		if (AS_FINAL_EXTERNAL_USERS)
			$rawhandleuserids=as_get_userids_from_public($handles);

		else {
			require_once AS_INCLUDE_DIR.'as-db-users.php';
			$rawhandleuserids=as_db_user_get_handle_userids($handles);
		}
		
		$gothandleuserids=array();

		if ($exactonly) { // only take the exact matches
			foreach ($handles as $handle)
				$gothandleuserids[$handle]=@$rawhandleuserids[$handle];
		
		} else { // normalize to lowercase without accents, and then find matches
			$normhandleuserids=array();
			foreach ($rawhandleuserids as $handle => $userid)
				$normhandleuserids[as_string_remove_accents(as_strtolower($handle))]=$userid;
			
			foreach ($handles as $handle)
				$gothandleuserids[$handle]=@$normhandleuserids[as_string_remove_accents(as_strtolower($handle))];
		}
		
		return $gothandleuserids;
	}
	
	
	function as_handle_to_userid($handle)
/*
	Return the userid corresponding to $handle (not case- or accent-sensitive)
*/
	{
		if (AS_FINAL_EXTERNAL_USERS)
			$handleuserids=as_get_userids_from_public(array($handle));

		else {
			require_once AS_INCLUDE_DIR.'as-db-users.php';
			$handleuserids=as_db_user_get_handle_userids(array($handle));
		}
		
		if (count($handleuserids)==1)
			return reset($handleuserids); // don't use $handleuserids[$handle] since capitalization might be different
		
		return null;
	}
	
	
	function as_user_level_for_categories($categoryids)
/*
	Return the level of the logged in user for a post with $categoryids (expressing the full hierarchy to the final category)
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }

		require_once AS_INCLUDE_DIR.'as-app-updates.php';

		$level=as_get_logged_in_level();
		
		if (count($categoryids)) {
			$userlevels=as_get_logged_in_levels();
			
			$categorylevels=array(); // create a map
			foreach ($userlevels as $userlevel)
				if ($userlevel['entitytype']==AS_ENTITY_CATEGORY)
					$categorylevels[$userlevel['entityid']]=$userlevel['level'];
			
			foreach ($categoryids as $categoryid)
				$level=max($level, @$categorylevels[$categoryid]);
		}
		
		return $level;
	}
	
	
	function as_user_level_for_post($post)
/*
	Return the level of the logged in user for $post, as retrieved from the database
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		if (strlen(@$post['categoryids']))
			return as_user_level_for_categories(explode(',', $post['categoryids']));

		return null;
	}
	
	
	function as_user_level_maximum()
/*
	Return the maximum possible level of the logged in user in any context (i.e. for any category)
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		$level=as_get_logged_in_level();

		$userlevels=as_get_logged_in_levels();
		foreach ($userlevels as $userlevel)
			$level=max($level, $userlevel['level']);

		return $level;
	}
	

	function as_user_post_permit_error($permitoption, $post, $limitaction=null, $checkblocks=true)
/*
	Check whether the logged in user has permission to perform $permitoption on post $post (from the database)
	Other parameters and the return value are as for as_user_permit_error(...)
*/
	{
		return as_user_permit_error($permitoption, $limitaction, as_user_level_for_post($post), $checkblocks);
	}
	
	
	function as_user_maximum_permit_error($permitoption, $limitaction=null, $checkblocks=true)
/*
	Check whether the logged in user would have permittion to perform $permitoption in any context (i.e. for any category)
	Other parameters and the return value are as for as_user_permit_error(...)
*/
	{
		return as_user_permit_error($permitoption, $limitaction, as_user_level_maximum(), $checkblocks);
	}
	
	
	function as_user_permit_error($permitoption=null, $limitaction=null, $userlevel=null, $checkblocks=true)
/*
	Check whether the logged in user has permission to perform $permitoption. If $permitoption is null, this simply
	checks whether the user is blocked. Optionally provide an $limitaction (see top of as-app-limits.php) to also check
	against user or IP rate limits. You can pass in a AS_USER_LEVEL_* constant in $userlevel to consider the user at a
	different level to usual (e.g. if they are performing this action in a category for which they have elevated
	privileges). To ignore the user's blocked status, set $checkblocks to false.

	Possible results, in order of priority (i.e. if more than one reason, the first will be given):
	'level' => a special privilege level (e.g. expert) or minimum number of points is required
	'login' => the user should login or register
	'userblock' => the user has been blocked
	'ipblock' => the ip address has been blocked
	'confirm' => the user should confirm their email address
	'approve' => the user needs to be approved by the site admins
	'limit' => the user or IP address has reached a rate limit (if $limitaction specified)
	false => the operation can go ahead
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		require_once AS_INCLUDE_DIR.'as-app-limits.php';
		
		$userid=as_get_logged_in_userid();
		if (!isset($userlevel))
			$userlevel=as_get_logged_in_level();

		$flags=as_get_logged_in_flags();
		if (!$checkblocks)
			$flags&=~AS_USER_FLAGS_USER_BLOCKED;

		$error=as_permit_error($permitoption, $userid, $userlevel, $flags);
		
		if ($checkblocks && (!$error) && as_is_ip_blocked())
			$error='ipblock';
			
		if ((!$error) && isset($userid) && ($flags & AS_USER_FLAGS_MUST_CONFIRM) && as_opt('confirm_user_emails'))
			$error='confirm';
			
		if ((!$error) && isset($userid) && ($flags & AS_USER_FLAGS_MUST_APPROVE) && as_opt('moderate_users'))
			$error='approve';
		
		if (isset($limitaction) && !$error)
			if (as_user_limits_remaining($limitaction)<=0)
				$error='limit';
		
		return $error;
	}
	
	
	function as_permit_error($permitoption, $userid, $userlevel, $userflags, $userpoints=null)
/*
	Check whether $userid (null for no user) can perform $permitoption. Result as for as_user_permit_error(...).
	If appropriate, pass the user's level in $userlevel, flags in $userflags and points in $userpoints.
	If $userid is currently logged in, you can set $userpoints=null to retrieve them only if necessary.
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }

		$permit=isset($permitoption) ? as_opt($permitoption) : AS_PERMIT_ALL;

		if (isset($userid) && (($permit==AS_PERMIT_POINTS) || ($permit==AS_PERMIT_POINTS_CONFIRMED) || ($permit==AS_PERMIT_APPROVED_POINTS)) ) {
				// deal with points threshold by converting as appropriate
			
			if ( (!isset($userpoints)) && ($userid==as_get_logged_in_userid()) )
				$userpoints=as_get_logged_in_points(); // allow late retrieval of points (to avoid unnecessary DB query when using external users)
		
			if ($userpoints>=as_opt($permitoption.'_points'))
				$permit=($permit==AS_PERMIT_APPROVED_POINTS) ? AS_PERMIT_APPROVED :
					(($permit==AS_PERMIT_POINTS_CONFIRMED) ? AS_PERMIT_CONFIRMED : AS_PERMIT_USERS); // convert if user has enough points
			else
				$permit=AS_PERMIT_EXPERTS; // otherwise show a generic message so they're not tempted to collect points just for this
		}
		
		return as_permit_value_error($permit, $userid, $userlevel, $userflags);
	}
	
	
	function as_permit_value_error($permit, $userid, $userlevel, $userflags)
/*
	Check whether $userid of level $userlevel with $userflags can reach the permission level in $permit
	(generally retrieved from an option, but not always). Result as for as_user_permit_error(...).
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		if ($permit>=AS_PERMIT_ALL)
			$error=false;
			
		elseif ($permit>=AS_PERMIT_USERS)
			$error=isset($userid) ? false : 'login';
			
		elseif ($permit>=AS_PERMIT_CONFIRMED) {
			if (!isset($userid))
				$error='login';
			
			elseif (
				AS_FINAL_EXTERNAL_USERS || // not currently supported by single sign-on integration
				($userlevel>=AS_PERMIT_APPROVED) || // if user approved or assigned to a higher level, no need
				($userflags & AS_USER_FLAGS_EMAIL_CONFIRMED) || // actual confirmation
				(!as_opt('confirm_user_emails')) // if this option off, we can't ask it of the user
			)
				$error=false;
			
			else
				$error='confirm';

		} elseif ($permit>=AS_PERMIT_APPROVED) {
			if (!isset($userid))
				$error='login';
				
			elseif (
				($userlevel>=AS_USER_LEVEL_APPROVED) || // user has been approved
				(!as_opt('moderate_users')) // if this option off, we can't ask it of the user
			)
				$error=false;
				
			else
				$error='approve';
		
		} elseif ($permit>=AS_PERMIT_EXPERTS)
			$error=(isset($userid) && ($userlevel>=AS_USER_LEVEL_EXPERT)) ? false : 'level';
			
		elseif ($permit>=AS_PERMIT_EDITORS)
			$error=(isset($userid) && ($userlevel>=AS_USER_LEVEL_EDITOR)) ? false : 'level';
			
		elseif ($permit>=AS_PERMIT_MODERATORS)
			$error=(isset($userid) && ($userlevel>=AS_USER_LEVEL_MODERATOR)) ? false : 'level';
			
		elseif ($permit>=AS_PERMIT_ADMINS)
			$error=(isset($userid) && ($userlevel>=AS_USER_LEVEL_ADMIN)) ? false : 'level';
			
		else
			$error=(isset($userid) && ($userlevel>=AS_USER_LEVEL_SUPER)) ? false : 'level';
		
		if (isset($userid) && ($userflags & AS_USER_FLAGS_USER_BLOCKED) && ($error!='level'))
			$error='userblock';
		
		return $error;
	}
	
	
	function as_user_captcha_reason($userlevel=null)
/*
	Return whether a captcha is required for posts submitted by the current user. You can pass in a AS_USER_LEVEL_*
	constant in $userlevel to consider the user at a different level to usual (e.g. if they are performing this action
	in a category for which they have elevated privileges).
	
	Possible results:
	'login' => captcha required because the user is not logged in
	'approve' => captcha required because the user has not been approved
	'confirm' => captcha required because the user has not confirmed their email address
	false => captcha is not required
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		$reason=false;
		if (!isset($userlevel))
			$userlevel=as_get_logged_in_level();
		
		if ($userlevel < AS_USER_LEVEL_APPROVED) { // approved users and above aren't shown captchas
			$userid=as_get_logged_in_userid();
			
			if (as_opt('captcha_on_anon_post') && !isset($userid))
				$reason='login';
			elseif (as_opt('moderate_users') && as_opt('captcha_on_unapproved'))
				$reason='approve';
			elseif (as_opt('confirm_user_emails') && as_opt('captcha_on_unconfirmed') && !(as_get_logged_in_flags() & AS_USER_FLAGS_EMAIL_CONFIRMED) )
				$reason='confirm';
		}
		
		return $reason;
	}

	
	function as_user_use_captcha($userlevel=null)
/*
	Return whether a captcha should be presented to the logged in user for writing posts. You can pass in a
	AS_USER_LEVEL_* constant in $userlevel to consider the user at a different level to usual.
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		return as_user_captcha_reason($userlevel)!=false;
	}
	
	
	function as_user_moderation_reason($userlevel=null)
/*
	Return whether moderation is required for posts submitted by the current user. You can pass in a AS_USER_LEVEL_*
constant in $userlevel to consider the user at a different level to usual (e.g. if they are performing this action
in a category for which they have elevated privileges).
	
	Possible results:
	'login' => moderation required because the user is not logged in
	'approve' => moderation required because the user has not been approved
	'confirm' => moderation required because the user has not confirmed their email address
	'points' => moderation required because the user has insufficient points
	false => moderation is not required
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		$reason=false;
		if (!isset($userlevel))
			$userlevel=as_get_logged_in_level();
		
		if (
			($userlevel < AS_USER_LEVEL_EXPERT) && // experts and above aren't moderated
			as_user_permit_error('permit_moderate') // if the user can approve posts, no point in moderating theirs
		) {
			$userid=as_get_logged_in_userid();
			
			if (isset($userid)) {
				if (as_opt('moderate_users') && as_opt('moderate_unapproved') && ($userlevel<AS_USER_LEVEL_APPROVED))
					$reason='approve';
				elseif (as_opt('confirm_user_emails') && as_opt('moderate_unconfirmed') && !(as_get_logged_in_flags() & AS_USER_FLAGS_EMAIL_CONFIRMED) )
					$reason='confirm';
				elseif (as_opt('moderate_by_points') && (as_get_logged_in_points() < as_opt('moderate_points_limit')))
					$reason='points';
			
			} elseif (as_opt('moderate_anon_post'))
				$reason='login';
		}
		
		return $reason;
	}
	
	
	function as_user_userfield_label($userfield)
/*
	Return the label to display for $userfield as retrieved from the database, using default if no name set
*/
	{
		if (isset($userfield['content']))
			return $userfield['content'];
		
		else {
			$defaultlabels=array(
				'name' => 'users/full_name',
				'about' => 'users/about',
				'location' => 'users/location',
				'website' => 'users/website',
			);
			
			if (isset($defaultlabels[$userfield['title']]))
				return as_lang($defaultlabels[$userfield['title']]);
		}
			
		return '';
	}


	function as_set_form_security_key()
/*
	Set or extend the cookie in browser of non logged-in users which identifies them for the purposes of form security (anti-CSRF protection)
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }

		global $as_form_key_cookie_set;
		
		if ( (!as_is_logged_in()) && !@$as_form_key_cookie_set) {
			$as_form_key_cookie_set=true;
		
			if (strlen(@$_COOKIE['as_key'])!=AS_FORM_KEY_LENGTH) {
				require_once AS_INCLUDE_DIR.'as-util-string.php';
				$_COOKIE['as_key']=as_random_alphanum(AS_FORM_KEY_LENGTH);
			}
			
			setcookie('as_key', $_COOKIE['as_key'], time()+2*AS_FORM_EXPIRY_SECS, '/', AS_COOKIE_DOMAIN); // extend on every page request
		}
	}
	
	
	function as_calc_form_security_hash($action, $timestamp)
/*
	Return the form security (anti-CSRF protection) hash for an $action (any string), that can be performed within
	AS_FORM_EXPIRY_SECS of $timestamp (in unix seconds) by the current user.
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		$salt=as_opt('form_security_salt');
		
		if (as_is_logged_in())
			return sha1($salt.'/'.$action.'/'.$timestamp.'/'.as_get_logged_in_userid().'/'.as_get_logged_in_user_field('passsalt'));
		else
			return sha1($salt.'/'.$action.'/'.$timestamp.'/'.@$_COOKIE['as_key']); // lower security for non logged in users - code+cookie can be transferred
	}
	
	
	function as_get_form_security_code($action)
/*
	Return the full form security (anti-CSRF protection) code for an $action (any string) performed within
	AS_FORM_EXPIRY_SECS of now by the current user.
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		as_set_form_security_key();
		
		$timestamp=as_opt('db_time');
		
		return (int)as_is_logged_in().'-'.$timestamp.'-'.as_calc_form_security_hash($action, $timestamp);
	}
	
	
	function as_check_form_security_code($action, $value)
/*
	Return whether $value matches the expected form security (anti-CSRF protection) code for $action (any string) and
	that the code has not expired (if more than AS_FORM_EXPIRY_SECS have passed). Logs causes for suspicion.
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		$reportproblems=array();
		$silentproblems=array();
		
		if (!isset($value))
			$silentproblems[]='code missing';

		else if (!strlen($value))
			$silentproblems[]='code empty';

		else {
			$parts=explode('-', $value);
			
			if (count($parts)==3) {
				$loggedin=$parts[0];
				$timestamp=$parts[1];
				$hash=$parts[2];
				$timenow=as_opt('db_time');
				
				if ($timestamp>$timenow)
					$reportproblems[]='time '.($timestamp-$timenow).'s in future';
				elseif ($timestamp<($timenow-AS_FORM_EXPIRY_SECS))
					$silentproblems[]='timeout after '.($timenow-$timestamp).'s';
				
				if (as_is_logged_in()) {
					if (!$loggedin)
						$silentproblems[]='now logged in';
					
				} else {
					if ($loggedin)
						$silentproblems[]='now logged out';

					else {
						$key=@$_COOKIE['as_key'];
						
						if (!isset($key))
							$silentproblems[]='key cookie missing';
						elseif (!strlen($key))
							$silentproblems[]='key cookie empty';
						else if (strlen($key)!=AS_FORM_KEY_LENGTH)
							$reportproblems[]='key cookie '.$key.' invalid';
					}
				}

				if (empty($silentproblems) && empty($reportproblems))
					if (strtolower(as_calc_form_security_hash($action, $timestamp))!=strtolower($hash))
						$reportproblems[]='code mismatch';

			} else
				$reportproblems[]='code '.$value.' malformed';
		}
		
		if (count($reportproblems))
			@error_log(
				'PHP Question2Answer form security violation for '.$action.
				' by '.(as_is_logged_in() ? ('userid '.as_get_logged_in_userid()) : 'anonymous').
				' ('.implode(', ', array_merge($reportproblems, $silentproblems)).')'.
				' on '.@$_SERVER['REQUEST_URI'].
				' via '.@$_SERVER['HTTP_REFERER']
			);
		
		return (empty($silentproblems) && empty($reportproblems));
	}


/*
	Omit PHP closing tag to help avoid accidental output
*/