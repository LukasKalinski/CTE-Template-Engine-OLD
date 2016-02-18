<?php
require_once('system/lib.error.php');

define('IMPORT_START', 1);
define('IMPORT_END', 2);
define('IMPORT_BASE_FILE', 3);
define('JS_SCRAM_SKIPNAME_DATA_FILE', 'data.jsskipnames.txt');
define('JS_SCRAM_LEVEL_NONE', 0);
define('JS_SCRAM_LEVEL_LOW', 1); // Remove comments.
define('JS_SCRAM_LEVEL_MEDIUM', 2); // Remove comments and spaces/line breaks.
define('JS_SCRAM_LEVEL_HIGH', 3); // Remove comments, spaces/line breaks and replace variable and function names.

class JS_Scrambler
{
  const SCRAM_CONTEXT_GLO = 1;
  const SCRAM_CONTEXT_OBJ = 2;
  const DEBUG = false;
  
  private $re;
  private $scram_prefix = 'x';
  private $scram_skip_prefix = '__';
  private $scram_index = 0;
  private $name_links;
  private $skip_names = array();
  private $skipped_by_prefix = array();
  private $dquote_stack = array();
  
  public function __construct()
  {
    $this->name_links = array();
    
    $skip_names = preg_split('/\s/', file_get_contents(JS_SCRAM_SKIPNAME_DATA_FILE, true), null, PREG_SPLIT_NO_EMPTY);
    
    if($skip_names[0] != '@issorted') // Sort file if not sorted.
    {
      $skip_names = array_unique($skip_names);
      sort($skip_names);
      $f = fopen(JS_SCRAM_SKIPNAME_DATA_FILE, 'w', true);
      fwrite($f, "@issorted\n".trim(implode("\n", $skip_names)));
      fclose($f);
    }
    else // Remove @issorted entry.
    {
      array_shift($skip_names);
    }
    
    $this->skip_names = array_combine($skip_names, array_pad(array(), count($skip_names), true));
    $this->re['squoted_string'] = '(?:\'.*?(?<!\\\)\')';
    $this->re['dquoted_string'] = '(?:\".*?(?<!\\\)\")';
    $this->re['regexp'] = '(?:\/.*?(?<!\\\)\/[ig]{0,2}(?:;|,|\)|\}))';
    $this->re['obj_name'] = '(?:[a-zA-Z_][a-zA-Z0-9_]*)';
  }
  
  /**
   * @param string $message
   * @param integer $line
   * @param string $err_type
   * @return void
   */
  private function trigger_error($message, $line, $err_type)
  {
    CYCOM__trigger_error('<b># class JS_Scrambler:</b><br />'.$message.'<br />'.
                         'On line '.$line.' in file '.basename(__FILE__), __FILE__, $line, $err_type, false, true);
  }
  
  /**
   * @desc Registers name to skip.
   * @return void
   */
  public function reg_skipname($name)
  {
    $this->skip_names[$name] = true;
  }
  
  /**
   * @return string[]
   */
  public function get_name_links()
  {
    return $this->name_links;
  }
  
  /**
   * @param string $name
   * @param int $context
   * @return void
   */
  private function register_scrambled_name($name)
  {
    if(!key_exists($name, $this->name_links)) // Make sure we don't overwrite the first name link.
    {
      if(!key_exists($name, $this->skip_names))
      {
        if(substr($name, 0, strlen($this->scram_skip_prefix)) != $this->scram_skip_prefix)
        {
          $scrambled_name = $this->scram_prefix.dechex($this->scram_index++);
          $this->name_links[$name] = $scrambled_name;
        }
        else
        {
          $this->skipped_by_prefix[$name] = true;
        }
      }
      else
      {
        $this->name_links[$name] = $name;
      }
    }
  }
  
  /**
   * @desc Stores found double quote strings for parsing later on.
   * @param &string $token
   * @return void
   */
  private function push_dquote_to_stack(&$token)
  {
    array_push($this->dquote_stack, &$token);
  }
  
  /**
   * @desc Parses function arguments (in $toks from $i) and registers them for scrambling. The first index shold point at a '('.
   * @param &string[] $toks
   * @param &int $i
   * @return void
   */
  private function parse_func_args(&$toks, &$i)
  {
    if($toks[$i] != '(')
      $this->trigger_error('Missing ( after function.', __LINE__, ERR_USER_ERROR);
    
    $i++; // ([?]
    while($toks[$i] != ')')
    {
      if($toks[$i] != ',');
        if(preg_match('/^'.$this->re['obj_name'].'$/', $toks[$i]))
          $this->register_scrambled_name($toks[$i]);
      $i++;
    }
  }
  
  /**
   * @return string
   */
  public function scramble($str, $level, $dquote_parse=false)
  {
    // Remove comments:
    $str = preg_replace('/\/\/.*/', '', $str);         // Single line. (//)
    $str = preg_replace('/\/\*.*?\*\//sm', '', $str);  // Multi line. (/**/)
    
    // ## QUIT: LEVEL LOW
    if($level == JS_SCRAM_LEVEL_LOW)
      return $str;
    
    $toks = preg_split('/('.$this->re['dquoted_string'].'|'.
                            $this->re['squoted_string'].'|'.
                            $this->re['obj_name'].'|'.
                            $this->re['regexp'].'|;|[^a-zA-Z0-9_])/',
                       $str, null, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    
    $toks_len = count($toks);
    
    // Trim whitespaces:
    $trim_toks = array();
    for($i=0; $i<$toks_len; $i++)
    {
      // Skip for single quoted strings and free regexps:
      if(!preg_match('/^'.$this->re['squoted_string'].'$/', $toks[$i]) &&
         !preg_match('/^'.$this->re['regexp'].'$/', $toks[$i]) &&
         !preg_match('/^'.$this->re['dquoted_string'].'$/', $toks[$i]))
      {
        switch($toks[$i])
        {
          case 'in':
            $toks[$i] = ' '.$toks[$i];
          case 'new':
          case 'case':
          case 'return':
            $toks[$i] = $toks[$i].' ';
            break;
          case 'break':
          case 'continue':
            if($toks[$i+1] != ';')
              $toks[$i] = $toks[$i].' ';
            break;
          case 'function':
            if(trim($toks[$i+1]) != '(')
              $toks[$i] = $toks[$i].' '; // Add whitespace for functions (so we don't merge them with other alpha-strings).
            break;
          case 'var':
            $toks[$i] = $toks[$i].' ';
            break;
          case 'else':
            if(trim($toks[$i+1]) != '{')
            {
              $toks[$i] = $toks[$i].' '; // Add whitespace for bracketless else-statements (so we don't merge them with other alpha-strings).
              break;
            }
          default:
            $toks[$i] = preg_replace('/\s/', '', $toks[$i]);
            if(strlen($toks[$i]) == 0)
              $toks[$i] = false;
        }
      }
      if($toks[$i] !== false)
        array_push($trim_toks, $toks[$i]);
    }
    $toks = $trim_toks;
    $toks_len = count($toks);
    unset($trim_toks);
    
    // ## QUIT: LEVEL MEDIUM
    if($level == JS_SCRAM_LEVEL_MEDIUM)
      return implode('', $toks);
    
    // Parse tokens for variable and function declarations:
    for($i=0; $i<$toks_len; $i++)
    {
      // Do recursive parse on double quoted strings:
      if(!$dquote_parse && preg_match('/^'.$this->re['dquoted_string'].'$/', $toks[$i]))
      {
        $this->push_dquote_to_stack($toks[$i]);
      }
      else
      {
        switch(trim($toks[$i]))
        {
          case 'var';
            $init_i = $i;
            $i++; // var [?]
            $open_parenthesis = 0;
            while($toks[$i] != ';')
            {
              switch($toks[$i])
              {
                case '(':
                  $open_parenthesis++;
                  break;
                case ')':
                  $open_parenthesis--;
                  break;
                default:
                  // Check if we have a valid variable name declaration.
                  if(($i-1 == $init_i || $toks[$i-1] == ',') && $open_parenthesis == 0 && preg_match('/^'.$this->re['obj_name'].'$/', $toks[$i]))
                  // * If we're in the beggining or are preceeded by a coma and we're not in any parenthesises and have a regexp-match for the variable name:
                    $this->register_scrambled_name($toks[$i]);
              }
              $i++;
            }
            continue 2;
          case 'function':
            if($i == 0 || $toks[$i-1] != '=') // Global function.
            {
              $this->register_scrambled_name($toks[$i+1]);
              $this->parse_func_args($toks, $i+=2);
            }
            else // Object function.
            {
              $this->parse_func_args($toks, $i+=1);
            }
            continue 2;
          case 'this':
            $i++; // this[?]
            if($i == 0 || $toks[$i] == '.')
            {
              $i++; // this.[?]
              $obj_name = $toks[$i];
              $i++; // this.name[?]
              if(substr($toks[$i],0,1) == '=') // We have a object assignment.
                $this->register_scrambled_name($obj_name);
            }
            continue 2;
        }
      }
    }
    
    // Do the name replacement:
    $skip_next_static_prop = false; // Skip properties like prop.foo, but not prop[foo].
    for($i=0; $i<$toks_len; $i++)
    {
      if(preg_match('/^'.$this->re['obj_name'].'$/', $toks[$i]))
      {
        if(!key_exists($toks[$i], $this->skip_names) && key_exists($toks[$i], $this->name_links))
          $toks[$i] = $this->name_links[$toks[$i]];
      }
      elseif(self::DEBUG && $toks[$i] == ';' && !$dquote_parse)
      {
        $toks[$i] .= "\n";
      }
    }
    
    // Scramble ""-quoted strings:
    if(!$dquote_parse)
      for($i=0, $ii=count($this->dquote_stack); $i<$ii; $i++)
        $this->dquote_stack[$i] = '"' . $this->scramble(trim($this->dquote_stack[$i], '"'), $level, true) . '"';
    
    if(!$dquote_parse)
    {
//      header('Content-type:text/plain');
//      print_r($toks);
//      echo implode($toks);
//      print_r($this->name_links);
//      echo str_replace(';', ";\n", implode($toks));
//      exit('#exit');
    }
    return implode('', $toks);
  }
}

/**
 * Class JS_Manager
 */
class JS_Manager
{
  const INSTR_PREFIX = '@';
  const INSTR_START = '@{';
  const INSTR_INCLUDE = 'include';
  const INSTR_SKIP_SCRAM = 'scramble_skip_name';
  const INSTR_END = '@}';
  
  private $contents = '';
  private $base_file = null;
  private $included_files = array();
  private $inc_file_path = null;
  private $SCRAM = null;              // @var JS_Scrambler
  
  /**
   * @desc Constructor does nothing right now.
   * @param string $base_file
   * @param string $inc_file_path
   */
  public function __construct($base_file, $inc_file_path)
  {
    $this->SCRAM = new JS_Scrambler();
    $this->base_file = $base_file;
    $this->inc_file_path = $inc_file_path;
    $this->import_file($this->base_file, IMPORT_BASE_FILE);
  }
  
  /**
   * @param string $message
   * @param integer $line
   * @param string $err_type
   * @return void
   */
  private function trigger_error($message, $line, $err_type)
  {
    CYCOM__trigger_error('Error in class JS_Manager: '.$message.' on line '.$line.' in file '.basename(__FILE__), __FILE__, $line, $err_type, false, true);
  }
  
  /**
   * @return assoc_array
   */
  public function get_scram_name_links()
  {
    return $this->SCRAM->get_name_links();
  }
  
  /**
   * @param string $filepath
   * @return string
   */
  private function get_file_contents($file_path)
  {
    if(!file_exists($file_path))
      $this->trigger_error('Failed to open file '.$file_path, __LINE__, ERR_USER_ERROR);
    
    return file_get_contents($file_path)."\n";
  }
  
  /**
   * @desc Reads, performs and removes instructions from $_str.
   * @param string[] &$str
   * @return void
   */
  private function read_instructions(&$_str)
  {
    if(substr($_str, 0, strlen(self::INSTR_START)) == self::INSTR_START)
    {
      $instr_end_pos = strpos($_str, self::INSTR_END);
      
      if($instr_end_pos === false)
        $this->trigger_error('Missing instructions-end in file '.$file_name, __LINE__, ERR_USER_ERROR);
      
      $instrs = substr($_str, strlen(self::INSTR_START), $instr_end_pos-strlen(self::INSTR_END));
      $instrs = explode('@', $instrs);
      
      $_str = substr($_str, $instr_end_pos+strlen(self::INSTR_END));
      
      $instr = null;
      $param = null;
      $tmp = null;
      $inc_contents = '';
      for($i=count($instrs)-1; $i>=0; $i--)
      {
        $instrs[$i] = trim($instrs[$i]);
        if(empty($instrs[$i]))
          continue;
        
        $tmp = explode(' ', $instrs[$i]);
        $instr = $tmp[0];
        $param = trim($tmp[1], '"');
        
        switch($instr)
        {
          case self::INSTR_INCLUDE:
            if(!in_array($param, $this->included_files))
            {
              array_push($this->included_files, $param);
              $inc_contents = $this->get_file_contents($this->inc_file_path.$param);
              $this->read_instructions($inc_contents);
              $_str = $inc_contents . $_str;
            }
            break;
          case self::INSTR_SKIP_SCRAM:
            $this->SCRAM->reg_skipname($param);
            break;
          default:
            $this->trigger_error('Unknown instruction: '.$instr, __LINE__, ERR_USER_ERROR);
        }
      }
    }
  }
  
  /**
   * @desc Imports content from specified file.
   * @param string $file_path
   * @param int $type
   * @return void
   */
  public function import_file($file_path, $type=IMPORT_END)
  {
    switch($type)
    {
      case IMPORT_END: // Place contents at the end of the file.
        $this->contents .= $this->get_file_contents($file_path);
        break;
      case IMPORT_START: // Place contents at the start of the file.
        $this->contents = $this->get_file_contents($file_path) . $this->contents;
        break;
      case IMPORT_BASE_FILE: // Import base file.
        $this->contents = $this->get_file_contents($file_path) . $this->contents;
        break;
      default: // Unknown type...
        $this->trigger_error('Unknown import type.', __LINE__, ERR_SYSTEM_ERROR);
    }
    
    $this->read_instructions($this->contents);
  }
  
  /**
   * @desc Generates a filename (with extension if $ext==true) and returns it.
   * @param bool $ext
   */
  public function get_suggested_filename($ext=false)
  {
    sort($this->included_files);
    return md5(implode(';', $this->included_files).';'.$this->base_file) . ($ext ? '.js' : '');
  }
  
  /**
   * @desc Performs a full scramble on available content and returns it.
   * @param bool $full
   * @return string
   */
  public function get_prepared_contents($scramble_level=JS_SCRAM_LEVEL_NONE)
  {
    if($scramble_level == JS_SCRAM_LEVEL_NONE)
    {
      return $this->contents;
    }
    else
    {
      return $this->SCRAM->scramble($this->contents, $scramble_level);
    }
  }
}
?>