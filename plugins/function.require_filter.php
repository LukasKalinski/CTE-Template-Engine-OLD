<?php
/**
 * Requires a filter of type=$type and name=$name.
 *
 * AVAILABLE MODES:
 * - force-eval
 *
 * @param string $scope
 * @param mixed[] $args
 * @return void
 */
function CTEPL__require_filter($scope, $args)
{
  extract($args, EXTR_PREFIX_ALL|EXTR_REFS, 'arg');
  
  if(!isset($arg_type))
    CTE_Compiler::trigger_error('Plugin json: missing type-argument.', $args, __FUNCTION__, __LINE__, ERR_USER_WARNING, __FILE__);
  if(!isset($arg_name))
    CTE_Compiler::trigger_error('Plugin json: missing name-argument.', $args, __FUNCTION__, __LINE__, ERR_USER_WARNING, __FILE__);
  
  CTE_Compiler::plugin_enable_filter($arg_type, $arg_name);
}
?>
