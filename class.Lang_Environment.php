<?php
/**
 * Language handling class.
 *
 * @package CTE
 * @since 2005-03-16
 * @version 2006-06-13
 * @copyright Cylab 2005-2006
 * @author Lukas Kalinski
 */

require_once('function.parse_ini.php');

define('LANG_ENTRY_TYPE__NORMAL',       1);
define('LANG_ENTRY_TYPE__LIST_ENTRY',   2);
define('LANG_ENTRY_TYPE__LIST_IMPORT',  3);
define('LANG_ENTRY_TYPE__FILE',         4);

class Lang_Environment
{
  // ## Language ID:s
  // The order default=0,current=1 must not be changed.
  const LANG_DEFAULT = 0;
  const LANG_CURRENT = 1;
  
  // ## System
  private $current_entry_type;
  
  // ## Environment settings
  private $lang_root_path = NULL; // @var string
  private $languages = NULL;      // @var string[]
  private $no_entry_wrapper = ''; // @var string
  
  
  // ## Data containers
  private $ini_data = NULL;       // @var complicated array...
  
  
  /**
   * Constructor(CTE, string, string, string)
   *
   * @param CTE     &$CTE
   * @param string  $lang_root
   * @param string  $default_lang
   * @param string  $current_lang
   */
  public function __construct($lang_root, $default_lang, $current_lang)
  {
    $this->lang_root_path = $lang_root;
    
    $this->languages = array(self::LANG_DEFAULT => $default_lang,
                             self::LANG_CURRENT => $current_lang);
    $this->ini_data  = array(self::LANG_DEFAULT => array('all'        => array(),
                                                         'list'       => array(),
                                                         'assoc_list' => array()),
                             self::LANG_CURRENT => array('all'        => array(),
                                                         'list'       => array(),
                                                         'assoc_list' => array()));
    
    // Require system file (this should already be included).
    require_once($lang_root.$current_lang.DIR_SEPARATOR.$current_lang.'.system.php');
    
    $this->load_ini(self::LANG_DEFAULT);
    $this->load_ini(self::LANG_CURRENT);
  }
  
  /**
   * void load_ini(integer)
   */
  private function load_ini($lang_id)
  {
    $filepath = $this->lang_root_path . $this->languages[$lang_id] . DIR_SEPARATOR . $this->languages[$lang_id] . '.data.ini';
    
    if(file_exists($filepath))
    {
      $this->ini_data[$lang_id]['all'] = Cylib__parse_ini($filepath);
    }
    else
    {
      CTE_Compiler::trigger_error('Failed to load lang data, missing file: $1.', array($filepath), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR, __FILE__);
    }
    
    $this->load_lists($lang_id);
  }
  
  /**
   * void load_lists(integer)
   */
  private function load_lists($lang_id)
  {
    // Simple list.
    foreach($this->ini_data[$lang_id]['all']['list'] as $key => $value)
    {
      if(!empty($value))
      {
        $this->ini_data[$lang_id]['list'][$key] = explode($this->ini_data[$lang_id]['all']['ini_setup']['list_separator'], $value);
      }
    }
    
    // Associative list.
    foreach($this->ini_data[$lang_id]['all']['assoc_list'] as $key => $value)
    {
      if(!empty($value))
      {
        // Tokenize entries (key<list_separator>value).
        $entries = explode($this->ini_data[$lang_id]['all']['ini_setup']['list_separator'], $value);
        
        for($i=0, $ii=count($entries); $i<$ii; $i++)
        {
          $list_sep = &$this->ini_data[$lang_id]['all']['ini_setup']['list_assoc_operator'];
          // Tokenize key and value.
          if(preg_match('/^([^'.preg_quote($list_sep, '/').']+)'.     // Key.
                        preg_quote($list_sep, '/'). // Assoc list separator.
                        '([^'.preg_quote($list_sep, '/').']+)$/i',    // Value.
                        $entries[$i], $match))
          {
            $this->ini_data[$lang_id]['assoc_list'][$key][$match[1]] = $match[2];
          }
          else
          {
            CTE_Compiler::trigger_error('Parse error in $1-language ini: [assoc_list] syntax error: $2.',
                                        array($this->languages[$lang_id],$entries[$i]), __FUNCTION__, __LINE__, ERR_SYSTEM_WARNING);
          }
        }
      }
    }
  }
  
  /**
   * void set_no_entry_wrapper(string)
   */
  public function set_no_entry_wrapper($str)
  {
    $this->no_entry_wrapper = $str;
  }
  
  /**
   * void set_entry_type(int)
   *
   */
  private function set_entry_type($type)
  {
    $this->current_entry_type = $type;
  }
  
  /** 
   * int get_entry_type()
   */
  public function get_entry_type()
  {
    return $this->current_entry_type;
  }
  
  /**
   * @param string $section       # Section.
   * @param string $entry         # Section entry.
   * @param string $list_entry    # A specific list entry requested (means we don't need to import the whole list).
   * @return string
   */
  public function get_entry($section, $entry, $list_entry=NULL)
  {
    switch($section)
    {
      // Import used lists:
      case 'list':
      case 'assoc_list':
        if(!is_null($list_entry))
        {
          $this->set_entry_type(LANG_ENTRY_TYPE__LIST_ENTRY);
          return $this->get_list_entry($section, $entry, $list_entry);
        }
        else
        {
          $this->set_entry_type(LANG_ENTRY_TYPE__LIST_IMPORT);
          $export_var_name = 'LANG__'.$entry;
          $export_var_value = $this->get_list_entry($section, $entry);
          CTE::create_var($export_var_name, ''); // Simulate variable availability (it will be available after compile otherwise and we'll get an error).
          CTE_Compiler::add_php_procedure('self::create_var(\''.$export_var_name.'\','.$export_var_value.');');
          return CTE_Compiler::compile_var('$'.$export_var_name, false, false, true);
        }
      
      case 'file':
        $this->set_entry_type(LANG_ENTRY_TYPE__FILE);
        return $this->get_file_entry($section, $entry);
      
      default:
        $this->set_entry_type(LANG_ENTRY_TYPE__NORMAL);
        return $this->get_simple_entry($section, $entry);
    }
  }
  
  /**
   * string get_simple_entry(string, string)
   *
   * @param string $section
   * @param string $entry
   */
  private function get_simple_entry($section, $entry)
  {
    // Note: $section isn't validated here and therefore its validation must remain.
    
    // Current language entry found.
    if(key_exists($section, $this->ini_data[self::LANG_CURRENT]['all']) &&
       key_exists($entry,   $this->ini_data[self::LANG_CURRENT]['all'][$section]))
    {
      return $this->ini_data[self::LANG_CURRENT]['all'][$section][$entry];
    }
    // Default language entry found.
    elseif(key_exists($section, $this->ini_data[self::LANG_DEFAULT]['all']) &&
           key_exists($entry,   $this->ini_data[self::LANG_DEFAULT]['all'][$section]))
    {
      return $this->ini_data[self::LANG_DEFAULT]['all'][$section][$entry];
    }
    // No entry found.
    else
    {
      CTE_Compiler::trigger_error('Missing language entry: $1.$2.', array($section,$entry), __FUNCTION__, __LINE__, ERR_USER_NOTICE, __FILE__);
      return str_replace('<entry>', self::LANG_DEFAULT.'->'.$section.'.'.$entry, $this->no_entry_wrapper);
    }
  }
  
  /**
   * string get_file_entry(string, string)
   */
  private function get_file_entry($section, $entry)
  {
    if(!key_exists($section, $this->ini_data[self::LANG_CURRENT]['all']))
    {
      CTE_Compiler::trigger_error('Missing ini-section: $1.', array($section), __FUNCTION__, __LINE__, ERR_SYSTEM_ERROR, __FILE__);
    }
    
    // Current language entry found.
    if(key_exists($entry, $this->ini_data[self::LANG_CURRENT]['all'][$section]))
    {
      $lang_id = self::LANG_CURRENT;
    }
    // Default language entry found.
    elseif(key_exists($entry, $this->ini_data[self::LANG_DEFAULT]['all'][$section]))
    {
      $lang_id = self::LANG_DEFAULT;
    }
    // No entry found.
    else
    {
      CTE_Compiler::trigger_error('Missing language entry: $1.$2.', array($section,$entry), __FUNCTION__, __LINE__, ERR_USER_NOTICE, __FILE__);
      return str_replace('<entry>', self::LANG_DEFAULT.'->'.$section.'.'.$entry, $this->no_entry_wrapper);
    }
    
    $filename = $this->ini_data[$lang_id]['all'][$section][$entry];
    $filepath = $this->lang_root_path . $this->languages[$lang_id] . DIR_SEPARATOR . 'data' . DIR_SEPARATOR . $filename . '.stpl';
    
    if(!file_exists($filepath))
      $filepath = $this->lang_root_path . $this->languages[self::LANG_DEFAULT] . '/data/' . $filename . '.stpl';
    
    if(file_exists($filepath))
    {
      $this->entry_found = true;
      
      if(preg_match('/.+\.xml$/i', $filename))
      {
        require_once('cte/tplenv/lang/class.XML2HTML_Parser.php');
        $FILE = new XML2HTML_Parser($filepath);
        return $FILE->to_html();
      }
      else
      {
        return file_get_contents($filepath);
      }
    }
    else
    {
      CTE_Compiler::trigger_error('Language file not found: $1', array(basename($filepath)), __FUNCTION__, __LINE__, ERR_USER_NOTICE, __FILE__);
      return '[Language file not found: '.basename($filepath).']';
    }
  }
  
  /**
   * string get_list_entry(integer, string, string, string)
   * Returns list entry if found, otherwise a not-found notice string is returned.
   *
   * @param string  $type       List type: list, assoc_list
   * @param string  $name       Name of the list.
   * @param string  $entry      The requested list entry (in fact the list key).
   */
  private function get_list_entry($type, $name, $entry=NULL)
  {
    end($this->languages);
    
    // Look for an entry in current lang data, then look for it in default lang data.
    do
    {
      $lang_id = key($this->languages);
      $lang_name = current($this->languages);
      
      if(key_exists($lang_id, $this->ini_data) &&
         key_exists($name,    $this->ini_data[$lang_id][$type]))
      {
        reset($this->languages);
        // List entry requested.
        if(key_exists($entry, $this->ini_data[$lang_id][$type][$name]))
        {
          $this->entry_found = true;
          return $this->ini_data[$lang_id][$type][$name][$entry];
        }
        // List import requested.
        else
        {
          $this->entry_found = true;
          return CTECF__make_array_string($this->ini_data[$lang_id][$type][$name]);
        }
      }
    } while (prev($this->languages) !== false);
    
    reset($this->languages);
    
    // No entry found.
    CTE_Compiler::trigger_error('Missing language list entry: $1.$2.', array($name,$entry), __FUNCTION__, __LINE__, ERR_USER_NOTICE, __FILE__);
    return str_replace('<entry>', $lang_name.'->'.$type.'.'.$name.'.'.$entry, $this->no_entry_wrapper);
  }
}
?>