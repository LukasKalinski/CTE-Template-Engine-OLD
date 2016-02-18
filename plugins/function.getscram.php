<?php
/**
 * @desc Returns scrambled name if names were scrambled and the name if they were not.
 * @param CTE $CTE
 * @param mixed[] $args
 * @return string
 */
function CTEPL__getscram($scope, $args)
{
  extract($args, EXTR_PREFIX_ALL|EXTR_REFS, 'arg');
  
  // Check arguments:
  if(!isset($arg_name))
    CTE::handle_error('Plugin get_scram: missing name argument', ERR_USER_ERROR);
  
  $scram = CTE::get_sysds_plugin_entry('load_js', 'scram', CTE::SYSDS_KEY_STATIC);
  return (is_null($scram) || !key_exists($arg_name, $scram) ? $arg_name : $scram[$arg_name]);
}
?>
