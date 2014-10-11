<?php
	
/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: qa-include/qa-page-tag.php
	Version: See define()s at top of qa-include/qa-base.php
	Description: Controller for page for a specific tag


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

	if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
		header('Location: ../');
		exit;
	}

	require_once QA_INCLUDE_DIR.'qa-db-selects.php';
	require_once QA_INCLUDE_DIR.'qa-app-format.php';
	require_once QA_INCLUDE_DIR.'qa-app-updates.php';
	
	$tag=as_request_part(1); // picked up from qa-page.php
	$start=as_get_start();
	$userid=as_get_logged_in_userid();


//	Find the questions with this tag

	if (!strlen($tag))
		as_redirect('tags');

	list($questions, $tagword)=as_db_select_with_pending(
		as_db_tag_recent_qs_selectspec($userid, $tag, $start, false, as_opt_if_loaded('page_size_tag_qs')),
		as_db_tag_word_selectspec($tag)
	);
	
	$pagesize=as_opt('page_size_tag_qs');
	$questions=array_slice($questions, 0, $pagesize);
	$usershtml=as_userids_handles_html($questions);


//	Prepare content for theme
	
	$as_content=as_content_prepare(true);
	
	$as_content['title']=as_lang_html_sub('main/questions_tagged_x', as_html($tag));
	
	if (isset($userid) && isset($tagword)) {
		$favoritemap=as_get_favorite_non_qs_map();
		$favorite=@$favoritemap['tag'][as_strtolower($tagword['word'])];
		
		$as_content['favorite']=as_favorite_form(QA_ENTITY_TAG, $tagword['wordid'], $favorite,
			as_lang_sub($favorite ? 'main/remove_x_favorites' : 'main/add_tag_x_favorites', $tagword['word']));
	}

	if (!count($questions))
		$as_content['q_list']['title']=as_lang_html('main/no_questions_found');

	$as_content['q_list']['form']=array(
		'tags' => 'method="post" action="'.as_self_html().'"',

		'hidden' => array(
			'code' => as_get_form_security_code('vote'),
		),
	);

	$as_content['q_list']['qs']=array();
	foreach ($questions as $postid => $question)
		$as_content['q_list']['qs'][]=as_post_html_fields($question, $userid, as_cookie_get(), $usershtml, null, as_post_html_options($question));
		
	$as_content['page_links']=as_html_page_links(as_request(), $start, $pagesize, $tagword['tagcount'], as_opt('pages_prev_next'));

	if (empty($as_content['page_links']))
		$as_content['suggest_next']=as_html_suggest_qs_tags(true);

	if (as_opt('feed_for_tag_qs'))
		$as_content['feed']=array(
			'url' => as_path_html(as_feed_request('tag/'.$tag)),
			'label' => as_lang_html_sub('main/questions_tagged_x', as_html($tag)),
		);

		
	return $as_content;


/*
	Omit PHP closing tag to help avoid accidental output
*/