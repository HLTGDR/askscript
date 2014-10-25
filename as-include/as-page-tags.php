<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-page-tags.php
	Version: See define()s at top of as-include/as-base.php
	Description: Controller for popular tags page


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

	require_once AS_INCLUDE_DIR.'as-db-selects.php';
	require_once AS_INCLUDE_DIR.'as-app-format.php';


//	Get popular tags
	
	$start=as_get_start();
	$userid=as_get_logged_in_userid();
	$populartags=as_db_select_with_pending(
		as_db_popular_tags_selectspec($start, as_opt_if_loaded('page_size_tags'))
	);

	$tagcount=as_opt('cache_tagcount');
	$pagesize=as_opt('page_size_tags');
	
	
//	Prepare content for theme

	$content=as_content_prepare();

	$content['title']=as_lang_html('main/popular_tags');
	
	$content['ranking']=array(
		'items' => array(),
		'rows' => ceil($pagesize/as_opt('columns_tags')),
		'type' => 'tags'
	);
	
	if (count($populartags)) {
		$favoritemap=as_get_favorite_non_qs_map();
		
		$output=0;
		foreach ($populartags as $word => $count) {
			$content['ranking']['items'][]=array(
				'label' => as_tag_html($word, false, @$favoritemap['tag'][as_strtolower($word)]),
				'count' => number_format($count),
			);

			if ((++$output)>=$pagesize)
				break;
		}

	} else
		$content['title']=as_lang_html('main/no_tags_found');
	
	$content['page_links']=as_html_page_links(as_request(), $start, $pagesize, $tagcount, as_opt('pages_prev_next'));
	
	if (empty($content['page_links']))
		$content['suggest_next']=as_html_suggest_ask();
		

	return $content;


/*
	Omit PHP closing tag to help avoid accidental output
*/