<?php
/********************************************************************************
 * File:          cte/engine/lib.CTE_process_monitoring.php
 * Description:   Monitoring environment for CTE_Compiler class.
 * Begin:         2005-02-23
 * Edit:          2006-02-19
 * Author:        Lukas Kalinski
 * Copyright:     2005-2006 CyLab Sweden
 ********************************************************************************/

// ## TPL Process
define('TPL_PROCESS__MAIN_FILE',        1);
define('TPL_PROCESS__SUB_FILE',         2);
define('TPL_PROCESS__LANG_ENTRY',       3);


// ## Block process
define('BLOCK_PROCESS__IF',             'if');
define('BLOCK_PROCESS__SECTION',        'section');
define('BLOCK_PROCESS__FOREACH',        'foreach');
define('BLOCK_PROCESS__LITERAL',        'literal');
define('BLOCK_PROCESS__PH_ISSET',       'ph_isset');

define('B_SECTION_FLAG__HAS_ELSE',      1);
define('B_PHISSET_FLAG__PARSE_CONTENT', 1);
define('B_FOREACH_FLAG__HAS_ELSE',      1);


// ## Tag process
define('TAG_PROCESS__VAR',              1);
define('TAG_PROCESS__CONST',            2);
define('TAG_PROCESS__FUNCTION',         3);
define('TAG_PROCESS__SUBTPL',           4);
define('TAG_PROCESS__PLACEHOLDER',      5);

define('T_VAR_FLAG__PROCESS_ACTIVATED',   1);
define('T_VAR_FLAG__FORCE_EVAL',          2);
define('T_VAR_FLAG__IGNORE_UNSET',        4);
define('T_VAR_FLAG__IS_LANG_ENTRY',       8);
define('T_VAR_FLAG__IS_SYS_ENTRY',        16);
define('T_VAR_FLAG__IS_TPL_ENTRY',        32);
define('T_VAR_FLAG__DKEY_FOUND',          64);
define('T_VAR_FLAG__FORCE_COMPILE',       128);
define('T_VAR_FLAG__LANG_IS_LIST_IMPORT', 256);

define('T_CONST_FLAG__PROCESS_ACTIVATED', 1);
define('T_CONST_FLAG__FORCE_EVAL',        2);
define('T_CONST_FLAG__IGNORE_UNSET',      4);
define('T_CONST_FLAG__ISSET_PASSED',      8);

define('T_PH_FLAG__PROCESS_ACTIVATED',  1);
define('T_PH_FLAG__FORCE_EVAL',         2);
define('T_PH_FLAG__CONTAINS_STRING',    4);
define('T_PH_FLAG__CONTAINS_CV',        8); // CV = Constant or Variable
define('T_PH_FLAG__IGNORE_UNSET',       16);

define('T_FUNCTION_FLAG__PROCESS_ACTIVATED', 1);
define('T_FUNCTION_FLAG__FORCE_EVAL',        2);


/**
 * CTE_Monitor
 */
class CTE_Monitor
{
  private $type  = NULL;  // @var integer
  private $flags = 0;     // @var integer
  
  public function __construct($type)
  {
    $this->type = $type;
  }
  
  public function get_type() { return $this->type; }
  
  /**
   * bool set_flag(ingeger, bool)
   *
   * @param integer $flag
   * @param bool    $on
   */
  public function set_flag($flag, $on)
  {
    if($on)
    {
      $this->flags = ($this->flags | $flag);
    }
    elseif($this->flag_ison($flag))
    {
      $this->flags = ($this->flags & ~$flag);
    }
    
    return $on;
  }
  
  /**
   * bool flag_ison(integer)
   *
   * @param integer $flag
   */
  public function flag_ison($flag)
  {
    return (($this->flags & $flag) > 0) ? true : false;
  }
}

/**
 * CTE_Tag_Process
 * Tag process monitoring class.
 * Stores information about a tag operation, like compilation of variables, constants, placeholders and functions.
 */
class CTE_Tag_Process extends CTE_Monitor
{
  
}

/**
 * CTE_Block_Process
 * Block process monitoring class.
 * Stores information about active blocks.
 */
class CTE_Block_Process extends CTE_Monitor
{
  private $isset_checked_objects = array();
  
  public function __construct($type)
  {
    parent::__construct($type);
  }
  
  /**
   * void register_isset_check($obj_name)
   * Stores variables with this pattern: $var_name.skey[dkey]
   * Stores constants with this pattern: #CONSTANT
   *
   * @param string $obj_name
   */
  public function register_isset_check($obj_name)
  {
    array_push($this->isset_checked_objects, $obj_name);
  }
  
  /**
   * void set_isset_checked_objects(string[])
   *
   * @param string[] $objects
   */
  public function set_isset_checked_objects($objects)
  {
    $this->isset_checked_objects = $objects;
  }
  
  /**
   * string[] get_isset_checked_objects()
   */
  public function get_isset_checked_objects()
  {
    return $this->isset_checked_objects;
  }
  
  /**
   * bool var_is_checked(string)
   *
   * @param string $obj_name
   */
  public function isset_is_checked($obj_name)
  {
    return in_array($obj_name, $this->isset_checked_objects);
  }
  
  /**
   * bool is_open(integer)
   *
   * @param integer $type
   */
  public function is_open($type)
  {
    // Note: The type is stored in parent class not in this.
    return (parent::get_type($type) == $type);
  }
}

/**
 * CTE_TPL_Process
 * Template process class containing all valuable data about a compile process.
 */
class CTE_TPL_Process
{
  // ## Main object environment.
  private $P_PROCESS = NULL;  // @var CTE_TPL_Process - Parent process
  
  
  // ## Static data.
  private $type         = NULL;  // @var int[]     - Process type.
  private $id           = NULL;  // @var string    - Process ID: /^[a-z0-9_]+$/
  private $name         = NULL;  // @var string    - Name of current template (file or lang entry name).
  private $content      = NULL;  // @var string    - Content of current template.


  // ## Parser data.
  private $block_balance            = NULL;     // @var int[]           - Keeps track of block start- and end-tags.
  private $active_section_stack     = array();  // @var string[]        - Stack of open sections.
  private $active_foreach_stack     = array();  // @var string[]        - Stack of open foreach blocks.
  private $avail_loop_var_ids       = array();  // @var string[]        - Available section loop variables.
  private $avail_foreach_key_vars   = array();  // @var string[]assoc   - 
  private $avail_foreach_value_vars = array();  // @var string[]assoc   - 
  private $line                     = 1;        // @var int             - Keeps track of which line the parser is on currently.
  private $placeholders             = array();  // @var string[]        - Stores registered placeholders.

  
  // ## Compile environment data.
  private $block_process_stack = array();
  private $tag_process_stack   = array();
  
  private $current_block_process = NULL;
  private $current_tag_process   = NULL;


  /**
   * Constructor (CTE_TPL_Process/NULL)
   *
   * @param CTE_TPL_Process/null &$P_PROCESS    # Parent process reference.
   */
  public function __construct(&$P_PROCESS)
  {
    if(is_object($P_PROCESS))
    {
      $this->P_PROCESS = &$P_PROCESS;
    }
    
    $this->block_balance = array(BLOCK_PROCESS__IF => 0,
                                 BLOCK_PROCESS__SECTION => 0,
                                 BLOCK_PROCESS__LITERAL => 0,
                                 BLOCK_PROCESS__PH_ISSET => 0,
                                 BLOCK_PROCESS__FOREACH => 0);
  }

  // ## Collection: Member access methods
  public function get_type()          { return $this->type;     }
  public function get_name()          { return $this->name;     }
  public function get_content()       { return $this->content;  }
  public function get_line()          { return $this->line;     }
  public function get_id()            { return $this->id;       }

  // ## Collection: Member set/change methods
  public function increment_line($by)     { $this->line += $by;       }
  public function set_in_literal($in)     { $this->in_literal = $in;  }

  /**
   * void register foreach(string, string [, string])
   *
   * @var string $foreach_id
   * @var string $foreach_value_var
   * @var string $foreach_key_var     (null)
   */
  public function register_foreach($foreach_id, $foreach_value_var, $foreach_key_var=null)
  {
    if(in_array($foreach_id, $this->active_foreach_stack))
    {
      CTE_Compiler::trigger_error('Foreach with ID $1 is still in use.', array($foreach_id), __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
    }
    else
    {
      // These must be pushed together.. the foreach handler relays on a syncronized stack order.
      array_push($this->active_foreach_stack, $foreach_id);
      array_push($this->avail_foreach_value_vars, $foreach_value_var);
      array_push($this->avail_foreach_key_vars, $foreach_key_var);
    }
  }
  
  /**
   * @param string $var_name    Variable name without the $.
   * @return bool
   */
  public function foreach_var_isset($var_name)
  {
    return in_array($var_name, $this->avail_foreach_value_vars) || in_array($var_name, $this->avail_foreach_key_vars);
  }
  
  /**
   * @desc Returns the ID of a currently opened foreach block if exists and throws an error if no match is found.
   * @param string $var_name
   * @return string
   */
  public function foreach_get_id_of($var_name)
  {
    // Check if the variable is a foreach "key variable":
    $key = array_search($var_name, $this->avail_foreach_key_vars);

    // If not a "key variable"; check if the variable is a foreach "value variable":
    if($key === false)
      $key = array_search($var_name, $this->avail_foreach_value_vars);
    
    // Check if we've got a key and throw an error if not:
    if($key === false)
      CTE_Compiler::trigger_error('Variable $1 not found in current foreach block(s).', array($var_name), __FUNCTION__, __LINE__, ERR_SYSTEM_WARNING);
    
    return $this->active_foreach_stack[$key];
  }
  
  /**
   * @return void
   */
  public function unregister_foreach()
  {
    array_pop($this->active_foreach_stack);
    array_pop($this->avail_foreach_value_vars);
    array_pop($this->avail_foreach_key_vars);
  }
  
  /**
   * @return void
   */
  public function register_section($id)
  {
    if($this->loop_var_id_in_use($id))
      CTE_Compiler::trigger_error('The ID $1 has already been used in a section and cannot be used until that section is closed.',
                                  array($id), __FUNCTION__, __LINE__, ERR_USER_ERROR);
    
    array_push($this->active_section_stack, $id);
    array_push($this->avail_loop_var_ids, $id);
  }
  
  /**
   * @desc Checks for an active section with corresponding in the process tree and returns true if found (in the tree) or false otherwise.
   * @param string $name
   * @return bool
   */
  public function loop_var_id_in_use($id)
  {
    return in_array($id, $this->active_section_stack) || ($this->P_PROCESS != null && $this->P_PROCESS->loop_var_id_in_use($id));
  }
  
  /**
   * @desc Checks if section id exists (that does NOT mean "is active) in the process tree and returns true if found (in the tree) or false otherwise.
   *       Alternatively: Checks whether an id is available or not.
   * @param string $name
   * @return bool
   */
  public function loop_var_id_isset($id)
  {
    return in_array($id, $this->avail_loop_var_ids) || ($this->P_PROCESS != null && $this->P_PROCESS->loop_var_id_isset($id));
  }
  
  /**
   * @return void
   */
  public function unregister_section()
  {
    array_pop($this->active_section_stack);
  }
  
  /**
   * void setup(integer, string, string)
   * Setup base data.
   *
   * @param integer $tpl_type
   * @param string  $tpl_name
   * @param string  $tpl_content
   */
  public function setup($tpl_type, $tpl_name, $tpl_content)
  {
    $this->type = $tpl_type;
    $this->name = $tpl_name;
    $this->content = $tpl_content;
    
    $id = strlen($tpl_name);
    for($i=0, $ii=strlen($tpl_name); $i<$ii; $i++)
    {
      $id += (ord($tpl_name{$i}) / ($i+1));
    }
    $this->id = preg_replace('/[^a-z0-9]/i', '', $tpl_name) . '_' . dechex($id);
  }
  
  /**
   * void check_block_balance()
   * Checks wether we have open blocks or not.
   */
  public function check_block_balance()
  {
    foreach($this->block_balance as $type => $balance)
    {
      if($balance != 0)
      {
        if($balance > 0)
          CTE_Compiler::trigger_error('Expecting end-tag for $1-block.', array($type), __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
        elseif($balance < 0)
          CTE_Compiler::trigger_error('Too many end-tags found for $1-block.', array($type), __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
      }
    }
  }
  
  /**
   * bool content_is_empty()
   */
  public function content_is_empty()
  {
    return empty($this->content);
  }
  
  
  // ## -----------------
  // ## Block processing
  // ## -----------------
  
  /**
   * void register_block_process(integer)
   *
   * @param integer $type
   */
  public function register_block_process($type)
  {
    // Add current block process to the stack, first stack value will (must) be NULL.
    $this->block_process_stack[] = $this->current_block_process;
    
    // Get checked vars before we re-define current_block_process.
    $isset_checked_objects = NULL;
    if(is_object($this->current_block_process))
    {
      $isset_checked_objects = $this->current_block_process->get_isset_checked_objects();
    }
    
    $this->current_block_process = new CTE_Block_Process($type);
    $this->block_balance[$type]++;
    
    if($isset_checked_objects !== NULL)
    {
      $this->current_block_process->set_isset_checked_objects($isset_checked_objects);
    }
  }
  
  /**
   * bool in_block([integer])
   */
  public function in_block($type=null)
  {
    if(!is_null($type))
    {
      if(!is_object($this->current_block_process))
      {
        return false;
      }
      else
      {
        return $this->current_block_process->is_open($type);
      }
    }
    else
    {
      return is_object($this->current_block_process);
    }
  }
  
  /**
   * void register_isset_check(string, string)
   * Stores variables using this pattern: $var_name.skey[dkey]
   * Stores constants using this pattern: #CONSTANT
   *
   * @param string $type
   * @param string $obj_name
   */
  public function register_isset_check($obj_name)
  {
    $this->current_block_process->register_isset_check($obj_name);
  }
  
  /**
   * bool isset_is_checked(string)
   *
   * @param string $var_name
   */
  public function isset_is_checked($obj_name)
  {
    if(is_object($this->current_block_process))
    {
      return $this->current_block_process->isset_is_checked($obj_name);
    }
    else
    {
      return false;
    }
  }
  
  /**
   * void set_cbp_flag(integer, bool)
   * Sets current block process flag.
   *
   * @param integer $flag
   * @param bool    $on
   */
  public function set_cbp_flag($flag, $on)
  {
    if(is_object($this->current_block_process))
    {
      $this->current_block_process->set_flag($flag, $on);
    }
    else
    {
      CTE_Compiler::trigger_error('Cannot set flag: current_block_process is null.', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR, __FILE__);
    }
  }
  
  /**
   * bool cbp_flag_ison(integer)
   * Check if current block process flag is on.
   *
   * @param integer $flag
   */
  public function cbp_flag_ison($flag)
  {
    if(!is_object($this->current_block_process))
    {
      return false;
    }
    else
    {
      return $this->current_block_process->flag_ison($flag);
    }
  }
  
  /**
   * void unregister_block_process()
   * Unregisters current block process.
   */
  public function unregister_block_process($type)
  {
    // Check if we're unregistering the block incorrectly.
    if(is_object($this->current_block_process) && $type != $this->current_block_process->get_type())
    {
      CTE_Compiler::trigger_error('Cannot end other block types until current block type is closed.', null, __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
    }
    else
    {
      switch($type)
      {
        case BLOCK_PROCESS__SECTION:
          $this->unregister_section();
          break;
        case BLOCK_PROCESS__FOREACH:
          $this->unregister_foreach();
          break;
      }
      
      $this->current_block_process = array_pop($this->block_process_stack);
    }
    
    $this->block_balance[$type]--;
  }
  
  
  // ## ---------------
  // ## Tag processing
  // ## ---------------
  
  /**
   * bool tag_process_avail(integer, integer)
   * Checks if a process is registered, if not it'll be marked as registered and return true, otherwise false.
   * The purpose of this is to allow tag processing functions to easily check if they have a process registered 
   * and if it has been activated.
   *
   * @param integet $process_type
   * @param integer $process_registered_flag
   */
  public function tag_process_avail($process_type, $process_activated_flag)
  {
    // No process is found at all and/or current tag process type is not equal to $process_type.
    if(!is_object($this->current_tag_process) || $this->current_tag_process->get_type() !== $process_type)
    {
      return false;
    }
    // Process has already been activated.
    elseif($this->current_tag_process->flag_ison($process_activated_flag))
    {
      return false;
    }
    else
    {
      $this->current_tag_process->set_flag($process_activated_flag, true);
      return true;
    }
  }
  
  /**
   * void register_tag_process()
   * Register Constant/Variable/Function process.
   */
  public function register_tag_process($type)
  {
    array_push($this->tag_process_stack, $this->current_tag_process);
    
    $this->current_tag_process = new CTE_Tag_Process($type);
  }
  
  /**
   * bool parent_tag_process_exists()
   */
  public function parent_tag_process_exists()
  {
    return (count($this->tag_process_stack) > 1); // Since first array_push pushes a null value onto the stack...
  }
  
  /**
   * integer get_ctp_type()
   * Get current tag process type.
   */
  public function get_ctp_type()
  {
    if(!is_object($this->current_tag_process))
    {
      CTE_Compiler::trigger_error('No process available.', null, __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR, __FILE__);
    }
    
    return $this->current_tag_process->get_type();
  }
  
  /**
   * bool ctp_flag_ison($flag)
   * Current tag process flag ison.
   *
   * @param integer $flag
   */
  public function ctp_flag_ison($flag)
  {
    return $this->current_tag_process->flag_ison($flag);
  }
  
  /**
   * bool set_ctp_flag($flag)
   * Set current tag process flag.
   *
   * @param integer $flag
   * @param bool    $on
   */
  public function set_ctp_flag($flag, $on)
  {
    return $this->current_tag_process->set_flag($flag, $on);
  }
  
  /**
   * void unregister_tag_process()
   */
  public function unregister_tag_process()
  {
    if(!is_object($this->current_tag_process))
      CTE_Compiler::trigger_error('Too many unregistrations of tag process found.', null, __FUNCTION__, __LINE__, ERR_SYSTEM_NOTICE, __FILE__);
    
    $this->current_tag_process = array_pop($this->tag_process_stack);
  }
  
  
  // ## -------------
  // ## Placeholders
  // ## -------------
  
  /**
   * void register_placeholder(string, string)
   *
   * @param string $name
   * @param string $value
   */
  public function register_placeholder($name, $value)
  {
    $this->placeholders[$name] = $value;
  }
  
  /**
   * bool placeholder_is_registered(string [, bool])
   *
   * @param string $name
   * @param bool   $is_internal_call (false)
   */
  public function placeholder_is_registered($name, $is_internal_call=false)
  {
    if(!$is_internal_call && $this->type == TPL_PROCESS__MAIN_FILE)
      CTE_Compiler::trigger_error('Placeholders not allowed in main template file.', null, __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
    
    if($is_internal_call)
      return key_exists($name, $this->placeholders);
    else
      return $this->P_PROCESS->placeholder_is_registered($name, true);
  }
  
  /**
   * string get_placeholder_value(string [, bool])
   *
   * @param string $name
   * @param bool   $is_internal_call (false)
   */
  public function get_placeholder_value($name, $is_internal_call=false)
  {
    if($is_internal_call)
      return $this->placeholders[$name];
    elseif(is_object($this->P_PROCESS))
      return $this->P_PROCESS->get_placeholder_value($name, true);
    else
      CTE_Compiler::trigger_error('Placeholders not allowed in main template file.', null, __FUNCTION__, __LINE__, ERR_USER_ERROR, __FILE__);
  }
}
?>
