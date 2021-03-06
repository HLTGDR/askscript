<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-app-favorites.php
	Version: See define()s at top of as-include/as-base.php
	Description: Handles favoriting and unfavoriting (application level)


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


	function as_user_favorite_set($userid, $handle, $cookieid, $entitytype, $entityid, $favorite)
/*
	If $favorite is true, set $entitytype and $entityid to be favorites of $userid with $handle and $cookieid, otherwise
	remove them from its favorites list. Handles event reporting.
*/
	{
		require_once AS_INCLUDE_DIR.'as-db-favorites.php';
		require_once AS_INCLUDE_DIR.'as-app-limits.php';
		require_once AS_INCLUDE_DIR.'as-app-updates.php';
		
		if ($favorite)
			as_db_favorite_create($userid, $entitytype, $entityid);
		else
			as_db_favorite_delete($userid, $entitytype, $entityid);
		
		switch ($entitytype) {
			case AS_ENTITY_QUESTION:
				$action=$favorite ? 'q_favorite' : 'q_unfavorite';
				$params=array('postid' => $entityid);
				break;
			
			case AS_ENTITY_USER:
				$action=$favorite ? 'u_favorite' : 'u_unfavorite';
				$params=array('userid' => $entityid);
				break;
				
			case AS_ENTITY_TAG:
				$action=$favorite ? 'tag_favorite' : 'tag_unfavorite';
				$params=array('wordid' => $entityid);
				break;
				
			case AS_ENTITY_CATEGORY:
				$action=$favorite ? 'cat_favorite' : 'cat_unfavorite';
				$params=array('categoryid' => $entityid);
				break;
			
			default:
				as_fatal_error('Favorite type not recognized');
				break;
		}
		
		as_report_event($action, $userid, $handle, $cookieid, $params);
	}
	
	
/*
	Omit PHP closing tag to help avoid accidental output
*/