<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-widget-activity-count.php
	Version: See define()s at top of as-include/as-base.php
	Description: Widget module class for activity count plugin


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

	class as_activity_count {
		
		function allow_template($template)
		{
			return true;
		}

		
		function allow_region($region)
		{
			return ($region=='side');
		}

		
		function output_widget($region, $place, $themeobject, $template, $request, $as_content)
		{
			$themeobject->output('<div class="as-activity-count">');
			
			$this->output_count($themeobject, as_opt('cache_qcount'), 'main/1_question', 'main/x_questions');
			$this->output_count($themeobject, as_opt('cache_acount'), 'main/1_answer', 'main/x_answers');
			
			if (as_opt('comment_on_qs') || as_opt('comment_on_as'))
				$this->output_count($themeobject, as_opt('cache_ccount'), 'main/1_comment', 'main/x_comments');
			
			$this->output_count($themeobject, as_opt('cache_userpointscount'), 'main/1_user', 'main/x_users');
			
			$themeobject->output('</div>');
		}
		

		function output_count($themeobject, $value, $langsingular, $langplural)
		{
			$themeobject->output('<p class="as-activity-count-item">');
			
			if ($value==1)
				$themeobject->output(as_lang_html_sub($langsingular, '<span class="as-activity-count-data">1</span>', '1'));
			else
				$themeobject->output(as_lang_html_sub($langplural, '<span class="as-activity-count-data">'.number_format((int)$value).'</span>'));

			$themeobject->output('</p>');
		}
	
	}
	

/*
	Omit PHP closing tag to help avoid accidental output
*/