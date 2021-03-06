<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-plugin/wysiwyg-editor/as-wysiwyg-upload.php
	Version: See define()s at top of as-include/as-base.php
	Description: Page module class for WYSIWYG editor (CKEditor) file upload receiver


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


	class as_wysiwyg_upload {
	
		function match_request($request)
		{
			return ($request=='wysiwyg-editor-upload');
		}

		
		function process_request($request)
		{
			$message='';
			$url='';
			
			if (is_array($_FILES) && count($_FILES)) {
				if (!as_opt('wysiwyg_editor_upload_images'))
					$message=as_lang('users/no_permission');
					
				require_once AS_INCLUDE_DIR.'as-app-upload.php';
				
				$upload=as_upload_file_one(
					as_opt('wysiwyg_editor_upload_max_size'),
					as_get('as_only_image') || !as_opt('wysiwyg_editor_upload_all'),
					as_get('as_only_image') ? 600 : null, // max width if it's an image upload
					null // no max height
				);
				
				$message=@$upload['error'];
				$url=@$upload['bloburl'];
			}
			
			echo "<script type='text/javascript'>window.parent.CKEDITOR.tools.callFunction(".as_js(as_get('CKEditorFuncNum')).
				", ".as_js($url).", ".as_js($message).");</script>";
			
			return null;
		}
		
	}


/*
	Omit PHP closing tag to help avoid accidental output
*/