<?php
/**
 * [FUNCTION]
 * Eval and force-eval.
 *
 * void cycle(CTE, array)
 *
 * @param string $scope
 * @param mixed[] $args
 */
function CTEPL__cycle($scope, $args)
{
  extract($args, EXTR_PREFIX_ALL|EXTR_REFS, 'cycle');
  
  if(empty($cycle_id))        CTE::handle_error('Missing id-attribute for cycle.', ERR_USER_ERROR);
  if(empty($cycle_values))    CTE::handle_error('Missing values-attribute for cycle.', ERR_USER_ERROR);
  if(empty($cycle_separator)) $cycle_separator = ',';
  
  $cycle_values = explode($cycle_separator, $cycle_values);
  
  if(CTE::get_sysds_plugin_entry('cycle', $cycle_id, $scope) === NULL)
  {
    CTE::set_sysds_plugin_entry('cycle', $cycle_id, $cycle_values[0], $scope);
  }
  else
  {
    for($i=0, $ii=count($cycle_values); $i<$ii; $i++)
    {
      if($cycle_values[$i] == CTE::get_sysds_plugin_entry('cycle', $cycle_id, $scope))
      {
        $next_index = $i+1;
        if(!key_exists($next_index, $cycle_values))
        {
          $next_index = 0;
        }
        
        CTE::set_sysds_plugin_entry('cycle', $cycle_id, $cycle_values[$next_index], $scope);
        break;
      }
    }
  }
}
?>
