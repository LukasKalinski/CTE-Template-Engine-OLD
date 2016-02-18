<?php
require_once('system/env.globals.php');
require_once('function.parse_ini.php');

/**
 * @desc Returns string-array with the ID's of available themes: 001, 002, 003 .. n
 * @return string[]
 *
 * @todo Move to DB...
 */
function CTEPL_LIB__get_avail_themes($fetch_data=true)
{
  $avail_themes = array();
  
  if($handle = opendir(PATH_SYS__THEME_LIB_ROOT))
  {
    while(($file = readdir($handle)) !== false)
    {
      if(preg_match('/^theme\.([0-9]{3})\.ini$/', $file, $match))
      {
        $theme_data = Cylib__parse_ini(PATH_SYS__THEME_LIB_ROOT.$file);
        if($theme_data['enabled'] === true)
        {
          if($fetch_data)
            array_push($avail_themes, $theme_data);
          else
            array_push($avail_themes, $theme_data['id']);
        }
      }
    }
    closedir($handle);
  }
  
  return $avail_themes;
}
?>