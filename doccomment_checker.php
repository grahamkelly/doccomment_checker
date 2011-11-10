<?php
/**
 * Doc-comment Checker
 *
 * The MIT License
 *
 * Copyright (c) 2011 Graham Kelly
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is 
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in 
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR 
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, 
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER 
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN 
 * THE SOFTWARE
 *
 * 
 * @author Graham Kelly  <graham@graha.mk>
 * @copyright Copyright (c) 2011 Graham Kelly
 * @license http://www.opensource.org/licenses/MIT  The MIT License
 *
 * @package doccomment_checker
 */


/**
 * The core doc-comment checker. This is meant to be easily expandable for 
 * custom uses (such as a packaging or build script). To check a file simply 
 * call the check_file() function. Similarly to check all the files in a 
 * directory (and sub-directories) call check_dir(). To grab the missing 
 * doc-comment errors being reported wirte a child class of doc_comment_chekcer
 * and implement a version of report_error().
 *
 * 
 * @author Graham Kelly  <graham@graha.mk>
 * @package doccomment_checker
 */
class doc_comment_checker {
	const PATTERN_GROUP          = 1;
	const PATTERN_GROUP_OPTIONAL = 2;
	const PATTERN_GROUP_OR       = 3;

	public $report_missing_file_comments        = true;
	public $report_missing_class_comments       = true;
	public $report_missing_interface_comments   = true;
	public $report_missing_class_var_comments   = true;
	public $report_missing_class_const_comments = true;
	public $report_missing_constant_comments    = true;
	public $report_missing_function_comments    = true;


	public $missing_file_comment_errors        = 0;
	public $missing_class_comment_errors       = 0;
	public $missing_interface_comment_errors   = 0;
	public $missing_class_var_comment_errors   = 0;
	public $missing_class_const_comment_errors = 0;
	public $missing_constant_comment_errors    = 0;
	public $missing_function_comment_errors    = 0;

	public $found_file_comments        = 0;
	public $found_class_comments       = 0;
	public $founc_interface_comments   = 0;
	public $found_class_var_comments   = 0;
	public $found_class_const_comments = 0;
	public $found_constant_comments    = 0;
	public $found_function_comments    = 0;


	public $files_checked = 0;
	public $directories_checked = 0;


	/**
	 * Checks all the .php files in a directory for missing doc-comments. 
	 *
	 * @param string $dir - The directory to check
	 * @return boolean
	 */
	public function check_dir($dir) {
		if (!file_exists($dir)) {
			return false;
		}

		if (!is_dir($dir)) {
			return $this->check_file($dir);
		}

		$this->directories_checked++;

		if (substr($dir, -1, 1) != '/' || substr($dir, -1, 1) != '/') {
			$dir .= '/';
		}

		$handle = opendir($dir);

		while (($file = readdir($handle)) !== false) {
			if ($file != '.' && $file != '..') {
				if (is_dir($dir.$file)) {
					$this->check_dir($dir.$file);
				} else if (strtolower(substr($file, -4, 4)) == '.php') {
					$this->check_file($dir.$file);
				}
			}
		}

		closedir($handle);

		return true;
	}


	/**
	 * Checks a PHP file for missing doc-comments. 
	 *
	 * @param string $file - The name of the file to check. 
	 */
	public function check_file($file) {
		$i = 0;

		$class_name = null;
		$class_line = 0;
		$class_nesting = 0;

		$interface_name = null;
		$interface_line = 0;
		$interface_nesting = 0;

		$function_nesting = -1;
		
		$file_doc_comment = -1;
		$last_doc_comment = -1;

		$this->files_checked++;

		$tokens = token_get_all(file_get_contents($file));
		$c = count($tokens);

		//$this->debug_dump_tokens($tokens);

		$this->check_file_doc_comment($file, $tokens, $i, $file_doc_comment);

		while ($i < $c) {
			$loop_i = $i;

			if (is_array($tokens[$i])) {
				$token = $tokens[$i][0];
			} else {
				$token = $tokens[$i];
			}

			switch ($token) {
				case T_CLASS:
					$this->check_class($file, $tokens, $i, $last_doc_comment, $class_name, $class_line);
					$class_nesting = 0;
					break;
				case T_INTERFACE:
					$this->check_interface($file, $tokens, $i, $last_doc_comment, $interface_name, $interface_line);
					$interface_nesting = 0;
					break;
				case T_FUNCTION:
					$this->check_function($file, $tokens, $i, $last_doc_comment, $class_name, $class_line, $interface_name, $interface_line);
					$function_nesting = 0;

					/* 
					 * Skip until the first { token. If this is not done all 
					 * the parameters will be reported as class variables with 
					 * missing doc-comments. 
					 */
					while ((is_array($tokens[$i+1]) || $tokens[$i+1] != '{') && $i < $c) {
						++$i;
					}
					break;
				case T_VARIABLE:
					if (($class_name !== null || $interface_name !== null) && $function_nesting <= 0) {
						$this->check_class_variable($file, $tokens, $i, $last_doc_comment, $class_name, $class_line, $interface_name, $interface_line);
					}
					break;
				case T_CONST:
					if ($class_name !== null || $interface_name !== null) {
						$this->check_class_constant($file, $tokens, $i, $last_doc_comment, $class_name, $class_line, $interface_name, $interface_line);
					}
					break;
				case T_DOC_COMMENT:
					$last_doc_comment = $i;
					$i++;
					break;
				case '{':
					if ($class_name !== null) {
						$class_nesting++;
					} else if ($interface_name !== null) {
						$interface_nesting++;
					}

					if ($function_nesting >= 0) {
						$function_nesting++;
					}
					$i++;
					break;
				case '}':
					if ($class_name !== null) {
						$class_nesting--;

						if ($class_nesting == 0) {
							$class_name = null;
							$class_line = 0;
						}
					} else if ($interface_name !== null) {
						$interface_nesting--;

						if ($interface_nesting == 0) {
							$interface_name = null;
							$interface_line = 0;
						}
					}

					if ($function_nesting > 0) {
						$function_nesting--;
					} else {
						$function_nesting = -1;
					}
					$i++;
					break;
				default:
					$i++;
					break;
			}


			if ($file_doc_comment >= 0 && $last_doc_comment == $file_doc_comment) {
				/*
				 * Even if a doc-comment was found at the head of the file it 
				 * does not mean that it is a file level comment. it might be 
				 * associated with the first class, function, etc in the file 
				 * instead. if that is the case we need to report the missing 
				 * file-level doc-comment error. 
				 *
				 */
				$this->report_file_doc_comment_error($file, 1);
				$file_doc_comment = -1; /* reset to prevent multiple error messages from occuring */
			}	


			if ($i <= $loop_i) {
				/* 
				 * There is no reason for this to be here but its a small safety
				 * measure to prevent infinant loops 
				 */
				$i = $loop_i+1;
			}
		}
	}


	/**
	 * Checks for a doc-comment directly before the start of a class. When this 
	 * function is called $i should be pointing to the T_CLASS token. 
	 *
	 * T_DOC_COMMENT
	 * [T_WHITESPACE]
	 * [T_ABSTRACT T_WHITESPACE] T_CLASS T_WHITESPACE T_STRING
	 * 
	 * @param string $file - 
	 * @param array $tokens - 
	 * @param integer $i - 
	 * @param integer $last_doc_comment - 
	 * @param string $class_name -
	 * @param integer $class_line - 
	 */
	private function check_class($file, $tokens, &$i, &$last_doc_comment, &$class_name, &$class_line) {
		$this->detect_token_type_error($tokens[$i], T_CLASS);

		$back_i = $i;

		/* Find the class name and line */
		$this->skip_whitespace($tokens, $i);
		$this->detect_token_type_error($tokens[$i], T_STRING);

		$class_name = $tokens[$i][1];
		$class_line = $tokens[$i][2];


		/* Check for the doc-comment */
		$pattern = array(
			self::PATTERN_GROUP_OPTIONAL,
			T_ABSTRACT,
			T_WHITESPACE,
		);
		$this->skip_reverse($pattern, $tokens, $back_i);
		$this->skip_whitespace_reverse($tokens, $back_i);


		if (!is_array($tokens[$back_i]) || $tokens[$back_i][0] != T_DOC_COMMENT) {
			/* Error: Missing doc-comment for class */
			$this->missing_class_comment_errors++;

			if ($this->report_missing_class_comments) {
				$this->report_error($file, $class_line, 'Missing doc-comment for class `'.$class_name.'`');
			}
		} else {
			$this->found_class_comments++;
			$last_doc_comment = $back_i;
		}
	}




	/**
	 * Checks for a doc-comment directly before the start of an interface.
	 *
	 * T_DOC_COMMENT
	 * [T_WHITESPACE]
	 * T_INTERFACE T_WHITESPACE T_STRING
	 *
	 * @param string $file - 
	 * @param array $tokens - 
	 * @param integer $i - 
	 * @param integer $last_doc_comment - 
	 * @param string $interface_name - 
	 * @param integer $interface_line - 
	 */
	private function check_interface($file, $tokens, &$i, &$last_doc_comment, &$interface_name, &$interface_line) {
		$this->detect_token_type_error($tokens[$i], T_INTERFACE);

		$back_i = $i;

		/* Find interface name and line */
		$this->skip_whitespace($tokens, $i);
		$this->detect_token_type_error($tokens[$i], T_STRING);

		$interface_name = $tokens[$i][1];
		$interface_line = $tokens[$i][2];

		/* Check for doc-comment */
		$this->skip_whitespace_reverse($tokens, $back_i);

		if (!is_array($tokens[$back_i]) || $tokens[$back_i][0] != T_DOC_COMMENT) {
			/* Error: Missing doc-comment for interface */
			$this->missing_interface_comment_errors++;

			if ($this->report_missing_interface_comments) {
				$this->report_error($file, $interface_line, 'Missing doc-comment for interface `'.$interface_name.'`');
			}
		} else {
			$this->found_interface_comments++;
			$last_doc_comment = $back_i;
		}
	}

	
	/**
	 * Checks for a doc-comment directly before the start of a function. 
	 *
	 * T_DOC_COMMENT
	 * [T_WHITESPACE]
	 * [T_STATIC T_WHITESPACE] [T_PUBLIC | T_PRIVATE | T_PROTECTED] T_WHITESPACE [T_STATIC T_WHITESPACE] T_FUNCTION T_WHITESPACE T_STRING
	 *
	 * @param string $file - 
	 * @param array $tokens - 
	 * @param integer $i - 
	 * @param integer $last_doc_comment - 
	 * @param string $class_name - 
	 * @param integer $class_line - 
	 * @param string $interface_name - 
	 * @param integer $interface_line - 
	 */
	private function check_function($file, $tokens, &$i, &$last_doc_comment, $class_name, $class_line, $interface_name, $interface_line) {
		$this->detect_token_type_error($tokens[$i], T_FUNCTION);
	
		$back_i = $i;

		/* Find function name and line */
		$this->skip_whitespace($tokens, $i);
		$this->detect_token_type_error($tokens[$i], T_STRING);

		$function_name = $tokens[$i][1];
		$function_line = $tokens[$i][2];

		/* Check for doc-comment */
		$pattern = array(
			self::PATTERN_GROUP,
			array(
				self::PATTERN_GROUP_OPTIONAL,
				T_STATIC,
				T_WHITESPACE,
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				array(
					self::PATTERN_GROUP_OR,
					T_PUBLIC,
					T_PRIVATE,
					T_PROTECTED,
				),
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				T_WHITESPACE,
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				T_STATIC,
				T_WHITESPACE,
			),
		);
		$this->skip_reverse($pattern, $tokens, $back_i);
		$this->skip_whitespace_reverse($tokens, $back_i);

		if (!is_array($tokens[$back_i]) || $tokens[$back_i][0] != T_DOC_COMMENT) {
			/* Error: Missing doc-comment for function */
			$this->missing_function_comment_errors++;

			if ($this->report_missing_function_comments) {
				if ($class_name !== null) {
					$this->report_error($file, $function_line, 'Missing doc-comment for function `'.$class_name.'::'.$function_name.'`');
				} else if ($interface_name !== null) {
					$this->report_error($file, $function_line, 'Missing doc-comment for function `'.$interface_name.'::'.$function_name.'`');
				} else {
					$this->report_error($file, $function_line, 'Missing doc-comment for function `'.$function_name.'`');
				}
			}
		} else {
			$this->found_function_comments++;
			$last_doc_comment = $back_i;
		}
	}

	
	/**
	 * Checks for a doc-comment directly before the start of a class variable.
	 *
	 * T_DOC_COMMENT
	 * [T_WHITESPACE]
	 * [T_STATIC T_WHITESPACE] [T_PUBLIC | T_PRIVATE | T_PROTECTED] T_WHITESPACE [T_STATIC T_WHITESPACE] T_VARIABLE
	 *
	 */
	private function check_class_variable($file, $tokens, &$i, &$last_doc_comment, $class_name, $class_line, $interface_name, $interface_line) {
		$this->detect_token_type_error($tokens[$i], T_VARIABLE);

		$back_i = $i;

		/* Find class variable and line */
		$var_name = $tokens[$i][1];
		$var_line = $tokens[$i][2];

		
		/* Check for doc-comment */
		$pattern = array(
			self::PATTERN_GROUP,
			array(
				self::PATTERN_GROUP_OPTIONAL,
				array(
					self::PATTERN_GROUP,
					T_STATIC,
					T_WHITESPACE,
				),
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				T_WHITESPACE,
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				array(
					self::PATTERN_GROUP_OR,
					T_PUBLIC,
					T_PRIVATE,
					T_PROTECTED,
				),
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				T_WHITESPACE,
			),
			array(
				self::PATTERN_GROUP_OPTIONAL,
				array(
					self::PATTERN_GROUP,
					T_STATIC,
					T_WHITESPACE
				),
			),
		);
		$this->skip_reverse($pattern, $tokens, $back_i);
		$this->skip_whitespace_reverse($tokens, $back_i);


		if (!is_array($tokens[$back_i]) || $tokens[$back_i][0] != T_DOC_COMMENT) {
			/* Error: Missing doc-comment for class variable */
			$this->missing_class_var_comment_errors++;

			if ($this->report_missing_class_var_comments) {
				if ($class_name !== null) {
					$this->report_error($file, $var_line, 'Missing doc-comment for class variable `'.$class_name.'::'.$var_name.'`');
				} else if ($interface_name !== null) {
					$this->report_error($file, $var_line, 'Missing doc-comment for class variable `'.$interface_name.'::'.$var_name.'`');
				} else {
					$this->report_error($file, $var_line, 'Missing doc-comment for class variable `'.$var_name.'`');
				}
			}
		} else {
			$this->found_class_var_comments++;
			$last_doc_comment = $back_i;
		}
	}


	/**
	 * Checks for a doc-comment directly before the start of a class constant.
	 *
	 * T_DOC_COMMENT
	 * [T_WHITESPACE]
	 * T_CONST T_WHITESPACE T_STRING
	 *
	 * @param string $file - 
	 * @param array $tokens - 
	 * @param integer $i - 
	 * @param integer $last_doc_comment - 
	 * @param string $class_name - 
	 * @param integer $class_line - 
	 * @param string $interface_name - 
	 * @param integer $interface_line - 
	 */
	private function check_class_constant($file, $tokens, &$i, &$last_doc_comment, $class_name, $class_line, $interface_name, $interface_line) {
		$this->detect_token_type_error($tokens[$i], T_CONST);

		$back_i = $i;

		/* Find Constant Name */
		$this->skip_whitespace($tokens, $i);
		$this->detect_token_type_error($tokens[$i], T_STRING);

		$const_name = $tokens[$i][1];
		$const_line = $tokens[$i][2];

		/* Check for doc-comment */
		$this->skip_whitespace_reverse($tokens, $back_i);

		if (!is_array($tokens[$back_i]) || $tokens[$back_i][0] != T_DOC_COMMENT) {
			/* Error: Missing doc-comment for class level constant */
			$this->missing_class_cont_comment_errors++;

			if ($this->report_missing_class_const_comments) {
				$this->report_error($file, $const_line, 'Missing doc-comment for class level constant `'.$class_name.'::'.$const_name.'`');
			}
		} else {
			$this->found_class_const_comments++;
			$last_doc_comment = $back_i;
		}
	}


	/**
	 * Checks for a doc-comment directly before a define() function call. 
	 *
	 * T_DOC_COMMENT
	 * [T_WHITESPACE]
	 * T_STRING=define [T_WHITESPACE] ( [T_WHITESPACE] [T_STRING | ] [T_WHITESPACE] 
	 * 
	 */
	private function check_constant($file, $tokens, &$i, &$last_doc_comment) {
		
	}


	/**
	 * Checks that a doc-comment is at the start of a file. 
	 *
	 * [T_INLINE_HTML]
	 * [T_OPEN_TAG]
	 * T_DOC_COMMENT
	 *
	 * @param string $file - 
	 * @param array $tokens - 
	 * @param integer $i - 
	 * @param integer $last_doc_comment - 
	 */
	private function check_file_doc_comment($file, $tokens, &$i, &$last_doc_comment) {
		$last_line = 1;

		if ($i > 0) {
			return false;
		}

		if (is_array($tokens[$i]) && $tokens[$i][0] == T_INLINE_HTML) {
			$last_line = $tokens[$i][2];
			$i++;
		}

		if (is_array($tokens[$i]) && $tokens[$i][0] == T_OPEN_TAG) {
			$last_line = $tokens[$i][2];
			$i++;
		}

		if (!is_array($tokens[$i]) || $tokens[$i][0] != T_DOC_COMMENT) {
			if (is_array($tokens[$i])) {
				$last_line = $tokens[$i][2];
			}

			/* Error: missing file-level doc-comment */
			$this->report_file_doc_comment_error($file, $last_line);
		} else {
			$this->found_file_comments++;
			$last_doc_comment = $i;
			$i++;
		}
	}

	
	/**
	 * Simply reports the error message for a missing file-level doc-comment 
	 * error. 
	 *
	 * @param string $file 
	 * @param integer $line 
	 */
	private function report_file_doc_comment_error($file, $line) {
		$this->missing_file_comment_errors++;

		if ($this->report_missing_file_comments) {
			$this->report_error($file, $line, 'Missing file level doc-comment');
		}
	}


	/**
	 * Reports the existance of a missing doc-comment.
	 *
	 * @param string $file
	 * @param integer $line
	 * @param string $message
	 */	
	protected function report_error($file, $line, $message) {
		echo($file.':'.$line.': '.$message."\n");
	}


	/**
	 * Returns false if the token is not of type $type.
	 *
	 * @param array|string $token - 
	 * @param integer|string $type -
	 * @return boolean
	 */
	private function detect_token_type_error($token, $type) {
		if ((is_array($token) && $token[0] == $type) || (is_string($token) && $token == $type)) {
			return true;
		}

		return false;
	}


	/**
	 * Moves the pointer, $i, past all whitespace tokens. 
	 *
	 * @param array $tokens 
	 * @param integer $i 
	 */
	private function skip_whitespace($tokens, &$i) {
		$i++;
		while (is_array($tokens[$i]) && $tokens[$i][0] == T_WHITESPACE) {
			$i++;
		}
	}


	/**
	 * Moves the pointer, $i, past all prior whitespace tokens. 
	 *
	 * @param array $tokens
	 * @param integer $i
	 */
	private function skip_whitespace_reverse($tokens, &$i) {
		$i--;
		while (is_array($tokens[$i]) && $tokens[$i][0] == T_WHITESPACE) {
			$i--;
		}
	}


	/**
	 * Skips previous tokens based on a given pattern. 
	 *
	 * @param array $pattern
	 * @param array $tokens
	 * @param integer $i
	 * @return boolean
	 */
	private function skip_reverse($pattern, $tokens, &$i) {
		$orig_i = $i;
		$i--;
		$pattern_fail = false;
		$pattern_type = $pattern[0];
		switch ($pattern_type) {
			case self::PATTERN_GROUP:
				$pattern_fail = !$this->skip_reverse_pattern_group($pattern, $tokens, $i);
				break;
			case self::PATTERN_GROUP_OPTIONAL:
				$pattern_fail = !$this->skip_reverse_pattern_optional_group($pattern, $tokens, $i);
				break;
			case self::PATTERN_GROUP_OR:
				$pattern_fail = !$this->skip_reverse_pattern_or_group($pattern, $tokens, $i);
				break;
		}


		if ($pattern_fail) {
			$i = $orig_i;
			return false;
		} else {
			$i++;
			return true;
		}
	}


	/**
	 * Skips all tokens or sub patterns that match. Each token MUST match for 
	 * true to be returned. If a token does not match $i will be reset and false
	 * will be returned. 
	 *
	 * @param array $pattern - 
	 * @param array $tokens - 
	 * @param integer $i - 
	 * @return boolean
	 */
	private function skip_reverse_pattern_group($pattern, $tokens, &$i) {
		$orig_i = $i;

		$pattern_fail = false;
		$pattern_count = count($pattern);
		for ($pattern_index = $pattern_count-1; $pattern_index > 0 && !$pattern_fail; $pattern_index--) {
			if (is_array($pattern[$pattern_index])) {
				/* A sub-pattern must be true here */
				$sub_pattern = $pattern[$pattern_index];

				switch ($sub_pattern[0]) {
					case self::PATTERN_GROUP:
						$pattern_fail = !$this->skip_reverse_pattern_group($sub_pattern, $tokens, $i);
						break;
					case self::PATTERN_GROUP_OPTIONAL:
						$pattern_fail = !$this->skip_reverse_pattern_optional_group($sub_pattern, $tokens, $i);
						break;
					case self::PATTERN_GROUP_OR:
						$pattern_fail = !$this->skip_reverse_pattern_or_group($sub_pattern, $tokens, $i);
						break;
					default:
						$pattern_fail = false;
						break;
				}
			} else if (is_array($tokens[$i]) && $tokens[$i][0] != $pattern[$pattern_index]) {
				/* The token did not match the pattern, fail! */
				$pattern_fail = true;
			} else if (!is_array($tokens[$i]) && $tokens[$i] != $pattern[$pattern_index]) {
				$pattern_fail = true;
			} else {
				$i--;
			}
		}

		if ($pattern_fail) {
			$i = $orig_i;
			return false;
		}

		return true;
	}


	/**
	 * Skips a pattern of tokens if the token stream matches but if it does not 
	 * $i will be reset and true will still be returned. 
	 *
	 * @param array $pattern
	 * @param array $tokens
	 * @param integer $i
	 * @return boolean
	 */
	private function skip_reverse_pattern_optional_group($pattern, $tokens, &$i) {
		$orig_i = $i;

		$pattern_fail = false;
		$pattern_count = count($pattern);
		for ($pattern_index = $pattern_count-1; $pattern_index > 0 && !$pattern_fail; $pattern_index--) {
			if (is_array($pattern[$pattern_index])) {
				/* A sub-pattern must be true here */
				$sub_pattern = $pattern[$pattern_index];

				switch ($sub_pattern[0]) {
					case self::PATTERN_GROUP:
						$pattern_fail = !$this->skip_reverse_pattern_group($sub_pattern, $tokens, $i);
						break;
					case self::PATTERN_GROUP_OPTIONAL:
						$pattern_fail = !$this->skip_reverse_pattern_optional_group($sub_pattern, $tokens, $i);
						break;
					case self::PATTERN_GROUP_OR:
						$pattern_fail = !$this->skip_reverse_pattern_or_group($sub_pattern, $tokens, $i);
						break;
					default:
						$pattern_fail = false;
						break;
				}
			} else if (is_array($tokens[$i]) && $tokens[$i][0] != $pattern[$pattern_index]) {
				/* The token did not match the pattern, fail! */
				$pattern_fail = true;
			} else if (!is_array($tokens[$i]) && $tokens[$i] != $pattern[$pattern_index]) {
				$pattern_fail = true;
			} else {
				
				$i--;
			}
		}

		if ($pattern_fail) {
			$i = $orig_i;
		}

		return true;
	}


	/**
	 * Skips only one token. The pattern is a list of possible tokens that could
	 * fill a specific spot. Once that token has been found the pattern returns
	 * true. If the token does not match one listed in the pattern $i is reset
	 * and false is returned. 
	 *
	 * 
	 */
	private function skip_reverse_pattern_or_group($pattern, $tokens, &$i) {
		//echo("\tskip_reverse_pattern_or_group(<pattern>, <tokens>, ".$i.")\n");

		$pattern_count = count($pattern);
		for ($pattern_index = 1; $pattern_index < $pattern_count; $pattern_index++) {
			if (is_array($tokens[$i]) && $tokens[$i][0] == $pattern[$pattern_index]) {
				/* A match was found! */
				$i--;
				return true;
			} else if (!is_array($tokens[$i]) && $tokens[$i] == $pattern[$pattern_index]) {
				/* A match was found! */
				$i--;
				return true;
			}
		}

		return false;
	}


	/**
	 * A handy little debug function for printing out an array of tokens. 
	 *
	 * @param array $tokens - An array of tokens to print
	 */
	private function debug_dump_tokens($tokens) {
		$c = count($tokens);
		for ($i = 0; $i < $c; ++$i) {
			if (is_array($tokens[$i])) {
				echo("[".$i."] ".token_name($tokens[$i][0])."\n");
			} else {
				echo("[".$i."] ".$tokens[$i]."\n");
			}
		}
	}
}



/**
 * Command line interface for the doc-comment checker. 
 *
 * @author Graham Kelly <graham@graha.mk>
 * @package doccomment_checker
 */
class doc_comment_checker_cli extends doc_comment_checker {
	/**
	 * @var integer $error_count - Tracks the number of errors that occured. 
	 */
	private $error_count = 0;


	/**
	 * @var array $files - An array of the files or directories to check. 
	 */
	private $files = array();


	/**
	 * Runs the doc-comment checker based on command line arguments. 
	 *
	 */
	public function __construct() {
		$this->process_command_line_args();

		$c = count($this->files);

		/* Check that files exist */
		for ($i = 0; $i < $c; ++$i) {
			if (!file_exists($this->files[$i])) {
				/* Error */
				echo("Error: File or directory `".$this->files[$i]."` does not exist.\n");
			}
		}


		/* Check the files for doc-comment errors */
		for ($i = 0; $i < $c; ++$i) {
			if (file_exists($this->files[$i])) {
				if (is_dir($this->files[$i])) {
					$this->check_dir($this->files[$i]);
				} else {
					$this->check_file($this->files[$i]);
				}
			}
		}


		echo("Missing:\n");
		echo("\tFile doc-comments: ".$this->missing_file_comment_errors."\n");
		echo("\tClass doc-comments: ".$this->missing_class_comment_errors."\n");
		echo("\tInterface doc-comments: ".$this->missing_interface_comment_errors."\n");
		echo("\tFunction doc-comments: ".$this->missing_function_comment_errors."\n");
		echo("\tClass constant doc-comments: ".$this->missing_class_const_comment_errors."\n");
		echo("\tClass variable doc-comments: ".$this->missing_class_var_comment_errors."\n");
		echo("\tMissing Doc-comments: ".$this->error_count."\n");
	}


	

	/**
	 * Parses all the command line arguments and sets the approriate settings in
	 * doc_comment_checker. 
	 *
	 */
	private function process_command_line_args() {
		$longargs = array(
			'report-file-level',
			'noreport-file-level',
			'report-class-level',
			'noreport-class-level',
			'report-interface-level',
			'noreport-interface-level',
			'report-class-var-level',
			'noreport-class-var-level',
			'report-class-const-level',
			'noreport-class-const-level',
			'report-constant-level',
			'noreport-constant-level',
			'report-function-level',
			'noreport-function-level',
			'report-all',
			'report-none',
		);


		$options = getopt('hcf:', $longargs);
		$require_f = true;

		if (isset($options['report-none'])) {
			$this->report_missing_file_comments        = false;
			$this->report_missing_class_comments       = false;
			$this->report_missing_interface_comments   = false;
			$this->report_missing_class_var_comments   = false;
			$this->report_missing_class_const_comments = false;
			$this->report_missing_constant_comments    = false;
			$this->report_missing_function_comments    = false;
		}

		$this->process_report_noreport_option($options, 'file-level', $this->report_missing_file_comments);
		$this->process_report_noreport_option($options, 'class-level', $this->report_missing_class_comments);
		$this->process_report_noreport_option($options, 'interface-level', $this->report_missing_interface_comments);
		$this->process_report_noreport_option($options, 'class-var-level', $this->report_missing_class_var_comments);
		$this->process_report_noreport_option($options, 'class-const-level', $this->report_missing_class_const_comments);
		$this->process_report_noreport_option($options, 'constant-level', $this->report_missing_constant_comments);
		$this->process_report_noreport_option($options, 'function-level', $this->report_missing_function_comments);


		if (isset($options['report-all'])) {
			$this->report_missing_file_comments        = true;
			$this->report_missing_class_comments       = true;
			$this->report_missing_interface_comments   = true;
			$this->report_missing_class_var_comments   = true;
			$this->report_missing_class_const_comments = true;
			$this->report_missing_constant_comments    = true;
			$this->report_missing_function_comments    = true;
		}

		

		if (isset($options['c']) || isset($options['print-config'])) {
			$this->print_config();
			$require_f = false;	
		}

		if (isset($options['h']) || isset($options['help'])) {
			$this->print_usage();
			$require_f = false;
		}

		if (isset($options['f']) || isset($options['file'])) {
			if (isset($options['f'])) {
				if (is_array($options['f'])) {
					$this->files = $options['f'];
				} else {
					$this->files = array(
						$options['f'],
					);
				}
			}

			if (isset($options['file'])) {
				if (is_array($options['file'])) {
					$this->files = $options['file'];
				} else {
					$this->files = array(
						$options['file'],
					);
				}
			}
		} else if ($require_f && !isset($options['f'])) {
			echo("Error: No input file was given. Use -f to specify a file or directory to check.");
			exit();
		}
	}


	/**
	 * Handles a set of report-x and noreport-x command line arguments. 
	 *
	 * @param array $options The array of options returnecd by getopt().
	 * @param string $name The command line argument name (minus the `report-` 
	 *                     or `noreport-` prefix). 
	 * @param boolean $config_var The configuration variable to set.
	 *
	 */
	private function process_report_noreport_option($options, $name, &$config_var) {
		if (isset($options['noreport-'.$name])) {
			$config_var = false;
		} else if (isset($options['report-'.$name])) {
			$config_var = true;
		}
	}


	/**
	 * Prints the help information for the CLI version of the program.
	 */
	private function print_usage() {
		echo("doccomment_checker.php [options]\n");
		echo("\t-c           Print Config\n");
		echo("\t-f <file>    Check <file> for missing doc-comments or all files in\n");
		echo("\t             <file> if <file> is a directory\n");
		echo("\t-h           Help\n");
		
		exit();
	}


	/**
	 * Prints the configuration options used to report errors. 
	 */
	private function print_config() {
		echo("Config\n");
		$this->print_config_item($this->report_missing_file_comments, 'File Comments');
		$this->print_config_item($this->report_missing_class_comments, 'Class Comments');
		$this->print_config_item($this->report_missing_interface_comments, 'Interface Comments');
		$this->print_config_item($this->report_missing_class_var_comments, 'Class Variable Comments');
		$this->print_config_item($this->report_missing_class_const_comments, 'Class Constant Comments');
		$this->print_config_item($this->report_missing_constant_comments, 'Constant Comments');
		$this->print_config_item($this->report_missing_function_comments, 'Function Comments');		
	}


	/**
	 * Used by print_config() to print the value of a specific option. 
	 *
	 * @param bool $value
	 * @param string $name
	 */
	private function print_config_item($value, $name) {
		$val = 'TRUE';
		if (!$value) {
			$val = 'FALSE';
		}

		echo("\t".$name.': '.$val."\n");
	}


	/**
	 * The CLI version of report_error(). Simply print the error and incrament 
	 * the error_counter. 
	 *
	 * @param string $file
	 * @param integer $line
	 * @param string $message
	 */
	protected function report_error($file, $line, $message) {
		$this->error_count++;
		echo($file.':'.$line.': '.$message."\n");
	}
}


$DocCommentCheckerCLI = new doc_comment_checker_cli();
?>
