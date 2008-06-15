<?php
/**
 * MyBB 1.4
 * Copyright © 2008 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.net
 * License: http://www.mybboard.net/about/license
 *
 * $Id$
 */

/*
options = array(
	allow_html
	allow_smilies
	allow_mycode
	nl2br
	filter_badwords
	me_username
	shorten_urls
)
*/

class postParser
{
	/**
	 * Internal cache of MyCode.
	 *
	 * @var mixed
	 */
	var $mycode_cache = 0;

	/**
	 * Internal cache of smilies
	 *
	 * @var mixed
	 */
	var $smilies_cache = 0;

	/**
	 * Internal cache of badwords filters
	 *
	 * @var mixed
	 */
	var $badwords_cache = 0;

	/**
	 * Base URL for smilies
	 *
	 * @var string
	 */
	var $base_url;
	
	/**
	 * Options for this parsed message (Private - set by parse_message argument)
	 *
	 * @access private
	 * @var array
	 */
	var $options;

	/**
	 * Parses a message with the specified options.
	 *
	 * @param string The message to be parsed.
	 * @param array Array of yes/no options - allow_html,filter_badwords,allow_mycode,allow_smilies,nl2br,me_username.
	 * @return string The parsed message.
	 */
	function parse_message($message, $options=array())
	{
		global $plugins, $mybb;

		// Set base URL for parsing smilies
		$this->base_url = $mybb->settings['bburl'];

		if($this->base_url != "")
		{
			if(my_substr($this->base_url, my_strlen($this->base_url) -1) != "/")
			{
				$this->base_url = $this->base_url."/";
			}
		}
		
		// Set the options		
		$this->options = $options;

		$message = $plugins->run_hooks("parse_message_start", $message);

		// Get rid of cartridge returns for they are the workings of the devil
		$message = str_replace("\r", "", $message);

		// Filter bad words if requested.
		if($options['filter_badwords'])
		{
			$message = $this->parse_badwords($message);
		}

		if($options['allow_html'] != 1)
		{
			$message = $this->parse_html($message);
		}
		else
		{		
			while(preg_match("#<script(.*)>(.*)</script(.*)>#is", $message))
			{
				$message = preg_replace("#<script(.*)>(.*)</script(.*)>#is", "&lt;script$1&gt;$2&lt;/script$3&gt;", $message);
			}
			// Remove these completely
			$message = preg_replace("#\s*<base[^>]*>\s*#is", "", $message);
			$message = preg_replace("#\s*<meta[^>]*>\s*#is", "", $message);
			$message = str_replace(array('<?php', '<!--', '-->', '?>', "<br />\n", "<br>\n"), array('&lt;?php', '&lt;!--', '--&gt;', '?&gt;', "\n", "\n"), $message);
		}
		
		// If MyCode needs to be replaced, first filter out [code] and [php] tags.
		if($options['allow_mycode'])
		{
			// First we split up the contents of code and php tags to ensure they're not parsed.
			preg_match_all("#\[(code|php)\](.*?)\[/\\1\](\r\n?|\n?)#si", $message, $code_matches, PREG_SET_ORDER);
			$message = preg_replace("#\[(code|php)\](.*?)\[/\\1\](\r\n?|\n?)#si", "<mybb-code>\n", $message);
		}

		// Always fix bad Javascript in the message.
		$message = $this->fix_javascript($message);
		
		// Replace "me" code and slaps if we have a username
		if($options['me_username'])
		{
			global $lang;
			
			$message = preg_replace('#(>|^|\r|\n)/me ([^\r\n<]*)#i', "\\1<span style=\"color: red;\">* {$options['me_username']} \\2</span>", $message);
			$message = preg_replace('#(>|^|\r|\n)/slap ([^\r\n<]*)#i', "\\1<span style=\"color: red;\">* {$options['me_username']} {$lang->slaps} \\2 {$lang->with_trout}</span>", $message);
		}
		
		// If we can, parse smilies
		if($options['allow_smilies'])
		{
			$message = $this->parse_smilies($message, $options['allow_html']);
		}

		// Replace MyCode if requested.
		if($options['allow_mycode'])
		{
			$message = $this->parse_mycode($message, $options);
		}

		// Run plugin hooks
		$message = $plugins->run_hooks("parse_message", $message);
		
		if($options['allow_mycode'])
		{
			// Now that we're done, if we split up any code tags, parse them and glue it all back together
			if(count($code_matches) > 0)
			{
				foreach($code_matches as $text)
				{
					// Fix up HTML inside the code tags so it is clean
					if($options['allow_html'] != 0)
					{
						$text[2] = $this->parse_html($text[2]);
					}
					
					if(my_strtolower($text[1]) == "code")
					{
						$code = $this->mycode_parse_code($text[2]);
					}
					elseif(my_strtolower($text[1]) == "php")
					{
						$code = $this->mycode_parse_php($text[2]);
					}
					$message = preg_replace("#\<mybb-code>\n?#", $code, $message, 1);
				}
			}
		}

		if($options['nl2br'] !== 0)
		{
			$message = nl2br($message);
			// Fix up new lines and block level elements
			$message = preg_replace("#(</?(?:html|head|body|div|p|form|table|thead|tbody|tfoot|tr|td|th|ul|ol|li|div|p|blockquote|cite|hr)[^>]*>)\s*<br />#i", "$1", $message);
			$message = preg_replace("#(&nbsp;)+(</?(?:html|head|body|div|p|form|table|thead|tbody|tfoot|tr|td|th|ul|ol|li|div|p|blockquote|cite|hr)[^>]*>)#i", "$2", $message);
		}

		$message = my_wordwrap($message);
	
		$message = $plugins->run_hooks("parse_message_end", $message);
				
		return $message;
	}

	/**
	 * Converts HTML in a message to their specific entities whilst allowing unicode characters.
	 *
	 * @param string The message to be parsed.
	 * @return string The formatted message.
	 */
	function parse_html($message)
	{
		$message = preg_replace("#&(?!\#[0-9]+;)#si", "&amp;", $message); // fix & but allow unicode
		$message = str_replace("<","&lt;",$message);
		$message = str_replace(">","&gt;",$message);
		return $message;
	}

	/**
	 * Generates a cache of MyCode, both standard and custom.
	 *
	 * @access private
	 */
	function cache_mycode()
	{
		global $cache, $lang;
		$this->mycode_cache = array();

		$standard_mycode['b']['regex'] = "#\[b\](.*?)\[/b\]#si";
		$standard_mycode['b']['replacement'] = "<strong>$1</strong>";

		$standard_mycode['u']['regex'] = "#\[u\](.*?)\[/u\]#si";
		$standard_mycode['u']['replacement'] = "<u>$1</u>";

		$standard_mycode['i']['regex'] = "#\[i\](.*?)\[/i\]#si";
		$standard_mycode['i']['replacement'] = "<em>$1</em>";

		$standard_mycode['s']['regex'] = "#\[s\](.*?)\[/s\]#si";
		$standard_mycode['s']['replacement'] = "<del>$1</del>";

		$standard_mycode['copy']['regex'] = "#\(c\)#i";
		$standard_mycode['copy']['replacement'] = "&copy;";

		$standard_mycode['tm']['regex'] = "#\(tm\)#i";
		$standard_mycode['tm']['replacement'] = "&#153;";

		$standard_mycode['reg']['regex'] = "#\(r\)#i";
		$standard_mycode['reg']['replacement'] = "&reg;";

		$standard_mycode['url_simple']['regex'] = "#\[url\]([a-z]+?://)([^\r\n\"\[<]+?)\[/url\]#sei";
		$standard_mycode['url_simple']['replacement'] = "\$this->mycode_parse_url(\"$1$2\")";

		$standard_mycode['url_simple2']['regex'] = "#\[url\]([^\r\n\"\[<]+?)\[/url\]#ei";
		$standard_mycode['url_simple2']['replacement'] = "\$this->mycode_parse_url(\"$1\")";

		$standard_mycode['url_complex']['regex'] = "#\[url=([a-z]+?://)([^\r\n\"\[<]+?)\](.+?)\[/url\]#esi";
		$standard_mycode['url_complex']['replacement'] = "\$this->mycode_parse_url(\"$1$2\", \"$3\")";

		$standard_mycode['url_complex2']['regex'] = "#\[url=([^\r\n\"\[<&\(\)]+?)\](.+?)\[/url\]#esi";
		$standard_mycode['url_complex2']['replacement'] = "\$this->mycode_parse_url(\"$1\", \"$2\")";

		$standard_mycode['email_simple']['regex'] = "#\[email\](.*?)\[/email\]#ei";
		$standard_mycode['email_simple']['replacement'] = "\$this->mycode_parse_email(\"$1\")";

		$standard_mycode['email_complex']['regex'] = "#\[email=(.*?)\](.*?)\[/email\]#ei";
		$standard_mycode['email_complex']['replacement'] = "\$this->mycode_parse_email(\"$1\", \"$2\")";

		$standard_mycode['color']['regex'] = "#\[color=([a-zA-Z]*|\#?[0-9a-fA-F]{6})](.*?)\[/color\]#si";
		$standard_mycode['color']['replacement'] = "<span style=\"color: $1;\">$2</span>";

		$standard_mycode['size']['regex'] = "#\[size=(xx-small|x-small|small|medium|large|x-large|xx-large)\](.*?)\[/size\]#si";
		$standard_mycode['size']['replacement'] = "<span style=\"font-size: $1;\">$2</span>";

		$standard_mycode['size_int']['regex'] = "#\[size=([0-9\+\-]+?)\](.*?)\[/size\]#esi";
		$standard_mycode['size_int']['replacement'] = "\$this->mycode_handle_size(\"$1\", \"$2\")";

		$standard_mycode['font']['regex'] = "#\[font=([a-z ]+?)\](.+?)\[/font\]#si";
		$standard_mycode['font']['replacement'] = "<span style=\"font-family: $1;\">$2</span>";

		$standard_mycode['align']['regex'] = "#\[align=(left|center|right|justify)\](.*?)\[/align\]#si";
		$standard_mycode['align']['replacement'] = "<div style=\"text-align: $1;\">$2</div>";

		$standard_mycode['hr']['regex'] = "#\[hr\]#si";
		$standard_mycode['hr']['replacement'] = "<hr />";

		$custom_mycode = $cache->read("mycode");

		// If there is custom MyCode, load it.
		if(is_array($custom_mycode))
		{
			foreach($custom_mycode as $key => $mycode)
			{
				$custom_mycode[$key]['regex'] = "#".$mycode['regex']."#si";
			}
			$mycode = array_merge($standard_mycode, $custom_mycode);
		}
		else
		{
			$mycode = $standard_mycode;
		}

		// Assign the MyCode to the cache.
		foreach($mycode as $code)
		{
			$this->mycode_cache['find'][] = $code['regex'];
			$this->mycode_cache['replacement'][] = $code['replacement'];
		}
	}

	/**
	 * Parses MyCode tags in a specific message with the specified options.
	 *
	 * @param string The message to be parsed.
	 * @param array Array of options in yes/no format. Options are allow_imgcode.
	 * @return string The parsed message.
	 */
	function parse_mycode($message, $options=array())
	{
		global $lang;

		// Cache the MyCode globally if needed.
		if($this->mycode_cache == 0)
		{
			$this->cache_mycode();
		}
		
		// Parse quotes first
		$message = $this->mycode_parse_quotes($message);

		$message = $this->mycode_auto_url($message);

		$message = str_replace('$', '&#36;', $message);
		
		// Replace the rest
		$message = preg_replace($this->mycode_cache['find'], $this->mycode_cache['replacement'], $message);

		// Special code requiring special attention
		while(preg_match("#\[list\](.*?)\[/list\]#esi", $message))
		{
			$message = preg_replace("#\s?\[list\](.*?)\[/list\](\r\n?|\n?)#esi", "\$this->mycode_parse_list('$1')\n", $message);
		}

		// Replace lists.
		while(preg_match("#\[list=(a|A|i|I|1)\](.*?)\[/list\](\r\n?|\n?)#esi", $message))
		{
			$message = preg_replace("#\s?\[list=(a|A|i|I|1)\](.*?)\[/list\]#esi", "\$this->mycode_parse_list('$2', '$1')\n", $message);
		}

		// Convert images when allowed.
		if($options['allow_imgcode'] !== 0)
		{
			$message = preg_replace("#\[img\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#ise", "\$this->mycode_parse_img('$2')\n", $message);
			$message = preg_replace("#\[img=([0-9]{1,3})x([0-9]{1,3})\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#ise", "\$this->mycode_parse_img('$4', array('$1', '$2'));", $message);
			$message = preg_replace("#\[img align=([a-z]+)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#ise", "\$this->mycode_parse_img('$3', array(), '$1');", $message);
			$message = preg_replace("#\[img=([0-9]{1,3})x([0-9]{1,3}) align=([a-z]+)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#ise", "\$this->mycode_parse_img('$5', array('$1', '$2'), '$3');", $message);
		}

		return $message;
	}

	/**
	 * Generates a cache of smilies
	 *
	 * @access private
	 */
	function cache_smilies()
	{
		global $cache;
		$this->smilies_cache = array();

		$smilies = $cache->read("smilies");
		if(is_array($smilies))
		{
			foreach($smilies as $sid => $smilie)
			{
				$this->smilies_cache[$smilie['find']] = "<img src=\"{$this->base_url}{$smilie['image']}\" style=\"vertical-align: middle;\" border=\"0\" alt=\"{$smilie['name']}\" title=\"{$smilie['name']}\" />";
			}
		}
	}

	/**
	 * Parses smilie code in the specified message.
	 *
	 * @param string The message being parsed.
	 * @param string Base URL for the image tags created by smilies.
	 * @param string Yes/No if HTML is allowed in the post
	 * @return string The parsed message.
	 */
	function parse_smilies($message, $allow_html=0)
	{
		if($this->smilies_cache == 0)
		{
			$this->cache_smilies();
		}
		
		$message = ' ' . $message . ' ';
		
		// First we take out any of the tags we don't want parsed between (url= etc)
		preg_match_all("#\[(url(=[^\]]*])?\].*?\[\/url\]|quote=([^\]]*)?\])#i", $message, $bad_matches, PREG_PATTERN_ORDER);
        $message = preg_replace("#\[(url(=[^\]]*])?\].*?\[\/url\]|quote=([^\]]*)?\])#si", "<mybb-bad-sm>", $message);
		
		// Impose a hard limit of 500 smilies per message as to not overload the parser
		$remaining = 500;

		if(is_array($this->smilies_cache))
		{
			foreach($this->smilies_cache as $find => $replace)
			{
				$find = $this->parse_html($find);
				if(version_compare(PHP_VERSION, "5.1.0", ">="))
				{
					$message = preg_replace("#(?<=[^\w&;/\"])".preg_quote($find,"#")."(?=.\W|\"|\W.|\W$)#si", $replace, $message, $remaining, $replacements);
					$remaining -= $replacements;
					if($remaining <= 0)
					{
						break; // Reached the limit
					}
				}
				else
				{
					$message = preg_replace("#(?<=[^\w&;/\"])".preg_quote($find,"#")."(?=.\W|\"|\W.|\W$)#si", $replace, $message, $remaining);
				}
			}
		}

		// If we matched any tags previously, swap them back in
		if(count($bad_matches[0]) > 0)
		{
			foreach($bad_matches[0] as $match)
			{
				$message = preg_replace("#<mybb-bad-sm>#", $match, $message, 1);
			}
		}

		return trim($message);
	}

	/**
	 * Generates a cache of badwords filters.
	 *
	 * @access private
	 */
	function cache_badwords()
	{
		global $cache;
		$this->badwords_cache = array();
		$this->badwords_cache = $cache->read("badwords");
	}

	/**
	 * Parses a list of filtered/badwords in the specified message.
	 *
	 * @param string The message to be parsed.
	 * @param array Array of parser options in yes/no format.
	 * @return string The parsed message.
	 */
	function parse_badwords($message, $options=array())
	{
		if($this->badwords_cache == 0)
		{
			$this->cache_badwords();
		}
		if(is_array($this->badwords_cache))
		{
			reset($this->badwords_cache);
			foreach($this->badwords_cache as $bid => $badword)
			{
				if(!$badword['replacement'])
				{
					$badword['replacement'] = "*****";
				}
				$badword['badword'] = preg_quote($badword['badword']);
				$message = preg_replace("#(\b|^)".$badword['badword']."(\b|$)#i", "\\1".$badword['replacement']."\\2", $message);
			}
		}
		if($options['strip_tags'] == 1)
		{
			$message = strip_tags($message);
		}
		return $message;
	}

	/**
	 * Attempts to move any javascript references in the specified message.
	 *
	 * @param string The message to be parsed.
	 * @return string The parsed message.
	 */
	function fix_javascript($message)
	{
		$js_array = array(
			"#(&\#(0*)106;|&\#(0*)74;|j)((&\#(0*)97;|&\#(0*)65;|a)(&\#(0*)118;|&\#(0*)86;|v)(&\#(0*)97;|&\#(0*)65;|a)(\s)?(&\#(0*)115;|&\#(0*)83;|s)(&\#(0*)99;|&\#(0*)67;|c)(&\#(0*)114;|&\#(0*)82;|r)(&\#(0*)105;|&\#(0*)73;|i)(&\#112;|&\#(0*)80;|p)(&\#(0*)116;|&\#(0*)84;|t)(&\#(0*)58;|\:))#i",
			"#(o)(nmouseover\s?=)#i",
			"#(o)(nmouseout\s?=)#i",
			"#(o)(nmousedown\s?=)#i",
			"#(o)(nmousemove\s?=)#i",
			"#(o)(nmouseup\s?=)#i",
			"#(o)(nclick\s?=)#i",
			"#(o)(ndblclick\s?=)#i",
			"#(o)(nload\s?=)#i",
			"#(o)(nsubmit\s?=)#i",
			"#(o)(nblur\s?=)#i",
			"#(o)(nchange\s?=)#i",
			"#(o)(nfocus\s?=)#i",
			"#(o)(nselect\s?=)#i",
			"#(o)(nunload\s?=)#i",
			"#(o)(nkeypress\s?=)#i"
		);
		$message = preg_replace($js_array, "$1<strong></strong>$2", $message);

		return $message;
	}
	
	/**
	* Handles fontsize.
	*
	* @param string The original size.
	* @param string The text within a size tag.
	* @return string The parsed text.
	*/
	function mycode_handle_size($size, $text)
	{
		$size = intval($size)+10;

		if($size > 50)
		{
			$size = 50;
		}

		$text = "<span style=\"font-size: {$size}pt\">".str_replace('\"', '"', $text)."</span>";

		return $text;
	}

	/**
	* Parses quote MyCode.
	*
	* @param string The message to be parsed
	* @param boolean Are we formatting as text?
	* @return string The parsed message.
	*/
	function mycode_parse_quotes($message, $text_only=false)
	{
		global $lang, $templates, $theme, $mybb;

		// Assign pattern and replace values.
		$pattern = array(
			"#\[quote=(?:&quot;|\"|')?(.*?)[\"']?(?:&quot;|\"|')?\](.*?)\[\/quote\](\r\n?|\n?)#esi",
			"#\[quote\](.*?)\[\/quote\](\r\n?|\n?)#si"
		);

		if($text_only == false)
		{
			$replace = array(
				"\$this->mycode_parse_post_quotes('$2','$1')",
				"</p>\n<blockquote><cite>$lang->quote</cite>$1</blockquote><p>\n"
			);
		}
		else
		{
			$replace = array(
				"\$this->mycode_parse_post_quotes('$2', '$1', true)",
				"\n{$lang->quote}\n--\n$1\n--\n"
			);
		}

		while(preg_match($pattern[0], $message) || preg_match($pattern[1], $message))
		{
			$message = preg_replace($pattern, $replace, $message);
		}

		if($text_only == false)
		{
			$find = array(
				"#(\r\n*|\n*)<\/cite>(\r\n*|\n*)#",
				"#(\r\n*|\n*)<\/blockquote>#"
			);

			$replace = array(
				"</cite><br />",
				"</blockquote>"
			);
			$message = preg_replace($find, $replace, $message);
		}
		return $message;
	}
	
	/**
	* Parses quotes with post id and/or dateline.
	*
	* @param string The message to be parsed
	* @param string The username to be parsed
	* @param boolean Are we formatting as text?
	* @return string The parsed message.
	*/
	function mycode_parse_post_quotes($message, $username, $text_only=false)
	{
		global $lang, $templates, $theme, $mybb;

		$linkback = $date = "";

		$message = trim($message);
		$message = preg_replace("#(^<br(\s?)(\/?)>|<br(\s?)(\/?)>$)#i", "", $message);

		if(!$message) return '';

		$message = str_replace('\"', '"', $message);
		$username = str_replace('\"', '"', $username)."'";
		$delete_quote = true;

		preg_match("#pid=(?:&quot;|\"|')?([0-9]+)[\"']?(?:&quot;|\"|')?#i", $username, $match);
		if(intval($match[1]))
		{
			$pid = intval($match[1]);
			$url = $mybb->settings['bburl']."/".get_post_link($pid)."#pid$pid";
			if(defined("IN_ARCHIVE"))
			{
				$linkback = " <a href=\"{$url}\">[ -> ]</a>";
			}
			else
			{
				eval("\$linkback = \" ".$templates->get("postbit_gotopost", 1, 0)."\";");
			}
			
			$username = preg_replace("#(?:&quot;|\"|')? pid=(?:&quot;|\"|')?[0-9]+[\"']?(?:&quot;|\"|')?#i", '', $username);
			$delete_quote = false;
		}

		unset($match);
		preg_match("#dateline=(?:&quot;|\"|')?([0-9]+)(?:&quot;|\"|')?#i", $username, $match);
		if(intval($match[1]))
		{
			if($match[1] < TIME_NOW)
			{
				$postdate = my_date($mybb->settings['dateformat'], intval($match[1]));
				$posttime = my_date($mybb->settings['timeformat'], intval($match[1]));
				$date = " ({$postdate} {$posttime})";
			}
			$username = preg_replace("#(?:&quot;|\"|')? dateline=(?:&quot;|\"|')?[0-9]+(?:&quot;|\"|')?#i", '', $username);
			$delete_quote = false;
		}

		if($delete_quote)
		{
			$username = my_substr($username, 0, my_strlen($username)-1);
		}

		if($text_only)
		{
			return "\n".htmlspecialchars_uni($username)." $lang->wrote{$date}\n--\n{$message}\n--\n";
		}
		else
		{
			$span = "";
			if(!$delete_quote)
			{
				$span = "<span style=\"float: right; font-weight: normal;\">{$date}</span>";
			}
			return "<p>\n<blockquote><cite>{$span}".htmlspecialchars_uni($username)." $lang->wrote{$linkback}</cite>{$message}</blockquote></p>\n";
		}
	}

	/**
	* Parses code MyCode.
	*
	* @param string The message to be parsed
	* @param boolean Are we formatting as text?
	* @return string The parsed message.
	*/
	function mycode_parse_code($code, $text_only=false)
	{
		global $lang;

		if($text_only == true)
		{
			return "\n{$lang->code}\n--\n{$code}\n--\n";
		}

		// Clean the string before parsing.
		$code = preg_replace('#^(\t*)(\n|\r|\0|\x0B| )*#', '\\1', $code);
		$code = rtrim($code);
		$original = preg_replace('#^\t*#', '', $code);

		if(empty($original))
		{
			return;
		}

		$code = str_replace('$', '&#36;', $code);
		$code = preg_replace('#\$([0-9])#', '\\\$\\1', $code);
		$code = str_replace('\\', '&#92;', $code);
		$code = str_replace("\t", '&nbsp;&nbsp;&nbsp;&nbsp;', $code);
		$code = str_replace("  ", '&nbsp;&nbsp;', $code);

		return "<div class=\"codeblock\">\n<div class=\"title\">".$lang->code."\n</div><div class=\"body\" dir=\"ltr\"><code>".$code."</code></div></div>\n";
	}

	/**
	* Parses PHP code MyCode.
	*
	* @param string The message to be parsed
	* @param boolean Whether or not it should return it as pre-wrapped in a div or not.
	* @param boolean Are we formatting as text?
	* @return string The parsed message.
	*/
	function mycode_parse_php($str, $bare_return = false, $text_only = false)
	{
		global $lang;

		if($text_only == true)
		{
			return "\n{$lang->php_code}\n--\n$str\n--\n";
		}

		// Clean the string before parsing except tab spaces.
		$str = preg_replace('#^(\t*)(\n|\r|\0|\x0B| )*#', '\\1', $str);
		$str = rtrim($str);

		$original = preg_replace('#^\t*#', '', $str);

		if(empty($original))
		{
			return;
		}

		$str = str_replace('&amp;', '&', $str);
		$str = str_replace('&lt;', '<', $str);
		$str = str_replace('&gt;', '>', $str);

		// See if open and close tags are provided.
		$added_open_tag = false;
		if(!preg_match("#^\s*<\?#si", $str))
		{
			$added_open_tag = true;
			$str = "<?php \n".$str;
		}

		$added_end_tag = false;
		if(!preg_match("#\?>\s*$#si", $str))
		{
			$added_end_tag = true;
			$str = $str." \n?>";
		}

		// If the PHP version < 4.2, catch highlight_string() output.
		if(version_compare(PHP_VERSION, "4.2.0", "<"))
		{
			ob_start();
			@highlight_string($str);
			$code = ob_get_contents();
			ob_end_clean();
		}
		else
		{
			$code = @highlight_string($str, true);
		}

		// If < PHP 5, make XHTML compatible.
		if(version_compare(PHP_VERSION, "5", "<"))
		{
			$find = array(
				"<font",
				"color=\"",
				"</font>"
			);

			$replace = array(
				"<span",
				"style=\"color: ",
				"</span>"
			);
			$code = str_replace($find, $replace, $code);
		}

		// Do the actual replacing.
		$code = preg_replace('#<code>\s*<span style="color: \#000000">\s*#i', "<code>", $code);
		$code = preg_replace("#</span>\s*</code>#", "</code>", $code);
		$code = preg_replace("#</span>(\r\n?|\n?)</code>#", "</span></code>", $code);
		$code = str_replace("\\", '&#092;', $code);
		$code = str_replace('$', '&#36;', $code);
		$code = preg_replace("#&amp;\#([0-9]+);#si", "&#$1;", $code);

		if($added_open_tag)
		{
			$code = preg_replace("#<code><span style=\"color: \#([A-Z0-9]{6})\">&lt;\?php( |&nbsp;)(<br />?)#", "<code><span style=\"color: #$1\">", $code);
		}

		if($added_end_tag)
		{
			$code = str_replace("?&gt;</span></code>", "</span></code>", $code);
			// Wait a minute. It fails highlighting? Stupid highlighter.
			$code = str_replace("?&gt;</code>", "</code>", $code);
		}

		$code = preg_replace("#<span style=\"color: \#([A-Z0-9]{6})\"></span>#", "", $code);
		$code = str_replace("<code>", "<div dir=\"ltr\"><code>", $code);
		$code = str_replace("</code>", "</code></div>", $code);
		$code = preg_replace("# *$#", "", $code);

		if($bare_return)
		{
			return $code;
		}

		// Send back the code all nice and pretty
		return "<div class=\"codeblock phpcodeblock\"><div class=\"title\">$lang->php_code\n</div><div class=\"body\">".$code."</div></div>\n";
	}

	/**
	* Parses URL MyCode.
	*
	* @param string The URL to link to.
	* @param string The name of the link.
	* @return string The built-up link.
	*/
	function mycode_parse_url($url, $name="")
	{
		if(!preg_match("#^[a-z0-9]+://#i", $url))
		{
			$url = "http://".$url;
		}
		$fullurl = $url;

		$url = str_replace('&amp;', '&', $url);
		$name = str_replace('&amp;', '&', $name);

		if(!preg_match("#[a-z0-9]+://#i", $fullurl))
		{
			$fullurl = "http://".$fullurl;
		}
		if(!$name)
		{
			$name = $url;
		}
		
		$name = str_replace('\"', '"', $name);
		$url = str_replace('\"', '"', $url);
		$fullurl = str_replace('\"', '"', $fullurl);
		
		if($name == $url && $this->options['shorten_urls'] != 0)
		{
			if(my_strlen($url) > 55)
			{
				$name = my_substr($url, 0, 40)."...".my_substr($url, -10);
			}
		}

		$name = preg_replace("#&amp;\#([0-9]+);#si", "&#$1;", $name); // Fix & but allow unicode
		$link = "<a href=\"$fullurl\" target=\"_blank\">$name</a>";
		return $link;
	}

	/**
	 * Parses IMG MyCode.
	 *
	 * @param string The URL to the image
	 * @param array Optional array of dimensions
	 */
	function mycode_parse_img($url, $dimensions=array(), $align='')
	{
		global $lang;
		$url = trim($url);
		$url = str_replace("\n", "", $url);
		$url = str_replace("\r", "", $url);
		if($align == "right")
		{
			$css_align = " style=\"float: right;\"";
		}
		else if($align == "left")
		{
			$css_align = " style=\"float: left;\"";
		}
		$alt = htmlspecialchars_uni(basename($url));
		if(my_strlen($alt) > 55)
		{
			$alt = my_substr($alt, 0, 40)."...".my_substr($alt, -10);
		}
		$alt = $lang->sprintf($lang->posted_image, $alt);
		if($dimensions[0] > 0 && $dimensions[1] > 0)
		{
			return "<img src=\"{$url}\" width=\"{$dimensions[0]}\" height=\"{$dimensions[1]}\" border=\"0\" alt=\"{$alt}\"{$css_align} />";
		}
		else
		{
			return "<img src=\"{$url}\" border=\"0\" alt=\"{$alt}\"{$css_align} />";			
		}
	}

	/**
	* Parses email MyCode.
	*
	* @param string The email address to link to.
	* @param string The name for the link.
	* @return string The built-up email link.
	*/
	function mycode_parse_email($email, $name="")
	{
		if(!$name)
		{
			$name = $email;
		}
		if(preg_match("/^([a-zA-Z0-9-_\+\.]+?)@[a-zA-Z0-9-]+\.[a-zA-Z0-9\.-]+$/si", $email))
		{
			return "<a href=\"mailto:$email\">".$name."</a>";
		}
		else
		{
			return $email;
		}
	}

	/**
	* Parses URLs automatically.
	*
	* @param string The message to be parsed
	* @return string The parsed message.
	*/
	function mycode_auto_url($message)
	{
		$message = " ".$message;
		$message = preg_replace("#([\s\(\)])(https?|ftp|news){1}://([\w\-]+\.([\w\-]+\.)*[\w]+(:[0-9]+)?(/[^\"\s<\[]*)?)#i", "$1[url]$2://$3[/url]", $message);
		$message = preg_replace("#([\s\(\)])(www|ftp)\.(([\w\-]+\.)*[\w]+(:[0-9]+)?(/[^\"\s<\[]*)?)#i", "$1[url]$2.$3[/url]", $message);
		$message = my_substr($message, 1);
		return $message;
	}

	/**
	* Parses list MyCode.
	*
	* @param string The message to be parsed
	* @param string The list type
	* @param boolean Are we formatting as text?
	* @return string The parsed message.
	*/
	function mycode_parse_list($message, $type="")
	{
		$message = str_replace('\"', '"', $message);
		$message = preg_replace("#\s*\[\*\]\s*#", "</li>\n<li>", $message);
		$message .= "</li>";

		if($type)
		{
			$list = "\n<ol type=\"$type\">$message</ol>\n";
		}
		else
		{
			$list = "<ul>$message</ul>\n";
		}
		$list = preg_replace("#<(ol type=\"$type\"|ul)>\s*</li>#", "<$1>", $list);
		return $list;
	}

	/**
	 * Strips smilies from a string
 	 *
	 * @param string The message for smilies to be stripped from
	 * @return string The message with smilies stripped
	 */
	function strip_smilies($message)
	{
		if($this->smilies_cache == 0)
		{
			$this->cache_smilies();
		}
		if(is_array($this->smilies_cache))
		{
			$message = str_replace($this->smilies_cache, array_keys($this->smilies_cache), $message);
		}
		return $message;
	}

	/**
	 * Parses message to plain text equivilents of MyCode.
	 *
	 * @param string The message to be parsed
	 * @return string The parsed message.
	 */
	function text_parse_message($message, $options=array())
	{
		global $plugins;
		
		// Filter bad words if requested.
		if($options['filter_badwords'] != 0)
		{
			$message = $this->parse_badwords($message);
		}

		// Parse quotes first
		$message = $this->mycode_parse_quotes($message, true);

		$find = array(
			"#\[(b|u|i|s|url|email|color|img)\](.*?)\[/\\1\]#is",
			"#\[code\](.*?)\[/code\](\r\n?|\n?)#ise",
			"#\[php\](.*?)\[/php\](\r\n?|\n?)#ise",
			"#\[img=([0-9]{1,3})x([0-9]{1,3})\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is"
		);
		
		$replace = array(
			"$2",
			"\$this->mycode_parse_php('$1', false, true)",
			"\$this->mycode_parse_code('$1', true)",
			"$4"
		);
		$message = preg_replace($find, $replace, $message);
		
		// Replace "me" code and slaps if we have a username
		if($options['me_username'])
		{
			global $lang;
			
			$message = preg_replace('#(>|^|\r|\n)/me ([^\r\n<]*)#i', "\\1* {$options['me_username']} \\2", $message);
			$message = preg_replace('#(>|^|\r|\n)/slap ([^\r\n<]*)#i', "\\1* {$options['me_username']} {$lang->slaps} \\2 {$lang->with_trout}", $message);
		}

		// Special code requiring special attention
		while(preg_match("#\[list\](.*?)\[/list\]#si", $message))
		{
			$message = preg_replace("#\[list\](.*?)\[/list\](\r\n?|\n?)#esi", "\$this->mycode_parse_list('$1', '', true)\n", $message);
		}

		// Replace lists.
		while(preg_match("#\[list=(a|A|i|I|1)\](.*?)\[/list\](\r\n?|\n?)#esi", $message))
		{
			$message = preg_replace("#\[list=(a|A|i|I|1)\](.*?)\[/list\]#esi", "\$this->mycode_parse_list('$2', '$1', true)\n", $message);
		}

		// Run plugin hooks
		$message = $plugins->run_hooks("text_parse_message", $message);
		
		return $message;
	}
}
?>