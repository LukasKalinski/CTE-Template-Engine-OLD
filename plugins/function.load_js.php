<?php
require_once('lib/lib.jsparsing.php');

// Setup output paths if running first time:
if(!is_dir(PATH_SYS__JSC_OUTPUT_ROOT))
{
  require_once('function.create_dir.php');
  CYCOM__create_dir(PATH_SYS__JSC_OUTPUT_ROOT, 0740);
}

/**
 * [CTE Function]
 *
 * string load_js(CTE, array, bool)
 * Loads javascript files into a file or return their contents.
 *
 * Modes:     force eval
 * Arguments: @param bool     scramble          # True will scramble the javascript-code, false will not. (true)
 *            @param string   scramble_level    # Level of scrambling; low=remove comments, medium=remove whitespaces, high=rename functions and variables.
 *
 * @param string $scope
 * @param mixed[] $args
 */
function CTEPL__load_js($scope, $args)
{
  if($scope != CTE::SYSDS_KEY_STATIC)
    CTE::handle_error('Plugin load_js can only be called in force-eval mode.', ERR_USER_ERROR);
  
  $result = null;
  $main_file_name = CTE::get_env('base_tpl_file');
  $main_file_name = substr($main_file_name, 0, strrpos($main_file_name, '.', 1)) . '.js';
  
  extract($args, EXTR_PREFIX_ALL|EXTR_REFS, 'arg');
  
  // Set optional arguments if not set:
  if(!isset($arg_scramble)) $arg_scramble = true;
  else                      $arg_scramble = (bool) $arg_scramble;
  if(!isset($arg_scramble_level)) $arg_scramble_level = 'medium';
  
  // Initiate paths, containers, etc.
  $js_main_path    = PATH_SYS__JSC_LIB_ROOT . 'local/';
  $contents = '';
  
  // Check that we have a main file:
  if(!file_exists(PATH_SYS__JSC_LIB_ROOT.'local/'.$main_file_name))
    CTE::handle_error('Plugin load_js: missing base file: '.$main_file_name, ERR_USER_ERROR);
  
  $JS = new JS_Manager(PATH_SYS__JSC_LIB_ROOT.'local/'.$main_file_name, PATH_SYS__JSC_LIB_ROOT.'common/');
  
  // Set auto-includes:
  $auto_incs = array('__base.js');
  if(CTE::has_devmode(CTE_DEVMODE_TPL))
    array_push($auto_incs, '__debug.js');
  
  // Import auto-includes:
  for($i=0, $ii=count($auto_incs); $i<$ii; $i++)
    $JS->import_file(PATH_SYS__JSC_LIB_ROOT . $auto_incs[$i], IMPORT_START);
  
  // Fetch contents:
  if($arg_scramble)
  {
    if($arg_scramble_level == 'low')
    {
      $contents = $JS->get_prepared_contents(JS_SCRAM_LEVEL_LOW);
    }
    elseif($arg_scramble_level == 'medium')
    {
      $contents = $JS->get_prepared_contents(JS_SCRAM_LEVEL_MEDIUM);
    }
    elseif($arg_scramble_level == 'high')
    {
      $contents = $JS->get_prepared_contents(JS_SCRAM_LEVEL_HIGH);
      CTE::set_sysds_plugin_entry('load_js', 'scram', $JS->get_scram_name_links(), CTE::SYSDS_KEY_STATIC); // Register scrambled names.
    }
    else
    {
      CTE::handle_error('Unknown scramble level: '.$arg_scramble_level, ERR_USER_NOTICE);
    }
  }
  else
  {
    $contents = $JS->get_prepared_contents(JS_SCRAM_LEVEL_NONE);
  }
  
  // Remove all debugging-related function calls. Fix this to fetch relevant function names from file instead...
//  if(!CTE::has_devmode(CTE_DEVMODE_TPL))
//    $contents = preg_replace('/__DEB_[A-Z0-9_]+(.*?);/', '', $contents); // ## MOVE ME TO JS_Manager class............................
  
  // Generate filename:
  $filename = $JS->get_suggested_filename(true);
  
  $fs_file_path = PATH_SYS__JSC_OUTPUT_ROOT . $filename;
  
  // Build file.
  if($handle = @fopen($fs_file_path, 'w'))
  {
    fwrite($handle, $contents);
    fclose($handle);
    
    return PATH_WWW__JSC_OUTPUT_ROOT.$filename;
  }
  else
  {
    CTE::handle_error('Failed to open file '.$fs_file_path.' at line '.__LINE__.' in file '.__FILE__.'.', ERR_SYSTEM_WARNING);
    return '';
  }
}
?>
