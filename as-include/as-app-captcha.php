<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-app-captcha.php
	Version: See define()s at top of as-include/as-base.php
	Description: Wrapper functions and utilities for captcha modules


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


	function as_captcha_available()
/*
	Return whether a captcha module has been selected and it indicates that it is fully set up to go
*/
	{
		$module=as_load_module('captcha', as_opt('captcha_module'));
		
		return isset($module) && ( (!method_exists($module, 'allow_captcha')) || $module->allow_captcha());
	}
	
	
	function as_captcha_reason_note($captchareason)
/*
	Return an HTML string explaining $captchareason (from as_user_captcha_reason()) to the user about why they are seeing a captcha
*/
	{
		$notehtml=null;
		
		switch ($captchareason) {
			case 'login':
				$notehtml=as_insert_login_links(as_lang_html('misc/captcha_login_fix'));
				break;
				
			case 'confirm':
				$notehtml=as_insert_login_links(as_lang_html('misc/captcha_confirm_fix'));
				break;
				
			case 'approve':
				$notehtml=as_lang_html('misc/captcha_approve_fix');
				break;		
		}
		
		return $notehtml;
	}


	function as_set_up_captcha_field(&$content, &$fields, $errors, $note=null)
/*
	Prepare $content for showing a captcha, adding the element to $fields, given previous $errors, and a $note to display
*/
	{
		if (as_captcha_available()) {
			$captcha=as_load_module('captcha', as_opt('captcha_module'));
			
			$count=@++$content['as_captcha_count']; // work around fact that reCAPTCHA can only display per page
			
			if ($count>1)
				$html='[captcha placeholder]'; // single captcha will be moved about the page, to replace this
			else {
				$content['script_var']['as_captcha_in']='as_captcha_div_1';
				$html=$captcha->form_html($content, @$errors['captcha']);
			}
			
			$fields['captcha']=array(
				'type' => 'custom',
				'label' => as_lang_html('misc/captcha_label'),
				'html' => '<div id="as_captcha_div_'.$count.'">'.$html.'</div>',
				'error' => @array_key_exists('captcha', $errors) ? as_lang_html('misc/captcha_error') : null,
				'note' => $note,
			);
					
			return "if (as_captcha_in!='as_captcha_div_".$count."') { document.getElementById('as_captcha_div_".$count."').innerHTML=document.getElementById(as_captcha_in).innerHTML; document.getElementById(as_captcha_in).innerHTML=''; as_captcha_in='as_captcha_div_".$count."'; }";
		}
		
		return '';
	}


	function as_captcha_validate_post(&$errors)
/*
	Check if captcha is submitted correctly, and if not, set $errors['captcha'] to a descriptive string
*/
	{
		if (as_captcha_available()) {
			$captcha=as_load_module('captcha', as_opt('captcha_module'));
			
			if (!$captcha->validate_post($error)) {
				$errors['captcha']=$error;
				return false;
			}
		}
		
		return true;
	}


/*
	Omit PHP closing tag to help avoid accidental output
*/