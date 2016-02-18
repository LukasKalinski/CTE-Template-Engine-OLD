<?php
require_once('system/env.globals.php');

class CSS_Loader
{
  const BASE_FILE_NAME = '__base';
  const DEV_FILE_NAME = '__dev';
  
  private $common_file_rpath;
  private $main_file_rpath;
  private $c_static = ''; // Static content
  private $c_dynamic = ''; // Dynamic content
  
  /**
   * @param string $main_file_rpath   # Relative path to main file.
   */
  public function __construct($tpl_file_rpath)
  {
    // Build main_file-name:
    $this->main_file_rpath = substr($tpl_file_rpath, 0, strrpos($tpl_file_rpath, '.')) . '.css';
    
    // Build common file name (common means that the file is common inside a sub-group; ie: user, config etc):
    $this->common_file_rpath = substr($tpl_file_rpath, 0, strpos($tpl_file_rpath, '.')) . '.css';
  }
  
  /**
   * @param string $message
   * @param integer $line
   * @param string $err_type
   * @return void/EXIT
   */
  private function trigger_error($message, $line, $err_type)
  {
    CYCOM__trigger_error('<b># class CSS_Loader:</b><br />'.$message.'<br />'.
                         'On line '.$line.' in file '.basename(__FILE__), __FILE__, $line, $err_type, false, true);
  }
  
  /**
   * @param string[] $includes
   * @param bool $load_dev
   * @return void
   */
  public function static_load($includes, $load_dev=false)
  {
    $dir_root = PATH_SYS__CSS_LIB_ROOT.'static/';
    $this->c_static = file_get_contents($dir_root.self::BASE_FILE_NAME.'.css');
    
    if($load_dev)
      $this->c_static .= file_get_contents($dir_root.self::DEV_FILE_NAME.'.css');
    
    for($i=0, $ii=count($includes); $i<$ii; $i++)
    {
      $include_file_path = $dir_root.'global/'.$includes[$i];
      
      if(file_exists($include_file_path))
        $this->c_static .= file_get_contents($include_file_path);
      else
        $this->trigger_error('Missing requested static style-file: '.$include_file_path.'.', __LINE__, ERR_USER_ERROR);
    }
    
    // Load static common file if found:
    $common_file_path = $dir_root.'local/'.$this->common_file_rpath;
    if(file_exists($common_file_path))
      $this->c_static .= file_get_contents($common_file_path);
    
    // Load static main file if found:
    $main_file_path = $dir_root.'local/'.$this->main_file_rpath;
    if(file_exists($main_file_path))
      $this->c_static .= file_get_contents($main_file_path);
  }
  
  /**
   * @desc Gets rid of old dynamic content.
   * @return void
   */
  public function clear_dynamic_content()
  {
    $this->c_dynamic = '';
  }
  
  /**
   * @param string $theme_id
   * @param string[] @includes
   * @param bool $load_dev
   * @return void
   */
  public function dynamic_load($theme_id, $includes, $load_dev=false)
  {
    if(!empty($this->c_dynamic))
      $this->trigger_error('Cannot load dynamic content again; old content still exists.', __LINE__, ERR_USER_ERROR);
    
    $dir_root = PATH_SYS__CSS_LIB_ROOT.'dynamic/T'.$theme_id.'/';
    
    $this->c_dynamic = file_get_contents($dir_root.self::BASE_FILE_NAME.'.css');
    
    if($load_dev)
      $this->c_dynamic .= file_get_contents($dir_root.self::DEV_FILE_NAME.'.css');
    
    for($i=0, $ii=count($includes); $i<$ii; $i++)
    {
      $include_file_path = $dir_root.'global/'.$includes[$i];
      
      if(file_exists($include_file_path))
        $this->c_dynamic .= file_get_contents($include_file_path);
      else
        $this->trigger_error('Missing requested dynamic style-file: '.$include_file_path.' for theme '.$theme_id.'.', __LINE__, ERR_USER_ERROR);
    }
    
    // Load dynamic common file if found:
    $common_file_path = $dir_root.'local/'.$this->common_file_rpath;
    if(file_exists($common_file_path))
      $this->c_dynamic .= file_get_contents($common_file_path);
    
    // Load dynamic main file if found:
    $main_file_path = $dir_root.'local/'.$this->main_file_rpath;
    if(file_exists($main_file_path))
      $this->c_dynamic .= file_get_contents($main_file_path);
  }
  
  /**
   * @desc Scrambles, interprets simple expressions and returns array with browser-case dependent content.
   * @param string[] $browsers
   * @param a_array $replace_constants
   * @return string[]
   */
  public function get_prepared_contents($browsers, $replace_constants)
  {
    $contents = $this->c_static.$this->c_dynamic;
    
    // Remove comments:
    $contents = preg_replace('/\/\*.*?\*\//sm', '', $contents);  // Multi line.
    
    // Remove multiple spaces:
    $contents = preg_replace('/\s+/sm', ' ', $contents); // Remove multiple spaces and line breaks.
    $contents = preg_replace('/\s*(\{|\}|;|:|,)\s*/m', '$1', $contents); // Remove spaces and line breaks around ';', '{', '}', ',' and ':'.
    $contents = preg_replace('/;\}/sm', '}', $contents); // Remove unnecessary ';' characters.
    
    // Replace constants.
    foreach($replace_constants as $name => $replacement)
      $contents = str_replace('[#'.$name.']', $replacement, $contents);
    
    // Tokenize browser statements.
    $tokens = preg_split('/(\[(?:.+?))\]/i', $contents, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    
    
    // Initiate "browser dependent contents"-array.
    $browser_detached_contents = array_combine($browsers, array_pad(array(), count($browsers), ''));
    
    // ## Compile browser cases.
    for($token_i=0, $tokens_num=count($tokens); $token_i<$tokens_num; $token_i++)
    {
      // We have an irrelevant match.
      if($tokens[$token_i]{0} != '[')
      {
        foreach($browsers as $browser_id => $browser_name)
          $browser_detached_contents[$browser_name] .= $tokens[$token_i];
        continue;
      }
      
      $tokens[$token_i] = substr($tokens[$token_i], 1); // Note: ] is not part of the match.
      
      $case_tokens = explode('|', $tokens[$token_i]);
      
      
      // ## Loop through browser cases.
      $matched_browsers = array_combine($browsers, array_pad(array(), count($browsers), false));
      for($case_i=0,$cases_num=count($case_tokens); $case_i<$cases_num; $case_i++)
      {
        $case = explode('=', $case_tokens[$case_i]);
        $case_name = &$case[0];
        $case_value = &$case[1];
        
        if(in_array($case_name, $browsers))
        {
          $matched_browsers[$case_name] = true;
          $browser_detached_contents[$case_name] .= $case_value;
          continue;
        }
        
        // We're parsing the last case token.
        if(!key_exists($case_i+1, $case_tokens))
        {
          if($case_name == 'default')
          {
            // Set values for remaining browsers.
            foreach($matched_browsers as $browser => $is_matched)
              if(!$is_matched)
                $browser_detached_contents[$browser] .= $case_value;
            
            break;
          }
          else
          {
            CTE::handle_error('Missing default-case in requested css file(s): <i>'.$tokens[$token_i].'</i>.', ERR_USER_NOTICE);
            break;
          }
        }
        // We're not parsing the last case token and default case is used: ERROR->NOTICE.
        elseif(key_exists($case_i+1, $case_tokens) && $case_name == 'default')
        {
          CTE::handle_error('Misplaced default-case in requested css file(s), expected to be placed at the end.', ERR_USER_NOTICE);
          
          foreach($matched_browsers as $browser => $is_matched)
              if(!$is_matched)
                $browser_detached_contents[$browser] .= '/* load_css(): no value found. */';
        }
      }
    }
    
    return $browser_detached_contents;
  }
}
?>