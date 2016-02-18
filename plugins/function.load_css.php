<?php
require_once('function.parse_ini.php');
require_once('lib/function.get_avail_themes.php');
require_once('lib/lib.loadcss.php');

// Setup output paths if running first time:
if(!is_dir(PATH_SYS__CSS_OUTPUT_ROOT))
{
  require_once('function.create_dir.php');
  CYCOM__create_dir(PATH_SYS__CSS_OUTPUT_ROOT, 0740);
}

/**
 * [CTE Function]
 *
 * string load_css(CTE, array, bool)
 * Generates a css-file tree taking theme and browser into account.
 * Returns filename of the css-package.
 *
 * Content load order:
 *  1. static/__base.css                  # Loads always.
 *  2. static/global/*.css                # Auto-loads if found; corresponding to the request above.
 *  3. static/local/<common_file>.css     # Common file for a specific file group (like 'user' or 'config').
 *  4. static/local/<main_file>.css       # Loads always (that means required).
 *  5. dynamic/__base.css                 # Loads always.
 *  6. dynamic/global/*.css               # Loads one or many files on request.
 *  7. dynamic/local/<common_file>.css    # Loads if found; corresponding to the request above.
 *  8. dynamic/local/<main_file>.css      # Loads if found.
 *
 * Modes:     force eval
 * Arguments: @param string include       Common files to load, separated by ;.
 *            @param bool   scramble      Wether to scramble or not (true).
 *
 * @param mixed[] $args
 */
function CTEPL__load_css($scope, $args)
{
  if($scope != CTE::SYSDS_KEY_STATIC)
    CTE::handle_error('Plugin:load_css can only be called in force-eval mode.', ERR_USER_ERROR);
  
  // Supported browsers.
  $browsers = array('msie', 'gecko');
  $browser_name2id = array('msie' => BROWSER__CASE_MSIE, 'gecko' => BROWSER__CASE_GECKO);
  
  // Extract function arguments.
  extract($args, EXTR_PREFIX_ALL|EXTR_REFS, 'arg');
  
  // Build main_file_name.
  $main_file_rpath = CTE::get_env('base_tpl_file');
  $main_file_rpath = substr($main_file_rpath, 0, strrpos($main_file_rpath, '.', 1)) . '.css';
  
  // Build common file name (this common means that the file is common inside a sub-group; ie: user, config etc).
  $common_file_rpath = substr($main_file_rpath, 0, strpos($main_file_rpath, '.')) . '.css';
  
  // ## Check arguments.
  if(!isset($arg_scramble))
    $arg_scramble = true;
  else
    $arg_scramble = (bool) $arg_scramble;
  if(!isset($arg_load_common))
    $arg_load_common = true;
  else
    $arg_load_common = (bool) $arg_load_common;
  
  if(isset($arg_import))
    $arg_include = $arg_import; // Back compatibility; argument include is deprecated.
  $include_files = (isset($arg_include) && !empty($arg_include) ? explode(';', $arg_include) : array());
  
  // Add extensions if missing:
  for($i=0; $i<count($include_files); $i++)
    if(!preg_match('/\.css$/', $include_files[$i]))
      $include_files[$i] = $include_files[$i].'.css';
  
  /**
   * 1. Sort file-list.
   * 2. Implode file-list.
   * 3. Md5 the imploded file-list and use the output as filename.
   *
   * The purpose of this procedure is to avoid many files with the same content.
   * Ie: file1;file2 and file2;file1 would generate two different filenames.
   */
  sort($include_files); // Necessary (explanation can be found below).
  $filename = implode(';', $include_files);
  $filename = md5($main_file_rpath . ';' . $filename) . '.css';
  
  $CSSL = new CSS_Loader(CTE::get_env('base_tpl_file'));
  $CSSL->static_load($include_files, CTE::has_devmode(CTE_DEVMODE_TPL));
  
  // Get theme data.
  $avail_themes = CTEPL_LIB__get_avail_themes();
  
  // ## Build files.
  foreach($avail_themes as $theme_data)
  {
    $CSSL->dynamic_load($theme_data['id'], $include_files, CTE::has_devmode(CTE_DEVMODE_TPL));
    
    $constant_replacers = array('GFX_ROOT' => PATH_WWW__GFX_ROOT.$theme_data['id'].'/'.$theme_data['hash'].'/', 'GFX_COMMON_ROOT' => PATH_WWW__CSS_OUTPUT_ROOT);
    $result = $CSSL->get_prepared_contents($browsers, $constant_replacers);
    
    // ## ----------------------------
    // ## Compile simple expressions.
    // ## ----------------------------
    
    $output_root = PATH_SYS__CSS_OUTPUT_ROOT.$theme_data['id'].'/';
    
    // Create theme dir if not exist.
    if(!is_dir($output_root))
      mkdir($output_root);
    
    $output_root = $output_root.$theme_data['hash'].'/';
    if(!is_dir($output_root))
      mkdir($output_root);
    
    foreach($result as $browser => $contents)
    {
      // Set output path.
      $theme_output_path = $output_root.$browser_name2id[$browser].'/';
      
      // Create browser dir if not exist.
      if(!is_dir($theme_output_path))
        mkdir($theme_output_path);
      
      // File-system compiled css path.
      $fs_file_path = $theme_output_path . $filename;
      
      if($handle = @fopen($fs_file_path, 'w'))
      {
        fwrite($handle, $contents);
        fclose($handle);
      }
      else
      {
        CTE::handle_error('Failed to open file: '.$fs_file_path.' at line '.__LINE__.' in file '.__FILE__.'.', ERR_SYSTEM_WARNING);
      }
    }
  }
  
  return $filename;
}
?>
