<?php
/**
 * Compiler class for CTE
 *
 * @package CTE
 * @since 2003-12-28
 * @version 2006-06-13
 * @copyright Cylab 2003-2006
 * @author Lukas Kalinski
 */

// ## Include required environmental libs:
require_once('system/lib.error.php');
require_once('class.Lang_Environment.php');
require_once('lib.CTE_process_monitoring.php');

// ## Include required global functions:
require_once('modifier.escape_string.php');
require_once('modifier.unescape_string.php');
require_once('modifier.squote.php');
require_once('modifier.rm_dquotes.php');
require_once('function.parse_ini.php');

// ## Include required core functions:
require_once('cte/engine/core/core.make_array_string.php');
require_once('cte/engine/core/core.wrap_php_code.php');
require_once('cte/engine/core/core.wrap_php_printout.php');


/**
 * CTE_Compiler
 */
class CTE_Compiler__old
{
  /**
   * Environment members
   */
  // ## Regexps, names, symbols etc:
  private static $operator   = null;  // @var string[] - Tag operators container.
  private static $ifunction  = null;  // @var string[] - Integrated function name container.
  private static $exprparam  = null;  // @var string[] - Expression parameter name container.
  private static $re         = null;  // @var string[] - Regular expressions container.
  
  // ## System related:
  private static $used_plugins = array();  // @var mixed[]   - Array containing used plugins: array([plugin] => [do_include]).
  private static $has_error = false;
  
  private static $additional_php_procedures = ''; // @var string    - Additional content to add in the beggining of the compiled template file.
  
  // ## Compiler related:
  private static $base_tpl_name       = null;  // @var string   - Name of main template file.
  private static $plugin_cfg_path     = null;  // @var string   - Location of plugin config file.
  private static $plugins_cfg         = null;  // @var mixed[]  - Plugin config container.
  private static $lang                = null;  // @var string[] - Language data container.
  
  
  /**
   * Template process monitoring members
   * Monitiors current template operation.
   */
  private static $process_id_stack = array(); // @var string[]           - Stores ID's of templates being processed.
  
  /**
  * @var CTE_TPL_Process[] # Stores parent template processes.
  */
  private static $PROCESS_STACK = array();
  
  /**
  * @var CTE_TPL_Process # Current template process object (must never remain null during compilation).
  */
  private static $PROCESS = null;
  
  private function __construct() {} // Disallow instances.
  
  /**
   * @desc Initiates CTE_Compiler.
   */
  public static function __init()
  {
    // Setup language environment:
    self::$lang = new Lang_Environment(CTE::get_env('cte_root').CTE::get_env('lang_rpath'),
                                       CTE::get_env('default_lang'),
                                       CTE::get_env('current_lang')); /**
    * @todo LANG: adjust to database support */
    self::$lang->set_no_entry_wrapper(CTE::get_env('lang_missing_entry_wrapper'));
    
    // Setup operators:
    self::$operator['constant_prefix']          = '#';
    self::$operator['variable_prefix']          = '$';
    self::$operator['variable_skey_prefix']     = '.';
    self::$operator['block_end']                = '/';
    self::$operator['modifier_prefix']          = '|';
    self::$operator['modifier_arg_prefix']      = ':';
    self::$operator['expr_param_postfix']       = '@';
    self::$operator['placeholder_prefix']       = '&';
    
    
    // Setup expression parameters:
    self::$exprparam['force_eval']    = 'eval';
    self::$exprparam['ignore_unset']  = 'nreq';
    
    
    // Setup integrated functions:
    self::$ifunction['subtemplate']           = 'subtemplate';
    self::$ifunction['register_placeholder']  = 'phreg';
    
    
    // Setup regular expression patterns:

    self::$re['linebreak'] = '(?:\n|\r)';
    
    /**
     * Double quoted string
     * Double-quote escaping is done with \.
     */
    self::$re['dquoted_string'] = '(?:".*?(?<!\\\)")';
    
    /**
     * Boolean true
     */
    self::$re['boolean_true'] = '(?:true|1|yes)';
    
    /**
     * Boolean false
     */
    self::$re['boolean_false'] = '(?:false|0|no)';
    
    /**
     * Boolean
     * Datatype: boolean - true/1 or false/0.
     */
    self::$re['boolean'] = '(?:'.self::$re['boolean_true'].'|'.self::$re['boolean_false'].')';
    
    /**
     * Template tag
     */
    self::$re['template_tag'] = '(?:'.
                                  preg_quote(CTE::get_env('left_delim'), '/').
                                    '(?:'.
                                      '(?:\\\['.                                              // Containing escaped delimiters and/or...
                                        preg_quote(CTE::get_env('left_delim'), '/').
                                        preg_quote(CTE::get_env('right_delim'), '/').
                                      '])|(?:[^'.                                             // ...containing anything but unescaped delimiters.
                                        preg_quote(CTE::get_env('left_delim'), '/').   // (This solves the {{literal}-bug) / 2006-01-04.
                                        preg_quote(CTE::get_env('right_delim'), '/').
                                    ']))*?'.
                                  '(?<!\\\)'.
                                  preg_quote(CTE::get_env('right_delim'), '/'). // Right delimiter not preceded by \.
                                ')';
    
    /**
     * Variable instance base
     */
    self::$re['variable_instance_base'] = '(?:'.
                                              '->[a-z_][a-z0-9_]*'.
                                          ')';

    /**
     * Constant
     * Constant without its pre- and postfix characters.
     */
    self::$re['constant'] = '(?:' . preg_quote(self::$operator['constant_prefix'], '#') . '[a-z_][a-z0-9_]*)';
    
    /**
     * Variable instance argument (make this arguments when necessary)
     */
    self::$re['variable_instance_arg'] = '(?:'.
                                             '(?:(?<!\(),\s*)?'. // Matches a coma that is not preceeded by a left parenthesis.
                                             '(?:'.
                                               '(?:'.self::$re['boolean'].')|'.
                                               '(?:'.self::$re['dquoted_string'].')'.
                                             ')'.
                                         ')';
    
    /**
     * Variable instance call
     */
    self::$re['variable_instance_call'] = '(?:'.
                                            self::$re['variable_instance_base'].
                                            '\('.
                                                self::$re['variable_instance_arg'].'*?'.
                                            '\)'.
                                          ')';
    
    /**
     * Variable static key ref
     */
    self::$re['variable_static_key_ref'] = '(?:'.
                                             preg_quote(self::$operator['variable_skey_prefix'], '/').
                                             '[a-z0-9_]+'.
                                           ')';
    
    /**
     * Variable base
     */
    self::$re['variable_base'] = '(?:' . preg_quote(self::$operator['variable_prefix'], '/') . '[a-z_][a-z0-9_]*' . ')';
    
    /**
     * Variable dynamic key ref
     */
    self::$re['variable_dynamic_key_ref'] = '(?:'.
                                              '\[(?:(?:[a-z_][a-z0-9_]*)|(?:'.self::$re['variable_base'].self::$re['variable_static_key_ref'].'*))\]'.
                                            ')';
    
    /**
     * Variable
     * Variable with both static and dynamic keys allowed.
     */
    self::$re['variable'] = '(?:'.
                              self::$re['variable_base'].
                              '(?:'.
                                self::$re['variable_dynamic_key_ref'].'|'.
                                self::$re['variable_static_key_ref'].
                              ')*'.
                              self::$re['variable_instance_call'].'?'.
                            ')';
    
    /**
     * Placeholder name
     */
    self::$re['placeholder_name'] = '(?:[a-z_][a-z0-9_]*)';
    
    /**
     * Placeholder base
     */
    self::$re['placeholder_base'] = '(?:'. preg_quote(self::$operator['placeholder_prefix'], '/') . self::$re['placeholder_name'] . ')';
    
    self::$re['placeholder_keys'] = '(?:'.
                                      self::$re['variable_dynamic_key_ref'].'|'.
                                      self::$re['variable_static_key_ref'].
                                    ')';
    
    /**
     * Placeholder
     */
    self::$re['placeholder'] = '(?:'.
                                 self::$re['placeholder_base'].
                                 self::$re['placeholder_keys'].'*'.
                               ')';
    
    /**
     * Tag/block parameter
     */
    self::$re['expr_param'] = '(?:'.
                                '(?:';
                                  foreach(self::$exprparam as $value) self::$re['expr_param'] .= '(?:'.$value.')|';
                                  self::$re['expr_param'] = rtrim(self::$re['expr_param'], '|');
    self::$re['expr_param']  .= ')'.
                                preg_quote(self::$operator['expr_param_postfix'], '/').
                              ')';
    
    /**
     * PHP function header
     */
    self::$re['php_function_header'] = '(?:[a-z_][a-z0-9_]*)';
    
    /**
     * PHP function argument
     * Matches a php-function argument.
     */
    self::$re['php_function_argument'] = '(?:'.
                                           '(?:'.
                                             '(?<!\(),\s*'. // Matches a coma that is not preceeded by a left parenthesis.
                                           ')?'.
                                           '(?:'.
                                             '(?:'.self::$re['expr_param'].'*'.self::$re['placeholder'].')|'.
                                             '(?:'.self::$re['expr_param'].'*'.self::$re['variable'].')|'.
                                             '(?:'.self::$re['expr_param'].'*'.self::$re['constant'].')|'.
                                             '(?:'.self::$re['boolean'].')|'.
                                             '(?:'.self::$re['dquoted_string'].')'.
                                           ')'.
                                         ')';
    
    /**
     * PHP function
     * (?<!\(), does find an occurrence of "," that is not preceded by "\(". 
     * some_built_in_func($foo, "some bla", 4)
     */
    self::$re['php_function'] = '(?:'.self::$re['php_function_header'].
                                  '\('.
                                  '(?:'.
                                    self::$re['php_function_argument'].
                                  ')*?'.
                                  '\)'.
                                ')';
    
    /**
     * Math operators
     */
    self::$re['math_op'] = '(?:[\/*\-+.%])';
    
    /**
     * Math expression
     * Any mathematical expression - spaces not allowed.
     */
    self::$re['math_expr'] = '(?:'.
                               '(?:'.
                                 '(?:'.
                                   '(?:[0-9]+)|'.
                                   self::$re['variable'].'|'.
                                   self::$re['constant'].'|'.
                                   self::$re['php_function'].
                                 ')'.
                                 '(?:'.
                                   '(?:\s*'.self::$re['math_op'].'\s*)(?=.+)'. // Matches an operator that is not in the beggining of the expression.
                                 ')?'.
                               ')+'.
                             ')';
    
    /**
     * CTE integrated functions
     * Matches function names that are handled in this class.
     */
    self::$re['integrated_function'] = '(?:';
    foreach(self::$ifunction as $value) self::$re['integrated_function'] .= '(?:'.$value.')|';
    self::$re['integrated_function'] = rtrim(self::$re['integrated_function'], '|') . ')';
    
    /**
     * Modifier header
     */
    self::$re['modifier_header'] = '(?:'.preg_quote(self::$operator['modifier_prefix'], '/').'[a-z_][a-z0-9_]+)';

    /**
     * Modifier argument
     * Matches a single modifier argument.
     */
    self::$re['modifier_argument'] = '(?:'.preg_quote(self::$operator['modifier_arg_prefix'], '/').
                                       '(?:'.
                                         self::$re['dquoted_string'].'|'.
                                         self::$re['math_expr'].'|'.
                                         self::$re['boolean'].'|'.
                                         self::$re['variable'].'|'.
                                         self::$re['placeholder'].
                                       ')'.
                                     ')';


    /**
     * Modifier
     * Matches one occurence of a modifier. The modifier is allowed to have zero or more arguments.
     * An argument can be a quoted (must be ") string (escaping " with \). Mathematical expressions can also be 
     * passed through, although they have to be surrounded by parenthesis, ie: (5*4/2).
     *
     * I.e: |modifier
     *      |modifier:"arg":(10*20)
     */
    self::$re['modifier'] = '(?:'.self::$re['modifier_header'] . self::$re['modifier_argument'].'*)';
    
    /**
     * Tag function name
     */
    self::$re['tag_function_name'] = '(?:[a-z_][a-z0-9_]*)';

    /**
     * Tag argument name
     */
    self::$re['tag_argument_name'] = '(?:[a-z_][a-z0-9_]*)';

    /**
     * Tag argument value token
     */
    self::$re['tag_argument_value_token'] = '(?:'.self::$re['dquoted_string'].'|'.
                                              self::$re['boolean'].'|'.
                                              '(?:[0-9.]+)|'.
                                              '(?:'.
                                                self::$re['expr_param'].'*'.
                                                '(?:'.
                                                  self::$re['variable'].'|'.
                                                  self::$re['constant'].'|'.
                                                  self::$re['placeholder'].
                                                ')'.
                                                self::$re['modifier'].'*'.
                                              ')'.
                                            ')';

    /**
     * Tag argument value
     */
    self::$re['tag_argument_value'] = '(?:'.
                                        self::$re['tag_argument_value_token'].
                                        '(?:'.
                                          '\s*\+\s*'.self::$re['tag_argument_value_token'].
                                        ')*'.
                                      ')';

    /**
     * tag argument
     */
    self::$re['tag_argument'] = '(?:'.
                                  '(?:\s+'.self::$re['tag_argument_name'].')'. // Argument name.
                                  '='.
                                  self::$re['tag_argument_value'].             // Argument value.
                                ')';
    
    /**
     * Comparison operators
     * Matches comparing operators.
     */
    self::$re['comp_op'] = '(?:eq|lt|gt|gte|lte|neq|ne|==|!=|<|>|<=|>=|===|!==)';
    
    /**
     * Logical operators
     * Matches logical operators.
     */
    self::$re['log_op'] = '(?:&&|or|and|xor|\|\|)';
    
    
    // ## Setup plugin environment.
    self::$plugin_cfg_path = CTE::get_env('cte_root') . CTE::get_env('plugin_rpath') . 'setup.ini';
    self::$plugins_cfg = self::parse_plugin_cfg_file();
    
    self::$used_plugins['tpl__tag_function'] = array();
    self::$used_plugins['tpl__modifier'] = array();
  }
  
  /**
   * string wrap_missing_entry(string)
   *
   * @param string $entry
   * @param string $wrappers
   */
  private static function wrap_missing_entry($entry, $wrappers)
  {
    if(!empty($wrappers))
    {
      return str_replace('<entry>', $entry, $wrappers);
    }
    else
    {
      return '';
    }
  }
  
  /**
   * void add_php_procedure(string)
   * 
   * @param string $procedure
   */
  public static function add_php_procedure($procedure)
  {
    self::$additional_php_procedures .= $procedure;
  }
  
  /**
   * mixed[] _parse_plugin_cfg_file()
   * Checks for and returns the parsed plugin config file.
   */
  private static function parse_plugin_cfg_file()
  {
    if(file_exists(self::$plugin_cfg_path))
      return Cylib__parse_ini(self::$plugin_cfg_path);
    else
      self::trigger_error('Failed to load plugin config $1.', array(basename(self::$plugin_cfg_path)), __LINE__, ERR_SYSTEM_ERROR);
  }
  
  /**
   * string _prefix_plugin(string)
   * Adds the CTE plugin prefix to the plugin call string.
   *
   * @param string  $plugin_name
   * @param integer $plugin_type
   * @returns prefixed plugin.
   */
  private static function prefix_plugin($plugin_name)
  {
    return self::$plugins_cfg['env']['common__function_prefix'].$plugin_name;
  }
  
  /**
   * bool _plugin_is_enabled(int)
   * Checks whether a plugin is enabled (1) or disabled (0).
   * Returns true when enabled, false otherwise.
   */
  private static function plugin_is_enabled($entry_value)
  {
    return (!empty($entry_value) && $entry_value == 1);
  }
  
  /**
   * @desc Enables a filter of type=$type and with name=$name.
   * @param string $type
   * @param string $name
   * @return void
   */
  public static function plugin_enable_filter($type, $name)
  {
    if(!key_exists($type, self::$plugins_cfg) || !key_exists($name, self::$plugins_cfg[$type]))
      self::trigger_error('Could not enable filter $1->$2.', array($type,$name), __FUNCTION__, __LINE__, ERR_USER_WARNING);
    self::$plugins_cfg[$type][$name] = 1;
  }
  
  /**
   * void register_tpl_plugin_use(string, string [, bool])
   * Procedure:
   * 1. Checks if plugin exist and if it's enabled.
   * 2. Registers the plugin use together with some data about the context it appeared in.
   *
   * The results are used for ie. include instructions later on.
   *
   * @param string $plugin_type The type of plugin.
   * @param string $plugin_name The name of the plugin.
   * @param bool
   * @return void
   */
  private static function register_tpl_plugin_use($plugin_type, $plugin_name)
  {
    $plugin_name = strtolower($plugin_name);
    $plugin_is_fe = false;
    
    $tag_process_type = self::$PROCESS->get_ctp_type();
    switch($tag_process_type)
    {
      // Modifier (variable with modifier).
      case TAG_PROCESS__VAR:
        $plugin_is_fe = self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__FORCE_EVAL);
        break;
      
      // Function.
      case TAG_PROCESS__FUNCTION:
        $plugin_is_fe = self::$PROCESS->ctp_flag_ison(T_FUNCTION_FLAG__FORCE_EVAL);
        break;
    }
    
    // Initiate plugin file path string.
    $plugin_file_path = CTE::get_env('cte_root') . CTE::get_env('plugin_rpath');
    
    // Register plugins.
    switch($plugin_type)
    {
      case 'tpl__tag_function':
          
          $pl_enabled    = key_exists($plugin_name, self::$plugins_cfg['tpl__tag_function']) &&
                           self::plugin_is_enabled(self::$plugins_cfg['tpl__tag_function'][$plugin_name]);
          $pl_fe_enabled = key_exists($plugin_name, self::$plugins_cfg['tpl__tag_function_fe']) &&
                           self::plugin_is_enabled(self::$plugins_cfg['tpl__tag_function_fe'][$plugin_name]);
          
          // ## Validate function request:
          if(($plugin_is_fe && !$pl_fe_enabled) || (!$plugin_is_fe && !$pl_enabled))
          {
            // * Function is not registered/enabled.
            self::trigger_error('Function $1 '.($plugin_is_fe ? 'with expression param $2 ' : '').'is not registered/enabled.',
                                array($plugin_name, self::$exprparam['force_eval']), __LINE__, ERR_USER_ERROR);
            return;
          }
          
          // Check if we have a function export.
          if(isset(self::$plugins_cfg['tpl__php_function_export'][$plugin_name]))
            return;
          
          // ## Update used plugins log:
          // We have a first occurence of the plugin.
          if(!isset(self::$used_plugins[$plugin_type][$plugin_name]))
          {
            $pl_env =& self::$plugins_cfg['env'];
            
            if(!empty($pl_env[$plugin_type.'__file_prefix']))
              $plugin_file_path = $plugin_file_path . $pl_env[$plugin_type.'__file_prefix'] . '.';
            
            $plugin_file_path = $plugin_file_path . $plugin_name . '.php';
            
            self::$used_plugins[$plugin_type][$plugin_name] = array('do_include' => !$plugin_is_fe,
                                                                    'file_path'  => $plugin_file_path,
                                                                    'n_calls'    => 1);
          }
          // We have a 2..n use of the plugin and until now do_include was set to false.
          elseif(!self::$used_plugins[$plugin_type][$plugin_name]['do_include'] && !$plugin_is_fe)
          {
            self::$used_plugins[$plugin_type][$plugin_name]['do_include'] = true;
            self::$used_plugins[$plugin_type][$plugin_name]['n_calls']++;
          }
          // We have a 2..n use of the plugin and we don't need to update do_include-key.
          else
          {
            self::$used_plugins[$plugin_type][$plugin_name]['n_calls']++;
          }
          
        break;
      case 'tpl__modifier':
          
          $pl_cfg_data =& self::$plugins_cfg['tpl__modifier'];
          
          
          // ## Validate plugin request:
          // Modifier is not available.
          if(!self::plugin_is_enabled($pl_cfg_data[$plugin_name]))
          {
            self::trigger_error('Modifier $1 not found.', array($plugin_name), __LINE__, ERR_USER_ERROR);
          }
          
          $do_include = (self::get_modifier_alias_target($plugin_name) == null && !$plugin_is_fe); // UPDATED @ 2006-01-29
          
          // ## Update used plugins log:
          // We have a first occurence of the plugin:
          if(!isset(self::$used_plugins[$plugin_type][$plugin_name]))
          {
            $pl_env =& self::$plugins_cfg['env'];
            
            if(!empty($pl_env[$plugin_type.'__file_prefix']))
              $plugin_file_path = $plugin_file_path . $pl_env[$plugin_type.'__file_prefix'] . '.';
            
            $plugin_file_path = $plugin_file_path . $plugin_name . '.php';
            
            self::$used_plugins[$plugin_type][$plugin_name] = array('do_include' => $do_include,
                                                                    'file_path'  => $plugin_file_path,
                                                                    'n_calls'    => 1);
          }
          // We have a 2..n use of the plugin and we don't need to update do_include-key.
          else
          {
            self::$used_plugins[$plugin_type][$plugin_name]['n_calls']++;
            
            // Check if we need to update 'do_include' key:
            if(!self::$used_plugins[$plugin_type][$plugin_name]['do_include'] && $do_include) // UPDATED @ 2006-02-19
              self::$used_plugins[$plugin_type][$plugin_name]['do_include'] = true;           // UPDATED @ 2006-01-29
          }
          
        break;
        
        default:
          self::trigger_error('Failed to recognize plugin type: $1.', array($plugin_type), __LINE__, ERR_SYSTEM_ERROR);
    }
  }
  
  /**
   * string _get_modifier_alias_target(string)
   * Returns alias target when found and null otherwise.
   * 
   * @param string $modifier_name
   */
  private static function get_modifier_alias_target($modifier_name)
  {
    if(isset(self::$plugins_cfg['tpl__modifier_alias'][$modifier_name]) && !empty(self::$plugins_cfg['tpl__modifier_alias'][$modifier_name]))
    {
      return self::$plugins_cfg['tpl__modifier_alias'][$modifier_name];
    }
    else
    {
      return null;
    }
  }
  
  /**
   * string[] get_plugin_include_data()
   * Returns an array with file paths to all registered and not-fe plugins.
   */
  private static function get_plugin_include_data()
  {
    // Initiate file path array.
    $files = array();
    
    // Get functions.
    foreach(self::$used_plugins['tpl__tag_function'] as $plugin_data)
    {
      if($plugin_data['do_include'])
      {
        $files[] = $plugin_data['file_path'];
      }
    }
    
    // Get modifiers.
    foreach(self::$used_plugins['tpl__modifier'] as $plugin_data)
    {
      if($plugin_data['do_include'])
      {
        $files[] = $plugin_data['file_path'];
      }
    }
    
    return $files;
  }
  
  /**
   * bool php_function_avail(string)
   *
   * @param string $name
   * @returns true if function is available, false otherwise.
   */
  private static function php_function_avail($name)
  {
    return self::plugin_is_enabled(self::$plugins_cfg['tpl__php_function'][$name]);
  }
  
  /**
   * string apply_filters(string, string)
   *
   * @param string $filter_type
   * @param string $string Target string.
   * @returns string.
   */
  private static function apply_filters($filter_type, $string)
  {
    foreach(self::$plugins_cfg[$filter_type] as $filter_name => $filter_value)
    {
      if(self::plugin_is_enabled($filter_value))
      {
        self::include_plugin($filter_type, $filter_name);
        $string = call_user_func(self::prefix_plugin($filter_name), $string);
      }
    }
    
    return $string;
  }
  
  /**
   * string[] parse_expr_params(string)
   *
   * @param string $param_string
   */
  private static function parse_expr_params($param_string)
  {
    return explode(self::$operator['expr_param_postfix'], $param_string);
  }
  
  /**
   * bool param_isset(string[], string)
   *
   * @param string[] $param_list
   * @param string   $param
   */
  private static function param_isset($param_list, $param)
  {
    if(!is_array($param_list))
    {
      return false;
    }
    elseif(!key_exists($param, self::$exprparam))
    {
      self::trigger_error('Unknown expression parameter found: $1. ', array($param), __LINE__, ERR_USER_WARNING);
      return false;
    }
    else
    {
      return in_array(self::$exprparam[$param], $param_list);
    }
  }
  
  /**
   * string eval_code(string)
   * 
   * @param string   $code
   * @param bool     $cte_scope
   * @param string[] $globals   -> '$foo,$bar' will make $foo and $bar global in this function.
   */
  private static function eval_code($code, $cte_scope=false)
  {
    if($cte_scope)
    {
      return CTE::cte_scope_eval($code);
    }
    else
    {
      return @eval($code);
    }
  }
  
  /**
   * void register_template_process(string)
   *
   * @param integer $tpl_type >> TPL_PROCESS__LANG_ENTRY || TPL_PROCESS__MAIN_FILE || TPL_PROCESS__SUB_FILE
   * @param string  $tpl_name >> If type == TPL_TYPE_MAIN_FILE then this is the filename.
   *                             If type == TPL_TYPE_LANG_ENTRY then this is the lang entry path: $category.$entry_name.
   */
  private static function register_template_process($tpl_type, $tpl_name, $tpl_content=null)
  {
    // This process must be unique.
    if(in_array($tpl_name, self::$process_id_stack))
      self::trigger_error('Cannot compile template contents with ID $1 since it already has a compile process running.',
                          array($tpl_name), __LINE__, ERR_SYSTEM_ERROR);
    
    // Check that TPL_TYPE_MAIN_FILE is registered properly.
    if($tpl_type == TPL_PROCESS__MAIN_FILE && count(self::$process_id_stack) > 0)
      self::trigger_error('TPL_PROCESS__MAIN_FILE can only be registered once and only in the beggining.', null, __LINE__, ERR_SYSTEM_ERROR);
    
    // Register process id.
    self::$process_id_stack[] = $tpl_name;
    
    // Initiate current_tpl_process key which will point at the parent tpl process.
    $parent_process_key = -1;
    
    // Add current process to stack if isset.
    if(self::$PROCESS !== null)
    {
      $parent_process_key = array_push(self::$PROCESS_STACK, self::$PROCESS);
      $parent_process_key--;
    }
    
    // Create parent object reference if available, null otherwise.
    $parent_process = null;
    if(key_exists($parent_process_key, self::$PROCESS_STACK))
    {
      $parent_process =& self::$PROCESS_STACK[$parent_process_key];
    }
    
    // Register process object.
    self::$PROCESS = new CTE_TPL_Process($parent_process);
    
    // Type is file.
    if($tpl_type == TPL_PROCESS__MAIN_FILE || $tpl_type == TPL_PROCESS__SUB_FILE)
    {
      // Initiate template filepath string.
      $template_file_path = CTE::get_env('cte_root');
      
      // Main-file is the case.
      if($tpl_type == TPL_PROCESS__MAIN_FILE)
      {
        $template_file_path .= CTE::get_env('template_rpath');
      }
      // Sub-file is the case.
      else
      {
        $template_file_path .= CTE::get_env('subtemplate_rpath');
      }
      
      $template_file_path .= $tpl_name;
      
      if($f = @fopen($template_file_path, 'r'))
      {
        if(($size = @filesize($template_file_path)) > 0)
        {
          $tpl_content = @fread($f, $size);
          @fclose($f);
        }
        else
        {
          $tpl_content = '';
        }
      }
      else
      {
        if($tpl_type == TPL_PROCESS__MAIN_FILE)
          self::trigger_error('Failed to open template file: $1', array($template_file_path), __LINE__, ERR_USER_ERROR);
        else
          self::trigger_error('Failed to open sub-template file: $1.', array($template_file_path), __LINE__, ERR_USER_ERROR);
      }
      
      // Apply content pre-filters if we're compiling the main-file.
      if($tpl_type == TPL_PROCESS__MAIN_FILE)
      {
        $tpl_content = self::apply_filters('cf__content_prefilter', $tpl_content);
      }
    }
    // Type is content, language content in this case.
    elseif($tpl_type == TPL_PROCESS__LANG_ENTRY)
    {
      if($tpl_content === null)
        self::trigger_error('Content is null.', null, __LINE__, ERR_USER_WARNING);
    }
    // Type is unknown.
    else
    {
      self::trigger_error('Unknown tpl_type $1..', array($tpl_type), __LINE__, ERR_SYSTEM_ERROR);
    }
    
    // Setup process object.
    self::$PROCESS->setup($tpl_type, $tpl_name, $tpl_content);
  }
  
  /**
   * void unregister_template_process()
   */
  private static function unregister_template_process()
  {
    if(count(self::$process_id_stack) > 0)
    {
      self::$PROCESS->check_block_balance();
      
      array_pop(self::$process_id_stack);
      
      if(count(self::$PROCESS_STACK) > 0)
      {
        self::$PROCESS = array_pop(self::$PROCESS_STACK);
      }
    }
    else
    {
      self::trigger_error('Called unregister_template_process() when no processes available.', null, __LINE__, ERR_SYSTEM_WARNING);
    }
  }
  
  /**
   * string wrap_template_tag(string)
   * Wrap string in template tag left and right delims, ie: string -> {string}
   * Used for error messages.
   *
   * @param string $string
   */
  private static function wrap_template_tag($string)
  {
    return CTE::get_env('left_delim') . $string . CTE::get_env('right_delim');
  }
  
  /**
   * void trigger_error(string [, int [, int [, string [, int]]]])
   * Trigger error.
   *
   * @param string $error_msg
   * @param array $vars
   * @param string $line
   * @param integer $err_level
   * @param string $php_file
   */
  public static function trigger_error($error_msg, $vars=null, $func='unknown', $php_line=null, $err_level=ERR_USER_ERROR, $php_file=null)
  {
    if(is_array($vars))
      for($i=0, $ii=count($vars); $i<$ii; $i++)
        $error_msg = preg_replace('/(?<!\\\)\$'.($i+1).'/', '<i>'.$vars[$i].'</i>', $error_msg);
    $error_msg = stripslashes($error_msg);
    
    $msg = '<b>Error message:</b> '.$error_msg.'<br />';
    
    if(is_object(self::$PROCESS))
      $msg .= '<b>Template data:</b> '.basename(self::$PROCESS->get_name()).' on line '.self::$PROCESS->get_line().'<br />';
    $msg .= '<b>PHP data:</b> '.basename($php_file == null ? __FILE__ : $php_file).' on line '.$php_line.' in function '.$func.'()<br />';
    
    if($err_level != ERR_USER_NOTICE)
      self::$has_error = true;
    
    CTE::handle_error($msg, $err_level);
  }
  
  /**
   * @return bool
   */
  public static function has_error()
  {
    return self::$has_error;
  }
  
  /**
   * @param string $scope
   * @return string
   */
  private static function get_system_var_base($scope=null)
  {
    return 'self::$' . CTE::get_env('system_data_source') . ($scope != null ? '[' . Cylib__squote($scope) . ']' : '');
  }
  
  /**
   * string get_foreach_var_base(string [, string])
   */
  private static function get_foreach_var_base($foreach_id, $var=null)
  {
    $var_base = self::get_system_var_base(CTE::SYSDS_KEY_DYNAMIC) . '[' . Cylib__squote('foreach') . '][' . Cylib__squote($foreach_id) . ']';
    if(!is_null($var)) $var_base .= '['.Cylib__squote($var).']';
    return $var_base;
  }
  
  /**
   * string get_tpl_var_base(bool)
   */
  private static function get_tpl_var_base()
  {
    return 'self::$'.CTE::get_env('template_data_source');
  }
  
  /**
   * bool var_exists(string)
   * Check if a variable is registered. Returns true on success, false otherwise.
   *
   * @param string $var_refstr The name of the variable.
   */
  private static function var_exists($var_refstr)
  {
    return CTE::cte_scope_eval('return isset('.$var_refstr.');', true);
  }

  /**
   * string create_section_loop_var(string)
   *
   * @param string $id
   */
  private static function create_section_loop_var($id, $is_count_container=false)
  {
    return 'self::$_sysds[' . Cylib__squote('section') . '][' .
                              Cylib__squote($id) . '][' .
                              Cylib__squote(($is_count_container ? 'limit' : 'index')) . ']';
  }

  /**
   * string compile_section_tag(string)
   * Compiles section tag.
   *
   * @param string $args
   * @returns the compiled tag.
   */
  private static function compile_section_tag($args)
  {
    // Define valid attributes.
    $id     = null;
    $src    = null;
    $start  = 0;
    $step   = 1;
    $max    = -1;
    
    // Tokenize arguments.
    preg_match_all('/(?:('.self::$re['tag_argument_name'].')=('.self::$re['tag_argument_value'].'))+/i', $args, $match);
    
    $n_args    = count($match[1]);
    $arg_name  = $match[1];
    $arg_value = $match[2];

    // Loop argument list.
    for($i=0; $i<$n_args; $i++)
    {
      $arg_value[$i] = Cylib__rm_dquotes($arg_value[$i]);
      
      switch($arg_name[$i])
      {
        case 'id':
          if(preg_match('/^[a-z_][a-z0-9_]*$/i', $arg_value[$i]))
          {
            $id = $arg_value[$i];
            self::$PROCESS->register_section($id);
          }
          else
          {
            self::trigger_error('Section: id-attribute $1 contains invalid characters.', array($arg_value[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
          }
          break;
        
        case 'src':
          if(preg_match('/^'.self::$re['variable'].'$/i', $arg_value[$i]))
          {
            self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
            $src = self::compile_var($arg_value[$i], false, false);
            self::$PROCESS->unregister_tag_process();
          }
          elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['variable'].self::$re['modifier'].'*$/i', $arg_value[$i]))
          {
            self::trigger_error('Section: src: $1 modifiers and/or expression params are not allowed in this context.',
                                array($arg_value[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
          }
          elseif(preg_match('/^'.self::$re['placeholder'].'$/', $arg_value[$i]))
          {
            self::$PROCESS->register_tag_process(TAG_PROCESS__PLACEHOLDER);
            $src = self::compile_placeholder($arg_value[$i], true);
            self::$PROCESS->unregister_tag_process();
          }
          else
          {
            self::trigger_error('Section: src: $1 is not a valid variable.', array($arg_value[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
          }
          break;
        
        case 'start':
        case 'step':
        case 'max':
          $name =& $arg_name[$i];
          
          // We have a number.
          if(is_numeric($arg_value[$i]))
          {
            $$name = $arg_value[$i];
          }
          // We have a variable.
          elseif(preg_match('/^'.self::$re['expr_param'] .'*'. self::$re['variable'] . self::$re['modifier'].'*$/i', $arg_value[$i]))
          {
            self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
            $$name = self::compile_var($arg_value[$i]);
            self::$PROCESS->unregister_tag_process();
          }
          // We have a constant.
          elseif(preg_match('/^('.self::$re['expr_param'] .'*)'. self::$re['constant'] . self::$re['modifier'].'*$/i', $arg_value[$i]))
          {
            self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
            $$name = self::compile_const($arg_value[$i]);
            self::$PROCESS->unregister_tag_process();
          }
          // We have nothing.
          else
          {
            self::trigger_error('Section attribute: $1="$2", value must be an integer '.
                                'or a variable/constant containing an integer.', array($name, $arg_value[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
          }
          break;
      }
    }
    
    // Check that required arguments are set.
    if($id == null || $src == null)
      self::trigger_error('Section required attribute missing: $1.', array(($id == null ? 'id' : 'src')), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    // Step must not be less than 1.
    if($step < 1)
      self::trigger_error('Section attribute: step="$1", value must be greater than 0.', array($step), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    // Build php loop header.
    $loop_var        = self::create_section_loop_var($id);
    $count_container = self::create_section_loop_var($id, true);
    
    $return = 'if(is_array('.$src.')&&('.$count_container.'=count('.$src.'))>0){';
    $return = $return . 'for('.$loop_var.'='.$start.';'.$loop_var.'<'.$count_container.';'.$loop_var.'+='.$step.'){';
    
    if($max != -1)
    {
      if($step > 1)
      {
        $return = $return . 'if(('.$loop_var.'/'.$step.')>='.$max.')break;';
      }
      else
      {
        $return = $return . 'if('.$loop_var.'>='.$max.')break;';
      }
    }
    
    return $return;
  }
  
  /**
   * string compile_foreach_tag(string)
   */
  private static function compile_foreach_tag($args)
  {
    $id    = null;
    $src   = null;
    $key   = null;
    $value = 'value';
    $keys  = false;
    
    // Tokenize arguments.
    preg_match_all('/(?:('.self::$re['tag_argument_name'].')=('.self::$re['tag_argument_value'].'))+/i', $args, $match);
    
    $n_args    = count($match[1]);
    $arg_name  = $match[1];
    $arg_value = $match[2];
    
    // Loop argument list.
    for($i=0; $i<$n_args; $i++)
    {
      $arg_value[$i] = Cylib__rm_dquotes($arg_value[$i]);
      
      switch($arg_name[$i])
      {
        case 'src':
          if(preg_match('/^'.self::$re['variable'].'$/i', $arg_value[$i]))
          {
            self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
            $src = self::compile_var($arg_value[$i], false, false);
            self::$PROCESS->unregister_tag_process();
          }
          elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['variable'].self::$re['modifier'].'*$/i', $arg_value[$i]))
          {
            self::trigger_error('Foreach attribute: $1="$2", modifiers and/or expression params are not allowed in this context.',
                                array($arg_name[$i],$arg_value[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
          }
          else
          {
            self::trigger_error('Foreach attribute: $1="$2", value is not a valid variable.', array($arg_name[$i],$arg_value[$i]),
                                __FUNCTION__, __LINE__, ERR_USER_ERROR);
          }
          break;
          
        case 'key':
          $keys = true;
        case 'id':
        case 'value':
          $name =& $arg_name[$i];
          
          if(preg_match('/^[a-z_][a-z0-9_]*$/i', $arg_value[$i]))
            $$name = $arg_value[$i];
          else
            self::trigger_error('Foreach attribute: $1="$2", value is not a valid string.', array($name,$arg_value[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
          break;
      }
    }
    
    self::$PROCESS->register_foreach($id, $value, $key);
    
    // Check that required arguments are set.
    if($id == null || $src == null)
      self::trigger_error('Foreach: Missing required attribute: $1.', array(($id == null ? 'id' : 'src')), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    $return = 'if(is_array('.$src.')&&count('.$src.')>0){';
    $return .= 'foreach('.$src.' as ';
    if($keys) $return .= self::get_foreach_var_base($id, $key) . '=>';
    $return .= self::get_foreach_var_base($id, $value) . '){';
    
    return $return;
  }
  
  /**
   * void include_plugin(string, string)
   * Tries to include plugin, throws error on fail.
   *
   * @param string $type
   * @param string $name
   */
  private static function include_plugin($plugin_type, $plugin_name)
  {
    $pl_env =& self::$plugins_cfg['env'];
    
    $plugin_path = CTE::get_env('cte_root') . CTE::get_env('plugin_rpath') . $pl_env[$plugin_type.'__file_prefix'] . '.' . $plugin_name . '.php';
    
    if(!function_exists(self::prefix_plugin($plugin_name)) && file_exists($plugin_path))
    {
      @include_once $plugin_path;
      
      if(!function_exists(self::prefix_plugin($plugin_name)))
      {
        self::trigger_error('Plugin $1 not found in $2.', array(self::prefix_plugin($plugin_name), $plugin_path), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
      }
    }
    elseif(!file_exists($plugin_path))
    {
      self::trigger_error('Could not find plugin file: $1.', array($plugin_path), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    }
  }
  
  /**
   * string compile_value_token(string, &string[])
   * Compiles and returns the an argument value. Used for combined strings, variables and constants and for values set in arguments lists.
   * Sets $token_compile_data argument to: array('is_cv'     => bool,
   *                                             'is_string' => bool)
   *
   * @param string    $token                Value substring.
   *                                        $somevar+"some string"+#constant
   *                                        $somevar, "some string" and #constant are tokens in this context.
   * @param string[]  $token_compile_data
   * @param string    $escape_chars         Chars that could be escaped in this context. (Default is for combined strings.)
   */
  private static function compile_value_token($token, &$token_compile_data, $escape_chars = '{}"') // BUGFIX @ 2006-03-06
  {
    $token_compile_data = array('is_string' => false,
                                'is_nquot'  => false,
                                'is_cv'     => false);  // Is constant or variable.
    
    // Numeric value.
    if(is_numeric($token))
    {
      $token = '('.$token.')';
      $token_compile_data['is_nqout'] = true;
    }
    // Boolean/null value.
    elseif(preg_match('/^('.self::$re['boolean_true'].'|'.self::$re['boolean_false'].'|null)$/i', $token, $match))
    {
      if(strpos(self::$re['boolean_true'], $token) !== false)
        $token = 'true';
      elseif(strpos(self::$re['boolean_false'], $token) !== false)
        $token = 'false';
      else
        $token = 'null';
      
      $token_compile_data['is_nqout'] = true;
    }
    // Check for placeholder.
    elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['placeholder'].self::$re['modifier'].'*$/i', $token))
    {
      self::$PROCESS->register_tag_process(TAG_PROCESS__PLACEHOLDER);
      $token = self::compile_placeholder($token);
      
      if(!self::$PROCESS->ctp_flag_ison(T_PH_FLAG__CONTAINS_CV) || self::$PROCESS->ctp_flag_ison(T_PH_FLAG__FORCE_EVAL))
        $token_compile_data['is_string'] = true;
      else
        $token_compile_data['is_cv'] = true;
      
      self::$PROCESS->unregister_tag_process();
    }
    // String match.
    elseif(preg_match('/^'.self::$re['dquoted_string'].'$/i', $token))
    {
      $token = Cylib__rm_dquotes($token);
      $token = Cylib__unescape_string($token, $escape_chars);
      $token_compile_data['is_string'] = true;
    }
    // Variable match.
    elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['variable'].self::$re['modifier'].'*$/i', $token))
    {
      self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
      $token = self::compile_var($token);
      
      // Is evaluated.
      if(self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__FORCE_EVAL))
        $token_compile_data['is_string'] = true;
      // Is constant.
      else
        $token_compile_data['is_cv'] = true;
      
      self::$PROCESS->unregister_tag_process();
    }
    // Constant match.
    elseif(preg_match('/^'. self::$re['expr_param'] .'*'. self::$re['constant'] . self::$re['modifier'].'*$/i', $token))
    {
      self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
      
      $token = self::compile_const($token);
      
      // Is evaluated.
      if(self::$PROCESS->ctp_flag_ison(T_CONST_FLAG__FORCE_EVAL))
        $token_compile_data['is_string'] = true;
      // Is constant.
      else
        $token_compile_data['is_cv'] = true;
      
      self::$PROCESS->unregister_tag_process();
    }
    // Unknown match.
    else
    {
      self::trigger_error('Unknown attribute value token found: $1.', array($token), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    }
    
    return $token;
  }
  
  /**
   * string compile_value_string(string)
   * Compiles compisite value strings, ie: $variable+"string value"+#constant
   *
   * @param string $arg_value
   */
  private static function compile_value_string($arg_value, &$compile_data)
  {
    $compile_data = array('contains_string'   => false,
                          'contains_cv' => false);
    
    // Set chars that must be escaped in this context.
    $escape_chars = '\'\\';
    
    // Tokenize value substrings; "quoted string", $variable, #CONSTANT
    preg_match_all('/\+?('.self::$re['tag_argument_value_token'].')/i', $arg_value, $match);
    
    $value_tokens = $match[1];
    
    //## Build valid php value string out of tokens.
    $prev_was_string = false;
    for($j=0, $jj=count($value_tokens); $j<$jj; $j++)
    {
      $value_tokens[$j] = trim($value_tokens[$j]);
      
      // Compile token and get data about it.
      $value_tokens[$j] = self::compile_value_token($value_tokens[$j], $token_compile_data);
      
      // Set compile data.
      if(!$compile_data['contains_string']   && $token_compile_data['is_string'])   $compile_data['contains_string']   = true;
      if(!$compile_data['contains_cv']       && $token_compile_data['is_cv'])       $compile_data['contains_cv']       = true;
      
      // Previous token was a string and so is this.
      if($prev_was_string && $token_compile_data['is_string'] && !$token_compile_data['is_nquot'])
      {
        $value_tokens[$j] = Cylib__escape_string($value_tokens[$j], $escape_chars);
      }
      // Previous token was a constant, variable, number or boolean value (or empty) and this is a string.
      elseif(!$prev_was_string && $token_compile_data['is_string'] && !$token_compile_data['is_nquot'])
      {
        $value_tokens[$j] = ($j == 0 ? '' : '.') . '\'' . Cylib__escape_string($value_tokens[$j], $escape_chars);
      }
      // Previous token was a string and this is a constant, variable, number or boolean value.
      elseif($prev_was_string && !$token_compile_data['is_string'])
      {
        $value_tokens[$j] = '\'.' . $value_tokens[$j];
      }
      // Previous token was a constant, variable, number or boolean value (or empty) and so is this.
      elseif(!$prev_was_string && !$token_compile_data['is_string'])
      {
        $value_tokens[$j] = ($j == 0 ? '' : '.') . $value_tokens[$j];
      }
      
      $prev_was_string = $token_compile_data['is_string'];
    }
    
    $return = implode('', $value_tokens);
    
    // Add an end-quote if we had a string in the last token.
    if($prev_was_string)
    {
      $return = $return . '\'';
    }
    
    return $return;
  }
  
  /**
   * string[] parse_tag_args(string)
   * Parses argument list and compiles its values.
   *
   * @param string $arg_string
   * @returns tokenized arguments: string[$name] = $value
   */
  private static function parse_tag_args($arg_string, $compile_values=true)
  {
    if(strlen(trim($arg_string)) < 1)
      return array();
    
    preg_match_all('/(?:('.self::$re['tag_argument_name'].')=('.self::$re['tag_argument_value'].'))+/i', $arg_string, $match);
    $args = array_combine($match[1], $match[2]);
    
    if($compile_values)
      foreach($args as &$arg_value)
        $arg_value = self::compile_value_string($arg_value, $compile_data);
    
    return $args;
  }
  
  /**
   * string compile_function_tag(string [, bool])
   * Compiles a function tag.
   * Returns compiled function when in dynamic mode and the function result when in static (force-eval) mode.
   *
   * @param string $func_full_str The function full string.
   */
  private static function compile_function_tag($func_full_str)
  {
    // Check that we have a process available and that we're not trying to use a previously registered process.
    if(!self::$PROCESS->tag_process_avail(TAG_PROCESS__FUNCTION, T_FUNCTION_FLAG__PROCESS_ACTIVATED))
      self::trigger_error('No tag process registered for function compilation.', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // Tokenize expression parameter, function header and function arguments.
    preg_match('/^('.self::$re['expr_param'] .'*)'.
                 '('.self::$re['tag_function_name']  .')'.
                 '('.self::$re['tag_argument'].'*)/i', $func_full_str, $match);
    
    $func_params = self::parse_expr_params($match[1]);
    $func_header = $match[2];
    
    // Note: do not change the following order.
    $force_eval  = self::$PROCESS->set_ctp_flag(T_FUNCTION_FLAG__FORCE_EVAL, self::param_isset($func_params, 'force_eval'));
    $func_args   = self::parse_tag_args($match[3]);
    
    $func_is_imported = key_exists($func_header, self::$plugins_cfg['tpl__php_function_export']);
    
    // Function header is empty.
    if(empty($func_header))
      self::trigger_error('Failed to match function $1.', array($func_full_str), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // Check and register function use.
    self::register_tpl_plugin_use('tpl__tag_function', $func_header);
    
    if($func_is_imported) // PHP built-in function.
    {
      $ini_value_tokens = explode(':', self::$plugins_cfg['tpl__php_function_export'][$func_header]);
      
      $php_func_name = $ini_value_tokens[0];
      $php_func_args = explode(',', $ini_value_tokens[1]);
      
      $php_arg_str = '';
      for($i=0, $ii=count($php_func_args); $i<$ii; $i++)
      {
        $required = true;
        if(substr($php_func_args[$i], 0, 1) == '?')
        {
          $required = false;
          $php_func_args[$i] = substr($php_func_args[$i], 1);
        }
        
        if(key_exists($php_func_args[$i], $func_args))
          $php_arg_str .= $func_args[$php_func_args[$i]] . ',';
        elseif($required)
          self::trigger_error('Missing required argument $1 for function: $2.', array($php_func_args[$i],$func_header), __FUNCTION__, __LINE__, ERR_USER_ERROR);
      }
      $php_arg_str = trim($php_arg_str, ',');
      
      $func_refstr = $php_func_name . '(' . $php_arg_str . ')';
      
      if($force_eval)
        return self::eval_code('return '.$func_refstr.';', true);
      else
        return 'echo '.$func_refstr;
    }
    else
    {
      // Initiate function call string.
      $func_refstr = self::prefix_plugin($func_header);
      
      // Build the associative array string.
      $arg_array = CTECF__make_array_string($func_args, false);
      
      // Function is force-eval and will be evaluated now.
      if($force_eval)
      {
        self::include_plugin('tpl__tag_function', $func_header);
        return self::eval_code('return '.self::prefix_plugin($func_header) . '(CTE::SYSDS_KEY_STATIC,' . $arg_array . ',true);', true);
      }
      // Function will be evaluated when the compiled template file is executed.
      else
      {
        return self::prefix_plugin($func_header) . '(CTE::SYSDS_KEY_DYNAMIC,' . $arg_array . ');';
      }
    }
  }
  
  /**
   * string extract_modifiers(string, string)
   * Returns variable/constant wrapped inside its modifiers.
   *
   * @param string $target_arg - Target, the object which is to be wrapped.
   * @param string $mods       - Raw modifier string.
   */
  private static function extract_modifiers($target_arg, $mods)
  {
    // Tokenize modifiers and arguments.
    preg_match_all('/('.self::$re['modifier_header'].')('.self::$re['modifier_argument'].'*)/i', $mods, $match);
    
    $mod_name = $match[1]; // Modifiers array (headers).
    $mod_args = $match[2]; // Arguments array.
    
    $return = $target_arg; // Initiate return string.
    
    // Parse modifiers.
    for($i=0,$ii=count($mod_name); $i<$ii; $i++) 
    {
      $mod_name[$i] = ltrim($mod_name[$i], self::$operator['modifier_prefix']);
      
      // ## Determine modifier source: built-in or external.
      $mod_refstr = '';
      
      // Modifier exists.
      if(isset(self::$plugins_cfg['tpl__modifier'][$mod_name[$i]]))
      {
        // Get possible alias target (like upper -> strtoupper).
        $alias_target = self::get_modifier_alias_target($mod_name[$i]);
        
        // CTE modifier.
        if($alias_target == null)
        {
          self::include_plugin('tpl__modifier', $mod_name[$i]);
          $mod_refstr = self::prefix_plugin($mod_name[$i]);
        }
        // PHP built-in modifier.
        else
        {
          $mod_refstr = $alias_target;
          
          if(!function_exists($mod_refstr))
          {
            self::trigger_error('Modifier $1 is not php-built-in.', array($mod_refstr), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
          }
        }
      }
      // Modifier doesn't exist.
      else
      {
        self::trigger_error('Use of undefined modifier: $1.', array($mod_name[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
      }
      
      self::register_tpl_plugin_use('tpl__modifier', $mod_name[$i]);
      
      $return = $mod_refstr.'('.$return;
      preg_match_all('/('.self::$re['modifier_argument'].')/i', $mod_args[$i], $match); // Fetch possible argument list.
      $current_args = $match[0];
      
      // Parse modifier arguments.
      for($j=0,$jj=count($current_args); $j<$jj; $j++)
      {
        $arg =& $current_args[$j];
        
        // Remove preceding colon.
        $arg = ltrim($arg, ':');
        
        // Replace double quotes with single quotes: " becomes '.
        if(preg_match('/('.self::$re['dquoted_string'].')/i', $arg, $match))
        {
          $arg = Cylib__squote(Cylib__rm_dquotes(Cylib__unescape_string($match[1], '"{}'))); // BUGFIX: added Cylib__unescape_string() @ 2006-03-06
        }                                                                                    // (BUGFIX: added stripslashes @ 2006-01-08)
        // Compile var if found
        elseif(preg_match('/('.self::$re['variable'].')/i', $arg, $match))
        {
          self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
          $arg = self::compile_var($match[1], false, false);
          if(!self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__LANG_IS_LIST_IMPORT))
            $arg = Cylib__squote($arg);
          self::$PROCESS->unregister_tag_process();
        }
        elseif(preg_match('/('.self::$re['constant'].')/i', $arg, $match))
        {
          self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
          $arg = self::compile_const($match[1], false, false);
          self::$PROCESS->unregister_tag_process();
        }
        elseif(preg_match('/('.self::$re['placeholder'].')/', $arg, $match)) // # ADDED @ 2006-02-19
        {
          self::$PROCESS->register_tag_process(TAG_PROCESS__PLACEHOLDER);
          $arg = self::compile_placeholder($match[1]);
          self::$PROCESS->unregister_tag_process();
        }
        
        // Add argument to result string.
        $return = $return.','.$arg;
      }
      
      $return = $return.')'; // Add ending parenthesis.
    }
    
    return $return;
  }
  
  /**
   * string compile_const(string [, bool [, bool]])
   * Compiles a constant.
   * Returns compiled constant with possible modifiers.
   * 
   * @param string $const_full_str
   * @param bool   $force_eval_allowed (true)
   * @param bool   $mods_allowed (true)
   */
  private static function compile_const($const_full_str, $force_eval_allowed=true, $mods_allowed=true)
  {
    // Check that we have a process available and that we're not trying to use a previously registered process.
    if(!self::$PROCESS->tag_process_avail(TAG_PROCESS__CONST, T_CONST_FLAG__PROCESS_ACTIVATED))
      self::trigger_error('No tag process registered for constant compilation.', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // Tokenize expression param, constant refstr and modifiers.
    preg_match('/^('.self::$re['expr_param'] .'*)'.
                 '('.self::$re['constant']  .')'.
                 '('.self::$re['modifier'].'*)/i', $const_full_str, $match);
    
    $const_params = self::parse_expr_params($match[1]);
    $const_header = $match[2];
    $const_mods   = $match[3];
    
    $force_eval   = self::$PROCESS->set_ctp_flag(T_CONST_FLAG__FORCE_EVAL,   self::param_isset($const_params, 'force_eval'));
    $ignore_unset = self::$PROCESS->set_ctp_flag(T_CONST_FLAG__IGNORE_UNSET, self::param_isset($const_params, 'ignore_unset'));
    
    if(!$force_eval && self::$PROCESS->isset_is_checked($const_header))
      self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_COMPILE, true);
    
    // We miss a match.
    if(empty($const_header))
      self::trigger_error('Failed to match constant $1 in _compile_const().', array($const_full_str), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    $const_refstr = trim($const_header, self::$operator['constant_prefix']);
    
    // We have modifiers and we don't want them in this context.
    if(!$mods_allowed && !empty($const_mods))
      self::trigger_error('Constant modifiers not allowed in this context: $1.', array($const_full_str), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    if(defined($const_refstr) || self::$PROCESS->isset_is_checked($const_header))
    {
      $return = $const_refstr;
    }
    elseif($ignore_unset || self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__FORCE_COMPILE))
    {
      $return = '@'.$const_refstr; // Ignore php-errors. // UPDATED @ 2006-01-27
    }
    else
    {
      self::trigger_error('Undefined constant: $1.', array($const_refstr), __FUNCTION__, __LINE__, ERR_USER_NOTICE);
      $return = Cylib__squote($const_refstr);
    }
    
    // We have modifiers.
    if(!empty($const_mods))
    {
      $return = self::extract_modifiers($return, $const_mods);
    }
    
    if($force_eval)
    {
      $return = self::eval_code('return '.$return.';');
    }
    
    return $return;
  }
  
  /**
   * string compile_var_keys(string[] [, bool])
   * Returns a string of static/dynamic key refs _without_ the variable base, that is: return('[elem1][elem2]')
   * 
   * @param string[] $keys
   * @param bool $allow_dynamic_keys
   */
  private static function compile_var_keys($keys, $allow_dynamic_keys=true)
  {
    if(is_array($keys) && count($keys) > 0)
    {
      $key_string = '';
      for($i=0, $ii=count($keys); $i<$ii; $i++)
      {
        // Dynamic key.
        if(preg_match('/^'.self::$re['variable_dynamic_key_ref'].'$/i', $keys[$i]))
        {
          $keys[$i] = rtrim(ltrim($keys[$i], '['), ']');
          
          // Check use of dynamic keys is allowed.
          if(!$allow_dynamic_keys)
            self::trigger_error('Dynamic keys not allowed in this context.', null, __FUNCTION__, __LINE__, ERR_USER_ERROR);
          
          self::$PROCESS->set_ctp_flag(T_VAR_FLAG__DKEY_FOUND, true);
          
          if(preg_match('/^'.self::$re['variable_base'].'$/i', $keys[$i]))
          {
            self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
            $key_string = $key_string . '[' . self::compile_var($keys[$i], false, false) . ']';
            self::$PROCESS->unregister_tag_process();
          }
          else
          {
            // #CHANGED @ 2006-02-19
            
            // Check if the loop var exists.
            if(!self::$PROCESS->loop_var_id_isset($keys[$i]))
              self::trigger_error('Use of undefined loop variable $1.', array($keys[$i]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
            
            $key_string = $key_string . '[' . self::create_section_loop_var($keys[$i]) . ']';
            // #END CHANGE
          }
        }
        elseif(preg_match('/^'.self::$re['variable_static_key_ref'].'$/i', $keys[$i]))
        {
          $keys[$i] = ltrim($keys[$i], self::$operator['variable_skey_prefix']);
          
          // Numeric static key.
          if(is_numeric($keys[$i]))
          {
            $key_string = $key_string.'['.$keys[$i].']';
          }
          // Alphanumeric static key.
          elseif(!empty($keys[$i]))
          {
            $key_string = $key_string.'[\''.$keys[$i].'\']';
          }
        }
        elseif(empty($keys[$i]))
        {
          self::trigger_error('Empty key value found in compile_var_keys().', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
        }
        else
        {
          self::trigger_error('Unknown value found in compile_var_keys().', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
        }
      }
    }
    else
    {
      $key_string = '';
    }
    
    return $key_string;
  }
  
  /**
   * string[] tokenize_keys(string)
   * Transforms a string of key-tokens to an array of key-tokens and returns it.
   *
   * @param string $keystr
   */
  private static function tokenize_keys($keystr)
  {
    $return = array();
    
    if(!empty($keystr))
    {
      preg_match_all('/'.self::$re['variable_dynamic_key_ref'].'|'.self::$re['variable_static_key_ref'].'/i', $keystr, $match);
      $keys = $match[0];
      
      for($i=0, $ii=count($keys); $i<$ii; $i++)
      {
        $return[] = $keys[$i];
      }
    }
    
    return $return;
  }
  
  /**
   * string compile_var(string [, bool [, bool [, bool]]])
   * Compiles and returns a variable.
   * An isset check is performed if current tag process tracker allows it.
   * Ie. $foo.bar becomes: self::$tpl_vars['foo']['bar']
   * 
   * @param string $var_full_str - Params, variable base, variable keys, modifiers etc.
   * @param bool $mods_allowed (true)
   * @param bool $force_eval (true)
   * @param bool $external_call (false)
   */
  public static function compile_var($var_full_str, $allow_force_eval=true, $mods_allowed=true, $external_call=false)
  {
    if($external_call)
      self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);

    // Check that we have a process available and that we're not trying to use a previously registered process.
    if(!self::$PROCESS->tag_process_avail(TAG_PROCESS__VAR, T_VAR_FLAG__PROCESS_ACTIVATED))
      self::trigger_error('No tag process registered for variable compilation.', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // ## --------------------------------------
    // ## Variable type independent procedures:
    // ## --------------------------------------
    
    // Tokenize expression param, variable header and modifiers.
    preg_match('/^('.self::$re['expr_param'] .'*)'.
                 '('.self::$re['variable']  .')'.
                 '('.self::$re['modifier'].'*)'.
                 '('.self::$re['tag_argument'].'*)/i', $var_full_str, $match);

    $var_params = self::parse_expr_params($match[1]);
    $var_header = $match[2];
    $var_mods   = $match[3];
    $var_args   = $match[4];

    // We miss a match.
    if(empty($var_header))
      self::trigger_error('Failed to match variable $1 in compile_var().', array($var_full_str), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // We have modifiers and we don't want them in this context.
    if(!$mods_allowed && !empty($var_mods))
      self::trigger_error('Variable $1: modifiers not allowed in this context.', array($var_full_str), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    $force_eval   = self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_EVAL,   self::param_isset($var_params, 'force_eval'));
    $ignore_unset = self::$PROCESS->set_ctp_flag(T_VAR_FLAG__IGNORE_UNSET, self::param_isset($var_params, 'ignore_unset'));
    
    // We have a force-eval and we don't want it.
    if($force_eval && !$allow_force_eval)
      self::trigger_error('Variable $1: force-eval not allowed in this context.', array($var_full_str), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // Tokenize var base, var name, dynamic key and static keys.
    preg_match('/('.self::$re['variable_base'].')'.
                 '('.
                   '(?:'.
                     self::$re['variable_dynamic_key_ref'].'|'.
                     self::$re['variable_static_key_ref'].
                   ')+'.
                 ')?'.
                 '('.
                   self::$re['variable_instance_call'].
                 ')?'.
               '/i', $var_header, $match);
    
    $var_base = $match[1];
    $var_name = ltrim($var_base, self::$operator['variable_prefix']);
    $var_keys = isset($match[2]) ? self::tokenize_keys($match[2]) : null;
    $var_inst = isset($match[3]) ? $match[3] : '';
    
    // Compile instance call if found:
    if(!empty($var_inst))
    {
      preg_match('/^('.self::$re['variable_instance_base'].')\(('.self::$re['variable_instance_arg'].'*?)\)$/i', $var_inst, $match);
      
      $var_inst_base = $match[1];
      $arg_list = $match[2];
      
      if(!empty($arg_list))
      {
        $args = explode(',', $arg_list);
        $arg_list = '';
        for($i=0, $ii=count($args); $i<$ii; $i++)
          $arg_list .= Cylib__squote(Cylib__rm_dquotes($args[$i])) . ',';
        $arg_list = substr($arg_list, 0, -1);
      }
      
      $var_inst = $var_inst_base.'('.$arg_list.')';
    }
    
    // Initiate return string.
    $return = '';
    
    // Check variable type.
    $is_lang_var = ($var_name == CTE::get_env('lang_tpl_var_name') && CTE::get_env('enable_lang_support'));
    $is_sys_var  = ($var_name == CTE::get_env('system_tpl_var_name'));


    // ## ------------------------------------
    // ## Variable type dependent procedures:
    // ## ------------------------------------
    
    // Language variable case.
    if($is_lang_var)
    {
      self::$PROCESS->set_ctp_flag(T_VAR_FLAG__IS_LANG_ENTRY, true);
      self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_EVAL, true);
      
      if(!empty($var_args))
      {
        $var_args = self::parse_tag_args($var_args, false);
        foreach($var_args as $ph_name => $ph_value)
          self::$PROCESS->register_placeholder($ph_name, $ph_value);
      }
      
      // ## Check that we have the proper amount (3 for lists and 2 for others) of static keys.
      if(empty($var_keys))
        self::trigger_error('No language data category specified.', null, __FUNCTION__, __LINE__, ERR_USER_NOTICE);
      elseif(count($var_keys) < 2)
        self::trigger_error('No language data entry specified.', null, __FUNCTION__, __LINE__, ERR_USER_NOTICE);
      elseif(count($var_keys) > 3)
        self::trigger_error('Undefined language data entries found: too many keys.', null, __FUNCTION__, __LINE__, ERR_USER_NOTICE);
      
      for($i=0, $ii=count($var_keys); $i<$ii; $i++)
      {
        if(!preg_match('/^'.self::$re['variable_static_key_ref'].'$/i', $var_keys[$i]))
          self::trigger_error('Variable $1: failed to match static key.', array($var_full_str), __FUNCTION__, __LINE__, ERR_USER_ERROR);
        else
          $var_keys[$i] = ltrim($var_keys[$i], self::$operator['variable_skey_prefix']);
      }
      
      $section =& $var_keys[0];
      $entry   =& $var_keys[1];
      $key     =  isset($var_keys[2]) ? $var_keys[2] : null;
      $id      = $section.'.'.$entry.'.'.$key;
      
      $lang_entry = self::$lang->get_entry($section, $entry, $key);
      
      /**
       * @todo redo get_entry_type ...
       */
      if(self::$lang->get_entry_type() == LANG_ENTRY_TYPE__LIST_IMPORT)
      {
        self::$PROCESS->set_ctp_flag(T_VAR_FLAG__LANG_IS_LIST_IMPORT, true);
        if(self::$PROCESS->parent_tag_process_exists() || self::$PROCESS->in_block())
        {
          // Variable is embeded inside a function or block.
          return $lang_entry;
        }
        else
        {
          // Variable is a stand-alone; {$somevar} (known bug: if variable appears inside a block it will be returned as a raw variable-name string)
          return CTECF__wrap_php_printout($lang_entry);
        }
      }
      else
      {
        $lang_str = &$lang_entry;
        
        // Tokenize template tags and plain content.
        $tokens = preg_split('/('.self::$re['template_tag'].')/i', $lang_str, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
        
        // Apply modifiers on static content.
        for($i=0, $ii=count($tokens); $i<$ii; $i++)
        {
          // We have a plain text: apply modifiers.
          if($tokens[$i]{0} != CTE::get_env('left_delim'))
          {
            $tokens[$i] = self::extract_modifiers(Cylib__squote($tokens[$i]), $var_mods);
            $tokens[$i] = self::eval_code('return ' . $tokens[$i] . ';');
          }
        }
        $return = implode('', $tokens);
        
        $return = self::eval_code('return ' . Cylib__squote($return) . ';');
        
        self::register_template_process(TPL_PROCESS__LANG_ENTRY, $id, $return);
        $return = self::compile();
        self::unregister_template_process();
      }
    }
    // System variable case.
    elseif($is_sys_var)
    {
      self::$PROCESS->set_ctp_flag(T_VAR_FLAG__IS_SYS_ENTRY, true);
      
      $var_refstr = self::get_system_var_base() . self::compile_var_keys($var_keys);
      
      if(count($var_keys) == 0)
        self::trigger_error('System variable: missing required keys for \$$1.', array(CTE::get_env('system_tpl_var_name')),
                            __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
      
      // Check if entry is static:
      // Note: substr because: $var_keys[...] has format .foo, and we don't want the . here.
      if(in_array(substr($var_keys[0], 1), CTE::get_env('system_static_subkeys'))) // # ADDED @ 2006-02-19
      {
        $force_eval = true;
        self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_EVAL, true);
      }

      // Check if variable exists:
      if(!$ignore_unset && $force_eval && !self::var_exists($var_refstr))
      {
        self::trigger_error('Use of undefined sys-variable: $1.', array($var_header), __FUNCTION__, __LINE__, ERR_USER_NOTICE);
        $return = self::wrap_missing_entry($var_header, CTE::get_env('sysds_missing_entry_wrapper'));
        self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_EVAL, true);
      }
      else
      {
        // We have modifiers.
        if(!empty($var_mods))
        {
          $return = self::extract_modifiers($var_refstr, $var_mods);
        }
        // No modifiers.
        else
        {
          $return = '@'.$var_refstr;
        }
        
        if($force_eval)
        {
          $return = self::eval_code('return @'.$return.';', true);
        }
      }
    }
    // Simple variable case.
    else
    {
      // Check if we have a foreach variable:
      if(!$external_call && self::$PROCESS->foreach_var_isset($var_name))
      {
        $foreach_id = self::$PROCESS->foreach_get_id_of($var_name);
        return self::get_foreach_var_base($foreach_id, $var_name);
      }
      
      self::$PROCESS->set_ctp_flag(T_VAR_FLAG__IS_TPL_ENTRY, true);
      
      $var_compiled_base = self::get_tpl_var_base() . '[\''.$var_name.'\']';
      $var_compiled_keys = self::compile_var_keys($var_keys);
      $var_refstr = $var_compiled_base . $var_compiled_keys;
      
      // Will be set to true when and if var exists.
      $var_exists = false;
      
      // If variable check is done internally in the template this will be set to true.
      $var_check_passed = self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__FORCE_COMPILE) || self::$PROCESS->isset_is_checked($var_header);
      $var_check_passed = $var_check_passed || (self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__DKEY_FOUND) &&
                                               (self::var_exists($var_base) || self::$PROCESS->isset_is_checked($var_base)));
      
      // Check if variable exists.
      if(!$var_check_passed)
      {
        $var_check_str = $var_name;
        for($key_index=0, $num_keys=count($var_keys); $key_index<$num_keys; $key_index++)
        {
          $var_check_str .= $var_keys[$key_index];
          $var_check_passed = self::$PROCESS->isset_is_checked($var_check_str);

          if($var_check_passed)
            break;
        }
        
        if(self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__DKEY_FOUND))
        {
          if($force_eval)
          {
            self::trigger_error('Variable $1: force-eval not allowed in dynamic key context.', array($var_full_str), __FUNCTION__, __LINE__, ERR_USER_WARNING);
            return '';
          }
          
          $var_exists = self::var_exists($var_compiled_base);
        }
        else
        {
          $var_exists = self::var_exists($var_compiled_base.$var_compiled_keys);
        }
      }
      
      // Final check if variable exists.
      if(!$ignore_unset && !$var_check_passed && !$var_exists)
      {
        self::trigger_error('Variable $1: undefined.', array($var_header), __FUNCTION__, __LINE__, ERR_USER_NOTICE);
        $return = self::wrap_missing_entry($var_refstr, CTE::get_env('tplds_missing_entry_wrapper'));
        self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_EVAL, true);
      }
      else
      {
        if($ignore_unset)
          $var_refstr = '@'.$var_refstr;
        
        if(!$var_check_passed && !$var_exists)
        {
          $return = $var_refstr;
        }
        else
        {
          $var_refstr = $var_refstr . $var_inst;
          
          // We have modifiers.
          if(!empty($var_mods))
          {
            $return = self::extract_modifiers($var_refstr, $var_mods);
          }
          // No modifiers.
          else
          {
            $return = $var_refstr;
          }
          
          if($force_eval)
          {
            $return = self::eval_code('return '.$return.';', true);
          }
        }
      }
    }
    
    if($external_call)
      self::$PROCESS->unregister_tag_process();
    
    return $return;
  }
  
  /**
   * string compile_placeholder(string)
   *
   * @param string $ph_full_str
   */
  private static function compile_placeholder($ph_full_str, $required=true)
  {
    // Check that we have a process available and that we're not trying to use a previously registered process.
    if(!self::$PROCESS->tag_process_avail(TAG_PROCESS__PLACEHOLDER, T_PH_FLAG__PROCESS_ACTIVATED))
      self::trigger_error('Placeholder $1: No tag process registered for compilation.', array($ph_full_str), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    
    // Tokenize expression param, variable header and modifiers.
    preg_match('/^'.
                 '('.self::$re['expr_param'].'*)'.
                 '('.self::$re['placeholder'].')'.
                 '('.self::$re['modifier'].'+)?/i', $ph_full_str, $match);
    
    $ph_params = self::parse_expr_params($match[1]);
    $ph_header = $match[2];
    $ph_mods   = isset($match[3]) ? $match[3] : '';
    
    self::$PROCESS->set_ctp_flag(T_PH_FLAG__IGNORE_UNSET, self::param_isset($ph_params, 'ignore_unset'));
    
    // We miss a match; system error.
    if(empty($ph_header))
    {
      self::trigger_error('Placeholder $1: match failed.', array($ph_full_str), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR);
    }
    
    // Tokenize placeholder base, name, dynamic and static keys.
    preg_match('/'.
                 '('.self::$re['placeholder_base'].')'.
                 '('.
                   '(?:'.
                     self::$re['variable_dynamic_key_ref'].'|'.   // These would be the variable keys.
                     self::$re['variable_static_key_ref'].        // - " -
                   ')+'.
                 ')?'.
               '/i', $ph_header, $match);
    
    $ph_base = $match[1];
    $ph_name = ltrim($ph_base, self::$operator['placeholder_prefix']);
    $ph_keys = isset($match[2]) ? self::tokenize_keys($match[2]) : '';
    
    // Initiate return string.
    $return = '';
    
    if(self::$PROCESS->placeholder_is_registered($ph_name))
    {
      $ph_value = self::$PROCESS->get_placeholder_value($ph_name);
      $return = self::compile_value_string($ph_value, $compile_data);
      
      // We have modifiers applied to the placeholder.
      if(!empty($ph_mods))
      {
        $return = self::extract_modifiers($return, $ph_mods);
      }
      
      self::$PROCESS->set_ctp_flag(T_PH_FLAG__CONTAINS_STRING, $compile_data['contains_string']);
      self::$PROCESS->set_ctp_flag(T_PH_FLAG__CONTAINS_CV,     $compile_data['contains_cv']);
      
      // All arguments are static and the code will be evaluated now.
      if(self::$PROCESS->ctp_flag_ison(T_PH_FLAG__FORCE_EVAL) || !self::$PROCESS->ctp_flag_ison(T_PH_FLAG__CONTAINS_CV))
      {
        $return = self::eval_code('return '.$return.';', true);
      }
    }
    elseif(!self::$PROCESS->ctp_flag_ison(T_PH_FLAG__IGNORE_UNSET))
    {
      // Placeholder not found.
      if($required)
      {
        self::trigger_error('Placeholder $1: undefined.', array($ph_name), __FUNCTION__, __LINE__, ERR_USER_ERROR);
      }
      else
      {
        $return = '';
      }
    }
    
    return $return;
  }
  
  /**
   * string compile_subtpl_tag(string)
   *
   * @param string $stpl_full_str
   */
  private static function compile_subtpl_tag($stpl_full_str)
  {
    // Tokenize expression parameter and arguments.
    preg_match('/^('.self::$re['expr_param'] .'*)'.
                 '('.self::$ifunction['subtemplate'] .')'.
                 '('.self::$re['tag_argument'].'*)/i', $stpl_full_str, $match);
    
    $stpl_params = self::parse_expr_params($match[1]);
    $stpl_header = $match[2];
    $stpl_args   = self::parse_tag_args($match[3], false);
    
    // Validate source.
    if(!key_exists('src', $stpl_args))
      self::trigger_error('Subtemplate $1: missing attribute $1.', array($stpl_header, 'src'), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    $stpl_file = Cylib__rm_dquotes($stpl_args['src']);
    unset($stpl_args['src']);
    
    // Interpret remaining arguments: register placeholders.
    foreach($stpl_args as $name => $value)
      self::$PROCESS->register_placeholder($name, $value);
    
    // Initiate return string.
    $return = '';
    
    // Return empty string if no content is found; we don't need to process anything anyways.
    if(self::$PROCESS->content_is_empty())
    {
      $return = '';
    }
    else
    {
      self::register_template_process(TPL_PROCESS__SUB_FILE, $stpl_file);
      $return = self::compile();
      self::unregister_template_process();
    }
    
    return $return;
  }
  
  /**
   * string compile_if_tag(string [, bool])
   * Compiles an if/elseif start-tag.
   *
   * @param string $expression The if expression.
   * @param bool $elseif Set to true if the tag is an elseif.
   */
  private static function compile_if_tag($expression, $elseif=false)
  {
    // Tokenize if-expression.
    preg_match_all('/'.
                   '(?:\s('.self::$re['dquoted_string'].'))|'.
                   '('.
                     '(?:'.self::$re['php_function'].')|'.
                     '(?:'.self::$re['expr_param'].'*'.
                       '(?:'.
                         '(?:'.self::$re['variable'].')|'.
                         '(?:'.self::$re['constant'].')|'.
                         '(?:'.self::$re['placeholder'].')'.
                       ')'.
                       '(?:'.self::$re['modifier'].'*)'.
                     ')'.
                   ')|'.
                   '('.self::$re['php_function'].')|'.
                   '('.self::$re['boolean'].')|'.
                   '('.self::$re['comp_op'].')|'.
                   '('.self::$re['log_op'].')|'.
                   '(?>\(|\)|,|\*|\@|!|\d+|\b\w+\b|\S+)'.
                   '/ix', $expression, $tokens);
    
    $tokens = $tokens[0];
    
    // Keep track of parenthesis.
    $open_parenthesis = 0;
    
    // Compile tokens.
    for($i=0, $ii=count($tokens); $i<$ii; $i++)
    {
      $token =& $tokens[$i];
      $token = trim($token);
      
      switch(strtolower($token))
      {
        // Skip compilation of valid operators.
        case '!':
        case '%':
        case '==':
        case '!=':
        case '===':
        case '!==':
        case '>':
        case '<':
        case '<=':
        case '>=':
        case '<>':
        case '<<':
        case '>>':
        case '&&':
        case '|':
        case '^':
        case ',':
        case '+':
        case '-':
        case '*':
        case '/':
        case '@':
          break;
        
        case '||':
          break;
        
        case '(':
          $open_parenthesis++;
          break;
        
        case ')':
          $open_parenthesis--;
          break;
        
        // Compiling logical-operator aliases.
        case 'and':
          $token = ' && ';
          break;
        
        case 'or':
          $token = ' || ';
          break;
        
        case 'xor':
          $token = ' xor ';
          break;
        
        // Compiling compare-operator aliases.
        case 'eq':
          $token = '==';
          break;
        
        case 'neq':
        case 'ne':
          $token = '!=';
          break;
        
        case 'lt':
          $token = '<';
          break;
        
        case 'gt':
          $token = '>';
          break;
        
        case 'lte':
          $token = '<=';
          break;
        
        case 'gte':
          $token = '>=';
          break;
        
        default:
        
            // PHP built-in function found.
            if(preg_match('/^'.self::$re['php_function'].'$/i', $token))
            {
              preg_match('/^('.self::$re['php_function_header'].')\(('.self::$re['php_function_argument'].'*)\)$/i', $token, $match);
              
              $func_name = $match[1];
              $func_args = preg_split('/('.self::$re['php_function_argument'].')/ism',
                                      $match[2], -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
              
              // Check that function is available.
              if(!self::php_function_avail($func_name))
                self::trigger_error('If block: Function $1: undefined.', array($func_name), __FUNCTION__, __LINE__, ERR_USER_ERROR);

              // Special case, we have to force a safe variable/constant compilation even if the variable/constant doesn't exist.
              if($func_name == 'isset' && count($func_args) == 1)
              {
                if(preg_match('/^'.self::$re['variable'].'$/i', $func_args[0]))
                {
                  self::$PROCESS->register_isset_check($func_args[0]);
                  
                  self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
                  self::$PROCESS->set_ctp_flag(T_VAR_FLAG__FORCE_COMPILE, true);
                  $var_ref = self::compile_var($func_args[0]);
                  self::$PROCESS->unregister_tag_process();
                  
                  $key_tokens = preg_replace('/^self::\$'.CTE::get_env('template_data_source').'/i', '', $var_ref);
                  $key_tokens = preg_split('/\[(.+?)\]/i', $key_tokens, null, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
                  
                  $token = ''; // This is the main loop token(!).
                  
                  $base = self::get_tpl_var_base();
                  for($key_token_index=0, $num_key_tokens=count($key_tokens); $key_token_index<$num_key_tokens; $key_token_index++)
                  {
                    $token .= 'key_exists('.$key_tokens[$key_token_index].','.$base.')&&';
                    $base .= '['.$key_tokens[$key_token_index].']';
                  }
                  $token = rtrim($token, '&&');
                }
                elseif(preg_match('/^'.self::$re['constant'].'$/i', $func_args[0]))
                {
                  self::$PROCESS->register_isset_check($func_args[0]);
                  
                  self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
                  self::$PROCESS->set_ctp_flag(T_CONST_FLAG__ISSET_PASSED, true);
                  $func_args = self::compile_const($func_args[0]);
                  self::$PROCESS->unregister_tag_process();
                  
                  $token = 'defined('.Cylib__squote($func_args).')'; // BUGFIX: "Didn't print constant if undefined" @ 2006-01-14
                }
                else
                {
                  self::trigger_error('If block: Function $1: argument $2 is not a valid constant or variable.',
                                      array($func_name,$func_args[0]), __FUNCTION__, __LINE__, ERR_USER_ERROR);
                }
              }
              else
              {
                for($j=0, $jj=count($func_args); $j<$jj; $j++)
                {
                  $func_args[$j] = ltrim($func_args[$j], ',');
                  $func_args[$j] = ltrim($func_args[$j]);
                  
                  $func_args[$j] = self::compile_value_string($func_args[$j], $compile_data);
                }

                $token = $func_name . '(' . implode(',', $func_args) . ')';
              }
            }
            // Placeholder found.
            elseif(preg_match('/^'.self::$re['placeholder'].self::$re['modifier'].'*$/i', $token))
            {
              self::$PROCESS->register_tag_process(TAG_PROCESS__PLACEHOLDER);
              $token = self::compile_placeholder($token);
              
              if(self::$PROCESS->ctp_flag_ison(T_PH_FLAG__FORCE_EVAL) || !self::$PROCESS->ctp_flag_ison(T_PH_FLAG__CONTAINS_CV))
              {
                $token = Cylib__squote($token);
              }
              
              self::$PROCESS->unregister_tag_process();
            }
            // Variable found.
            elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['variable'].self::$re['modifier'].'*$/i', $token))
            {
              self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
              
              $token = self::compile_var($token);
              
              if(self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__FORCE_EVAL))
              {
                $token = Cylib__squote($token);
              }
              
              self::$PROCESS->unregister_tag_process();
            }
            // Constant found.
            elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['constant'].self::$re['modifier'].'*$/i', $token))
            {
              self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
              $token = self::compile_const($token);
              
              if(self::$PROCESS->ctp_flag_ison(T_CONST_FLAG__FORCE_EVAL))
              {
                $token = Cylib__squote($token);
              }
              
              self::$PROCESS->unregister_tag_process();
            }
            // Number found.
            elseif(is_numeric($token))
            {
              
            }
            // String found.
            elseif(preg_match('/^'.self::$re['dquoted_string'].'$/i', $token))
            {
              $token = Cylib__rm_dquotes($token);
              $token = Cylib__squote($token);
            }
            // Unknown token found.
            else
            {
              self::trigger_error('$1: unknown token $2.', array('{if '.substr($expression, 0, 20).'...}', $token), __FUNCTION__, __LINE__, ERR_USER_ERROR);
            }
          break;
      }
    }
    
    if($open_parenthesis != 0)
      self::trigger_error('$1: parenthesis unbalanced.', array('{if '.substr($expression, 0, 20).'...}'), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    $c_expression = implode('', $tokens);
    
    return ($elseif ? '}else' : '') . 'if(' . $c_expression . '){';
  }
  
  /**
   * string compile_tag(string)
   * Compiles a tag. Throws an error if the submited tag is invalid.
   *
   * @param string $tag_string - Could be anything wrapped inside { ... }.
   */
  private static function compile_tag($tag_string)
  {
    // Tag is a constant.
    if(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['constant'].self::$re['modifier'].'*$/i', $tag_string))
    {
      self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
      $return = self::compile_const($tag_string);
      
      if(!self::$PROCESS->ctp_flag_ison(T_CONST_FLAG__FORCE_EVAL))
      {
        $return = CTECF__wrap_php_printout($return);
      }
      
      self::$PROCESS->unregister_tag_process();
      return $return;
    }
    // Tag is a placeholder.
    elseif(self::$PROCESS->get_type() != TPL_PROCESS__MAIN_FILE && 
           preg_match('/^'.self::$re['placeholder'].self::$re['modifier'].'*$/i', $tag_string))
    {
      self::$PROCESS->register_tag_process(TAG_PROCESS__PLACEHOLDER);
      $return = self::compile_placeholder($tag_string);
      
      // $return contains variables and/or constants.
      if(self::$PROCESS->ctp_flag_ison(T_PH_FLAG__CONTAINS_CV) && !self::$PROCESS->ctp_flag_ison(T_PH_FLAG__FORCE_EVAL))
      {
        $return = CTECF__wrap_php_printout($return);
      }
      
      self::$PROCESS->unregister_tag_process();
      return $return;
    }
    // Tag is a variable.
    elseif(preg_match('/^'.self::$re['expr_param'].'*'.self::$re['variable'].self::$re['modifier'].'*('.self::$re['tag_argument'].'*)$/i', $tag_string))
    {
      self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
      $return = self::compile_var($tag_string);
      
      // Variable is not evaluated.
      if(!self::$PROCESS->ctp_flag_ison(T_VAR_FLAG__FORCE_EVAL))
      {
        $return = CTECF__wrap_php_printout($return);
      }
      
      self::$PROCESS->unregister_tag_process();
      return $return;
    }
    // Tag is a control structure.
    elseif(preg_match('/^'.
                        '('.
                          '(?:'.
                            '(?:'.
                              preg_quote(self::$operator['block_end'], '/').
                            ')?'.
                            '(?:'.
                              'if|section|foreach'.
                            ')'.
                          ')|'.
                          '(?:'.
                            'elseif|else|sectionelse|foreachelse'.
                          ')'.
                        ')(?:\s+(.*))?$/is', $tag_string, $match))
    {
      $tag_header = $match[1];
      $tag_attr = isset($match[2]) ? $match[2] : '';
      
      switch($tag_header)
      {
        // ## If block
        case 'if':
          self::$PROCESS->register_block_process(BLOCK_PROCESS__IF);
          return CTECF__wrap_php_code(self::compile_if_tag($tag_attr));
          
        case 'elseif':
          return CTECF__wrap_php_code(self::compile_if_tag($tag_attr, true));
          
        case 'else':
          return CTECF__wrap_php_code('}else{');
          
        case self::$operator['block_end'].'if':
          self::$PROCESS->unregister_block_process(BLOCK_PROCESS__IF);
          return CTECF__wrap_php_code('}');
        
        
        // ## Section block
        case 'section':
          self::$PROCESS->register_block_process(BLOCK_PROCESS__SECTION);
          return CTECF__wrap_php_code(self::compile_section_tag($tag_attr));
          
        case 'sectionelse':
          self::$PROCESS->set_cbp_flag(B_SECTION_FLAG__HAS_ELSE, true);
          return CTECF__wrap_php_code('}}else{');
          
        case self::$operator['block_end'].'section':
          if(self::$PROCESS->cbp_flag_ison(B_SECTION_FLAG__HAS_ELSE))
            $return = CTECF__wrap_php_code('}');
          else
            $return = CTECF__wrap_php_code('}}');
          
          self::$PROCESS->unregister_block_process(BLOCK_PROCESS__SECTION);
          return $return;
        
        
        // ## Foreach block
        case 'foreach':
          self::$PROCESS->register_block_process(BLOCK_PROCESS__FOREACH);
          return CTECF__wrap_php_code(self::compile_foreach_tag($tag_attr));
        
        case 'foreachelse':
          self::$PROCESS->set_cbp_flag(B_FOREACH_FLAG__HAS_ELSE, true);
          return CTECF__wrap_php_code('}}else{');
          return;
        
        case self::$operator['block_end'].'foreach':
          if(self::$PROCESS->cbp_flag_ison(B_FOREACH_FLAG__HAS_ELSE))
          {
            $return = CTECF__wrap_php_code('}');
          }
          else
          {
            $return = CTECF__wrap_php_code('}}');
          }
          
          self::$PROCESS->unregister_block_process(BLOCK_PROCESS__FOREACH);
          return $return;
      }
    }
    // Tag is a function.
    elseif(preg_match('/^'.self::$re['expr_param'].'*('.self::$re['tag_function_name'].')('.self::$re['tag_argument'].'*)$/i', $tag_string, $match))
    {
      // Look for special functions.
      switch($match[1])
      {
        case self::$ifunction['subtemplate']:
          $return = self::compile_subtpl_tag($tag_string);
          break;
        
        case self::$ifunction['register_placeholder']: // DEPRECATED ..... might be useful when registering a placeholder for multiple targets.
          $placeholders = self::parse_tag_args($match[2], false);
          foreach($placeholders as $name => $value)
            self::$PROCESS->register_placeholder($name, $value);
          
          $return = '';
          break;
        
        default:
          self::$PROCESS->register_tag_process(TAG_PROCESS__FUNCTION);
          $return = self::compile_function_tag($tag_string);
          
          if(!self::$PROCESS->ctp_flag_ison(T_FUNCTION_FLAG__FORCE_EVAL))
          {
            $return = CTECF__wrap_php_code($return);
          }
          
          self::$PROCESS->unregister_tag_process();
      }
      
      return $return;
    }
    // Tag is a math expression.
    elseif(preg_match('/^'.self::$re['math_expr'].'$/i', $tag_string))
    {
      $tokens = preg_split('/\s*('.self::$re['math_op'].')\s*/i', $tag_string, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
      
      for($i=0, $ii=count($tokens); $i<$ii; $i++)
      {
        if(preg_match('/^'.self::$re['math_op'].'$/', $tokens[$i]))
        {
          continue;
        }
        elseif(preg_match('/^'.self::$re['variable'].'$/i', $tokens[$i]))
        {
          self::$PROCESS->register_tag_process(TAG_PROCESS__VAR);
          $tokens[$i] = self::compile_var($tokens[$i], false, false);
          self::$PROCESS->unregister_tag_process();
        }
        elseif(preg_match('/^'.self::$re['constant'].'$/i', $tokens[$i]))
        {
          self::$PROCESS->register_tag_process(TAG_PROCESS__CONST);
          $tokens[$i] = self::compile_const($tokens[$i], false, false);
          self::$PROCESS->unregister_tag_process();
        }
        elseif(preg_match('/^'.self::$re['php_function'].'$/i', $tokens[$i]))
        {
          continue;
        }
        else
        {
          self::trigger_error('Unknown token $1 in $2.', array($tokens[$i],self::wrap_template_tag($tag_string)), __FUNCTION__, __LINE__, ERR_USER_ERROR);
        }
      }
      
      return CTECF__wrap_php_printout(implode('', $tokens));
    }
    // Tag is unknown:
    else
    {
      self::trigger_error('Use of undefined tag: $1.', array(self::wrap_template_tag($tag_string)), __FUNCTION__, __LINE__, ERR_USER_WARNING);
    }
  }
  
  /**
   * string compile([string])
   * Compiles template and returns valid PHP-file content.
   *
   * @param string $tpl_file Path of the template file. (null)
   */
  public static function compile($tpl_file=null)
  {
    $ldq = preg_quote(CTE::get_env('left_delim'), '/');
    $rdq = preg_quote(CTE::get_env('right_delim'), '/');

    $content = self::$PROCESS->get_content();

    // Separate non-template content from template tags.
    $tokens = preg_split('/('.self::$re['linebreak'].'*(?: {2,})?'.
                              '(?:'.$ldq.'\*.*?\*(?<!\\\)'.$rdq.')|'. // Comment blocks.
                              self::$re['template_tag'].
                          ')/ism', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    for($i=0; $i<count($tokens); $i++)
    {
      self::$PROCESS->increment_line(substr_count($tokens[$i], "\n"));

      if(substr(ltrim($tokens[$i]), 0, 1) != CTE::get_env('left_delim'))
      {
        // Check that the content is allowed to be displayed.
        if(self::$PROCESS->in_block(BLOCK_PROCESS__PH_ISSET) && !self::$PROCESS->cbp_flag_ison(B_PHISSET_FLAG__PARSE_CONTENT))
          $tokens[$i] = '';
        else
          $tokens[$i] = self::apply_filters('cf__row_prefilter', $tokens[$i]);
        continue;
      }
      $tokens[$i] = trim($tokens[$i]);
      
      // Look for ph_isset tags.
      if(!self::$PROCESS->in_block(BLOCK_PROCESS__PH_ISSET) && 
         preg_match('/^'.$ldq.'ph_isset '.
                                        '('.
                                          self::$re['placeholder'].
                                          '(?:\s*(?:\&\&|and)\s*'.self::$re['placeholder'].')*'.
                                        ')'.
                                        $rdq.'$/i', $tokens[$i], $match))
      {
        self::$PROCESS->register_block_process(BLOCK_PROCESS__PH_ISSET);

        $placeholders = preg_split('/(?:(?:\&\&)|(?:and))/i', $match[1]);
        
        $do_parse = true;
        
        for($ph_i=0, $ph_ii=count($placeholders); $ph_i<$ph_ii; $ph_i++)
        {
          $placeholders[$ph_i] = ltrim(trim($placeholders[$ph_i]), self::$operator['placeholder_prefix']);
          
          if(!self::$PROCESS->placeholder_is_registered($placeholders[$ph_i]))
          {
            $do_parse = false;
            break;
          }
        }
        
        self::$PROCESS->set_cbp_flag(B_PHISSET_FLAG__PARSE_CONTENT, $do_parse);
        $tokens[$i] = '';
      }
      elseif(self::$PROCESS->in_block(BLOCK_PROCESS__PH_ISSET) && preg_match('/^'.$ldq.'ph_issetelse'.$rdq.'$/i', $tokens[$i]))
      {
        self::$PROCESS->set_cbp_flag(B_PHISSET_FLAG__PARSE_CONTENT, !self::$PROCESS->cbp_flag_ison(B_PHISSET_FLAG__PARSE_CONTENT));
        $tokens[$i] = '';
      }
      elseif(self::$PROCESS->in_block(BLOCK_PROCESS__PH_ISSET) && preg_match('/^'.$ldq.'\/ph_isset'.$rdq.'$/i', $tokens[$i]))
      {
        self::$PROCESS->unregister_block_process(BLOCK_PROCESS__PH_ISSET);
        $tokens[$i] = '';
      }
      
      // If we're in a ph_isset-block and it has evaluated to false we'll erase it's contents and continue.
      if(self::$PROCESS->in_block(BLOCK_PROCESS__PH_ISSET) && !self::$PROCESS->cbp_flag_ison(B_PHISSET_FLAG__PARSE_CONTENT))
      {
        $tokens[$i] = '';
        continue;
      }

      // Look for literal tags.
      if(!self::$PROCESS->in_block(BLOCK_PROCESS__LITERAL) && preg_match('/^'.$ldq.'literal'.$rdq.'$/i', $tokens[$i]))
      {
        self::$PROCESS->register_block_process(BLOCK_PROCESS__LITERAL);
        $tokens[$i] = '';
        continue;
      }
      elseif(self::$PROCESS->in_block(BLOCK_PROCESS__LITERAL) && preg_match('/^'.$ldq.'\/literal'.$rdq.'$/i', $tokens[$i]))
      {
        self::$PROCESS->unregister_block_process(BLOCK_PROCESS__LITERAL);
        $tokens[$i] = '';
        continue;
      }

      // Continue if we're in a literal block.
      if(self::$PROCESS->in_block(BLOCK_PROCESS__LITERAL)) continue;

      // Remove comment blocks.
      if(preg_match('/^'.$ldq.'\*.+?\*(?<!\\\)'.$rdq.'$/ism', $tokens[$i]))
      {
        $tokens[$i] = '';
        continue;
      }
      
      // We have a tag.
      if(preg_match('/^'.self::$re['template_tag'].'$/s', $tokens[$i]))
      {
        // Strip left and right delimiters from tag and forward it to the tag compiling function.
        $tokens[$i] = ltrim($tokens[$i], CTE::get_env('left_delim'));
        $tokens[$i] = rtrim($tokens[$i], CTE::get_env('right_delim'));
        
        $tokens[$i] = trim($tokens[$i]);
        
        $tokens[$i] = self::compile_tag($tokens[$i]);
      }
      
      // Post-compile row filter.
      $tokens[$i] = self::apply_filters('cf__row_postfilter', $tokens[$i]);
    }
    $content = implode('', $tokens);
    
    return $content;
  }

  /**
   * string _build()
   * Builds main template file.
   */
  public static function build()
  {
    self::register_template_process(TPL_PROCESS__MAIN_FILE, CTE::get_env('base_tpl_file'));

    // Compile content.
    $compiled_content = self::compile();
    
    if(!empty(self::$additional_php_procedures))
      $compiled_content = CTECF__wrap_php_code(self::$additional_php_procedures) . $compiled_content;

    // ## Add include data.
    $inc_data = self::get_plugin_include_data();
    $inc_str = '';
    for($i=0,$ii=count($inc_data); $i<$ii; $i++)
      $inc_str = $inc_str . 'include_once ' . Cylib__squote($inc_data[$i]) . ';';

    if(!empty($inc_str))
      $compiled_content = CTECF__wrap_php_code($inc_str) . $compiled_content;
    
    // Add compile notice.
    if(CTE::get_env('add_compile_notice'))
    {
      $compiled_content = CTECF__wrap_php_code('/* Compiled with CTE '.CTE::get_env('version').' on '.date('Y-m-d H:i:s').' */').$compiled_content;
    }

    // Apply post-compile content filter.
    $compiled_content = self::apply_filters('cf__content_postfilter', $compiled_content);
    
    self::unregister_template_process();

    return $compiled_content;
  }
}
CTE_Compiler::__init();
?>
