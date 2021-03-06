<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-ajax-answer.php
	Version: See define()s at top of as-include/as-base.php
	Description: Server-side response to Ajax create answer requests


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

	require_once AS_INCLUDE_DIR.'as-app-users.php';
	require_once AS_INCLUDE_DIR.'as-app-limits.php';
	require_once AS_INCLUDE_DIR.'as-db-selects.php';


//	Load relevant information about this question
	
	$questionid=as_post_text('a_questionid');
	$userid=as_get_logged_in_userid();
	
	list($question, $childposts)=as_db_select_with_pending(
		as_db_full_post_selectspec($userid, $questionid),
		as_db_full_child_posts_selectspec($userid, $questionid)
	);


//	Check if the question exists, is not closed, and whether the user has permission to do this

	if ((@$question['basetype']=='Q') && (!isset($question['closedbyid'])) && !as_user_post_permit_error('permit_post_a', $question, AS_LIMIT_ANSWERS)) {
		require_once AS_INCLUDE_DIR.'as-app-captcha.php';
		require_once AS_INCLUDE_DIR.'as-app-format.php';
		require_once AS_INCLUDE_DIR.'as-app-post-create.php';
		require_once AS_INCLUDE_DIR.'as-app-cookies.php';
		require_once AS_INCLUDE_DIR.'as-page-question-view.php';
		require_once AS_INCLUDE_DIR.'as-page-question-submit.php';


	//	Try to create the new answer
	
		$usecaptcha=as_user_use_captcha(as_user_level_for_post($question));
		$answers=as_page_q_load_as($question, $childposts);
		$answerid=as_page_q_add_a_submit($question, $answers, $usecaptcha, $in, $errors);
		
	//	If successful, page content will be updated via Ajax

		if (isset($answerid)) {
			$answer=as_db_select_with_pending(as_db_full_post_selectspec($userid, $answerid));
			
			$question=$question+as_page_q_post_rules($question, null, null, $childposts); // array union
			$answer=$answer+as_page_q_post_rules($answer, $question, $answers, null);
			
			$usershtml=as_userids_handles_html(array($answer), true);
			
			$a_view=as_page_q_answer_view($question, $answer, false, $usershtml, false);
			
			$themeclass=as_load_theme_class(as_get_site_theme(), 'ajax-answer', null, null);
			
			echo "AS_AJAX_RESPONSE\n1\n";

			
		//	Send back whether the 'answer' button should still be visible
		
			echo (int)as_opt('allow_multi_answers')."\n";

			
		//	Send back the count of answers
			
			$countanswers=$question['acount']+1;

			if ($countanswers==1)
				echo as_lang_html('question/1_answer_title')."\n";
			else
				echo as_lang_html_sub('question/x_answers_title', $countanswers)."\n";


		//	Send back the HTML

			$themeclass->a_list_item($a_view);

			return;
		}
	}
	

	echo "AS_AJAX_RESPONSE\n0\n"; // fall back to non-Ajax submission if there were any problems

	
/*
	Omit PHP closing tag to help avoid accidental output
*/