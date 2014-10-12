<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-page-users.php
	Version: See define()s at top of as-include/as-base.php
	Description: Controller for top scoring users page


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

	require_once AS_INCLUDE_DIR.'as-db-users.php';
	require_once AS_INCLUDE_DIR.'as-db-selects.php';
	require_once AS_INCLUDE_DIR.'as-app-format.php';


//	Get list of all users
	
	$start=as_get_start();	
	$users=as_db_select_with_pending(as_db_top_users_selectspec($start, as_opt_if_loaded('page_size_users')));
	
	$usercount=as_opt('cache_userpointscount');
	$pagesize=as_opt('page_size_users');
	$users=array_slice($users, 0, $pagesize);
	$usershtml=as_userids_handles_html($users);


//	Prepare content for theme
	
	$as_content=as_content_prepare();

	$as_content['title']=as_lang_html('main/highest_users');

	$as_content['ranking']=array(
		'items' => array(),
		'rows' => ceil($pagesize/as_opt('columns_users')),
		'type' => 'users'
	);
	
	if (count($users)) {
		foreach ($users as $userid => $user)
			$as_content['ranking']['items'][]=array(
				'label' =>
					(AS_FINAL_EXTERNAL_USERS
						? as_get_external_avatar_html($user['userid'], as_opt('avatar_users_size'), true)
						: as_get_user_avatar_html($user['flags'], $user['email'], $user['handle'],
							$user['avatarblobid'], $user['avatarwidth'], $user['avatarheight'], as_opt('avatar_users_size'), true)
					).' '.$usershtml[$user['userid']],
				'score' => as_html(number_format($user['points'])),
				'raw' => $user,
			);
	
	} else
		$as_content['title']=as_lang_html('main/no_active_users');
	
	$as_content['page_links']=as_html_page_links(as_request(), $start, $pagesize, $usercount, as_opt('pages_prev_next'));

	$as_content['navigation']['sub']=as_users_sub_navigation();
	

	return $as_content;


/*
	Omit PHP closing tag to help avoid accidental output
*/