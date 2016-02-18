<?php
/**
 * @todo implement this in CYCOM/regional/ .. instead.
 */
if(file_exists($file = CTE::get_env('cte_root').'tplenv/lang/'.CTE::get_env('current_lang').'/plugins/modifier.possess.php'))
  require($file);
elseif(file_exists($file = CTE::get_env('cte_root').'tplenv/lang/'.CTE::get_env('default_lang').'/plugins/modifier.possess.php'))
  require($file);
else
  exit('Error: Missing grammar file for lang{'.CTE::get_env('current_lang').'/'.CTE::get_env('default_lang').'}.');

/**
 * string possess(CTE, array)
 * 
 *
 * @param CTE $cte
 * @param mixed[] $args
 */
function CTEPL__possess($target, $possession, $gender)
{
  return CTEPL_LANG__possess(ucfirst($target), ucfirst($possession));
}
?>
